<?php

namespace Tests\Feature;

use App\Enums\ComponentType;
use App\Enums\IdentityResolutionState;
use App\Models\HardwareIdentity;
use App\Models\PcBuild;
use App\Models\PcPart;
use App\Models\Price;
use App\Models\Product;
use App\Models\Store;
use App\Models\Url;
use App\Models\User;
use App\Services\HardwareEvidenceNormalizer;
use App\Services\HardwareIdentityIngestionService;
use App\Services\RetailerProductUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HardwareIdentityIngestionTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_exact_hardware_across_retailers_resolves_to_one_identity_idempotently(): void
    {
        $service = app(HardwareIdentityIngestionService::class);
        $normalizer = app(HardwareEvidenceNormalizer::class);
        $parser = app(RetailerProductUrl::class);
        $evidence = $normalizer->fromArray([
            'component_type' => 'ssd',
            'manufacturer' => 'Samsung',
            'model' => '870 QVO',
            'mpn' => 'MZ-77Q8T0B/AM',
            'capacity' => '8TB',
            'storage_type' => 'SSD',
            'interface' => 'SATA III',
            'form_factor' => '2.5 inch',
        ]);

        $amazon = $service->ingest($parser->identify('https://www.amazon.com/dp/B089C3TZL9'), $evidence);
        $newegg = $service->ingest($parser->identify('https://www.newegg.com/p/N82E16820147784'), $evidence);
        $again = $service->ingest($parser->identify('https://www.amazon.com/dp/B089C3TZL9?tag=ignored'), $evidence);

        $this->assertSame(IdentityResolutionState::Verified, $amazon->resolution->state);
        $this->assertSame($amazon->identity?->getKey(), $newegg->identity?->getKey());
        $this->assertSame($amazon->listing->getKey(), $again->listing->getKey());
        $this->assertDatabaseCount('hardware_identities', 1);
        $this->assertDatabaseCount('retailer_listings', 2);
    }

    public function test_strong_capacity_conflict_is_recorded_without_association(): void
    {
        $service = app(HardwareIdentityIngestionService::class);
        $normalizer = app(HardwareEvidenceNormalizer::class);
        $parser = app(RetailerProductUrl::class);
        $base = [
            'component_type' => 'ssd',
            'manufacturer' => 'Samsung',
            'model' => '870 QVO',
            'mpn' => 'MZ-77Q8T0B/AM',
            'storage_type' => 'SSD',
        ];

        $service->ingest($parser->identify('https://www.amazon.com/dp/B089C3TZL9'), $normalizer->fromArray([...$base, 'capacity' => '8TB']));
        $conflict = $service->ingest($parser->identify('https://www.newegg.com/p/N82E16820147785'), $normalizer->fromArray([...$base, 'capacity' => '1TB']));

        $this->assertSame(IdentityResolutionState::Conflicting, $conflict->resolution->state);
        $this->assertNull($conflict->listing->hardware_identity_id);
        $this->assertDatabaseCount('hardware_identities', 1);
    }

    public function test_identity_enrichment_never_moves_product_url_or_price_history(): void
    {
        $store = Store::factory()->create(['slug' => 'amazon-us', 'name' => 'Amazon US']);
        $part = PcPart::factory()->create([
            'component_type' => ComponentType::Ssd,
            'manufacturer' => 'Samsung',
            'name' => 'Samsung 870 QVO 8TB SSD',
            'part_numbers' => ['MZ-77Q8T0B/AM'],
            'retailer_urls' => ['amazon' => 'https://www.amazon.com/dp/B089C3TZL9'],
            'specifications' => ['capacity' => '8TB', 'storage_type' => 'SSD'],
        ]);
        $product = Product::factory()->create(['pc_part_id' => $part->getKey(), 'component_type' => ComponentType::Ssd]);
        $url = Url::factory()->for($product)->for($store)->create(['url' => 'https://www.amazon.com/dp/B089C3TZL9']);
        $price = Price::factory()->for($url)->for($store)->create(['price' => 1479.99]);

        $listing = app(RetailerProductUrl::class)->identify($url->url);
        $result = app(HardwareIdentityIngestionService::class)->ingest(
            $listing,
            app(HardwareEvidenceNormalizer::class)->fromPcPart($part),
            part: $part,
            url: $url,
        );

        $this->assertNotNull($result->identity);
        $this->assertSame($product->getKey(), $url->fresh()->product_id);
        $this->assertSame($url->getKey(), $price->fresh()->url_id);
        $this->assertSame(1479.99, (float) $price->fresh()->price);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('urls', 1);
        $this->assertDatabaseCount('prices', 1);
    }

    public function test_risky_observation_never_promotes_or_attaches_an_existing_identity(): void
    {
        $service = app(HardwareIdentityIngestionService::class);
        $normalizer = app(HardwareEvidenceNormalizer::class);
        $parser = app(RetailerProductUrl::class);
        $base = [
            'component_type' => 'ssd',
            'manufacturer' => 'Samsung',
            'model' => '870 QVO',
            'mpn' => 'MZ-77Q8T0B/AM',
            'capacity' => '8TB',
        ];
        $verified = $service->ingest(
            $parser->identify('https://www.amazon.com/dp/B089C3TZL9'),
            $normalizer->fromArray($base),
        );

        $risky = $service->ingest(
            $parser->identify('https://www.newegg.com/p/N82E16820147784'),
            $normalizer->fromArray([...$base, 'condition' => 'renewed', 'marketplace' => true]),
        );
        $reobserved = $service->ingest(
            $parser->identify('https://www.amazon.com/dp/B089C3TZL9'),
            $normalizer->fromArray([...$base, 'condition' => 'renewed']),
        );

        $this->assertSame(IdentityResolutionState::Probable, $risky->resolution->state);
        $this->assertNull($risky->identity);
        $this->assertNull($risky->listing->hardware_identity_id);
        $this->assertSame(IdentityResolutionState::Probable, $reobserved->resolution->state);
        $this->assertNull($reobserved->identity);
        $this->assertSame($verified->identity?->getKey(), $reobserved->listing->hardware_identity_id);
        $this->assertDatabaseCount('hardware_identities', 1);
    }

    #[DataProvider('strongContradictions')]
    public function test_strong_contradictions_are_persisted_fail_closed(
        array $existing,
        array $incoming,
        string $conflictToken,
    ): void {
        $service = app(HardwareIdentityIngestionService::class);
        $normalizer = app(HardwareEvidenceNormalizer::class);
        $parser = app(RetailerProductUrl::class);
        $service->ingest(
            $parser->identify('https://www.amazon.com/dp/B089C3TZL9'),
            $normalizer->fromArray($existing),
        );

        $result = $service->ingest(
            $parser->identify('https://www.newegg.com/p/N82E16820147784'),
            $normalizer->fromArray($incoming),
        );

        $this->assertSame(IdentityResolutionState::Conflicting, $result->resolution->state);
        $this->assertNull($result->identity);
        $this->assertNull($result->listing->hardware_identity_id);
        $this->assertNull($result->listing->resolved_at);
        $this->assertSame('conflicting', $result->listing->fresh()->resolution_state->value);
        $this->assertStringContainsString($conflictToken, json_encode($result->listing->decision_trace, JSON_THROW_ON_ERROR));
        $this->assertDatabaseCount('hardware_identities', 1);
    }

    public function test_prelinked_part_conflict_does_not_leave_an_orphan_identity(): void
    {
        $existingIdentity = HardwareIdentity::factory()->create([
            'component_type' => ComponentType::Ssd,
            'manufacturer' => 'Samsung',
            'manufacturer_normalized' => 'SAMSUNG',
            'model' => '990 PRO',
            'model_normalized' => '990-PRO',
        ]);
        $part = PcPart::factory()->create([
            'hardware_identity_id' => $existingIdentity->getKey(),
            'component_type' => ComponentType::Ssd,
        ]);
        $evidence = app(HardwareEvidenceNormalizer::class)->fromArray([
            'component_type' => 'ssd',
            'manufacturer' => 'Crucial',
            'model' => 'T705',
            'mpn' => 'CT4000T705SSD3',
            'capacity' => '4TB',
        ]);

        $result = app(HardwareIdentityIngestionService::class)->ingest(
            app(RetailerProductUrl::class)->identify('https://www.newegg.com/p/N82E16820147784'),
            $evidence,
            part: $part,
        );

        $this->assertSame(IdentityResolutionState::Conflicting, $result->resolution->state);
        $this->assertSame($existingIdentity->getKey(), $part->fresh()->hardware_identity_id);
        $this->assertDatabaseCount('hardware_identities', 1);
        $this->assertDatabaseCount('hardware_identity_claims', 0);
    }

    public function test_listing_hash_is_recomputed_and_invalid_identity_data_fails_closed(): void
    {
        $listing = app(RetailerProductUrl::class)->identify('https://www.amazon.com/dp/B089C3TZL9');
        $listing['external_identifier'] = 'B000000000';
        $evidence = app(HardwareEvidenceNormalizer::class)->fromArray([
            'component_type' => 'ssd',
            'manufacturer' => 'Samsung',
            'mpn' => 'MZ-77Q8T0B/AM',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        try {
            app(HardwareIdentityIngestionService::class)->ingest($listing, $evidence);
        } finally {
            $this->assertDatabaseCount('retailer_listings', 0);
            $this->assertDatabaseCount('hardware_identities', 0);
        }
    }

    public function test_ambiguous_and_unverified_evidence_never_attach_a_physical_identity(): void
    {
        $normalizer = app(HardwareEvidenceNormalizer::class);
        $service = app(HardwareIdentityIngestionService::class);
        $parser = app(RetailerProductUrl::class);
        $ambiguousEvidence = $normalizer->fromArray([
            'component_type' => 'psu',
            'manufacturer' => 'Corsair',
            'model' => 'RM850X',
            'wattage' => 850,
        ]);
        HardwareIdentity::factory()->count(2)->create([
            'component_type' => ComponentType::Psu,
            'manufacturer' => 'CORSAIR',
            'manufacturer_normalized' => 'CORSAIR',
            'model' => 'RM850X',
            'model_normalized' => 'RM850X',
            'variant_fingerprint' => $ambiguousEvidence->variantFingerprint(),
            'attributes' => $ambiguousEvidence->attributes,
        ]);

        $ambiguous = $service->ingest(
            $parser->identify('https://www.amazon.com/dp/B089C3TZL9'),
            $ambiguousEvidence,
        );
        $unverified = $service->ingest(
            $parser->identify('https://www.newegg.com/p/N82E16820147784'),
            $normalizer->fromArray(['component_type' => 'psu', 'title' => 'Generic power supply']),
        );

        $this->assertSame(IdentityResolutionState::Ambiguous, $ambiguous->resolution->state);
        $this->assertNull($ambiguous->listing->hardware_identity_id);
        $this->assertSame(IdentityResolutionState::Unverified, $unverified->resolution->state);
        $this->assertNull($unverified->listing->hardware_identity_id);
    }

    public function test_linking_existing_duplicates_to_one_identity_never_merges_owned_state_or_history(): void
    {
        $user = User::factory()->create();
        $amazon = Store::factory()->create(['slug' => 'amazon-us', 'name' => 'Amazon US']);
        $newegg = Store::factory()->create(['slug' => 'newegg-us', 'name' => 'Newegg US']);
        $parts = collect([1, 2])->map(fn (int $index): PcPart => PcPart::factory()->create([
            'component_type' => ComponentType::Ssd,
            'manufacturer' => 'Samsung',
            'name' => 'Samsung 870 QVO 8TB SSD copy '.$index,
            'part_numbers' => ['MZ-77Q8T0B/AM'],
            'series' => '870 QVO',
            'specifications' => [
                'capacity' => '8TB',
                'interface' => 'SATA III',
                'form_factor' => '2.5 inch',
            ],
        ]));
        $products = $parts->map(fn (PcPart $part, int $index): Product => Product::factory()->for($user)->create([
            'pc_part_id' => $part->getKey(),
            'component_type' => ComponentType::Ssd,
            'paused' => $index === 0,
            'paused_by_user' => $index === 0,
            'favourite' => $index !== 0,
            'refresh_interval' => $index === 0 ? 12345 : 28800,
            'notify_price' => $index === 0 ? 1200 : 1300,
        ]));
        $urls = collect([
            Url::factory()->for($products[0])->for($amazon)->create(['url' => 'https://www.amazon.com/dp/B089C3TZL9']),
            Url::factory()->for($products[1])->for($newegg)->create(['url' => 'https://www.newegg.com/p/N82E16820147784']),
        ]);
        $prices = collect([
            Price::factory()->for($urls[0])->for($amazon)->create(['price' => 1479.99]),
            Price::factory()->for($urls[1])->for($newegg)->create(['price' => 1399.99]),
        ]);
        $build = PcBuild::query()->create(['user_id' => $user->getKey(), 'name' => 'Preserved build']);
        DB::table('pc_build_items')->insert([
            'pc_build_id' => $build->getKey(),
            'product_id' => $products[0]->getKey(),
            'pc_part_id' => $parts[0]->getKey(),
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $before = [
            'products' => Product::query()->orderBy('id')->get()->map->only(['id', 'pc_part_id', 'user_id', 'paused', 'paused_by_user', 'favourite', 'refresh_interval', 'notify_price'])->all(),
            'urls' => $urls->map->only(['id', 'product_id'])->all(),
            'prices' => $prices->map->only(['id', 'url_id', 'price'])->all(),
            'build' => (array) DB::table('pc_build_items')->first(),
        ];

        $service = app(HardwareIdentityIngestionService::class);
        $normalizer = app(HardwareEvidenceNormalizer::class);
        foreach ($parts as $index => $part) {
            $service->ingest(
                app(RetailerProductUrl::class)->identify($urls[$index]->url),
                $normalizer->fromPcPart($part),
                part: $part,
                url: $urls[$index],
            );
        }

        $this->assertSame($parts[0]->fresh()->hardware_identity_id, $parts[1]->fresh()->hardware_identity_id);
        $this->assertSame($before['products'], Product::query()->orderBy('id')->get()->map->only(array_keys($before['products'][0]))->all());
        $this->assertSame($before['urls'], Url::query()->orderBy('id')->get()->map->only(['id', 'product_id'])->all());
        $this->assertSame($before['prices'], Price::query()->orderBy('id')->get()->map->only(['id', 'url_id', 'price'])->all());
        $this->assertSame($before['build'], (array) DB::table('pc_build_items')->first());
        $this->assertDatabaseCount('products', 2);
        $this->assertDatabaseCount('urls', 2);
        $this->assertDatabaseCount('prices', 2);
    }

    public static function strongContradictions(): array
    {
        $ssd = [
            'component_type' => 'ssd',
            'manufacturer' => 'Samsung',
            'model' => '870 QVO',
            'mpn' => 'MZ-77Q8T0B/AM',
            'capacity' => '8TB',
        ];

        return [
            'SSD capacity' => [$ssd, [...$ssd, 'capacity' => '1TB'], 'capacity_gb'],
            'CPU model suffix' => [[
                'component_type' => 'cpu', 'manufacturer' => 'AMD', 'model' => 'Ryzen 7 7800X3D', 'mpn' => '100-100000910WOF',
            ], [
                'component_type' => 'cpu', 'manufacturer' => 'AMD', 'model' => 'Ryzen 7 7800X', 'mpn' => '100-100000910WOF',
            ], 'model'],
            'GPU VRAM' => [[
                'component_type' => 'gpu', 'manufacturer' => 'Nvidia', 'model' => 'RTX 4060 Ti', 'mpn' => '900-1G141-2544-000', 'vram_gb' => '8GB',
            ], [
                'component_type' => 'gpu', 'manufacturer' => 'Nvidia', 'model' => 'RTX 4060 Ti', 'mpn' => '900-1G141-2544-000', 'vram_gb' => '16GB',
            ], 'vram_gb'],
            'component type' => [[
                'component_type' => 'cpu', 'manufacturer' => 'AMD', 'model' => '7800X3D', 'mpn' => '100-100000910WOF',
            ], [
                'component_type' => 'gpu', 'manufacturer' => 'AMD', 'model' => '7800X3D', 'mpn' => '100-100000910WOF',
            ], 'component_type'],
            'different MPNs' => [[
                'component_type' => 'ssd', 'manufacturer' => 'Samsung', 'model' => '990 PRO', 'mpn' => 'MZ-V9P2T0B-AM', 'capacity' => '2TB',
            ], [
                'component_type' => 'ssd', 'manufacturer' => 'Samsung', 'model' => '990 PRO', 'mpn' => 'MZ-V9P2T0B-CW', 'capacity' => '2TB',
            ], 'mpn:no_exact_overlap'],
        ];
    }
}
