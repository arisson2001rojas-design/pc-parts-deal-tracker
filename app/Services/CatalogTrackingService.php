<?php

namespace App\Services;

use App\Enums\Statuses;
use App\Jobs\EnrichProductImageJob;
use App\Jobs\UpdateProductPricesJob;
use App\Models\PcPart;
use App\Models\Product;
use App\Models\Store;
use App\Models\Url;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CatalogTrackingService
{
    private const int BROWSER_OBSERVATION_DEDUPLICATION_SECONDS = 300;

    /**
     * @param  null|array<int, string>  $retailers
     */
    public function track(
        PcPart $part,
        int $userId,
        ?array $retailers = null,
        bool $queueRefresh = true,
        bool $respectManualPause = false,
    ): Product {
        return DB::transaction(fn (): Product => $this->activate(
            $part,
            $userId,
            $retailers,
            $queueRefresh,
            respectManualPause: $respectManualPause,
        ));
    }

    /**
     * Activate only the catalog part observed by Browser Radar, persist its
     * verified browser price, and leave the regular due-product scheduler in
     * charge of the next refresh.
     *
     * @param  array{price: int|float|string, image_url?: string|null, availability?: string|null}  $observation
     */
    public function trackBrowserDiscovery(
        PcPart $part,
        int $userId,
        string $retailer,
        array $observation,
    ): Product {
        return DB::transaction(function () use ($part, $userId, $retailer, $observation): Product {
            $image = ScrapeUrl::preSaveMaxLength($observation['image_url'] ?? null);
            $product = $this->activate(
                $part,
                $userId,
                [$retailer],
                queueRefresh: false,
                image: $image,
                queueImageEnrichment: false,
                browserDiscovery: true,
            );

            $this->recordBrowserObservation($product, $part, $retailer, $observation, $image);
            $this->scheduleBrowserDiscovery($product);

            return $product->fresh();
        });
    }

    /**
     * @param  null|array<int, string>  $retailers
     */
    private function activate(
        PcPart $part,
        int $userId,
        ?array $retailers,
        bool $queueRefresh,
        ?string $image = null,
        bool $queueImageEnrichment = true,
        bool $browserDiscovery = false,
        bool $respectManualPause = false,
    ): Product {
        $trackingInterval = max(3600, (int) config('price_buddy.pc_parts_tracking_interval_seconds', 28800));
        $product = Product::query()->firstOrCreate(
            [
                'user_id' => $userId,
                'pc_part_id' => $part->getKey(),
            ],
            [
                'title' => Str::limit($part->name, 1024, ''),
                'image' => $image,
                'component_type' => $part->component_type->value,
                'price_cache' => [],
                'favourite' => ! $browserDiscovery,
                'refresh_interval' => $trackingInterval,
                'paused' => false,
                'paused_by_user' => false,
            ]
        );
        $wasRecentlyCreated = $product->wasRecentlyCreated;
        $product = Product::query()->lockForUpdate()->findOrFail($product->getKey());
        $preserveManualPause = ! $wasRecentlyCreated
            && $respectManualPause
            && $product->paused_by_user;
        $preserveExistingCadence = ! $wasRecentlyCreated && $respectManualPause;

        $reactivated = ! $preserveManualPause && ! $wasRecentlyCreated && ($browserDiscovery
            ? ($product->paused && ! $product->paused_by_user)
            : ($product->paused || ! $product->favourite || $product->status !== Statuses::Published));
        $attributes = [
            'title' => Str::limit($part->name, 1024, ''),
            'image' => blank($product->image) ? $image : $product->image,
            'component_type' => $part->component_type->value,
        ];

        if (! $preserveManualPause) {
            $attributes['status'] = Statuses::Published->value;
        }

        if ($browserDiscovery) {
            $attributes['paused'] = $product->paused_by_user;

            if (is_null($product->refresh_interval)) {
                $attributes['refresh_interval'] = $trackingInterval;
            }
        } elseif (! $preserveManualPause) {
            $attributes['favourite'] = true;
            $attributes['paused'] = false;
            $attributes['paused_by_user'] = false;

            if (! $preserveExistingCadence) {
                $attributes['refresh_interval'] = $trackingInterval;
            }
        }

        $product->forceFill($attributes);
        if ($product->isDirty()) {
            $product->save();
        }

        $product->loadMissing('urls');
        $createdUrl = false;
        $retailerUrls = Arr::only(
            $part->retailer_urls ?? [],
            $retailers ?? array_keys($part->retailer_urls ?? [])
        );

        foreach ($retailerUrls as $retailer => $url) {
            $store = Store::query()->where('slug', $this->storeSlug($retailer))->first();
            if (! $store || ! is_string($url) || ! str_starts_with($url, 'https://')) {
                continue;
            }

            $trackedUrl = $product->urls()->firstOrCreate(
                ['url' => $url],
                ['store_id' => $store->getKey(), 'price_factor' => 1]
            );
            $createdUrl = $createdUrl || $trackedUrl->wasRecentlyCreated;
        }

        $needsBootstrap = $wasRecentlyCreated || $reactivated || $createdUrl;

        if ($queueImageEnrichment && blank($product->image) && $needsBootstrap) {
            EnrichProductImageJob::dispatch($product->getKey())->afterCommit();
        }

        if ($queueRefresh
            && $needsBootstrap
            && ! $product->paused
            && ! $product->paused_by_user
            && $product->urls()->exists()) {
            $product->scheduleNextCheck();
            UpdateProductPricesJob::dispatch($product, true)->afterCommit();
        }

        return $product;
    }

    private function scheduleBrowserDiscovery(Product $product): void
    {
        if ($product->paused || ! $product->refresh_interval || $product->next_check_at?->isFuture()) {
            return;
        }

        $product->scheduleNextCheck();
    }

    /**
     * @param  array{price: int|float|string, image_url?: string|null, availability?: string|null}  $observation
     */
    private function recordBrowserObservation(
        Product $product,
        PcPart $part,
        string $retailer,
        array $observation,
        ?string $image,
    ): void {
        $urlValue = ($part->retailer_urls ?? [])[$retailer] ?? null;
        if (! is_string($urlValue)) {
            return;
        }

        $url = $product->urls()->where('url', $urlValue)->first();
        if (! $url instanceof Url) {
            return;
        }

        $capturedAvailability = $observation['availability'] ?? null;
        $availability = in_array($capturedAvailability, ['in_stock', 'out_of_stock'], true)
            ? $capturedAvailability
            : $url->getAvailabilityStatus()?->value;

        $url->loadMissing(['product', 'store']);
        $url->updatePrice(
            $observation['price'],
            ['image' => $image, 'availability' => $availability],
            self::BROWSER_OBSERVATION_DEDUPLICATION_SECONDS,
        );
    }

    private function storeSlug(string $retailer): string
    {
        return match ($retailer) {
            'amazon' => 'amazon-us',
            'walmart' => 'walmart-us',
            'newegg' => 'newegg-us',
            'micro-center' => 'micro-center-us',
            'best-buy' => 'best-buy-us',
            'gamestop' => 'gamestop-us',
            default => $retailer,
        };
    }
}
