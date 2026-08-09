<?php

namespace App\Services;

use App\Models\DealOffer;
use Illuminate\Support\Facades\Http;
use Throwable;

class RetailPriceExtractorClient
{
    /**
     * @return array{page_url: string, title: string, image_url: ?string, candidates: array<int, array<string, mixed>>}|null
     */
    public function extract(DealOffer $offer): ?array
    {
        return $this->extractUrl($offer->url);
    }

    /**
     * @return array{page_url: string, title: string, image_url: ?string, candidates: array<int, array<string, mixed>>}|null
     */
    public function extractUrl(string $url): ?array
    {
        $baseUrl = rtrim((string) config('deal_hunter.price_extractor_url'), '/');
        if ($baseUrl === '' || ! $this->supports($url) || ! ScrapeUrl::allowsAutomatedAccess($url)) {
            return null;
        }

        try {
            $response = Http::acceptJson()
                ->connectTimeout(5)
                ->timeout(35)
                ->post($baseUrl.'/extract', ['url' => $url]);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $data = $response->json('data');
        if (! is_array($data)
            || ! is_string($data['page_url'] ?? null)
            || ! is_string($data['title'] ?? null)
            || trim($data['title']) === ''
            || ! is_array($data['candidates'] ?? null)
            || $data['candidates'] === []) {
            return null;
        }

        if (! $this->sameRetailer($url, $data['page_url'])) {
            return null;
        }

        return [
            'page_url' => $data['page_url'],
            'title' => trim($data['title']),
            'image_url' => is_string($data['image_url'] ?? null) && $this->isHttpUrl($data['image_url'])
                ? $data['image_url']
                : null,
            'candidates' => array_values($data['candidates']),
        ];
    }

    private function supports(string $url): bool
    {
        $host = $this->host($url);
        if ($host === null) {
            return false;
        }

        foreach ((array) config('deal_hunter.retailers', []) as $retailer) {
            foreach ((array) ($retailer['domains'] ?? []) as $domain) {
                if ($this->hostMatches($host, (string) $domain)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function sameRetailer(string $firstUrl, string $secondUrl): bool
    {
        $firstHost = $this->host($firstUrl);
        $secondHost = $this->host($secondUrl);
        if ($firstHost === null || $secondHost === null) {
            return false;
        }

        foreach ((array) config('deal_hunter.retailers', []) as $retailer) {
            foreach ((array) ($retailer['domains'] ?? []) as $domain) {
                if ($this->hostMatches($firstHost, (string) $domain)
                    && $this->hostMatches($secondHost, (string) $domain)) {
                    return true;
                }
            }
        }

        return false;
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

    private function isHttpUrl(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}
