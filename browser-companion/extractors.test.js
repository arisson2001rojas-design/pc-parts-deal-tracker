import assert from "node:assert/strict";
import test from "node:test";

await import("./extractors.js");

const { __testing, detectComponentType, extract, isProductPage, parseAmount } = globalThis.PriceBuddyExtractor;

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
  const pane = (label, price) => ({
    querySelector: (selector) => selector === ".form-radiobox-title"
      ? { textContent: label }
      : null,
    querySelectorAll: () => [{
      getAttribute: () => null,
      querySelector: () => null,
      textContent: price
    }]
  });
  const buyBox = {
    querySelectorAll: () => [pane("Buy Used", "$999.49"), pane("Buy New", "$1,399.99")],
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
  assert.equal(detectComponentType("Samsung 870 QVO 8TB SATA SSD"), "ssd");
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
