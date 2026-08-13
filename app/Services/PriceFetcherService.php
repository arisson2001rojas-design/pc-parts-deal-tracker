<?php

namespace App\Services;

use App\Enums\Statuses;
use App\Jobs\UpdateAllPricesJob;
use App\Jobs\UpdateProductPricesJob;
use App\Models\Product;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

class PriceFetcherService
{
    public const JOB_TIMEOUT = 1200; // 20 minutes

    public const PRODUCT_LOCK_TTL = self::JOB_TIMEOUT + 300;

    public const PRODUCT_LOCK_RELEASE_AFTER = 30;

    public const PRODUCT_LOCK_MAX_QUEUE_ATTEMPTS = 60;

    protected array $config;

    protected bool $logging = false;

    public function __construct()
    {
        $this->config = config('price_buddy');
    }

    public static function new(): self
    {
        return resolve(static::class);
    }

    public function setLogging(bool $logging): self
    {
        $this->logging = $logging;

        return $this;
    }

    /**
     * Global lane: fetch every published product that follows the global
     * schedule (no custom interval) and isn't paused.
     */
    public function updateAllPrices(): void
    {
        Product::select('id')
            ->published()
            ->where('paused', false)
            ->whereNull('refresh_interval')
            ->chunk(data_get($this->config, 'chunk_size'), function (EloquentCollection $productIds) {
                UpdateAllPricesJob::dispatch($productIds->pluck('id')->toArray());
            });
    }

    /**
     * Per-product lane: fetch published, non-paused products with a custom
     * interval that are now due. Selection is repeated under row locks and the
     * next check is advanced in the same transaction as dispatch, so competing
     * schedulers cannot claim the same product.
     */
    public function updateDuePrices(): void
    {
        $chunkSize = max(1, (int) data_get($this->config, 'chunk_size'));

        Product::query()
            ->select('id')
            ->published()
            ->where('paused', false)
            ->whereNotNull('refresh_interval')
            ->where(fn ($query) => $query
                ->whereNull('next_check_at')
                ->orWhere('next_check_at', '<=', now())
            )
            ->chunkById($chunkSize, function (EloquentCollection $candidates): void {
                $candidates->each(function (Product $candidate): void {
                    DB::transaction(function () use ($candidate): void {
                        $product = Product::query()
                            ->whereKey($candidate->getKey())
                            ->lockForUpdate()
                            ->first();

                        if (! $product
                            || $product->status !== Statuses::Published
                            || $product->paused
                            || is_null($product->refresh_interval)
                            || $product->next_check_at?->isFuture()) {
                            return;
                        }

                        UpdateAllPricesJob::dispatch([$product->getKey()]);
                        $product->scheduleNextCheck();
                    });
                });
            });
    }

    public function getProducts(array $productIds): EloquentCollection
    {
        return Product::whereIn('id', $productIds)->get();
    }

    public function updatePrices(array $productIds): EloquentCollection
    {
        return $this
            ->getProducts($productIds)
            ->each(function ($product) {
                /** @var Product $product */
                UpdateProductPricesJob::dispatch($product, $this->logging);
            });
    }
}
