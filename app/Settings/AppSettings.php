<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class AppSettings extends Settings
{
    public string $scrape_schedule = '0 6 * * *';

    public int $scrape_cache_ttl = 720;

    public int $sleep_seconds_between_scrape = 10;

    public int $log_retention_days = 30;

    public int $max_attempts_to_scrape = 3;

    /**
     * Total logical scheduled executions for a failed URL, including the
     * original. Each scheduled execution permits one configured-engine attempt;
     * the HTTP/configured-engine strategy fallback remains part of that single
     * execution. A value <= 1 disables delayed retries.
     */
    public int $scrape_retry_max_attempts = 3;

    public int $scrape_retry_delay_minutes = 15;

    public array $notification_services = [];

    public array $integrated_services = [];

    public array $default_locale_settings = [];

    public static function new(): self
    {
        return resolve(static::class);
    }

    public static function group(): string
    {
        return 'app';
    }
}
