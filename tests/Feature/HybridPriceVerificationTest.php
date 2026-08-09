<?php

namespace Tests\Feature;

use App\Enums\ComponentType;
use App\Jobs\VerifyDealOfferJob;
use App\Models\DealOffer;
use App\Models\DealSearch;
use App\Models\Store;
use App\Models\User;
use App\Services\BrowserPriceCaptureService;
use App\Services\DealHunterService;
use App\Services\RetailPriceExtractorClient;
use App\Services\ScrapeUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class HybridPriceVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:'.base64_encode(str_repeat('h', 32)));
        config()->set('deal_hunter.dealnews_feed_url');
        config()->set('deal_hunter.image_lookup_limit', 0);
        config()->set('deal_hunter.price_extractor_url', 'http://price-extractor.test');
        config()->set('deal_hunter.retailers', [
            'newegg' => [
                'name' => 'Newegg',
                'domains' => ['newegg.com'],
                'product_path_pattern' => '~/p/[A-Z0-9]{8,}(?:[/?]|$)~i',
            ],
        ]);
    }

    public function test_a_discovered_offer_is_queued_for_automatic_verification(): void
    {
        Queue::fake();
        config()->set('deal_hunter.search_url', 'http://searxng.test/search');
        Http::fake([
            'searxng.test/*' => Http::response([
                'results' => [[
                    'title' => 'AMD Ryzen 7 7700X3D Desktop Processor',
                    'url' => 'https://www.newegg.com/p/N82E16819113941',
                    'content' => 'AMD desktop CPU',
                ]],
            ]),
        ]);

        $search = $this->search();
        resolve(DealHunterService::class)->refresh($search);
        $offer = DealOffer::query()->firstOrFail();

        Queue::assertPushed(
            VerifyDealOfferJob::class,
            fn (VerifyDealOfferJob $job): bool => $job->dealOfferId === $offer->getKey(),
        );
    }

    public function test_the_automatic_extractor_updates_a_matching_offer(): void
    {
        $offer = $this->offer();
        Http::fake([
            'price-extractor.test/extract' => Http::response([
                'data' => [
                    'page_url' => $offer->url,
                    'title' => 'AMD Ryzen 7 7700X3D Desktop Processor',
                    'image_url' => 'https://c1.neweggimages.com/productimage.jpg',
                    'candidates' => [
                        ['price' => 159.99, 'currency' => 'USD', 'source' => 'site_specific', 'confidence' => 0.96],
                    ],
                ],
            ]),
        ]);

        (new VerifyDealOfferJob($offer->getKey()))->handle(
            resolve(RetailPriceExtractorClient::class),
            resolve(BrowserPriceCaptureService::class),
            resolve(DealHunterService::class),
        );

        $offer->refresh();
        $this->assertSame('159.99', $offer->price);
        $this->assertSame('USD', $offer->currency);
        $this->assertSame('direct_extract', $offer->source);
        $this->assertSame('https://c1.neweggimages.com/productimage.jpg', $offer->image_url);
    }

    public function test_tracked_product_urls_use_the_lightweight_extractor_first(): void
    {
        $store = Store::factory()->create([
            'domains' => [['domain' => 'www.newegg.com']],
            'settings' => [
                'scraper_service' => 'http',
                'scraper_service_settings' => '',
                'locale_settings' => ['locale' => 'en_US', 'currency' => 'USD'],
            ],
        ]);
        Http::fake([
            'price-extractor.test/*' => Http::response([
                'data' => [
                    'page_url' => 'https://www.newegg.com/p/N82E16819113941',
                    'title' => 'AMD Ryzen 7 7700X3D Desktop Processor',
                    'image_url' => 'https://c1.neweggimages.com/productimage.jpg',
                    'candidates' => [
                        ['price' => 159.99, 'currency' => 'USD', 'source' => 'site_specific', 'confidence' => 0.96],
                    ],
                ],
            ]),
        ]);

        $url = 'https://www.newegg.com/p/N82E16819113941';
        $this->assertNotNull(resolve(RetailPriceExtractorClient::class)->extractUrl($url));

        $scraper = Mockery::mock(ScrapeUrl::class, [$url])->makePartial();
        $scraper->shouldReceive('getStore')->andReturn($store);
        $result = $scraper->scrape();

        $this->assertSame('AMD Ryzen 7 7700X3D Desktop Processor', $result['title']);
        $this->assertSame(159.99, $result['price']);
        $this->assertSame('https://c1.neweggimages.com/productimage.jpg', $result['image']);
    }

    public function test_a_new_discovery_does_not_erase_a_recent_browser_price(): void
    {
        Queue::fake();
        config()->set('deal_hunter.search_url', 'http://searxng.test/search');
        Http::fake([
            'searxng.test/*' => Http::response([
                'results' => [[
                    'title' => 'AMD Ryzen 7 7700X3D Desktop Processor',
                    'url' => 'https://www.newegg.com/p/N82E16819113941',
                    'content' => 'AMD desktop CPU',
                ]],
            ]),
        ]);
        $offer = $this->offer();
        $offer->forceFill([
            'price' => 159.99,
            'source' => 'browser_capture',
            'fetched_at' => now(),
        ])->save();

        resolve(DealHunterService::class)->refresh($offer->dealSearch);

        $offer->refresh();
        $this->assertSame('159.99', $offer->price);
        $this->assertSame('browser_capture', $offer->source);
        Queue::assertNotPushed(VerifyDealOfferJob::class);
    }

    private function search(): DealSearch
    {
        return DealSearch::query()->create([
            'user_id' => User::factory()->create()->getKey(),
            'name' => 'Ryzen 7 7700X3D',
            'query' => 'AMD Ryzen 7 7700X3D',
            'component_type' => ComponentType::Cpu,
            'enabled' => true,
        ]);
    }

    private function offer(): DealOffer
    {
        $search = $this->search();
        $url = 'https://www.newegg.com/p/N82E16819113941';

        return $search->offers()->create([
            'store' => 'Newegg',
            'title' => 'AMD Ryzen 7 7700X3D',
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'price' => null,
            'currency' => 'USD',
            'source' => 'web_index',
            'fetched_at' => now(),
        ]);
    }
}
