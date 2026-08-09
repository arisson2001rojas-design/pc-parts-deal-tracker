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

test("classifies components without collecting complete computers", () => {
  assert.equal(detectComponentType("AMD Ryzen 5 5600 Desktop Processor"), "cpu");
  assert.equal(detectComponentType("ASUS GeForce RTX 5070 12GB Graphics Card"), "gpu");
  assert.equal(detectComponentType("Corsair RM850x 850W Power Supply"), "psu");
  assert.equal(detectComponentType("Gaming PC Desktop Computer with Ryzen and Radeon"), null);
  assert.equal(detectComponentType("B650 Motherboard with DDR5 Memory"), null);
  assert.equal(detectComponentType("CPU Air Cooler compatible with AMD Ryzen"), null);
  assert.equal(detectComponentType("PCIe graphics card support holder"), null);
});
