"use strict";

(() => {
  const params = new URLSearchParams(location.hash.slice(1));
  const encodedEndpoint = params.get("pricebuddy");
  let manualEndpoint = null;

  if (encodedEndpoint) {
    params.delete("pricebuddy");
    const remainingHash = params.toString();
    history.replaceState(
      null,
      "",
      `${location.pathname}${location.search}${remainingHash ? `#${remainingHash}` : ""}`
    );

    try {
      const padded = encodedEndpoint.replaceAll("-", "+").replaceAll("_", "/")
        .padEnd(Math.ceil(encodedEndpoint.length / 4) * 4, "=");
      manualEndpoint = atob(padded);
    } catch (_error) {
      manualEndpoint = null;
    }
  }

  function toast(message, state = "working", autoHide = false) {
    let element = document.getElementById("pricebuddy-capture-status");
    if (!element) {
      element = document.createElement("div");
      element.id = "pricebuddy-capture-status";
      Object.assign(element.style, {
        position: "fixed",
        top: "18px",
        right: "18px",
        zIndex: "2147483647",
        maxWidth: "380px",
        padding: "14px 18px",
        borderRadius: "12px",
        color: "#ecfeff",
        font: "600 14px/1.4 system-ui, sans-serif",
        boxShadow: "0 16px 40px rgba(0,0,0,.35)"
      });
      document.documentElement.appendChild(element);
    }
    element.style.background = state === "error" ? "#991b1b" : state === "success" ? "#065f46" : "#0f766e";
    element.textContent = message;
    if (autoHide) setTimeout(() => element.remove(), 4200);
  }

  function send(message) {
    return new Promise((resolve) => {
      chrome.runtime.sendMessage(message, (response) => {
        if (chrome.runtime.lastError) {
          resolve({ ok: false, message: chrome.runtime.lastError.message });
        } else {
          resolve(response || { ok: false, message: "PriceBuddy no respondió." });
        }
      });
    });
  }

  function settings() {
    return new Promise((resolve) => {
      chrome.storage.local.get({ radarEnabled: true, radarNotifications: true }, resolve);
    });
  }

  async function manualCapture() {
    toast("PriceBuddy está comprobando el precio visible…");
    for (const wait of [800, 2200, 4500]) {
      await new Promise((resolve) => setTimeout(resolve, wait));
      const payload = globalThis.PriceBuddyExtractor.extract();
      if (!payload.title || payload.candidates.length === 0) continue;
      const response = await send({
        type: "pricebuddy:capture",
        endpoint: manualEndpoint,
        payload
      });
      if (response.ok) {
        const price = Number(response.data?.price).toLocaleString("en-US", {
          style: "currency",
          currency: "USD"
        });
        toast(`Precio confirmado: ${price}. Ya aparece en PriceBuddy.`, "success");
        const options = await settings();
        if (options.radarEnabled && payload.component_type) {
          await send({ type: "pricebuddy:discover", payload });
        }
        return;
      }
      if (response.message && !/No plausible|found/i.test(response.message)) {
        toast(response.message, "error");
        return;
      }
    }
    toast("No encontré un precio confiable. Confirma que sea la página del producto y que muestre USD.", "error");
  }

  async function passiveDiscovery() {
    const options = await settings();
    if (!options.radarEnabled || !globalThis.PriceBuddyExtractor.isProductPage()) return;

    for (const wait of [1200, 3000, 5000]) {
      await new Promise((resolve) => setTimeout(resolve, wait));
      const payload = globalThis.PriceBuddyExtractor.extract();
      if (!payload.supported_product_page || !payload.component_type || payload.candidates.length === 0) continue;

      const response = await send({ type: "pricebuddy:discover", payload });
      if (response.ok && !response.skipped && !response.ignored && options.radarNotifications) {
        const price = Number(response.data?.price).toLocaleString("en-US", {
          style: "currency",
          currency: "USD"
        });
        toast(`Radar PriceBuddy: ${payload.component_type.toUpperCase()} guardado a ${price}.`, "success", true);
      }
      return;
    }
  }

  function run() {
    if (manualEndpoint) {
      manualCapture();
    } else {
      passiveDiscovery();
    }
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", run, { once: true });
  } else {
    run();
  }
})();
