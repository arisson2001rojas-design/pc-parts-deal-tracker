<?php

namespace App\Models;

use App\Services\CatalogTrackingService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property PcBuild $build
 * @property ?Product $product
 * @property ?PcPart $pcPart
 */
class PcBuildItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'quantity' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (PcBuildItem $item): void {
            if ($item->isDirty('pc_part_id')) {
                $item->product_id = null;
            }
        });

        static::saved(function (PcBuildItem $item): void {
            if ($item->product_id || ! $item->pc_part_id) {
                return;
            }

            $build = $item->build()->first();
            $part = $item->pcPart()->first();
            if (! $build || ! $part) {
                return;
            }

            $product = resolve(CatalogTrackingService::class)->track(
                $part,
                $build->user_id,
                respectManualPause: true,
            );
            $item->forceFill(['product_id' => $product->getKey()])->saveQuietly();
            $item->setRelation('product', $product);
        });
    }

    /**
     * @return BelongsTo<PcBuild, $this>
     */
    public function build(): BelongsTo
    {
        return $this->belongsTo(PcBuild::class, 'pc_build_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<PcPart, $this>
     */
    public function pcPart(): BelongsTo
    {
        return $this->belongsTo(PcPart::class);
    }
}
