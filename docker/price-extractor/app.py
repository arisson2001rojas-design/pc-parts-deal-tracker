from __future__ import annotations

import os
import threading
import time
from collections import defaultdict
from urllib.parse import urlparse

from curl_cffi import requests
from curl_cffi.const import CurlECode
from flask import Flask, jsonify, request

from extractor import extract_document, looks_blocked


app = Flask(__name__)

ALLOWED_DOMAINS = tuple(
    domain.strip().lower().lstrip(".")
    for domain in os.getenv(
        "ALLOWED_RETAILER_DOMAINS",
        "amazon.com,walmart.com,microcenter.com,newegg.com,bestbuy.com,gamestop.com",
    ).split(",")
    if domain.strip()
)
MIN_HOST_INTERVAL = max(1.0, float(os.getenv("MIN_HOST_INTERVAL_SECONDS", "3")))
_sessions: dict[str, requests.Session] = {}
_locks: defaultdict[str, threading.Lock] = defaultdict(threading.Lock)
_last_request: defaultdict[str, float] = defaultdict(float)


def _host_allowed(host: str) -> bool:
    return any(host == domain or host.endswith(f".{domain}") for domain in ALLOWED_DOMAINS)


def _session(host: str) -> requests.Session:
    if host not in _sessions:
        _sessions[host] = requests.Session(impersonate="chrome131")
    return _sessions[host]


def _upstream_error(status_code: int):
    if status_code == 429:
        status, retryable, error = "rate_limited", True, "retailer rate limited the request"
    elif status_code in (403, 409):
        status, retryable, error = "challenge", True, "retailer rejected the request"
    elif status_code == 404:
        status, retryable, error = "no_price", False, "retailer product was not found"
    elif status_code == 408:
        status, retryable, error = "network_error", True, "retailer request failed"
    elif status_code >= 500:
        status, retryable, error = "network_error", True, "retailer server error"
    else:
        status, retryable, error = "invalid_response", False, "retailer rejected the request"

    payload = {
        "error": error,
        "status": status,
        "http_status": status_code,
        "retryable": retryable,
    }
    if status_code in (403, 409):
        payload["blocked"] = True
    if status_code == 404:
        payload["not_found"] = True

    return jsonify(payload), status_code


@app.get("/health")
def health():
    return jsonify({"status": "ok"})


@app.post("/extract")
def extract():
    payload = request.get_json(silent=True) or {}
    url = str(payload.get("url") or "").strip()
    parsed = urlparse(url)
    host = (parsed.hostname or "").lower()

    if parsed.scheme != "https" or not _host_allowed(host):
        return jsonify({"error": "unsupported retailer URL"}), 422

    with _locks[host]:
        delay = MIN_HOST_INTERVAL - (time.monotonic() - _last_request[host])
        if delay > 0:
            time.sleep(delay)
        try:
            response = _session(host).get(
                url,
                headers={
                    "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8",
                    "Accept-Language": "en-US,en;q=0.9",
                    "Cache-Control": "no-cache",
                },
                timeout=25,
                allow_redirects=True,
            )
        except requests.RequestsError as exception:
            if exception.code == CurlECode.OPERATION_TIMEDOUT:
                return jsonify({
                    "error": "timeout",
                    "status": "network_error",
                    "retryable": True,
                }), 504

            return jsonify({
                "error": "retailer request failed",
                "status": "network_error",
                "http_status": 502,
                "retryable": True,
            }), 502
        finally:
            _last_request[host] = time.monotonic()

    final_url = str(response.url)
    final_host = (urlparse(final_url).hostname or "").lower()
    if not _host_allowed(final_host):
        return jsonify({
            "error": "retailer redirected outside the supported retailer",
            "status": "invalid_response",
            "http_status": 502,
            "retryable": False,
        }), 502
    if response.status_code >= 400:
        return _upstream_error(response.status_code)

    html = response.text
    if looks_blocked(html):
        return jsonify({"error": "retailer returned a verification page", "blocked": True}), 409

    result = extract_document(html, final_url)
    if not result["title"] or not result["candidates"]:
        return jsonify({"error": "no reliable product price found", "data": result}), 422

    return jsonify({"data": result})
