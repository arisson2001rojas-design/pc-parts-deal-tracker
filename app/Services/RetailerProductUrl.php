<?php

namespace App\Services;

final class RetailerProductUrl
{
    private const array IDENTIFIER_PATTERNS = [
        'amazon' => '~/(?:dp|gp/product|gp/aw/d)/([A-Z0-9]{10})(?:[/?]|$)~i',
        'walmart' => '~/ip/(?:[^/]+/)?([0-9]+)(?:[/?]|$)~i',
        'micro-center' => '~/product/([0-9]+)(?:[/?]|$)~i',
        'newegg' => '~/p/([A-Z0-9-]{8,})(?:[/?]|$)~i',
        'best-buy' => '~/(?:site/[^/]+/([0-9]+)\.p|product/[^/]+/([A-Z0-9]+))(?:[/?]|$)~i',
        'gamestop' => '~/products/.+/([0-9]+)\.html(?:[/?]|$)~i',
    ];

    /** @return null|array{slug: string, store: string, identifier: string, product_key: string, url: string} */
    public function identify(string $url): ?array
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ! isset($parts['host'], $parts['path'])) {
            return null;
        }

        $host = strtolower(rtrim((string) $parts['host'], '.'));
        $path = (string) $parts['path'];
        foreach ((array) config('deal_hunter.retailers', []) as $slug => $retailer) {
            if (! is_array($retailer) || ! $this->matchesRetailer($host, $path, $retailer)) {
                continue;
            }

            $pattern = self::IDENTIFIER_PATTERNS[$slug] ?? null;
            if (! is_string($pattern) || preg_match($pattern, $path, $matches) !== 1) {
                return null;
            }

            $firstIdentifier = (string) $matches[1];
            $identifier = $firstIdentifier !== '' ? $firstIdentifier : (string) ($matches[2] ?? '');
            if ($identifier === '') {
                return null;
            }
            $identifier = strtoupper($identifier);

            return [
                'slug' => (string) $slug,
                'store' => (string) ($retailer['name'] ?? $slug),
                'identifier' => $identifier,
                'product_key' => $slug.':'.$identifier,
                'url' => $this->canonicalUrl((string) $slug, $identifier, $host, $path),
            ];
        }

        return null;
    }

    /** @param array<string, mixed> $retailer */
    private function matchesRetailer(string $host, string $path, array $retailer): bool
    {
        $allowedHost = collect((array) ($retailer['domains'] ?? []))
            ->contains(function (mixed $domain) use ($host): bool {
                $domain = strtolower(ltrim((string) $domain, '.'));

                return $host === $domain || str_ends_with($host, '.'.$domain);
            });
        $pattern = $retailer['product_path_pattern'] ?? null;

        return $allowedHost && is_string($pattern) && preg_match($pattern, $path) === 1;
    }

    private function canonicalUrl(string $slug, string $identifier, string $host, string $path): string
    {
        return match ($slug) {
            'amazon' => 'https://www.amazon.com/dp/'.$identifier,
            'walmart' => 'https://www.walmart.com/ip/'.$identifier,
            'micro-center' => 'https://www.microcenter.com/product/'.$identifier,
            'newegg' => 'https://www.newegg.com/p/'.$identifier,
            default => 'https://'.$host.rtrim($path, '/'),
        };
    }
}
