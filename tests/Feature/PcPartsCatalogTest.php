<?php

namespace Tests\Feature;

use App\Console\Commands\SyncPcPartsCatalog;
use App\Enums\ComponentType;
use App\Jobs\UpdateProductPricesJob;
use App\Models\PcBuild;
use App\Models\PcBuildItem;
use App\Models\PcPart;
use App\Models\Store;
use App\Models\User;
use App\Services\BuildCoresCatalogImporter;
use App\Services\CatalogTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

class PcPartsCatalogTest extends TestCase
{
    use RefreshDatabase;

    private ?string $archivePath = null;

    protected function tearDown(): void
    {
        if ($this->archivePath && is_file($this->archivePath)) {
            unlink($this->archivePath);
        }

        parent::tearDown();
    }

    public function test_it_imports_supported_open_catalog_parts_and_skips_hard_drives(): void
    {
        $this->archivePath = $this->makeCatalogArchive();

        $this->artisan(SyncPcPartsCatalog::COMMAND, ['--source' => $this->archivePath])
            ->assertSuccessful();

        $this->assertDatabaseCount('pc_parts', 5);
        $this->assertDatabaseMissing('pc_parts', ['name' => 'Example HDD']);

        $cpu = PcPart::query()->where('name', 'Example CPU')->firstOrFail();
        $this->assertSame(ComponentType::Cpu, $cpu->component_type);
        $this->assertSame('https://www.amazon.com/dp/B000TEST01', $cpu->retailer_urls['amazon']);
        $this->assertSame('https://www.walmart.com/ip/123456', $cpu->retailer_urls['walmart']);
        $this->assertSame('https://www.newegg.com/p/N82E16800000001', $cpu->retailer_urls['newegg']);
        $this->assertSame(BuildCoresCatalogImporter::SOURCE_URL, $cpu->source_url);
    }

    public function test_tracking_a_catalog_part_creates_one_product_and_retailer_urls(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $this->createUsStores();
        $part = PcPart::factory()->create([
            'component_type' => ComponentType::Gpu,
            'name' => 'Example GPU',
            'retailer_urls' => [
                'amazon' => 'https://www.amazon.com/dp/B000TEST02',
                'walmart' => 'https://www.walmart.com/ip/234567',
                'newegg' => 'https://www.newegg.com/p/N82E16800000002',
            ],
        ]);

        $tracking = resolve(CatalogTrackingService::class);
        $product = $tracking->track($part, $user->getKey());
        $tracking->track($part, $user->getKey());

        $this->assertSame('Example GPU', $product->title);
        $this->assertSame(ComponentType::Gpu, $product->component_type);
        $this->assertSame(3, $product->urls()->count());
        $this->assertDatabaseCount('products', 1);
        Queue::assertPushed(UpdateProductPricesJob::class, 1);
    }

    public function test_selecting_a_catalog_part_in_a_build_starts_tracking(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $this->createUsStores();
        $part = PcPart::factory()->create([
            'component_type' => ComponentType::Cpu,
            'retailer_urls' => ['amazon' => 'https://www.amazon.com/dp/B000TEST03'],
        ]);
        $build = PcBuild::query()->create([
            'user_id' => $user->getKey(),
            'name' => 'Budget build',
        ]);

        $item = PcBuildItem::query()->create([
            'pc_build_id' => $build->getKey(),
            'pc_part_id' => $part->getKey(),
            'quantity' => 1,
        ]);

        $this->assertNotNull($item->fresh()->product_id);
        $this->assertDatabaseHas('products', [
            'user_id' => $user->getKey(),
            'pc_part_id' => $part->getKey(),
        ]);
        $this->assertSame(1, $build->fresh()->missing_price_count);
        Queue::assertPushed(UpdateProductPricesJob::class, 1);
    }

    private function createUsStores(): void
    {
        foreach (['Amazon US', 'Walmart US', 'Newegg US'] as $name) {
            Store::factory()->create(['name' => $name]);
        }
    }

    private function makeCatalogArchive(): string
    {
        $path = tempnam(storage_path('framework/testing'), 'catalog-');
        $this->assertNotFalse($path);

        $archive = new ZipArchive;
        $this->assertTrue($archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));

        foreach ([
            'CPU' => $this->partData('Example CPU', [
                'amazon_sku' => 'B000TEST01',
                'walmart_sku' => 123456,
                'newegg_sku' => 'N82E16800000001',
            ]),
            'GPU' => $this->partData('Example GPU'),
            'RAM' => $this->partData('Example RAM'),
            'PSU' => $this->partData('Example PSU'),
            'Storage' => $this->partData('Example SSD', [], ['storage_type' => 'SSD']),
        ] as $category => $data) {
            $archive->addFromString(
                'buildcores-open-db-main/open-db/'.$category.'/'.Str::uuid().'.json',
                json_encode($data, JSON_THROW_ON_ERROR)
            );
        }

        $archive->addFromString(
            'buildcores-open-db-main/open-db/Storage/'.Str::uuid().'.json',
            json_encode($this->partData('Example HDD', [], ['storage_type' => 'HDD']), JSON_THROW_ON_ERROR)
        );
        $archive->close();

        return $path;
    }

    /**
     * @param  array<string, string|int>  $retailerData
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function partData(string $name, array $retailerData = [], array $extra = []): array
    {
        return array_merge([
            'opendb_id' => (string) Str::uuid(),
            'metadata' => [
                'name' => $name,
                'manufacturer' => 'Example',
                'series' => 'Budget',
                'variant' => null,
                'part_numbers' => ['EXAMPLE-1'],
                'releaseYear' => 2026,
            ],
            'general_product_information' => $retailerData,
        ], $extra);
    }
}
