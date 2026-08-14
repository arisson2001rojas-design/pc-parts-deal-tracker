<?php

namespace Tests\Feature;

use App\Enums\ComponentType;
use App\Enums\IdentityResolutionState;
use App\Enums\Statuses;
use App\Models\DealOffer;
use App\Models\HardwareIdentity;
use App\Models\PcPart;
use App\Models\Product;
use App\Models\RetailerListing;
use App\Models\Store;
use App\Models\Url;
use App\Models\User;
use App\Services\CatalogTrackingService;
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

    public function test_browser_radar_reuses_the_historical_owner_of_an_exact_retailer_listing(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $store = $this->createStore('Amazon US');
        $historical = $this->createHistoricalTrackedListing(
            $user,
            $store,
            'https://www.amazon.com/dp/B00EHBES1U',
            149.99,
            retailer: 'amazon',
        );
        $ownership = $historical['product']->only(['id', 'user_id', 'pc_part_id']);
        $payload = $this->payload();
        $payload['page_url'] = 'https://www.amazon.com/dp/B00EHBES1U';

        $response = $this->withHeader('X-PriceBuddy-Companion', '1')
            ->postJson(route('api.browser-discoveries'), $payload)
            ->assertCreated();

        $this->assertSame($historical['part']->getKey(), $response->json('data.pc_part_id'));
        $this->assertSame($historical['product']->getKey(), $response->json('data.product_id'));
        $this->assertDatabaseCount('pc_parts', 1);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('urls', 1);
        $this->assertDatabaseCount('prices', 2);
        $this->assertDatabaseHas('prices', [
            'url_id' => $historical['url']->getKey(),
            'price' => 129.99,
        ]);
        $listing = RetailerListing::query()->sole();
        $this->assertSame($listing->getKey(), $historical['url']->fresh()->retailer_listing_id);
        $this->assertSame($ownership, $historical['product']->fresh()->only(array_keys($ownership)));
        Queue::assertNothingPushed();
    }

    public function test_browser_radar_reuses_a_tracking_url_variant_and_preserves_tracking_preferences(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $store = $this->createStore('Newegg US');
        $futureCheck = now()->addHours(5)->startOfSecond();
        $historical = $this->createHistoricalTrackedListing(
            $user,
            $store,
            'https://www.newegg.com/p/9SIC3U3KN44182?utm_source=legacy',
            149.99,
            productAttributes: [
                'favourite' => true,
                'paused' => true,
                'paused_by_user' => true,
                'refresh_interval' => 43210,
                'next_check_at' => $futureCheck,
            ],
        );
        $scheduledAt = $historical['product']->fresh()->next_check_at;
        $payload = $this->payload();
        $payload['page_url'] .= '?utm_campaign=radar';

        $this->withHeader('X-PriceBuddy-Companion', '1')
            ->postJson(route('api.browser-discoveries'), $payload)
            ->assertCreated()
            ->assertJsonPath('data.pc_part_id', $historical['part']->getKey())
            ->assertJsonPath('data.product_id', $historical['product']->getKey());

        $product = $historical['product']->fresh();
        $this->assertTrue($product->favourite);
        $this->assertTrue($product->paused);
        $this->assertTrue($product->paused_by_user);
        $this->assertSame(43210, $product->refresh_interval);
        $this->assertTrue($scheduledAt->equalTo($product->next_check_at));
        $this->assertDatabaseCount('pc_parts', 1);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('urls', 1);
        $this->assertDatabaseCount('prices', 2);
        $this->assertDatabaseHas('urls', [
            'id' => $historical['url']->getKey(),
            'url' => 'https://www.newegg.com/p/9SIC3U3KN44182?utm_source=legacy',
        ]);
        Queue::assertNothingPushed();
    }

    public function test_conflicting_exact_listing_observation_preserves_trusted_historical_catalog_fields(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $store = $this->createStore('Newegg US');
        $identity = HardwareIdentity::factory()->create([
            'component_type' => ComponentType::Cpu,
            'manufacturer' => 'AMD',
            'manufacturer_normalized' => 'AMD',
            'model' => 'Ryzen 5 5600',
            'model_normalized' => 'RYZEN-5-5600',
            'mpn' => '100-000000927',
            'mpn_normalized' => '100-000000927',
        ]);
        $historical = $this->createHistoricalTrackedListing(
            $user,
            $store,
            'https://www.newegg.com/p/9SIC3U3KN44182',
            149.99,
            partAttributes: ['hardware_identity_id' => $identity->getKey()],
        );
        $trusted = $historical['part']->only([
            'hardware_identity_id',
            'component_type',
            'name',
            'manufacturer',
            'part_numbers',
        ]);
        $ownership = $historical['product']->only(['id', 'user_id', 'pc_part_id']);
        $originalPrice = $historical['url']->prices()->firstOrFail();
        $payload = $this->payload();
        $payload['title'] = 'NVIDIA GeForce RTX 5090 32GB Graphics Card';
        $payload['manufacturer'] = 'NVIDIA';
        $payload['model'] = 'GeForce RTX 5090';
        $payload['mpn'] = 'RTX5090-CONFLICT';
        $payload['part_number'] = 'RTX5090-CONFLICT';
        $payload['candidates'][0]['price'] = 119.99;
        $payload['candidates'][1]['price'] = 119.99;

        $response = $this->withHeader('X-PriceBuddy-Companion', '1')
            ->postJson(route('api.browser-discoveries'), $payload)
            ->assertCreated();

        $part = $historical['part']->fresh();
        $product = $historical['product']->fresh();
        $listing = RetailerListing::query()->sole();
        $this->assertSame($historical['part']->getKey(), $response->json('data.pc_part_id'));
        $this->assertSame($historical['product']->getKey(), $response->json('data.product_id'));
        $this->assertSame(IdentityResolutionState::Conflicting, $listing->resolution_state);
        $this->assertSame($trusted, $part->only(array_keys($trusted)));
        $this->assertSame($ownership, $product->only(array_keys($ownership)));
        $this->assertDatabaseHas('prices', [
            'id' => $originalPrice->getKey(),
            'url_id' => $historical['url']->getKey(),
            'price' => 149.99,
        ]);
        $this->assertDatabaseHas('prices', [
            'url_id' => $historical['url']->getKey(),
            'price' => 119.99,
        ]);
        $this->assertDatabaseCount('pc_parts', 1);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('urls', 1);
        $this->assertDatabaseCount('prices', 2);
        Queue::assertNothingPushed();
    }

    public function test_browser_radar_fails_closed_when_multiple_products_claim_the_exact_listing(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $store = $this->createStore('Newegg US');
        $this->createHistoricalTrackedListing(
            $user,
            $store,
            'https://www.newegg.com/p/9SIC3U3KN44182',
            149.99,
        );
        $this->createHistoricalTrackedListing(
            $user,
            $store,
            'https://www.newegg.com/p/9SIC3U3KN44182?utm_source=duplicate',
            139.99,
            partAttributes: [
                'name' => 'Conflicting historical catalog part',
                'part_numbers' => ['OTHER-MPN'],
            ],
        );

        $this->withHeader('X-PriceBuddy-Companion', '1')
            ->postJson(route('api.browser-discoveries'), $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('page_url')
            ->assertJsonPath(
                'errors.page_url.0',
                'This retailer listing is already tracked by multiple products. Resolve those duplicate products before Browser Radar can record it.',
            );

        $this->assertDatabaseCount('pc_parts', 2);
        $this->assertDatabaseCount('products', 2);
        $this->assertDatabaseCount('urls', 2);
        $this->assertDatabaseCount('prices', 2);
        $this->assertDatabaseCount('retailer_listings', 0);
        $this->assertDatabaseCount('deal_offers', 0);
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
        $this->assertDatabaseCount('hardware_identities', 1);
        $this->assertDatabaseCount('retailer_listings', 2);
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

    public function test_radar_reuses_a_catalog_part_for_the_same_exact_cross_retailer_hardware(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $this->createStore('Amazon US');
        $this->createStore('Newegg US');
        $part = PcPart::factory()->create([
            'component_type' => ComponentType::Ssd,
            'name' => 'Samsung 870 QVO 8TB SSD',
            'manufacturer' => 'Samsung',
            'series' => '870',
            'variant' => '8000GB',
            'part_numbers' => ['MZ-77Q8T0B', 'AM', 'MZ-77Q8T0BW', 'MZ-77Q8T0B/AM'],
            'retailer_urls' => [
                'amazon' => 'https://www.amazon.com/dp/B089C3TZL9',
                'newegg' => 'https://www.newegg.com/p/N82E16820147784',
            ],
            'specifications' => [
                'capacity' => '8TB',
                'storage_type' => 'SSD',
                'interface' => 'SATA 6.0 Gb/s',
                'form_factor' => '2.5"',
            ],
        ]);
        $tracked = app(CatalogTrackingService::class)->track($part, $user->getKey(), ['amazon'], queueRefresh: false);
        $tracked->setUserPaused(true)->saveQuietly();
        Queue::fake();

        $payload = $this->payload();
        $payload['page_url'] = 'https://www.newegg.com/p/N82E16820147784';
        $payload['title'] = 'Samsung 870 QVO 8TB 2.5 inch SATA III SSD';
        $payload['manufacturer'] = 'Samsung';
        $payload['model'] = '870 QVO';
        $payload['mpn'] = 'MZ-77Q8T0B/AM';
        $payload['part_number'] = 'MZ-77Q8T0B/AM';
        $payload['candidates'] = [[
            'price' => 1399.99,
            'currency' => 'USD',
            'source' => 'json_ld',
            'confidence' => 0.98,
        ]];

        $response = $this->withHeader('X-PriceBuddy-Companion', '1')
            ->postJson(route('api.browser-discoveries'), $payload)
            ->assertCreated();

        $this->assertSame($part->getKey(), $response->json('data.pc_part_id'));
        $this->assertSame($tracked->getKey(), $response->json('data.product_id'));
        $this->assertDatabaseCount('hardware_identities', 1);
        $this->assertDatabaseCount('retailer_listings', 2);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('urls', 2);
        $tracked->refresh();
        $this->assertTrue($tracked->paused);
        $this->assertTrue($tracked->paused_by_user);
        Queue::assertNothingPushed();
    }

    public function test_same_title_without_typed_identity_evidence_stays_separate(): void
    {
        Queue::fake();
        User::factory()->create();
        $this->createStore('Newegg US');
        $this->createStore('Amazon US');
        $neweggPayload = $this->payload();
        unset($neweggPayload['mpn'], $neweggPayload['model'], $neweggPayload['part_number']);

        $newegg = $this->withHeader('X-PriceBuddy-Companion', '1')
            ->postJson(route('api.browser-discoveries'), $neweggPayload)
            ->assertCreated();

        $amazonPayload = $neweggPayload;
        $amazonPayload['page_url'] = 'https://www.amazon.com/dp/B0ABC12345';
        $amazon = $this->withHeader('X-PriceBuddy-Companion', '1')
            ->postJson(route('api.browser-discoveries'), $amazonPayload)
            ->assertCreated();

        $this->assertNotSame($newegg->json('data.pc_part_id'), $amazon->json('data.pc_part_id'));
        $this->assertNotSame($newegg->json('data.product_id'), $amazon->json('data.product_id'));
        $this->assertDatabaseCount('hardware_identities', 0);
        $this->assertDatabaseCount('retailer_listings', 2);
        $this->assertDatabaseCount('pc_parts', 2);
        $this->assertDatabaseCount('products', 2);
    }

    public function test_conflicting_radar_observation_never_downgrades_a_verified_catalog_part(): void
    {
        Queue::fake();
        User::factory()->create();
        $this->createStore('Newegg US');

        $first = $this->withHeader('X-PriceBuddy-Companion', '1')
            ->postJson(route('api.browser-discoveries'), $this->payload())
            ->assertCreated();

        $part = PcPart::query()->findOrFail($first->json('data.pc_part_id'));
        $product = Product::query()->findOrFail($first->json('data.product_id'));
        $identityId = $part->hardware_identity_id;
        $trusted = [
            'component_type' => $part->component_type,
            'name' => $part->name,
            'manufacturer' => $part->manufacturer,
            'part_numbers' => $part->part_numbers,
        ];
        $productOwnership = $product->only(['id', 'user_id', 'pc_part_id']);
        $urlsBefore = $product->urls()->orderBy('id')->get(['id', 'product_id', 'url'])->toArray();
        /** @var Url $productUrl */
        $productUrl = $product->urls()->firstOrFail();
        $pricesBefore = $productUrl->prices()
            ->orderBy('id')
            ->get(['id', 'url_id', 'price', 'created_at'])
            ->toArray();

        $this->assertNotNull($identityId);
        $conflicting = $this->payload();
        $conflicting['title'] = 'NVIDIA GeForce RTX 5090 32GB Graphics Card';
        $conflicting['manufacturer'] = 'NVIDIA';
        $conflicting['model'] = 'GeForce RTX 5090';
        $conflicting['mpn'] = 'RTX5090-CONFLICT';
        $conflicting['part_number'] = 'RTX5090-CONFLICT';

        $second = $this->withHeader('X-PriceBuddy-Companion', '1')
            ->postJson(route('api.browser-discoveries'), $conflicting)
            ->assertCreated();

        $part->refresh();
        $product->refresh();
        $listing = RetailerListing::query()->sole();
        $this->assertSame($part->getKey(), $second->json('data.pc_part_id'));
        $this->assertSame($product->getKey(), $second->json('data.product_id'));
        $this->assertSame(IdentityResolutionState::Conflicting, $listing->resolution_state);
        $this->assertSame($identityId, $part->hardware_identity_id);
        $this->assertSame($trusted['component_type'], $part->component_type);
        $this->assertSame($trusted['name'], $part->name);
        $this->assertSame($trusted['manufacturer'], $part->manufacturer);
        $this->assertSame($trusted['part_numbers'], $part->part_numbers);
        $this->assertSame($productOwnership, $product->only(['id', 'user_id', 'pc_part_id']));
        $this->assertSame($urlsBefore, $product->urls()->orderBy('id')->get(['id', 'product_id', 'url'])->toArray());
        $this->assertSame(
            $pricesBefore,
            $productUrl->prices()
                ->orderBy('id')
                ->get(['id', 'url_id', 'price', 'created_at'])
                ->toArray(),
        );
        $this->assertDatabaseCount('hardware_identities', 1);
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
        unset($payload['manufacturer'], $payload['mpn'], $payload['model'], $payload['part_number']);
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
        /** @var ComponentType $dealSearchComponentType */
        $dealSearchComponentType = $offer->dealSearch->component_type;
        $this->assertSame('browser-radar:motherboard', $offer->dealSearch->query);
        $this->assertSame('motherboard', $dealSearchComponentType->value);
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
            'mpn' => '100-000000927',
            'model' => 'Ryzen 5 5600',
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

    /**
     * @param  array<string, mixed>  $partAttributes
     * @param  array<string, mixed>  $productAttributes
     * @return array{part: PcPart, product: Product, url: Url}
     */
    private function createHistoricalTrackedListing(
        User $user,
        Store $store,
        string $url,
        float $price,
        string $retailer = 'newegg',
        array $partAttributes = [],
        array $productAttributes = [],
    ): array {
        $part = PcPart::factory()->create([
            'component_type' => ComponentType::Cpu,
            'name' => 'AMD Ryzen 5 5600 Desktop Processor',
            'manufacturer' => 'AMD',
            'part_numbers' => ['100-000000927'],
            'retailer_urls' => [$retailer => $url],
            'source_url' => $url,
            ...$partAttributes,
        ]);
        $product = Product::factory()->create([
            'user_id' => $user->getKey(),
            'pc_part_id' => $part->getKey(),
            'title' => $part->name,
            'component_type' => $part->component_type->value,
            ...$productAttributes,
        ]);
        /** @var Url $trackedUrl */
        $trackedUrl = $product->urls()->create([
            'url' => $url,
            'store_id' => $store->getKey(),
            'price_factor' => 1,
        ]);
        $trackedUrl->prices()->create([
            'price' => $price,
            'unit_price' => $price,
            'price_factor' => 1,
            'store_id' => $store->getKey(),
        ]);
        $product->updatePriceCache();

        return ['part' => $part, 'product' => $product, 'url' => $trackedUrl];
    }
}
