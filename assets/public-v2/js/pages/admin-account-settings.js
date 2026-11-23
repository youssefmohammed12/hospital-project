"use strict";

/*
 * HealthBridge Public V2 — Admin own-account Settings controller
 * (Admin Phase 7).
 *
 * The admin's own account on the shared settings endpoint:
 * api/settings/get.php loads, api/settings/update.php saves, in
 * the sections the endpoint genuinely supports for an admin —
 * profile (name, email, phone), password (the endpoint's real
 * strength rules, its messages shown verbatim), appearance
 * (theme applies immediately and saves), notifications (the five
 * stored toggles), and the admin section's admin_default_tab —
 * the landing preference the Overview's first-visit forwarding
 * reads (Phase 0/1 behaviour). Decision D7: this page is also
 * the admin's Profile; there is no separate profile page.
 *
 * There is NO delete_account wiring here, deliberately: the
 * shared endpoint does expose a delete_account section with no
 * role gate, but there is exactly one admin account and a
 * self-deactivation control on it is a lock-out trap (admin
 * plan Phase 7). The page states that exclusion in words
 * instead of offering the control.
 *
 * Save confirmations use the site-wide pop-up message (site.js
 * showToast — the same treatment as the login page); field and
 * form errors stay inline. Every control is persistence-audited
 * against the server (change → reload → re-read → restore).
 * Depends on the Public V2 shared site.js runtime.
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

  const SAVED_MESSAGES = {
    profile: "Saved",
    password: "Password updated",
    theme: "Appearance saved",
    notif: "Saved",
    landing: "Saved",
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

  // ── Loaded snapshot (for dirty tracking) ──────────────────────
  let loaded = null;

  function profileDirty() {
    if (!loaded) return false;
    return $("aacct-name").value.trim() !== (loaded.profile.name ?? "")
      || $("aacct-email").value.trim() !== (loaded.profile.email ?? "")
      || $("aacct-phone").value.trim() !== (loaded.profile.phone ?? "");
  }

  function notifDirty() {
    if (!loaded) return false;
    const form = $("aacct-notif-form");
    return NOTIF_FIELDS.some((name) => {
      const input = form?.querySelector(`input[name="${name}"]`);
      return input && (input.checked ? 1 : 0) !== Number(loaded.preferences[name] ?? (name === "notif_email" ? 0 : 1));
    });
  }

  function landingDirty() {
    if (!loaded) return false;
    return $("aacct-default-tab").value !== (loaded.preferences.admin_default_tab ?? "overview");
  }

  function paintDirty() {
    const p = $("aacct-profile-dirty"); if (p) p.hidden = !profileDirty();
    const n = $("aacct-notif-dirty"); if (n) n.hidden = !notifDirty();
    const l = $("aacct-landing-dirty"); if (l) l.hidden = !landingDirty();
  }

  const NOTIF_FIELDS = ["notif_appointment", "notif_ratings", "notif_messages", "notif_announcements", "notif_email"];

  // ── Loading ───────────────────────────────────────────────────
  async function loadSettings() {
    try {
      const result = await request("api/settings/get.php");
      const profile = result.profile || {};
      if (profile.role !== "admin") {
        throw new Error("This settings page is available to admin accounts only.");
      }
      loaded = result;

      setValue("aacct-name", profile.name);
      setValue("aacct-email", profile.email);
      setValue("aacct-phone", profile.phone);
      setValue("aacct-role", "Administrator");
      setValue("aacct-created", formatDate(profile.created_at));
      setValue("aacct-status", profile.is_active ? "Active" : "Disabled");

      const prefs = result.preferences || {};
      const form = $("aacct-notif-form");
      NOTIF_FIELDS.forEach((name) => {
        const input = form?.querySelector(`input[name="${name}"]`);
        if (input) input.checked = Number(prefs[name] ?? (name === "notif_email" ? 0 : 1)) === 1;
      });

      const tab = ["overview", "doctors", "patients", "appointments", "support-messages"]
        .includes(prefs.admin_default_tab) ? prefs.admin_default_tab : "overview";
      setValue("aacct-default-tab", tab);
      loaded.preferences.admin_default_tab = tab;

      const theme = ["light", "dark", "system"].includes(prefs.theme) ? prefs.theme : "system";
      markTheme(theme);

      paintDirty();
    } catch (error) {
      const banner = $("aacct-load-error");
      if (banner) {
        banner.hidden = false;
        banner.textContent = error.message || "Your account details could not be loaded.";
      }
    }

    if (window.location.hash) {
      document.querySelector(window.location.hash)?.scrollIntoView();
    }
  }

  // ── Account ───────────────────────────────────────────────────
  function bindProfileForm() {
    const form = $("aacct-profile-form");
    ["aacct-name", "aacct-email", "aacct-phone"].forEach((id) => {
      $(id)?.addEventListener("input", paintDirty);
    });
    form?.addEventListener("submit", async (event) => {
      event.preventDefault();
      formError("aacct-profile-error", null);
      setBusy(form, true);
      try {
        const result = await post("api/settings/update.php", {
          section: "profile",
          name: $("aacct-name").value.trim(),
          email: $("aacct-email").value.trim(),
          phone: $("aacct-phone").value.trim(),
        });
        try {
          const cached = JSON.parse(localStorage.getItem("hb_user") || "null");
          if (cached) {
            cached.name = result.name || cached.name;
            cached.email = result.email || cached.email;
            localStorage.setItem("hb_user", JSON.stringify(cached));
          }
        } catch { /* identity refresh is best-effort */ }
        if (loaded) {
          loaded.profile.name = $("aacct-name").value.trim();
          loaded.profile.email = $("aacct-email").value.trim();
          loaded.profile.phone = $("aacct-phone").value.trim() || null;
        }
        paintDirty();
        flashSaved("profile");
      } catch (error) {
        formError("aacct-profile-error", error.message);
      } finally {
        setBusy(form, false);
      }
    });
  }

  // ── Password ──────────────────────────────────────────────────
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
    const pw = $("aacct-new-pw")?.value || "";
    let score = 0;
    Object.entries(PW_CHECKS).forEach(([key, test]) => {
      const met = test(pw);
      if (met) score += 1;
      document.querySelector(`#aacct-rules li[data-rule="${key}"]`)?.classList.toggle("is-met", met);
    });
    const fill = $("aacct-strength-fill");
    const label = $("aacct-strength-label");
    if (fill) {
      fill.style.width = pw ? `${(score / 5) * 100}%` : "0";
      fill.style.background = PW_LEVELS[score].color;
    }
    if (label) label.textContent = pw ? PW_LEVELS[score].label : "";
    return score;
  }

  function bindPasswordForm() {
    $("aacct-new-pw")?.addEventListener("input", paintStrength);
    const form = $("aacct-password-form");
    form?.addEventListener("submit", async (event) => {
      event.preventDefault();
      formError("aacct-password-error", null);
      const current = $("aacct-current-pw").value;
      const next = $("aacct-new-pw").value;
      const confirm = $("aacct-confirm-pw").value;
      if (!current || !next || !confirm) {
        formError("aacct-password-error", "All password fields are required.");
        return;
      }
      if (next !== confirm) {
        formError("aacct-password-error", "New passwords do not match.");
        return;
      }
      if (paintStrength() < 4) {
        formError("aacct-password-error", "Your new password needs at least 4 of the 5 rules above.");
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
        formError("aacct-password-error", error.message);
      } finally {
        setBusy(form, false);
      }
    });
  }

  // ── Appearance ────────────────────────────────────────────────
  function markTheme(theme) {
    document.querySelectorAll(".aacct-theme-option").forEach((option) => {
      option.classList.toggle("is-selected", option.dataset.themeValue === theme);
    });
  }

  function bindThemeOptions() {
    document.querySelectorAll(".aacct-theme-option").forEach((option) => {
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
          if (loaded) loaded.preferences.theme = theme;
          flashSaved("theme");
        } catch (error) {
          if (typeof showToast === "function") showToast(error.message, "error");
        }
      });
    });
  }

  // ── Notifications ─────────────────────────────────────────────
  function bindNotifForm() {
    const form = $("aacct-notif-form");
    form?.querySelectorAll('input[type="checkbox"]').forEach((input) => {
      input.addEventListener("change", paintDirty);
    });
    form?.addEventListener("submit", async (event) => {
      event.preventDefault();
      formError("aacct-notif-error", null);
      setBusy(form, true);
      const body = { section: "notifications" };
      NOTIF_FIELDS.forEach((name) => {
        const input = form.querySelector(`input[name="${name}"]`);
        body[name] = input?.checked ? 1 : 0;
      });
      try {
        await post("api/settings/update.php", body);
        if (loaded) NOTIF_FIELDS.forEach((name) => { loaded.preferences[name] = body[name]; });
        paintDirty();
        flashSaved("notif");
      } catch (error) {
        formError("aacct-notif-error", error.message);
      } finally {
        setBusy(form, false);
      }
    });
  }

  // ── Landing preference (admin section) ────────────────────────
  function bindLandingForm() {
    const form = $("aacct-landing-form");
    $("aacct-default-tab")?.addEventListener("change", paintDirty);
    form?.addEventListener("submit", async (event) => {
      event.preventDefault();
      formError("aacct-landing-error", null);
      setBusy(form, true);
      try {
        await post("api/settings/update.php", {
          section: "admin",
          admin_default_tab: $("aacct-default-tab").value,
        });
        if (loaded) loaded.preferences.admin_default_tab = $("aacct-default-tab").value;
        paintDirty();
        flashSaved("landing");
      } catch (error) {
        formError("aacct-landing-error", error.message);
      } finally {
        setBusy(form, false);
      }
    });
  }

  // ── Password visibility toggles ───────────────────────────────
  function bindPwToggles() {
    document.querySelectorAll("[data-aacct-pw-toggle]").forEach((button) => {
      button.addEventListener("click", () => {
        const input = $(button.dataset.aacctPwToggle);
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
    bindPasswordForm();
    bindThemeOptions();
    bindNotifForm();
    bindLandingForm();
    bindPwToggles();
    loadSettings();
  }

  document.addEventListener("admin:shell-ready", init, { once: true });
  if (document.querySelector(".main-content.admin-shell-ready")) {
    queueMicrotask(init);
  }
})();
