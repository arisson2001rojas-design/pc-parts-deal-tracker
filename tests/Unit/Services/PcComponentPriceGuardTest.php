<?php

namespace Tests\Unit\Services;

use App\Enums\ComponentType;
use App\Models\Product;
use App\Services\PcComponentPriceGuard;
use App\Services\ScrapeUrl;
use Tests\TestCase;

class PcComponentPriceGuardTest extends TestCase
{
    public function test_it_rejects_a_costa_rican_price_for_a_usd_pc_component(): void
    {
        $product = new Product(['component_type' => ComponentType::Cpu]);

        $reason = PcComponentPriceGuard::rejectionReason(
            $product,
            '₡149,361.46',
            149_361.46,
            'USD',
        );

        $this->assertSame('page currency CRC does not match USD', $reason);
    }

    public function test_it_rejects_an_implausible_unlabelled_pc_component_price(): void
    {
        $product = new Product(['component_type' => ComponentType::Cpu]);

        $this->assertNotNull(PcComponentPriceGuard::rejectionReason(
            $product,
            '149361.46',
            149_361.46,
            'USD',
        ));
    }

    public function test_it_accepts_a_plausible_usd_component_price(): void
    {
        $product = new Product(['component_type' => ComponentType::Cpu]);

        $this->assertNull(PcComponentPriceGuard::rejectionReason($product, 'US$329.00', 329, 'USD'));
    }

    public function test_it_applies_safe_ranges_to_the_extended_component_catalog(): void
    {
        foreach ([
            [ComponentType::Motherboard, 149.99],
            [ComponentType::Hdd, 89.99],
            [ComponentType::Sshd, 109.99],
            [ComponentType::CpuCooler, 39.99],
            [ComponentType::PcCase, 79.99],
        ] as [$type, $price]) {
            $this->assertNull(PcComponentPriceGuard::rejectionReasonForComponent($type, $price, 'USD'));
        }

        $this->assertNotNull(PcComponentPriceGuard::rejectionReasonForComponent(
            ComponentType::Motherboard,
            4.99,
            'USD',
        ));
    }

    public function test_only_configured_domains_are_disabled_for_automated_access(): void
    {
        config()->set('price_buddy.automated_access_disabled_domains', ['example.test']);

        $this->assertFalse(ScrapeUrl::allowsAutomatedAccess('https://shop.example.test/product/1'));
        $this->assertTrue(ScrapeUrl::allowsAutomatedAccess('https://www.newegg.com/p/N82E16819113941'));
        $this->assertTrue(ScrapeUrl::allowsAutomatedAccess('https://www.amazon.com/dp/B000TEST01'));
        $this->assertTrue(ScrapeUrl::allowsAutomatedAccess(null));
    }
}
