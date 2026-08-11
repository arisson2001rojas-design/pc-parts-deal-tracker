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
        yield 'motherboard' => ['MSI B550M PRO-VDH WiFi Motherboard for AMD Ryzen 5000', ComponentType::Motherboard];
        yield 'motherboard spanish' => ['MSI B550M PRO-VDH WiFi Placa base AMD Ryzen 5000 AM4 DDR4', ComponentType::Motherboard];
        yield 'ram' => ['Corsair Vengeance 32GB DDR5 Desktop Memory', ComponentType::Ram];
        yield 'ssd' => ['Samsung 990 Pro 2TB NVMe SSD', ComponentType::Ssd];
        yield 'ssd compatible with laptop' => ['KOOTION SSD NVMe M.2 PCIe 2280 de 256GB Compatible con laptop y PC de escritorio', ComponentType::Ssd];
        yield 'laptop memory module' => ['Crucial 32GB DDR5 SODIMM Laptop Memory', ComponentType::Ram];
        yield 'hdd' => ['Western Digital Blue 4TB 3.5 inch HDD Hard Drive', ComponentType::Hdd];
        yield 'sshd' => ['Seagate FireCuda 2TB SSHD Solid State Hybrid Drive', ComponentType::Sshd];
        yield 'air cooler' => ['Thermalright Peerless Assassin 120 SE CPU Air Cooler', ComponentType::CpuCooler];
        yield 'aio cooler' => ['ARCTIC Liquid Freezer III 360 AIO Liquid CPU Cooler', ComponentType::CpuCooler];
        yield 'pc case' => ['Corsair 4000D Airflow ATX Mid-Tower PC Case', ComponentType::PcCase];
        yield 'psu' => ['Corsair RM850x 850W Power Supply', ComponentType::Psu];
    }

    /** @return iterable<string, array{string}> */
    public static function nonComponents(): iterable
    {
        yield 'complete pc' => ['Gaming PC Desktop Computer with Ryzen and Radeon'];
        yield 'gpu holder' => ['PCIe graphics card support holder'];
        yield 'psu cable' => ['Modular PSU replacement cable'];
        yield 'thermal paste' => ['Arctic MX-6 CPU thermal paste'];
        yield 'case fan' => ['120mm RGB case fan replacement'];
        yield 'drive enclosure' => ['USB-C NVMe SSD storage enclosure adapter'];
    }
}
