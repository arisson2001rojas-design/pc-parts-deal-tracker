<?php

namespace App\Dto;

use App\Enums\PriceUpdateStatus;
use App\Models\Price;

final readonly class PriceUpdateOutcome
{
    private function __construct(
        public PriceUpdateStatus $status,
        public ?Price $price = null,
        public bool $retryable = false,
        public ?string $reason = null,
        private ?Price $legacyResult = null,
    ) {}

    public static function accepted(Price $price): self
    {
        return new self(PriceUpdateStatus::Accepted, $price, legacyResult: $price);
    }

    public static function unchanged(?Price $price = null, ?string $reason = null): self
    {
        return new self(PriceUpdateStatus::Unchanged, $price, reason: $reason, legacyResult: $price);
    }

    public static function rejected(?string $reason = null, ?Price $legacyResult = null): self
    {
        return new self(PriceUpdateStatus::Rejected, reason: $reason, legacyResult: $legacyResult);
    }

    public static function failed(bool $retryable, ?string $reason = null): self
    {
        return new self(PriceUpdateStatus::Failed, retryable: $retryable, reason: $reason);
    }

    public static function skipped(?string $reason = null): self
    {
        return new self(PriceUpdateStatus::Skipped, reason: $reason);
    }

    public function shouldRetry(): bool
    {
        return $this->status === PriceUpdateStatus::Failed && $this->retryable;
    }

    public function legacyResult(): ?Price
    {
        return $this->legacyResult;
    }
}
