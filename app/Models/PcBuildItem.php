<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PcBuildItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'quantity' => 'integer',
    ];

    public function build(): BelongsTo
    {
        return $this->belongsTo(PcBuild::class, 'pc_build_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
