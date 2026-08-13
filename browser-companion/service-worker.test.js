import assert from "node:assert/strict";
import test from "node:test";

const stored = {};
let messageListener;
let fetchCalls = 0;

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
  assert.equal(url, "http://127.0.0.1:8381/api/browser-discoveries");
  assert.equal(options.credentials, "omit");
  assert.equal(options.headers["X-PriceBuddy-Companion"], "1");
  return {
    ok: true,
    status: 201,
    json: async () => ({
      data: { component_type: "cpu", price: 129.99, currency: "USD" }
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
