<?php

namespace Tests\Unit\Services;

use App\Enums\FulfillmentType;
use App\Enums\OfferCondition;
use App\Enums\OfferEvidenceQuality;
use App\Enums\OfferPurchasability;
use App\Enums\OfferScope;
use App\Enums\SellerType;
use App\Services\OfferObservationNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OfferObservationNormalizerTest extends TestCase
{
    public function test_only_a_reliable_active_primary_new_first_party_offer_is_comparison_eligible(): void
    {
        $observation = (new OfferObservationNormalizer)->normalize($this->eligiblePayload());

        $this->assertSame('Newegg', $observation->seller);
        $this->assertSame(SellerType::Retailer, $observation->sellerType);
        $this->assertSame(OfferCondition::New, $observation->condition);
        $this->assertSame(OfferScope::Primary, $observation->offerScope);
        $this->assertSame(OfferPurchasability::Active, $observation->purchasability);
        $this->assertSame(FulfillmentType::Retailer, $observation->fulfillmentType);
        $this->assertSame(OfferEvidenceQuality::Reliable, $observation->evidenceQuality);
        $this->assertFalse($observation->marketplace());
        $this->assertTrue($observation->comparisonEligible);
        $this->assertTrue($observation->toDealOfferAttributes()['comparison_eligible']);
        $this->assertSame(false, $observation->toHardwareEvidenceAttributes()['marketplace']);
    }

    #[DataProvider('incomparableOfferProvider')]
    public function test_each_unsafe_or_incomparable_dimension_fails_closed(array $changes): void
    {
        $observation = (new OfferObservationNormalizer)->normalize([
            ...$this->eligiblePayload(),
            ...$changes,
        ]);

        $this->assertFalse($observation->comparisonEligible);
    }

    public static function incomparableOfferProvider(): iterable
    {
        yield 'marketplace seller' => [['seller_type' => 'marketplace']];
        yield 'unknown seller type' => [['seller_type' => 'unknown']];
        yield 'missing seller provenance' => [['seller' => null]];
        yield 'used' => [['condition' => 'used']];
        yield 'preowned' => [['condition' => 'preowned']];
        yield 'renewed' => [['condition' => 'renewed']];
        yield 'refurbished' => [['condition' => 'refurbished']];
        yield 'open box' => [['condition' => 'open_box']];
        yield 'unknown condition' => [['condition' => 'unknown']];
        yield 'secondary offer' => [['offer_scope' => 'secondary']];
        yield 'no current offer' => [['offer_scope' => 'none']];
        yield 'buying choices only' => [['purchasability' => 'buying_choices_only']];
        yield 'unavailable' => [['purchasability' => 'unavailable']];
        yield 'ambiguous evidence' => [['evidence_quality' => 'ambiguous']];
        yield 'invalid evidence' => [['evidence_quality' => 'invalid']];
        yield 'bundle' => [['bundle' => true]];
        yield 'out of stock' => [['availability' => 'out_of_stock']];
        yield 'unknown availability' => [['availability' => 'unknown']];
    }

    public function test_missing_integrity_fields_are_explicitly_unknown_and_ambiguous(): void
    {
        $observation = (new OfferObservationNormalizer)->normalize([]);

        $this->assertSame(SellerType::Unknown, $observation->sellerType);
        $this->assertSame(OfferCondition::Unknown, $observation->condition);
        $this->assertSame(OfferScope::Unknown, $observation->offerScope);
        $this->assertSame(OfferPurchasability::Unknown, $observation->purchasability);
        $this->assertSame(FulfillmentType::Unknown, $observation->fulfillmentType);
        $this->assertSame(OfferEvidenceQuality::Ambiguous, $observation->evidenceQuality);
        $this->assertNull($observation->marketplace());
        $this->assertFalse($observation->comparisonEligible);
    }

    public function test_marketplace_false_without_seller_provenance_does_not_infer_retailer(): void
    {
        $observation = (new OfferObservationNormalizer)->normalize([
            'marketplace' => false,
        ]);

        $this->assertNull($observation->seller);
        $this->assertSame(SellerType::Unknown, $observation->sellerType);
        $this->assertNull($observation->marketplace());
        $this->assertFalse($observation->comparisonEligible);
    }

    #[DataProvider('sellerBoilerplateProvider')]
    public function test_seller_boilerplate_is_rejected_and_downgrades_the_observation(string $seller): void
    {
        $observation = (new OfferObservationNormalizer)->normalize([
            ...$this->eligiblePayload(),
            'seller' => $seller,
        ]);

        $this->assertNull($observation->seller);
        $this->assertSame(OfferEvidenceQuality::Ambiguous, $observation->evidenceQuality);
        $this->assertContains($observation->evidence['seller_rejection'], [
            'boilerplate',
            'price',
            'shipping_or_returns',
            'url',
        ]);
        $this->assertFalse($observation->comparisonEligible);
    }

    public static function sellerBoilerplateProvider(): iterable
    {
        yield ['Learn more about the seller'];
        yield ['Seller information'];
        yield ['seller'];
        yield ['Más información acerca del vendedor'];
        yield ['MÃ¡s informaciÃ³n acerca del vendedor'];
        yield ['https://www.amazon.com/sp?seller=A123'];
        yield ['$129.99'];
        yield ['USD 129.99'];
        yield ['Ships from Amazon.com Returns accepted'];
        yield ['Accessibility'];
    }

    public function test_offer_evidence_is_allowlisted_compact_and_html_free(): void
    {
        $observation = (new OfferObservationNormalizer)->normalize([
            ...$this->eligiblePayload(),
            'offer_evidence' => [
                'seller_source' => '  <b>visible buy box</b>  ',
                'conflict' => false,
                'raw_html' => '<html>secret</html>',
            ],
        ]);

        $this->assertSame([
            'seller_source' => 'visible buy box',
            'conflict' => false,
        ], $observation->evidence);
    }

    public function test_conflicting_seller_type_and_compatibility_marketplace_flag_are_recorded_and_fail_closed(): void
    {
        $observation = (new OfferObservationNormalizer)->normalize([
            ...$this->eligiblePayload(),
            'seller_type' => 'retailer',
            'marketplace' => true,
        ]);

        $this->assertSame(SellerType::Retailer, $observation->sellerType);
        $this->assertSame(OfferEvidenceQuality::Ambiguous, $observation->evidenceQuality);
        $this->assertTrue($observation->evidence['seller_type_marketplace_conflict']);
        $this->assertFalse($observation->comparisonEligible);
    }

    public function test_unknown_seller_type_does_not_invent_a_marketplace_flag_conflict(): void
    {
        $observation = (new OfferObservationNormalizer)->normalize([
            'seller_type' => 'unknown',
            'marketplace' => false,
        ]);

        $this->assertSame(SellerType::Unknown, $observation->sellerType);
        $this->assertArrayNotHasKey('seller_type_marketplace_conflict', $observation->evidence);
        $this->assertFalse($observation->comparisonEligible);
    }

    public function test_structural_offer_conflict_downgrades_reliable_evidence(): void
    {
        $observation = (new OfferObservationNormalizer)->normalize([
            ...$this->eligiblePayload(),
            'offer_evidence' => ['conflict' => 'visible_structured_seller'],
        ]);

        $this->assertSame(OfferEvidenceQuality::Ambiguous, $observation->evidenceQuality);
        $this->assertSame('visible_structured_seller', $observation->evidence['conflict']);
        $this->assertFalse($observation->comparisonEligible);
    }

    public function test_marketplace_compatibility_input_is_used_only_when_seller_type_is_absent(): void
    {
        $observation = (new OfferObservationNormalizer)->normalize([
            'marketplace' => true,
        ]);

        $this->assertSame(SellerType::Marketplace, $observation->sellerType);
        $this->assertTrue($observation->marketplace());
    }

    public function test_legacy_aliases_are_normalized_without_collapsing_condition_classes(): void
    {
        $normalizer = new OfferObservationNormalizer;

        $this->assertSame(
            OfferCondition::Preowned,
            $normalizer->normalize(['condition' => 'pre-owned'])->condition,
        );
        $this->assertSame(
            OfferCondition::Refurbished,
            $normalizer->normalize(['condition' => 'factory-refurbished'])->condition,
        );
        $this->assertSame(
            OfferCondition::OpenBox,
            $normalizer->normalize(['condition' => 'openbox'])->condition,
        );
        $this->assertSame(
            OfferPurchasability::BuyingChoicesOnly,
            $normalizer->normalize(['purchasability' => 'buying-options-only'])->purchasability,
        );
    }

    /** @return array<string, mixed> */
    private function eligiblePayload(): array
    {
        return [
            'seller' => ' Newegg ',
            'seller_type' => 'retailer',
            'condition' => 'new',
            'offer_scope' => 'primary',
            'purchasability' => 'active',
            'fulfillment_type' => 'retailer',
            'evidence_quality' => 'reliable',
            'bundle' => false,
            'availability' => 'in_stock',
            'offer_evidence' => ['seller_source' => 'buy_box'],
        ];
    }
}
