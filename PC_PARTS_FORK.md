# PC Parts Deal Tracker fork

This fork specializes PriceBuddy for comparing PC-component prices. It keeps the upstream product history and notification system and adds:

- component types for CPU, GPU, RAM, SSD, PSU, and other parts;
- component-type search and filtering in the Products screen;
- PC builds that use the cheapest currently available price for every selected product;
- a target-total alert for a whole build;
- US store templates for Amazon, Walmart, and Newegg;
- the existing product alerts through database, email, Pushover, Gotify, Apprise, Telegram, Discord, and ntfy.

## Quick start

1. Follow the normal Docker installation in `README.md`.
2. Run `php artisan migrate` after upgrading an existing PriceBuddy database.
3. Run `php artisan buddy:create-stores usa --update` to install or refresh the three retailer templates.
4. Add product-page URLs from the retailers you are authorized to monitor.
5. Assign every product a component type in **Products**.
6. Open **PC Builds**, select one or more tracked products, and optionally set a target total.

The build total is the sum of each product's cheapest available listing multiplied by its quantity. It excludes shipping, sales tax, import fees, forwarding fees, and currency conversion. Keep all products in a build in the same currency. For delivery to Costa Rica, treat the displayed number as an item subtotal, not the landed cost.

## Retailer access and API strategy

| Retailer | Preferred route | Practical limitation | Direct-scraping risk |
|---|---|---|---|
| Amazon | Creators API for an approved Amazon Associates application | Intended for applications that send sales to Amazon; credentials and program compliance are required | High: Amazon conditions restrict collection of listings/prices and automated extraction |
| Walmart | An approved Walmart partner or marketplace API | Public catalog endpoints are primarily for sellers, suppliers, or advertisers, not a general consumer price-comparison feed | High: Walmart terms expressly prohibit scraping/data-mining without written consent |
| Newegg | Newegg Marketplace API with seller credentials | The official API is seller-oriented, not a public consumer shopping API | Very high: Newegg prohibits automated access and warns that price/inventory scrapers can receive a permanent IP ban |

The included store templates use page metadata and do not bypass CAPTCHAs, authentication, or access controls. They are configuration examples, not permission to scrape. Use them only when the applicable retailer terms, robots policy, account agreement, and local law allow it. A long interval reduces load and blocking probability but does not make prohibited scraping compliant.

Recommended operating posture:

- prefer an approved API or licensed data provider;
- use manual product URLs instead of crawling search-result pages;
- start at 12–24 hour checks and use the existing randomized scheduling;
- never automate checkout, rotate identities to evade a block, or bypass a CAPTCHA;
- stop a retailer integration when it returns a block, challenge, or withdrawal of consent;
- keep source URLs and scrape timestamps visible so a price can be verified before buying.

## License

This remains a fork of PriceBuddy and retains its `LICENSE.md`, which allows modification and distribution for non-commercial use but prohibits commercial use. Do not deploy this fork as a paid service or commercial product without separate permission from the upstream author.
