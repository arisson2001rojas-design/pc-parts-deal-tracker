<?php

namespace Tests\Feature;

use App\Enums\ComponentType;
use App\Models\PcBuild;
use App\Models\Product;
use App\Models\User;
use App\Notifications\PcBuildTargetNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PcBuildTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_calculates_the_cheapest_complete_build_total(): void
    {
        $user = User::factory()->create();
        $build = PcBuild::create([
            'name' => 'Budget build',
            'user_id' => $user->getKey(),
        ]);
        $cpu = Product::factory()->create([
            'component_type' => ComponentType::Cpu,
            'current_price' => 120.50,
            'user_id' => $user->getKey(),
        ]);
        $ram = Product::factory()->create([
            'component_type' => ComponentType::Ram,
            'current_price' => 45.25,
            'user_id' => $user->getKey(),
        ]);

        $build->items()->create(['product_id' => $cpu->getKey(), 'quantity' => 1]);
        $build->items()->create(['product_id' => $ram->getKey(), 'quantity' => 2]);

        $this->assertSame(211.0, $build->fresh()->current_total);
        $this->assertSame(3, $build->fresh()->component_count);
        $this->assertSame(0, $build->fresh()->missing_price_count);
    }

    public function test_it_reports_products_without_an_available_price(): void
    {
        $user = User::factory()->create();
        $build = PcBuild::create([
            'name' => 'Incomplete build',
            'user_id' => $user->getKey(),
        ]);
        $gpu = Product::factory()->create([
            'component_type' => ComponentType::Gpu,
            'current_price' => 0,
            'user_id' => $user->getKey(),
        ]);

        $build->items()->create(['product_id' => $gpu->getKey(), 'quantity' => 1]);

        $this->assertSame(0.0, $build->fresh()->current_total);
        $this->assertSame(1, $build->fresh()->missing_price_count);
    }

    public function test_target_alert_fires_once_per_threshold_crossing(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $build = PcBuild::create([
            'name' => 'Alert build',
            'target_total' => 500,
            'user_id' => $user->getKey(),
        ]);
        $gpu = Product::factory()->create([
            'component_type' => ComponentType::Gpu,
            'current_price' => 450,
            'user_id' => $user->getKey(),
        ]);
        $build->items()->create(['product_id' => $gpu->getKey(), 'quantity' => 1]);

        $build->fresh()->evaluateAlert();
        $build->fresh()->evaluateAlert();

        Notification::assertSentToTimes($user, PcBuildTargetNotification::class, 1);

        $gpu->update(['current_price' => 550]);
        $build->fresh()->evaluateAlert();
        $this->assertNull($build->fresh()->last_alerted_total);

        $gpu->update(['current_price' => 475]);
        $build->fresh()->evaluateAlert();

        Notification::assertSentToTimes($user, PcBuildTargetNotification::class, 2);
    }

    public function test_component_type_is_cast_to_enum(): void
    {
        $product = Product::factory()->create(['component_type' => ComponentType::Ssd]);

        $this->assertSame(ComponentType::Ssd, $product->fresh()->component_type);
    }
}
