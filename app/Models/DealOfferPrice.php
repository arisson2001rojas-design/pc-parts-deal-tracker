<?php

namespace App\Models;

use App\Enums\FulfillmentType;
use App\Enums\OfferCondition;
use App\Enums\OfferEvidenceQuality;
use App\Enums\OfferPurchasability;
use App\Enums\OfferScope;
use App\Enums\SellerType;
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
            'seller_type' => SellerType::class,
            'condition' => OfferCondition::class,
            'offer_scope' => OfferScope::class,
            'purchasability' => OfferPurchasability::class,
            'fulfillment_type' => FulfillmentType::class,
            'evidence_quality' => OfferEvidenceQuality::class,
            'bundle' => 'boolean',
            'comparison_eligible' => 'boolean',
            'offer_evidence' => 'array',
        ];
    }

    /** @return BelongsTo<DealOffer, $this> */
    public function dealOffer(): BelongsTo
    {
        return $this->belongsTo(DealOffer::class);
    }
}
