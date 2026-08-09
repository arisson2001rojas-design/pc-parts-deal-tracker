<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DealOffer extends Model
{
    public const array VERIFIED_PRICE_SOURCES = ['best_buy_api', 'dealnews_rss'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'fetched_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<DealSearch, $this>
     */
    public function dealSearch(): BelongsTo
    {
        return $this->belongsTo(DealSearch::class);
    }

    public function scopeCurrentUser(Builder $query): Builder
    {
        return $query->whereHas('dealSearch', fn (Builder $search): Builder => $search
            ->where('user_id', auth()->id())
        );
    }

    public function scopeVerifiedPrice(Builder $query): Builder
    {
        return $query
            ->whereNotNull('price')
            ->whereIn('source', self::VERIFIED_PRICE_SOURCES);
    }

    public function hasVerifiedPrice(): bool
    {
        return $this->price !== null && in_array($this->source, self::VERIFIED_PRICE_SOURCES, true);
    }
}
