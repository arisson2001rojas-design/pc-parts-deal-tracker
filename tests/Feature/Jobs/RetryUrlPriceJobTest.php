<?php

namespace Tests\Feature\Jobs;

use App\Dto\PriceFetchResult;
use App\Enums\PriceFetchStatus;
use App\Enums\ScraperService;
use App\Enums\Statuses;
use App\Jobs\RetryUrlPriceJob;
use App\Models\Product;
use App\Models\Store;
use App\Models\Url;
use App\Models\User;
use App\Notifications\ScrapeFailNotification;
use App\Services\PriceFetchOrchestrator;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

class RetryUrlPriceJobTest extends TestCase
{
    use RefreshDatabase;

    private const string PRODUCT_URL = 'https://www.newegg.com/p/N82E16800000001';

    public function test_retryable_failure_schedules_the_next_logical_attempt(): void
    {
        Queue::fake();
        Notification::fake();
        [, [$url]] = $this->productWithUrls();
        $this->mockFetch($this->failureResult(retryable: true));

        (new RetryUrlPriceJob($url, 2))->handle();

        Queue::assertPushed(
            RetryUrlPriceJob::class,
            fn (RetryUrlPriceJob $job): bool => $job->attempt === 3
                && $job->url->is($url)
                && $job->delay !== null,
        );
        Notification::assertNothingSent();
    }

    public function test_exhausted_retryable_failure_notifies_and_stops(): void
    {
        Queue::fake();
        Notification::fake();
        [, [$url], $user] = $this->productWithUrls();
        $this->mockFetch($this->failureResult(retryable: true));

        (new RetryUrlPriceJob($url, 3))->handle();

        Notification::assertSentTo($user, ScrapeFailNotification::class);
        Queue::assertNotPushed(RetryUrlPriceJob::class);
    }

    public function test_accepted_outcome_stops_without_retry_or_notification(): void
    {
        Queue::fake();
        Notification::fake();
        [, [$url]] = $this->productWithUrls();
        $this->mockFetch($this->successResult());

        (new RetryUrlPriceJob($url, 2))->handle();

        $this->assertDatabaseCount('prices', 1);
        Queue::assertNotPushed(RetryUrlPriceJob::class);
        Notification::assertNothingSent();
    }

    public function test_unchanged_out_of_stock_outcome_stops_without_retry_or_notification(): void
    {
        Queue::fake();
        Notification::fake();
        [, [$url]] = $this->productWithUrls();
        $this->mockFetch($this->outOfStockResult());

        (new RetryUrlPriceJob($url, 2))->handle();

        $this->assertDatabaseCount('prices', 0);
        Queue::assertNotPushed(RetryUrlPriceJob::class);
        Notification::assertNothingSent();
    }

    public function test_rejected_outcome_stops_without_retry_or_notification(): void
    {
        Queue::fake();
        Notification::fake();
        [, [$url]] = $this->productWithUrls();
        $this->mockFetch($this->successResult(currency: 'CRC'));

        (new RetryUrlPriceJob($url, 2))->handle();

        $this->assertDatabaseCount('prices', 0);
        Queue::assertNotPushed(RetryUrlPriceJob::class);
        Notification::assertNothingSent();
    }

    public function test_paused_product_aborts_before_fetching(): void
    {
        Queue::fake();
        Notification::fake();
        [$product, [$url]] = $this->productWithUrls();
        $product->forceFill(['paused' => true, 'paused_by_user' => true])->saveQuietly();
        $this->mock(PriceFetchOrchestrator::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('fetch');
        });

        (new RetryUrlPriceJob($url, 2))->handle();

        Queue::assertNotPushed(RetryUrlPriceJob::class);
        Notification::assertNothingSent();
    }

    public function test_exhaustion_across_two_urls_notifies_once_per_product(): void
    {
        Queue::fake();
        Notification::fake();
        [, [$urlA, $urlB], $user] = $this->productWithUrls(2);
        $this->mockFetch($this->failureResult(retryable: true), times: 2);

        (new RetryUrlPriceJob($urlA, 3))->handle();
        (new RetryUrlPriceJob($urlB, 3))->handle();

        Notification::assertSentToTimes($user, ScrapeFailNotification::class, 1);
        Queue::assertNotPushed(RetryUrlPriceJob::class);
    }

    /** @return array{Product, list<Url>, User} */
    private function productWithUrls(int $count = 1): array
    {
        $user = User::factory()->create();
        $product = Product::factory()->for($user)->create([
            'paused' => false,
            'paused_by_user' => false,
            'status' => Statuses::Published->value,
        ]);
        $store = Store::factory()->create([
            'domains' => [['domain' => 'www.newegg.com']],
            'settings' => [
                'scraper_service' => ScraperService::Http->value,
                'scraper_service_settings' => '',
                'locale_settings' => ['locale' => 'en_US', 'currency' => 'USD'],
                'ai_self_healing_disabled' => true,
            ],
        ]);
        $urls = [];

        for ($index = 1; $index <= $count; $index++) {
            $urls[] = Url::factory()->for($product)->for($store)->create([
                'url' => self::PRODUCT_URL.'?source='.$index,
            ]);
        }

        return [$product, $urls, $user];
    }

    private function mockFetch(PriceFetchResult $result, int $times = 1): void
    {
        $this->mock(PriceFetchOrchestrator::class, function (MockInterface $mock) use ($result, $times): void {
            $mock->shouldReceive('fetch')->times($times)->andReturn($result);
        });
    }

    private function successResult(string $currency = 'USD'): PriceFetchResult
    {
        return new PriceFetchResult(
            status: PriceFetchStatus::Success,
            source: 'test',
            engine: 'price_extractor',
            finalUrl: self::PRODUCT_URL,
            title: 'Test Product',
            candidates: [[
                'amount' => 149.99,
                'currency' => $currency,
                'confidence' => 0.99,
                'evidence' => 'test',
            ]],
            observedAt: new DateTimeImmutable,
            pricesNormalized: true,
        );
    }

    private function outOfStockResult(): PriceFetchResult
    {
        return new PriceFetchResult(
            status: PriceFetchStatus::NoPrice,
            source: 'test',
            engine: 'price_extractor',
            finalUrl: self::PRODUCT_URL,
            title: 'Test Product',
            availability: 'out_of_stock',
            observedAt: new DateTimeImmutable,
            error: ['kind' => 'no_price', 'retryable' => false],
            availabilityNormalized: true,
        );
    }

    private function failureResult(bool $retryable): PriceFetchResult
    {
        return new PriceFetchResult(
            status: PriceFetchStatus::NetworkError,
            source: 'test',
            engine: 'price_extractor',
            finalUrl: self::PRODUCT_URL,
            observedAt: new DateTimeImmutable,
            error: ['kind' => 'network_error', 'retryable' => $retryable],
        );
    }
}
