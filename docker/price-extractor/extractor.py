from __future__ import annotations

import json
import re
from dataclasses import asdict, dataclass
from typing import Any, Iterable
from urllib.parse import urlparse

from bs4 import BeautifulSoup, Tag


@dataclass(frozen=True)
class Candidate:
    price: float
    currency: str
    source: str
    confidence: float


SITE_SELECTORS: dict[str, tuple[str, ...]] = {
    "amazon.com": (
        "#corePrice_feature_div .priceToPay .a-offscreen",
        "#corePrice_feature_div .a-price .a-offscreen",
        "#corePriceDisplay_desktop_feature_div .a-price .a-offscreen",
        "#apex_offerDisplay_desktop .a-price .a-offscreen",
        ".priceToPay .a-offscreen",
    ),
    "walmart.com": (
        '[data-automation-id="product-price"]',
        '[itemprop="price"]',
    ),
    "microcenter.com": (
        ".product-price",
        '[itemprop="price"]',
    ),
    "newegg.com": (
        ".product-buy-box .price-current",
        ".product-price .price-current",
        ".price-current",
    ),
    "bestbuy.com": (
        ".priceView-customer-price span",
        '[data-testid="customer-price"]',
        ".priceView-hero-price span",
    ),
    "gamestop.com": (
        ".actual-price",
        ".sales .value",
        '[itemprop="price"]',
    ),
}

EXCLUDED_CONTEXT = re.compile(
    r"coupon|savings?|list[-_ ]?price|regular[-_ ]?price|was[-_ ]?price|"
    r"installment|monthly|per[-_ ]?month|affirm|klarna|afterpay|trade[-_ ]?in|rebate",
    re.IGNORECASE,
)


def _domain_for(host: str) -> str | None:
    host = host.lower().removeprefix("www.")
    return next((domain for domain in SITE_SELECTORS if host == domain or host.endswith(f".{domain}")), None)


def _currency(raw: str, hint: str | None = None) -> str:
    value = raw.upper()
    if "₡" in raw or re.search(r"\bCRC\b", value):
        return "CRC"
    if "US$" in value or re.search(r"\bUSD\b", value):
        return "USD"
    if "C$" in value or re.search(r"\bCAD\b", value):
        return "CAD"
    if "A$" in value or re.search(r"\bAUD\b", value):
        return "AUD"
    if "€" in raw or re.search(r"\bEUR\b", value):
        return "EUR"
    if "£" in raw or re.search(r"\bGBP\b", value):
        return "GBP"
    return (hint or "USD").upper()


def _number(raw: str) -> float | None:
    matches = re.findall(r"(?:\d{1,3}(?:[\s,.]\d{3})+|\d+)(?:[,.]\d{1,2})?", raw)
    if not matches:
        return None

    value = matches[0].replace(" ", "")
    comma = value.rfind(",")
    period = value.rfind(".")
    if comma >= 0 and period >= 0:
        value = value.replace(".", "").replace(",", ".") if comma > period else value.replace(",", "")
    elif comma >= 0:
        tail = value[comma + 1 :]
        value = value.replace(",", "") if len(tail) == 3 else value.replace(",", ".")
    elif period >= 0:
        tail = value[period + 1 :]
        if len(tail) == 3:
            value = value.replace(".", "")

    try:
        amount = round(float(value), 2)
    except ValueError:
        return None
    # Preserve large localized values (for example CRC) so the PHP validator
    # can reject the currency explicitly instead of silently losing evidence.
    return amount if 0 < amount <= 100_000_000 else None


def _element_text(element: Tag) -> str:
    for attribute in ("content", "data-price", "data-price-amount", "aria-label"):
        value = element.get(attribute)
        if isinstance(value, str) and value.strip():
            return value.strip()

    strong = element.select_one("strong")
    sup = element.select_one("sup")
    if strong and sup:
        dollars = re.sub(r"[^0-9,]", "", strong.get_text(" ", strip=True))
        cents = re.sub(r"\D", "", sup.get_text(" ", strip=True))
        if dollars and cents:
            return f"${dollars}.{cents[:2]}"

    return element.get_text(" ", strip=True)


def _excluded(element: Tag) -> bool:
    current: Tag | None = element
    for _ in range(4):
        if current is None:
            break
        attributes = " ".join(
            str(current.get(name, "")) for name in ("id", "class", "data-testid", "data-automation-id")
        )
        if EXCLUDED_CONTEXT.search(attributes):
            return True
        current = current.parent if isinstance(current.parent, Tag) else None
    return False


def _add(
    candidates: list[Candidate],
    raw: Any,
    source: str,
    confidence: float,
    currency_hint: str | None = None,
) -> None:
    if raw is None:
        return
    text = str(raw).strip()
    price = _number(text)
    if price is None:
        return
    candidate = Candidate(price, _currency(text, currency_hint), source, confidence)
    if candidate not in candidates:
        candidates.append(candidate)


def _walk(value: Any) -> Iterable[dict[str, Any]]:
    if isinstance(value, dict):
        yield value
        for child in value.values():
            yield from _walk(child)
    elif isinstance(value, list):
        for child in value:
            yield from _walk(child)


def _json_ld(soup: BeautifulSoup, candidates: list[Candidate]) -> tuple[str | None, str | None]:
    title = None
    image = None
    for script in soup.select('script[type="application/ld+json"]'):
        try:
            data = json.loads(script.string or script.get_text())
        except (TypeError, json.JSONDecodeError):
            continue
        for item in _walk(data):
            item_type = item.get("@type")
            types = item_type if isinstance(item_type, list) else [item_type]
            if "Product" not in types:
                continue
            title = title or (str(item.get("name")) if item.get("name") else None)
            raw_image = item.get("image")
            if isinstance(raw_image, list):
                raw_image = raw_image[0] if raw_image else None
            if isinstance(raw_image, dict):
                raw_image = raw_image.get("url")
            image = image or (str(raw_image) if raw_image else None)
            offers = item.get("offers") or []
            if isinstance(offers, dict):
                offers = [offers]
            for offer in offers:
                if not isinstance(offer, dict):
                    continue
                specification = offer.get("priceSpecification") or {}
                if isinstance(specification, list):
                    specification = specification[0] if specification else {}
                raw_price = offer.get("price") or offer.get("lowPrice") or specification.get("price")
                currency = offer.get("priceCurrency") or specification.get("priceCurrency")
                _add(candidates, raw_price, "json_ld", 0.90, currency)
    return title, image


def _walmart_embedded(soup: BeautifulSoup, candidates: list[Candidate]) -> tuple[str | None, str | None]:
    script = soup.select_one("#__NEXT_DATA__")
    if not script:
        return None, None
    try:
        data = json.loads(script.string or script.get_text())
    except (TypeError, json.JSONDecodeError):
        return None, None

    props = data.get("props", {}).get("pageProps", {})
    initial = props.get("initialData") or props.get("initialProps") or {}
    product = initial.get("data", {}).get("product", {})
    if not isinstance(product, dict):
        return None, None
    price_info = product.get("priceInfo", {}).get("currentPrice", {})
    _add(candidates, price_info.get("price"), "embedded_data", 0.98, price_info.get("currencyCode"))
    image_info = product.get("imageInfo", {})
    images = image_info.get("allImages") or []
    image = image_info.get("thumbnailUrl")
    if not image and images and isinstance(images[0], dict):
        image = images[0].get("url")
    return product.get("name"), image


def extract_document(html: str, url: str) -> dict[str, Any]:
    soup = BeautifulSoup(html, "html.parser")
    host = (urlparse(url).hostname or "").lower()
    domain = _domain_for(host)
    candidates: list[Candidate] = []

    meta_currency = None
    currency_meta = soup.select_one(
        'meta[property="product:price:currency"], meta[itemprop="priceCurrency"]'
    )
    if currency_meta:
        meta_currency = currency_meta.get("content")

    structured_title, structured_image = _json_ld(soup, candidates)
    embedded_title = embedded_image = None
    if domain == "walmart.com":
        embedded_title, embedded_image = _walmart_embedded(soup, candidates)

    for selector in (
        'meta[property="product:price:amount"]',
        'meta[property="og:price:amount"]',
        'meta[itemprop="price"]',
    ):
        element = soup.select_one(selector)
        if element:
            _add(candidates, element.get("content"), "meta", 0.88, meta_currency)

    if domain:
        for selector in SITE_SELECTORS[domain]:
            for element in soup.select(selector)[:4]:
                if not _excluded(element):
                    _add(candidates, _element_text(element), "site_specific", 0.96, meta_currency)

    title_meta = soup.select_one('meta[property="og:title"]')
    image_meta = soup.select_one('meta[property="og:image"]')
    heading = soup.select_one("h1")
    title = embedded_title or structured_title
    if not title and title_meta:
        title = title_meta.get("content")
    if not title and heading:
        title = heading.get_text(" ", strip=True)
    image = embedded_image or structured_image
    if not image and image_meta:
        image = image_meta.get("content")

    return {
        "page_url": url,
        "title": str(title or "").strip(),
        "image_url": str(image).strip() if image else None,
        "candidates": [asdict(candidate) for candidate in candidates[:20]],
    }


def looks_blocked(html: str, title: str = "") -> bool:
    sample = f"{title}\n{html[:80_000]}".lower()
    markers = (
        "access denied",
        "are you a human",
        "verify you are human",
        "checking your browser",
        "robot or human",
        "/errors/validatecaptcha",
        "press and hold to confirm",
    )
    return any(marker in sample for marker in markers)
