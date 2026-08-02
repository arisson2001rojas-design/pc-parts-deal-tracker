<?php

namespace App\Services;

use App\Jobs\UpdateProductPricesJob;
use App\Models\PcPart;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class CatalogTrackingService
{
    /**
     * @param  null|array<int, string>  $retailers
     */
    public function track(PcPart $part, int $userId, ?array $retailers = null, bool $queueRefresh = true): Product
    {
        $product = Product::query()->firstOrCreate(
            [
                'user_id' => $userId,
                'pc_part_id' => $part->getKey(),
            ],
            [
                'title' => Str::limit($part->name, 1024, ''),
                'component_type' => $part->component_type->value,
                'price_cache' => [],
                'favourite' => true,
                'refresh_interval' => 86400,
                'paused' => false,
            ]
        );

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

        if ($queueRefresh && ($product->wasRecentlyCreated || $createdUrl) && $product->urls()->exists()) {
            $product->scheduleNextCheck();
            UpdateProductPricesJob::dispatch($product, true)->afterCommit();
        }

        return $product;
    }

    private function storeSlug(string $retailer): string
    {
        return match ($retailer) {
            'amazon' => 'amazon-us',
            'walmart' => 'walmart-us',
            'newegg' => 'newegg-us',
            default => $retailer,
        };
    }
}

