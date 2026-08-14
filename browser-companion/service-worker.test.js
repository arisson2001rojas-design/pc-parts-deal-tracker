import assert from "node:assert/strict";
import test from "node:test";

const stored = {};
let messageListener;
let fetchCalls = 0;
const fetchPayloads = [];

globalThis.chrome = {
  storage: {
    local: {
      get: (defaults, callback) => callback({ ...defaults, ...stored }),
      set: (values, callback = () => {}) => {
        Object.assign(stored, values);
        callback();
      }
    }
  },
  runtime: {
    onInstalled: { addListener: () => {} },
    onMessage: {
      addListener: (listener) => { messageListener = listener; }
    }
  }
};

globalThis.fetch = async (url, options) => {
  fetchCalls += 1;
  const payload = JSON.parse(options.body);
  fetchPayloads.push(payload);
  assert.equal(url, "http://127.0.0.1:8381/api/browser-discoveries");
  assert.equal(options.credentials, "omit");
  assert.equal(options.headers["X-PriceBuddy-Companion"], "1");
  return {
    ok: true,
    status: 201,
    json: async () => ({
      data: {
        component_type: "cpu",
        price: payload.candidates?.length ? 129.99 : null,
        currency: "USD",
        stored: !payload.page_url.includes("not-stored")
      }
    })
  };
};

await import("./service-worker.js");

function dispatch(message, senderUrl) {
  return new Promise((resolve) => {
    const asyncResponse = messageListener(message, { url: senderUrl }, resolve);
    assert.equal(asyncResponse, true);
  });
}

test("passive radar sends a supported product once and deduplicates it", async () => {
  const payload = {
    page_url: "https://www.newegg.com/p/9SIC3U3KN44182",
    title: "AMD Ryzen 5 5600 Desktop Processor",
    candidates: [{ price: 129.99, currency: "USD", source: "site_specific", confidence: 0.96 }]
  };

  const first = await dispatch(
    { type: "pricebuddy:discover", payload },
    "https://www.newegg.com/p/9SIC3U3KN44182"
  );
  assert.equal(first.ok, true);
  assert.equal(stored.capturedCount, 1);
  assert.equal(fetchCalls, 1);

  const duplicate = await dispatch(
    { type: "pricebuddy:discover", payload },
    "https://www.newegg.com/p/9SIC3U3KN44182"
  );
  assert.equal(duplicate.skipped, true);
  assert.equal(fetchCalls, 1);
});

test("passive radar coalesces simultaneous discoveries for the same URL and price", async () => {
  const before = fetchCalls;
  const payload = {
    page_url: "https://www.amazon.com/dp/B0D7654321",
    title: "AMD Ryzen 7 Desktop Processor",
    candidates: [{ price: 249.99, currency: "USD", source: "amazon_primary", confidence: 0.99 }]
  };

  const [first, second] = await Promise.all([
    dispatch({ type: "pricebuddy:discover", payload }, "https://www.amazon.com/dp/B0D7654321"),
    dispatch({ type: "pricebuddy:discover", payload }, "https://www.amazon.com/dp/B0D7654321")
  ]);

  assert.equal(first.ok, true);
  assert.equal(second.ok, true);
  assert.equal(fetchCalls, before + 1);
  assert.ok(
    [first, second].some((response) => response.skipped === true && response.reason === "in_flight")
  );
});

test("same-price offer-integrity changes are not deduplicated", async () => {
  const before = fetchCalls;
  const base = {
    page_url: "https://www.amazon.com/dp/B0D1111111",
    title: "NVIDIA GeForce RTX 5070 Graphics Card",
    availability: "in_stock",
    seller: "Pixel Depot",
    seller_type: "marketplace",
    marketplace: true,
    condition: "new",
    offer_scope: "primary",
    purchasability: "active",
    fulfillment_type: "platform",
    evidence_quality: "reliable",
    bundle: null,
    offer_evidence: {
      source: "amazon_buy_box",
      seller_source: "amazon_merchant_info",
      condition_source: "amazon_primary_default",
      fulfillment_source: "amazon_merchant_info",
      conflict: null
    },
    candidates: [{ price: 599.99, currency: "USD", source: "amazon_primary", confidence: 0.99 }]
  };

  const first = await dispatch(
    { type: "pricebuddy:discover", payload: base },
    base.page_url
  );
  const harmless = await dispatch({
    type: "pricebuddy:discover",
    payload: { ...base, title: `${base.title} - updated`, image_url: "https://example.com/new.jpg" }
  }, base.page_url);
  const changed = await dispatch({
    type: "pricebuddy:discover",
    payload: {
      ...base,
      seller: "UsedTech",
      condition: "used",
      fulfillment_type: "seller"
    }
  }, base.page_url);

  assert.equal(first.ok, true);
  assert.equal(harmless.skipped, true);
  assert.equal(changed.ok, true);
  assert.equal(fetchCalls, before + 2);
  assert.equal(fetchPayloads.at(-1).seller, "UsedTech");
  assert.equal(fetchPayloads.at(-1).condition, "used");
});

test("passive radar posts a normalized candidate-less unavailable observation", async () => {
  const before = fetchCalls;
  const payload = {
    page_url: "https://www.gamestop.com/products/example/123456.html",
    title: "Samsung 990 Pro 2TB NVMe SSD",
    availability: "out_of_stock",
    seller: null,
    seller_type: "unknown",
    marketplace: false,
    condition: "unknown",
    offer_scope: "none",
    purchasability: "unavailable",
    fulfillment_type: "unknown",
    evidence_quality: "ambiguous",
    bundle: null,
    offer_evidence: {
      source: "generic",
      seller_source: "unknown",
      condition_source: "unknown",
      fulfillment_source: "unknown",
      conflict: null
    },
    candidates: []
  };

  const response = await dispatch(
    { type: "pricebuddy:discover", payload },
    payload.page_url
  );

  assert.equal(response.ok, true);
  assert.equal(fetchCalls, before + 1);
  assert.deepEqual(fetchPayloads.at(-1), payload);
  assert.equal(stored.lastDiscovery.price, null);
});

test("metadata-only observations that the backend cannot attach are not counted or cached", async () => {
  const beforeFetches = fetchCalls;
  const beforeCaptured = stored.capturedCount;
  const payload = {
    page_url: "https://www.walmart.com/ip/not-stored/987654321",
    title: "AMD Ryzen 5 Desktop Processor",
    availability: "out_of_stock",
    seller: null,
    seller_type: "unknown",
    marketplace: false,
    condition: "unknown",
    offer_scope: "none",
    purchasability: "unavailable",
    fulfillment_type: "unknown",
    evidence_quality: "ambiguous",
    bundle: null,
    offer_evidence: {
      source: "generic",
      seller_source: "unknown",
      condition_source: "unknown",
      fulfillment_source: "unknown",
      conflict: null
    },
    candidates: []
  };

  const first = await dispatch({ type: "pricebuddy:discover", payload }, payload.page_url);
  const second = await dispatch({ type: "pricebuddy:discover", payload }, payload.page_url);

  assert.equal(first.ignored, true);
  assert.equal(first.reason, "not_stored");
  assert.equal(second.ignored, true);
  assert.equal(fetchCalls, beforeFetches + 2);
  assert.equal(stored.capturedCount, beforeCaptured);
});

test("passive radar rejects a payload that does not match the sender page", async () => {
  const before = fetchCalls;
  const response = await dispatch({
    type: "pricebuddy:discover",
    payload: {
      page_url: "https://www.amazon.com/dp/B0D1234567",
      title: "AMD Ryzen Processor",
      candidates: [{ price: 99.99 }]
    }
  }, "https://www.newegg.com/p/9SIC3U3KN44182");

  assert.equal(response.ok, false);
  assert.equal(fetchCalls, before);
});
