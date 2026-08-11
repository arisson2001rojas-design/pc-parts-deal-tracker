import unittest
from types import SimpleNamespace
from unittest.mock import Mock, patch

from app import app


class PriceExtractorApiContractTests(unittest.TestCase):
    @patch("app.time.sleep")
    @patch("app._session")
    def test_no_price_response_preserves_available_metadata(self, session_factory: Mock, _sleep: Mock):
        session_factory.return_value.get.return_value = SimpleNamespace(
            url="https://www.newegg.com/p/N82E16819113941",
            status_code=200,
            text=(
                '<html><head><meta property="og:image" content="https://images.example/cpu.jpg">'
                "</head><body><h1>AMD Ryzen 7 7700X3D</h1></body></html>"
            ),
        )

        response = app.test_client().post(
            "/extract",
            json={"url": "https://www.newegg.com/p/N82E16819113941"},
        )

        self.assertEqual(422, response.status_code)
        payload = response.get_json()
        self.assertEqual("AMD Ryzen 7 7700X3D", payload["data"]["title"])
        self.assertEqual("https://images.example/cpu.jpg", payload["data"]["image_url"])
        self.assertEqual([], payload["data"]["candidates"])


if __name__ == "__main__":
    unittest.main()
