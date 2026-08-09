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

    def test_walmart_embedded_product_data(self):
        payload = {
            'props': {'pageProps': {'initialData': {'data': {'product': {
                'name': 'AMD Ryzen 5 5600X',
                'priceInfo': {'currentPrice': {'price': 119.99, 'currencyCode': 'USD'}},
                'imageInfo': {'thumbnailUrl': 'https://images.example/cpu.jpg'},
            }}}}},
        }
        result = extract_document(
            f'<script id="__NEXT_DATA__" type="application/json">{json.dumps(payload)}</script>',
            'https://www.walmart.com/ip/20657920229',
        )

        self.assertEqual('AMD Ryzen 5 5600X', result['title'])
        self.assertEqual('embedded_data', result['candidates'][0]['source'])

    def test_localized_currency_is_preserved_for_server_validation(self):
        result = extract_document(
            '<h1>AMD Ryzen 7 7700X3D</h1><div class="priceToPay"><span class="a-offscreen">₡149.361,46</span></div>',
            'https://www.amazon.com/dp/B000TEST01',
        )

        self.assertEqual('CRC', result['candidates'][0]['currency'])
        self.assertEqual(149361.46, result['candidates'][0]['price'])


if __name__ == '__main__':
    unittest.main()
