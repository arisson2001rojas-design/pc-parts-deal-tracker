<?php

namespace Tests\Feature;

use App\Enums\ComponentType;
use App\Models\DealOffer;
use App\Models\DealSearch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class BrowserPriceCaptureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:'.base64_encode(str_repeat('b', 32)));
    }

    public function test_a_signed_browser_capture_updates_a_matching_offer(): void
    {
        $offer = $this->offer();

        $this->withHeader('X-PriceBuddy-Companion', '1')
            ->postJson($this->signedUrl($offer), [
                'page_url' => 'https://www.newegg.com/p/N82E16819113941',
                'title' => 'AMD Ryzen 7 7700X3D Desktop Processor',
                'image_url' => 'https://c1.neweggimages.com/productimage.jpg',
                'candidates' => [
                    ['price' => 159.99, 'currency' => 'USD', 'source' => 'site_specific', 'confidence' => 0.98],
                    ['price' => 159.99, 'currency' => 'USD', 'source' => 'meta', 'confidence' => 0.88],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.price', 159.99)
            ->assertJsonPath('data.currency', 'USD');

        $offer->refresh();
        $this->assertSame('browser_capture', $offer->source);
        $this->assertSame('159.99', $offer->price);
        $this->assertTrue($offer->hasVerifiedPrice());
    }

    public function test_an_unsigned_capture_is_rejected(): void
    {
        $offer = $this->offer();

        $this->withHeader('X-PriceBuddy-Companion', '1')
            ->postJson(route('api.browser-capture', $offer), [])
            ->assertForbidden();
    }

    public function test_a_redirect_to_another_product_is_rejected(): void
    {
        $offer = $this->offer();

        $this->withHeader('X-PriceBuddy-Companion', '1')
            ->postJson($this->signedUrl($offer), [
                'page_url' => 'https://www.newegg.com/p/N82E16800000000',
                'title' => 'AMD Ryzen 7 7700X3D Desktop Processor',
                'candidates' => [
                    ['price' => 159.99, 'currency' => 'USD', 'source' => 'site_specific', 'confidence' => 0.98],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('page_url');

        $this->assertNull($offer->fresh()->price);
    }

    public function test_a_localized_or_conflicting_price_is_not_guessed(): void
    {
        $offer = $this->offer();

        $this->withHeader('X-PriceBuddy-Companion', '1')
            ->postJson($this->signedUrl($offer), [
                'page_url' => 'https://www.newegg.com/p/N82E16819113941',
                'title' => 'AMD Ryzen 7 7700X3D Desktop Processor',
                'candidates' => [
                    ['price' => 149361.46, 'currency' => 'CRC', 'source' => 'site_specific', 'confidence' => 0.98],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('candidates');

        $this->withHeader('X-PriceBuddy-Companion', '1')
            ->postJson($this->signedUrl($offer), [
                'page_url' => 'https://www.newegg.com/p/N82E16819113941',
                'title' => 'AMD Ryzen 7 7700X3D Desktop Processor',
                'candidates' => [
                    ['price' => 159.99, 'currency' => 'USD', 'source' => 'site_specific', 'confidence' => 0.98],
                    ['price' => 329, 'currency' => 'USD', 'source' => 'json_ld', 'confidence' => 0.90],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('candidates');

        $this->assertNull($offer->fresh()->price);
    }

    private function offer(): DealOffer
    {
        $search = DealSearch::query()->create([
            'user_id' => User::factory()->create()->getKey(),
            'name' => 'Ryzen 7 7700X3D',
            'query' => 'AMD Ryzen 7 7700X3D',
            'component_type' => ComponentType::Cpu,
            'enabled' => true,
        ]);

        return $search->offers()->create([
            'store' => 'Newegg',
            'title' => 'AMD Ryzen 7 7700X3D',
            'url' => 'https://www.newegg.com/p/N82E16819113941',
            'url_hash' => hash('sha256', 'https://www.newegg.com/p/N82E16819113941'),
            'price' => null,
            'currency' => 'USD',
            'source' => 'web_index',
            'fetched_at' => now(),
        ]);
    }

    private function signedUrl(DealOffer $offer): string
    {
        return URL::temporarySignedRoute(
            'api.browser-capture',
            now()->addMinutes(5),
            ['offer' => $offer->getKey()],
        );
    }
}
