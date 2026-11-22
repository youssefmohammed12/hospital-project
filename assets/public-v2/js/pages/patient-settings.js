"use strict";

/*
 * HealthBridge Public V2 — Patient Settings account-data loader.
 * Read-only: retrieves the authenticated user's saved profile and
 * populates the existing Account fields. No updates or mutations.
 */
(() => {
  let started = false;

  const setValue = (id, value) => {
    const field = document.getElementById(id);
    if (field) field.value = value == null ? "" : String(value);
  };

  const formatDate = (value) => {
    if (!value) return "—";
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return String(value);
    return date.toLocaleDateString(undefined, {
      year: "numeric",
      month: "short",
      day: "numeric",
    });
  };

  async function loadPatientAccount() {
    if (started) return;
    started = true;

    const form = document.getElementById("settings-profile-form");
    if (form) form.setAttribute("aria-busy", "true");

    try {
      const base = typeof getBasePath === "function" ? getBasePath() : "../../";
      const response = await fetch(base + "api/settings/get.php", {
        method: "GET",
        credentials: "same-origin",
        headers: { Accept: "application/json" },
      });
      const result = await response.json().catch(() => null);

      if (!response.ok || !result?.success || !result.profile) {
        throw new Error("Your account details could not be loaded. Please refresh the page.");
      }

      const profile = result.profile;
      if (profile.role !== "patient") {
        throw new Error("This settings page is available to patient accounts only.");
      }

      setValue("settings-name", profile.name);
      setValue("settings-email", profile.email);
      setValue("settings-phone", profile.phone);
      setValue("settings-role", "Patient");
      setValue("settings-created", formatDate(profile.created_at));
      setValue("settings-status", profile.is_active ? "Active" : "Disabled");

      const preferences = result.preferences || {};
      const theme = ["light", "dark", "system"].includes(preferences.theme)
        ? preferences.theme
        : "system";
      document.querySelectorAll("#theme-options .theme-option").forEach((option) => {
        const selected = option.dataset.theme === theme;
        option.classList.toggle("selected", selected);
        const radio = option.querySelector('input[type="radio"]');
        if (radio) radio.checked = selected;
      });
    } catch (error) {
      const target = document.getElementById("settings-account-load-error");
      if (target) {
        target.hidden = false;
        target.textContent = error instanceof Error
          ? error.message
          : "Your account details could not be loaded.";
      } else if (typeof showToast === "function") {
        showToast(error.message || "Your account details could not be loaded.", "error");
      }
    } finally {
      if (form) {
        form.setAttribute("aria-busy", "false");
        form.dataset.accountLoaded = "true";
      }
    }
  }

  document.addEventListener("patient:shell-ready", loadPatientAccount, { once: true });
  if (document.querySelector(".main-content.patient-shell-ready") &&
      typeof getUser === "function" && getUser()?.role === "patient") {
    queueMicrotask(loadPatientAccount);
  }
})();