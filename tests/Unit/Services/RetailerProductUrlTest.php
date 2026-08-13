<?php

namespace Tests\Unit\Services;

use App\Services\RetailerProductUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RetailerProductUrlTest extends TestCase
{
    #[DataProvider('retailerUrls')]
    public function test_it_returns_typed_stable_listing_identity(
        string $url,
        string $slug,
        string $store,
        string $identifierType,
        string $identifier,
        string $canonicalUrl,
        string $normalizedUrl,
    ): void {
        $listing = (new RetailerProductUrl)->identify($url);

        $this->assertNotNull($listing);
        $this->assertSame($slug, $listing['slug']);
        $this->assertSame($store, $listing['store']);
        $this->assertSame($identifier, $listing['identifier']);
        $this->assertSame($slug.':'.$identifier, $listing['product_key']);
        $this->assertSame($canonicalUrl, $listing['url']);
        $this->assertSame($identifierType, $listing['identifier_type']);
        $this->assertSame($identifier, $listing['external_identifier']);
        $this->assertSame($slug.':'.$identifierType.':'.$identifier, $listing['listing_key']);
        $this->assertSame(hash('sha256', $listing['listing_key']), $listing['listing_key_hash']);
        $this->assertSame($normalizedUrl, $listing['normalized_url']);
    }

    /** @return iterable<string, array{string, string, string, string, string, string, string}> */
    public static function retailerUrls(): iterable
    {
        yield 'localized Amazon ASIN' => [
            'https://www.amazon.com/-/es/Samsung-870-QVO/dp/b089c3tzl9/?tag=affiliate',
            'amazon', 'Amazon', 'asin', 'B089C3TZL9', 'https://www.amazon.com/dp/B089C3TZL9',
            'amazon.com/dp/b089c3tzl9',
        ];
        yield 'Newegg item number' => [
            'https://www.newegg.com/samsung-8tb-870-qvo-series-sata/p/n82e16820147784?Item=N82E16820147784',
            'newegg', 'Newegg', 'item_number', 'N82E16820147784', 'https://www.newegg.com/p/N82E16820147784',
            'newegg.com/p/n82e16820147784',
        ];
        yield 'Walmart item ID' => [
            'https://www.walmart.com/ip/Samsung-870-QVO-8TB/963319278?athbdg=L1100',
            'walmart', 'Walmart', 'item_id', '963319278', 'https://www.walmart.com/ip/963319278',
            'walmart.com/ip/963319278',
        ];
        yield 'Micro Center SKU' => [
            'https://www.microcenter.com/product/678910/samsung-870-qvo-8tb',
            'micro-center', 'Micro Center', 'sku', '678910', 'https://www.microcenter.com/product/678910',
            'microcenter.com/product/678910',
        ];
        yield 'Best Buy SKU' => [
            'https://www.bestbuy.com/site/samsung-990-pro-2tb/6523595.p?skuId=6523595',
            'best-buy', 'Best Buy', 'sku', '6523595', 'https://www.bestbuy.com/site/samsung-990-pro-2tb/6523595.p',
            'bestbuy.com/site/samsung-990-pro-2tb/6523595.p',
        ];
        yield 'GameStop SKU' => [
            'https://www.gamestop.com/pc-gaming/pc-components/storage/products/example/20012345.html?condition=New',
            'gamestop', 'GameStop', 'sku', '20012345', 'https://www.gamestop.com/pc-gaming/pc-components/storage/products/example/20012345.html',
            'gamestop.com/pc-gaming/pc-components/storage/products/example/20012345.html',
        ];
    }

    public function test_amazon_url_variants_share_the_same_listing_identity(): void
    {
        $service = new RetailerProductUrl;
        $standard = $service->identify('https://amazon.com/dp/B089C3TZL9');
        $mobile = $service->identify('https://www.amazon.com/gp/aw/d/B089C3TZL9?ref_=abc');

        $this->assertNotNull($standard);
        $this->assertNotNull($mobile);
        $this->assertSame($standard['listing_key'], $mobile['listing_key']);
        $this->assertSame($standard['listing_key_hash'], $mobile['listing_key_hash']);
        $this->assertSame($standard['normalized_url'], $mobile['normalized_url']);
    }

    #[DataProvider('unsupportedUrls')]
    public function test_it_fails_closed_for_unsupported_urls(string $url): void
    {
        $this->assertNull((new RetailerProductUrl)->identify($url));
    }

    /** @return iterable<string, array{string}> */
    public static function unsupportedUrls(): iterable
    {
        yield 'non HTTPS' => ['http://www.amazon.com/dp/B089C3TZL9'];
        yield 'lookalike domain' => ['https://amazon.com.attacker.example/dp/B089C3TZL9'];
        yield 'retailer search page' => ['https://www.newegg.com/p/pl?d=ssd'];
        yield 'unknown retailer' => ['https://example.com/product/12345'];
    }
}
