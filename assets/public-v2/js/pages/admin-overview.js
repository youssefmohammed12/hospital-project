/*
 * HealthBridge Admin Overview — Public V2 page script.
 * Phase 0: the page body is an interim placeholder; the analytics
 * build arrives in Phase 1. What already works is the landing
 * behaviour: the admin's saved default-tab preference
 * (settings → admin_default_tab, one of the values the settings
 * endpoint accepts) is honoured once per session — the first landing
 * on the Overview in a session forwards to the preferred section,
 * and later explicit visits to the Overview stay put.
 * Depends on the Public V2 shared site.js runtime.
 */
"use strict";

(() => {
  const TAB_PAGES = {
    overview: "admin-dashboard.html",
    doctors: "doctors.html",
    patients: "patients.html",
    appointments: "appointments.html",
    "support-messages": "support-messages.html",
  };
  const FLAG = "hb_admin_landing_done";

  document.addEventListener("admin:shell-ready", async () => {
    let already;
    try { already = sessionStorage.getItem(FLAG); } catch { already = "1"; }
    if (already) return;
    try { sessionStorage.setItem(FLAG, "1"); } catch { /* storage off: redirect at most once per load */ }

    try {
      const ROOT = typeof getBasePath === "function" ? getBasePath() : "../../";
      const response = await fetch(ROOT + "api/settings/get.php", {
        method: "GET", credentials: "same-origin", headers: { Accept: "application/json" },
      });
      const payload = await response.json().catch(() => null);
      const tab = payload?.success ? payload.preferences?.admin_default_tab : null;
      const target = typeof tab === "string" ? TAB_PAGES[tab] : null;
      if (target && target !== "admin-dashboard.html") {
        window.location.assign(target);
      }
    } catch { /* preference unreadable: stay on the Overview */ }
  });
})();
