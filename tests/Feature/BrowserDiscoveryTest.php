<?php

namespace Tests\Feature;

use App\Enums\Statuses;
use App\Models\DealOffer;
use App\Models\PcPart;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BrowserDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_browser_radar_adds_an_unknown_component_and_verified_price(): void
    {
        Queue::fake();
        User::factory()->create();
        $this->createStore('Newegg US');
        $unrelatedPart = PcPart::factory()->create();

        $this->withHeader('X-PriceBuddy-Companion', '1')
            ->postJson(route('api.browser-discoveries'), $this->payload())
            ->assertCreated()
            ->assertJsonStructure(['data' => ['product_id']])
            ->assertJsonPath('data.component_type', 'cpu')
            ->assertJsonPath('data.price', 129.99)
            ->assertJsonPath('data.stored', true);

        $this->assertDatabaseHas('deal_searches', [
            'query' => 'browser-radar:cpu',
            'component_type' => 'cpu',
            'enabled' => false,
        ]);
        $this->assertDatabaseHas('deal_offers', [
            'store' => 'Newegg',
            'url' => 'https://www.newegg.com/p/9SIC3U3KN44182',
            'price' => 129.99,
            'source' => 'browser_discovery',
            'availability' => DealOffer::AVAILABILITY_IN_STOCK,
            'seller' => 'SenyTech Global',
        ]);
        $this->assertDatabaseHas('pc_parts', [
            'component_type' => 'cpu',
            'name' => 'AMD Ryzen 5 5600 Desktop Processor',
            'manufacturer' => 'AMD',
            'source_url' => 'https://www.newegg.com/p/9SIC3U3KN44182',
        ]);
        $this->assertDatabaseCount('deal_offer_prices', 1);

        $product = Product::query()->where('pc_part_id', '!=', $unrelatedPart->getKey())->firstOrFail();
        $this->assertSame(Statuses::Published, $product->status);
        $this->assertFalse($product->favourite);
        $this->assertFalse($product->paused);
        $this->assertFalse($product->paused_by_user);
        $this->assertSame(28800, $product->refresh_interval);
        $this->assertNotNull($product->next_check_at);
        $this->assertTrue($product->next_check_at->gte(now()->addSeconds(28799)));
        $this->assertTrue($product->next_check_at->lte(now()->addSeconds(29101)));
        $this->assertSame('https://c1.neweggimages.com/ProductImage.jpg', $product->image);
        $this->assertDatabaseHas('urls', [
            'product_id' => $product->getKey(),
            'url' => 'https://www.newegg.com/p/9SIC3U3KN44182',
        ]);
        $this->assertDatabaseHas('prices', ['price' => 129.99]);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseMissing('products', ['pc_part_id' => $unrelatedPart->getKey()]);
        Queue::assertNothingPushed();
    }

    public function test_browser_radar_deduplicates_products_and_appends_changed_prices(): void
    {
        Queue::fake();
        User::factory()->create();
        $this->createStore('Newegg US');

        $firstResponse = $this->withHeader('X-PriceBuddy-Companion', '1')
            ->postJson(route('api.browser-discoveries'), $this->payload())
            ->assertCreated();
        $product = Product::query()->firstOrFail();
        $productId = $product->getKey();
        $this->assertSame($productId, $firstResponse->json('data.product_id'));
        $offerId = $firstResponse->json('data.offer_id');

        $product->forceFill([
            'favourite' => false,
            'paused' => true,
            'paused_by_user' => false,
            'refresh_interval' => null,
            'next_check_at' => null,
        ])->saveQuietly();

        $this->withHeader('X-PriceBuddy-Companion', '1')
            ->postJson(route('api.browser-discoveries'), $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.product_id', $productId)
            ->assertJsonPath('data.offer_id', $offerId);

        $product->refresh();
        $this->assertFalse($product->favourite);
        $this->assertFalse($product->paused);
        $this->assertFalse($product->paused_by_user);
        $this->assertSame(28800, $product->refresh_interval);
        $this->assertNotNull($product->next_check_at);
        $this->assertDatabaseCount('deal_offer_prices', 1);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('urls', 1);
        $this->assertDatabaseCount('prices', 1);

        $payload = $this->payload();
        $payload['page_url'] .= '?utm_source=tracking';
        $payload['candidates'] = [[
            'price' => 119.99,
            'currency' => 'USD',
            'source' => 'site_specific',
            'confidence' => 0.96,
        ]];
        $this->withHeader('X-PriceBuddy-Companion', '1')
            ->postJson(route('api.browser-discoveries'), $payload)
            ->assertCreated()
            ->assertJsonPath('data.price', 119.99);

        $this->assertDatabaseCount('deal_searches', 1);
        $this->assertDatabaseCount('deal_offers', 1);
        $this->assertDatabaseCount('pc_parts', 1);
        $this->assertDatabaseCount('deal_offer_prices', 2);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('urls', 1);
        $this->assertDatabaseCount('prices', 2);
        $this->assertDatabaseHas('prices', ['price' => 119.99]);
        Queue::assertNothingPushed();
    }

    public function test_same_component_at_different_retailers_keeps_distinct_offers_and_urls(): void
    {
        Queue::fake();
        User::factory()->create();
        $this->createStore('Newegg US');
        $this->createStore('Amazon US');

        $newegg = $this->withHeader('X-PriceBuddy-Companion', '1')
            ->postJson(route('api.browser-discoveries'), $this->payload())
            ->assertCreated();

        $amazonPayload = $this->payload();
        $amazonPayload['page_url'] = 'https://www.amazon.com/dp/B0ABC12345?ref_=tracking';
        $amazonPayload['image_url'] = 'https://images-na.ssl-images-amazon.com/images/I/cpu.jpg';
        $amazonPayload['candidates'] = [[
            'price' => 124.99,
            'currency' => 'USD',
            'source' => 'site_specific',
            'confidence' => 0.97,
        ]];
        $amazon = $this->withHeader('X-PriceBuddy-Companion', '1')
            ->postJson(route('api.browser-discoveries'), $amazonPayload)
            ->assertCreated();

        $this->assertSame($newegg->json('data.pc_part_id'), $amazon->json('data.pc_part_id'));
        $this->assertSame($newegg->json('data.product_id'), $amazon->json('data.product_id'));
        $this->assertNotSame($newegg->json('data.offer_id'), $amazon->json('data.offer_id'));
        $this->assertDatabaseCount('deal_searches', 1);
        $this->assertDatabaseCount('deal_offers', 2);
        $this->assertDatabaseCount('deal_offer_prices', 2);
        $this->assertDatabaseCount('pc_parts', 1);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('urls', 2);
        $this->assertDatabaseCount('prices', 2);
        $this->assertDatabaseHas('deal_offers', [
            'store' => 'Newegg',
            'url' => 'https://www.newegg.com/p/9SIC3U3KN44182',
        ]);
        $this->assertDatabaseHas('deal_offers', [
            'store' => 'Amazon',
            'url' => 'https://www.amazon.com/dp/B0ABC12345',
        ]);
        Queue::assertNothingPushed();
    }

    public function test_browser_radar_respects_an_explicit_user_pause_and_still_records_price_history(): void
    {
        Queue::fake();
        User::factory()->create();
        $this->createStore('Newegg US');

        $this->withHeader('X-PriceBuddy-Companion', '1')
            ->postJson(route('api.browser-discoveries'), $this->payload())
            ->assertCreated();

        $product = Product::query()->firstOrFail();
        $productId = $product->getKey();
        $scheduledAt = $product->next_check_at;
        $product->setUserPaused(true)->saveQuietly();

        $payload = $this->payload();
        $payload['candidates'] = [[
            'price' => 124.99,
            'currency' => 'USD',
            'source' => 'site_specific',
            'confidence' => 0.96,
        ]];

        $this->withHeader('X-PriceBuddy-Companion', '1')
            ->postJson(route('api.browser-discoveries'), $payload)
            ->assertCreated()
            ->assertJsonPath('data.product_id', $productId)
            ->assertJsonPath('data.price', 124.99);

        $product->refresh();
        $this->assertTrue($product->paused);
        $this->assertTrue($product->paused_by_user);
        $this->assertFalse($product->favourite);
        $this->assertTrue($scheduledAt->equalTo($product->next_check_at));
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('urls', 1);
        $this->assertDatabaseCount('prices', 2);
        $this->assertDatabaseHas('prices', ['price' => 124.99]);
        Queue::assertNothingPushed();
    }

    public function test_browser_radar_preserves_custom_interval_favourite_and_future_schedule(): void
    {
        Queue::fake();
        User::factory()->create();
        $this->createStore('Newegg US');

        $this->withHeader('X-PriceBuddy-Companion', '1')
            ->postJson(route('api.browser-discoveries'), $this->payload())
            ->assertCreated();

        $product = Product::query()->firstOrFail();
        $futureCheck = now()->addHours(2)->startOfSecond();
        $product->forceFill([
            'favourite' => true,
            'refresh_interval' => 3600,
            'next_check_at' => $futureCheck,
        ])->saveQuietly();

        $this->withHeader('X-PriceBuddy-Companion', '1')
            ->postJson(route('api.browser-discoveries'), $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.product_id', $product->getKey());

        $product->refresh();
        $this->assertTrue($product->favourite);
        $this->assertSame(3600, $product->refresh_interval);
        $this->assertTrue($futureCheck->equalTo($product->next_check_at));
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('urls', 1);
        $this->assertDatabaseCount('prices', 1);
        Queue::assertNothingPushed();
    }

    public function test_browser_radar_reschedules_a_due_product_only_once(): void
    {
        Queue::fake();
        User::factory()->create();
        $this->createStore('Newegg US');

        $this->withHeader('X-PriceBuddy-Companion', '1')
            ->postJson(route('api.browser-discoveries'), $this->payload())
            ->assertCreated();

        $product = Product::query()->firstOrFail();
        $product->forceFill([
            'refresh_interval' => 3600,
            'next_check_at' => now()->subMinute(),
        ])->saveQuietly();

        $this->withHeader('X-PriceBuddy-Companion', '1')
            ->postJson(route('api.browser-discoveries'), $this->payload())
            ->assertCreated();
        $firstScheduledAt = $product->fresh()->next_check_at;

        $this->withHeader('X-PriceBuddy-Companion', '1')
            ->postJson(route('api.browser-discoveries'), $this->payload())
            ->assertCreated();

        $product->refresh();
        $this->assertTrue($firstScheduledAt->isFuture());
        $this->assertTrue($firstScheduledAt->equalTo($product->next_check_at));
        $this->assertSame(3600, $product->refresh_interval);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('urls', 1);
        Queue::assertNothingPushed();
    }

    public function test_browser_radar_reclassifies_a_legacy_motherboard_capture(): void
    {
        User::factory()->create();
        $this->createStore('Amazon US');
        $payload = $this->payload();
        $payload['page_url'] = 'https://www.amazon.com/dp/B0D1234567';
        $payload['title'] = 'AMD Ryzen 5 5600 Desktop Processor';
        $payload['candidates'] = [[
            'price' => 89.99,
            'currency' => 'USD',
            'source' => 'site_specific',
            'confidence' => 0.96,
        ]];

        $this->withHeader('X-PriceBuddy-Companion', '1')
            ->postJson(route('api.browser-discoveries'), $payload)
            ->assertCreated()
            ->assertJsonPath('data.component_type', 'cpu');

        $payload['title'] = 'MSI B550M PRO-VDH WiFi Motherboard for AMD Ryzen 5000';
        $this->withHeader('X-PriceBuddy-Companion', '1')
            ->postJson(route('api.browser-discoveries'), $payload)
            ->assertCreated()
            ->assertJsonPath('data.component_type', 'motherboard');

        $this->assertDatabaseCount('deal_offers', 1);
        $this->assertDatabaseCount('pc_parts', 1);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseHas('pc_parts', [
            'component_type' => 'motherboard',
            'name' => 'MSI B550M PRO-VDH WiFi Motherboard for AMD Ryzen 5000',
        ]);

        $offer = DealOffer::query()->firstOrFail();
        $this->assertSame('browser-radar:motherboard', $offer->dealSearch->query);
        $this->assertSame('motherboard', $offer->dealSearch->component_type->value);
        $this->assertSame('motherboard', Product::query()->firstOrFail()->component_type->value);
    }

    public function test_browser_radar_ignores_non_component_products(): void
    {
        User::factory()->create();
        $payload = $this->payload();
        $payload['title'] = 'Gaming PC Desktop Computer with Ryzen and Radeon Graphics';

        $this->withHeader('X-PriceBuddy-Companion', '1')
            ->postJson(route('api.browser-discoveries'), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('title');

        $this->assertDatabaseCount('deal_offers', 0);
        $this->assertDatabaseCount('pc_parts', 0);
    }

    public function test_browser_radar_rejects_search_pages_and_requests_without_extension_header(): void
    {
        User::factory()->create();
        $payload = $this->payload();
        $payload['page_url'] = 'https://www.newegg.com/p/pl?d=ryzen+5600';

        $this->withHeader('X-PriceBuddy-Companion', '1')
            ->postJson(route('api.browser-discoveries'), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('page_url');

        $this->withoutHeader('X-PriceBuddy-Companion')
            ->postJson(route('api.browser-discoveries'), $this->payload())
            ->assertForbidden();
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'page_url' => 'https://www.newegg.com/p/9SIC3U3KN44182',
            'title' => 'AMD Ryzen 5 5600 Desktop Processor',
            'image_url' => 'https://c1.neweggimages.com/ProductImage.jpg',
            'availability' => 'in_stock',
            'seller' => 'SenyTech Global',
            'manufacturer' => 'AMD',
            'part_number' => '100-000000927',
            'candidates' => [
                [
                    'price' => 129.99,
                    'currency' => 'USD',
                    'source' => 'site_specific',
                    'confidence' => 0.96,
                ],
                [
                    'price' => 129.99,
                    'currency' => 'USD',
                    'source' => 'json_ld',
                    'confidence' => 0.90,
                ],
            ],
        ];
    }

    private function createStore(string $name): Store
    {
        return Store::factory()->create(['name' => $name]);
    }
}
