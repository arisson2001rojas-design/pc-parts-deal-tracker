<?php

namespace App\Services;

use App\Models\DealOffer;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BrowserPriceCaptureService
{
    private const array PRODUCT_PATTERNS = [
        'amazon.com' => '~/(?:dp|gp/product)/([A-Z0-9]{10})(?:[/?]|$)~i',
        'walmart.com' => '~/ip/(?:[^/]+/)?([0-9]+)(?:[/?]|$)~i',
        'microcenter.com' => '~/product/([0-9]+)(?:[/?]|$)~i',
        'newegg.com' => '~/p/([A-Z0-9-]{8,})(?:[/?]|$)~i',
        'bestbuy.com' => '~/(?:site/[^/]+/([0-9]+)\.p|product/[^/]+/([A-Z0-9]+))(?:[/?]|$)~i',
        'gamestop.com' => '~/products/.+/([0-9]+)\.html(?:[/?]|$)~i',
    ];

    /** @var list<string> */
    private const array QUERY_STOP_WORDS = [
        'amd', 'intel', 'nvidia', 'desktop', 'processor', 'graphics', 'card',
        'memory', 'kit', 'solid', 'state', 'drive', 'power', 'supply', 'series',
    ];

    public function __construct(private readonly PriceCandidateSelector $candidateSelector) {}

    public function capture(
        DealOffer $offer,
        array $payload,
        string $verificationSource = 'browser_capture',
    ): DealOffer {
        if (! in_array($verificationSource, ['browser_capture', 'direct_extract'], true)) {
            throw new \InvalidArgumentException('Unsupported verification source.');
        }

        $offer->loadMissing('dealSearch');
        $exactProduct = $this->assertMatchingPage($offer->url, (string) $payload['page_url']);
        $title = trim((string) $payload['title']);

        if (! $exactProduct && ! $this->titleMatchesSearch($offer, $title)) {
            throw ValidationException::withMessages([
                'title' => 'The opened page does not match the component in this hunt.',
            ]);
        }

        $winner = $this->candidateSelector->select(
            (array) $payload['candidates'],
            $offer->dealSearch?->component_type,
        );
        $imageUrl = $payload['image_url'] ?? null;

        $offer->forceFill([
            'title' => Str::limit($title, 1024, ''),
            'image_url' => is_string($imageUrl) && $imageUrl !== '' ? $imageUrl : $offer->image_url,
            'price' => $winner['price'],
            'currency' => 'USD',
            'source' => $verificationSource,
            'fetched_at' => now(),
        ])->save();

        return $offer->fresh('dealSearch');
    }

    private function assertMatchingPage(string $offerUrl, string $pageUrl): bool
    {
        $offerHost = $this->host($offerUrl);
        $pageHost = $this->host($pageUrl);

        if ($offerHost === null || $pageHost === null || ! $this->sameRetailer($offerHost, $pageHost)) {
            throw ValidationException::withMessages([
                'page_url' => 'The opened page is not from the expected retailer.',
            ]);
        }

        $offerId = $this->productId($offerUrl, $offerHost);
        $pageId = $this->productId($pageUrl, $pageHost);

        if ($offerId !== null && $pageId !== null && ! hash_equals($offerId, $pageId)) {
            throw ValidationException::withMessages([
                'page_url' => 'The retailer redirected to a different product.',
            ]);
        }

        return $offerId !== null && $pageId !== null;
    }

    private function sameRetailer(string $firstHost, string $secondHost): bool
    {
        foreach ((array) config('deal_hunter.retailers', []) as $retailer) {
            foreach ((array) ($retailer['domains'] ?? []) as $domain) {
                if ($this->hostMatches($firstHost, $domain) && $this->hostMatches($secondHost, $domain)) {
                    return true;
                }
            }
        }

        return $firstHost === $secondHost;
    }

    private function productId(string $url, string $host): ?string
    {
        foreach (self::PRODUCT_PATTERNS as $domain => $pattern) {
            if (! $this->hostMatches($host, $domain) || preg_match($pattern, $url, $matches) !== 1) {
                continue;
            }

            $identifier = (string) $matches[1];
            if ($identifier === '') {
                $identifier = $matches[2] ?? '';
            }

            return $identifier !== '' ? strtoupper($identifier) : null;
        }

        return null;
    }

    private function titleMatchesSearch(DealOffer $offer, string $title): bool
    {
        $queryTokens = $this->tokens((string) $offer->dealSearch?->query);
        $titleTokens = $this->tokens($title);
        $modelTokens = array_values(array_filter(
            $queryTokens,
            fn (string $token): bool => preg_match('/\d/', $token) === 1,
        ));

        if ($modelTokens !== []) {
            return array_intersect($modelTokens, $titleTokens) !== [];
        }

        $important = array_values(array_diff($queryTokens, self::QUERY_STOP_WORDS));

        if ($important === []) {
            return false;
        }

        return count(array_intersect($important, $titleTokens)) >= min(2, count($important));
    }

    /** @return list<string> */
    private function tokens(string $value): array
    {
        $parts = preg_split('/[^a-z0-9]+/', strtolower(Str::ascii($value))) ?: [];

        return array_values(array_unique(array_filter(
            $parts,
            fn (string $part): bool => strlen($part) >= 2,
        )));
    }

    private function host(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? strtolower(rtrim($host, '.')) : null;
    }

    private function hostMatches(string $host, string $domain): bool
    {
        $domain = strtolower(ltrim($domain, '.'));

        return $host === $domain || str_ends_with($host, '.'.$domain);
    }
}
