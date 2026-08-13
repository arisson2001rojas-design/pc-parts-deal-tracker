<?php

namespace App\Jobs;

use App\Dto\PriceUpdateOutcome;
use App\Enums\PriceUpdateStatus;
use App\Enums\Statuses;
use App\Models\PcBuild;
use App\Models\Product;
use App\Models\Url;
use App\Notifications\ScrapeFailNotification;
use App\Services\PriceFetcherService;
use App\Services\ScrapeUrl;
use App\Settings\AppSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Collection;
use Illuminate\Support\Sleep;

class UpdateProductPricesJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public $timeout = PriceFetcherService::JOB_TIMEOUT;

    public $tries = 1;

    public $maxExceptions = 1;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Product $product, public bool $logging) {}

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('price-fetch-product:'.$this->product->getKey()))
                ->shared()
                ->dontRelease()
                ->expireAfter(PriceFetcherService::PRODUCT_LOCK_TTL),
        ];
    }

    public function handle(): void
    {
        $product = Product::query()
            ->with(['urls', 'user'])
            ->find($this->product->getKey());

        if (! $product
            || $product->paused
            || $product->paused_by_user
            || $product->status !== Statuses::Published) {
            $this->logSkipped($product, PriceUpdateOutcome::skipped('state_changed'));

            return;
        }

        /** @var EloquentCollection<int, Url> $productUrls */
        $productUrls = $product->urls;
        $urls = $productUrls
            ->filter(fn (Url $url): bool => $url->store_id !== null
                && ScrapeUrl::allowsAutomatedAccess($url->url))
            ->values();

        if ($urls->isEmpty()) {
            $this->logSkipped($product, PriceUpdateOutcome::skipped('no_sources'));

            return;
        }

        $this->product = $product;

        if ($this->logging) {
            logger()->info("Starting price fetch for: '{$product->title}'", [
                'product_id' => $product->id,
            ]);
        }

        $outcomes = $product->updatePricesWithOutcomes($urls, 1);
        $retryableFailures = $outcomes
            ->filter(fn (array $attempt): bool => $attempt['outcome']->shouldRetry())
            ->values();
        $terminalFailures = $outcomes
            ->filter(fn (array $attempt): bool => $attempt['outcome']->status === PriceUpdateStatus::Failed
                && ! $attempt['outcome']->retryable)
            ->values();
        $rejected = $outcomes
            ->filter(fn (array $attempt): bool => $attempt['outcome']->status === PriceUpdateStatus::Rejected)
            ->count();
        $successful = $retryableFailures->isEmpty() && $terminalFailures->isEmpty() && $rejected === 0;

        if ($this->logging) {
            $prefix = $successful ? 'Successful' : 'Failed (or partially failed)';
            $method = $successful ? 'info' : 'warning';
            logger()->{$method}("$prefix price fetch for product: '{$product->title}'", [
                'product_id' => $product->id,
                'retryable_failures' => $retryableFailures->count(),
                'terminal_failures' => $terminalFailures->count(),
                'rejected' => $rejected,
            ]);
        }

        $immediateFailureNotificationSent = false;

        if ($terminalFailures->isNotEmpty()) {
            $product->user?->notify(new ScrapeFailNotification($product));
            $immediateFailureNotificationSent = true;
        }

        if ($retryableFailures->isNotEmpty()) {
            $this->handleFailures($retryableFailures, ! $immediateFailureNotificationSent);
        }

        PcBuild::query()
            ->whereHas('items', fn ($query) => $query->where('product_id', $product->getKey()))
            ->with(['items.product', 'user'])
            ->get()
            ->each(fn (PcBuild $build) => $build->evaluateAlert());

        Sleep::for(AppSettings::new()->sleep_seconds_between_scrape)->seconds();
    }

    /**
     * Schedule a delayed retry for each failed URL, or notify immediately if
     * delayed retries are disabled.
     *
     * @param  Collection<int, array{url: Url, outcome: PriceUpdateOutcome}>  $failedAttempts
     */
    protected function handleFailures(Collection $failedAttempts, bool $notifyIfRetriesDisabled): void
    {
        $settings = AppSettings::new();

        if ($settings->scrape_retry_max_attempts < 2) {
            if ($notifyIfRetriesDisabled) {
                $this->product->user?->notify(new ScrapeFailNotification($this->product));
            }

            return;
        }

        $delay = now()->addMinutes($settings->scrape_retry_delay_minutes);

        $failedAttempts->each(
            fn (array $attempt) => RetryUrlPriceJob::dispatch($attempt['url'], 2)->delay($delay)
        );
    }

    private function logSkipped(?Product $product, PriceUpdateOutcome $outcome): void
    {
        if (! $this->logging) {
            return;
        }

        logger()->info('Skipped product price fetch', [
            'product_id' => $product?->getKey() ?? $this->product->getKey(),
            'status' => $outcome->status->value,
            'reason' => $outcome->reason,
        ]);
    }
}
