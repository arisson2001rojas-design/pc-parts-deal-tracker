<?php

use App\Models\Store;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Store::query()
            ->whereIn('slug', ['amazon-us', 'walmart-us', 'newegg-us'])
            ->each(function (Store $store): void {
                $settings = $store->settings ?? [];

                if (blank(data_get($settings, 'locale_settings.locale'))) {
                    data_set($settings, 'locale_settings.locale', 'en_US');
                }

                if (blank(data_get($settings, 'locale_settings.currency'))) {
                    data_set($settings, 'locale_settings.currency', 'USD');
                }

                if (blank(data_get($settings, 'locale_settings.price_locale_fallback'))) {
                    data_set($settings, 'locale_settings.price_locale_fallback', 'en_US');
                }

                $store->forceFill(['settings' => $settings])->saveQuietly();
            });
    }

    public function down(): void
    {
        // Intentionally conservative: inherited and user-entered values cannot
        // be distinguished reliably after this migration has run.
    }
};
