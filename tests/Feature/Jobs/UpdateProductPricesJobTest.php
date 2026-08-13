<?php

namespace Tests\Feature\Jobs;

use App\Dto\PriceFetchResult;
use App\Enums\PriceFetchStatus;
use App\Enums\ScraperService;
use App\Enums\Statuses;
use App\Jobs\RetryUrlPriceJob;
use App\Jobs\UpdateProductPricesJob;
use App\Models\Product;
use App\Models\Store;
use App\Models\Url;
use App\Models\User;
use App\Notifications\ScrapeFailNotification;
use App\Services\PriceFetchOrchestrator;
use App\Settings\AppSettings;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Mockery\MockInterface;
use Tests\TestCase;

class UpdateProductPricesJobTest extends TestCase
{
    use RefreshDatabase;

    private const string PRODUCT_URL = 'https://www.newegg.com/p/N82E16800000001';

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
    }

    public function test_job_executes_a_valid_cycle_with_an_automatable_source(): void
    {
        [$product] = $this->productWithUrl();
        $this->mockFetch($this->successResult());
        $expectedSleep = AppSettings::new()->sleep_seconds_between_scrape;

        (new UpdateProductPricesJob($product, false))->handle();

        $this->assertDatabaseCount('prices', 1);
        Sleep::assertSequence([Sleep::for($expectedSleep)->seconds()]);
    }

    public function test_job_logs_when_logging_is_enabled(): void
    {
        $messages = [];

        Log::listen(function (MessageLogged $event) use (&$messages): void {
            $messages[] = $event;
        });

        [$product] = $this->productWithUrl(title: 'Test Product');
        $this->mockFetch($this->successResult());

        (new UpdateProductPricesJob($product, true))->handle();

        $this->assertTrue(
            collect($messages)->contains(
                fn (MessageLogged $event): bool => $event->level === 'info'
                    && str_contains($event->message, "Starting price fetch for: 'Test Product'")
            )
        );
    }

    public function test_retryable_failure_schedules_attempt_two_without_notifying(): void
    {
        Queue::fake();
        Notification::fake();
        [$product, $url] = $this->productWithUrl();
        $this->mockFetch($this->failureResult(retryable: true));

        (new UpdateProductPricesJob($product, false))->handle();

        Queue::assertPushed(
            RetryUrlPriceJob::class,
            fn (RetryUrlPriceJob $job): bool => $job->url->is($url)
                && $job->attempt === 2
                && $job->delay !== null,
        );
        Notification::assertNothingSent();
    }

    public function test_retryable_failure_notifies_when_delayed_retries_are_disabled(): void
    {
        Queue::fake();
        Notification::fake();
        $settings = AppSettings::new();
        $settings->scrape_retry_max_attempts = 1;
        $settings->save();
        [$product, , $user] = $this->productWithUrl();
        $this->mockFetch($this->failureResult(retryable: true));

        (new UpdateProductPricesJob($product, false))->handle();

        Notification::assertSentTo($user, ScrapeFailNotification::class);
        Queue::assertNotPushed(RetryUrlPriceJob::class);
    }

    public function test_mixed_failures_notify_once_when_delayed_retries_are_disabled(): void
    {
        Queue::fake();
        Notification::fake();
        $settings = AppSettings::new();
        $settings->scrape_retry_max_attempts = 1;
        $settings->save();
        [$product, $url, $user] = $this->productWithUrl();
        Url::factory()->for($product)->create([
            'store_id' => $url->store_id,
            'url' => 'https://www.newegg.com/p/N82E16800000002',
        ]);
        $terminalFailure = $this->failureResult(retryable: false);
        $retryableFailure = $this->failureResult(retryable: true);
        $this->mock(PriceFetchOrchestrator::class, function (MockInterface $mock) use ($terminalFailure, $retryableFailure): void {
            $mock->shouldReceive('fetch')
                ->twice()
                ->andReturn($terminalFailure, $retryableFailure);
        });

        (new UpdateProductPricesJob($product, false))->handle();

        Notification::assertSentToTimes($user, ScrapeFailNotification::class, 1);
        Queue::assertNotPushed(RetryUrlPriceJob::class);
    }

    public function test_success_does_not_retry_or_notify(): void
    {
        Queue::fake();
        Notification::fake();
        [$product] = $this->productWithUrl();
        $this->mockFetch($this->successResult());

        (new UpdateProductPricesJob($product, false))->handle();

        Queue::assertNotPushed(RetryUrlPriceJob::class);
        Notification::assertNothingSent();
    }

    public function test_state_change_and_no_sources_skip_without_fetch_retry_or_notification(): void
    {
        Queue::fake();
        Notification::fake();
        $paused = Product::factory()->create([
            'paused' => true,
            'paused_by_user' => true,
            'status' => Statuses::Published->value,
        ]);
        $noSources = Product::factory()->create([
            'paused' => false,
            'paused_by_user' => false,
            'status' => Statuses::Published->value,
        ]);
        $this->mock(PriceFetchOrchestrator::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('fetch');
        });

        (new UpdateProductPricesJob($paused, false))->handle();
        (new UpdateProductPricesJob($noSources, false))->handle();

        Queue::assertNotPushed(RetryUrlPriceJob::class);
        Notification::assertNothingSent();
        $this->assertDatabaseCount('prices', 0);
    }

    /** @return array{Product, Url, User} */
    private function productWithUrl(string $title = 'Test Product'): array
    {
        $user = User::factory()->create();
        $product = Product::factory()->for($user)->create([
            'title' => $title,
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
        $url = Url::factory()->for($product)->for($store)->create(['url' => self::PRODUCT_URL]);

        return [$product, $url, $user];
    }

    private function mockFetch(PriceFetchResult $result): void
    {
        $this->mock(PriceFetchOrchestrator::class, function (MockInterface $mock) use ($result): void {
            $mock->shouldReceive('fetch')->once()->andReturn($result);
        });
    }

    private function successResult(): PriceFetchResult
    {
        return new PriceFetchResult(
            status: PriceFetchStatus::Success,
            source: 'test',
            engine: 'price_extractor',
            finalUrl: self::PRODUCT_URL,
            title: 'Test Product',
            candidates: [[
                'amount' => 149.99,
                'currency' => 'USD',
                'confidence' => 0.99,
                'evidence' => 'test',
            ]],
            observedAt: new DateTimeImmutable,
            pricesNormalized: true,
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
