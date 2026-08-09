"use strict";

(() => {
  const STORE_SELECTORS = {
    "amazon.com": [
      "#corePrice_feature_div .priceToPay .a-offscreen",
      "#corePrice_feature_div .a-price .a-offscreen",
      "#corePriceDisplay_desktop_feature_div .a-price .a-offscreen",
      "#apex_offerDisplay_desktop .a-price .a-offscreen",
      ".priceToPay .a-offscreen"
    ],
    "walmart.com": [
      "[data-automation-id='product-price']",
      "[itemprop='price']"
    ],
    "microcenter.com": [
      ".product-price",
      "[itemprop='price']"
    ],
    "newegg.com": [
      ".product-buy-box .price-current",
      ".product-price .price-current",
      ".price-current"
    ],
    "bestbuy.com": [
      ".priceView-customer-price span",
      "[data-testid='customer-price']",
      ".priceView-hero-price span"
    ],
    "gamestop.com": [
      ".actual-price",
      ".sales .value",
      "[itemprop='price']"
    ]
  };

  const EXCLUDED = /coupon|savings?|list[-_ ]?price|regular[-_ ]?price|was[-_ ]?price|installment|monthly|per[-_ ]?month|affirm|klarna|afterpay|trade[-_ ]?in|rebate/i;

  function domainFor(hostname) {
    const host = hostname.toLowerCase().replace(/^www\./, "");
    return Object.keys(STORE_SELECTORS).find((domain) => host === domain || host.endsWith(`.${domain}`));
  }

  function detectCurrency(raw, hint = "USD") {
    const value = String(raw || "").toUpperCase();
    if (value.includes("₡") || /\bCRC\b/.test(value)) return "CRC";
    if (value.includes("US$") || /\bUSD\b/.test(value)) return "USD";
    if (value.includes("C$") || /\bCAD\b/.test(value)) return "CAD";
    if (value.includes("A$") || /\bAUD\b/.test(value)) return "AUD";
    if (value.includes("€") || /\bEUR\b/.test(value)) return "EUR";
    if (value.includes("£") || /\bGBP\b/.test(value)) return "GBP";
    return String(hint || "USD").toUpperCase();
  }

  function normalizeAvailability(raw) {
    const value = String(raw || "").toLowerCase().replaceAll("_", " ");
    if (/out\s*of\s*stock|sold\s*out|unavailable|not\s*available|agotado|sin\s*existencias/.test(value)) {
      return "out_of_stock";
    }
    if (/in\s*stock|limited\s*stock|available|disponible|preorder/.test(value)) {
      return "in_stock";
    }
    return "unknown";
  }

  function sellerName(raw) {
    if (typeof raw === "string") return raw.trim() || null;
    if (raw && typeof raw === "object") return String(raw.name || raw.legalName || "").trim() || null;
    return null;
  }

  function parseAmount(raw) {
    const matches = String(raw || "").match(/(?:\d{1,3}(?:[\s,.]\d{3})+|\d+)(?:[,.]\d{1,2})?/g);
    if (!matches?.length) return null;
    let value = matches[0].replace(/\s/g, "");
    const comma = value.lastIndexOf(",");
    const period = value.lastIndexOf(".");
    if (comma >= 0 && period >= 0) {
      value = comma > period
        ? value.replaceAll(".", "").replace(",", ".")
        : value.replaceAll(",", "");
    } else if (comma >= 0) {
      value = value.length - comma - 1 === 3 ? value.replaceAll(",", "") : value.replace(",", ".");
    } else if (period >= 0 && value.length - period - 1 === 3) {
      value = value.replaceAll(".", "");
    }
    const amount = Number.parseFloat(value);
    return Number.isFinite(amount) && amount > 0 && amount <= 100000000
      ? Math.round(amount * 100) / 100
      : null;
  }

  function elementText(element) {
    for (const attribute of ["content", "data-price", "data-price-amount", "aria-label"]) {
      const value = element.getAttribute?.(attribute);
      if (value?.trim()) return value.trim();
    }
    const strong = element.querySelector?.("strong");
    const sup = element.querySelector?.("sup");
    if (strong && sup) {
      const dollars = strong.textContent.replace(/[^0-9,]/g, "");
      const cents = sup.textContent.replace(/\D/g, "");
      if (dollars && cents) return `$${dollars}.${cents.slice(0, 2)}`;
    }
    return element.textContent?.trim() || "";
  }

  function excluded(element) {
    let current = element;
    for (let level = 0; current && level < 4; level += 1, current = current.parentElement) {
      const attributes = [current.id, current.className, current.getAttribute?.("data-testid"), current.getAttribute?.("data-automation-id")].join(" ");
      if (EXCLUDED.test(attributes)) return true;
    }
    return false;
  }

  function addCandidate(candidates, raw, source, confidence, currencyHint) {
    const price = parseAmount(raw);
    if (price === null) return;
    const candidate = {
      price,
      currency: detectCurrency(raw, currencyHint),
      source,
      confidence
    };
    const duplicate = candidates.some((item) => item.source === source
      && item.currency === candidate.currency
      && item.price === candidate.price);
    if (!duplicate) candidates.push(candidate);
  }

  function walk(value, visit) {
    if (Array.isArray(value)) {
      value.forEach((child) => walk(child, visit));
    } else if (value && typeof value === "object") {
      visit(value);
      Object.values(value).forEach((child) => walk(child, visit));
    }
  }

  function extractJsonLd(candidates) {
    let title = null;
    let image = null;
    let availability = "unknown";
    let seller = null;
    document.querySelectorAll("script[type='application/ld+json']").forEach((script) => {
      let data;
      try { data = JSON.parse(script.textContent); } catch (_error) { return; }
      walk(data, (item) => {
        const types = Array.isArray(item["@type"]) ? item["@type"] : [item["@type"]];
        if (!types.includes("Product")) return;
        title ||= typeof item.name === "string" ? item.name : null;
        const rawImage = Array.isArray(item.image) ? item.image[0] : item.image;
        image ||= typeof rawImage === "string" ? rawImage : rawImage?.url || null;
        const offers = Array.isArray(item.offers) ? item.offers : [item.offers];
        offers.filter(Boolean).forEach((offer) => {
          const specification = Array.isArray(offer.priceSpecification)
            ? offer.priceSpecification[0] : offer.priceSpecification || {};
          addCandidate(
            candidates,
            offer.price ?? offer.lowPrice ?? specification.price,
            "json_ld",
            0.90,
            offer.priceCurrency ?? specification.priceCurrency
          );
          if (availability === "unknown") availability = normalizeAvailability(offer.availability);
          seller ||= sellerName(offer.seller);
        });
      });
    });
    return { title, image, availability, seller };
  }

  function extractWalmart(candidates) {
    const script = document.querySelector("#__NEXT_DATA__");
    if (!script) return {};
    try {
      const data = JSON.parse(script.textContent);
      const pageProps = data?.props?.pageProps || {};
      const initial = pageProps.initialData || pageProps.initialProps || {};
      const product = initial?.data?.product;
      const current = product?.priceInfo?.currentPrice;
      addCandidate(candidates, current?.price, "embedded_data", 0.98, current?.currencyCode);
      return {
        title: product?.name || null,
        image: product?.imageInfo?.thumbnailUrl || product?.imageInfo?.allImages?.[0]?.url || null,
        availability: normalizeAvailability(product?.availabilityStatus || product?.availabilityStatusV2),
        seller: sellerName(product?.sellerDisplayName || product?.sellerName || product?.seller)
      };
    } catch (_error) {
      return {};
    }
  }

  function pageAvailability(domain) {
    const selectors = {
      "amazon.com": ["#availability", "#outOfStock", "#buybox-see-all-buying-choices"],
      "walmart.com": ["[data-automation-id='fulfillment-shipping']", "[data-automation-id='product-availability']"],
      "microcenter.com": [".inventory", ".inventoryCnt", ".stock"],
      "newegg.com": [".product-inventory", ".product-buy-box"],
      "bestbuy.com": [".fulfillment-add-to-cart-button", "[data-testid='fulfillment-add-to-cart-button']"],
      "gamestop.com": [".availability-msg", ".product-availability"]
    };
    const text = (selectors[domain] || [])
      .flatMap((selector) => [...document.querySelectorAll(selector)].slice(0, 3))
      .map((element) => element.textContent || element.getAttribute?.("aria-label") || "")
      .join(" ");
    const explicit = normalizeAvailability(text);
    if (explicit !== "unknown") return explicit;

    const buyButton = document.querySelector(
      "#add-to-cart-button, [data-automation-id='atc'], .btn-primary.btn-wide, .add-to-cart-button"
    );
    return buyButton && !buyButton.disabled && buyButton.getAttribute("aria-disabled") !== "true"
      ? "in_stock"
      : "unknown";
  }

  function pageSeller(domain) {
    const selectors = {
      "amazon.com": ["#sellerProfileTriggerId", "#merchant-info"],
      "walmart.com": ["[data-automation-id='seller-name']", "[data-testid='seller-name']"],
      "microcenter.com": ["[itemprop='seller']"],
      "newegg.com": [".product-seller", ".product-seller-info"],
      "bestbuy.com": ["[data-testid='marketplace-seller-name']"],
      "gamestop.com": ["[itemprop='seller']"]
    };
    for (const selector of selectors[domain] || []) {
      const value = document.querySelector(selector)?.textContent?.trim();
      if (value) return value.replace(/^sold\s+by\s+/i, "").slice(0, 255);
    }
    return null;
  }

  function extract() {
    const candidates = [];
    const domain = domainFor(location.hostname);
    const currencyMeta = document.querySelector("meta[property='product:price:currency'], meta[itemprop='priceCurrency']")?.content || "USD";
    const structured = extractJsonLd(candidates);
    const embedded = domain === "walmart.com" ? extractWalmart(candidates) : {};

    [
      "meta[property='product:price:amount']",
      "meta[property='og:price:amount']",
      "meta[itemprop='price']"
    ].forEach((selector) => {
      const element = document.querySelector(selector);
      if (element) addCandidate(candidates, element.content, "meta", 0.88, currencyMeta);
    });

    if (domain) {
      STORE_SELECTORS[domain].forEach((selector) => {
        [...document.querySelectorAll(selector)].slice(0, 4).forEach((element) => {
          if (!excluded(element)) {
            addCandidate(candidates, elementText(element), "site_specific", 0.96, currencyMeta);
          }
        });
      });
    }

    const title = embedded.title
      || structured.title
      || document.querySelector("meta[property='og:title']")?.content
      || document.querySelector("h1")?.textContent?.trim()
      || document.title;
    const image = embedded.image
      || structured.image
      || document.querySelector("meta[property='og:image']")?.content
      || null;
    const availability = embedded.availability && embedded.availability !== "unknown"
      ? embedded.availability
      : structured.availability !== "unknown"
        ? structured.availability
        : pageAvailability(domain);
    const seller = embedded.seller || structured.seller || pageSeller(domain);

    return {
      page_url: location.href.split("#")[0],
      title: String(title || "").trim(),
      image_url: image,
      availability,
      seller,
      candidates: candidates.slice(0, 20)
    };
  }

  globalThis.PriceBuddyExtractor = { extract, parseAmount, detectCurrency };
})();
