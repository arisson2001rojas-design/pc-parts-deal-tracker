<?php

use App\Enums\ScraperStrategyType;

return [
    /*
    |--------------------------------------------------------------------------
    | Help link in sidebar.
    |--------------------------------------------------------------------------
    */
    'help_url' => env('HELP_URL', 'https://pricebuddy.jez.me?ref=pb-app'),

    /*
    |--------------------------------------------------------------------------
    | How many products to scrape at a time.
    |--------------------------------------------------------------------------
    */
    'chunk_size' => 10,

    /*
    |--------------------------------------------------------------------------
    | The url to the scraper service.
    |--------------------------------------------------------------------------
    */
    'scraper_api_url' => env('SCRAPER_BASE_URL', 'http://scraper:3000'),

    /*
    | Optional local deny-list for retailers that should never be requested by
    | the scheduled URL scraper. Keep this configurable instead of hard-coding
    | transport failures as policy decisions.
    */
    'automated_access_disabled_domains' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('SCRAPER_DISABLED_DOMAINS', '')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Open PC component catalog.
    |--------------------------------------------------------------------------
    */
    'pc_parts_catalog_url' => env(
        'PC_PARTS_CATALOG_URL',
        'https://codeload.github.com/buildcores/buildcores-open-db/zip/refs/heads/main'
    ),

    'pc_parts_tracking_interval_seconds' => (int) env('PC_PARTS_TRACKING_INTERVAL_SECONDS', 28800),
    'pc_parts_bulk_track_limit' => (int) env('PC_PARTS_BULK_TRACK_LIMIT', 25),

    'pc_parts_starter_searches' => [
        'cpu' => [
            'Ryzen 5 4500', 'Ryzen 5 5500', 'Ryzen 5 5600', 'Ryzen 5 7600',
            'Core i3-12100F', 'Core i3-13100F', 'Core i5-12400F',
        ],
        'gpu' => [
            'Arc A380', 'Arc A580', 'Arc B570', 'Radeon RX 6400',
            'Radeon RX 6500 XT', 'Radeon RX 6600', 'GeForce RTX 3050', 'GeForce RTX 4060',
        ],
        'ram' => [
            'Vengeance LPX 16GB', 'T-Force Vulcan Z 16GB', 'Silicon Power 16GB',
            'Crucial Pro 32GB', 'Vengeance 32GB DDR5',
        ],
        'ssd' => [
            'Crucial P3 Plus 1TB', 'WD Blue SN580 1TB', 'MP44L 1TB',
            'Kingston NV3 1TB', 'Silicon Power UD90 1TB',
        ],
        'psu' => [
            'MAG A650BN', 'Corsair CX650', 'Thermaltake Smart 500W',
            'Toughpower GX2 600W', 'Pure Power 12 M 750W',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Meta extraction (POST /api/meta-extraction).
    |--------------------------------------------------------------------------
    |
    | budget_seconds is the wall-clock ceiling for one request, covering the scrape,
    | any browser re-scrape and the AI healing agent. It is published to clients as
    | limits.meta_extraction_timeout_seconds in /api/client-config so they can set
    | their own abort from it rather than hardcoding a guess.
    |
    | heal_floor_seconds is the least remaining budget worth starting healing with.
    | Below it healing is skipped outright, since an agent run that gets cut off
    | costs the user the wait without any chance of a better answer.
    |
    */
    'meta_extraction' => [
        'budget_seconds' => (int) env('META_EXTRACTION_BUDGET_SECONDS', 25),
        'heal_floor_seconds' => (int) env('META_EXTRACTION_HEAL_FLOOR_SECONDS', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | AI provider circuit breaker.
    |--------------------------------------------------------------------------
    |
    | After this many consecutive failures a provider is treated as unavailable for
    | the cooldown, and callers working to a deadline skip it instead of paying the
    | timeout again. The cooldown doubles as the window: failures spread more thinly
    | than this never accumulate. Any success closes the breaker immediately.
    |
    */
    'ai_provider_breaker' => [
        'failure_threshold' => (int) env('AI_PROVIDER_FAILURE_THRESHOLD', 3),
        'cooldown_seconds' => (int) env('AI_PROVIDER_COOLDOWN_SECONDS', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Strategies to attempt for auto store creation.
    |
    | For each strategy, you can specify a selector and/or regex to attempt to
    | extract the data from the page. Selectors will be attempted first
    | with the first working match being used to create the store.
    |--------------------------------------------------------------------------
    */
    'auto_create_store_strategies' => [
        'title' => [
            ScraperStrategyType::SchemaOrg->value => [],
            ScraperStrategyType::Selector->value => [
                'meta[property="og:title"]|content',
                'title',
                'h1',
            ],
            ScraperStrategyType::xPath->value => [],
            ScraperStrategyType::Regex->value => [],
        ],
        'price' => [
            ScraperStrategyType::SchemaOrg->value => [],
            ScraperStrategyType::Selector->value => [
                'meta[property="product:price:amount"]|content',
                'meta[property="og:price:amount"]|content',
                '.a-price .a-offscreen',            // Amazon
                '[itemProp="price"]|content',
                '.price',
                '.product-price, .product-price-value',
                '[class^="price"]',
                '[class*="price"]',
            ],
            ScraperStrategyType::xPath->value => [],
            ScraperStrategyType::Regex->value => [
                '~\"price\"\:\s?\"(.*?)\"~',        // Something that looks like a price, in a json object, eg "price": "99.99"
                '~>\$(\d+(\.\d{2})?)<~',            // Something that looks like a price, in a tag, eg >$99.99<
                '~\$(\d+(\.\d{2})?)~',              // Something that looks like a price, not in a tag
            ],
        ],
        'image' => [
            ScraperStrategyType::SchemaOrg->value => [],
            ScraperStrategyType::Selector->value => [
                'meta[property="og:image"]|content',
                'meta[property="og:image:secure_url"]|content',
            ],
            ScraperStrategyType::xPath->value => [],
            ScraperStrategyType::Regex->value => [
                '~\"hiRes\":\"(.+?)\"~',            // Amazon
                '~\"image\"\:\s?\"(.*?\.jpg)\"~',   // Something that looks like an image, in a json object, eg "price": "99.99"
                '~\"image\"\:\s?\"(.*?\.png)\"~',   // Something that looks like an image, in a json object, eg "price": "99.99"
            ],
        ],
    ],
];
