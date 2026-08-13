<?php

namespace App\Jobs;

use App\Enums\PriceUpdateStatus;
use App\Enums\Statuses;
use App\Models\Product;
use App\Models\Url;
use App\Notifications\ScrapeFailNotification;
use App\Services\PriceFetcherService;
use App\Services\ScrapeUrl;
use App\Settings\AppSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;

class RetryUrlPriceJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public $timeout = PriceFetcherService::JOB_TIMEOUT;

    /**
     * The logical retry chain is driven explicitly by $attempt. Queue attempts
     * are reserved for lock deferrals; maxExceptions prevents a real execution
     * exception from producing hidden scrape attempts.
     */
    public $tries = PriceFetcherService::PRODUCT_LOCK_MAX_QUEUE_ATTEMPTS;

    public $maxExceptions = 1;

    /**
     * If the URL (or its product) is deleted before this delayed job runs, just
     * discard the job instead of failing it.
     */
    public bool $deleteWhenMissingModels = true;

    public int $productId = 0;

    /**
     * @param  Url  $url  the URL to re-scrape
     * @param  int  $attempt  the attempt number this job represents (the original scheduled scrape is attempt 1)
     */
    public function __construct(public Url $url, public int $attempt)
    {
        $this->productId = (int) $url->product_id;
    }

    public function middleware(): array
    {
        $productId = $this->productId ?: (int) $this->url->product_id;

        return [
            (new WithoutOverlapping('price-fetch-product:'.$productId))
                ->shared()
                ->releaseAfter(PriceFetcherService::PRODUCT_LOCK_RELEASE_AFTER)
                ->expireAfter(PriceFetcherService::PRODUCT_LOCK_TTL),
        ];
    }

    public function handle(): void
    {
        $productId = $this->productId ?: (int) $this->url->product_id;
        $url = Url::query()->with('product.user')->find($this->url->getKey());
        $product = $url?->product;

        if (! $url
            || ! $product
            || $url->product_id !== $productId
            || $product->paused
            || $product->paused_by_user
            || $product->status !== Statuses::Published
            || $url->store_id === null
            || ! ScrapeUrl::allowsAutomatedAccess($url->url)) {
            return;
        }

        $outcome = $url->updatePriceWithOutcome(maxConfiguredEngineAttempts: 1);

        if (in_array($outcome->status, [
            PriceUpdateStatus::Accepted,
            PriceUpdateStatus::Unchanged,
            PriceUpdateStatus::Rejected,
        ], true)) {
            $product->updatePriceCache();
            $product->updateInsightsCache();

            return;
        }

        $settings = AppSettings::new();

        if (! $outcome->shouldRetry()) {
            if ($outcome->status === PriceUpdateStatus::Failed) {
                $this->notifyExhausted($product, $settings);
            }

            return;
        }

        if ($this->attempt < $settings->scrape_retry_max_attempts) {
            self::dispatch($url, $this->attempt + 1)
                ->delay(now()->addMinutes($settings->scrape_retry_delay_minutes));

            return;
        }

        $this->notifyExhausted($product, $settings);
    }

    /**
     * Notify the product owner that the scrape failed after all retries. The
     * notification is product-level, so it is throttled to one per product per
     * retry window to avoid flooding when several of a product's URLs exhaust
     * their retries at around the same time.
     */
    protected function notifyExhausted(Product $product, AppSettings $settings): void
    {
        $cacheKey = "scrape-fail-notified:{$product->getKey()}";
        $window = now()->addMinutes(max(1, $settings->scrape_retry_delay_minutes));

        if (Cache::add($cacheKey, true, $window)) {
            $product->user?->notify(new ScrapeFailNotification($product));
        }
    }
}
