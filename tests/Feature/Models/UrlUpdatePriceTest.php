<?php

namespace Tests\Feature\Models;

use App\Dto\AiExtractionResultDto;
use App\Enums\ComponentType;
use App\Enums\StockStatus;
use App\Models\Price;
use App\Models\Product;
use App\Models\Store;
use App\Models\Url;
use App\Services\AiConfigHealer;
use App\Services\AiExtractionService;
use App\Services\Helpers\SettingsHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Once;
use Tests\TestCase;

class UrlUpdatePriceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SettingsHelper::$settings = null;
        Cache::flush();
        Once::flush();

        // The healer is tested separately; make it a no-op here so these tests
        // focus purely on the AiScrapeEnhancer fallback path.
        $this->mock(AiConfigHealer::class, fn ($m) => $m->shouldReceive('heal')
            ->andReturnUsing(fn ($u, $r) => $r));
    }

    private function configureProviders(): void
    {
        SettingsHelper::setSetting('integrated_services', ['ai' => [
            'enabled' => true,
            'default_provider_id' => 'p1',
            'providers' => [[
                'id' => 'p1', 'name' => 'Local', 'type' => 'ollama',
                'base_url' => 'http://ai.example:11434', 'model' => 'm',
            ]],
        ]]);
        SettingsHelper::$settings = null;
        Cache::flush();
        Once::flush();
    }

    public function test_ai_backfills_price_when_scrape_finds_none(): void
    {
        $this->configureProviders();
        $this->mock(AiExtractionService::class, fn ($m) => $m->shouldReceive('extract')
            ->once()->andReturn(new AiExtractionResultDto(price: 9.99, confidence: 0.9)));
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('withContext')->andReturnSelf();
        Log::shouldReceive('info');
        Log::shouldReceive('debug');

        $store = Store::factory()->create([
            'settings' => ['scraper_service' => 'http', 'ai_extraction_enabled' => true],
        ]);
        $url = Url::factory()->for(Product::factory())->for($store)->create();

        $price = $url->updatePrice(null, ['price' => null, 'body' => '<html>9.99</html>', 'availability' => null]);

        $this->assertInstanceOf(Price::class, $price);
        $this->assertSame(9.99, (float) $price->price);
    }

    public function test_no_price_recorded_when_ai_disabled_and_scrape_finds_none(): void
    {
        $this->mock(AiExtractionService::class, fn ($m) => $m->shouldReceive('extract')->never());

        $url = Url::factory()->for(Product::factory())->create();

        $price = $url->updatePrice(null, ['price' => null, 'body' => '<html>9.99</html>', 'availability' => null]);

        $this->assertNull($price);
        $this->assertDatabaseCount('prices', 0);
    }

    public function test_implausible_foreign_currency_pc_price_is_not_recorded(): void
    {
        $store = Store::factory()->create([
            'settings' => [
                'locale_settings' => ['locale' => 'en', 'currency' => 'USD'],
            ],
        ]);
        $product = Product::factory()->create(['component_type' => ComponentType::Cpu]);
        $url = Url::factory()->for($product)->for($store)->create();

        $price = $url->updatePrice('₡149,361.46', [
            'price' => '₡149,361.46',
            'availability' => null,
        ]);

        $this->assertNull($price);
        $this->assertDatabaseCount('prices', 0);
    }

    public function test_raw_price_uses_explicit_store_locale_fallback(): void
    {
        $store = Store::factory()->create([
            'settings' => [
                'locale_settings' => [
                    'locale' => 'es',
                    'currency' => 'USD',
                    'price_locale_fallback' => 'en_US',
                ],
            ],
        ]);
        $product = Product::factory()->create(['component_type' => ComponentType::Ssd]);
        $url = Url::factory()->for($product)->for($store)->create();

        $price = $url->updatePrice('$1,479.99', [
            'price' => '$1,479.99',
            'availability' => null,
        ]);

        $this->assertSame(1479.99, (float) $price?->price);
    }

    public function test_ambiguous_raw_price_is_not_recorded(): void
    {
        $store = Store::factory()->create([
            'settings' => [
                'locale_settings' => [
                    'locale' => 'es',
                    'currency' => 'USD',
                    'price_locale_fallback' => 'en_US',
                ],
            ],
        ]);
        $url = Url::factory()->for(Product::factory())->for($store)->create();

        $price = $url->updatePrice('1,479', [
            'price' => '1,479',
            'availability' => null,
        ]);

        $this->assertNull($price);
        $this->assertDatabaseCount('prices', 0);
    }

    public function test_normalized_foreign_currency_is_rejected_before_the_pc_specific_guard(): void
    {
        $store = Store::factory()->create([
            'settings' => [
                'locale_settings' => ['locale' => 'en_US', 'currency' => 'USD'],
            ],
        ]);
        $product = Product::factory()->create(['component_type' => null]);
        $url = Url::factory()->for($product)->for($store)->create();
        $existing = $url->prices()->create([
            'price' => 1479.99,
            'unit_price' => 1479.99,
            'price_factor' => 1,
            'store_id' => $store->getKey(),
        ]);

        $price = $url->updatePrice(672300.26, [
            'price' => 672300.26,
            'normalized_price' => 672300.26,
            'price_normalized' => true,
            'currency' => 'CRC',
        ]);

        $this->assertNull($price);
        $this->assertDatabaseCount('prices', 1);
        $this->assertSame(1479.99, (float) $existing->fresh()->price);
    }

    public function test_raw_unknown_preserves_prior_availability_and_fails_closed_without_price(): void
    {
        foreach ([null, StockStatus::OutOfStock] as $priorAvailability) {
            $url = Url::factory()->for(Product::factory())->create([
                'availability' => $priorAvailability,
            ]);

            $result = $url->updatePrice(null, [
                'price' => null,
                'availability' => ' UNKNOWN ',
            ]);

            $this->assertNull($result);
            $this->assertSame($priorAvailability, $url->fresh()->getAvailabilityStatus());
            $this->assertCount(0, $url->prices);
        }
    }

    public function test_guard_rejection_preserves_history_and_legacy_return_contract(): void
    {
        $store = Store::factory()->create([
            'settings' => [
                'locale_settings' => ['locale' => 'en_US', 'currency' => 'USD'],
            ],
        ]);
        $product = Product::factory()->create(['component_type' => ComponentType::Ssd]);
        $url = Url::factory()->for($product)->for($store)->create();
        $existing = $url->prices()->create([
            'price' => 149.99,
            'unit_price' => 149.99,
            'price_factor' => 1,
            'store_id' => $store->getKey(),
        ]);

        $result = $url->updatePrice(672300.26, [
            'price' => 672300.26,
            'normalized_price' => 672300.26,
            'price_normalized' => true,
            'currency' => 'USD',
        ]);

        $this->assertInstanceOf(Price::class, $result);
        $this->assertSame($existing->getKey(), $result->getKey());
        $this->assertDatabaseCount('prices', 1);
        $this->assertSame(149.99, (float) $existing->fresh()->price);
    }
}
