<?php

namespace Tests\Unit\Services;

use App\Enums\IdentityResolutionState;
use App\Services\HardwareEvidenceNormalizer;
use App\Services\HardwareIdentityResolver;
use PHPUnit\Framework\TestCase;

class HardwareEvidenceNormalizerTest extends TestCase
{
    private HardwareEvidenceNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->normalizer = new HardwareEvidenceNormalizer;
    }

    public function test_it_normalizes_typed_mpn_without_erasing_model_suffixes(): void
    {
        $evidence = $this->normalizer->fromArray([
            'component_type' => 'ssd',
            'manufacturer' => 'Samsung Electronics',
            'model' => '870 QVO',
            'mpn' => 'MZ-77Q8T0B/AM',
            'part_number' => 'UNTRUSTED-JSON-LD-SKU',
        ]);

        $this->assertSame('SAMSUNG ELECTRONICS', $evidence->manufacturer);
        $this->assertSame('870-QVO', $evidence->model);
        $this->assertSame(['MZ-77Q8T0B-AM'], $evidence->mpns);
        $this->assertTrue($evidence->canEstablishIdentity());
        $this->assertNotNull($evidence->authoritativeKeyHash());
        $this->assertNotNull($evidence->variantFingerprint());
    }

    public function test_it_parses_storage_capacity_type_interface_and_form_factor(): void
    {
        $evidence = $this->normalizer->fromArray([
            'component_type' => 'ssd',
            'manufacturer' => 'Samsung',
            'series' => '870 QVO',
            'title' => 'Samsung 870 QVO 8TB 2.5 inch SATA III SSD',
            'specifications' => [
                'form_factor' => '2.5 inch',
                'interface' => 'SATA 6.0 Gb/s',
            ],
        ]);

        $this->assertSame('870-QVO', $evidence->model);
        $this->assertSame(8000, $evidence->attributes['capacity_gb']);
        $this->assertSame('SSD', $evidence->attributes['storage_type']);
        $this->assertSame('2-5-INCH', $evidence->attributes['form_factor']);
        $this->assertSame('SATA-III', $evidence->attributes['interface']);
    }

    public function test_it_parses_ram_capacity_layout_generation_and_speed(): void
    {
        $evidence = $this->normalizer->fromArray([
            'component_type' => 'ram',
            'manufacturer' => 'Corsair',
            'series' => 'Vengeance',
            'title' => 'Corsair Vengeance 2 x 16GB DDR5-6000 desktop memory kit',
        ]);

        $this->assertSame(32, $evidence->attributes['total_capacity_gb']);
        $this->assertSame(2, $evidence->attributes['module_count']);
        $this->assertSame(16, $evidence->attributes['module_capacity_gb']);
        $this->assertSame('DDR5', $evidence->attributes['ddr_generation']);
        $this->assertSame(6000, $evidence->attributes['speed_mhz']);
        $this->assertFalse($evidence->bundle, 'A RAM kit is not a multi-product bundle.');
    }

    public function test_it_preserves_gpu_and_cpu_variant_tokens(): void
    {
        $gpu = $this->normalizer->fromArray([
            'component_type' => 'gpu',
            'manufacturer' => 'ASUS',
            'title' => 'ASUS GeForce RTX 4070 Ti SUPER 16GB GDDR6X Graphics Card',
            'memory_type' => 'GDDR6X',
        ]);
        $cpu = $this->normalizer->fromArray([
            'component_type' => 'cpu',
            'manufacturer' => 'AMD',
            'title' => 'AMD Ryzen 7 7800X3D AM5 Desktop Processor',
        ]);

        $this->assertSame('RTX-4070-TI-SUPER', $gpu->model);
        $this->assertSame('RTX-4070-TI-SUPER', $gpu->attributes['gpu_model']);
        $this->assertSame(16, $gpu->attributes['vram_gb']);
        $this->assertSame('GDDR6X', $gpu->attributes['memory_type']);
        $this->assertSame('RYZEN-7-7800X3D', $cpu->model);
        $this->assertSame('X3D', $cpu->attributes['cpu_suffix']);
        $this->assertSame('AM5', $cpu->attributes['socket']);
    }

    public function test_it_parses_motherboard_and_psu_critical_dimensions(): void
    {
        $board = $this->normalizer->fromArray([
            'component_type' => 'motherboard',
            'manufacturer' => 'ASUS',
            'variant' => 'B650E-E Gaming WiFi',
            'revision' => 'Rev 2.0',
            'specifications' => [
                'chipset' => 'AMD B650E',
                'socket' => 'AM5',
                'memory' => ['ram_type' => 'DDR5'],
            ],
        ]);
        $psu = $this->normalizer->fromArray([
            'component_type' => 'psu',
            'manufacturer' => 'Corsair',
            'title' => 'Corsair RM850x 850W ATX Power Supply',
        ]);

        $this->assertSame('B650E-E-GAMING-WIFI', $board->model);
        $this->assertSame('AMD-B650E', $board->attributes['chipset']);
        $this->assertSame('AM5', $board->attributes['socket']);
        $this->assertSame('DDR5', $board->attributes['ram_generation']);
        $this->assertSame('REV-2-0', $board->attributes['revision']);
        $this->assertSame('RM850X', $psu->model);
        $this->assertSame(850, $psu->attributes['wattage_w']);
        $this->assertSame('ATX', $psu->attributes['form_factor']);
    }

    public function test_unsafe_listing_evidence_cannot_establish_identity(): void
    {
        $evidence = $this->normalizer->fromArray([
            'component_type' => 'ssd',
            'manufacturer' => 'Samsung',
            'mpn' => 'MZ-77Q8T0B/AM',
            'title' => 'Amazon Renewed Samsung SSD bundle',
            'marketplace' => true,
        ]);

        $this->assertSame('renewed', $evidence->condition);
        $this->assertTrue($evidence->bundle);
        $this->assertTrue($evidence->marketplace);
        $this->assertTrue($evidence->isUnsafeForVerification());
        $this->assertFalse($evidence->canEstablishIdentity());
    }

    public function test_real_samsung_catalog_and_browser_shapes_normalize_to_the_same_variant(): void
    {
        $catalog = $this->normalizer->fromArray([
            'component_type' => 'ssd',
            'manufacturer' => 'Samsung',
            'series' => '870',
            'variant' => '8000GB',
            'title' => 'Samsung 870 QVO 8TB SSD',
            'part_numbers' => ['MZ-77Q8T0B', 'AM', 'MZ-77Q8T0BW', 'MZ-77Q8T0B/AM'],
            'specifications' => [
                'capacity' => 8000,
                'interface' => 'SATA 6.0 Gb/s',
                'form_factor' => '2.5"',
            ],
        ]);
        $browser = $this->normalizer->fromArray([
            'component_type' => 'ssd',
            'manufacturer' => 'Samsung',
            'model' => '870 QVO',
            'title' => 'Samsung 870 QVO 8TB 2.5 inch SATA III SSD',
            'mpn' => 'MZ-77Q8T0B/AM',
        ]);
        $resolution = (new HardwareIdentityResolver($this->normalizer))->resolve(
            $browser,
            [['id' => 1223, 'evidence' => $catalog]],
        );

        $this->assertSame('870-QVO', $catalog->model);
        $this->assertSame('SATA-III', $catalog->attributes['interface']);
        $this->assertSame('2-5-INCH', $catalog->attributes['form_factor']);
        $this->assertSame(8000, $catalog->attributes['capacity_gb']);
        $this->assertSame(IdentityResolutionState::Verified, $resolution->state);
        $this->assertSame(1223, $resolution->matchedIdentityId);
    }
}
