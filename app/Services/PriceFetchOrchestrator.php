<?php

namespace App\Services;

use App\Dto\PriceFetchResult;
use App\Enums\PriceFetchStatus;
use App\Enums\ScraperService;
use App\Models\Store;
use App\Services\Helpers\CurrencyHelper;
use Closure;
use DateTimeImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class PriceFetchOrchestrator
{
    public function __construct(
        private readonly RetailPriceExtractorClient $extractor,
        private readonly PriceCandidateSelector $candidateSelector,
    ) {}

    /**
     * Run the existing lightweight extractor first and invoke the configured store
     * scraper only when the HTTP result cannot safely supply a price.
     *
     * @param  Closure(): array<string, mixed>  $fallback
     */
    public function fetch(string $url, Store $store, Closure $fallback): PriceFetchResult
    {
        $http = $this->extractor->fetchUrl($url);
        $selected = $this->selectHttpCandidate($http, $store);

        if ($selected !== null) {
            return $selected;
        }

        $startedAt = hrtime(true);
        try {
            $payload = $fallback();
            $fallbackResult = $this->fromFallbackPayload($url, $store, $payload, $startedAt);
        } catch (Throwable $exception) {
            $fallbackResult = $this->fromFallbackException($url, $store, $exception, $startedAt);
        }

        return $this->mergeMetadata($http, $fallbackResult);
    }

    private function selectHttpCandidate(PriceFetchResult $result, Store $store): ?PriceFetchResult
    {
        if ($result->status !== PriceFetchStatus::Success) {
            return null;
        }

        $currency = strtoupper((string) ($store->currency ?: 'USD'));
        try {
            $winner = $this->candidateSelector->select(
                $result->toSelectorCandidates(),
                expectedCurrency: $currency,
            );
        } catch (ValidationException) {
            return null;
        }

        return new PriceFetchResult(
            status: PriceFetchStatus::Success,
            source: $winner['source'],
            engine: $result->engine,
            finalUrl: $result->finalUrl,
            title: $result->title,
            image: $result->image,
            availability: $result->availability,
            candidates: [[
                'amount' => $winner['price'],
                'currency' => $currency,
                'confidence' => $winner['confidence'],
                'evidence' => $winner['source'],
            ]],
            observedAt: $result->observedAt,
            latencyMs: $result->latencyMs,
            seller: $result->seller,
            pricesNormalized: $result->pricesNormalized,
        );
    }

    /** @param array<string, mixed> $payload */
    private function fromFallbackPayload(string $url, Store $store, array $payload, int $startedAt): PriceFetchResult
    {
        $engine = $store->scraper_service === ScraperService::Api->value
            ? 'seleniumbase'
            : 'store_http';
        $title = $this->nullableString($payload['title'] ?? null);
        $image = $this->nullableString($payload['image'] ?? null);
        $availability = $this->nullableString($payload['availability'] ?? null);
        $body = is_string($payload['body'] ?? null) ? $payload['body'] : '';
        $rawErrors = is_array($payload['errors'] ?? null) ? array_values($payload['errors']) : [];
        $fetchError = is_array($payload['fetch_error'] ?? null) ? $payload['fetch_error'] : null;
        $notFound = (bool) ($payload[ScrapeUrl::NOT_FOUND_KEY] ?? false);

        if ($fetchError !== null) {
            $status = $this->statusForErrorKind((string) ($fetchError['kind'] ?? 'invalid_response'));

            return $this->fallbackFailure(
                $status,
                $url,
                $engine,
                $title,
                $image,
                $availability,
                $body,
                $rawErrors,
                $startedAt,
                (bool) ($fetchError['retryable'] ?? false),
                $notFound,
            );
        }

        $diagnosticText = Str::lower($body.' '.json_encode($rawErrors));
        if ($this->looksLikeChallenge($diagnosticText)) {
            return $this->fallbackFailure(
                PriceFetchStatus::Challenge,
                $url,
                $engine,
                $title,
                $image,
                $availability,
                $body,
                $rawErrors,
                $startedAt,
                true,
                $notFound,
            );
        }
        if ($rawErrors !== []) {
            $status = str_contains($diagnosticText, 'timed out') || str_contains($diagnosticText, 'timeout')
                ? PriceFetchStatus::Timeout
                : PriceFetchStatus::InvalidResponse;

            return $this->fallbackFailure(
                $status,
                $url,
                $engine,
                $title,
                $image,
                $availability,
                $body,
                $rawErrors,
                $startedAt,
                $status === PriceFetchStatus::Timeout,
                $notFound,
            );
        }

        $rawPrice = $payload['price'] ?? null;
        $price = CurrencyHelper::toFloat($rawPrice, locale: $store->locale, iso: $store->currency);
        if ($title === null || $rawPrice === null || $rawPrice === '' || $price <= 0) {
            return $this->fallbackFailure(
                PriceFetchStatus::NoPrice,
                $url,
                $engine,
                $title,
                $image,
                $availability,
                $body,
                $rawErrors,
                $startedAt,
                false,
                $notFound,
            );
        }

        return new PriceFetchResult(
            status: PriceFetchStatus::Success,
            source: 'store_strategy',
            engine: $engine,
            finalUrl: $url,
            title: $title,
            image: $image,
            availability: $availability,
            candidates: [[
                'amount' => is_int($rawPrice) || is_float($rawPrice) || is_string($rawPrice) ? $rawPrice : $price,
                'currency' => strtoupper((string) ($store->currency ?: 'USD')),
                'confidence' => 1.0,
                'evidence' => 'store_strategy',
            ]],
            observedAt: new DateTimeImmutable,
            latencyMs: $this->latencyMs($startedAt),
            body: $body,
            notFound: $notFound,
        );
    }

    private function fromFallbackException(
        string $url,
        Store $store,
        Throwable $exception,
        int $startedAt,
    ): PriceFetchResult {
        $message = Str::lower($exception->getMessage());
        $status = str_contains($message, 'timed out') || str_contains($message, 'timeout')
            ? PriceFetchStatus::Timeout
            : PriceFetchStatus::NetworkError;

        return $this->fallbackFailure(
            $status,
            $url,
            $store->scraper_service === ScraperService::Api->value ? 'seleniumbase' : 'store_http',
            null,
            null,
            null,
            '',
            [],
            $startedAt,
            true,
        );
    }

    /** @param list<mixed> $rawErrors */
    private function fallbackFailure(
        PriceFetchStatus $status,
        string $url,
        string $engine,
        ?string $title,
        ?string $image,
        ?string $availability,
        string $body,
        array $rawErrors,
        int $startedAt,
        bool $retryable,
        bool $notFound = false,
    ): PriceFetchResult {
        return new PriceFetchResult(
            status: $status,
            source: 'store_strategy',
            engine: $engine,
            finalUrl: $url,
            title: $title,
            image: $image,
            availability: $availability,
            observedAt: new DateTimeImmutable,
            latencyMs: $this->latencyMs($startedAt),
            error: ['kind' => $status->value, 'retryable' => $retryable],
            body: $body,
            rawErrors: $rawErrors,
            notFound: $notFound,
        );
    }

    private function mergeMetadata(PriceFetchResult $primary, PriceFetchResult $fallback): PriceFetchResult
    {
        return new PriceFetchResult(
            status: $fallback->status,
            source: $fallback->source,
            engine: $fallback->engine,
            finalUrl: $fallback->finalUrl,
            title: $fallback->title ?? $primary->title,
            image: $fallback->image ?? $primary->image,
            availability: $this->preferredAvailability($fallback->availability, $primary->availability),
            candidates: $fallback->candidates,
            observedAt: $fallback->observedAt,
            latencyMs: $primary->latencyMs + $fallback->latencyMs,
            error: $fallback->error,
            seller: $fallback->seller ?? $primary->seller,
            body: $fallback->body,
            rawErrors: $fallback->rawErrors,
            notFound: $fallback->notFound,
            pricesNormalized: $fallback->pricesNormalized,
        );
    }

    private function preferredAvailability(?string $fallback, ?string $primary): ?string
    {
        return $fallback !== null && $fallback !== 'unknown' ? $fallback : ($primary ?? $fallback);
    }

    private function looksLikeChallenge(string $text): bool
    {
        return Str::contains($text, [
            'access denied',
            'are you a human',
            'verify you are human',
            'checking your browser',
            'robot or human',
            'validatecaptcha',
            'press and hold to confirm',
        ]);
    }

    private function statusForErrorKind(string $kind): PriceFetchStatus
    {
        return PriceFetchStatus::tryFrom($kind) ?? PriceFetchStatus::InvalidResponse;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function latencyMs(int $startedAt): int
    {
        return max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000));
    }
}
