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
  const MARKETPLACE_DOMAINS = new Set([
    "amazon.com",
    "walmart.com",
    "newegg.com",
    "bestbuy.com"
  ]);
  const RETAILER_SELLERS = {
    "amazon.com": /^(?:amazon(?:\.com)?|amazon\.com services(?:,? inc\.?)?)$/i,
    "walmart.com": /^(?:walmart(?:\.com)?)$/i,
    "microcenter.com": /^(?:micro\s*center)$/i,
    "newegg.com": /^(?:newegg(?:\.com)?)$/i,
    "bestbuy.com": /^(?:best\s*buy(?:\.com)?)$/i,
    "gamestop.com": /^(?:game\s*stop(?:\.com)?)$/i
  };
  const CONDITION_VALUES = new Set([
    "new",
    "used",
    "preowned",
    "renewed",
    "refurbished",
    "open_box",
    "unknown"
  ]);

  function compactText(raw) {
    return String(raw || "").normalize("NFKC").replace(/\s+/g, " ").trim();
  }

  function sellerName(raw) {
    const source = typeof raw === "string"
      ? raw
      : raw && typeof raw === "object"
        ? raw.name || raw.legalName || ""
        : "";
    let value = compactText(source);
    if (!value || value.length > 255) return null;

    const generic = /^(?:seller|vendor|merchant|vendedor|vendedora|seller information|seller info|seller details|seller profile|informaci(?:o|\u00f3|\u00c3\u00b3)n (?:acerca )?del vendedor|sold by|vendido por)$/iu;
    const actionThenSeller = /\b(?:learn|see|view|visit|read|more|details?|information|info|about|m(?:a|\u00e1|\u00c3\u00a1)s|informaci(?:o|\u00f3|\u00c3\u00b3)n)\b.*\b(?:seller|vendor|merchant|vendedor|vendedora)\b/iu;
    const sellerThenAction = /\b(?:seller|vendor|merchant|vendedor|vendedora)\b.*\b(?:information|info|details?|profile|learn|more|link|button|window|informaci(?:o|\u00f3|\u00c3\u00b3)n)\b/iu;
    const navigation = /^(?:learn more|more information|click here|read more|opens? in (?:a )?new (?:tab|window)|link|button|visit (?:the )?(?:store|profile)|m(?:a|\u00e1|\u00c3\u00a1)s informaci(?:o|\u00f3|\u00c3\u00b3)n)$/iu;
    const mixedOfferText = /\b(?:ships?\s+from|shipped\s+by|fulfilled\s+by|sold\s+and\s+shipped|returns?|delivery|enviado\s+por|devoluciones?)\b/iu;
    const priceLike = /^(?:us\s*)?[$€£]?\s*\d[\d,.]*(?:\s*(?:usd|cad|aud|eur|gbp))?$/iu;
    const explicitCurrencyPriceLike = /^(?:usd|cad|aud|eur|gbp|crc|us)\s*\d[\d,.]*(?:\s*(?:usd|cad|aud|eur|gbp|crc))?$/iu;
    if (generic.test(value) || actionThenSeller.test(value) || sellerThenAction.test(value) || navigation.test(value)
        || mixedOfferText.test(value) || /https?:\/\/|www\./i.test(value)
        || /^(?:accessibility|navigation|menu)$/iu.test(value)
        || priceLike.test(value) || explicitCurrencyPriceLike.test(value) || /^[\p{P}\p{S}\s]+$/u.test(value)) {
      return null;
    }

    value = value.replace(/^(?:sold\s+by|vendido\s+por)\s*[:\-]?\s*/iu, "").trim();
    return value && !generic.test(value) ? value.slice(0, 255) : null;
  }

  function normalizeCondition(raw) {
    const value = compactText(raw).toLowerCase().replaceAll("_", " ");
    if (!value) return "unknown";
    if (/\b(?:open[ -]?box|caja\s+abierta)\b/i.test(value)) return "open_box";
    if (/\b(?:pre[ -]?owned|seminuevo|segunda\s+mano)\b/i.test(value)) return "preowned";
    if (/\b(?:renewed|renovado)\b/i.test(value)) return "renewed";
    if (/\b(?:refurbished|refurb|reconditioned|reacondicionado)\b|refurbishedcondition/i.test(value)) return "refurbished";
    if (/\b(?:used|usado)\b|usedcondition/i.test(value)) return "used";
    if (/\b(?:new condition|condition:?\s*new|buy\s+new|nuevo|new)\b|newcondition/i.test(value)) return "new";
    return "unknown";
  }

  function normalizeBundle(raw) {
    if (raw === true || raw === "true") return true;
    if (raw === false || raw === "false") return false;
    return null;
  }

  function bundleFromTitle(raw) {
    return /\b(?:bundle|combo)\b/i.test(compactText(raw)) ? true : null;
  }

  function isRetailerSeller(domain, seller) {
    return Boolean(seller && RETAILER_SELLERS[domain]?.test(seller));
  }

  function sellerType(domain, seller, marketplaceSignal = false) {
    if (isRetailerSeller(domain, seller)) return "retailer";
    if (marketplaceSignal || (seller && MARKETPLACE_DOMAINS.has(domain))) return "marketplace";
    return "unknown";
  }

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
    if (/\bpre[ -]?order\b/.test(value)) return "unknown";
    if (/in\s*stock|limited\s*stock|available|disponible/.test(value)) {
      return "in_stock";
    }
    return "unknown";
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

  function normalizedFulfillmentType(domain, raw, currentSellerType, seller) {
    const value = compactText(raw);
    if (!value) return "unknown";
    if (RETAILER_SELLERS[domain]?.test(value)
        || (domain === "amazon.com" && /\bamazon(?:\.com)?\b/i.test(value))
        || (domain === "walmart.com" && /\bwalmart(?:\.com)?\b/i.test(value))
        || (domain === "newegg.com" && /\bnewegg(?:\.com)?\b/i.test(value))) {
      return currentSellerType === "marketplace" ? "platform" : "retailer";
    }
    if (seller && value.toLowerCase().includes(seller.toLowerCase())) return "seller";
    if (/\b(?:seller|merchant|vendedor|third[ -]?party)\b/i.test(value)) return "seller";
    return "unknown";
  }

  function offerObservation(domain, values = {}) {
    const rawSeller = typeof values.seller === "string" ? values.seller : sellerName(values.seller);
    const seller = sellerName(rawSeller);
    const sellerRejected = values.seller_rejected === true
      || (Boolean(compactText(rawSeller)) && !seller);
    const inferredSellerType = ["retailer", "marketplace", "unknown"].includes(values.seller_type)
      ? values.seller_type
      : sellerType(domain, seller, values.marketplace_signal === true);
    const condition = CONDITION_VALUES.has(values.condition)
      ? values.condition
      : normalizeCondition(values.condition);
    const offerScope = ["primary", "secondary", "none", "unknown"].includes(values.offer_scope)
      ? values.offer_scope
      : "unknown";
    const purchasability = ["active", "buying_choices_only", "unavailable", "unknown"].includes(values.purchasability)
      ? values.purchasability
      : "unknown";
    const fulfillmentType = ["retailer", "platform", "seller", "unknown"].includes(values.fulfillment_type)
      ? values.fulfillment_type
      : "unknown";
    let evidenceQuality = ["reliable", "ambiguous", "invalid"].includes(values.evidence_quality)
      ? values.evidence_quality
      : "ambiguous";
    if (sellerRejected) evidenceQuality = "invalid";

    const evidence = values.offer_evidence || {};
    return {
      seller,
      seller_type: inferredSellerType,
      marketplace: inferredSellerType === "marketplace"
        ? true
        : inferredSellerType === "retailer"
          ? false
          : null,
      condition,
      offer_scope: offerScope,
      purchasability,
      fulfillment_type: fulfillmentType,
      evidence_quality: evidenceQuality,
      bundle: normalizeBundle(values.bundle),
      offer_evidence: {
        source: compactText(evidence.source || "generic").slice(0, 64),
        seller_source: compactText(evidence.seller_source || "unknown").slice(0, 64),
        condition_source: compactText(evidence.condition_source || "unknown").slice(0, 64),
        fulfillment_source: compactText(evidence.fulfillment_source || "unknown").slice(0, 64),
        conflict: sellerRejected
          ? "seller_boilerplate"
          : compactText(evidence.conflict || "").slice(0, 64) || null
      }
    };
  }

  function neweggPrimaryOffer(root = document) {
    const buyBox = root.querySelector?.(".product-buy-box");
    if (!buyBox) return null;

    const panes = [...buyBox.querySelectorAll(".product-pane")];
    const selectedPane = panes.find((pane) => pane.querySelector?.(
      "input[type='radio']:checked, [aria-checked='true'], .is-selected"
    ));
    const labelledNewPane = panes.find((pane) => {
      const label = pane.querySelector?.(".form-radiobox-title")?.textContent?.trim();
      return /^buy new$/i.test(label || "");
    });
    const offerPane = labelledNewPane || selectedPane || (panes.length === 1 ? panes[0] : null);
    if (!offerPane) {
      return {
        raw: "",
        price: null,
        availability: normalizeAvailability(buyBox.textContent),
        observation: offerObservation("newegg.com", {
          offer_scope: "secondary",
          purchasability: "buying_choices_only",
          evidence_quality: "ambiguous",
          offer_evidence: { source: "newegg_buy_box", conflict: "multiple_unscoped_offers" }
        })
      };
    }

    const priceElement = [...offerPane.querySelectorAll(
      ".price-current_2026, .price-current, [data-pp-amount]"
    )].find((element) => parseAmount(elementText(element)) !== null);
    const raw = priceElement ? elementText(priceElement) : "";
    const label = offerPane.querySelector?.(".form-radiobox-title")?.textContent?.trim() || "";
    const condition = normalizeCondition(label);
    const sellerElement = firstElement([
      "[data-testid='seller-name']",
      ".product-seller a",
      ".product-seller-info a",
      ".product-seller",
      ".product-seller-info"
    ], offerPane);
    const rawSeller = sellerElement?.textContent?.trim() || "";
    const normalizedSeller = sellerName(rawSeller);
    const currentSellerType = sellerType("newegg.com", normalizedSeller);
    const fulfillmentElement = firstElement([
      "[data-testid='fulfillment-message']",
      ".product-fulfillment",
      ".product-seller-info"
    ], offerPane);
    const fulfillmentRaw = fulfillmentElement?.textContent?.trim() || "";
    const fulfillmentType = normalizedFulfillmentType(
      "newegg.com",
      fulfillmentRaw,
      currentSellerType,
      normalizedSeller
    );
    const addToCart = offerPane.querySelector?.("button.btn-primary.btn-wide, .btn-primary.btn-wide")
      || buyBox.querySelector?.("button.btn-primary.btn-wide, .btn-primary.btn-wide");
    const canBuy = addToCart
      && /add to cart/i.test(addToCart.textContent || "")
      && !addToCart.disabled
      && addToCart.getAttribute?.("aria-disabled") !== "true";
    const availability = canBuy ? "in_stock" : normalizeAvailability(offerPane.textContent);
    const reliable = Boolean(priceElement && canBuy && condition !== "unknown" && currentSellerType !== "unknown");

    return {
      raw,
      price: parseAmount(raw),
      availability,
      observation: offerObservation("newegg.com", {
        seller: rawSeller,
        seller_type: currentSellerType,
        condition,
        offer_scope: priceElement ? "primary" : "none",
        purchasability: canBuy ? "active" : availability === "out_of_stock" ? "unavailable" : "unknown",
        fulfillment_type: fulfillmentType,
        evidence_quality: reliable ? "reliable" : "ambiguous",
        bundle: null,
        offer_evidence: {
          source: condition === "new" || !label ? "newegg_buy_new" : "newegg_buy_box",
          seller_source: rawSeller ? "newegg_offer_pane" : "unknown",
          condition_source: label ? "newegg_offer_label" : "unknown",
          fulfillment_source: fulfillmentRaw ? "newegg_offer_pane" : "unknown"
        }
      })
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
    let condition = "unknown";
    let bundle = null;
    let offerCount = 0;
    let offerConflict = null;
    let sellerRejected = false;
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
        if (bundle === null) bundle = normalizeBundle(item.isBundle);
        const rawImage = Array.isArray(item.image) ? item.image[0] : item.image;
        image ||= typeof rawImage === "string" ? rawImage : rawImage?.url || null;
        const offers = Array.isArray(item.offers) ? item.offers : [item.offers];
        offers.filter(Boolean).forEach((offer) => {
          offerCount += 1;
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
          const rawSeller = typeof offer.seller === "string"
            ? offer.seller
            : offer.seller?.name || offer.seller?.legalName || "";
          const normalizedSeller = sellerName(rawSeller);
          if (compactText(rawSeller) && !normalizedSeller) sellerRejected = true;
          if (seller && normalizedSeller && seller !== normalizedSeller) {
            offerConflict ||= "structured_seller_conflict";
          }
          seller ||= normalizedSeller;
          const normalizedCondition = normalizeCondition(offer.itemCondition);
          if (condition !== "unknown" && normalizedCondition !== "unknown" && condition !== normalizedCondition) {
            offerConflict ||= "structured_condition_conflict";
          }
          if (condition === "unknown") condition = normalizedCondition;
          if (bundle === null) bundle = normalizeBundle(offer.isBundle);
        });
      });
    });
    return {
      title,
      image,
      availability,
      seller,
      manufacturer,
      mpn,
      model,
      sku,
      partNumber,
      condition,
      bundle,
      offerCount,
      offerConflict,
      sellerRejected
    };
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
      const availability = normalizeAvailability(product?.availabilityStatus || product?.availabilityStatusV2);
      const rawSeller = product?.sellerDisplayName || product?.sellerName || product?.seller || "";
      const normalizedSeller = sellerName(rawSeller);
      const currentSellerType = sellerType("walmart.com", normalizedSeller);
      const conditionRaw = product?.conditionDisplayName
        || product?.condition
        || product?.productCondition
        || product?.offerInfo?.condition
        || "";
      const condition = normalizeCondition(conditionRaw);
      const selectedFulfillment = Array.isArray(product?.fulfillmentOptions)
        ? product.fulfillmentOptions.find((option) => option?.selected || option?.isSelected)
        : null;
      const fulfillmentRaw = product?.fulfillmentType
        || product?.fulfillmentBadge
        || selectedFulfillment?.fulfilledBy
        || selectedFulfillment?.sellerName
        || "";
      const fulfillmentType = normalizedFulfillmentType(
        "walmart.com",
        fulfillmentRaw,
        currentSellerType,
        normalizedSeller
      );
      const hasCurrentPrice = parseAmount(current?.price) !== null;
      const active = hasCurrentPrice && availability === "in_stock";
      return {
        title: product?.name || null,
        image: product?.imageInfo?.thumbnailUrl || product?.imageInfo?.allImages?.[0]?.url || null,
        currentPrice: parseAmount(current?.price),
        currency: current?.currencyCode || "USD",
        availability,
        seller: normalizedSeller,
        observation: offerObservation("walmart.com", {
          seller: rawSeller,
          seller_type: currentSellerType,
          condition,
          offer_scope: hasCurrentPrice ? "primary" : availability === "out_of_stock" ? "none" : "unknown",
          purchasability: active ? "active" : availability === "out_of_stock" ? "unavailable" : "unknown",
          fulfillment_type: fulfillmentType,
          evidence_quality: active && currentSellerType !== "unknown" && condition !== "unknown"
            ? "reliable"
            : "ambiguous",
          bundle: product?.isBundle,
          offer_evidence: {
            source: "walmart_next_data",
            seller_source: rawSeller ? "walmart_current_product" : "unknown",
            condition_source: conditionRaw ? "walmart_current_product" : "unknown",
            fulfillment_source: fulfillmentRaw ? "walmart_current_product" : "unknown"
          }
        })
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

  function pageSellerEvidence(domain) {
    const selectors = {
      "amazon.com": ["#sellerProfileTriggerId", "#merchant-info"],
      "walmart.com": ["[data-automation-id='seller-name']", "[data-testid='seller-name']"],
      "microcenter.com": ["[itemprop='seller']"],
      "newegg.com": [".product-seller", ".product-seller-info"],
      "bestbuy.com": ["[data-testid='marketplace-seller-name']"],
      "gamestop.com": ["[itemprop='seller']"]
    };
    for (const selector of selectors[domain] || []) {
      const element = document.querySelector(selector);
      const raw = element?.textContent?.trim() || "";
      if (!raw) continue;
      const seller = sellerName(raw);
      const href = element?.getAttribute?.("href") || element?.href || "";
      return {
        seller,
        raw,
        source: domain === "amazon.com"
          ? "amazon_page_seller"
          : domain === "walmart.com"
            ? "walmart_page_seller"
            : domain === "newegg.com"
              ? "newegg_page_seller"
              : "generic_page_seller",
        marketplace_signal: selector.includes("marketplace")
          || (domain === "amazon.com" && /[?&]seller=/i.test(href)),
        rejected: !seller
      };
    }
    return { seller: null, raw: "", source: "unknown", marketplace_signal: false, rejected: false };
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

  function firstElement(selectors, root = document) {
    for (const selector of selectors) {
      const element = root.querySelector?.(selector);
      if (element) return element;
    }
    return null;
  }

  function amazonTabularValue(kind) {
    const labelPattern = kind === "seller"
      ? /^(?:sold by|seller|vendido por|vendedor)$/i
      : /^(?:ships? from|fulfilled by|enviado por|enviado desde)$/i;
    const rows = [...document.querySelectorAll?.("#tabular-buybox [tabular-attribute-name]") || []];
    for (const row of rows) {
      const label = compactText(row.getAttribute?.("tabular-attribute-name"));
      if (!labelPattern.test(label)) continue;
      const value = row.querySelector?.(".tabular-buybox-text-message")?.textContent
        || row.textContent
        || "";
      const compactValue = compactText(value);
      return compactValue.toLowerCase().startsWith(label.toLowerCase())
        ? compactValue.slice(label.length).replace(/^\s*:?\s*/, "")
        : compactValue;
    }
    return "";
  }

  function amazonMerchantValue(raw, kind) {
    const value = compactText(raw);
    if (!value) return "";
    const pattern = kind === "seller"
      ? /(?:sold\s+by|vendido\s+por)\s*:?\s*(.+?)(?=\s+(?:ships?\s+from|enviado\s+(?:por|desde)|returns?|devoluciones?)\b|$)/i
      : /(?:ships?\s+from|enviado\s+(?:por|desde))\s*:?\s*(.+?)(?=\s+(?:sold\s+by|vendido\s+por|returns?|devoluciones?)\b|$)/i;
    return compactText(value.match(pattern)?.[1] || "").replace(/[.,;]+$/, "").trim();
  }

  function enabledPurchaseControl(root = document) {
    const control = firstElement([
      "#add-to-cart-button",
      "#buy-now-button",
      "[data-automation-id='atc']",
      ".btn-primary.btn-wide",
      ".add-to-cart-button"
    ], root);
    return Boolean(control
      && !control.disabled
      && control.getAttribute?.("aria-disabled") !== "true");
  }

  function amazonOfferObservation(amazonPrimary, structured, availability, title) {
    const buyingChoices = firstElement([
      "#buybox-see-all-buying-choices",
      "#buybox-see-all-buying-choices-announce"
    ]);
    const activePurchase = Boolean(amazonPrimary && enabledPurchaseControl());
    const merchantElement = document.querySelector?.("#merchant-info");
    const merchantRaw = merchantElement?.textContent || "";
    const profileElement = document.querySelector?.("#sellerProfileTriggerId");
    const profileRaw = profileElement?.textContent?.trim() || "";
    const profileHref = profileElement?.getAttribute?.("href") || profileElement?.href || "";
    const tabularSeller = amazonTabularValue("seller");
    const merchantSeller = amazonMerchantValue(merchantRaw, "seller");
    const rawSeller = tabularSeller || merchantSeller || profileRaw || "";
    const normalizedSeller = sellerName(rawSeller);
    const marketplaceSignal = /[?&]seller=/i.test(profileHref)
      || Boolean(normalizedSeller && !isRetailerSeller("amazon.com", normalizedSeller));
    const currentSellerType = sellerType("amazon.com", normalizedSeller, marketplaceSignal);
    const tabularFulfillment = amazonTabularValue("fulfillment");
    const merchantFulfillment = amazonMerchantValue(merchantRaw, "fulfillment");
    const fulfillmentRaw = tabularFulfillment || merchantFulfillment;
    const fulfillmentType = normalizedFulfillmentType(
      "amazon.com",
      fulfillmentRaw,
      currentSellerType,
      normalizedSeller
    );
    const explicitConditionElement = firstElement([
      "#condition",
      "#condition-value",
      "#offerCondition",
      "#renewedProgramDescription"
    ]);
    let conditionRaw = explicitConditionElement?.textContent?.trim() || "";
    if (!conditionRaw && /\b(?:amazon\s+)?renewed\b|\brefurbished\b|\bopen[ -]?box\b|\bpre[ -]?owned\b|\bused\b/i.test(title || "")) {
      conditionRaw = title;
    }
    const condition = normalizeCondition(conditionRaw);

    let offerScope = amazonPrimary ? "primary" : "unknown";
    let purchasability = activePurchase ? "active" : "unknown";
    if (buyingChoices) {
      offerScope = "none";
      purchasability = "buying_choices_only";
    } else if (availability === "out_of_stock") {
      offerScope = "none";
      purchasability = "unavailable";
    }

    const reliable = offerScope === "none"
      || (offerScope === "primary" && purchasability === "active"
        && currentSellerType !== "unknown" && condition !== "unknown");

    return offerObservation("amazon.com", {
      seller: rawSeller,
      seller_type: currentSellerType,
      condition,
      offer_scope: offerScope,
      purchasability,
      fulfillment_type: fulfillmentType,
      evidence_quality: reliable ? "reliable" : "ambiguous",
      bundle: structured.bundle,
      offer_evidence: {
        source: "amazon_buy_box",
        seller_source: tabularSeller
          ? "amazon_tabular_buy_box"
          : merchantSeller
            ? "amazon_merchant_info"
            : profileRaw
              ? "amazon_seller_profile"
              : "unknown",
        condition_source: explicitConditionElement
          ? "amazon_buy_box"
          : conditionRaw
            ? "amazon_title"
            : "unknown",
        fulfillment_source: tabularFulfillment
          ? "amazon_tabular_buy_box"
          : merchantFulfillment
            ? "amazon_merchant_info"
            : "unknown",
        conflict: null
      }
    });
  }

  function genericOfferObservation(domain, structured, availability) {
    const visibleSeller = pageSellerEvidence(domain);
    const rawSeller = visibleSeller.raw || structured.seller || "";
    const normalizedSeller = sellerName(rawSeller);
    const currentSellerType = sellerType(
      domain,
      normalizedSeller,
      visibleSeller.marketplace_signal
    );
    const condition = structured.offerCount === 1 ? structured.condition : "unknown";
    const unavailable = availability === "out_of_stock";
    return offerObservation(domain, {
      seller: rawSeller,
      seller_type: currentSellerType,
      condition,
      offer_scope: unavailable ? "none" : "unknown",
      purchasability: unavailable
        ? "unavailable"
        : enabledPurchaseControl()
          ? "active"
          : "unknown",
      fulfillment_type: "unknown",
      evidence_quality: "ambiguous",
      seller_rejected: visibleSeller.rejected || structured.sellerRejected,
      bundle: structured.bundle,
      offer_evidence: {
        source: "generic",
        seller_source: visibleSeller.raw ? visibleSeller.source : structured.seller ? "json_ld_offer" : "unknown",
        condition_source: condition !== "unknown" ? "json_ld_offer" : "unknown",
        fulfillment_source: "unknown",
        conflict: structured.offerConflict
      }
    });
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

    if (domain === "walmart.com" && embedded.observation) {
      candidates.length = 0;
      if (embedded.currentPrice !== null) {
        addCandidate(candidates, embedded.currentPrice, "walmart_current_offer", 0.995, embedded.currency);
      }
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
      addCandidate(
        candidates,
        neweggPrimary.raw,
        neweggPrimary.observation?.offer_evidence?.source || "newegg_buy_box",
        0.995,
        currencyMeta
      );
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
    let availability = neweggPrimary?.availability && neweggPrimary.availability !== "unknown"
      ? neweggPrimary.availability
      : embedded.availability && embedded.availability !== "unknown"
        ? embedded.availability
        : structured.availability !== "unknown"
          ? structured.availability
          : pageAvailability(domain);
    const componentType = detectComponentType(title);
    const offer = domain === "amazon.com"
      ? amazonOfferObservation(amazonPrimary, structured, availability, title)
      : domain === "walmart.com" && embedded.observation
        ? embedded.observation
        : domain === "newegg.com" && neweggPrimary?.observation
          ? neweggPrimary.observation
          : genericOfferObservation(domain, structured, availability);

    if (offer.bundle === null) offer.bundle = bundleFromTitle(title);

    if (offer.purchasability === "buying_choices_only") availability = "unknown";
    if (offer.purchasability === "unavailable") availability = "out_of_stock";
    if (offer.offer_scope === "none" || offer.offer_scope === "secondary") {
      candidates.length = 0;
    }

    return {
      page_url: location.href.split("#")[0],
      title: String(title || "").trim(),
      image_url: image,
      availability,
      ...offer,
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
      normalizeAvailability,
      normalizeCondition,
      sellerName,
      selectorsFor: (domain) => [...(STORE_SELECTORS[domain] || [])]
    }
  };
})();
