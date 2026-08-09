import assert from "node:assert/strict";
import test from "node:test";

await import("./extractors.js");

const { __testing, parseAmount } = globalThis.PriceBuddyExtractor;

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
