<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;

class DealOffer extends Model
{
    public const array VERIFIED_PRICE_SOURCES = [
        'best_buy_api',
        'dealnews_rss',
        'direct_extract',
        'browser_capture',
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
            ->whereIn('source', self::VERIFIED_PRICE_SOURCES);
    }

    public function hasVerifiedPrice(): bool
    {
        return $this->price !== null && in_array($this->source, self::VERIFIED_PRICE_SOURCES, true);
    }

    public function browserCaptureLaunchUrl(): string
    {
        $endpoint = URL::temporarySignedRoute(
            'api.browser-capture',
            now()->addMinutes(30),
            ['offer' => $this->getKey()],
        );
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
