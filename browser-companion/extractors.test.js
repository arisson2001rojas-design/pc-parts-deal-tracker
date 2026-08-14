import assert from "node:assert/strict";
import test from "node:test";

await import("./extractors.js");

const { __testing, detectComponentType, extract, isProductPage, parseAmount } = globalThis.PriceBuddyExtractor;

function withPage(document, location, callback) {
  const previousDocument = globalThis.document;
  const previousLocation = globalThis.location;
  globalThis.document = document;
  globalThis.location = location;
  try {
    return callback();
  } finally {
    if (previousDocument === undefined) delete globalThis.document;
    else globalThis.document = previousDocument;
    if (previousLocation === undefined) delete globalThis.location;
    else globalThis.location = previousLocation;
  }
}

function amazonOfferDisplayScope({
  asin,
  seller = "",
  sellerLabel = "Sold by",
  fulfillment = "",
  fulfillmentLabel = "Ships from",
  uodSeller = "",
  uodSellerLabel = "Sold by",
  uodFulfillment = "",
  uodFulfillmentLabel = "Ships from",
  uodCondition = "",
  exposeLabelNodes = true,
  selector = "#offerDisplayFeatures_desktop #offer-display-features"
}) {
  let scope;
  const row = (id, label, value) => {
    let currentRow;
    const secondary = /^uod/i.test(id);
    const labelElement = { textContent: label };
    const valueElement = {
      textContent: value,
      getAttribute: () => null,
      closest: (query) => {
        if (query === "[data-csa-c-asin]") return scope;
        if (secondary && query.includes("[id^='uod']")) return currentRow;
        return null;
      }
    };
    currentRow = {
      id,
      textContent: `${label} ${value}`,
      getAttribute: (name) => name === "id" ? id : null,
      closest: (query) => {
        if (query === "[data-csa-c-asin]") return scope;
        if (secondary && query.includes("[id^='uod']")) return currentRow;
        return null;
      },
      querySelector: (query) => {
        if (query === ".offer-display-feature-label") return exposeLabelNodes ? labelElement : null;
        if (query === ".offer-display-feature-text-message") return valueElement;
        return null;
      }
    };
    return currentRow;
  };
  const sellerRow = seller
    ? row("merchantInfoFeature_feature_div", sellerLabel, seller)
    : null;
  const fulfillmentRow = fulfillment
    ? row("fulfillerInfoFeature_feature_div", fulfillmentLabel, fulfillment)
    : null;
  const uodSellerRow = uodSeller
    ? row("uodMerchantInfoFeature_feature_div", uodSellerLabel, uodSeller)
    : null;
  const uodFulfillmentRow = uodFulfillment
    ? row("uodFulfillerInfoFeature_feature_div", uodFulfillmentLabel, uodFulfillment)
    : null;
  const uodConditionRow = uodCondition
    ? row("uodCondition_feature_div", "Condition", uodCondition)
    : null;
  scope = {
    getAttribute: (name) => name === "data-csa-c-asin" ? asin : null,
    closest: (query) => query === "[data-csa-c-asin]" ? scope : null,
    querySelector: (query) => {
      if (query === "#merchantInfoFeature_feature_div") return sellerRow;
      if (query === "#fulfillerInfoFeature_feature_div") return fulfillmentRow;
      if (query === "#uodMerchantInfoFeature_feature_div") return uodSellerRow;
      if (query === "#uodFulfillerInfoFeature_feature_div") return uodFulfillmentRow;
      if (query === "#uodCondition_feature_div") return uodConditionRow;
      return null;
    }
  };
  return { selector, scope };
}

function amazonVariationElement({ asin, id, label, value }) {
  let element;
  element = {
    id,
    textContent: `${label}: ${value}`,
    getAttribute: (name) => {
      if (name === "id") return id;
      if (name === "data-csa-c-asin") return asin;
      return null;
    },
    closest: (query) => query === "[data-csa-c-asin]" ? element : null,
    querySelector: (query) => {
      if (query === ".a-form-label") return { textContent: label };
      if (query === ".selection") return { textContent: value };
      return null;
    }
  };
  return element;
}

function amazonFixture({
  asin = "B0D1234567",
  merchant = "Ships from Amazon.com Sold by Amazon.com",
  profileText = "Amazon.com",
  profileHref = "",
  condition = "New",
  buyingChoices = false,
  price = 299.99,
  jsonLd = null,
  productTitle = "AMD Ryzen 7 Desktop Processor",
  offerDisplays = [],
  variationPattern = null,
  variationStyle = null
} = {}) {
  const title = productTitle;
  const offerDisplayScopes = offerDisplays.map((offerDisplay) => amazonOfferDisplayScope({
    asin,
    ...offerDisplay
  }));
  const variations = new Map();
  if (variationPattern) {
    variations.set("#variation_pattern_name", amazonVariationElement({
      asin,
      id: "variation_pattern_name",
      ...variationPattern
    }));
  }
  if (variationStyle) {
    variations.set("#variation_style_name", amazonVariationElement({
      asin,
      id: "variation_style_name",
      ...variationStyle
    }));
  }
  const priceScope = {
    id: "corePriceDisplay_desktop_feature_div",
    className: "",
    offsetWidth: 200,
    offsetHeight: 40,
    closest: () => null,
    getClientRects: () => [1],
    getAttribute: (name) => name === "data-csa-c-asin" ? asin : null,
    querySelector: (selector) => selector.includes("accessibility-label")
      ? { textContent: `US$${price}` }
      : null,
    querySelectorAll: () => []
  };
  const addToCart = {
    disabled: false,
    getAttribute: () => null,
    textContent: "Add to Cart"
  };
  const profile = {
    textContent: profileText,
    href: profileHref,
    getAttribute: (name) => name === "href" ? profileHref : null
  };
  const document = {
    title,
    querySelector: (selector) => {
      if (selector.includes("product:price:currency")) return { content: "USD" };
      if (selector === "#productTitle") return { textContent: title };
      if (selector === "#merchant-info") return merchant ? { textContent: merchant } : null;
      if (selector === "#sellerProfileTriggerId") return profileText ? profile : null;
      if (["#condition", "#condition-value", "#offerCondition", "#renewedProgramDescription"].includes(selector)) {
        return selector === "#condition" && condition ? { textContent: condition } : null;
      }
      if (selector === "#add-to-cart-button") return buyingChoices ? null : addToCart;
      if (selector === "#buybox-see-all-buying-choices") {
        return buyingChoices ? { textContent: "See All Buying Options" } : null;
      }
      if (selector === "#availability") return { textContent: buyingChoices ? "" : "In Stock" };
      if (variations.has(selector)) return variations.get(selector);
      const offerDisplay = offerDisplayScopes.find((entry) => entry.selector === selector);
      if (offerDisplay) return offerDisplay.scope;
      return null;
    },
    querySelectorAll: (selector) => {
      if (selector === "script[type='application/ld+json']") {
        return jsonLd ? [{ textContent: JSON.stringify(jsonLd) }] : [];
      }
      if (selector === "#tabular-buybox [tabular-attribute-name]") return [];
      if (selector.startsWith("#corePriceDisplay_desktop_feature_div")
          && !selector.includes(" .")) return price === null ? [] : [priceScope];
      if (selector === "#availability") return [{ textContent: buyingChoices ? "" : "In Stock" }];
      if (selector === "#buybox-see-all-buying-choices") {
        return buyingChoices ? [{ textContent: "See All Buying Options" }] : [];
      }
      if (variations.has(selector)) return [variations.get(selector)];
      if (selector.includes("#offer-display-features")) {
        return offerDisplayScopes
          .filter((entry) => entry.selector === selector)
          .map((entry) => entry.scope);
      }
      return [];
    }
  };
  return {
    document,
    location: { hostname: "www.amazon.com", href: `https://www.amazon.com/dp/${asin}` }
  };
}

function walmartFixture({
  seller = "Walmart.com",
  condition = "New",
  availability = "IN_STOCK",
  fulfillment = "Walmart.com",
  price = 599.99
} = {}) {
  const title = "NVIDIA GeForce RTX 5070 Graphics Card";
  const nextData = {
    props: { pageProps: { initialData: { data: { product: {
      name: title,
      availabilityStatus: availability,
      sellerDisplayName: seller,
      conditionDisplayName: condition,
      fulfillmentType: fulfillment,
      isBundle: false,
      priceInfo: { currentPrice: price === null ? null : { price, currencyCode: "USD" } },
      imageInfo: { thumbnailUrl: "https://i5.walmartimages.com/example.jpg" }
    } } } } }
  };
  return {
    document: {
      title,
      querySelector: (selector) => {
        if (selector === "#__NEXT_DATA__") return { textContent: JSON.stringify(nextData) };
        if (selector === "meta[property='product:price:amount']") return { content: "99.99" };
        if (selector.includes("product:price:currency")) return { content: "USD" };
        return null;
      },
      querySelectorAll: () => []
    },
    location: {
      hostname: "www.walmart.com",
      href: "https://www.walmart.com/ip/RTX-5070/123456789"
    }
  };
}

test("reads Newegg's 2026 split dollar and cents price", () => {
  const parts = {
    strong: { textContent: "129" },
    sup: { textContent: ".99" }
  };
  const element = {
    getAttribute: () => null,
    querySelector: (selector) => parts[selector] || null,
    textContent: "$129.99"
  };

  assert.equal(__testing.elementText(element), "$129.99");
  assert.equal(parseAmount(__testing.elementText(element)), 129.99);
});

test("uses only the primary Newegg buy box price selectors", () => {
  const selectors = __testing.selectorsFor("newegg.com");

  assert.ok(selectors.includes(".product-buy-box .price-current_2026"));
  assert.ok(selectors.includes(".product-buy-box .price-current"));
  assert.ok(selectors.includes(".product-buy-box [data-pp-placement='product'][data-pp-amount]"));
  assert.ok(!selectors.includes(".price-current"));
});

test("prefers Amazon productTitle over misleading structured page titles", () => {
  const previousDocument = globalThis.document;
  globalThis.document = {
    querySelector: (selector) => {
      if (selector === "#productTitle") {
        return { textContent: "KOOTION SSD NVMe M.2 PCIe 2280 256GB" };
      }
      if (selector === "meta[property='og:title']") {
        return { content: "Subtotal" };
      }
      return null;
    }
  };

  try {
    assert.equal(
      __testing.amazonProductTitle(),
      "KOOTION SSD NVMe M.2 PCIe 2280 256GB"
    );
  } finally {
    if (previousDocument === undefined) {
      delete globalThis.document;
    } else {
      globalThis.document = previousDocument;
    }
  }
});

test("extracts the current Amazon ASIN from localized product URLs", () => {
  assert.equal(
    __testing.amazonAsinFromUrl(
      "https://www.amazon.com/-/es/Logitech-G305/dp/B08SYJ32T3/?th=1"
    ),
    "B08SYJ32T3"
  );
  assert.equal(
    __testing.amazonAsinFromUrl(
      "https://www.amazon.com/-/es/MSI-B550M/dp/B089D1YG11?th=1"
    ),
    "B089D1YG11"
  );
});

test("identifies rendered Amazon price scopes separately from hidden stale variants", () => {
  const visible = {
    closest: () => null,
    getClientRects: () => [1],
    offsetWidth: 100,
    offsetHeight: 20
  };
  const hidden = {
    closest: (selector) => selector.includes(".aok-hidden") ? {} : null,
    getClientRects: () => [],
    offsetWidth: 0,
    offsetHeight: 0
  };

  assert.equal(__testing.amazonVisible(visible), true);
  assert.equal(__testing.amazonVisible(hidden), false);
});

test("reconstructs Amazon price when a-offscreen is empty", () => {
  const children = {
    ".a-offscreen": { textContent: "" },
    ".a-price-whole": { textContent: "29." },
    ".a-price-fraction": { textContent: "99" },
    ".a-price-symbol": { textContent: "US$" }
  };
  const element = {
    querySelector: (selector) => children[selector] || null,
    querySelectorAll: () => [],
    textContent: ""
  };

  assert.equal(__testing.amazonPriceText(element), "US$29.99");
  assert.equal(parseAmount(__testing.amazonPriceText(element)), 29.99);
});

test("rejects seller boilerplate while preserving real seller names", () => {
  assert.equal(__testing.sellerName("M\u00e1s informaci\u00f3n acerca del vendedor"), null);
  assert.equal(__testing.sellerName("M\u00c3\u00a1s informaci\u00c3\u00b3n acerca del vendedor"), null);
  assert.equal(__testing.sellerName("Learn more about the seller"), null);
  assert.equal(__testing.sellerName("Seller information"), null);
  assert.equal(__testing.sellerName("vendor"), null);
  assert.equal(__testing.sellerName("Opens in a new window"), null);
  assert.equal(__testing.sellerName("Accessibility"), null);
  assert.equal(__testing.sellerName("$129.99"), null);
  assert.equal(__testing.sellerName("USD 129.99"), null);
  assert.equal(__testing.sellerName("SenyTech Global"), "SenyTech Global");
});

test("normalizes supported offer conditions without guessing unknown values", () => {
  assert.equal(__testing.normalizeCondition("https://schema.org/NewCondition"), "new");
  assert.equal(__testing.normalizeCondition("Buy Used"), "used");
  assert.equal(__testing.normalizeCondition("Pre-Owned"), "preowned");
  assert.equal(__testing.normalizeCondition("Amazon Renewed"), "renewed");
  assert.equal(__testing.normalizeCondition("Certified Refurbished"), "refurbished");
  assert.equal(__testing.normalizeCondition("Open-Box Excellent"), "open_box");
  assert.equal(__testing.normalizeCondition("Special offer"), "unknown");
  assert.equal(__testing.normalizeAvailability("Pre-order now"), "unknown");
});

test("extracts one coherent Amazon marketplace offer", () => {
  const fixture = amazonFixture({
    merchant: "Ships from Amazon.com Sold by Pixel Depot",
    profileText: "Pixel Depot",
    profileHref: "/sp?seller=A1MARKETPLACE"
  });

  const result = withPage(fixture.document, fixture.location, () => extract());

  assert.equal(result.seller, "Pixel Depot");
  assert.equal(result.seller_type, "marketplace");
  assert.equal(result.marketplace, true);
  assert.equal(result.condition, "new");
  assert.equal(result.offer_scope, "primary");
  assert.equal(result.purchasability, "active");
  assert.equal(result.fulfillment_type, "platform");
  assert.equal(result.evidence_quality, "reliable");
  assert.equal(result.bundle, null);
  assert.equal(result.offer_evidence.source, "amazon_buy_box");
  assert.equal(result.candidates[0].price, 299.99);
});

test("classifies a coherent Amazon first-party offer as retailer new", () => {
  const fixture = amazonFixture({
    jsonLd: {
      "@type": "Product",
      name: "AMD Ryzen 7 Desktop Processor",
      offers: {
        "@type": "Offer",
        price: "299.99",
        priceCurrency: "USD",
        seller: { name: "amazon.com" },
        itemCondition: "https://schema.org/NewCondition"
      }
    }
  });

  const result = withPage(fixture.document, fixture.location, () => extract());

  assert.equal(result.seller, "Amazon.com");
  assert.equal(result.seller_type, "retailer");
  assert.equal(result.marketplace, false);
  assert.equal(result.condition, "new");
  assert.equal(result.offer_scope, "primary");
  assert.equal(result.purchasability, "active");
  assert.equal(result.fulfillment_type, "retailer");
  assert.equal(result.evidence_quality, "reliable");
});

test("reads Spanish Amazon offer-display seller and fulfillment from the active Buy Box", () => {
  const asin = "B0F8B59WP7";
  const fixture = amazonFixture({
    asin,
    merchant: "",
    profileText: "Más información acerca del vendedor",
    profileHref: `/sp?seller=A39LAUWR53IY4G&asin=${asin}&isAmazonFulfilled=1`,
    condition: "",
    price: 469.99,
    productTitle: "ASRock AMD Radeon Graphics Card",
    offerDisplays: [{
      sellerLabel: "Vendido por",
      seller: "ASRock USA",
      fulfillmentLabel: "Enviado por",
      fulfillment: "Amazon"
    }]
  });

  const result = withPage(fixture.document, fixture.location, () => extract());

  assert.equal(result.seller, "ASRock USA");
  assert.equal(result.seller_type, "marketplace");
  assert.equal(result.marketplace, true);
  assert.equal(result.condition, "unknown");
  assert.equal(result.offer_scope, "primary");
  assert.equal(result.purchasability, "active");
  assert.equal(result.fulfillment_type, "platform");
  assert.equal(result.evidence_quality, "ambiguous");
  assert.equal(result.offer_evidence.source, "amazon_offer_display_features");
  assert.equal(result.offer_evidence.seller_source, "amazon_offer_display_features");
  assert.equal(result.offer_evidence.fulfillment_source, "amazon_offer_display_features");
  assert.equal(result.offer_evidence.conflict, null);
  assert.equal(result.candidates[0].price, 469.99);
});

test("reads the English Amazon offer-display equivalent", () => {
  const fixture = amazonFixture({
    merchant: "",
    profileText: "",
    offerDisplays: [{
      sellerLabel: "Sold by",
      seller: "Third Party Seller",
      fulfillmentLabel: "Ships from",
      fulfillment: "Amazon"
    }]
  });

  const result = withPage(fixture.document, fixture.location, () => extract());

  assert.equal(result.seller, "Third Party Seller");
  assert.equal(result.seller_type, "marketplace");
  assert.equal(result.fulfillment_type, "platform");
  assert.equal(result.evidence_quality, "reliable");
});

test("classifies a first-party Amazon offer-display offer", () => {
  const fixture = amazonFixture({
    merchant: "",
    profileText: "",
    offerDisplays: [{
      sellerLabel: "Sold by",
      seller: "Amazon.com",
      fulfillmentLabel: "Ships from",
      fulfillment: "Amazon"
    }]
  });

  const result = withPage(fixture.document, fixture.location, () => extract());

  assert.equal(result.seller, "Amazon.com");
  assert.equal(result.seller_type, "retailer");
  assert.equal(result.marketplace, false);
  assert.equal(result.fulfillment_type, "retailer");
  assert.equal(result.evidence_quality, "reliable");
});

test("reads a combined Spanish first-party row without borrowing same-ASIN UOD evidence", () => {
  const asin = "B0D6NN6TM7";
  const fixture = amazonFixture({
    asin,
    merchant: "",
    profileText: "Más información acerca del vendedor",
    profileHref: `/sp?seller=AMAZON&asin=${asin}&isAmazonFulfilled=1`,
    condition: "",
    productTitle: "AMD Ryzen 5 9600X",
    offerDisplays: [{
      sellerLabel: "Remitente / Vendedor",
      seller: "Amazon.com",
      exposeLabelNodes: false,
      uodSellerLabel: "Vendido por",
      uodSeller: "Amazon Resale",
      uodFulfillmentLabel: "Enviado por",
      uodFulfillment: "Amazon",
      uodCondition: "Usado - Como nuevo"
    }]
  });

  const result = withPage(fixture.document, fixture.location, () => extract());

  assert.equal(result.seller, "Amazon.com");
  assert.equal(result.seller_type, "retailer");
  assert.equal(result.marketplace, false);
  assert.equal(result.fulfillment_type, "retailer");
  assert.equal(result.condition, "unknown");
  assert.equal(result.evidence_quality, "ambiguous");
  assert.equal(result.offer_evidence.seller_source, "amazon_offer_display_features");
  assert.equal(result.offer_evidence.fulfillment_source, "amazon_offer_display_features");
  assert.equal(result.offer_evidence.conflict, null);
  assert.notEqual(result.seller, "Amazon Resale");
  assert.notEqual(result.condition, "used");
});

test("reads the combined English first-party offer-display label", () => {
  const fixture = amazonFixture({
    merchant: "",
    profileText: "",
    condition: "",
    offerDisplays: [{
      sellerLabel: "Ships from / Sold by",
      seller: "Amazon.com"
    }]
  });

  const result = withPage(fixture.document, fixture.location, () => extract());

  assert.equal(result.seller, "Amazon.com");
  assert.equal(result.seller_type, "retailer");
  assert.equal(result.fulfillment_type, "retailer");
  assert.equal(result.condition, "unknown");
  assert.equal(result.evidence_quality, "ambiguous");
});

test("treats a combined third-party offer-display row as seller fulfilled", () => {
  const fixture = amazonFixture({
    merchant: "",
    profileText: "",
    offerDisplays: [{
      sellerLabel: "Ships from / Sold by",
      seller: "Example Seller"
    }]
  });

  const result = withPage(fixture.document, fixture.location, () => extract());

  assert.equal(result.seller, "Example Seller");
  assert.equal(result.seller_type, "marketplace");
  assert.equal(result.fulfillment_type, "seller");
  assert.equal(result.offer_evidence.seller_source, "amazon_offer_display_features");
  assert.equal(result.offer_evidence.fulfillment_source, "amazon_offer_display_features");
});

test("prefers structural Amazon seller evidence over seller-profile boilerplate", () => {
  const fixture = amazonFixture({
    merchant: "",
    profileText: "More information about the seller",
    profileHref: "/sp?seller=A1MARKETPLACE",
    offerDisplays: [{ seller: "Visible Seller", fulfillment: "Amazon" }]
  });

  const result = withPage(fixture.document, fixture.location, () => extract());

  assert.equal(result.seller, "Visible Seller");
  assert.equal(result.offer_evidence.seller_source, "amazon_offer_display_features");
  assert.equal(result.offer_evidence.conflict, null);
});

test("ignores Amazon offer-display rows scoped to a different ASIN", () => {
  const fixture = amazonFixture({
    merchant: "Ships from Amazon.com Sold by Current Seller",
    profileText: "",
    offerDisplays: [{
      asin: "B0STALE999",
      seller: "Stale Seller",
      fulfillment: "Stale Fulfillment"
    }]
  });

  const result = withPage(fixture.document, fixture.location, () => extract());

  assert.equal(result.seller, "Current Seller");
  assert.equal(result.fulfillment_type, "platform");
  assert.equal(result.offer_evidence.seller_source, "amazon_merchant_info");
  assert.notEqual(result.seller, "Stale Seller");
});

test("uses a matching Amazon seller-profile href only as fulfillment fallback", () => {
  const asin = "B0D1234567";
  const fixture = amazonFixture({
    asin,
    merchant: "",
    profileText: "Seller Profile",
    profileHref: `/sp?seller=A1MARKETPLACE&asin=${asin}&isAmazonFulfilled=1`,
    offerDisplays: [{ seller: "Profiled Seller" }]
  });

  const result = withPage(fixture.document, fixture.location, () => extract());

  assert.equal(result.seller, "Profiled Seller");
  assert.equal(result.fulfillment_type, "platform");
  assert.equal(result.offer_evidence.fulfillment_source, "amazon_seller_profile_href");
});

test("does not borrow a new condition from an unrelated Amazon JSON-LD offer", () => {
  const fixture = amazonFixture({
    condition: "",
    jsonLd: {
      "@type": "Product",
      name: "AMD Ryzen 7 Desktop Processor",
      offers: {
        "@type": "Offer",
        price: "299.99",
        priceCurrency: "USD",
        seller: { name: "Amazon.com" },
        itemCondition: "https://schema.org/NewCondition"
      }
    }
  });

  const result = withPage(fixture.document, fixture.location, () => extract());

  assert.equal(result.condition, "unknown");
  assert.equal(result.offer_scope, "primary");
  assert.equal(result.purchasability, "active");
  assert.equal(result.evidence_quality, "ambiguous");
});

test("keeps Amazon used renewed and open-box conditions non-new", () => {
  for (const [raw, expected] of [
    ["Used - Like New", "used"],
    ["Amazon Renewed", "renewed"],
    ["Open-Box Excellent", "open_box"]
  ]) {
    const fixture = amazonFixture({
      condition: raw,
      jsonLd: {
        "@type": "Product",
        name: "AMD Ryzen 7 Desktop Processor",
        offers: {
          "@type": "Offer",
          price: "299.99",
          priceCurrency: "USD",
          seller: { name: "Amazon.com" },
          itemCondition: "https://schema.org/NewCondition"
        }
      }
    });
    const result = withPage(fixture.document, fixture.location, () => extract());

    assert.equal(result.condition, expected);
    assert.equal(result.offer_scope, "primary");
    assert.equal(result.purchasability, "active");
    assert.equal(result.evidence_quality, "reliable");
    assert.equal(result.offer_evidence.conflict, null);
  }
});

test("ties Amazon seller fulfillment to the active third-party offer", () => {
  const fixture = amazonFixture({
    merchant: "Ships from Pixel Depot Sold by Pixel Depot",
    profileText: "Pixel Depot",
    profileHref: "/sp?seller=A1MARKETPLACE"
  });

  const result = withPage(fixture.document, fixture.location, () => extract());

  assert.equal(result.seller, "Pixel Depot");
  assert.equal(result.seller_type, "marketplace");
  assert.equal(result.fulfillment_type, "seller");
});

test("marks an explicitly titled Amazon bundle without inventing false", () => {
  const fixture = amazonFixture({
    productTitle: "AMD Ryzen 7 Processor and Motherboard Bundle"
  });

  const result = withPage(fixture.document, fixture.location, () => extract());

  assert.equal(result.bundle, true);
});

test("detects the real mixed CPU and motherboard Amazon title as a bundle", () => {
  const title = "AMD Ryzen 5 7600X + GIGABYTE B650 AORUS ELITE AX placa base";
  const fixture = amazonFixture({
    condition: "",
    productTitle: title
  });

  const result = withPage(fixture.document, fixture.location, () => extract());

  assert.deepEqual(__testing.detectBundleComponentTypes(title), ["cpu", "motherboard"]);
  assert.equal(result.bundle, true);
  assert.equal(result.component_type, "motherboard");
  assert.equal(result.condition, "unknown");
});

test("detects selected Spanish and English Amazon bundle variations", () => {
  const spanish = amazonFixture({
    productTitle: "AMD Ryzen 5 7600X Desktop Processor",
    variationPattern: { label: "Nombre del patrón", value: "Paquete" }
  });
  const english = amazonFixture({
    productTitle: "AMD Ryzen 5 7600X Desktop Processor",
    variationPattern: { label: "Pattern Name", value: "Bundle" }
  });

  const spanishResult = withPage(spanish.document, spanish.location, () => extract());
  const englishResult = withPage(english.document, english.location, () => extract());

  assert.equal(spanishResult.bundle, true);
  assert.equal(englishResult.bundle, true);
});

test("ignores Amazon bundle variation evidence scoped to another ASIN", () => {
  const fixture = amazonFixture({
    productTitle: "AMD Ryzen 5 7600X Desktop Processor",
    variationPattern: {
      asin: "B0STALE999",
      label: "Pattern Name",
      value: "Bundle"
    }
  });

  const result = withPage(fixture.document, fixture.location, () => extract());

  assert.equal(result.bundle, null);
});

test("keeps compatibility and single-component titles out of bundle inference", () => {
  assert.equal(__testing.bundleFromTitle("B650 motherboard DDR5 Ryzen 7000 compatible"), null);
  assert.equal(__testing.bundleFromTitle("DDR5 kit 32GB"), null);
  assert.equal(__testing.bundleFromTitle("Ryzen 5 7600X processor with Wraith cooler"), null);
  assert.equal(__testing.bundleFromTitle("Intel Core Ultra support + DDR5"), null);
});

test("keeps an active visible Amazon offer isolated from unrelated structured offer metadata", () => {
  const fixture = amazonFixture({
    jsonLd: {
      "@type": "Product",
      name: "AMD Ryzen 7 Desktop Processor",
      offers: {
        "@type": "Offer",
        price: "199.99",
        priceCurrency: "USD",
        seller: { name: "Secondary Seller" },
        itemCondition: "https://schema.org/UsedCondition"
      }
    }
  });

  const result = withPage(fixture.document, fixture.location, () => extract());

  assert.equal(result.seller, "Amazon.com");
  assert.equal(result.seller_type, "retailer");
  assert.equal(result.condition, "new");
  assert.equal(result.evidence_quality, "reliable");
  assert.equal(result.offer_evidence.conflict, null);
  assert.equal(result.candidates[0].price, 299.99);
});

test("never emits Amazon seller CTA text as a seller name", () => {
  const fixture = amazonFixture({
    merchant: "",
    profileText: "M\u00e1s informaci\u00f3n acerca del vendedor",
    profileHref: "/sp?seller=A1MARKETPLACE"
  });

  const result = withPage(fixture.document, fixture.location, () => extract());

  assert.equal(result.seller, null);
  assert.equal(result.seller_type, "marketplace");
  assert.equal(result.marketplace, true);
  assert.equal(result.evidence_quality, "invalid");
  assert.equal(result.offer_evidence.conflict, "seller_boilerplate");
});

test("fails closed when Amazon has only buying choices", () => {
  const structured = {
    "@type": "Product",
    name: "AMD Ryzen 7 Desktop Processor",
    offers: {
      "@type": "Offer",
      price: "249.99",
      priceCurrency: "USD",
      availability: "https://schema.org/InStock",
      seller: { name: "Secondary Seller" },
      itemCondition: "https://schema.org/UsedCondition"
    }
  };
  const fixture = amazonFixture({
    buyingChoices: true,
    price: null,
    merchant: "",
    profileText: "",
    condition: "",
    jsonLd: structured
  });

  const result = withPage(fixture.document, fixture.location, () => extract());

  assert.equal(result.offer_scope, "none");
  assert.equal(result.purchasability, "buying_choices_only");
  assert.equal(result.availability, "unknown");
  assert.deepEqual(result.candidates, []);
});

test("reads Newegg's product payment amount fallback", () => {
  const element = {
    getAttribute: (name) => name === "data-pp-amount" ? "129.99" : null,
    querySelector: () => null,
    textContent: ""
  };

  assert.equal(__testing.elementText(element), "129.99");
  assert.equal(parseAmount(__testing.elementText(element)), 129.99);
});

test("selects Newegg Buy New instead of used and marketplace prices", () => {
  const pane = (label, price, selected = false, seller = "Newegg") => ({
    querySelector: (selector) => selector === ".form-radiobox-title"
      ? { textContent: label }
      : selector.includes("seller-name")
        ? { textContent: seller }
        : selector.includes("fulfillment-message")
          ? { textContent: `Shipped by ${seller}` }
      : selected && selector.includes("aria-checked")
        ? { textContent: label }
        : null,
    querySelectorAll: () => [{
      getAttribute: () => null,
      querySelector: () => null,
      textContent: price
    }]
  });
  const buyBox = {
    querySelectorAll: () => [pane("Buy Used", "$999.49", true), pane("Buy New", "$1,399.99")],
    querySelector: (selector) => selector.includes("button")
      ? { disabled: false, getAttribute: () => null, textContent: "Add to cart" }
      : null,
    textContent: "Buy Used $999.49 Buy New $1,399.99 Add to cart"
  };
  const fixtureDocument = {
    querySelector: (selector) => selector === ".product-buy-box" ? buyBox : null
  };

  const result = __testing.neweggPrimaryOffer(fixtureDocument);

  assert.equal(result.price, 1399.99);
  assert.equal(result.raw, "$1,399.99");
  assert.equal(result.availability, "in_stock");
  assert.equal(result.observation.seller, "Newegg");
  assert.equal(result.observation.seller_type, "retailer");
  assert.equal(result.observation.condition, "new");
  assert.equal(result.observation.evidence_quality, "reliable");
  assert.equal(detectComponentType("Samsung 870 QVO 8TB SATA SSD"), "ssd");
});

test("keeps a Newegg marketplace New offer non-retailer", () => {
  const pane = {
    textContent: "Buy New $1,299.99 Sold by SenyTech Global",
    querySelector: (selector) => {
      if (selector === ".form-radiobox-title") return { textContent: "Buy New" };
      if (selector.includes("seller-name")) return { textContent: "SenyTech Global" };
      if (selector.includes("fulfillment-message")) return { textContent: "Shipped by SenyTech Global" };
      return null;
    },
    querySelectorAll: () => [{
      getAttribute: () => null,
      querySelector: () => null,
      textContent: "$1,299.99"
    }]
  };
  const buyBox = {
    textContent: pane.textContent,
    querySelectorAll: () => [pane],
    querySelector: (selector) => selector.includes("button")
      ? { disabled: false, getAttribute: () => null, textContent: "Add to cart" }
      : null
  };

  const result = __testing.neweggPrimaryOffer({
    querySelector: (selector) => selector === ".product-buy-box" ? buyBox : null
  });

  assert.equal(result.observation.condition, "new");
  assert.equal(result.observation.seller_type, "marketplace");
  assert.equal(result.observation.evidence_quality, "reliable");
});

test("uses Newegg's sole server-rendered pane as the active new offer", () => {
  const pane = {
    querySelector: () => null,
    querySelectorAll: () => [{
      getAttribute: () => null,
      querySelector: () => null,
      textContent: "$1,399.99"
    }],
    textContent: "$1,399.99"
  };
  const buyBox = {
    querySelectorAll: () => [pane],
    querySelector: (selector) => selector.includes("button")
      ? { disabled: false, getAttribute: () => null, textContent: "Add to cart" }
      : null
  };

  const result = __testing.neweggPrimaryOffer({
    querySelector: (selector) => selector === ".product-buy-box" ? buyBox : null
  });

  assert.equal(result.price, 1399.99);
  assert.equal(result.availability, "in_stock");
});

test("keeps Newegg used-offer metadata tied to the selected offer pane", () => {
  const pane = {
    textContent: "Buy Used $899.49 Sold by UsedTech Shipped by UsedTech",
    querySelector: (selector) => {
      if (selector === ".form-radiobox-title") return { textContent: "Buy Used" };
      if (selector.includes("seller-name")) return { textContent: "UsedTech" };
      if (selector.includes("fulfillment-message")) return { textContent: "Shipped by UsedTech" };
      return null;
    },
    querySelectorAll: () => [{
      getAttribute: () => null,
      querySelector: () => null,
      textContent: "$899.49"
    }]
  };
  const buyBox = {
    textContent: pane.textContent,
    querySelectorAll: () => [pane],
    querySelector: (selector) => selector.includes("button")
      ? { disabled: false, getAttribute: () => null, textContent: "Add to cart" }
      : null
  };

  const result = __testing.neweggPrimaryOffer({
    querySelector: (selector) => selector === ".product-buy-box" ? buyBox : null
  });

  assert.equal(result.price, 899.49);
  assert.equal(result.observation.seller, "UsedTech");
  assert.equal(result.observation.seller_type, "marketplace");
  assert.equal(result.observation.condition, "used");
  assert.equal(result.observation.offer_scope, "primary");
  assert.equal(result.observation.purchasability, "active");
  assert.equal(result.observation.fulfillment_type, "seller");
});

test("extracts Walmart marketplace metadata from the same current product", () => {
  const fixture = walmartFixture({ seller: "Pixel Depot" });

  const result = withPage(fixture.document, fixture.location, () => extract());

  assert.equal(result.seller, "Pixel Depot");
  assert.equal(result.seller_type, "marketplace");
  assert.equal(result.condition, "new");
  assert.equal(result.offer_scope, "primary");
  assert.equal(result.purchasability, "active");
  assert.equal(result.fulfillment_type, "platform");
  assert.equal(result.evidence_quality, "reliable");
  assert.equal(result.bundle, false);
  assert.equal(result.offer_evidence.source, "walmart_next_data");
  assert.deepEqual(result.candidates, [{
    price: 599.99,
    currency: "USD",
    source: "walmart_current_offer",
    confidence: 0.995
  }]);
});

test("classifies Walmart first-party New and fails closed when offer context is unknown", () => {
  const firstParty = walmartFixture();
  const trusted = withPage(firstParty.document, firstParty.location, () => extract());
  assert.equal(trusted.seller_type, "retailer");
  assert.equal(trusted.condition, "new");
  assert.equal(trusted.evidence_quality, "reliable");

  const unknown = walmartFixture({ seller: "", condition: "", fulfillment: "", price: null });
  const uncertain = withPage(unknown.document, unknown.location, () => extract());
  assert.equal(uncertain.seller_type, "unknown");
  assert.equal(uncertain.marketplace, null);
  assert.equal(uncertain.condition, "unknown");
  assert.equal(uncertain.evidence_quality, "ambiguous");
  assert.deepEqual(uncertain.candidates, []);
});

test("builds a valid Newegg SSD Browser Radar discovery", () => {
  const title = "SAMSUNG 870 QVO SATA III SSD 8TB 2.5-inch Internal Solid State Drive";
  const priceElement = {
    getAttribute: () => null,
    querySelector: () => null,
    textContent: "$1,399.99",
    parentElement: null
  };
  const pane = {
    querySelector: () => null,
    querySelectorAll: () => [priceElement],
    textContent: "$1,399.99"
  };
  const buyButton = {
    disabled: false,
    getAttribute: () => null,
    textContent: "Add to cart"
  };
  const buyBox = {
    querySelectorAll: () => [pane],
    querySelector: (selector) => selector.includes("button") ? buyButton : null
  };
  const fixtureDocument = {
    title,
    querySelector: (selector) => {
      if (selector === ".product-buy-box") return buyBox;
      if (selector.includes("product:price:currency")) return { content: "USD" };
      if (selector === "meta[property='og:title']") return { content: title };
      return null;
    },
    querySelectorAll: (selector) => selector === ".product-buy-box .price-current_2026"
      ? [priceElement]
      : []
  };
  const previousDocument = globalThis.document;
  const previousLocation = globalThis.location;
  globalThis.document = fixtureDocument;
  globalThis.location = {
    hostname: "www.newegg.com",
    href: "https://www.newegg.com/samsung-8tb-870-qvo-series-sata/p/N82E16820147784"
  };

  try {
    const result = extract();

    assert.equal(result.title, title);
    assert.equal(result.component_type, "ssd");
    assert.equal(result.supported_product_page, true);
    assert.equal(result.availability, "in_stock");
    assert.deepEqual(result.candidates, [{
      price: 1399.99,
      currency: "USD",
      source: "newegg_buy_new",
      confidence: 0.995
    }]);
  } finally {
    if (previousDocument === undefined) delete globalThis.document;
    else globalThis.document = previousDocument;
    if (previousLocation === undefined) delete globalThis.location;
    else globalThis.location = previousLocation;
  }
});

test("preserves typed JSON-LD identifiers and the legacy part number", () => {
  const product = {
    "@context": "https://schema.org",
    "@type": "Product",
    name: "Samsung 870 QVO 8TB SATA SSD",
    brand: { "@type": "Brand", name: "Samsung" },
    mpn: "MZ-77Q8T0B/AM",
    model: "870 QVO",
    sku: "N82E16820147784",
    offers: {
      "@type": "Offer",
      price: "599.99",
      priceCurrency: "USD",
      availability: "https://schema.org/InStock"
    }
  };
  const fixtureDocument = {
    title: product.name,
    querySelector: () => null,
    querySelectorAll: (selector) => selector === "script[type='application/ld+json']"
      ? [{ textContent: JSON.stringify(product) }]
      : []
  };
  const previousDocument = globalThis.document;
  const previousLocation = globalThis.location;
  globalThis.document = fixtureDocument;
  globalThis.location = {
    hostname: "www.newegg.com",
    href: "https://www.newegg.com/p/N82E16820147784"
  };

  try {
    const result = extract();

    assert.equal(result.mpn, "MZ-77Q8T0B/AM");
    assert.equal(result.model, "870 QVO");
    assert.equal(result.sku, "N82E16820147784");
    assert.equal(result.part_number, "MZ-77Q8T0B/AM");
  } finally {
    if (previousDocument === undefined) delete globalThis.document;
    else globalThis.document = previousDocument;
    if (previousLocation === undefined) delete globalThis.location;
    else globalThis.location = previousLocation;
  }
});

test("recognizes supported component product pages", () => {
  assert.equal(isProductPage("https://www.newegg.com/p/9SIC3U3KN44182"), true);
  assert.equal(isProductPage("https://www.amazon.com/dp/B0D1234567?tag=example"), true);
  assert.equal(isProductPage("https://www.newegg.com/p/pl?d=ryzen"), false);
});

test("classifies the extended component catalog without collecting accessories", () => {
  assert.equal(detectComponentType("AMD Ryzen 5 5600 Desktop Processor"), "cpu");
  assert.equal(detectComponentType("ASUS GeForce RTX 5070 12GB Graphics Card"), "gpu");
  assert.equal(detectComponentType("MSI B550M PRO-VDH WiFi Motherboard for AMD Ryzen 5000"), "motherboard");
  assert.equal(detectComponentType("MSI B550M PRO-VDH WiFi Placa base AMD Ryzen 5000"), "motherboard");
  assert.equal(detectComponentType("Corsair Vengeance 32GB DDR5 Desktop Memory"), "ram");
  assert.equal(detectComponentType("Samsung 990 Pro 2TB NVMe SSD"), "ssd");
  assert.equal(detectComponentType("KOOTION SSD NVMe M.2 PCIe 2280 de 256GB Compatible con laptop y PC de escritorio"), "ssd");
  assert.equal(detectComponentType("Crucial 32GB DDR5 SODIMM Laptop Memory"), "ram");
  assert.equal(detectComponentType("WD Blue 4TB HDD Hard Drive"), "hdd");
  assert.equal(detectComponentType("Seagate FireCuda 2TB SSHD Solid State Hybrid Drive"), "sshd");
  assert.equal(detectComponentType("Thermalright Peerless Assassin 120 SE CPU Air Cooler"), "cpu_cooler");
  assert.equal(detectComponentType("Corsair 4000D Airflow ATX Mid-Tower PC Case"), "pc_case");
  assert.equal(detectComponentType("Corsair RM850x 850W Power Supply"), "psu");
  assert.equal(detectComponentType("Gaming PC Desktop Computer with Ryzen and Radeon"), null);
  assert.equal(detectComponentType("PCIe graphics card support holder"), null);
  assert.equal(detectComponentType("120mm RGB case fan replacement"), null);
});
