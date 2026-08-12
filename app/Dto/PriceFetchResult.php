<?php

namespace App\Dto;

use App\Enums\PriceFetchStatus;
use App\Models\Store;
use DateTimeImmutable;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * Normalized result shared by retailer HTTP extraction and configured-store fallback.
 *
 * @implements Arrayable<string, mixed>
 */
final class PriceFetchResult implements Arrayable, JsonSerializable
{
    /**
     * @param  list<array{amount: int|float|string, currency: string, confidence: float, evidence: string, raw_amount?: int|float|string}>  $candidates
     * @param  array{kind: string, retryable: bool, http_status?: int, reason?: string, expected_currency?: string, detected_currency?: string}|null  $error
     * @param  list<mixed>  $rawErrors
     * @param  list<array{engine: string, source: string, status: string, latency_ms: int, final_url: string, error: array<string, mixed>|null, observed_at: string, http_status?: int, decision?: string, parse_locale?: string, expected_currency?: string, detected_currency?: string}>  $attempts
     */
    public function __construct(
        public PriceFetchStatus $status,
        public string $source,
        public string $engine,
        public string $finalUrl,
        public ?string $title = null,
        public ?string $image = null,
        public ?string $availability = null,
        public array $candidates = [],
        public DateTimeImmutable $observedAt = new DateTimeImmutable,
        public int $latencyMs = 0,
        public ?array $error = null,
        public ?string $seller = null,
        public string $body = '',
        public array $rawErrors = [],
        public bool $notFound = false,
        public bool $pricesNormalized = false,
        public ?int $httpStatus = null,
        public array $attempts = [],
        public bool $availabilityNormalized = false,
    ) {
        if ($this->attempts === []) {
            $this->attempts = [$this->toAttemptArray()];
        }
    }

    /**
     * Adapter for the existing Url validation/persistence pipeline.
     *
     * @return array<string, mixed>
     */
    public function toScrapeArray(Store $store): array
    {
        $candidate = $this->status === PriceFetchStatus::Success
            ? ($this->candidates[0] ?? null)
            : null;

        $normalizedPrice = $candidate['amount'] ?? null;
        $publicPrice = $candidate['raw_amount'] ?? $normalizedPrice;

        $payload = [
            'store' => $store,
            'status' => $this->status->value,
            'source' => $this->source,
            'engine' => $this->engine,
            'final_url' => $this->finalUrl,
            'title' => $this->title,
            'price' => $publicPrice,
            'normalized_price' => $this->pricesNormalized && $candidate !== null ? $normalizedPrice : null,
            'price_normalized' => $this->pricesNormalized && $candidate !== null,
            'currency' => $candidate['currency'] ?? null,
            'image' => $this->image,
            'availability' => $this->availability,
            'availability_normalized' => $this->availabilityNormalized,
            'observed_at' => $this->observedAt->format(DATE_ATOM),
            'latency_ms' => $this->latencyMs,
            'attempts' => $this->attempts,
            'fetch_error' => $this->error,
            'body' => $this->body,
            'errors' => $this->rawErrors,
        ];

        if ($this->notFound) {
            $payload['not_found'] = true;
        }

        return $payload;
    }

    /**
     * Adapter retained for Deal Hunter's existing direct-extractor verification job.
     *
     * @return array{page_url: string, title: string, image_url: ?string, availability?: string, seller?: ?string, candidates: list<array{price: int|float|string, currency: string, confidence: float, source: string}>}|null
     */
    public function toExtractorPayload(): ?array
    {
        if ($this->status !== PriceFetchStatus::Success || $this->title === null || $this->candidates === []) {
            return null;
        }

        $payload = [
            'page_url' => $this->finalUrl,
            'title' => $this->title,
            'image_url' => $this->image,
            'candidates' => array_map(
                static fn (array $candidate): array => [
                    'price' => $candidate['amount'],
                    'currency' => $candidate['currency'],
                    'confidence' => $candidate['confidence'],
                    'source' => $candidate['evidence'],
                ],
                $this->candidates,
            ),
        ];

        if ($this->availability !== null) {
            $payload['availability'] = $this->availability;
        }
        if ($this->seller !== null) {
            $payload['seller'] = $this->seller;
        }

        return $payload;
    }

    /**
     * @return list<array{price: int|float|string, currency: string, confidence: float, source: string}>
     */
    public function toSelectorCandidates(): array
    {
        return array_map(
            static fn (array $candidate): array => [
                'price' => $candidate['amount'],
                'currency' => $candidate['currency'],
                'confidence' => $candidate['confidence'],
                'source' => $candidate['evidence'],
            ],
            $this->candidates,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'source' => $this->source,
            'engine' => $this->engine,
            'finalUrl' => $this->finalUrl,
            'title' => $this->title,
            'image' => $this->image,
            'availability' => $this->availability,
            'availabilityNormalized' => $this->availabilityNormalized,
            'candidates' => $this->candidates,
            'observedAt' => $this->observedAt->format(DATE_ATOM),
            'latencyMs' => $this->latencyMs,
            'error' => $this->error,
            'notFound' => $this->notFound,
            'pricesNormalized' => $this->pricesNormalized,
            'httpStatus' => $this->httpStatus,
            'attempts' => $this->attempts,
        ];
    }

    /**
     * @return array{engine: string, source: string, status: string, latency_ms: int, final_url: string, error: array<string, mixed>|null, observed_at: string, http_status?: int, decision?: string, parse_locale?: string, expected_currency?: string, detected_currency?: string}
     */
    private function toAttemptArray(): array
    {
        $attempt = [
            'engine' => $this->engine,
            'source' => $this->source,
            'status' => $this->status->value,
            'latency_ms' => $this->latencyMs,
            'final_url' => $this->finalUrl,
            'error' => $this->error,
            'observed_at' => $this->observedAt->format(DATE_ATOM),
        ];

        if ($this->httpStatus !== null) {
            $attempt['http_status'] = $this->httpStatus;
        }

        return $attempt;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
