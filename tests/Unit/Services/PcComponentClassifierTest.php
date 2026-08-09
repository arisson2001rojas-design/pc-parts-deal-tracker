<?php

namespace Tests\Unit\Services;

use App\Enums\ComponentType;
use App\Services\PcComponentClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PcComponentClassifierTest extends TestCase
{
    #[DataProvider('components')]
    public function test_it_classifies_supported_components(string $title, ComponentType $expected): void
    {
        $this->assertSame($expected, (new PcComponentClassifier)->detect($title));
    }

    #[DataProvider('nonComponents')]
    public function test_it_rejects_complete_computers_and_accessories(string $title): void
    {
        $this->assertNull((new PcComponentClassifier)->detect($title));
    }

    /** @return iterable<string, array{string, ComponentType}> */
    public static function components(): iterable
    {
        yield 'cpu' => ['AMD Ryzen 5 5600 Desktop Processor', ComponentType::Cpu];
        yield 'gpu' => ['ASUS GeForce RTX 5070 12GB Graphics Card', ComponentType::Gpu];
        yield 'ram' => ['Corsair Vengeance 32GB DDR5 Desktop Memory', ComponentType::Ram];
        yield 'ssd' => ['Samsung 990 Pro 2TB NVMe SSD', ComponentType::Ssd];
        yield 'psu' => ['Corsair RM850x 850W Power Supply', ComponentType::Psu];
    }

    /** @return iterable<string, array{string}> */
    public static function nonComponents(): iterable
    {
        yield 'complete pc' => ['Gaming PC Desktop Computer with Ryzen and Radeon'];
        yield 'motherboard' => ['B650 Motherboard with DDR5 Memory'];
        yield 'cooler' => ['CPU Air Cooler compatible with AMD Ryzen'];
        yield 'gpu holder' => ['PCIe graphics card support holder'];
        yield 'psu cable' => ['Modular PSU replacement cable'];
    }
}
