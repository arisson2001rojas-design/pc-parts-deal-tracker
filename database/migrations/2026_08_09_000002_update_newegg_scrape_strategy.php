<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('stores')
            ->where('slug', 'newegg-us')
            ->orderBy('id')
            ->each(function (object $store): void {
                $strategy = json_decode((string) $store->scrape_strategy, true);

                if (! is_array($strategy) || ($strategy['price']['type'] ?? null) !== 'schema_org') {
                    return;
                }

                DB::table('stores')->where('id', $store->id)->update([
                    'scrape_strategy' => json_encode([
                        'title' => ['value' => 'h1.product-title', 'type' => 'selector'],
                        'price' => ['value' => '.product-buy-box .price-current', 'type' => 'selector'],
                        'image' => ['value' => 'meta[property="og:image"]|content', 'type' => 'selector'],
                    ], JSON_THROW_ON_ERROR),
                    'notes' => 'Public product pages use the main buy-box price. Requests are low-frequency; verify seller, shipping, and final price before purchase.',
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        // Preserve the working strategy and any later user customizations.
    }
};
