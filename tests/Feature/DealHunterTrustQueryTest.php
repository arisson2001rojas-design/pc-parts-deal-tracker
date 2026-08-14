<?php

namespace Tests\Feature;

use App\Enums\ComponentType;
use App\Filament\Resources\DealOfferResource;
use App\Filament\Resources\DealOfferResource\Widgets\DealOfferPriceHistoryChart;
use App\Filament\Resources\DealSearchResource;
use App\Models\DealOffer;
use App\Models\DealSearch;
use App\Models\User;
use App\Notifications\DealFoundNotification;
use App\Services\DealHunterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class DealHunterTrustQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_target_notification_uses_the_cheapest_available_comparison_eligible_offer(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $search = $this->search($user, targetPrice: 100);

        $this->offer($search, 10, [
            'source' => 'browser_discovery',
            'comparison_eligible' => false,
            'availability' => DealOffer::AVAILABILITY_IN_STOCK,
        ]);
        $this->offer($search, 20, [
            'source' => 'browser_discovery',
            'comparison_eligible' => true,
            'availability' => DealOffer::AVAILABILITY_OUT_OF_STOCK,
        ]);
        $this->offer($search, 30, [
            'source' => 'browser_discovery',
            'comparison_eligible' => true,
            'availability' => DealOffer::AVAILABILITY_IN_STOCK,
        ]);

        resolve(DealHunterService::class)->notifyWhenTargetReached($search);

        Notification::assertSentTo(
            $user,
            DealFoundNotification::class,
            fn (DealFoundNotification $notification): bool => data_get(
                $notification->toDatabase($user),
                'title',
            ) === 'Oferta encontrada: $30.00',
        );
        $this->assertSame('30.00', $search->fresh()->last_notified_price);
    }

    public function test_legacy_direct_and_user_confirmed_prices_remain_target_eligible(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $search = $this->search($user, targetPrice: 100);

        $this->offer($search, 60, [
            'source' => 'direct_extract',
            'comparison_eligible' => null,
            'availability' => DealOffer::AVAILABILITY_UNKNOWN,
        ]);
        $this->offer($search, 50, [
            'source' => DealOffer::USER_CONFIRMED_SOURCE,
            'comparison_eligible' => null,
            'availability' => DealOffer::AVAILABILITY_UNKNOWN,
        ]);

        resolve(DealHunterService::class)->notifyWhenTargetReached($search);

        Notification::assertSentTo(
            $user,
            DealFoundNotification::class,
            fn (DealFoundNotification $notification): bool => data_get(
                $notification->toDatabase($user),
                'title',
            ) === 'Oferta encontrada: $50.00',
        );
    }

    public function test_hunt_minimum_uses_only_verified_comparable_prices_and_preserves_legacy_rows(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $search = $this->search($user);

        $this->offer($search, 10, [
            'source' => 'browser_discovery',
            'comparison_eligible' => false,
        ]);
        $this->offer($search, 80, [
            'source' => 'browser_discovery',
            'comparison_eligible' => true,
        ]);
        $this->offer($search, 70, [
            'source' => 'direct_extract',
            'comparison_eligible' => null,
        ]);
        $this->offer($search, 1, [
            'source' => 'web_index',
            'comparison_eligible' => null,
        ]);

        $record = DealSearchResource::getEloquentQuery()->findOrFail($search->getKey());

        $this->assertSame(70.0, (float) $record->offers_min_price);
    }

    public function test_deal_offer_histories_exclude_non_comparable_snapshots_but_keep_legacy_rows(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $offer = $this->offer($this->search($user), 100, [
            'source' => 'browser_discovery',
            'comparison_eligible' => true,
        ]);

        $offer->priceSnapshots()->create([
            'price' => 120,
            'currency' => 'USD',
            'source' => 'direct_extract',
            'comparison_eligible' => null,
            'captured_at' => now()->subHours(2),
        ]);
        $offer->priceSnapshots()->create([
            'price' => 100,
            'currency' => 'USD',
            'source' => 'browser_discovery',
            'comparison_eligible' => true,
            'captured_at' => now()->subHour(),
        ]);
        $offer->priceSnapshots()->create([
            'price' => 10,
            'currency' => 'USD',
            'source' => 'browser_discovery',
            'comparison_eligible' => false,
            'captured_at' => now(),
        ]);

        $resourceOffer = DealOfferResource::getEloquentQuery()->findOrFail($offer->getKey());
        $this->assertSame(
            [120.0, 100.0],
            $resourceOffer->priceSnapshots->map(fn ($price): float => (float) $price->price)->all(),
        );

        $chart = new TestableDealOfferPriceHistoryChart;
        $chart->record = $offer;
        $this->assertSame([120.0, 100.0], $chart->data()['datasets'][0]['data']);
    }

    private function search(User $user, ?float $targetPrice = null): DealSearch
    {
        return DealSearch::query()->create([
            'user_id' => $user->getKey(),
            'name' => 'Offer trust',
            'query' => 'Radeon RX 7800 XT '.fake()->unique()->numerify('####'),
            'component_type' => ComponentType::Gpu,
            'target_price' => $targetPrice,
            'enabled' => true,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function offer(DealSearch $search, float $price, array $attributes = []): DealOffer
    {
        $url = 'https://www.newegg.com/p/'.fake()->unique()->bothify('N82E168########');

        return $search->offers()->create([
            'store' => 'Newegg',
            'title' => 'Radeon RX 7800 XT',
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'price' => $price,
            'currency' => 'USD',
            'source' => 'browser_discovery',
            'availability' => DealOffer::AVAILABILITY_IN_STOCK,
            'fetched_at' => now(),
            ...$attributes,
        ]);
    }
}

class TestableDealOfferPriceHistoryChart extends DealOfferPriceHistoryChart
{
    /** @return array<string, mixed> */
    public function data(): array
    {
        return $this->getData();
    }
}
