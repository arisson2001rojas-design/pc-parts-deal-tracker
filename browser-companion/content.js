"use strict";

(() => {
  const params = new URLSearchParams(location.hash.slice(1));
  const encodedEndpoint = params.get("pricebuddy");
  let manualEndpoint = null;
  let manualStarted = false;
  let activeDiscoveryUrl = null;
  let discoveryInFlight = false;
  let retryTimer = null;
  let mutationTimer = null;
  let rescanPending = false;
  let productObserver = null;

  const PRODUCT_CHANGE_SELECTOR = [
    "#productTitle",
    "#corePrice_feature_div",
    "#corePriceDisplay_desktop_feature_div",
    "#apex_offerDisplay_desktop",
    "#apex_price",
    "#availability",
    "#sellerProfileTriggerId",
    "#merchant-info",
    "#tabular-buybox",
    "#buybox-see-all-buying-choices",
    "#add-to-cart-button",
    "#buy-now-button",
    "#condition",
    "#condition-value",
    "#offerCondition",
    "#__NEXT_DATA__",
    "script[type='application/ld+json']",
    "#twister",
    "[id*='variation']",
    ".product-buy-box",
    ".product-pane",
    ".price-current",
    ".price-current_2026",
    "[data-automation-id='product-price']",
    "[data-testid='product-price']",
    "[data-automation-id='seller-name']",
    "[data-testid='seller-name']",
    "[data-testid='marketplace-seller-name']",
    "[data-automation-id='condition']",
    "[data-testid='condition']",
    "[data-automation-id='fulfillment-shipping']",
    ".product-seller",
    ".product-seller-info",
    "[itemprop='seller']",
    "[itemprop='itemCondition']",
    "[itemprop='price']"
  ].join(", ");

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

  function pageKey() {
    return location.href.split("#")[0];
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

  function hasDiscoveryObservation(payload) {
    if (payload.candidates?.length > 0) return true;
    return payload.offer_scope && payload.offer_scope !== "unknown"
      && payload.purchasability && payload.purchasability !== "unknown"
      && ["reliable", "ambiguous", "invalid"].includes(payload.evidence_quality);
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
    if (!options.radarEnabled || !globalThis.PriceBuddyExtractor.isProductPage()) return true;

    for (const wait of [0, 900, 2200, 4500]) {
      if (wait > 0) await new Promise((resolve) => setTimeout(resolve, wait));
      const payload = globalThis.PriceBuddyExtractor.extract();
      if (!payload.supported_product_page || !payload.component_type || !hasDiscoveryObservation(payload)) continue;

      const response = await send({ type: "pricebuddy:discover", payload });
      if (response.ok && !response.skipped && !response.ignored && options.radarNotifications) {
        if (response.data?.price === null || response.data?.price === undefined) {
          toast(`Radar PriceBuddy: estado de ${payload.component_type.toUpperCase()} guardado sin precio activo.`, "success", true);
        } else {
          const price = Number(response.data.price).toLocaleString("en-US", {
            style: "currency",
            currency: "USD"
          });
          toast(`Radar PriceBuddy: ${payload.component_type.toUpperCase()} guardado a ${price}.`, "success", true);
        }
      }
      return response.ok;
    }

    return false;
  }

  function schedulePassiveDiscovery(force = false) {
    if (manualEndpoint || discoveryInFlight) {
      if (force) rescanPending = true;
      return;
    }

    const currentUrl = pageKey();
    if (!force && currentUrl === activeDiscoveryUrl) return;

    activeDiscoveryUrl = currentUrl;
    discoveryInFlight = true;
    if (retryTimer) clearTimeout(retryTimer);

    passiveDiscovery()
      .then((handled) => {
        discoveryInFlight = false;

        if (pageKey() !== currentUrl) {
          activeDiscoveryUrl = null;
          rescanPending = false;
          schedulePassiveDiscovery();
          return;
        }

        if (rescanPending) {
          rescanPending = false;
          requestPassiveDiscovery(500);
          return;
        }

        if (!handled) {
          retryTimer = setTimeout(() => {
            if (pageKey() === currentUrl) {
              activeDiscoveryUrl = null;
              schedulePassiveDiscovery();
            }
          }, 8000);
        }
      })
      .catch(() => {
        discoveryInFlight = false;
        activeDiscoveryUrl = null;

        if (rescanPending) {
          rescanPending = false;
          requestPassiveDiscovery(800);
        }
      });
  }

  function requestPassiveDiscovery(delay = 750) {
    if (manualEndpoint) return;

    rescanPending = true;
    if (mutationTimer) return;

    mutationTimer = setTimeout(() => {
      mutationTimer = null;

      if (discoveryInFlight) {
        requestPassiveDiscovery(700);
        return;
      }

      if (!rescanPending) return;
      rescanPending = false;
      schedulePassiveDiscovery(true);
    }, delay);
  }

  function productNodeChanged(node) {
    const element = node?.nodeType === Node.ELEMENT_NODE
      ? node
      : node?.parentElement;

    if (!element) return false;

    return Boolean(
      element.matches?.(PRODUCT_CHANGE_SELECTOR)
      || element.closest?.(PRODUCT_CHANGE_SELECTOR)
      || element.querySelector?.(PRODUCT_CHANGE_SELECTOR)
    );
  }

  function startProductObserver() {
    if (manualEndpoint || productObserver || !document.documentElement) return;

    productObserver = new MutationObserver((mutations) => {
      const relevant = mutations.some((mutation) => {
        if (productNodeChanged(mutation.target)) return true;

        return [...(mutation.addedNodes || [])].some(productNodeChanged);
      });

      if (relevant) {
        // Background tabs are intentionally supported. Calling the scheduler
        // directly lets PriceBuddy react to price/variant mutations without
        // waiting for the tab to become visible again.
        if (document.hidden) {
          schedulePassiveDiscovery(true);
        } else {
          requestPassiveDiscovery(650);
        }
      }
    });

    productObserver.observe(document.documentElement, {
      subtree: true,
      childList: true,
      characterData: true,
      attributes: true,
      attributeFilter: [
        "class",
        "style",
        "hidden",
        "aria-hidden",
        "aria-disabled",
        "aria-checked",
        "checked",
        "disabled",
        "href",
        "data-csa-c-asin",
        "data-automation-id",
        "data-testid",
        "content"
      ]
    });

    // Finite safety sweeps: cover late Amazon hydration without polling forever.
    setTimeout(() => schedulePassiveDiscovery(true), 12000);
    setTimeout(() => schedulePassiveDiscovery(true), 30000);
  }

  function run() {
    if (manualEndpoint) {
      if (!manualStarted) {
        manualStarted = true;
        manualCapture();
      }
      return;
    }

    schedulePassiveDiscovery();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", () => {
      run();
      startProductObserver();
    }, { once: true });
  } else {
    run();
    startProductObserver();
  }

  window.addEventListener("pageshow", () => {
    run();
    startProductObserver();
  });
  document.addEventListener("visibilitychange", () => {
    if (!document.hidden) {
      run();
      requestPassiveDiscovery(350);
    }
  });
  setInterval(() => {
    if (!manualEndpoint && pageKey() !== activeDiscoveryUrl) {
      schedulePassiveDiscovery();
    }
  }, 1000);
})();
