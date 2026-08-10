<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

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
        ];
    }

    /**
     * @return BelongsTo<DealSearch, $this>
     */
    public function dealSearch(): BelongsTo
    {
        return $this->belongsTo(DealSearch::class);
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
        return $query
            ->whereNotNull('price')
            ->where(function (Builder $sources): Builder {
                return $sources
                    ->whereIn('source', array_values(array_diff(self::VERIFIED_PRICE_SOURCES, [self::USER_CONFIRMED_SOURCE])))
                    ->orWhere(fn (Builder $confirmed): Builder => $confirmed
                        ->where('source', self::USER_CONFIRMED_SOURCE)
                        ->where('fetched_at', '>=', now()->subHours(self::userConfirmationHours()))
                    );
            });
    }

    public function hasVerifiedPrice(): bool
    {
        if ($this->price === null || ! in_array($this->source, self::VERIFIED_PRICE_SOURCES, true)) {
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
        return max(1, (int) config('deal_hunter.user_confirmed_price_hours', 24));
    }

    public function recordPriceSnapshot(): ?DealOfferPrice
    {
        if (! $this->hasVerifiedPrice()) {
            return null;
        }

        $capturedAt = Carbon::parse($this->fetched_at ?? now());
        $latest = $this->priceSnapshots()->latest('captured_at')->first();
        if ($latest
            && (float) $latest->price === (float) $this->price
            && $latest->currency === $this->currency
            && abs(Carbon::parse($latest->captured_at)->diffInSeconds($capturedAt, false)) < 300) {
            return $latest;
        }

        return $this->priceSnapshots()->create([
            'price' => $this->price,
            'currency' => $this->currency,
            'source' => $this->source,
            'captured_at' => $capturedAt,
        ]);
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
