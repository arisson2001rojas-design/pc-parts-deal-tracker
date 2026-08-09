<?php

namespace App\Jobs;

use App\Models\Product;
use App\Services\ProductImageSearchService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

class EnrichProductImageJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $uniqueFor = 3600;

    public int $tries = 2;

    public int $timeout = 45;

    public function __construct(public readonly int $productId) {}

    public function handle(ProductImageSearchService $images): void
    {
        $product = Product::query()->with('pcPart')->find($this->productId);
        if (! $product || filled($product->image) || ! $product->pcPart) {
            return;
        }

        $image = $images->findForPart($product->pcPart);
        if ($image !== null) {
            $product->forceFill(['image' => $image])->save();
        }
    }

    public function uniqueId(): string
    {
        return (string) $this->productId;
    }
}
