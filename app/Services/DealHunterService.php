<?php

namespace App\Services;

use App\Enums\ComponentType;
use App\Jobs\VerifyDealOfferJob;
use App\Models\DealOffer;
use App\Models\DealSearch;
use App\Models\Product;
use App\Notifications\DealFoundNotification;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use SimpleXMLElement;
use InvalidArgumentException;
use Throwable;

class DealHunterService
{
    public function __construct(private readonly ProductImageSearchService $images) {}

    public function refresh(DealSearch $search): int
    {
        $this->pruneInvalidOffers($search);
        $offers = collect($this->searchWebIndex($search))
            ->merge($this->searchDealNews($search));

        if (filled(config('deal_hunter.best_buy_api_key'))) {
            $bestBuy = $this->searchBestBuy($search);

            if ($bestBuy['succeeded']) {
                $offers = $offers->reject(fn (array $offer): bool => $offer['store'] === 'Best Buy'
                    && $offer['source'] === 'web_index')
                    ->merge($bestBuy['offers']);
            }
        }

        $offers = collect($this->enrichImages($offers->all()));

        $now = now();
        $autoVerifyLimit = max(0, (int) config('deal_hunter.auto_verify_per_store', 3));
        $verificationCounts = [];

        foreach ($offers as $offer) {
            $hash = hash('sha256', $offer['url']);
            $values = $offer + ['fetched_at' => $now];
            if (blank($values['image_url'] ?? null)) {
                unset($values['image_url']);
            }

            $dealOffer = DealOffer::query()->firstOrNew([
                'deal_search_id' => $search->getKey(),
                'url_hash' => $hash,
            ]);
            if ($dealOffer->exists
                && $dealOffer->hasVerifiedPrice()
                && $values['source'] === 'web_index'
                && ($values['price'] ?? null) === null) {
                unset(
                    $values['price'],
                    $values['currency'],
                    $values['source'],
                    $values['fetched_at'],
                );
            }
            $dealOffer->fill($values)->save();
            if ($dealOffer->hasVerifiedPrice()
                && isset($values['price'])
                && in_array($values['source'], DealOffer::VERIFIED_PRICE_SOURCES, true)) {
                $dealOffer->recordPriceSnapshot();
            }

            $store = (string) $dealOffer->store;
            $verificationCounts[$store] ??= 0;
            $fetchedAt = $dealOffer->getAttribute('fetched_at');
            $verifiedPriceIsStale = in_array($dealOffer->source, ['direct_extract', 'browser_capture', 'browser_discovery'], true)
                && $fetchedAt !== null
                && Carbon::parse($fetchedAt)->lte(now()->subHours((int) config('deal_hunter.refresh_hours', 8)));
            if ($autoVerifyLimit > 0
                && filled(config('deal_hunter.price_extractor_url'))
                && ScrapeUrl::allowsAutomatedAccess($dealOffer->url)
                && ($dealOffer->source === 'web_index' || $verifiedPriceIsStale)
                && $verificationCounts[$store] < $autoVerifyLimit) {
                VerifyDealOfferJob::dispatch($dealOffer->getKey());
                $verificationCounts[$store]++;
            }
        }

        $search->forceFill(['last_searched_at' => $now])->save();
        $this->notifyWhenTargetReached($search->fresh(['user']));

        return $offers->count();
    }

    public function confirmPrice(DealOffer $offer, float $price): DealOffer
    {
        $search = $offer->dealSearch()->with('user')->first();
        if (! $search instanceof DealSearch) {
            throw new InvalidArgumentException('La cacería guardada para esta oferta ya no existe.');
        }

        $product = new Product(['component_type' => $search->component_type]);
        $reason = PcComponentPriceGuard::rejectionReason($product, (string) $price, $price, 'USD');
        if ($reason !== null) {
            throw new InvalidArgumentException($reason);
        }

        $offer->forceFill([
            'price' => round($price, 2),
            'currency' => 'USD',
            'source' => DealOffer::USER_CONFIRMED_SOURCE,
            'fetched_at' => now(),
        ])->save();
        $offer->recordPriceSnapshot();

        $this->notifyWhenTargetReached($search);

        return $offer->refresh();
    }

    /**
     * @return array<int, array{store: string, title: string, url: string, image_url: ?string, price: ?float, currency: string, source: string}>
     */
    private function searchWebIndex(DealSearch $search): array
    {
        $retailers = (array) config('deal_hunter.retailers', []);
        $endpoint = (string) config('deal_hunter.search_url');

        try {
            $responses = Http::pool(function (Pool $pool) use ($endpoint, $retailers, $search): array {
                $requests = [];

                foreach ($retailers as $slug => $retailer) {
                    $domain = data_get($retailer, 'domains.0');
                    if (! is_string($domain) || $domain === '') {
                        continue;
                    }

                    $requests[] = $pool
                        ->as((string) $slug)
                        ->acceptJson()
                        ->timeout(25)
                        ->get($endpoint, [
                            'format' => 'json',
                            'language' => 'en-US',
                            'safesearch' => 0,
                            'q' => $search->query.' site:'.$domain,
                        ]);
                }

                return $requests;
            });
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }

        $offers = [];

        foreach ($responses as $slug => $response) {
            if (! $response instanceof Response || ! $response->successful()) {
                continue;
            }

            $retailer = $retailers[$slug] ?? [];
            $store = (string) ($retailer['name'] ?? '');
            if ($store === '') {
                continue;
            }

            $results = (array) $response->json('results', []);
            $storeOffers = collect($results)
                ->map(fn (array $result): ?array => $this->mapWebResult($result, $retailer, $search))
                ->filter()
                ->take((int) config('deal_hunter.max_results_per_store', 10))
                ->values()
                ->all();

            array_push($offers, ...$storeOffers);
        }

        return collect($offers)->unique('url')->values()->all();
    }

    /**
     * @return array<int, array{store: string, title: string, url: string, image_url: ?string, price: ?float, currency: string, source: string, fetched_at: Carbon}>
     */
    private function searchDealNews(DealSearch $search): array
    {
        $feedUrl = config('deal_hunter.dealnews_feed_url');
        if (! is_string($feedUrl) || $feedUrl === '') {
            return [];
        }

        try {
            $contents = Cache::remember(
                'deal-hunter:dealnews-components-feed',
                now()->addMinutes(30),
                fn (): string => Http::withUserAgent('PC Deal Hunter local RSS reader')
                    ->accept('application/rss+xml, application/xml;q=0.9, text/xml;q=0.8')
                    ->timeout(25)
                    ->get($feedUrl)
                    ->throw()
                    ->body()
            );
            if (! is_string($contents)) {
                return [];
            }

            $feed = simplexml_load_string($contents, SimpleXMLElement::class, LIBXML_NOCDATA | LIBXML_NONET);
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }

        if (! $feed instanceof SimpleXMLElement) {
            return [];
        }

        $offers = [];
        foreach ($feed->channel->item as $item) {
            $title = trim((string) $item->title);
            $rawDescription = (string) $item->description;
            $description = html_entity_decode(
                strip_tags($rawDescription),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            );
            if ($title === ''
                || ! $this->matchesComponentType($title, $search->component_type)
                || ! $this->isRelevantText($title.' '.$description, $search->query)) {
                continue;
            }

            $store = $this->detectRetailer($title, $description);
            $url = trim((string) $item->link);
            if ($store === null || ! str_starts_with($url, 'https://www.dealnews.com/')) {
                continue;
            }

            try {
                $publishedAt = Carbon::parse((string) $item->pubDate);
            } catch (Throwable) {
                $publishedAt = now();
            }

            $offers[] = [
                'store' => $store,
                'title' => Str::limit($title, 1024, ''),
                'url' => $url,
                'image_url' => $this->extractImageUrl($rawDescription),
                'price' => $this->extractPrice([], $title),
                'currency' => 'USD',
                'source' => 'dealnews_rss',
                'fetched_at' => $publishedAt,
            ];
        }

        return collect($offers)
            ->take((int) config('deal_hunter.max_results_per_store', 10))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $retailer
     * @return null|array{store: string, title: string, url: string, image_url: ?string, price: ?float, currency: string, source: string}
     */
    private function mapWebResult(array $result, array $retailer, DealSearch $search): ?array
    {
        $url = data_get($result, 'url');
        if (! is_string($url) || ! $this->isProductUrl($url, $retailer)) {
            return null;
        }

        $host = Str::lower((string) parse_url($url, PHP_URL_HOST));
        $title = trim((string) data_get($result, 'title'));
        if ($title === '') {
            return null;
        }

        $snippet = trim((string) data_get($result, 'content'));
        if (! $this->matchesComponentType($title, $search->component_type)
            || ! $this->isRelevantText($title.' '.$snippet, $search->query)) {
            return null;
        }

        return [
            'store' => (string) ($retailer['name'] ?? $host),
            'title' => Str::limit(strip_tags($title), 1024, ''),
            'url' => $url,
            'image_url' => $this->resultImageUrl($result),
            // Search snippets are stale and frequently contain crossed-out,
            // financing, accessory, or unrelated prices. Keep the discovery,
            // but only trusted feeds/APIs may publish a numeric deal price.
            'price' => null,
            'currency' => 'USD',
            'source' => 'web_index',
        ];
    }

    private function isRelevantText(string $text, string $query): bool
    {
        $tokens = collect(preg_split('/[^a-z0-9]+/i', Str::lower($query)) ?: [])
            ->filter(fn (string $token): bool => strlen($token) >= 3 || in_array($token, ['rx', 'ti', 'xt'], true))
            ->unique()
            ->values();

        if ($tokens->isEmpty()) {
            return true;
        }

        $haystack = Str::lower(strip_tags($text));
        if (str_contains(Str::lower($query), 'desktop')
            && preg_match('/\b(?:laptop|notebook|so-?dimm)\b/i', $haystack)) {
            return false;
        }

        $identityTokens = $tokens->filter(fn (string $token): bool => (bool) preg_match('/^[0-9]{3,}$/', $token));
        if ($identityTokens->isNotEmpty()
            && ! $identityTokens->contains(fn (string $token): bool => str_contains($haystack, $token))) {
            return false;
        }

        $matches = $tokens->filter(fn (string $token): bool => str_contains($haystack, $token))->count();

        return $matches >= min(2, $tokens->count());
    }

    private function matchesComponentType(string $title, ComponentType|string $type): bool
    {
        $type = is_string($type) ? (ComponentType::tryFrom($type) ?? ComponentType::Other) : $type;

        return match ($type) {
            ComponentType::Cpu => (bool) preg_match('/\b(?:cpu|processor|ryzen|athlon|celeron|pentium|core\s+i[3579])\b/i', $title),
            ComponentType::Gpu => (bool) preg_match('/\b(?:gpu|graphics?\s+card|video\s+card|geforce|radeon|intel\s+arc)\b/i', $title),
            ComponentType::Ram => (bool) preg_match('/\b(?:ram|memory|ddr[345]|so-?dimm)\b/i', $title),
            ComponentType::Ssd => (bool) preg_match('/\b(?:ssd|solid[ -]state|nvme|m\.?2)\b/i', $title),
            ComponentType::Psu => (bool) preg_match('/\b(?:psu|power\s+suppl(?:y|ies))\b/i', $title),
            ComponentType::Other => true,
        };
    }

    private function detectRetailer(string $title, string $description): ?string
    {
        foreach ((array) config('deal_hunter.retailers', []) as $retailer) {
            $name = is_array($retailer) ? ($retailer['name'] ?? null) : null;
            if (! is_string($name) || $name === '') {
                continue;
            }

            $quotedName = preg_quote($name, '/');
            if (preg_match('/\b'.$quotedName.'\b/i', $title)
                || preg_match('/\b(?:buy|shop|available|sold)\b.{0,20}\b(?:at|from)\s+'.$quotedName.'\b/i', $description)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $retailer
     */
    private function isProductUrl(string $url, array $retailer): bool
    {
        if (! str_starts_with($url, 'https://')) {
            return false;
        }

        $host = Str::lower((string) parse_url($url, PHP_URL_HOST));
        $allowed = collect((array) ($retailer['domains'] ?? []))
            ->contains(fn (string $domain): bool => $host === $domain || str_ends_with($host, '.'.$domain));
        if (! $allowed) {
            return false;
        }

        $pathPattern = $retailer['product_path_pattern'] ?? null;

        return ! is_string($pathPattern)
            || (bool) preg_match($pathPattern, (string) parse_url($url, PHP_URL_PATH));
    }

    private function pruneInvalidOffers(DealSearch $search): void
    {
        $retailers = collect((array) config('deal_hunter.retailers', []))
            ->filter(fn (mixed $retailer): bool => is_array($retailer))
            ->keyBy(fn (array $retailer): string => (string) ($retailer['name'] ?? ''));

        $search->offers()->get()->each(function (DealOffer $offer) use ($retailers, $search): void {
            if ($offer->source === 'dealnews_rss') {
                if (! str_starts_with($offer->url, 'https://www.dealnews.com/')
                    || ! $this->matchesComponentType($offer->title, $search->component_type)
                    || ! $this->isRelevantText($offer->title, $search->query)) {
                    $offer->delete();
                }

                return;
            }

            $retailer = $retailers->get($offer->store);

            if (is_array($retailer) && (
                ! $this->isProductUrl($offer->url, $retailer)
                || ! $this->matchesComponentType($offer->title, $search->component_type)
                || ! $this->isRelevantText($offer->title, $search->query)
            )) {
                $offer->delete();
            }
        });
    }

    /**
     * @return array{
     *     succeeded: bool,
     *     offers: array<int, array{store: string, title: string, url: string, image_url: ?string, price: ?float, currency: string, source: string}>
     * }
     */
    private function searchBestBuy(DealSearch $search): array
    {
        $terms = collect(preg_split('/\s+/', $search->query) ?: [])
            ->filter()
            ->take(8)
            ->map(fn (string $term): string => 'search='.rawurlencode($term))
            ->implode('&');

        try {
            $response = Http::acceptJson()
                ->timeout(25)
                ->get('https://api.bestbuy.com/v1/products('.$terms.')', [
                    'apiKey' => config('deal_hunter.best_buy_api_key'),
                    'format' => 'json',
                    'show' => 'sku,name,salePrice,url,image,onlineAvailability',
                    'pageSize' => config('deal_hunter.max_results_per_store', 10),
                ])
                ->throw();
        } catch (Throwable $exception) {
            report($exception);

            return ['succeeded' => false, 'offers' => []];
        }

        return [
            'succeeded' => true,
            'offers' => collect((array) $response->json('products', []))
                ->filter(fn (array $product): bool => (bool) ($product['onlineAvailability'] ?? true))
                ->map(fn (array $product): array => [
                    'store' => 'Best Buy',
                    'title' => Str::limit((string) ($product['name'] ?? 'Best Buy product'), 1024, ''),
                    'url' => (string) ($product['url'] ?? ''),
                    'image_url' => $this->validImageUrl($product['image'] ?? null),
                    'price' => isset($product['salePrice']) ? (float) $product['salePrice'] : null,
                    'currency' => 'USD',
                    'source' => 'best_buy_api',
                    'availability' => DealOffer::AVAILABILITY_IN_STOCK,
                ])
                ->filter(fn (array $offer): bool => str_starts_with($offer['url'], 'https://'))
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<int, array{store: string, title: string, url: string, image_url: ?string, price: ?float, currency: string, source: string, fetched_at?: Carbon}>  $offers
     * @return array<int, array{store: string, title: string, url: string, image_url: ?string, price: ?float, currency: string, source: string, fetched_at?: Carbon}>
     */
    private function enrichImages(array $offers): array
    {
        $limit = max(0, (int) config('deal_hunter.image_lookup_limit', 6));
        $indexes = collect($offers)
            ->filter(fn (array $offer): bool => blank($offer['image_url'] ?? null))
            ->sortBy(fn (array $offer): array => [
                $offer['price'] === null ? 1 : 0,
                (float) ($offer['price'] ?? PHP_FLOAT_MAX),
            ])
            ->keys()
            ->take($limit);

        foreach ($indexes as $index) {
            $image = $this->images->find($offers[$index]['title']);
            if ($image !== null) {
                $offers[$index]['image_url'] = $image;
            }
        }

        return $offers;
    }

    /** @param array<string, mixed> $result */
    private function resultImageUrl(array $result): ?string
    {
        return $this->validImageUrl(data_get($result, 'img_src'))
            ?? $this->validImageUrl(data_get($result, 'thumbnail'));
    }

    private function extractImageUrl(string $html): ?string
    {
        if (! preg_match('/<img[^>]+src=["\'](?<url>https?:\/\/[^"\']+)["\']/i', $html, $matches)) {
            return null;
        }

        return $this->validImageUrl(html_entity_decode($matches['url'], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function validImageUrl(mixed $url): ?string
    {
        if (! is_string($url)
            || strlen($url) >= ScrapeUrl::MAX_STR_LENGTH
            || ! filter_var($url, FILTER_VALIDATE_URL)
            || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            return null;
        }

        return $url;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function extractPrice(array $result, string $text): ?float
    {
        $direct = data_get($result, 'price');
        if (is_numeric($direct)) {
            return $this->validPrice((float) $direct);
        }

        if (is_array($direct)) {
            $amount = data_get($direct, 'value', data_get($direct, 'amount'));
            if (is_numeric($amount)) {
                return $this->validPrice((float) $amount);
            }
        }

        $text = strip_tags($text);
        if (! preg_match_all(
            '/\$\s?([0-9]{1,4}(?:,[0-9]{3})*(?:\.[0-9]{2})?)/',
            $text,
            $matches,
            PREG_OFFSET_CAPTURE
        )) {
            return null;
        }

        foreach ($matches[1] as [$candidate, $offset]) {
            $before = substr($text, max(0, $offset - 28), min(28, $offset));
            $after = substr($text, $offset + strlen($candidate), 18);
            if (preg_match(
                '/(?:per\s+month|monthly|payment|financing|installment|as\s+low\s+as|\bmo\.?\b)/i',
                $before.' '.$after
            )) {
                continue;
            }

            $price = $this->validPrice((float) str_replace(',', '', $candidate));
            if ($price !== null) {
                return $price;
            }
        }

        return null;
    }

    private function validPrice(float $price): ?float
    {
        return $price >= 5 && $price <= 10_000 ? round($price, 2) : null;
    }

    public function notifyWhenTargetReached(?DealSearch $search): void
    {
        if (! $search || $search->target_price === null || ! $search->user) {
            return;
        }

        $best = $search->offers()
            ->where('fetched_at', '>=', now()->subDay())
            ->verifiedPrice()
            ->orderBy('price')
            ->first();

        if (! $best instanceof DealOffer || (float) $best->price > (float) $search->target_price) {
            return;
        }

        if ($search->last_notified_price !== null
            && (float) $best->price >= (float) $search->last_notified_price) {
            return;
        }

        $search->user->notify(new DealFoundNotification($best));
        $search->forceFill([
            'last_notified_price' => $best->price,
            'last_notified_at' => now(),
        ])->save();
    }
}
