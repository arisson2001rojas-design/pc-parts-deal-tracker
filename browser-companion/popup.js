"use strict";

const radarEnabled = document.getElementById("radarEnabled");
const radarNotifications = document.getElementById("radarNotifications");
const status = document.getElementById("status");
const count = document.getElementById("count");
const last = document.getElementById("last");
const defaults = {
  radarEnabled: true,
  radarNotifications: true,
  capturedCount: 0,
  lastDiscovery: null
};

function render(settings) {
  radarEnabled.checked = settings.radarEnabled;
  radarNotifications.checked = settings.radarNotifications;
  status.textContent = settings.radarEnabled ? "ACTIVO" : "PAUSADO";
  status.style.color = settings.radarEnabled ? "#5eead4" : "#cbd5e1";
  count.textContent = Number(settings.capturedCount || 0).toLocaleString("es");
  if (settings.lastDiscovery) {
    const price = Number(settings.lastDiscovery.price).toLocaleString("en-US", {
      style: "currency",
      currency: "USD"
    });
    last.textContent = `${String(settings.lastDiscovery.componentType || "PC").toUpperCase()} · ${price}`;
    last.title = settings.lastDiscovery.title || "";
  }
}

chrome.storage.local.get(defaults, render);

radarEnabled.addEventListener("change", () => {
  chrome.storage.local.set({ radarEnabled: radarEnabled.checked }, () => {
    chrome.storage.local.get(defaults, render);
  });
});

radarNotifications.addEventListener("change", () => {
  chrome.storage.local.set({ radarNotifications: radarNotifications.checked });
});

document.getElementById("openPriceBuddy").addEventListener("click", () => {
  chrome.tabs.create({ url: "http://127.0.0.1:8281/admin/deal-offers" });
});
