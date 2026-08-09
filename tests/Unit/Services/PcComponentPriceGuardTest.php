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

    public function test_newegg_direct_automation_is_disabled_but_other_retailers_remain_available(): void
    {
        config()->set('price_buddy.automated_access_disabled_domains', ['newegg.com']);

        $this->assertFalse(ScrapeUrl::allowsAutomatedAccess('https://www.newegg.com/p/N82E16819113941'));
        $this->assertTrue(ScrapeUrl::allowsAutomatedAccess('https://www.amazon.com/dp/B000TEST01'));
    }
}
