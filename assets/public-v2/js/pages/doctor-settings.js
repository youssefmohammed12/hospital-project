"use strict";

/*
 * HealthBridge Public V2 — Doctor Settings controller (Phase 6).
 * Loads the account + preferences from api/settings/get.php, pre-fills
 * every form, and wires all saves to api/settings/update.php sections
 * (profile / doctor / password / appearance / notifications /
 * delete_account). Nothing legacy is loaded — the legacy doctor
 * settings form never called an endpoint at all; this one does, and
 * every control is persistence-audited against the server.
 */
(() => {
  const ROOT = typeof getBasePath === "function" ? getBasePath() : "../../";
  let started = false;

  const $ = (id) => document.getElementById(id);

  function setValue(id, value) {
    const field = $(id);
    if (field) field.value = value == null ? "" : String(value);
  }

  function formatDate(value) { return window.HBHelpers.formatDateTime(value); }

  // Save confirmations use the site-wide pop-up message (site.js
  // showToast — the same treatment as the login page).
  const SAVED_MESSAGES = {
    profile: "Saved",
    prefs: "Saved",
    password: "Password updated",
    theme: "Appearance saved",
    notif: "Saved",
  };

  function flashSaved(key) {
    if (typeof showToast === "function") {
      showToast(SAVED_MESSAGES[key] || "Saved", "success");
    }
  }

  function formError(id, message) {
    const node = $(id);
    if (!node) return;
    node.hidden = !message;
    node.textContent = message || "";
  }

  async function request(url, options) {
    const response = await fetch(ROOT + url, {
      credentials: "same-origin",
      headers: { Accept: "application/json", ...(options?.body ? { "Content-Type": "application/json" } : {}) },
      ...options,
    });
    const result = await response.json().catch(() => null);
    if (!response.ok || !result?.success) {
      throw new Error(result?.message || "Something went wrong. Please try again.");
    }
    return result;
  }

  const post = (url, body) => request(url, { method: "POST", body: JSON.stringify(body) });

  function setBusy(form, busy) {
    const button = form?.querySelector('button[type="submit"]');
    if (!button) return;
    if (busy) {
      button.dataset.label = button.textContent;
      button.disabled = true;
      button.textContent = "Saving…";
    } else {
      button.disabled = false;
      if (button.dataset.label) button.textContent = button.dataset.label;
    }
  }

  // ── Loading ─────────────────────────────────────────────────────
  function fillDurationSelect(current) {
    const select = $("dst-duration");
    if (!select) return;
    select.innerHTML = "";
    for (let minutes = 15; minutes <= 120; minutes += 5) {
      const option = document.createElement("option");
      option.value = String(minutes);
      option.textContent = minutes + " minutes";
      select.appendChild(option);
    }
    // Keep an off-grid saved value selectable instead of re-mapping it.
    if (current != null && ![...select.options].some((o) => o.value === String(current))) {
      const option = document.createElement("option");
      option.value = String(current);
      option.textContent = current + " minutes";
      select.appendChild(option);
    }
    select.value = String(current ?? 30);
  }

  async function loadSettings() {
    try {
      const result = await request("api/settings/get.php");
      const profile = result.profile || {};
      if (profile.role !== "doctor") {
        throw new Error("This settings page is available to doctor accounts only.");
      }
      setValue("dst-name", profile.name);
      setValue("dst-email", profile.email);
      setValue("dst-phone", profile.phone);
      setValue("dst-role", "Doctor");
      setValue("dst-created", formatDate(profile.created_at));
      setValue("dst-status", profile.is_active ? "Active" : "Disabled");

      const prefs = result.preferences || {};
      const accept = $("dst-accept-new");
      if (accept) accept.checked = Number(prefs.accept_new_patients ?? 1) === 1;
      const visible = $("dst-profile-visible");
      if (visible) visible.checked = Number(prefs.profile_visible ?? 1) === 1;
      fillDurationSelect(prefs.consultation_duration ?? 30);

      const theme = ["light", "dark", "system"].includes(prefs.theme) ? prefs.theme : "system";
      markTheme(theme);

      const form = $("dst-notif-form");
      ["notif_appointment", "notif_ratings", "notif_messages", "notif_announcements", "notif_email"].forEach((name) => {
        const input = form?.querySelector(`input[name="${name}"]`);
        if (input) input.checked = Number(prefs[name] ?? (name === "notif_email" ? 0 : 1)) === 1;
      });
    } catch (error) {
      const banner = $("dst-load-error");
      if (banner) {
        banner.hidden = false;
        banner.textContent = error.message || "Your account details could not be loaded.";
      }
    }

    if (window.location.hash) {
      document.querySelector(window.location.hash)?.scrollIntoView();
    }
  }

  // ── Account ─────────────────────────────────────────────────────
  function bindProfileForm() {
    const form = $("dst-profile-form");
    form?.addEventListener("submit", async (event) => {
      event.preventDefault();
      formError("dst-profile-error", null);
      setBusy(form, true);
      try {
        const result = await post("api/settings/update.php", {
          section: "profile",
          name: $("dst-name").value.trim(),
          email: $("dst-email").value.trim(),
          phone: $("dst-phone").value.trim(),
        });
        try {
          const cached = JSON.parse(localStorage.getItem("hb_user") || "null");
          if (cached) {
            cached.name = result.name || cached.name;
            cached.email = result.email || cached.email;
            localStorage.setItem("hb_user", JSON.stringify(cached));
          }
        } catch { /* identity refresh is best-effort */ }
        flashSaved("profile");
      } catch (error) {
        formError("dst-profile-error", error.message);
      } finally {
        setBusy(form, false);
      }
    });
  }

  // ── Doctor preferences ──────────────────────────────────────────
  function bindPrefsForm() {
    const form = $("dst-prefs-form");
    form?.addEventListener("submit", async (event) => {
      event.preventDefault();
      formError("dst-prefs-error", null);
      setBusy(form, true);
      try {
        await post("api/settings/update.php", {
          section: "doctor",
          accept_new_patients: $("dst-accept-new")?.checked ? 1 : 0,
          profile_visible: $("dst-profile-visible")?.checked ? 1 : 0,
          consultation_duration: Number($("dst-duration")?.value || 30),
        });
        flashSaved("prefs");
      } catch (error) {
        formError("dst-prefs-error", error.message);
      } finally {
        setBusy(form, false);
      }
    });
  }

  // ── Password ────────────────────────────────────────────────────
  const PW_CHECKS = {
    min_length: (pw) => pw.length >= 8,
    has_uppercase: (pw) => /[A-Z]/.test(pw),
    has_lowercase: (pw) => /[a-z]/.test(pw),
    has_number: (pw) => /[0-9]/.test(pw),
    has_special: (pw) => /[^A-Za-z0-9]/.test(pw),
  };
  const PW_LEVELS = [
    { label: "", color: "transparent" },
    { label: "Weak", color: "#c65b4a" },
    { label: "Weak", color: "#c65b4a" },
    { label: "Fair", color: "#d9a441" },
    { label: "Strong", color: "#7fb069" },
    { label: "Very strong", color: "#2a8a81" },
  ];

  function paintStrength() {
    const pw = $("dst-new-pw")?.value || "";
    let score = 0;
    Object.entries(PW_CHECKS).forEach(([key, test]) => {
      const met = test(pw);
      if (met) score += 1;
      document.querySelector(`#dst-rules li[data-rule="${key}"]`)?.classList.toggle("is-met", met);
    });
    const fill = $("dst-strength-fill");
    const label = $("dst-strength-label");
    if (fill) {
      fill.style.width = pw ? `${(score / 5) * 100}%` : "0";
      fill.style.background = PW_LEVELS[score].color;
    }
    if (label) label.textContent = pw ? PW_LEVELS[score].label : "";
    return score;
  }

  function bindPasswordForm() {
    $("dst-new-pw")?.addEventListener("input", paintStrength);
    const form = $("dst-password-form");
    form?.addEventListener("submit", async (event) => {
      event.preventDefault();
      formError("dst-password-error", null);
      const current = $("dst-current-pw").value;
      const next = $("dst-new-pw").value;
      const confirm = $("dst-confirm-pw").value;
      if (!current || !next || !confirm) {
        formError("dst-password-error", "All password fields are required.");
        return;
      }
      if (next !== confirm) {
        formError("dst-password-error", "New passwords do not match.");
        return;
      }
      if (paintStrength() < 4) {
        formError("dst-password-error", "Your new password needs at least 4 of the 5 rules above.");
        return;
      }
      setBusy(form, true);
      try {
        await post("api/settings/update.php", {
          section: "password",
          current_password: current,
          new_password: next,
          confirm_password: confirm,
        });
        form.reset();
        paintStrength();
        flashSaved("password");
      } catch (error) {
        formError("dst-password-error", error.message);
      } finally {
        setBusy(form, false);
      }
    });
  }

  // ── Appearance ──────────────────────────────────────────────────
  function markTheme(theme) {
    document.querySelectorAll(".dst-theme-option").forEach((option) => {
      option.classList.toggle("is-selected", option.dataset.themeValue === theme);
    });
  }

  function bindThemeOptions() {
    document.querySelectorAll(".dst-theme-option").forEach((option) => {
      option.addEventListener("click", async () => {
        const theme = option.dataset.themeValue;
        markTheme(theme);
        if (theme === "system") {
          const osTheme = window.matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light";
          if (typeof applyTheme === "function") applyTheme(osTheme);
          try { localStorage.removeItem("hb_theme"); } catch { /* storage unavailable */ }
        } else if (typeof applyTheme === "function") {
          applyTheme(theme);
        }
        try {
          await post("api/settings/update.php", { section: "appearance", theme });
          flashSaved("theme");
        } catch (error) {
          if (typeof showToast === "function") showToast(error.message, "error");
        }
      });
    });
  }

  // ── Notifications ───────────────────────────────────────────────
  function bindNotifForm() {
    const form = $("dst-notif-form");
    form?.addEventListener("submit", async (event) => {
      event.preventDefault();
      formError("dst-notif-error", null);
      setBusy(form, true);
      const body = { section: "notifications" };
      ["notif_appointment", "notif_ratings", "notif_messages", "notif_announcements", "notif_email"].forEach((name) => {
        const input = form.querySelector(`input[name="${name}"]`);
        body[name] = input?.checked ? 1 : 0;
      });
      try {
        await post("api/settings/update.php", body);
        flashSaved("notif");
      } catch (error) {
        formError("dst-notif-error", error.message);
      } finally {
        setBusy(form, false);
      }
    });
  }

  // ── Danger zone ─────────────────────────────────────────────────
  function bindDangerZone() {
    const modal = $("dst-delete-modal");
    const open = () => {
      if (!modal) return;
      modal.hidden = false;
      formError("dst-delete-error", null);
      $("dst-delete-pw")?.focus();
    };
    const close = () => { if (modal) modal.hidden = true; };
    document.querySelector('[data-dst-action="open-delete"]')?.addEventListener("click", open);
    document.querySelector('[data-dst-action="close-delete"]')?.addEventListener("click", close);
    modal?.addEventListener("click", (event) => { if (event.target === modal) close(); });
    document.addEventListener("keydown", (event) => { if (event.key === "Escape") close(); });

    const form = $("dst-delete-form");
    form?.addEventListener("submit", async (event) => {
      event.preventDefault();
      formError("dst-delete-error", null);
      const password = $("dst-delete-pw").value;
      if (!password) {
        formError("dst-delete-error", "Password is required to deactivate your account.");
        return;
      }
      setBusy(form, true);
      try {
        await post("api/settings/update.php", { section: "delete_account", confirm_password: password });
        await post("api/auth/logout.php", {}).catch(() => null);
        try { localStorage.removeItem("hb_user"); } catch { /* ignore */ }
        window.location.assign(ROOT + "pages/auth/login.html");
      } catch (error) {
        formError("dst-delete-error", error.message);
        setBusy(form, false);
      }
    });
  }

  // ── Password visibility toggles ─────────────────────────────────
  function bindPwToggles() {
    document.querySelectorAll("[data-dst-pw-toggle]").forEach((button) => {
      button.addEventListener("click", () => {
        const input = $(button.dataset.dstPwToggle);
        if (!input) return;
        const show = input.type === "password";
        input.type = show ? "text" : "password";
        button.setAttribute("aria-label", show ? "Hide password" : "Show password");
      });
    });
  }

  function init() {
    if (started) return;
    started = true;
    bindProfileForm();
    bindPrefsForm();
    bindPasswordForm();
    bindThemeOptions();
    bindNotifForm();
    bindDangerZone();
    bindPwToggles();
    loadSettings();
  }

  document.addEventListener("doctor:shell-ready", init, { once: true });
  if (document.querySelector(".main-content.doctor-shell-ready")) {
    queueMicrotask(init);
  }
})();
