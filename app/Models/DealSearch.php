<?php

namespace App\Models;

use App\Enums\ComponentType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DealSearch extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'component_type' => ComponentType::class,
            'target_price' => 'decimal:2',
            'enabled' => 'boolean',
            'last_searched_at' => 'datetime',
            'last_notified_price' => 'decimal:2',
            'last_notified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<DealOffer, $this>
     */
    public function offers(): HasMany
    {
        return $this->hasMany(DealOffer::class);
    }

    public function scopeCurrentUser(Builder $query): Builder
    {
        return $query->where('user_id', auth()->id());
    }
}
