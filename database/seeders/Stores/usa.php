<?php

use App\Enums\ScraperService;

return [
    [
        'name' => 'Amazon US',
        'slug' => 'amazon-us',
        'domains' => [
            ['domain' => 'amazon.com'],
            ['domain' => 'www.amazon.com'],
        ],
        'scrape_strategy' => [
            'title' => [
                'value' => 'title',
                'type' => 'selector',
            ],
            'price' => [
                'value' => '.a-price > .a-offscreen',
                'type' => 'selector',
            ],
            'image' => [
                'value' => '~"hiRes":"(.+?)"~',
                'type' => 'regex',
            ],
        ],
        'settings' => [
            'scraper_service' => ScraperService::Http->value,
            'scraper_service_settings' => '',
            'locale_settings' => [
                'locale' => 'en_US',
                'currency' => 'USD',
                'price_locale_fallback' => 'en_US',
            ],
        ],
    ],
    [
        'name' => 'eBay US',
        'slug' => 'ebay-us',
        'domains' => [
            ['domain' => 'ebay.com'],
            ['domain' => 'www.ebay.com'],
        ],
        'scrape_strategy' => [
            'title' => [
                'value' => 'meta[property=og:title]|content',
                'type' => 'selector',
            ],
            'price' => [
                'value' => '.x-price-primary',
                'type' => 'selector',
            ],
            'image' => [
                'value' => 'meta[property=og:image]|content',
                'type' => 'selector',
            ],
        ],
    ],
    [
        'name' => 'Walmart US',
        'slug' => 'walmart-us',
        'domains' => [
            ['domain' => 'walmart.com'],
            ['domain' => 'www.walmart.com'],
        ],
        'scrape_strategy' => [
            'title' => ['type' => 'schema_org'],
            'price' => ['type' => 'schema_org'],
            'image' => ['type' => 'schema_org'],
            'availability' => ['type' => 'schema_org'],
        ],
        'settings' => [
            'scraper_service' => ScraperService::Api->value,
            'scraper_service_settings' => '',
            'locale_settings' => [
                'locale' => 'en_US',
                'currency' => 'USD',
                'price_locale_fallback' => 'en_US',
            ],
        ],
        'notes' => 'JavaScript-rendered store. Automated access may be prohibited by Walmart terms; use only with permission or an approved API.',
    ],
    [
        'name' => 'Newegg US',
        'slug' => 'newegg-us',
        'domains' => [
            ['domain' => 'newegg.com'],
            ['domain' => 'www.newegg.com'],
        ],
        'scrape_strategy' => [
            'title' => [
                'value' => 'h1.product-title',
                'type' => 'selector',
            ],
            'price' => [
                'value' => '.product-buy-box .price-current',
                'type' => 'selector',
            ],
            'image' => [
                'value' => 'meta[property="og:image"]|content',
                'type' => 'selector',
            ],
        ],
        'settings' => [
            'scraper_service' => ScraperService::Http->value,
            'scraper_service_settings' => '',
            'locale_settings' => [
                'locale' => 'en_US',
                'currency' => 'USD',
                'price_locale_fallback' => 'en_US',
            ],
        ],
        'notes' => 'Public product pages use the main buy-box price. Requests are low-frequency; verify seller, shipping, and final price before purchase.',
    ],
    [
        'name' => 'Micro Center US',
        'slug' => 'micro-center-us',
        'domains' => [
            ['domain' => 'microcenter.com'],
            ['domain' => 'www.microcenter.com'],
        ],
        'scrape_strategy' => [
            'title' => ['type' => 'schema_org'],
            'price' => ['type' => 'schema_org'],
            'image' => ['type' => 'schema_org'],
            'availability' => ['type' => 'schema_org'],
        ],
        'notes' => 'Deal Hunter discovers indexed links. Confirm the selected store location and pickup-only availability before purchase.',
    ],
    [
        'name' => 'Best Buy US',
        'slug' => 'best-buy-us',
        'domains' => [
            ['domain' => 'bestbuy.com'],
            ['domain' => 'www.bestbuy.com'],
        ],
        'scrape_strategy' => [
            'title' => ['type' => 'schema_org'],
            'price' => ['type' => 'schema_org'],
            'image' => ['type' => 'schema_org'],
            'availability' => ['type' => 'schema_org'],
        ],
        'notes' => 'For reliable near-real-time prices, add a free official Best Buy developer API key.',
    ],
    [
        'name' => 'GameStop US',
        'slug' => 'gamestop-us',
        'domains' => [
            ['domain' => 'gamestop.com'],
            ['domain' => 'www.gamestop.com'],
        ],
        'scrape_strategy' => [
            'title' => ['type' => 'schema_org'],
            'price' => ['type' => 'schema_org'],
            'image' => ['type' => 'schema_org'],
            'availability' => ['type' => 'schema_org'],
        ],
        'notes' => 'Direct automated scraping is disabled. Deal Hunter only discovers links and indexed price snippets.',
    ],
];
