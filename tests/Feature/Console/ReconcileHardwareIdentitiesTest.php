<?php

namespace Tests\Feature\Console;

use App\Console\Commands\ReconcileHardwareIdentities;
use App\Enums\ComponentType;
use App\Models\PcBuild;
use App\Models\PcPart;
use App\Models\Price;
use App\Models\Product;
use App\Models\Store;
use App\Models\Url;
use App\Services\HardwareEvidenceNormalizer;
use App\Services\HardwareIdentityIngestionService;
use App\Services\RetailerProductUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReconcileHardwareIdentitiesTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_is_a_repeatable_non_destructive_dry_run(): void
    {
        $store = Store::factory()->create(['slug' => 'amazon-us', 'name' => 'Amazon US']);
        $part = PcPart::factory()->create([
            'component_type' => ComponentType::Ssd,
            'manufacturer' => 'Samsung',
            'name' => 'Samsung 870 QVO 8TB SSD',
            'part_numbers' => ['MZ-77Q8T0B/AM'],
            'specifications' => ['capacity' => '8TB'],
        ]);
        $product = Product::factory()->create([
            'pc_part_id' => $part->getKey(),
            'component_type' => ComponentType::Ssd,
            'paused' => true,
            'paused_by_user' => true,
            'refresh_interval' => 12345,
        ]);
        $url = Url::factory()->for($product)->for($store)->create(['url' => 'https://www.amazon.com/dp/B089C3TZL9']);
        Price::factory()->for($url)->for($store)->create(['price' => 1479.99]);
        $identityResult = app(HardwareIdentityIngestionService::class)->ingest(
            app(RetailerProductUrl::class)->identify($url->url),
            app(HardwareEvidenceNormalizer::class)->fromPcPart($part),
            part: $part,
            url: $url,
        );
        $build = PcBuild::query()->create([
            'user_id' => $product->user_id,
            'name' => 'Identity audit build',
            'target_total' => 2000,
        ]);
        DB::table('pc_build_items')->insert([
            'pc_build_id' => $build->getKey(),
            'product_id' => $product->getKey(),
            'pc_part_id' => $part->getKey(),
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $before = [
            'paused' => $product->paused,
            'paused_by_user' => $product->paused_by_user,
            'refresh_interval' => $product->refresh_interval,
            'next_check_at' => $product->next_check_at?->toISOString(),
        ];
        $databaseBefore = $this->identityDatabaseSnapshot();
        $this->assertSame(0, Artisan::call(ReconcileHardwareIdentities::COMMAND, ['--product' => [$product->getKey()]]));
        $firstOutput = Artisan::output();
        $this->assertStringContainsString('wrote 0 records', $firstOutput);
        $this->assertSame(0, Artisan::call(ReconcileHardwareIdentities::COMMAND, [
            '--dry-run' => true,
            '--product' => [$product->getKey()],
        ]));
        $secondOutput = Artisan::output();

        $this->assertSame($firstOutput, $secondOutput);
        $this->assertSame($databaseBefore, $this->identityDatabaseSnapshot());
        $this->assertDatabaseCount('hardware_identities', 1);
        $this->assertDatabaseCount('hardware_identity_claims', 1);
        $this->assertDatabaseCount('retailer_listings', 1);
        $product->refresh();
        $this->assertSame($before, [
            'paused' => $product->paused,
            'paused_by_user' => $product->paused_by_user,
            'refresh_interval' => $product->refresh_interval,
            'next_check_at' => $product->next_check_at?->toISOString(),
        ]);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('urls', 1);
        $this->assertDatabaseCount('prices', 1);
        $this->assertDatabaseCount('pc_build_items', 1);
        $this->assertSame($identityResult->identity?->getKey(), $part->fresh()->hardware_identity_id);
        $this->assertSame($identityResult->listing->getKey(), $url->fresh()->retailer_listing_id);
    }

    /** @return array<string, mixed> */
    private function identityDatabaseSnapshot(): array
    {
        return collect([
            'hardware_identities',
            'hardware_identity_claims',
            'retailer_listings',
            'pc_parts',
            'products',
            'urls',
            'prices',
            'pc_build_items',
        ])->mapWithKeys(fn (string $table): array => [
            $table => DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
        ])->all();
    }
}
