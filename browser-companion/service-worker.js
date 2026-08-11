"use strict";

const DISCOVERY_ENDPOINT = "http://127.0.0.1:8281/api/browser-discoveries";
const RETAILER_DOMAINS = [
  "amazon.com",
  "walmart.com",
  "microcenter.com",
  "newegg.com",
  "bestbuy.com",
  "gamestop.com"
];

const inFlightDiscoveries = new Set();

function storageGet(defaults) {
  return new Promise((resolve) => chrome.storage.local.get(defaults, resolve));
}

function storageSet(values) {
  return new Promise((resolve) => chrome.storage.local.set(values, resolve));
}

function supportedRetailer(hostname) {
  const host = String(hostname || "").toLowerCase().replace(/^www\./, "");
  return RETAILER_DOMAINS.some((domain) => host === domain || host.endsWith(`.${domain}`));
}

async function postJson(endpoint, payload) {
  const response = await fetch(endpoint, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      "Accept": "application/json",
      "X-PriceBuddy-Companion": "1"
    },
    body: JSON.stringify(payload),
    credentials: "omit"
  });
  const body = await response.json().catch(() => ({}));
  if (!response.ok) {
    const errors = body?.errors && Object.values(body.errors).flat();
    const error = new Error(errors?.[0] || body?.message || "PriceBuddy no pudo guardar el precio.");
    error.status = response.status;
    throw error;
  }
  return body.data;
}

async function manualCapture(message) {
  let endpoint;
  try {
    endpoint = new URL(message.endpoint);
  } catch (_error) {
    return { ok: false, message: "Enlace de verificaciÃ³n invÃ¡lido." };
  }

  const localHost = endpoint.hostname === "localhost" || endpoint.hostname === "127.0.0.1";
  if (!localHost || endpoint.protocol !== "http:" || !endpoint.pathname.startsWith("/api/browser-capture/")) {
    return { ok: false, message: "PriceBuddy rechazÃ³ un destino que no es local." };
  }

  try {
    return { ok: true, data: await postJson(endpoint.toString(), message.payload) };
  } catch (error) {
    return { ok: false, message: error.message };
  }
}

async function passiveDiscovery(message, sender) {
  let senderUrl;
  let pageUrl;
  try {
    senderUrl = new URL(sender.url || "");
    pageUrl = new URL(message.payload?.page_url || "");
  } catch (_error) {
    return { ok: false, message: "PÃ¡gina de producto invÃ¡lida." };
  }

  if (senderUrl.protocol !== "https:" || pageUrl.protocol !== "https:"
      || senderUrl.hostname !== pageUrl.hostname || !supportedRetailer(pageUrl.hostname)) {
    return { ok: false, message: "PriceBuddy rechazÃ³ una captura fuera de las tiendas permitidas." };
  }

  const price = Number(message.payload?.candidates?.[0]?.price || 0);
  const pageKey = `${pageUrl.origin}${pageUrl.pathname}`;
  const discoveryKey = `${pageKey}|${price}`;

  if (inFlightDiscoveries.has(discoveryKey)) {
    return { ok: true, skipped: true, reason: "in_flight" };
  }

  inFlightDiscoveries.add(discoveryKey);

  try {
    const now = Date.now();
    const state = await storageGet({ recentDiscoveries: [], capturedCount: 0 });
    const recent = state.recentDiscoveries
      .filter((item) => now - Number(item.at || 0) < 30 * 60 * 1000)
      .slice(0, 99);

    if (recent.some((item) => item.key === pageKey && Number(item.price) === price)) {
      return { ok: true, skipped: true, reason: "recent" };
    }

    try {
      const data = await postJson(DISCOVERY_ENDPOINT, message.payload);
      recent.unshift({ key: pageKey, price, at: now });
      await storageSet({
        recentDiscoveries: recent,
        capturedCount: Number(state.capturedCount || 0) + 1,
        lastDiscovery: {
          title: String(message.payload.title || "").slice(0, 120),
          price: Number(data.price),
          componentType: data.component_type,
          at: now
        }
      });
      return { ok: true, data };
    } catch (error) {
      if (error.status === 422) return { ok: true, ignored: true };
      return { ok: false, message: error.message };
    }
  } finally {
    inFlightDiscoveries.delete(discoveryKey);
  }
}

chrome.runtime.onInstalled.addListener(() => {
  chrome.storage.local.get(["radarEnabled", "radarNotifications"], (settings) => {
    chrome.storage.local.set({
      radarEnabled: settings.radarEnabled ?? true,
      radarNotifications: settings.radarNotifications ?? true
    });
  });
});

chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
  let operation;
  if (message?.type === "pricebuddy:capture") {
    operation = manualCapture(message);
  } else if (message?.type === "pricebuddy:discover") {
    operation = passiveDiscovery(message, sender);
  } else {
    return false;
  }

  operation.then(sendResponse).catch((error) => sendResponse({ ok: false, message: error.message }));
  return true;
});
