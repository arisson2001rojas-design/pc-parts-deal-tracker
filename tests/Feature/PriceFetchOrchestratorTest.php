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
use RuntimeException;
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
        $this->assertCount(1, $result->attempts);
        $this->assertSame('price_extractor', $result->attempts[0]['engine']);
        $this->assertSame('success', $result->attempts[0]['status']);
        $this->assertSame(200, $result->attempts[0]['http_status']);
        $this->assertSame($result->latencyMs, $result->attempts[0]['latency_ms']);
        $this->assertSame($result->attempts, $result->toArray()['attempts']);
        $this->assertSame($result->attempts, $result->toScrapeArray($store)['attempts']);
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

        $scraper = Mockery::mock(ScrapeUrl::class, [self::PRODUCT_URL])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $scraper->shouldReceive('getStore')->andReturn($store);
        $scraper->shouldNotReceive('scrapeUrl');

        $stored = $url->updatePrice(null, $scraper->scrape());

        $this->assertSame(159.99, (float) $stored?->price);
        $this->assertDatabaseCount('prices', 1);
        $this->assertNull(
            $url->fresh()->getAvailabilityStatus(),
            'In-stock URLs use null as their persisted availability representation.',
        );
    }

    public function test_normalized_http_price_ignores_display_locale_and_clears_stale_stock(): void
    {
        $store = $this->store('api', 'es');
        $this->fakeExtractorSuccess(availability: 'in_stock', price: 1399.99);
        $url = Url::factory()->create([
            'url' => self::PRODUCT_URL,
            'store_id' => $store->getKey(),
            'availability' => StockStatus::OutOfStock,
        ]);
        $url->prices()->create([
            'price' => 1477.85,
            'unit_price' => 1477.85,
            'price_factor' => 1,
            'store_id' => $store->getKey(),
        ]);

        $scraper = Mockery::mock(ScrapeUrl::class, [self::PRODUCT_URL])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $scraper->shouldReceive('getStore')->andReturn($store);
        $scraper->shouldNotReceive('scrapeUrl');

        $scrape = $scraper->scrape();
        $this->assertArrayHasKey('price', $scrape, json_encode($scrape));
        $this->assertSame(1399.99, $scrape['price']);
        $this->assertTrue($scrape['price_normalized']);
        $this->assertSame('in_stock', $scrape['availability']);

        $stored = $url->updatePrice(null, $scrape);

        $this->assertSame(1399.99, (float) $stored?->price);
        $this->assertDatabaseCount('prices', 2);
        $this->assertNull($url->fresh()->getAvailabilityStatus());
    }

    public function test_raw_fallback_price_records_explicit_locale_recovery(): void
    {
        $store = $this->store('http', 'es');
        $store->update([
            'settings' => [
                ...$store->settings,
                'locale_settings' => [
                    'locale' => 'es',
                    'currency' => 'USD',
                    'price_locale_fallback' => 'en_US',
                ],
            ],
        ]);
        Http::fake([
            'price-extractor/*' => Http::response([
                'status' => 'no_price',
                'page_url' => self::PRODUCT_URL,
                'title' => 'Amazon SSD',
                'candidates' => [],
            ]),
        ]);

        $scraper = Mockery::mock(ScrapeUrl::class, [self::PRODUCT_URL])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $scraper->shouldReceive('getStore')->andReturn($store);
        $scraper->shouldReceive('scrapeUrl')->once()->andReturn([
            'store' => $store,
            'title' => 'Amazon SSD',
            'price' => '$1,479.99',
        ]);

        $scrape = $scraper->scrape();

        $this->assertSame('$1,479.99', $scrape['price']);
        $this->assertSame(1479.99, $scrape['normalized_price']);
        $this->assertTrue($scrape['price_normalized']);
        $this->assertCount(2, $scrape['attempts']);
        $this->assertSame('locale_fallback', $scrape['attempts'][1]['decision']);
        $this->assertSame('en_US', $scrape['attempts'][1]['parse_locale']);

        $store->update([
            'settings' => [
                ...$store->settings,
                'locale_settings' => ['locale' => 'es', 'currency' => 'USD'],
            ],
        ]);
        $store->refresh();
        $url = Url::factory()->for($store)->create(['url' => self::PRODUCT_URL]);
        $stored = $url->updatePrice(null, $scrape);

        $this->assertSame(1479.99, (float) $stored?->price);
    }

    public function test_ambiguous_raw_fallback_price_fails_closed_with_diagnostic(): void
    {
        $store = $this->store('http', 'es');
        $store->update([
            'settings' => [
                ...$store->settings,
                'locale_settings' => [
                    'locale' => 'es',
                    'currency' => 'USD',
                    'price_locale_fallback' => 'en_US',
                ],
            ],
        ]);
        Http::fake([
            'price-extractor/*' => Http::response([
                'status' => 'no_price',
                'page_url' => self::PRODUCT_URL,
                'title' => 'Ambiguous SSD',
                'candidates' => [],
            ]),
        ]);

        $result = resolve(PriceFetchOrchestrator::class)->fetch(
            self::PRODUCT_URL,
            $store,
            fn (): array => ['title' => 'Ambiguous SSD', 'price' => '1,479'],
        );

        $this->assertSame(PriceFetchStatus::InvalidResponse, $result->status);
        $this->assertSame('locale_mismatch', $result->attempts[1]['decision']);
        $this->assertArrayNotHasKey('parse_locale', $result->attempts[1]);
        $this->assertSame('locale_mismatch', $result->error['reason']);

        $url = Url::factory()->for($store)->create(['url' => self::PRODUCT_URL]);
        $this->assertNull($url->updatePrice(null, $result->toScrapeArray($store)));
        $this->assertDatabaseCount('prices', 0);
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
                    'seller' => 'HTTP Seller',
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
        $this->assertSame(149.99, $result->candidates[0]['amount']);
        $this->assertTrue($result->pricesNormalized);
        $this->assertSame('https://images.example/browser-cpu.jpg', $result->image);
        $this->assertSame('InStock', $result->availability);
        $this->assertSame('HTTP Seller', $result->seller);
        $this->assertSame(1, $fallbackCalls);
        $this->assertCount(2, $result->attempts);
        $this->assertSame(['no_price', 'success'], array_column($result->attempts, 'status'));
        $this->assertSame(['price_extractor', 'seleniumbase'], array_column($result->attempts, 'engine'));
        $this->assertSame(
            array_sum(array_column($result->attempts, 'latency_ms')),
            $result->latencyMs,
        );
    }

    public function test_timeout_is_normalized_as_retryable(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $timeout = resolve(RetailPriceExtractorClient::class)->fetchUrl(self::PRODUCT_URL);

        $this->assertSame(PriceFetchStatus::Timeout, $timeout->status);
        $this->assertSame(['kind' => 'timeout', 'retryable' => true], $timeout->error);
        $this->assertSame('timeout', $timeout->attempts[0]['status']);
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
            ], 403),
        ]);

        $challenge = resolve(RetailPriceExtractorClient::class)->fetchUrl(self::PRODUCT_URL);
        $this->assertSame(PriceFetchStatus::Challenge, $challenge->status);
        $this->assertSame([
            'kind' => 'challenge',
            'retryable' => true,
            'http_status' => 403,
            'reason' => 'retailer returned a verification page',
        ], $challenge->error);

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
        $this->assertSame(['challenge', 'success'], array_column($result->attempts, 'status'));
    }

    public function test_http_429_is_rate_limited_instead_of_challenge(): void
    {
        Http::fake([
            'price-extractor.test/extract' => Http::response([
                'error' => 'Too many requests',
            ], 429),
        ]);

        $result = resolve(RetailPriceExtractorClient::class)->fetchUrl(self::PRODUCT_URL);

        $this->assertSame(PriceFetchStatus::RateLimited, $result->status);
        $this->assertSame([
            'kind' => 'rate_limited',
            'retryable' => true,
            'http_status' => 429,
            'reason' => 'Too many requests',
        ], $result->error);
        $this->assertSame('rate_limited', $result->attempts[0]['status']);
        $this->assertSame(429, $result->attempts[0]['http_status']);
    }

    public function test_http_200_with_captcha_evidence_is_still_a_challenge(): void
    {
        Http::fake([
            'price-extractor.test/extract' => Http::response([
                'error' => 'CAPTCHA verification required',
                'blocked' => true,
            ]),
        ]);

        $result = resolve(RetailPriceExtractorClient::class)->fetchUrl(self::PRODUCT_URL);

        $this->assertSame(PriceFetchStatus::Challenge, $result->status);
        $this->assertSame(200, $result->attempts[0]['http_status']);
        $this->assertSame('CAPTCHA verification required', $result->error['reason']);
    }

    public function test_http_challenge_and_fallback_failure_preserve_both_attempts(): void
    {
        $store = $this->store('api');
        Http::fake([
            'price-extractor.test/extract' => Http::response([
                'error' => 'CAPTCHA verification required',
                'blocked' => true,
            ], 409),
        ]);

        $result = resolve(PriceFetchOrchestrator::class)->fetch(
            self::PRODUCT_URL,
            $store,
            fn (): array => [
                'fetch_error' => [
                    'kind' => 'network_error',
                    'retryable' => true,
                    'reason' => 'browser service unavailable',
                ],
            ],
        );

        $this->assertSame(PriceFetchStatus::NetworkError, $result->status);
        $this->assertCount(2, $result->attempts);
        $this->assertSame(['challenge', 'network_error'], array_column($result->attempts, 'status'));
        $this->assertSame(['price_extractor', 'seleniumbase'], array_column($result->attempts, 'engine'));
        $this->assertSame('browser service unavailable', $result->attempts[1]['error']['reason']);
    }

    public function test_fallback_network_exception_preserves_normalized_reason(): void
    {
        $store = $this->store('api');
        config()->set('deal_hunter.price_extractor_url');

        $result = resolve(PriceFetchOrchestrator::class)->fetch(
            self::PRODUCT_URL,
            $store,
            fn (): never => throw new RuntimeException(' Browser service   connection refused token=super-secret '),
        );

        $this->assertSame(PriceFetchStatus::NetworkError, $result->status);
        $this->assertSame('Browser service connection refused token=[redacted]', $result->error['reason']);
        $this->assertSame('Browser service connection refused token=[redacted]', $result->attempts[1]['error']['reason']);
    }

    public function test_fallback_timeout_exception_preserves_normalized_reason(): void
    {
        $store = $this->store('api');
        config()->set('deal_hunter.price_extractor_url');

        $result = resolve(PriceFetchOrchestrator::class)->fetch(
            self::PRODUCT_URL,
            $store,
            fn (): never => throw new RuntimeException(' Browser request   timed out after 35 seconds '),
        );

        $this->assertSame(PriceFetchStatus::Timeout, $result->status);
        $this->assertSame('Browser request timed out after 35 seconds', $result->error['reason']);
        $this->assertSame('Browser request timed out after 35 seconds', $result->attempts[1]['error']['reason']);
    }

    public function test_rejected_http_candidate_records_fallback_decision_without_changing_success_status(): void
    {
        $store = $this->store('api');
        Http::fake([
            'price-extractor.test/extract' => Http::response([
                'data' => [
                    'page_url' => self::PRODUCT_URL,
                    'title' => 'AMD Ryzen 7 7700X3D',
                    'availability' => 'in_stock',
                    'candidates' => [[
                        'price' => 159.99,
                        'currency' => 'EUR',
                        'source' => 'site_specific',
                        'confidence' => 0.96,
                    ]],
                ],
            ]),
        ]);

        $result = resolve(PriceFetchOrchestrator::class)->fetch(
            self::PRODUCT_URL,
            $store,
            fn (): array => [
                'store' => $store,
                'title' => 'AMD Ryzen 7 7700X3D',
                'price' => '149.99',
                'availability' => 'InStock',
                'errors' => [],
            ],
        );

        $this->assertSame(PriceFetchStatus::Success, $result->status);
        $this->assertCount(2, $result->attempts);
        $this->assertSame('success', $result->attempts[0]['status']);
        $this->assertNull($result->attempts[0]['error']);
        $this->assertSame('candidate_rejected', $result->attempts[0]['decision']);
        $this->assertSame('success', $result->attempts[1]['status']);
    }

    public function test_structured_fallback_uses_valid_rate_limit_status_when_kind_is_unknown(): void
    {
        $store = $this->store('http');
        Http::fake([
            'price-extractor.test/extract' => Http::response([
                'error' => 'Too many requests',
            ], 429),
        ]);

        $result = resolve(PriceFetchOrchestrator::class)->fetch(
            self::PRODUCT_URL,
            $store,
            fn (): array => [
                'fetch_error' => [
                    'kind' => 'vendor_throttled',
                    'status' => 'rate_limited',
                    'retryable' => true,
                    'http_status' => 429,
                    'reason' => 'upstream quota exhausted',
                ],
            ],
        );

        $this->assertSame(PriceFetchStatus::RateLimited, $result->status);
        $this->assertSame(['rate_limited', 'rate_limited'], array_column($result->attempts, 'status'));
        $this->assertNotContains('challenge', array_column($result->attempts, 'status'));
        $this->assertSame(429, $result->attempts[1]['http_status']);
        $this->assertSame('upstream quota exhausted', $result->error['reason']);
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
        $this->assertSame([
            'kind' => 'no_price',
            'retryable' => false,
            'http_status' => 200,
        ], $result->error);
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
        $this->assertSame(129.99, $result->candidates[0]['amount']);
        $this->assertTrue($result->pricesNormalized);
    }

    private function store(string $scraperService, string $locale = 'en_US'): Store
    {
        return Store::factory()->create([
            'domains' => [['domain' => 'www.newegg.com']],
            'settings' => [
                'scraper_service' => $scraperService,
                'scraper_service_settings' => '',
                'locale_settings' => ['locale' => $locale, 'currency' => 'USD'],
            ],
        ]);
    }

    private function fakeExtractorSuccess(string $availability, float $price = 159.99): void
    {
        Http::fake([
            'price-extractor.test/extract' => Http::response([
                'data' => [
                    'page_url' => self::PRODUCT_URL,
                    'title' => 'AMD Ryzen 7 7700X3D',
                    'image_url' => 'https://images.example/cpu.jpg',
                    'availability' => $availability,
                    'candidates' => [[
                        'price' => $price,
                        'currency' => 'USD',
                        'source' => 'site_specific',
                        'confidence' => 0.96,
                    ]],
                ],
            ]),
        ]);
    }
}
