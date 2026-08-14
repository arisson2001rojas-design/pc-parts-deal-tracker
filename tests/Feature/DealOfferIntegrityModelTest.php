<?php

namespace Tests\Feature;

use App\Enums\FulfillmentType;
use App\Enums\OfferCondition;
use App\Enums\OfferEvidenceQuality;
use App\Enums\OfferPurchasability;
use App\Enums\OfferScope;
use App\Enums\SellerType;
use App\Models\DealOffer;
use App\Models\DealSearch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DealOfferIntegrityModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_additive_offer_integrity_columns_are_available_on_latest_and_snapshot_rows(): void
    {
        $columns = [
            'seller_type',
            'condition',
            'offer_scope',
            'purchasability',
            'fulfillment_type',
            'evidence_quality',
            'bundle',
            'comparison_eligible',
            'offer_evidence',
        ];

        $this->assertTrue(Schema::hasColumns('deal_offers', $columns));
        $this->assertTrue(Schema::hasColumns('deal_offer_prices', [
            'seller',
            'availability',
            ...$columns,
        ]));
    }

    public function test_legacy_null_integrity_keeps_the_previous_verified_price_semantics(): void
    {
        $offer = $this->offer();

        $this->assertTrue($offer->hasObservedPrice());
        $this->assertTrue($offer->hasVerifiedPrice());
        $this->assertTrue(DealOffer::query()->observedPrice()->whereKey($offer)->exists());
        $this->assertTrue(DealOffer::query()->verifiedPrice()->whereKey($offer)->exists());
    }

    public function test_an_explicitly_incomparable_price_is_observed_and_snapshotted_but_not_verified(): void
    {
        $offer = $this->offer([
            ...$this->integrityAttributes(),
            'seller_type' => SellerType::Marketplace,
            'comparison_eligible' => false,
        ]);

        $this->assertTrue($offer->hasObservedPrice());
        $this->assertFalse($offer->hasVerifiedPrice());
        $this->assertTrue(DealOffer::query()->observedPrice()->whereKey($offer)->exists());
        $this->assertFalse(DealOffer::query()->verifiedPrice()->whereKey($offer)->exists());

        $snapshot = $offer->recordPriceSnapshot();

        $this->assertNotNull($snapshot);
        $this->assertSame(SellerType::Marketplace, $snapshot->seller_type);
        $this->assertSame(OfferCondition::New, $snapshot->condition);
        $this->assertFalse($snapshot->comparison_eligible);
        $this->assertSame(['seller_source' => 'buy_box'], $snapshot->offer_evidence);
    }

    public function test_an_explicitly_comparable_price_is_verified(): void
    {
        $offer = $this->offer($this->integrityAttributes());

        $this->assertTrue($offer->hasObservedPrice());
        $this->assertTrue($offer->hasVerifiedPrice());
        $this->assertTrue(DealOffer::query()->verifiedPrice()->whereKey($offer)->exists());
    }

    public function test_invalid_new_evidence_is_not_an_observed_or_verified_price(): void
    {
        $offer = $this->offer([
            ...$this->integrityAttributes(),
            'evidence_quality' => OfferEvidenceQuality::Invalid,
            'comparison_eligible' => false,
        ]);

        $this->assertFalse($offer->hasObservedPrice());
        $this->assertFalse($offer->hasVerifiedPrice());
        $this->assertFalse(DealOffer::query()->observedPrice()->whereKey($offer)->exists());
        $this->assertFalse(DealOffer::query()->verifiedPrice()->whereKey($offer)->exists());
        $this->assertNull($offer->recordPriceSnapshot());
    }

    public function test_same_price_snapshot_deduplication_includes_material_offer_context(): void
    {
        $offer = $this->offer($this->integrityAttributes());
        $first = $offer->recordPriceSnapshot();

        $offer->forceFill(['fetched_at' => now()->addMinute()])->save();
        $same = $offer->recordPriceSnapshot();

        $this->assertSame($first?->getKey(), $same?->getKey());
        $this->assertDatabaseCount('deal_offer_prices', 1);

        $offer->forceFill([
            'seller' => 'Marketplace Seller',
            'seller_type' => SellerType::Marketplace,
            'comparison_eligible' => false,
            'fetched_at' => now()->addMinutes(2),
        ])->save();
        $changed = $offer->recordPriceSnapshot();

        $this->assertNotSame($first?->getKey(), $changed?->getKey());
        $this->assertDatabaseCount('deal_offer_prices', 2);
        $this->assertSame('Marketplace Seller', $changed?->seller);
        $this->assertSame(SellerType::Marketplace, $changed?->seller_type);
        $this->assertFalse($changed?->comparison_eligible);
    }

    /** @param array<string, mixed> $attributes */
    private function offer(array $attributes = []): DealOffer
    {
        $user = User::factory()->create();
        $search = DealSearch::query()->create([
            'user_id' => $user->getKey(),
            'name' => 'GPU hunt',
            'query' => 'RTX 5070',
            'component_type' => 'gpu',
            'enabled' => true,
        ]);

        return $search->offers()->create([
            'store' => 'Newegg',
            'title' => 'RTX 5070',
            'url' => 'https://www.newegg.com/p/N82E16800000001',
            'url_hash' => hash('sha256', 'https://www.newegg.com/p/N82E16800000001'),
            'price' => 599.99,
            'currency' => 'USD',
            'source' => 'browser_discovery',
            'availability' => 'in_stock',
            'seller' => 'Newegg',
            'fetched_at' => now(),
            ...$attributes,
        ]);
    }

    /** @return array<string, mixed> */
    private function integrityAttributes(): array
    {
        return [
            'seller_type' => SellerType::Retailer,
            'condition' => OfferCondition::New,
            'offer_scope' => OfferScope::Primary,
            'purchasability' => OfferPurchasability::Active,
            'fulfillment_type' => FulfillmentType::Retailer,
            'evidence_quality' => OfferEvidenceQuality::Reliable,
            'bundle' => false,
            'comparison_eligible' => true,
            'offer_evidence' => ['seller_source' => 'buy_box'],
        ];
    }
}
