<?php

namespace App\Models;

use App\Enums\FulfillmentType;
use App\Enums\OfferCondition;
use App\Enums\OfferEvidenceQuality;
use App\Enums\OfferPurchasability;
use App\Enums\OfferScope;
use App\Enums\SellerType;
use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * @property ?string $price
 * @property string $currency
 * @property string $source
 * @property ?Carbon $fetched_at
 * @property ?RetailerListing $listing
 * @property ?SellerType $seller_type
 * @property ?OfferCondition $condition
 * @property ?OfferScope $offer_scope
 * @property ?OfferPurchasability $purchasability
 * @property ?FulfillmentType $fulfillment_type
 * @property ?OfferEvidenceQuality $evidence_quality
 * @property ?bool $bundle
 * @property ?bool $comparison_eligible
 * @property array<string, mixed>|null $offer_evidence
 */
class DealOffer extends Model
{
    public const string USER_CONFIRMED_SOURCE = 'user_confirmed';

    public const string AVAILABILITY_IN_STOCK = 'in_stock';

    public const string AVAILABILITY_OUT_OF_STOCK = 'out_of_stock';

    public const string AVAILABILITY_UNKNOWN = 'unknown';

    public const array VERIFIED_PRICE_SOURCES = [
        'best_buy_api',
        'dealnews_rss',
        'direct_extract',
        'browser_capture',
        'browser_discovery',
        self::USER_CONFIRMED_SOURCE,
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'fetched_at' => 'datetime',
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

    /**
     * @return BelongsTo<DealSearch, $this>
     */
    public function dealSearch(): BelongsTo
    {
        return $this->belongsTo(DealSearch::class);
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(RetailerListing::class, 'retailer_listing_id');
    }

    /** @return HasMany<DealOfferPrice, $this> */
    public function priceSnapshots(): HasMany
    {
        return $this->hasMany(DealOfferPrice::class);
    }

    public function scopeCurrentUser(Builder $query): Builder
    {
        return $query->whereHas('dealSearch', fn (Builder $search): Builder => $search
            ->where('user_id', auth()->id())
        );
    }

    public function scopeVerifiedPrice(Builder $query): Builder
    {
        return $this->scopeObservedPrice($query)
            ->where(fn (Builder $integrity): Builder => $integrity
                ->where('comparison_eligible', true)
                ->orWhereNull('comparison_eligible')
            );
    }

    public function scopeObservedPrice(Builder $query): Builder
    {
        return $query
            ->whereNotNull('price')
            ->where(function (Builder $sources): Builder {
                return $sources
                    ->whereIn('source', array_values(array_diff(self::VERIFIED_PRICE_SOURCES, [self::USER_CONFIRMED_SOURCE])))
                    ->orWhere(fn (Builder $confirmed): Builder => $confirmed
                        ->where('source', self::USER_CONFIRMED_SOURCE)
                        ->where('fetched_at', '>=', now()->subHours(self::userConfirmationHours()))
                    );
            })
            ->where(fn (Builder $quality): Builder => $quality
                ->whereNull('evidence_quality')
                ->orWhere('evidence_quality', '!=', OfferEvidenceQuality::Invalid->value)
            );
    }

    public function hasVerifiedPrice(): bool
    {
        return $this->hasObservedPrice() && $this->comparison_eligible !== false;
    }

    public function hasObservedPrice(): bool
    {
        if ($this->price === null
            || ! in_array($this->source, self::VERIFIED_PRICE_SOURCES, true)
            || $this->evidence_quality === OfferEvidenceQuality::Invalid) {
            return false;
        }

        return $this->source !== self::USER_CONFIRMED_SOURCE || $this->hasFreshUserConfirmation();
    }

    public function hasFreshUserConfirmation(): bool
    {
        return $this->source === self::USER_CONFIRMED_SOURCE
            && $this->price !== null
            && $this->fetched_at?->gte(now()->subHours(self::userConfirmationHours())) === true;
    }

    private static function userConfirmationHours(): int
    {
        return max(1, (int) config('deal_hunter.user_confirmed_price_hours', 8));
    }

    public function recordPriceSnapshot(): ?DealOfferPrice
    {
        if (! $this->exists) {
            return null;
        }

        return DB::transaction(function (): ?DealOfferPrice {
            $offer = self::query()->lockForUpdate()->find($this->getKey());
            if (! $offer instanceof self || ! $offer->hasObservedPrice()) {
                return null;
            }

            $capturedAt = Carbon::parse($offer->fetched_at ?? now());
            $attributes = $offer->priceSnapshotAttributes($capturedAt);
            $latest = $offer->priceSnapshots()
                ->lockForUpdate()
                ->latest('captured_at')
                ->latest('id')
                ->first();
            if ($latest
                && (float) $latest->price === (float) $offer->price
                && $latest->currency === $offer->currency
                && $offer->hasSameSnapshotContext($latest, $attributes)
                && abs(Carbon::parse($latest->captured_at)->diffInSeconds($capturedAt, false)) < 300) {
                return $latest;
            }

            return $offer->priceSnapshots()->create($attributes);
        });
    }

    /** @return array<string, mixed> */
    private function priceSnapshotAttributes(Carbon $capturedAt): array
    {
        return [
            'price' => $this->price,
            'currency' => $this->currency,
            'source' => $this->source,
            'captured_at' => $capturedAt,
            'seller' => $this->seller,
            'availability' => $this->availability,
            'seller_type' => $this->seller_type?->value,
            'condition' => $this->condition?->value,
            'offer_scope' => $this->offer_scope?->value,
            'purchasability' => $this->purchasability?->value,
            'fulfillment_type' => $this->fulfillment_type?->value,
            'evidence_quality' => $this->evidence_quality?->value,
            'bundle' => $this->bundle,
            'comparison_eligible' => $this->comparison_eligible,
            'offer_evidence' => $this->offer_evidence,
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function hasSameSnapshotContext(DealOfferPrice $latest, array $attributes): bool
    {
        $keys = [
            'seller',
            'availability',
            'seller_type',
            'condition',
            'offer_scope',
            'purchasability',
            'fulfillment_type',
            'evidence_quality',
            'bundle',
            'comparison_eligible',
            'offer_evidence',
        ];
        $latestContext = [];
        $incomingContext = [];
        foreach ($keys as $key) {
            $latestContext[$key] = $latest->getAttribute($key);
            $incomingContext[$key] = $attributes[$key] ?? null;
        }

        return self::canonicalizeSnapshotContext($latestContext)
            === self::canonicalizeSnapshotContext($incomingContext);
    }

    private static function canonicalizeSnapshotContext(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }
        if (! is_array($value)) {
            return $value;
        }

        $list = array_is_list($value);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalizeSnapshotContext($item);
        }
        if (! $list) {
            ksort($value);
        }

        return $value;
    }

    public function isOutOfStock(): bool
    {
        return $this->availability === self::AVAILABILITY_OUT_OF_STOCK;
    }

    public function amazonAsin(): ?string
    {
        $host = strtolower((string) parse_url($this->url, PHP_URL_HOST));
        if ($host !== 'amazon.com' && ! str_ends_with($host, '.amazon.com')) {
            return null;
        }

        if (preg_match('~/(?:dp|gp/product|gp/aw/d)/([A-Z0-9]{10})(?:[/?]|$)~i', $this->url, $matches) !== 1) {
            return null;
        }

        return strtoupper($matches[1]);
    }

    public function keepaGraphUrl(int $days = 90): ?string
    {
        $asin = $this->amazonAsin();
        if ($asin === null) {
            return null;
        }

        return 'https://graph.keepa.com/pricehistory.png?'.http_build_query([
            'asin' => $asin,
            'domain' => 'com',
            'amazon' => 1,
            'new' => 1,
            'used' => 0,
            'bb' => 1,
            'range' => max(1, min($days, 3650)),
            'width' => 1200,
            'height' => 460,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function keepaProductUrl(): ?string
    {
        $asin = $this->amazonAsin();

        return $asin === null ? null : 'https://keepa.com/#!product/1-'.$asin;
    }

    public function browserCaptureLaunchUrl(): string
    {
        $endpointPath = URL::temporarySignedRoute(
            'api.browser-capture',
            now()->addMinutes(30),
            ['offer' => $this->getKey()],
            false,
        );
        $endpoint = rtrim((string) config('deal_hunter.companion_url'), '/').$endpointPath;
        $token = rtrim(strtr(base64_encode($endpoint), '+/', '-_'), '=');
        $retailerUrl = preg_replace('/#.*$/', '', $this->url) ?: $this->url;

        return $retailerUrl.'#pricebuddy='.$token;
    }

    public function supportsBrowserCapture(): bool
    {
        $host = strtolower((string) parse_url($this->url, PHP_URL_HOST));
        if ($host === '') {
            return false;
        }

        foreach ((array) config('deal_hunter.retailers', []) as $retailer) {
            foreach ((array) ($retailer['domains'] ?? []) as $domain) {
                $domain = strtolower(ltrim((string) $domain, '.'));
                if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                    return true;
                }
            }
        }

        return false;
    }
}
