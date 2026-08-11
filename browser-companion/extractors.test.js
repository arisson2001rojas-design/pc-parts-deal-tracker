import assert from "node:assert/strict";
import test from "node:test";

await import("./extractors.js");

const { __testing, detectComponentType, isProductPage, parseAmount } = globalThis.PriceBuddyExtractor;

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
