"use strict";

chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
  if (message?.type !== "pricebuddy:capture") {
    return false;
  }

  let endpoint;
  try {
    endpoint = new URL(message.endpoint);
  } catch (_error) {
    sendResponse({ ok: false, message: "Enlace de verificación inválido." });
    return false;
  }

  const localHost = endpoint.hostname === "localhost" || endpoint.hostname === "127.0.0.1";
  if (!localHost || endpoint.protocol !== "http:" || !endpoint.pathname.startsWith("/api/browser-capture/")) {
    sendResponse({ ok: false, message: "PriceBuddy rechazó un destino que no es local." });
    return false;
  }

  fetch(endpoint.toString(), {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      "Accept": "application/json",
      "X-PriceBuddy-Companion": "1"
    },
    body: JSON.stringify(message.payload),
    credentials: "omit"
  })
    .then(async (response) => {
      const body = await response.json().catch(() => ({}));
      if (!response.ok) {
        const errors = body?.errors && Object.values(body.errors).flat();
        throw new Error(errors?.[0] || body?.message || "No se pudo confirmar el precio.");
      }
      sendResponse({ ok: true, data: body.data });
    })
    .catch((error) => sendResponse({ ok: false, message: error.message }));

  return true;
});
