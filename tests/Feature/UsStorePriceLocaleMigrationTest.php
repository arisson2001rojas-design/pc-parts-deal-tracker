<?php

namespace Tests\Feature;

use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsStorePriceLocaleMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_adds_missing_us_price_locales_without_replacing_store_settings(): void
    {
        $amazon = Store::factory()->create([
            'slug' => 'amazon-us',
            'settings' => ['scraper_service' => 'http', 'custom_option' => 'preserved'],
        ]);
        $walmart = Store::factory()->create([
            'slug' => 'walmart-us',
            'settings' => [
                'locale_settings' => ['locale' => 'es', 'currency' => 'USD'],
            ],
        ]);
        $newegg = Store::factory()->create([
            'slug' => 'newegg-us',
            'settings' => [
                'scraper_service' => 'http',
                'locale_settings' => [
                    'locale' => 'es',
                    'currency' => 'USD',
                    'price_locale_fallback' => 'fr_FR',
                ],
            ],
        ]);

        $migration = require database_path('migrations/2026_08_12_000001_set_us_store_price_locales.php');
        $migration->up();

        $this->assertSame('en_US', data_get($amazon->fresh()->settings, 'locale_settings.locale'));
        $this->assertSame('USD', data_get($amazon->fresh()->settings, 'locale_settings.currency'));
        $this->assertSame('en_US', data_get($amazon->fresh()->settings, 'locale_settings.price_locale_fallback'));
        $this->assertSame('preserved', data_get($amazon->fresh()->settings, 'custom_option'));
        $this->assertSame('es', data_get($walmart->fresh()->settings, 'locale_settings.locale'));
        $this->assertSame('USD', data_get($walmart->fresh()->settings, 'locale_settings.currency'));
        $this->assertSame('en_US', data_get($walmart->fresh()->settings, 'locale_settings.price_locale_fallback'));
        $this->assertSame('es', data_get($newegg->fresh()->settings, 'locale_settings.locale'));
        $this->assertSame('USD', data_get($newegg->fresh()->settings, 'locale_settings.currency'));
        $this->assertSame('fr_FR', data_get($newegg->fresh()->settings, 'locale_settings.price_locale_fallback'));
    }
}
