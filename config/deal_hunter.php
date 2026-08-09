<?php

return [
    'search_url' => env('DEAL_HUNTER_SEARCH_URL', 'http://searxng:8080/search'),
    'dealnews_feed_url' => env('DEAL_HUNTER_DEALNEWS_FEED_URL', 'https://www.dealnews.com/rss/c93/'),
    'best_buy_api_key' => env('BEST_BUY_API_KEY'),
    'refresh_hours' => (int) env('DEAL_HUNTER_REFRESH_HOURS', 6),
    'max_results_per_store' => (int) env('DEAL_HUNTER_MAX_RESULTS', 10),
    'image_lookup_limit' => (int) env('DEAL_HUNTER_IMAGE_LOOKUPS', 3),

    /*
     * Discovery uses a search index, not automated checkout or direct store
     * scraping. Indexed prices can be stale, so every result links to the
     * retailer for confirmation before purchase.
     */
    'retailers' => [
        'amazon' => [
            'name' => 'Amazon',
            'domains' => ['amazon.com'],
            'product_path_pattern' => '~/(?:dp|gp/product)/[A-Z0-9]{10}(?:[/?]|$)~i',
        ],
        'walmart' => [
            'name' => 'Walmart',
            'domains' => ['walmart.com'],
            'product_path_pattern' => '~/ip/(?:[^/]+/)?[0-9]+(?:[/?]|$)~i',
        ],
        'micro-center' => [
            'name' => 'Micro Center',
            'domains' => ['microcenter.com'],
            'product_path_pattern' => '~/product/[0-9]+(?:[/?]|$)~i',
        ],
        'newegg' => [
            'name' => 'Newegg',
            'domains' => ['newegg.com'],
            'product_path_pattern' => '~/p/[A-Z0-9]{8,}(?:[/?]|$)~i',
        ],
        'best-buy' => [
            'name' => 'Best Buy',
            'domains' => ['bestbuy.com'],
            'product_path_pattern' => '~/(?:product/[^/?]+/[A-Z0-9]+|site/[^/?]+/[0-9]+\.p)(?:[/?]|$)~i',
        ],
        'gamestop' => [
            'name' => 'GameStop',
            'domains' => ['gamestop.com'],
            'product_path_pattern' => '~/products/.+/[0-9]+\.html(?:[/?]|$)~i',
        ],
    ],

    'starter_searches' => [
        ['name' => 'CPU Ryzen 5 5600', 'query' => 'AMD Ryzen 5 5600', 'component_type' => 'cpu', 'target_price' => 110],
        ['name' => 'GPU RX 6600', 'query' => 'Radeon RX 6600 8GB', 'component_type' => 'gpu', 'target_price' => 190],
        ['name' => 'RAM DDR5 32 GB', 'query' => '32GB DDR5 6000 desktop memory kit', 'component_type' => 'ram', 'target_price' => 85],
        ['name' => 'SSD NVMe 1 TB', 'query' => '1TB NVMe PCIe 4 SSD', 'component_type' => 'ssd', 'target_price' => 65],
        ['name' => 'PSU 650 W Gold', 'query' => '650W 80 Plus Gold power supply', 'component_type' => 'psu', 'target_price' => 80],
        ['name' => 'Latest CPU deals', 'query' => 'AMD Intel desktop processor CPU', 'component_type' => 'cpu', 'target_price' => null],
        ['name' => 'Latest GPU deals', 'query' => 'Radeon GeForce Arc graphics card GPU', 'component_type' => 'gpu', 'target_price' => null],
        ['name' => 'Latest RAM deals', 'query' => 'DDR4 DDR5 desktop memory RAM', 'component_type' => 'ram', 'target_price' => null],
        ['name' => 'Latest SSD deals', 'query' => 'NVMe SATA solid state drive SSD', 'component_type' => 'ssd', 'target_price' => null],
        ['name' => 'Latest PSU deals', 'query' => 'ATX power supply PSU', 'component_type' => 'psu', 'target_price' => null],
    ],
];
