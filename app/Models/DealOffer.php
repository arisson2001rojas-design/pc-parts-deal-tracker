<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DealOffer extends Model
{
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
}
