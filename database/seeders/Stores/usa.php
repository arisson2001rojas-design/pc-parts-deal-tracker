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
            'title' => ['type' => 'schema_org'],
            'price' => ['type' => 'schema_org'],
            'image' => ['type' => 'schema_org'],
            'availability' => ['type' => 'schema_org'],
        ],
        'settings' => [
            'scraper_service' => ScraperService::Http->value,
            'scraper_service_settings' => '',
        ],
        'notes' => 'Automated access may be prohibited by Newegg terms and can lead to an IP ban; use only with permission or an approved API.',
    ],
];
