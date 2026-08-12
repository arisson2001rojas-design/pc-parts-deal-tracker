import json
import unittest

from extractor import extract_document


class ExtractorTest(unittest.TestCase):
    def test_newegg_buy_box_price(self):
        result = extract_document(
            '<html><head><meta property="og:title" content="AMD Ryzen 7 7700X3D">'
            '<meta property="og:image" content="https://images.example/cpu.jpg"></head>'
            '<body><div class="product-buy-box"><li class="price-current">$<strong>159</strong><sup>.99</sup></li></div></body></html>',
            'https://www.newegg.com/p/N82E16819113941',
        )

        self.assertEqual(159.99, result['candidates'][0]['price'])
        self.assertEqual('USD', result['candidates'][0]['currency'])

    def test_newegg_prefers_buy_new_over_used_and_secondary_offers(self):
        result = extract_document(
            '<html><head><meta property="og:title" content="Samsung 870 QVO 8TB SATA SSD"></head>'
            '<body><div class="product-buy-box">'
            '<div class="product-pane is-collapsed"><span class="form-radiobox-title">Buy Used</span>'
            '<div class="price-current_2026">$<strong>999</strong><sup>.49</sup></div></div>'
            '<div class="product-pane"><span class="form-radiobox-title">Buy New</span>'
            '<div class="price-current_2026">$<strong>1,399</strong><sup>.99</sup></div>'
            '<button class="btn btn-primary btn-wide">Add to cart</button></div>'
            '</div><div class="product-price"><li class="price-current">$1,477.85</li></div>'
            '</body></html>',
            'https://www.newegg.com/p/N82E16820147784',
        )

        self.assertEqual(1, len(result['candidates']))
        self.assertEqual(1399.99, result['candidates'][0]['price'])
        self.assertEqual('newegg_buy_new', result['candidates'][0]['source'])
        self.assertEqual('in_stock', result['availability'])

    def test_newegg_server_rendered_primary_pane_is_the_active_new_offer(self):
        result = extract_document(
            '<html><head><meta property="og:title" content="Samsung 870 QVO 8TB SATA SSD"></head>'
            '<body><div class="product-buy-box"><div class="product-pane">'
            '<div class="price-current_2026">$<strong>1,399</strong><sup>.99</sup></div></div>'
            '<button class="btn btn-primary btn-wide">Add to cart</button></div>'
            '<div class="product-price"><li class="price-current">$1,477.85</li></div>'
            '</body></html>',
            'https://www.newegg.com/p/N82E16820147784',
        )

        self.assertEqual(1, len(result['candidates']))
        self.assertEqual(1399.99, result['candidates'][0]['price'])
        self.assertEqual('newegg_buy_new', result['candidates'][0]['source'])
        self.assertEqual('in_stock', result['availability'])

    def test_walmart_embedded_product_data(self):
        payload = {
            'props': {'pageProps': {'initialData': {'data': {'product': {
                'name': 'AMD Ryzen 5 5600X',
                'priceInfo': {'currentPrice': {'price': 119.99, 'currencyCode': 'USD'}},
                'imageInfo': {'thumbnailUrl': 'https://images.example/cpu.jpg'},
                'availabilityStatus': 'OUT_OF_STOCK',
                'sellerDisplayName': 'Marketplace Seller',
            }}}}},
        }
        result = extract_document(
            f'<script id="__NEXT_DATA__" type="application/json">{json.dumps(payload)}</script>',
            'https://www.walmart.com/ip/20657920229',
        )

        self.assertEqual('AMD Ryzen 5 5600X', result['title'])
        self.assertEqual('embedded_data', result['candidates'][0]['source'])
        self.assertEqual('out_of_stock', result['availability'])
        self.assertEqual('Marketplace Seller', result['seller'])

    def test_localized_currency_is_preserved_for_server_validation(self):
        result = extract_document(
            '<h1>AMD Ryzen 7 7700X3D</h1><div class="priceToPay"><span class="a-offscreen">₡149.361,46</span></div>',
            'https://www.amazon.com/dp/B000TEST01',
        )

        self.assertEqual('CRC', result['candidates'][0]['currency'])
        self.assertEqual(149361.46, result['candidates'][0]['price'])

    def test_adjacent_iso_currency_and_bounded_raw_amount_are_preserved(self):
        result = extract_document(
            '<h1>Samsung 870 QVO 8TB</h1><div class="priceToPay"><span class="a-offscreen">CRC672,300.26</span></div>',
            'https://www.amazon.com/dp/B089C3TZL9',
        )

        self.assertEqual('CRC', result['candidates'][0]['currency'])
        self.assertEqual(672300.26, result['candidates'][0]['price'])
        self.assertEqual('CRC672,300.26', result['candidates'][0]['raw_amount'])


if __name__ == '__main__':
    unittest.main()
