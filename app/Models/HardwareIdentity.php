<?php

namespace App\Models;

use App\Enums\ComponentType;
use Database\Factories\HardwareIdentityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property ComponentType $component_type
 * @property ?string $manufacturer
 * @property ?string $manufacturer_normalized
 * @property ?string $model
 * @property ?string $model_normalized
 * @property ?string $mpn
 * @property ?string $mpn_normalized
 * @property ?string $authoritative_key_hash
 * @property ?string $variant_fingerprint
 * @property array<string, mixed>|null $attributes
 */
class HardwareIdentity extends Model
{
    /** @use HasFactory<HardwareIdentityFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'component_type' => ComponentType::class,
            'attributes' => 'array',
        ];
    }

    /** @return HasMany<RetailerListing, $this> */
    public function retailerListings(): HasMany
    {
        return $this->hasMany(RetailerListing::class);
    }

    /** @return HasMany<PcPart, $this> */
    public function pcParts(): HasMany
    {
        return $this->hasMany(PcPart::class);
    }

    /** @return HasMany<HardwareIdentityClaim, $this> */
    public function claims(): HasMany
    {
        return $this->hasMany(HardwareIdentityClaim::class);
    }
}
