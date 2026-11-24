"use strict";

/*
 * HealthBridge Public V2 — Patient Settings controller (Phase 6).
 * Loads the account + preferences from api/settings/get.php and the
 * medical record via the patient dashboard endpoint, pre-fills every
 * form, and wires all saves to api/settings/update.php and
 * api/patient/medical-profile.php. Nothing legacy is loaded.
 */
(() => {
  const ROOT = typeof getBasePath === "function" ? getBasePath() : "../../";
  let started = false;

  const $ = (id) => document.getElementById(id);

  function setValue(id, value) {
    const field = $(id);
    if (field) field.value = value == null ? "" : String(value);
  }

  function formatDate(value) {
    if (!value) return "—";
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return String(value);
    return date.toLocaleDateString(undefined, { year: "numeric", month: "short", day: "numeric" });
  }

  // Save confirmations use the site-wide pop-up message (site.js
  // showToast — the same treatment as the login page). The id is the
  // legacy per-form marker; the wording matches what each form's
  // inline "Saved" note used to say.
  const SAVED_MESSAGES = {
    "st-profile-saved": "Saved",
    "st-medical-saved": "Saved",
    "st-password-saved": "Password updated",
    "st-theme-saved": "Appearance saved",
    "st-notif-saved": "Saved",
  };

  function flashSaved(id) {
    if (typeof showToast === "function") {
      showToast(SAVED_MESSAGES[id] || "Saved", "success");
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
  async function loadSettings() {
    const problems = [];
    try {
      const result = await request("api/settings/get.php");
      const profile = result.profile || {};
      if (profile.role !== "patient") {
        throw new Error("This settings page is available to patient accounts only.");
      }
      setValue("st-name", profile.name);
      setValue("st-email", profile.email);
      setValue("st-phone", profile.phone);
      setValue("st-role", "Patient");
      setValue("st-created", formatDate(profile.created_at));
      setValue("st-status", profile.is_active ? "Active" : "Disabled");

      const prefs = result.preferences || {};
      const theme = ["light", "dark", "system"].includes(prefs.theme) ? prefs.theme : "system";
      markTheme(theme);
      const form = $("st-notif-form");
      ["notif_appointment", "notif_ratings", "notif_messages", "notif_announcements", "notif_email"].forEach((name) => {
        const input = form?.querySelector(`input[name="${name}"]`);
        if (input) input.checked = Number(prefs[name] ?? (name === "notif_email" ? 0 : 1)) === 1;
      });
    } catch (error) {
      problems.push(error.message || "Your account details could not be loaded.");
    }

    try {
      const result = await post("api/patient/dashboard.php", { sections: ["profile"] });
      const p = result.data?.profile || result.profile || {};
      setValue("st-dob", p.date_of_birth ? String(p.date_of_birth).slice(0, 10) : "");
      setValue("st-gender", p.gender || "");
      setValue("st-blood", p.blood_type || "");
      setValue("st-height", p.height_cm ?? "");
      setValue("st-weight", p.weight_kg ?? "");
      setValue("st-allergies", p.allergies || "");
      setValue("st-chronic", p.chronic_diseases || "");
      setValue("st-medications", p.current_medications || "");
      setValue("st-surgeries", p.previous_surgeries || "");
      setValue("st-family", p.family_history || "");
      setValue("st-notes", p.medical_notes || "");
      setValue("st-em-name", p.emergency_contact?.name || "");
      setValue("st-em-relation", p.emergency_contact?.relationship || "");
      setValue("st-em-phone", p.emergency_contact?.phone || "");
      setValue("st-address", p.location?.address || "");
      setValue("st-city", p.location?.city || "");
      setValue("st-governorate", p.location?.governorate || "");
      setValue("st-ins-provider", p.insurance?.provider || "");
      setValue("st-ins-number", p.insurance?.number || "");
    } catch {
      problems.push("Your medical information could not be loaded.");
    }

    if (problems.length) {
      const banner = $("st-load-error");
      if (banner) {
        banner.hidden = false;
        banner.textContent = problems.join(" ");
      }
    }

    if (window.location.hash) {
      document.querySelector(window.location.hash)?.scrollIntoView();
    }
  }

  // ── Account ─────────────────────────────────────────────────────
  function bindProfileForm() {
    const form = $("st-profile-form");
    form?.addEventListener("submit", async (event) => {
      event.preventDefault();
      formError("st-profile-error", null);
      setBusy(form, true);
      try {
        const result = await post("api/settings/update.php", {
          section: "profile",
          name: $("st-name").value.trim(),
          email: $("st-email").value.trim(),
          phone: $("st-phone").value.trim(),
        });
        try {
          const cached = JSON.parse(localStorage.getItem("hb_user") || "null");
          if (cached) {
            cached.name = result.name || cached.name;
            cached.email = result.email || cached.email;
            localStorage.setItem("hb_user", JSON.stringify(cached));
          }
        } catch { /* identity refresh is best-effort */ }
        const nameNode = $("patient-profile-name");
        if (nameNode && result.name) nameNode.textContent = result.name;
        flashSaved("st-profile-saved");
      } catch (error) {
        formError("st-profile-error", error.message);
      } finally {
        setBusy(form, false);
      }
    });
  }

  // ── Medical information ─────────────────────────────────────────
  function bindMedicalForm() {
    const form = $("st-medical-form");
    form?.addEventListener("submit", async (event) => {
      event.preventDefault();
      formError("st-medical-error", null);
      setBusy(form, true);
      const numberOrEmpty = (id) => {
        const raw = $(id).value;
        return raw === "" ? "" : Number(raw);
      };
      try {
        await post("api/patient/medical-profile.php", {
          date_of_birth: $("st-dob").value,
          gender: $("st-gender").value,
          blood_type: $("st-blood").value,
          height_cm: numberOrEmpty("st-height"),
          weight_kg: numberOrEmpty("st-weight"),
          allergies: $("st-allergies").value.trim(),
          chronic_diseases: $("st-chronic").value.trim(),
          current_medications: $("st-medications").value.trim(),
          previous_surgeries: $("st-surgeries").value.trim(),
          family_history: $("st-family").value.trim(),
          medical_notes: $("st-notes").value.trim(),
          emergency_name: $("st-em-name").value.trim(),
          emergency_relation: $("st-em-relation").value.trim(),
          emergency_phone: $("st-em-phone").value.trim(),
          address: $("st-address").value.trim(),
          city_text: $("st-city").value.trim(),
          governorate_text: $("st-governorate").value.trim(),
          insurance_provider: $("st-ins-provider").value.trim(),
          insurance_number: $("st-ins-number").value.trim(),
        });
        flashSaved("st-medical-saved");
      } catch (error) {
        formError("st-medical-error", error.message);
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
    const pw = $("st-new-pw")?.value || "";
    let score = 0;
    Object.entries(PW_CHECKS).forEach(([key, test]) => {
      const met = test(pw);
      if (met) score += 1;
      document.querySelector(`#st-rules li[data-rule="${key}"]`)?.classList.toggle("is-met", met);
    });
    const fill = $("st-strength-fill");
    const label = $("st-strength-label");
    if (fill) {
      fill.style.width = pw ? `${(score / 5) * 100}%` : "0";
      fill.style.background = PW_LEVELS[score].color;
    }
    if (label) label.textContent = pw ? PW_LEVELS[score].label : "";
    return score;
  }

  function bindPasswordForm() {
    $("st-new-pw")?.addEventListener("input", paintStrength);
    const form = $("st-password-form");
    form?.addEventListener("submit", async (event) => {
      event.preventDefault();
      formError("st-password-error", null);
      const current = $("st-current-pw").value;
      const next = $("st-new-pw").value;
      const confirm = $("st-confirm-pw").value;
      if (!current || !next || !confirm) {
        formError("st-password-error", "All password fields are required.");
        return;
      }
      if (next !== confirm) {
        formError("st-password-error", "New passwords do not match.");
        return;
      }
      if (paintStrength() < 4) {
        formError("st-password-error", "Your new password needs at least 4 of the 5 rules above.");
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
        flashSaved("st-password-saved");
      } catch (error) {
        formError("st-password-error", error.message);
      } finally {
        setBusy(form, false);
      }
    });
  }

  // ── Appearance ──────────────────────────────────────────────────
  function markTheme(theme) {
    document.querySelectorAll(".st-theme-option").forEach((option) => {
      option.classList.toggle("is-selected", option.dataset.themeValue === theme);
    });
  }

  function bindThemeOptions() {
    document.querySelectorAll(".st-theme-option").forEach((option) => {
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
          flashSaved("st-theme-saved");
        } catch (error) {
          if (typeof showToast === "function") showToast(error.message, "error");
        }
      });
    });
  }

  // ── Notifications ───────────────────────────────────────────────
  function bindNotifForm() {
    const form = $("st-notif-form");
    form?.addEventListener("submit", async (event) => {
      event.preventDefault();
      formError("st-notif-error", null);
      setBusy(form, true);
      const body = { section: "notifications" };
      ["notif_appointment", "notif_ratings", "notif_messages", "notif_announcements", "notif_email"].forEach((name) => {
        const input = form.querySelector(`input[name="${name}"]`);
        body[name] = input?.checked ? 1 : 0;
      });
      try {
        await post("api/settings/update.php", body);
        flashSaved("st-notif-saved");
      } catch (error) {
        formError("st-notif-error", error.message);
      } finally {
        setBusy(form, false);
      }
    });
  }

  // ── Danger zone ─────────────────────────────────────────────────
  function bindDangerZone() {
    const modal = $("st-delete-modal");
    const open = () => {
      if (!modal) return;
      modal.hidden = false;
      formError("st-delete-error", null);
      $("st-delete-pw")?.focus();
    };
    const close = () => { if (modal) modal.hidden = true; };
    document.querySelector('[data-st-action="open-delete"]')?.addEventListener("click", open);
    document.querySelector('[data-st-action="close-delete"]')?.addEventListener("click", close);
    modal?.addEventListener("click", (event) => { if (event.target === modal) close(); });
    document.addEventListener("keydown", (event) => { if (event.key === "Escape") close(); });

    const form = $("st-delete-form");
    form?.addEventListener("submit", async (event) => {
      event.preventDefault();
      formError("st-delete-error", null);
      const password = $("st-delete-pw").value;
      if (!password) {
        formError("st-delete-error", "Password is required to deactivate your account.");
        return;
      }
      setBusy(form, true);
      try {
        await post("api/settings/update.php", { section: "delete_account", confirm_password: password });
        await post("api/auth/logout.php", {}).catch(() => null);
        try { localStorage.removeItem("hb_user"); } catch { /* ignore */ }
        window.location.assign(ROOT + "pages/auth/login.html");
      } catch (error) {
        formError("st-delete-error", error.message);
        setBusy(form, false);
      }
    });
  }

  // ── Password visibility toggles ─────────────────────────────────
  function bindPwToggles() {
    document.querySelectorAll("[data-st-pw-toggle]").forEach((button) => {
      button.addEventListener("click", () => {
        const input = $(button.dataset.stPwToggle);
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
    bindMedicalForm();
    bindPasswordForm();
    bindThemeOptions();
    bindNotifForm();
    bindDangerZone();
    bindPwToggles();
    loadSettings();
  }

  document.addEventListener("patient:shell-ready", init, { once: true });
  if (document.querySelector(".main-content.patient-shell-ready")) {
    queueMicrotask(init);
  }
})();
