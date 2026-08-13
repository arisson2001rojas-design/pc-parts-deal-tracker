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
      ".product-buy-box .price-current_2026",
      ".product-buy-box .price-current",
      ".product-buy-box [data-pp-placement='product'][data-pp-amount]"
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

  const PRODUCT_PATHS = {
    "amazon.com": /\/(?:dp|gp\/product|gp\/aw\/d)\/[A-Z0-9]{10}(?:[/?]|$)/i,
    "walmart.com": /\/ip\/(?:[^/]+\/)?[0-9]+(?:[/?]|$)/i,
    "microcenter.com": /\/product\/[0-9]+(?:[/?]|$)/i,
    "newegg.com": /\/p\/[A-Z0-9-]{8,}(?:[/?]|$)/i,
    "bestbuy.com": /\/(?:product\/[^/?]+\/[A-Z0-9]+|site\/[^/?]+\/[0-9]+\.p)(?:[/?]|$)/i,
    "gamestop.com": /\/products\/.+\/[0-9]+\.html(?:[/?]|$)/i
  };

  const EXCLUDED = /coupon|savings?|list[-_ ]?price|regular[-_ ]?price|was[-_ ]?price|installment|monthly|per[-_ ]?month|affirm|klarna|afterpay|trade[-_ ]?in|rebate/i;

  function domainFor(hostname) {
    const host = hostname.toLowerCase().replace(/^www\./, "");
    return Object.keys(STORE_SELECTORS).find((domain) => host === domain || host.endsWith(`.${domain}`));
  }

  function isProductPage(rawUrl = location.href) {
    let url;
    try { url = new URL(rawUrl); } catch (_error) { return false; }
    const domain = domainFor(url.hostname);
    return Boolean(domain && PRODUCT_PATHS[domain]?.test(`${url.pathname}${url.search}`));
  }

  function detectComponentType(title) {
    const value = String(title || "").trim();
    if (!value) return null;

    const componentCompatibility = /\b(?:compatible\s+(?:with|con)|works?\s+with|for|para)\b.{0,80}\b(?:laptop|notebook|chromebook|desktop(?:\s+(?:computer|pc))?|pc\s+de\s+escritorio|computadora\s+de\s+escritorio|computadora\s+port[aá]til)\b|\b(?:laptop|notebook)\s+(?:memory|ram|ssd|nvme|hdd|hard\s+drive)\b/i.test(value);
    const completeComputer = /\b(?:laptop|notebook|chromebook|desktop\s+(?:computer|pc)|prebuilt(?:\s+pc)?|mini\s+pc|all-in-one\s+(?:pc|computer|desktop)|gaming\s+(?:desktop|computer|pc(?:\s+(?:desktop|computer|system))?)|pc\s+de\s+escritorio|computadora\s+de\s+escritorio|computadora\s+port[aá]til)\b/i.test(value);
    if (completeComputer && !componentCompatibility) {
      return null;
    }
    if (/\b(?:thermal\s+paste|pasta\s+t[eé]rmica|water\s+block|storage\s+enclosure|drive\s+enclosure|adapter|replacement\s+fan|extension\s+cable|case\s+fan|chassis\s+fan|graphics?\s+card\s+(?:holder|support)|gpu\s+(?:holder|support)|(?:power|psu).{0,20}cable)\b/i.test(value)) {
      return null;
    }

    if (/\b(?:mother\s*board|mainboard|mobo|placa\s+(?:base|madre)|tarjeta\s+madre)\b/i.test(value)) return "motherboard";
    if (/\b(?:cpu\s+(?:air\s+|liquid\s+)?cooler|processor\s+cooler|air\s+cpu\s+cooler|tower\s+cpu\s+cooler|(?:aio|all-in-one)\s+(?:liquid\s+)?(?:cpu\s+)?cooler|liquid\s+cpu\s+cooler|cpu\s+liquid\s+cooler|cpu\s+heatsink|heatsink\s+for\s+cpu|disipador(?:\s+de|\s+para)?\s+(?:cpu|procesador)|enfriador(?:\s+de|\s+para)?\s+(?:cpu|procesador)|refrigeraci[oó]n\s+l[ií]quida(?:\s+para\s+(?:cpu|procesador))?)\b/i.test(value)) return "cpu_cooler";
    if (/\b(?:pc\s+case|computer\s+case|gaming\s+case|atx\s+(?:mid[-\s]?tower\s+)?case|micro[-\s]?atx\s+case|mini[-\s]?itx\s+case|mid[-\s]?tower(?:\s+case)?|full[-\s]?tower(?:\s+case)?|mini[-\s]?tower(?:\s+case)?|pc\s+chassis|computer\s+chassis|gabinete(?:\s+(?:para|de)\s+(?:pc|computadora))?|caja\s+(?:para|de)\s+(?:pc|computadora))\b/i.test(value)) return "pc_case";
    if (/\b(?:sshd|solid[-\s]?state\s+hybrid(?:\s+drive)?|hybrid\s+(?:hard\s+)?drive)\b/i.test(value)) return "sshd";
    if (/\b(?:ssd|solid[ -]?state(?:\s+drive)?|nvme|unidad\s+de\s+estado\s+s[oó]lido)\b/i.test(value)) return "ssd";
    if (/\b(?:hdd|hard\s+(?:disk|drive)(?:\s+drive)?|disco\s+duro)\b/i.test(value)) return "hdd";
    if (/\b(?:psu|power\s+suppl(?:y|ies)|fuente\s+de\s+(?:poder|alimentaci[oó]n))\b/i.test(value)) return "psu";
    if (/\b(?:gpu|graphics?\s+card|video\s+card|geforce|radeon|intel\s+arc|tarjeta\s+gr[aá]fica)\b/i.test(value)) return "gpu";
    if (/\b(?:ram|ddr[345]|so-?dimm|dimm|memory|memoria)\b/i.test(value) && /\b(?:ram|memory|memoria|ddr[345]|so-?dimm|dimm)\b/i.test(value)) return "ram";
    if (/\b(?:cpu|processor|procesador|ryzen|athlon|threadripper|celeron|pentium|core\s+(?:ultra\s+)?[i3579])\b/i.test(value)) return "cpu";
    return null;
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
    for (const attribute of ["content", "data-price", "data-price-amount", "data-pp-amount", "aria-label"]) {
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

  function neweggPrimaryOffer(root = document) {
    const buyBox = root.querySelector?.(".product-buy-box");
    if (!buyBox) return null;

    const panes = [...buyBox.querySelectorAll(".product-pane")];
    const labelledNewPane = panes.find((pane) => {
      const label = pane.querySelector?.(".form-radiobox-title")?.textContent?.trim();
      return /^buy new$/i.test(label || "");
    });
    const newPane = labelledNewPane || (
      panes.length === 1 && !panes[0].querySelector?.(".form-radiobox-title") ? panes[0] : null
    );
    if (!newPane) return null;

    const priceElement = [...newPane.querySelectorAll(
      ".price-current_2026, .price-current, [data-pp-amount]"
    )].find((element) => parseAmount(elementText(element)) !== null);
    if (!priceElement) return null;

    const raw = elementText(priceElement);
    const addToCart = buyBox.querySelector?.("button.btn-primary.btn-wide, .btn-primary.btn-wide");
    const canBuy = addToCart
      && /add to cart/i.test(addToCart.textContent || "")
      && !addToCart.disabled
      && addToCart.getAttribute?.("aria-disabled") !== "true";

    return {
      raw,
      price: parseAmount(raw),
      availability: canBuy ? "in_stock" : normalizeAvailability(newPane.textContent)
    };
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
    let manufacturer = null;
    let mpn = null;
    let model = null;
    let sku = null;
    let partNumber = null;
    document.querySelectorAll("script[type='application/ld+json']").forEach((script) => {
      let data;
      try { data = JSON.parse(script.textContent); } catch (_error) { return; }
      walk(data, (item) => {
        const types = Array.isArray(item["@type"]) ? item["@type"] : [item["@type"]];
        if (!types.includes("Product")) return;
        title ||= typeof item.name === "string" ? item.name : null;
        manufacturer ||= sellerName(item.brand || item.manufacturer);
        mpn ||= String(item.mpn || "").trim() || null;
        model ||= String(item.model || "").trim() || null;
        sku ||= String(item.sku || "").trim() || null;
        partNumber ||= String(item.mpn || item.model || item.sku || "").trim() || null;
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
    return { title, image, availability, seller, manufacturer, mpn, model, sku, partNumber };
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

  const AMAZON_NOISE_SELECTOR = [
    ".a-carousel-card",
    "[id^='sp_detail']",
    "[id*='idAsinFaceoutContainer']",
    "[class*='idAsinFaceoutContainer']",
    "[class*='new-detail-faceout-box']",
    "[data-csa-c-content-id*='sponsored']",
    "[data-csa-c-content-id*='recommend']"
  ].join(", ");

  function amazonAsinFromUrl(rawUrl = location.href) {
    let url;
    try { url = new URL(rawUrl); } catch (_error) { return null; }
    const match = `${url.pathname}${url.search}`.match(
      /\/(?:dp|gp\/product|gp\/aw\/d)\/([A-Z0-9]{10})(?:[/?]|$)/i
    );
    return match?.[1]?.toUpperCase() || null;
  }

  function amazonNoise(element) {
    return Boolean(element?.closest?.(AMAZON_NOISE_SELECTOR));
  }

  function amazonVisible(element) {
    if (!element) return false;
    if (element.closest?.("[hidden], .aok-hidden")) return false;
    if (typeof element.getClientRects === "function" && element.getClientRects().length > 0) return true;
    return Boolean(element.offsetWidth || element.offsetHeight);
  }

  function amazonPriceText(element) {
    if (!element) return "";

    const offscreen = element.querySelector?.(".a-offscreen")?.textContent?.trim();
    if (offscreen && offscreen.toLowerCase() !== "null") return offscreen;

    const whole = element.querySelector?.(".a-price-whole")?.textContent
      ?.replace(/[^0-9,]/g, "")
      ?.trim();
    const fraction = element.querySelector?.(".a-price-fraction")?.textContent
      ?.replace(/\D/g, "")
      ?.slice(0, 2);
    const symbol = element.querySelector?.(".a-price-symbol")?.textContent?.trim() || "US$";
    if (whole) return `${symbol}${whole}${fraction ? `.${fraction}` : ""}`;

    const ariaHidden = [...(element.querySelectorAll?.("[aria-hidden='true']") || [])]
      .map((node) => node.textContent?.trim() || "")
      .find((value) => parseAmount(value) !== null);
    if (ariaHidden) return ariaHidden;

    const text = element.textContent?.trim() || "";
    return text.toLowerCase() === "null" ? "" : text;
  }

  function amazonScopedPrice(scope) {
    if (!scope || amazonNoise(scope)) return null;

    const label = scope.querySelector?.(
      "#apex-pricetopay-accessibility-label, .apex-pricetopay-accessibility-label, [data-pricetopay-label]"
    );
    const labelText = label?.textContent?.trim() || "";
    const labelPrice = parseAmount(labelText);
    if (labelPrice !== null) {
      return {
        price: labelPrice,
        raw: labelText,
        source: "amazon_asin_accessibility",
        confidence: 0.995
      };
    }

    const priceElements = [...scope.querySelectorAll(
      ".apex-pricetopay-value, .priceToPay"
    )].filter((element) => !amazonNoise(element));

    const visible = priceElements.filter(amazonVisible);
    const pool = visible.length ? visible : priceElements;
    const parsed = pool
      .map((element) => {
        const raw = amazonPriceText(element);
        return { raw, price: parseAmount(raw) };
      })
      .filter((item) => item.price !== null);

    const unique = [...new Map(
      parsed.map((item) => [item.price.toFixed(2), item])
    ).values()];

    if (unique.length === 1) {
      return {
        price: unique[0].price,
        raw: unique[0].raw,
        source: "amazon_asin_price_to_pay",
        confidence: 0.99
      };
    }

    return null;
  }

  function amazonCenterFallback() {
    const center = document.querySelector("#centerCol");
    if (!center || amazonNoise(center)) return null;

    const primary = amazonScopedPrice(center);
    if (primary) {
      return {
        ...primary,
        source: "amazon_center_price_to_pay",
        confidence: Math.min(primary.confidence, 0.96)
      };
    }

    const prices = [...center.querySelectorAll(".a-price .a-offscreen")]
      .filter((element) => !amazonNoise(element))
      .filter((element) => {
        const owner = element.closest?.(".a-price");
        const classes = `${owner?.className || ""} ${element.parentElement?.className || ""}`;
        return !/basisprice|basis-price|listprice|list-price|a-text-price|strike/i.test(classes);
      })
      .map((element) => ({
        raw: element.textContent?.trim() || "",
        price: parseAmount(element.textContent)
      }))
      .filter((item) => item.price !== null);

    const unique = [...new Map(
      prices.map((item) => [item.price.toFixed(2), item])
    ).values()];

    if (unique.length !== 1) return null;

    return {
      price: unique[0].price,
      raw: unique[0].raw,
      source: "amazon_center_unique_price",
      confidence: 0.93
    };
  }

  function extractAmazonPrimary() {
    const asin = amazonAsinFromUrl();
    if (!asin) return null;

    const escapedAsin = globalThis.CSS?.escape ? CSS.escape(asin) : asin;
    const selectors = [
      `#corePriceDisplay_desktop_feature_div[data-csa-c-asin="${escapedAsin}"]`,
      `[data-feature-name="corePriceDisplay_desktop"][data-csa-c-asin="${escapedAsin}"]`,
      `#corePrice_feature_div[data-csa-c-asin="${escapedAsin}"]`,
      `#apex_offerDisplay_desktop[data-csa-c-asin="${escapedAsin}"]`,
      "#corePriceDisplay_desktop_feature_div",
      "#corePrice_feature_div",
      "#apex_offerDisplay_desktop"
    ];

    const scopes = [];
    const seen = new Set();
    for (const selector of selectors) {
      for (const scope of document.querySelectorAll(selector)) {
        if (seen.has(scope) || amazonNoise(scope)) continue;
        seen.add(scope);

        const scopeAsin = scope.getAttribute?.("data-csa-c-asin")?.toUpperCase();
        if (scopeAsin && scopeAsin !== asin) continue;

        scopes.push(scope);
      }
    }

    // Amazon often leaves stale variant prices in the DOM. Prefer the price
    // scope that is actually rendered for the current ASIN.
    scopes.sort((left, right) => {
      const leftVisible = amazonVisible(left) ? 1 : 0;
      const rightVisible = amazonVisible(right) ? 1 : 0;
      if (leftVisible !== rightVisible) return rightVisible - leftVisible;

      const leftPrimary = left.id === "corePriceDisplay_desktop_feature_div" ? 1 : 0;
      const rightPrimary = right.id === "corePriceDisplay_desktop_feature_div" ? 1 : 0;
      return rightPrimary - leftPrimary;
    });

    for (const scope of scopes) {
      const result = amazonScopedPrice(scope);
      if (result) return { ...result, asin };
    }

    const fallback = amazonCenterFallback();
    return fallback ? { ...fallback, asin } : null;
  }

  function amazonProductTitle() {
    const productTitle = document.querySelector("#productTitle")?.textContent?.trim();
    if (productTitle) return productTitle;

    const ogTitle = document.querySelector("meta[property='og:title']")?.content?.trim();
    if (ogTitle && !/^(?:subtotal|cart|shopping cart|amazon\.com)$/i.test(ogTitle)) {
      return ogTitle;
    }

    return null;
  }

  function extract() {
    const candidates = [];
    const domain = domainFor(location.hostname);
    const currencyMeta = document.querySelector("meta[property='product:price:currency'], meta[itemprop='priceCurrency']")?.content || "USD";
    const structured = extractJsonLd(candidates);
    const embedded = domain === "walmart.com" ? extractWalmart(candidates) : {};
    const amazonPrimary = domain === "amazon.com" ? extractAmazonPrimary() : null;
    const neweggPrimary = domain === "newegg.com" ? neweggPrimaryOffer() : null;

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

    if (amazonPrimary) {
      candidates.length = 0;
      addCandidate(
        candidates,
        amazonPrimary.price,
        amazonPrimary.source,
        amazonPrimary.confidence,
        "USD"
      );
    }
    if (neweggPrimary) {
      candidates.length = 0;
      addCandidate(candidates, neweggPrimary.raw, "newegg_buy_new", 0.995, currencyMeta);
    }

    const amazonTitle = domain === "amazon.com" ? amazonProductTitle() : null;
    const title = embedded.title
      || amazonTitle
      || structured.title
      || document.querySelector("meta[property='og:title']")?.content
      || document.querySelector("h1")?.textContent?.trim()
      || document.title;
    const image = embedded.image
      || structured.image
      || document.querySelector("meta[property='og:image']")?.content
      || null;
    const availability = neweggPrimary?.availability && neweggPrimary.availability !== "unknown"
      ? neweggPrimary.availability
      : embedded.availability && embedded.availability !== "unknown"
        ? embedded.availability
        : structured.availability !== "unknown"
          ? structured.availability
          : pageAvailability(domain);
    const seller = embedded.seller || structured.seller || pageSeller(domain);
    const componentType = detectComponentType(title);

    return {
      page_url: location.href.split("#")[0],
      title: String(title || "").trim(),
      image_url: image,
      availability,
      seller,
      manufacturer: structured.manufacturer,
      mpn: structured.mpn,
      model: structured.model,
      sku: structured.sku,
      part_number: structured.partNumber,
      component_type: componentType,
      supported_product_page: isProductPage(),
      candidates: candidates.slice(0, 20)
    };
  }

  globalThis.PriceBuddyExtractor = {
    extract,
    parseAmount,
    detectCurrency,
    isProductPage,
    detectComponentType,
    __testing: {
      elementText,
      amazonAsinFromUrl,
      amazonPriceText,
      amazonProductTitle,
      amazonVisible,
      neweggPrimaryOffer,
      selectorsFor: (domain) => [...(STORE_SELECTORS[domain] || [])]
    }
  };
})();
