<?php

namespace Tests\Feature\Livewire;

use App\Livewire\ProductCardDetail;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductCardDetailTest extends TestCase
{
    use RefreshDatabase;

    private function scheduledProduct(): Product
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // A future check with price history so both the live countdown and the
        // static label branches have data to render.
        $product = Product::factory()->create([
            'user_id' => $user->id,
            'refresh_interval' => 7200,
            'price_cache' => [['price' => 100.0, 'date' => now()->toDateString(), 'history' => []]],
        ]);

        $product->forceFill(['next_check_at' => now()->addMinutes(70)])->saveQuietly();

        return $product;
    }

    public function test_live_countdown_shown_by_default(): void
    {
        $product = $this->scheduledProduct();

        // `setInterval` only appears in the live-countdown Alpine component.
        Livewire::test(ProductCardDetail::class, ['product' => $product])
            ->assertSee('Next check')
            ->assertSee('setInterval');
    }

    public function test_static_next_check_shown_when_countdown_disabled(): void
    {
        $product = $this->scheduledProduct();

        Livewire::test(ProductCardDetail::class, ['product' => $product, 'showNextCheck' => false])
            ->assertSee('Next check')
            ->assertSee($product->nextCheckShortLabel())
            ->assertDontSee('setInterval');
    }

    public function test_product_list_summary_does_not_render_a_chart_for_flat_history(): void
    {
        $product = $this->scheduledProduct();
        $product->forceFill([
            'price_cache' => [[
                'price' => 100.0,
                'store_name' => 'Amazon US',
                'history' => [
                    now()->subDay()->toDateString() => 100.0,
                    now()->toDateString() => 100.0,
                ],
            ]],
        ])->saveQuietly();

        Livewire::test(ProductCardDetail::class, ['product' => $product->fresh(), 'showChart' => true])
            ->assertSee('Amazon US')
            ->assertDontSee('h-10 w-36', false);
    }

    public function test_product_list_summary_renders_a_compact_chart_for_price_changes(): void
    {
        $product = $this->scheduledProduct();
        $product->forceFill([
            'price_cache' => [[
                'price' => 100.0,
                'store_name' => 'Amazon US',
                'history' => [
                    now()->subDay()->toDateString() => 120.0,
                    now()->toDateString() => 100.0,
                ],
            ]],
        ])->saveQuietly();

        Livewire::test(ProductCardDetail::class, ['product' => $product->fresh(), 'showChart' => true])
            ->assertSee('h-10 w-36', false);
    }
}
