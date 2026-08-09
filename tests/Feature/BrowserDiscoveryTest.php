<?php

namespace Tests\Feature;

use App\Models\DealOffer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrowserDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_browser_radar_adds_an_unknown_component_and_verified_price(): void
    {
        User::factory()->create();

        $this->withHeader('X-PriceBuddy-Companion', '1')
            ->postJson(route('api.browser-discoveries'), $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.component_type', 'cpu')
            ->assertJsonPath('data.price', 129.99)
            ->assertJsonPath('data.stored', true);

        $this->assertDatabaseHas('deal_searches', [
            'query' => 'browser-radar:cpu',
            'component_type' => 'cpu',
            'enabled' => false,
        ]);
        $this->assertDatabaseHas('deal_offers', [
            'store' => 'Newegg',
            'url' => 'https://www.newegg.com/p/9SIC3U3KN44182',
            'price' => 129.99,
            'source' => 'browser_discovery',
            'availability' => DealOffer::AVAILABILITY_IN_STOCK,
            'seller' => 'SenyTech Global',
        ]);
        $this->assertDatabaseHas('pc_parts', [
            'component_type' => 'cpu',
            'name' => 'AMD Ryzen 5 5600 Desktop Processor',
            'manufacturer' => 'AMD',
            'source_url' => 'https://www.newegg.com/p/9SIC3U3KN44182',
        ]);
        $this->assertDatabaseCount('deal_offer_prices', 1);
    }

    public function test_browser_radar_deduplicates_products_and_appends_changed_prices(): void
    {
        User::factory()->create();

        $this->withHeader('X-PriceBuddy-Companion', '1')
            ->postJson(route('api.browser-discoveries'), $this->payload())
            ->assertCreated();

        $payload = $this->payload();
        $payload['page_url'] .= '?utm_source=tracking';
        $payload['candidates'] = [[
            'price' => 119.99,
            'currency' => 'USD',
            'source' => 'site_specific',
            'confidence' => 0.96,
        ]];
        $this->withHeader('X-PriceBuddy-Companion', '1')
            ->postJson(route('api.browser-discoveries'), $payload)
            ->assertCreated()
            ->assertJsonPath('data.price', 119.99);

        $this->assertDatabaseCount('deal_searches', 1);
        $this->assertDatabaseCount('deal_offers', 1);
        $this->assertDatabaseCount('pc_parts', 1);
        $this->assertDatabaseCount('deal_offer_prices', 2);
    }

    public function test_browser_radar_ignores_non_component_products(): void
    {
        User::factory()->create();
        $payload = $this->payload();
        $payload['title'] = 'Gaming PC Desktop Computer with Ryzen and Radeon Graphics';

        $this->withHeader('X-PriceBuddy-Companion', '1')
            ->postJson(route('api.browser-discoveries'), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('title');

        $this->assertDatabaseCount('deal_offers', 0);
        $this->assertDatabaseCount('pc_parts', 0);
    }

    public function test_browser_radar_rejects_search_pages_and_requests_without_extension_header(): void
    {
        User::factory()->create();
        $payload = $this->payload();
        $payload['page_url'] = 'https://www.newegg.com/p/pl?d=ryzen+5600';

        $this->withHeader('X-PriceBuddy-Companion', '1')
            ->postJson(route('api.browser-discoveries'), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('page_url');

        $this->withoutHeader('X-PriceBuddy-Companion')
            ->postJson(route('api.browser-discoveries'), $this->payload())
            ->assertForbidden();
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'page_url' => 'https://www.newegg.com/p/9SIC3U3KN44182',
            'title' => 'AMD Ryzen 5 5600 Desktop Processor',
            'image_url' => 'https://c1.neweggimages.com/ProductImage.jpg',
            'availability' => 'in_stock',
            'seller' => 'SenyTech Global',
            'manufacturer' => 'AMD',
            'part_number' => '100-000000927',
            'candidates' => [
                [
                    'price' => 129.99,
                    'currency' => 'USD',
                    'source' => 'site_specific',
                    'confidence' => 0.96,
                ],
                [
                    'price' => 129.99,
                    'currency' => 'USD',
                    'source' => 'json_ld',
                    'confidence' => 0.90,
                ],
            ],
        ];
    }
}
