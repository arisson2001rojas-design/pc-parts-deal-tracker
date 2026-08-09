<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DealOfferPrice extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'captured_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<DealOffer, $this> */
    public function dealOffer(): BelongsTo
    {
        return $this->belongsTo(DealOffer::class);
    }
}
