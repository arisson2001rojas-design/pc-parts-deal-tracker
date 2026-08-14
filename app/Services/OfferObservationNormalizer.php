<?php

namespace App\Services;

use App\Dto\OfferObservation;
use App\Enums\FulfillmentType;
use App\Enums\OfferCondition;
use App\Enums\OfferEvidenceQuality;
use App\Enums\OfferPurchasability;
use App\Enums\OfferScope;
use App\Enums\SellerType;
use BackedEnum;
use Illuminate\Support\Str;

final class OfferObservationNormalizer
{
    public function normalize(array $payload): OfferObservation
    {
        [$seller, $sellerRejection] = $this->seller($payload['seller'] ?? null);
        [$sellerType, $sellerTypeConflict] = $this->sellerType($payload, $seller);
        $condition = $this->enumValue($payload['condition'] ?? null, OfferCondition::class)
            ?? $this->conditionAlias($payload['condition'] ?? null)
            ?? OfferCondition::Unknown;
        $offerScope = $this->enumValue($payload['offer_scope'] ?? null, OfferScope::class)
            ?? OfferScope::Unknown;
        $purchasability = $this->enumValue($payload['purchasability'] ?? null, OfferPurchasability::class)
            ?? $this->purchasabilityAlias($payload['purchasability'] ?? null)
            ?? OfferPurchasability::Unknown;
        $fulfillmentType = $this->enumValue($payload['fulfillment_type'] ?? null, FulfillmentType::class)
            ?? FulfillmentType::Unknown;
        $evidenceQuality = $this->enumValue($payload['evidence_quality'] ?? null, OfferEvidenceQuality::class)
            ?? OfferEvidenceQuality::Ambiguous;
        if (($sellerRejection !== null || $sellerTypeConflict) && $evidenceQuality === OfferEvidenceQuality::Reliable) {
            $evidenceQuality = OfferEvidenceQuality::Ambiguous;
        }

        $bundle = $this->nullableBoolean($payload['bundle'] ?? null);
        $availability = in_array($payload['availability'] ?? null, ['in_stock', 'out_of_stock', 'unknown'], true)
            ? $payload['availability']
            : 'unknown';
        $evidence = $this->offerEvidence($payload['offer_evidence'] ?? null);
        if ($this->hasEvidenceConflict($evidence) && $evidenceQuality === OfferEvidenceQuality::Reliable) {
            $evidenceQuality = OfferEvidenceQuality::Ambiguous;
        }
        if ($sellerRejection !== null) {
            $evidence['seller_rejection'] = $sellerRejection;
        }
        if ($sellerTypeConflict) {
            $evidence['seller_type_marketplace_conflict'] = true;
        }

        $comparisonEligible = $seller !== null
            && $sellerType === SellerType::Retailer
            && $condition === OfferCondition::New
            && $offerScope === OfferScope::Primary
            && $purchasability === OfferPurchasability::Active
            && $evidenceQuality === OfferEvidenceQuality::Reliable
            && $bundle !== true
            && $availability === 'in_stock';

        return new OfferObservation(
            seller: $seller,
            sellerType: $sellerType,
            condition: $condition,
            offerScope: $offerScope,
            purchasability: $purchasability,
            fulfillmentType: $fulfillmentType,
            evidenceQuality: $evidenceQuality,
            bundle: $bundle,
            availability: $availability,
            comparisonEligible: $comparisonEligible,
            evidence: $evidence,
        );
    }

    /** @return array{?string, ?string} */
    private function seller(mixed $value): array
    {
        if (! is_string($value)) {
            return [null, null];
        }

        $seller = Str::of($value)->squish()->limit(255, '')->toString();
        if ($seller === '') {
            return [null, null];
        }

        if (filter_var($seller, FILTER_VALIDATE_URL) !== false
            || preg_match('/(?:https?:\/\/|www\.)\S+/i', $seller) === 1) {
            return [null, 'url'];
        }

        if (preg_match('/^(?:(?:usd|cad|aud|eur|gbp|crc|us)\s*)?[$€£₡]?\s*\d[\d,.]*(?:\s*(?:usd|cad|aud|eur|gbp|crc))?$/i', $seller) === 1) {
            return [null, 'price'];
        }

        $normalized = trim(
            Str::of($seller)->lower()->ascii()->squish()->toString(),
            " \t\n\r\0\x0B:.-",
        );
        $boilerplate = preg_match(
            '/^(?:seller|vendor|sold by|vendido por|seller information|vendor information|learn more about (?:the )?seller|more information about (?:the )?seller|mas informacion (?:acerca|sobre) del vendedor)$/',
            $normalized,
        ) === 1
            || preg_match('/^m\S*\s+informaci\S*n\s+(?:acerca|sobre)\s+del\s+vendedor$/', $normalized) === 1
            || preg_match('/^(?:visit (?:the )?store|see (?:more|details)|click here|navigation|menu|link|button|accessibility)$/', $normalized) === 1;
        if ($boilerplate) {
            return [null, 'boilerplate'];
        }

        if (preg_match('/\b(?:free shipping|ships? from|shipping|delivery|returns?|return policy)\b/', $normalized) === 1) {
            return [null, 'shipping_or_returns'];
        }

        return [$seller, null];
    }

    /** @return array<string, bool|string> */
    private function offerEvidence(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $evidence = [];
        foreach (['source', 'seller_source', 'condition_source', 'fulfillment_source', 'conflict'] as $key) {
            $item = $value[$key] ?? null;
            if (is_bool($item)) {
                $evidence[$key] = $item;

                continue;
            }
            if (! is_string($item)) {
                continue;
            }

            $item = Str::of(strip_tags($item))->squish()->limit(120, '')->toString();
            if ($item !== '') {
                $evidence[$key] = $item;
            }
        }

        return $evidence;
    }

    /** @param array<string, bool|string> $evidence */
    private function hasEvidenceConflict(array $evidence): bool
    {
        $conflict = $evidence['conflict'] ?? null;

        return $conflict === true || (is_string($conflict) && $conflict !== '');
    }

    /** @return array{SellerType, bool} */
    private function sellerType(array $payload, ?string $seller): array
    {
        $explicitToken = $this->normalizedToken($payload['seller_type'] ?? null);
        $explicit = $this->enumValue($payload['seller_type'] ?? null, SellerType::class);
        if ($explicit instanceof SellerType) {
            $marketplace = $this->nullableBoolean($payload['marketplace'] ?? null);
            $derivedMarketplace = match ($explicit) {
                SellerType::Retailer => false,
                SellerType::Marketplace => true,
                SellerType::Unknown => null,
            };

            return [
                $explicit,
                $derivedMarketplace !== null
                    && $marketplace !== null
                    && $marketplace !== $derivedMarketplace,
            ];
        }

        $marketplace = $this->nullableBoolean($payload['marketplace'] ?? null);

        return [
            match ($marketplace) {
                true => SellerType::Marketplace,
                false => $seller === null ? SellerType::Unknown : SellerType::Retailer,
                null => SellerType::Unknown,
            },
            $explicitToken !== null,
        ];
    }

    private function conditionAlias(mixed $value): ?OfferCondition
    {
        return match ($this->normalizedToken($value)) {
            'brand_new' => OfferCondition::New,
            'pre_owned' => OfferCondition::Preowned,
            'factory_refurbished', 'refurb' => OfferCondition::Refurbished,
            'openbox' => OfferCondition::OpenBox,
            default => null,
        };
    }

    private function purchasabilityAlias(mixed $value): ?OfferPurchasability
    {
        return match ($this->normalizedToken($value)) {
            'buying_options_only' => OfferPurchasability::BuyingChoicesOnly,
            default => null,
        };
    }

    /**
     * @template T of BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return T|null
     */
    private function enumValue(mixed $value, string $enum): ?BackedEnum
    {
        $token = $this->normalizedToken($value);

        return $token === null ? null : $enum::tryFrom($token);
    }

    private function normalizedToken(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Str::of($value)->trim()->lower()->replace(['-', ' '], '_')->toString();
    }

    private function nullableBoolean(mixed $value): ?bool
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
    }
}
