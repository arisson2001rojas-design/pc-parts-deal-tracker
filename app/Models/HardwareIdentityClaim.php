<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Inspectable authoritative alias. Ambiguous legacy claims stay in source
 * evidence and are not inserted here; a verified claim has one owner.
 *
 * @property int $hardware_identity_id
 */
class HardwareIdentityClaim extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'verified' => 'boolean',
            'evidence' => 'array',
        ];
    }

    /** @return BelongsTo<HardwareIdentity, $this> */
    public function hardwareIdentity(): BelongsTo
    {
        return $this->belongsTo(HardwareIdentity::class);
    }
}
