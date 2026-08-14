<?php

namespace App\Dto;

use App\Enums\FulfillmentType;
use App\Enums\OfferCondition;
use App\Enums\OfferEvidenceQuality;
use App\Enums\OfferPurchasability;
use App\Enums\OfferScope;
use App\Enums\SellerType;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * Normalized context for one currently observed retailer offer.
 *
 * Offer provenance is deliberately separate from physical hardware identity.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class OfferObservation implements Arrayable, JsonSerializable
{
    /** @param array<string, mixed> $evidence */
    public function __construct(
        public ?string $seller,
        public SellerType $sellerType,
        public OfferCondition $condition,
        public OfferScope $offerScope,
        public OfferPurchasability $purchasability,
        public FulfillmentType $fulfillmentType,
        public OfferEvidenceQuality $evidenceQuality,
        public ?bool $bundle,
        public string $availability,
        public bool $comparisonEligible,
        public array $evidence = [],
    ) {}

    public function marketplace(): ?bool
    {
        return match ($this->sellerType) {
            SellerType::Retailer => false,
            SellerType::Marketplace => true,
            SellerType::Unknown => null,
        };
    }

    /** @return array<string, mixed> */
    public function toDealOfferAttributes(): array
    {
        return [
            'seller' => $this->seller,
            'availability' => $this->availability,
            'seller_type' => $this->sellerType->value,
            'condition' => $this->condition->value,
            'offer_scope' => $this->offerScope->value,
            'purchasability' => $this->purchasability->value,
            'fulfillment_type' => $this->fulfillmentType->value,
            'evidence_quality' => $this->evidenceQuality->value,
            'bundle' => $this->bundle,
            'comparison_eligible' => $this->comparisonEligible,
            'offer_evidence' => $this->evidence,
        ];
    }

    /** @return array<string, mixed> */
    public function toHardwareEvidenceAttributes(): array
    {
        return [
            'seller_type' => $this->sellerType->value,
            'condition' => $this->condition->value,
            'marketplace' => $this->marketplace(),
            'bundle' => $this->bundle,
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->toDealOfferAttributes();
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
