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
            $this->markLatestAttemptDecision($result, 'candidate_rejected');

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
            httpStatus: $result->httpStatus,
            attempts: $result->attempts,
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
        $httpStatus = $this->normalizedHttpStatus($payload['http_status'] ?? $fetchError['http_status'] ?? null);

        if ($fetchError !== null) {
            $status = $this->statusForFetchError($fetchError);

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
                $httpStatus,
                $this->normalizedReason($fetchError['reason'] ?? null),
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
                $httpStatus,
                'challenge_detected',
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
                $httpStatus,
                $status === PriceFetchStatus::Timeout ? 'fallback_timeout' : 'fallback_error',
            );
        }

        $rawPrice = $payload['price'] ?? null;
        $priceParse = CurrencyHelper::parsePrice(
            $rawPrice,
            locale: $store->locale,
            iso: $store->currency,
            fallbackLocale: $store->price_locale_fallback,
        );
        if ($title === null || $rawPrice === null || $rawPrice === '' || $priceParse['amount'] === null) {
            $failure = $this->fallbackFailure(
                $priceParse['decision'] === 'locale_mismatch'
                    ? PriceFetchStatus::InvalidResponse
                    : PriceFetchStatus::NoPrice,
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
                $httpStatus,
                $priceParse['decision'] === 'locale_mismatch' ? 'locale_mismatch' : 'missing_price_or_title',
            );

            $this->markLatestAttemptParsing($failure, $priceParse);

            return $failure;
        }

        $result = new PriceFetchResult(
            status: PriceFetchStatus::Success,
            source: 'store_strategy',
            engine: $engine,
            finalUrl: $url,
            title: $title,
            image: $image,
            availability: $availability,
            candidates: [[
                'amount' => $priceParse['amount'],
                'raw_amount' => $rawPrice,
                'currency' => strtoupper((string) ($store->currency ?: 'USD')),
                'confidence' => 1.0,
                'evidence' => 'store_strategy',
            ]],
            observedAt: new DateTimeImmutable,
            latencyMs: $this->latencyMs($startedAt),
            body: $body,
            notFound: $notFound,
            pricesNormalized: true,
            httpStatus: $httpStatus,
        );

        $this->markLatestAttemptParsing($result, $priceParse);

        return $result;
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
            reason: $this->normalizedReason($exception->getMessage()),
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
        ?int $httpStatus = null,
        ?string $reason = null,
    ): PriceFetchResult {
        $error = ['kind' => $status->value, 'retryable' => $retryable];
        if ($httpStatus !== null) {
            $error['http_status'] = $httpStatus;
        }
        if ($reason !== null) {
            $error['reason'] = $reason;
        }

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
            error: $error,
            body: $body,
            rawErrors: $rawErrors,
            notFound: $notFound,
            httpStatus: $httpStatus,
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
            httpStatus: $fallback->httpStatus,
            attempts: [...$primary->attempts, ...$fallback->attempts],
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

    /** @param array<string, mixed> $fetchError */
    private function statusForFetchError(array $fetchError): PriceFetchStatus
    {
        foreach (['kind', 'status'] as $key) {
            $value = $fetchError[$key] ?? null;
            if (is_string($value) && ($status = PriceFetchStatus::tryFrom($value)) !== null) {
                return $status;
            }
        }

        return PriceFetchStatus::InvalidResponse;
    }

    private function markLatestAttemptDecision(PriceFetchResult $result, string $decision): void
    {
        $index = array_key_last($result->attempts);
        if ($index !== null) {
            $result->attempts[$index]['decision'] = $decision;
        }
    }

    /** @param array{amount: ?float, locale: ?string, decision: string} $priceParse */
    private function markLatestAttemptParsing(PriceFetchResult $result, array $priceParse): void
    {
        if (! in_array($priceParse['decision'], ['locale_fallback', 'locale_mismatch'], true)) {
            return;
        }

        $index = array_key_last($result->attempts);
        if ($index === null) {
            return;
        }

        $result->attempts[$index]['decision'] = $priceParse['decision'];
        if ($priceParse['locale'] !== null) {
            $result->attempts[$index]['parse_locale'] = $priceParse['locale'];
        }
    }

    private function normalizedHttpStatus(mixed $status): ?int
    {
        if (! is_numeric($status)) {
            return null;
        }

        $status = (int) $status;

        return $status >= 100 && $status <= 599 ? $status : null;
    }

    private function normalizedReason(mixed $reason): ?string
    {
        if (! is_string($reason)) {
            return null;
        }

        $reason = preg_replace(
            [
                '/(\b(?:authorization|api[_-]?key|token|password|secret)\s*[:=]\s*)(?:bearer\s+)?[^,\s;]+/i',
                '/\bbearer\s+[a-z0-9._~+\/=\-]+/i',
                '/\s+/',
            ],
            ['$1[redacted]', 'Bearer [redacted]', ' '],
            trim($reason),
        );

        return is_string($reason) && $reason !== '' ? Str::limit($reason, 160, '') : null;
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
