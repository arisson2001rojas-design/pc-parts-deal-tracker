<?php

namespace Tests\Feature;

use App\Enums\ComponentType;
use App\Models\DealOffer;
use App\Models\DealSearch;
use App\Models\User;
use App\Services\DealHunterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManualDealPriceConfirmationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_manual_price_confirmation_is_verified_and_recorded_in_history(): void
    {
        $offer = $this->offer();

        resolve(DealHunterService::class)->confirmPrice($offer, 299.99);

        $offer->refresh();
        $this->assertSame(DealOffer::USER_CONFIRMED_SOURCE, $offer->source);
        $this->assertSame('299.99', $offer->price);
        $this->assertTrue($offer->hasVerifiedPrice());
        $this->assertDatabaseHas('deal_offer_prices', [
            'deal_offer_id' => $offer->getKey(),
            'price' => 299.99,
            'source' => DealOffer::USER_CONFIRMED_SOURCE,
        ]);
    }

    public function test_a_manual_confirmation_expires_after_the_configured_window(): void
    {
        config()->set('deal_hunter.user_confirmed_price_hours', 24);
        $offer = $this->offer();
        $offer->forceFill([
            'price' => 299.99,
            'source' => DealOffer::USER_CONFIRMED_SOURCE,
            'fetched_at' => now()->subHours(25),
        ])->save();

        $offer->refresh();
        $this->assertFalse($offer->hasVerifiedPrice());
        $this->assertFalse(DealOffer::query()->verifiedPrice()->whereKey($offer)->exists());
    }

    private function offer(): DealOffer
    {
        $search = DealSearch::query()->create([
            'user_id' => User::factory()->create()->getKey(),
            'name' => 'GPU manual price',
            'query' => 'Radeon RX 7800 XT',
            'component_type' => ComponentType::Gpu,
            'enabled' => true,
        ]);

        $url = 'https://www.newegg.com/p/N82E16800000999';

        return DealOffer::query()->create([
            'deal_search_id' => $search->getKey(),
            'store' => 'Newegg',
            'title' => 'Radeon RX 7800 XT',
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'source' => 'web_index',
            'fetched_at' => now(),
        ]);
    }
}
