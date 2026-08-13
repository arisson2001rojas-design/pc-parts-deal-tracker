<?php

namespace App\Models;

use App\Enums\IdentityResolutionState;
use Database\Factories\RetailerListingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $retailer_key
 * @property string $identifier_type
 * @property string $external_identifier
 * @property string $listing_key_hash
 * @property string $canonical_url
 * @property ?string $normalized_url
 * @property ?string $url_hash
 * @property ?string $title
 * @property ?string $seller
 * @property ?bool $marketplace
 * @property ?HardwareIdentity $hardwareIdentity
 * @property IdentityResolutionState $resolution_state
 * @property ?string $resolution_reason
 * @property array<string, mixed>|null $evidence
 * @property array<int, mixed>|array<string, mixed>|null $decision_trace
 */
class RetailerListing extends Model
{
    /** @use HasFactory<RetailerListingFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'marketplace' => 'boolean',
            'resolution_state' => IdentityResolutionState::class,
            'resolved_at' => 'datetime',
            'evidence' => 'array',
            'decision_trace' => 'array',
        ];
    }

    /** @return BelongsTo<HardwareIdentity, $this> */
    public function hardwareIdentity(): BelongsTo
    {
        return $this->belongsTo(HardwareIdentity::class);
    }

    /** @return HasMany<Url, $this> */
    public function urls(): HasMany
    {
        return $this->hasMany(Url::class);
    }

    /** @return HasMany<DealOffer, $this> */
    public function dealOffers(): HasMany
    {
        return $this->hasMany(DealOffer::class);
    }
}
