<?php

namespace App\Models;

use App\Notifications\PcBuildTargetNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $name
 * @property ?float $target_total
 * @property ?float $last_alerted_total
 * @property float $current_total
 * @property int $missing_price_count
 * @property int $component_count
 * @property User $user
 */
class PcBuild extends Model
{
    protected $guarded = [];

    protected $casts = [
        'target_total' => 'float',
        'last_alerted_total' => 'float',
        'last_alerted_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PcBuildItem::class);
    }

    public function scopeCurrentUser(Builder $query): Builder
    {
        return $query->where('user_id', auth()->id());
    }

    public function currentTotal(): Attribute
    {
        return Attribute::make(
            get: fn (): float => round(
                $this->itemsWithProducts()
                    ->filter(fn (PcBuildItem $item): bool => (float) $item->product->current_price > 0)
                    ->sum(fn (PcBuildItem $item): float => (float) $item->product->current_price * $item->quantity),
                2
            )
        );
    }

    public function missingPriceCount(): Attribute
    {
        return Attribute::make(
            get: fn (): int => $this->itemsWithProducts()
                ->filter(fn (PcBuildItem $item): bool => (float) $item->product->current_price <= 0)
                ->count()
        );
    }

    public function componentCount(): Attribute
    {
        return Attribute::make(
            get: fn (): int => (int) $this->itemsWithProducts()->sum('quantity')
        );
    }

    public function evaluateAlert(): void
    {
        $this->loadMissing(['items.product', 'user']);

        if (! $this->target_total || $this->items->isEmpty() || $this->missing_price_count > 0) {
            return;
        }

        if ($this->current_total > $this->target_total) {
            if ($this->last_alerted_total !== null) {
                $this->forceFill([
                    'last_alerted_total' => null,
                    'last_alerted_at' => null,
                ])->saveQuietly();
            }

            return;
        }

        if ($this->last_alerted_total !== null) {
            return;
        }

        $this->user->notify(new PcBuildTargetNotification($this));

        $this->forceFill([
            'last_alerted_total' => $this->current_total,
            'last_alerted_at' => now(),
        ])->saveQuietly();
    }

    private function itemsWithProducts()
    {
        $this->loadMissing('items.product');

        return $this->items->filter(fn (PcBuildItem $item): bool => $item->product !== null);
    }
}
