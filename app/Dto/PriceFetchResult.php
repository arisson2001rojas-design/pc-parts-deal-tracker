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
     * @param  list<array{amount: int|float|string, currency: string, confidence: float, evidence: string}>  $candidates
     * @param  array{kind: string, retryable: bool}|null  $error
     * @param  list<mixed>  $rawErrors
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
    ) {}

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

        return [
            'store' => $store,
            'status' => $this->status->value,
            'source' => $this->source,
            'engine' => $this->engine,
            'final_url' => $this->finalUrl,
            'title' => $this->title,
            'price' => $candidate['amount'] ?? null,
            'currency' => $candidate['currency'] ?? null,
            'image' => $this->image,
            'availability' => $this->availability,
            'observed_at' => $this->observedAt->format(DATE_ATOM),
            'latency_ms' => $this->latencyMs,
            'fetch_error' => $this->error,
            'body' => $this->body,
            'errors' => $this->rawErrors,
        ];
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
            'candidates' => $this->candidates,
            'observedAt' => $this->observedAt->format(DATE_ATOM),
            'latencyMs' => $this->latencyMs,
            'error' => $this->error,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
