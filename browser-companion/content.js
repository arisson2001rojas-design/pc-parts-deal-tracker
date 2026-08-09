"use strict";

(() => {
  const params = new URLSearchParams(location.hash.slice(1));
  const encodedEndpoint = params.get("pricebuddy");
  if (!encodedEndpoint) return;

  // Remove the short-lived local signature before retailer scripts can retain it.
  params.delete("pricebuddy");
  const remainingHash = params.toString();
  history.replaceState(
    null,
    "",
    `${location.pathname}${location.search}${remainingHash ? `#${remainingHash}` : ""}`
  );

  let endpoint;
  try {
    const padded = encodedEndpoint.replaceAll("-", "+").replaceAll("_", "/")
      .padEnd(Math.ceil(encodedEndpoint.length / 4) * 4, "=");
    endpoint = atob(padded);
  } catch (_error) {
    return;
  }

  function toast(message, state = "working") {
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
  }

  function send(payload) {
    return new Promise((resolve) => {
      chrome.runtime.sendMessage({ type: "pricebuddy:capture", endpoint, payload }, (response) => {
        if (chrome.runtime.lastError) {
          resolve({ ok: false, message: chrome.runtime.lastError.message });
        } else {
          resolve(response || { ok: false, message: "PriceBuddy no respondió." });
        }
      });
    });
  }

  async function run() {
    toast("PriceBuddy está comprobando el precio visible…");
    for (const wait of [800, 2200, 4500]) {
      await new Promise((resolve) => setTimeout(resolve, wait));
      const payload = globalThis.PriceBuddyExtractor.extract();
      if (!payload.title || payload.candidates.length === 0) continue;
      const response = await send(payload);
      if (response.ok) {
        const price = Number(response.data?.price).toLocaleString("en-US", {
          style: "currency",
          currency: "USD"
        });
        toast(`Precio confirmado: ${price}. Ya aparece en PriceBuddy.`, "success");
        return;
      }
      if (response.message && !/No plausible|found/i.test(response.message)) {
        toast(response.message, "error");
        return;
      }
    }
    toast("No encontré un precio confiable. Confirma que sea la página del producto y que muestre USD.", "error");
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", run, { once: true });
  } else {
    run();
  }
})();
