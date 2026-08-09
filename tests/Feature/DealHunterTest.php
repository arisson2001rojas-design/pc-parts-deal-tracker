<?php

namespace Tests\Feature;

use App\Enums\ComponentType;
use App\Filament\Resources\DealOfferResource;
use App\Filament\Resources\DealSearchResource;
use App\Models\DealOffer;
use App\Models\DealSearch;
use App\Models\User;
use App\Notifications\DealFoundNotification;
use App\Services\DealHunterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class DealHunterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        config()->set('deal_hunter.dealnews_feed_url');
        Cache::forget('deal-hunter:dealnews-components-feed');
    }

    public function test_it_collects_indexed_store_prices_and_notifies_below_target(): void
    {
        config()->set('deal_hunter.retailers', [
            'amazon' => [
                'name' => 'Amazon',
                'domains' => ['amazon.com'],
                'product_path_pattern' => '~/(?:dp|gp/product)/[A-Z0-9]{10}(?:[/?]|$)~i',
            ],
        ]);
        config()->set('deal_hunter.search_url', 'http://searxng.test/search');
        Notification::fake();
        Http::fake([
            'searxng.test/*' => Http::response([
                'results' => [[
                    'title' => 'AMD Ryzen 5 5600 - $89.99',
                    'url' => 'https://www.amazon.com/dp/B000DEAL01',
                    'content' => 'In stock today for $89.99',
                ], [
                    'title' => 'AMD Ryzen results from $28.40/mo',
                    'url' => 'https://www.amazon.com/s?k=amd+ryzen',
                    'content' => 'Financing payment $28.40/mo',
                ], [
                    'title' => 'Sony PlayStation 5 console $79.00',
                    'url' => 'https://www.amazon.com/dp/B000DEAL99',
                    'content' => 'Unrelated indexed product',
                ]],
            ]),
        ]);

        $user = User::factory()->create();
        $search = DealSearch::query()->create([
            'user_id' => $user->getKey(),
            'name' => 'Budget CPU',
            'query' => 'AMD Ryzen 5 5600',
            'component_type' => ComponentType::Cpu,
            'target_price' => 100,
            'enabled' => true,
        ]);

        $count = resolve(DealHunterService::class)->refresh($search);

        $this->assertSame(1, $count);
        $offer = DealOffer::query()->firstOrFail();
        $this->assertSame('89.99', $offer->price);
        $this->assertNotNull($search->fresh()->last_searched_at);
        Notification::assertSentTo($user, DealFoundNotification::class);
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'http://searxng.test/search?'));
    }

    public function test_it_does_not_repeat_an_alert_at_the_same_price(): void
    {
        config()->set('deal_hunter.retailers', [
            'amazon' => [
                'name' => 'Amazon',
                'domains' => ['amazon.com'],
                'product_path_pattern' => '~/(?:dp|gp/product)/[A-Z0-9]{10}(?:[/?]|$)~i',
            ],
        ]);
        config()->set('deal_hunter.search_url', 'http://searxng.test/search');
        Notification::fake();
        Http::fake([
            '*' => Http::response([
                'results' => [[
                    'title' => '1TB NVMe SSD $49.99',
                    'url' => 'https://amazon.com/dp/B000DEAL02',
                    'content' => '',
                ]],
            ]),
        ]);

        $user = User::factory()->create();
        $search = DealSearch::query()->create([
            'user_id' => $user->getKey(),
            'name' => 'SSD',
            'query' => '1TB NVMe SSD',
            'component_type' => ComponentType::Ssd,
            'target_price' => 60,
            'enabled' => true,
        ]);

        $hunter = resolve(DealHunterService::class);
        $hunter->refresh($search);
        $hunter->refresh($search->fresh());

        Notification::assertSentToTimes($user, DealFoundNotification::class, 1);
    }

    public function test_it_prunes_non_product_offers_after_filters_tighten(): void
    {
        config()->set('deal_hunter.retailers', [
            'newegg' => [
                'name' => 'Newegg',
                'domains' => ['newegg.com'],
                'product_path_pattern' => '~/p/[A-Z0-9]+(?:[/?]|$)~i',
            ],
        ]);
        config()->set('deal_hunter.search_url', 'http://searxng.test/search');
        Http::fake([
            '*' => Http::sequence()
                ->push([
                    'results' => [[
                        'title' => '1TB NVMe SSD search results starting at $74.99',
                        'url' => 'https://www.newegg.com/p/pl?d=1tb+nvme',
                        'content' => '',
                    ]],
                ])
                ->push([
                    'results' => [[
                        'title' => 'Search results starting at $19.99',
                        'url' => 'https://www.newegg.com/p/pl?d=1tb+nvme',
                        'content' => '',
                    ]],
                ]),
        ]);

        $search = DealSearch::query()->create([
            'user_id' => User::factory()->create()->getKey(),
            'name' => 'SSD',
            'query' => '1TB NVMe SSD',
            'component_type' => ComponentType::Ssd,
            'enabled' => true,
        ]);
        $hunter = resolve(DealHunterService::class);
        $hunter->refresh($search);
        $this->assertDatabaseCount('deal_offers', 1);

        config()->set('deal_hunter.retailers.newegg.product_path_pattern', '~/p/[A-Z0-9]{8,}(?:[/?]|$)~i');

        $this->assertSame(0, $hunter->refresh($search->fresh()));
        $this->assertDatabaseCount('deal_offers', 0);
    }

    public function test_it_preserves_previous_offers_when_a_successful_search_is_empty(): void
    {
        config()->set('deal_hunter.retailers', [
            'amazon' => [
                'name' => 'Amazon',
                'domains' => ['amazon.com'],
                'product_path_pattern' => '~/(?:dp|gp/product)/[A-Z0-9]{10}(?:[/?]|$)~i',
            ],
        ]);
        config()->set('deal_hunter.search_url', 'http://searxng.test/search');
        Http::fake([
            '*' => Http::sequence()
                ->push([
                    'results' => [[
                        'title' => 'AMD Ryzen 5 5600 $89.99',
                        'url' => 'https://amazon.com/dp/B000DEAL04',
                        'content' => '',
                    ]],
                ])
                ->push(['results' => []]),
        ]);

        $search = DealSearch::query()->create([
            'user_id' => User::factory()->create()->getKey(),
            'name' => 'CPU',
            'query' => 'AMD Ryzen 5 5600',
            'component_type' => ComponentType::Cpu,
            'enabled' => true,
        ]);
        $hunter = resolve(DealHunterService::class);
        $hunter->refresh($search);

        $this->assertSame(0, $hunter->refresh($search->fresh()));
        $this->assertDatabaseCount('deal_offers', 1);
    }

    public function test_it_preserves_previous_offers_when_a_store_request_fails(): void
    {
        config()->set('deal_hunter.retailers', [
            'amazon' => [
                'name' => 'Amazon',
                'domains' => ['amazon.com'],
                'product_path_pattern' => '~/(?:dp|gp/product)/[A-Z0-9]{10}(?:[/?]|$)~i',
            ],
        ]);
        config()->set('deal_hunter.search_url', 'http://searxng.test/search');
        Http::fake([
            '*' => Http::sequence()
                ->push([
                    'results' => [[
                        'title' => 'AMD Ryzen 5 5600 $89.99',
                        'url' => 'https://amazon.com/dp/B000DEAL03',
                        'content' => '',
                    ]],
                ])
                ->push([], 503),
        ]);

        $search = DealSearch::query()->create([
            'user_id' => User::factory()->create()->getKey(),
            'name' => 'CPU',
            'query' => 'AMD Ryzen 5 5600',
            'component_type' => ComponentType::Cpu,
            'enabled' => true,
        ]);
        $hunter = resolve(DealHunterService::class);
        $hunter->refresh($search);

        $this->assertSame(0, $hunter->refresh($search->fresh()));
        $this->assertDatabaseCount('deal_offers', 1);
    }

    public function test_it_collects_relevant_curated_deals_from_the_official_rss_feed(): void
    {
        config()->set('deal_hunter.retailers', [
            'newegg' => [
                'name' => 'Newegg',
                'domains' => ['newegg.com'],
                'product_path_pattern' => '~/p/[A-Z0-9]{8,}(?:[/?]|$)~i',
            ],
        ]);
        config()->set('deal_hunter.search_url', 'http://searxng.test/search');
        config()->set('deal_hunter.dealnews_feed_url', 'https://dealnews.test/rss');
        $publishedAt = now()->toRfc2822String();
        $feed = <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0"><channel><item>
                <title>AMD Ryzen 7 7800X3D Desktop Processor for $339</title>
                <link>https://www.dealnews.com/AMD-Ryzen-7-7800-X3-D-for-339/123.html</link>
                <pubDate>{$publishedAt}</pubDate>
                <description><![CDATA[Buy Now at Newegg. Desktop CPU deal.]]></description>
            </item><item>
                <title>Open-Box GAMEMAX Micro-ATX Computer Case for $49</title>
                <link>https://www.dealnews.com/GAMEMAX-Case-for-49/124.html</link>
                <pubDate>{$publishedAt}</pubDate>
                <description><![CDATA[Buy Now at Newegg. Fits a desktop CPU and ATX power supply.]]></description>
            </item></channel></rss>
            XML;
        Http::fake([
            'searxng.test/*' => Http::response(['results' => []]),
            'dealnews.test/*' => Http::response($feed, 200, ['Content-Type' => 'application/rss+xml']),
        ]);

        $search = DealSearch::query()->create([
            'user_id' => User::factory()->create()->getKey(),
            'name' => 'Latest CPU deals',
            'query' => 'AMD Intel desktop processor CPU',
            'component_type' => ComponentType::Cpu,
            'enabled' => true,
        ]);

        $this->assertSame(1, resolve(DealHunterService::class)->refresh($search));
        $this->assertDatabaseHas('deal_offers', [
            'store' => 'Newegg',
            'price' => 339,
            'source' => 'dealnews_rss',
        ]);
        Http::assertSentCount(2);
    }

    public function test_deal_hunter_pages_render_for_an_authenticated_user(): void
    {
        $this->withoutVite();
        $this->actingAs(User::factory()->create());

        $this->get(DealOfferResource::getUrl('index'))->assertOk();
        $this->get(DealSearchResource::getUrl('index'))->assertOk();
    }
}
