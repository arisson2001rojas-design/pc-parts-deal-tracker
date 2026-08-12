import unittest
from types import SimpleNamespace
from unittest.mock import Mock, patch

from curl_cffi import requests
from curl_cffi.const import CurlECode

from app import app


class PriceExtractorApiContractTests(unittest.TestCase):
    @patch("app.time.sleep")
    @patch("app._session")
    def test_upstream_http_status_and_semantics_are_preserved(self, session_factory: Mock, _sleep: Mock):
        cases = (
            (429, "rate_limited", True, False),
            (403, "challenge", True, False),
            (409, "challenge", True, False),
            (404, "no_price", False, True),
            (408, "network_error", True, False),
            (503, "network_error", True, False),
            (504, "network_error", True, False),
        )

        for status_code, status, retryable, not_found in cases:
            with self.subTest(status_code=status_code):
                session_factory.return_value.get.return_value = SimpleNamespace(
                    url="https://www.newegg.com/p/N82E16819113941",
                    status_code=status_code,
                )

                response = app.test_client().post(
                    "/extract",
                    json={"url": "https://www.newegg.com/p/N82E16819113941"},
                )

                self.assertEqual(status_code, response.status_code)
                payload = response.get_json()
                self.assertEqual(status, payload["status"])
                self.assertEqual(status_code, payload["http_status"])
                self.assertEqual(retryable, payload["retryable"])
                self.assertEqual(not_found, payload.get("not_found", False))

    @patch("app.time.sleep")
    @patch("app._session")
    def test_local_curl_timeout_uses_extractor_transport_504_without_upstream_status(
        self,
        session_factory: Mock,
        _sleep: Mock,
    ):
        session_factory.return_value.get.side_effect = requests.RequestsError(
            "operation timed out",
            code=CurlECode.OPERATION_TIMEDOUT,
        )

        response = app.test_client().post(
            "/extract",
            json={"url": "https://www.newegg.com/p/N82E16819113941"},
        )

        self.assertEqual(504, response.status_code)
        payload = response.get_json()
        self.assertEqual("network_error", payload["status"])
        self.assertEqual("timeout", payload["error"])
        self.assertTrue(payload["retryable"])
        self.assertNotIn("http_status", payload)

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
