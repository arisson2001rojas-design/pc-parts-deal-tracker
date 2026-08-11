<?php

namespace Tests\Feature;

use App\Dto\PriceFetchResult;
use App\Enums\PriceFetchStatus;
use App\Enums\StockStatus;
use App\Models\Store;
use App\Models\Url;
use App\Services\PriceFetchOrchestrator;
use App\Services\RetailPriceExtractorClient;
use App\Services\ScrapeUrl;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class PriceFetchOrchestratorTest extends TestCase
{
    use RefreshDatabase;

    private const string PRODUCT_URL = 'https://www.newegg.com/p/N82E16819113941';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('deal_hunter.price_extractor_url', 'http://price-extractor.test');
        config()->set('deal_hunter.retailers', [
            'newegg' => [
                'name' => 'Newegg',
                'domains' => ['newegg.com'],
            ],
        ]);
    }

    public function test_http_success_returns_common_contract_and_skips_browser_fallback(): void
    {
        $store = $this->store('api');
        $this->fakeExtractorSuccess(availability: 'in_stock');
        $fallbackCalls = 0;

        $result = resolve(PriceFetchOrchestrator::class)->fetch(
            self::PRODUCT_URL,
            $store,
            function () use (&$fallbackCalls): array {
                $fallbackCalls++;

                return ['title' => 'Should not run', 'price' => '999.99'];
            },
        );

        $this->assertSame(PriceFetchStatus::Success, $result->status);
        $this->assertSame('site_specific', $result->source);
        $this->assertSame('price_extractor', $result->engine);
        $this->assertSame(self::PRODUCT_URL, $result->finalUrl);
        $this->assertSame('AMD Ryzen 7 7700X3D', $result->title);
        $this->assertSame('https://images.example/cpu.jpg', $result->image);
        $this->assertSame('in_stock', $result->availability);
        $this->assertSame(159.99, $result->candidates[0]['amount']);
        $this->assertSame('USD', $result->candidates[0]['currency']);
        $this->assertSame('site_specific', $result->candidates[0]['evidence']);
        $this->assertInstanceOf(DateTimeImmutable::class, $result->observedAt);
        $this->assertGreaterThanOrEqual(0, $result->latencyMs);
        $this->assertNull($result->error);
        $this->assertSame(0, $fallbackCalls);
    }

    public function test_http_availability_and_currency_are_preserved_by_scrape_url(): void
    {
        $store = $this->store('api');
        $this->fakeExtractorSuccess(availability: 'out_of_stock');

        $scraper = Mockery::mock(ScrapeUrl::class, [self::PRODUCT_URL])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $scraper->shouldReceive('getStore')->andReturn($store);
        $scraper->shouldNotReceive('scrapeUrl');

        $result = $scraper->scrape();

        $this->assertSame(159.99, $result['price']);
        $this->assertSame('USD', $result['currency']);
        $this->assertSame('out_of_stock', $result['availability']);
        $this->assertSame('AMD Ryzen 7 7700X3D', $result['title']);
        $this->assertSame('https://images.example/cpu.jpg', $result['image']);

        $url = Url::factory()->create([
            'url' => self::PRODUCT_URL,
            'store_id' => $store->getKey(),
        ]);
        $stored = $url->updatePrice(null, $result);

        $this->assertSame(159.99, (float) $stored?->price);
        $this->assertSame(StockStatus::OutOfStock, $url->fresh()->getAvailabilityStatus());
    }

    public function test_canonical_in_stock_remains_in_stock_through_url_persistence(): void
    {
        $store = $this->store('api');
        $this->fakeExtractorSuccess(availability: 'in_stock');
        $url = Url::factory()->create([
            'url' => self::PRODUCT_URL,
            'store_id' => $store->getKey(),
        ]);

        $stored = $url->updatePrice();

        $this->assertSame(159.99, (float) $stored?->price);
        $this->assertDatabaseCount('prices', 1);
        $this->assertNull(
            $url->fresh()->getAvailabilityStatus(),
            'In-stock URLs use null as their persisted availability representation.',
        );
    }

    public function test_soft_404_marker_survives_orchestrator_and_url_persistence(): void
    {
        $store = $this->store('api');
        $store->update([
            'scrape_strategy' => [
                'title' => ['type' => 'selector', 'value' => 'title'],
                'availability' => [
                    'type' => 'selector',
                    'value' => '.stock',
                    'match' => [
                        'out_of_stock' => ['type' => 'match', 'value' => 'Sold out'],
                        'default' => 'in_stock',
                    ],
                ],
            ],
        ]);
        Http::fake([
            'price-extractor.test/extract' => Http::response([
                'error' => 'no reliable product price found',
                'data' => [
                    'page_url' => self::PRODUCT_URL,
                    'title' => null,
                    'image_url' => null,
                    'availability' => 'unknown',
                    'candidates' => [],
                ],
            ], 422),
        ]);

        $scraper = Mockery::mock(ScrapeUrl::class, [self::PRODUCT_URL])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $scraper->shouldReceive('getStore')->andReturn($store);
        $scraper->shouldReceive('scrapeUrl')->andReturn([
            'store' => $store,
            'title' => 'Page not found',
            'price' => null,
            'availability' => 'https://schema.org/Discontinued',
            ScrapeUrl::NOT_FOUND_KEY => true,
            'body' => '<html><title>Page not found</title></html>',
            'errors' => [],
        ]);

        $scrapeResult = $scraper->scrape();
        $this->assertTrue($scrapeResult[ScrapeUrl::NOT_FOUND_KEY] ?? false);

        $url = Url::factory()->create([
            'url' => self::PRODUCT_URL,
            'store_id' => $store->getKey(),
        ]);
        $existingPrice = $url->updatePrice('199.99');
        $returnedPrice = $url->updatePrice(null, $scrapeResult);

        $this->assertSame($existingPrice?->getKey(), $returnedPrice?->getKey());
        $this->assertDatabaseCount('prices', 1);
        $this->assertSame(StockStatus::Discontinued, $url->fresh()->getAvailabilityStatus());
    }

    public function test_http_no_price_is_distinguished_and_uses_existing_seleniumbase_fallback(): void
    {
        $store = $this->store('api');
        Http::fake([
            'price-extractor.test/extract' => Http::response([
                'error' => 'no reliable product price found',
                'data' => [
                    'page_url' => self::PRODUCT_URL,
                    'title' => 'AMD Ryzen 7 7700X3D',
                    'image_url' => 'https://images.example/cpu.jpg',
                    'availability' => 'unknown',
                    'candidates' => [],
                ],
            ], 422),
        ]);

        $httpResult = resolve(RetailPriceExtractorClient::class)->fetchUrl(self::PRODUCT_URL);
        $this->assertSame(PriceFetchStatus::NoPrice, $httpResult->status);
        $this->assertSame('AMD Ryzen 7 7700X3D', $httpResult->title);

        $fallbackCalls = 0;
        $result = resolve(PriceFetchOrchestrator::class)->fetch(
            self::PRODUCT_URL,
            $store,
            function () use (&$fallbackCalls, $store): array {
                $fallbackCalls++;

                return [
                    'store' => $store,
                    'title' => 'AMD Ryzen 7 7700X3D',
                    'price' => '149.99',
                    'image' => 'https://images.example/browser-cpu.jpg',
                    'availability' => 'InStock',
                    'body' => '<html></html>',
                    'errors' => [],
                ];
            },
        );

        $this->assertSame(PriceFetchStatus::Success, $result->status);
        $this->assertSame('seleniumbase', $result->engine);
        $this->assertSame('store_strategy', $result->source);
        $this->assertSame('149.99', $result->candidates[0]['amount']);
        $this->assertSame(1, $fallbackCalls);
    }

    public function test_timeout_is_normalized_as_retryable(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $timeout = resolve(RetailPriceExtractorClient::class)->fetchUrl(self::PRODUCT_URL);

        $this->assertSame(PriceFetchStatus::Timeout, $timeout->status);
        $this->assertSame(['kind' => 'timeout', 'retryable' => true], $timeout->error);
    }

    public function test_network_error_is_normalized_as_retryable(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 7: Connection refused'));

        $network = resolve(RetailPriceExtractorClient::class)->fetchUrl(self::PRODUCT_URL);

        $this->assertSame(PriceFetchStatus::NetworkError, $network->status);
        $this->assertSame(['kind' => 'network_error', 'retryable' => true], $network->error);
    }

    public function test_challenge_is_controlled_and_can_use_the_existing_fallback(): void
    {
        $store = $this->store('api');
        Http::fake([
            'price-extractor.test/extract' => Http::response([
                'error' => 'retailer returned a verification page',
                'blocked' => true,
            ], 409),
        ]);

        $challenge = resolve(RetailPriceExtractorClient::class)->fetchUrl(self::PRODUCT_URL);
        $this->assertSame(PriceFetchStatus::Challenge, $challenge->status);
        $this->assertSame(['kind' => 'challenge', 'retryable' => true], $challenge->error);

        $result = resolve(PriceFetchOrchestrator::class)->fetch(
            self::PRODUCT_URL,
            $store,
            fn (): array => [
                'store' => $store,
                'title' => 'AMD Ryzen 7 7700X3D',
                'price' => '139.99',
                'availability' => 'InStock',
                'errors' => [],
            ],
        );

        $this->assertSame(PriceFetchStatus::Success, $result->status);
        $this->assertSame('seleniumbase', $result->engine);
    }

    public function test_invalid_partial_response_never_becomes_a_price_candidate(): void
    {
        Http::fake([
            'price-extractor.test/extract' => Http::response([
                'data' => [
                    'page_url' => self::PRODUCT_URL,
                    'title' => 'AMD Ryzen 7 7700X3D',
                    'image_url' => null,
                    'availability' => 'unknown',
                    'candidates' => [[
                        'price' => 'not-a-price',
                        'currency' => 'USD',
                        'source' => 'site_specific',
                        'confidence' => 0.96,
                    ]],
                ],
            ]),
        ]);

        $result = resolve(RetailPriceExtractorClient::class)->fetchUrl(self::PRODUCT_URL);

        $this->assertSame(PriceFetchStatus::NoPrice, $result->status);
        $this->assertSame([], $result->candidates);
        $this->assertSame(['kind' => 'no_price', 'retryable' => false], $result->error);
    }

    public function test_error_result_cannot_be_persisted_as_a_valid_price(): void
    {
        $store = $this->store('api');
        $url = Url::factory()->create([
            'url' => self::PRODUCT_URL,
            'store_id' => $store->getKey(),
        ]);
        $result = new PriceFetchResult(
            status: PriceFetchStatus::NetworkError,
            source: 'retailer_page',
            engine: 'price_extractor',
            finalUrl: self::PRODUCT_URL,
            observedAt: new DateTimeImmutable,
            latencyMs: 1,
            error: ['kind' => 'network_error', 'retryable' => true],
        );

        $this->assertNull($url->updatePrice(null, $result->toScrapeArray($store)));
        $this->assertDatabaseCount('prices', 0);
    }

    public function test_existing_http_store_fallback_remains_http(): void
    {
        $store = $this->store('http');
        config()->set('deal_hunter.price_extractor_url');

        $result = resolve(PriceFetchOrchestrator::class)->fetch(
            self::PRODUCT_URL,
            $store,
            fn (): array => [
                'store' => $store,
                'title' => 'AMD Ryzen 7 7700X3D',
                'price' => '129.99',
                'availability' => 'InStock',
                'errors' => [],
            ],
        );

        $this->assertSame(PriceFetchStatus::Success, $result->status);
        $this->assertSame('store_http', $result->engine);
        $this->assertSame('129.99', $result->candidates[0]['amount']);
    }

    private function store(string $scraperService): Store
    {
        return Store::factory()->create([
            'domains' => [['domain' => 'www.newegg.com']],
            'settings' => [
                'scraper_service' => $scraperService,
                'scraper_service_settings' => '',
                'locale_settings' => ['locale' => 'en_US', 'currency' => 'USD'],
            ],
        ]);
    }

    private function fakeExtractorSuccess(string $availability): void
    {
        Http::fake([
            'price-extractor.test/extract' => Http::response([
                'data' => [
                    'page_url' => self::PRODUCT_URL,
                    'title' => 'AMD Ryzen 7 7700X3D',
                    'image_url' => 'https://images.example/cpu.jpg',
                    'availability' => $availability,
                    'candidates' => [[
                        'price' => 159.99,
                        'currency' => 'USD',
                        'source' => 'site_specific',
                        'confidence' => 0.96,
                    ]],
                ],
            ]),
        ]);
    }
}
