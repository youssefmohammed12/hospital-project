/*
 * HealthBridge Admin — System Settings (Phase 5).
 *
 * The hospital settings from api/settings/get-hospital.php,
 * saved through api/settings/update-hospital.php in two
 * independent forms: identity (name, phone, email, address,
 * description) and appointment hours (open, close, default
 * duration). Address and description are NULL in the seed and
 * are rendered honestly empty — never pre-filled.
 *
 * The two-step hours-conflict flow (the endpoint's real
 * mechanics, verified): when a save carries opening/closing
 * times and no `confirmed` flag, the server checks every working
 * doctor schedule against the proposed window. If any fall
 * outside, it saves NOTHING and answers HTTP 200 with
 * { success:false, requires_confirmation:true, conflict_count,
 * conflicts: [first 5 of {doctor_name, day_of_week, start_time,
 * end_time}], message }. This page shows that response in the
 * review modal — the server's own message plus the affected
 * schedules — and only an explicit "Save Hours Anyway" click
 * re-posts the same payload with confirmed:true. Nothing is
 * ever auto-confirmed. Only changed fields are sent, so an
 * identity-only save never triggers the hours check.
 * Success feedback is the server's own message via the
 * login-toast standard (site.js showToast).
 * Depends on the Public V2 shared site.js runtime.
 */
"use strict";

(() => {
  const ROOT = typeof getBasePath === "function" ? getBasePath() : "../../";
  const $ = (id) => document.getElementById(id);
  const esc = window.HBHelpers.esc;

  const DAY_NAMES = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"];
  const IDENTITY_FIELDS = [
    ["aset-name", "hospital_name"],
    ["aset-phone", "hospital_phone"],
    ["aset-email", "hospital_email"],
    ["aset-address", "hospital_address"],
    ["aset-description", "hospital_description"],
  ];

  let loaded = null; // last settings snapshot read from the server
  let pendingHoursPayload = null; // hours payload awaiting a conflict confirm

  async function rawPost(url, body) {
    const response = await fetch(ROOT + url, {
      method: "POST",
      credentials: "same-origin",
      headers: { Accept: "application/json", "Content-Type": "application/json" },
      body: JSON.stringify(body),
    });
    const payload = await response.json().catch(() => null);
    return { response, payload };
  }

  async function api(url, options) {
    const response = await fetch(ROOT + url, {
      credentials: "same-origin",
      headers: { Accept: "application/json", ...(options?.body ? { "Content-Type": "application/json" } : {}) },
      ...options,
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload?.success) {
      throw new Error(payload?.message || ("Request failed: " + url));
    }
    return payload;
  }

  function toast(message, type) {
    if (typeof window.showToast === "function") window.showToast(message, type || "info");
  }

  /* ---------- Load / fill ---------- */

  function fillForms(settings) {
    loaded = settings;
    for (const [id, key] of IDENTITY_FIELDS) {
      $(id).value = settings[key] ?? "";
    }
    $("aset-address-hint").hidden = Boolean(settings.hospital_address);
    $("aset-description-hint").hidden = Boolean(settings.hospital_description);
    $("aset-open").value = String(settings.appointment_open_time || "08:00").slice(0, 5);
    $("aset-close").value = String(settings.appointment_close_time || "22:00").slice(0, 5);
    $("aset-duration").value = String(settings.default_appointment_duration ?? 30);
    markDirty();
  }

  async function load() {
    try {
      const payload = await api("api/settings/get-hospital.php");
      fillForms(payload.settings || {});
      document.getElementById("admin-main")?.removeAttribute("aria-busy");
    } catch (err) {
      $("aset-error").hidden = false;
      document.getElementById("admin-main")?.removeAttribute("aria-busy");
    }
  }

  /* ---------- Dirty state ---------- */

  function identityChanges() {
    const changes = {};
    for (const [id, key] of IDENTITY_FIELDS) {
      const value = $(id).value.trim();
      if (value !== String(loaded?.[key] ?? "")) changes[key] = value;
    }
    return changes;
  }

  function hoursChanges() {
    const changes = {};
    const open = $("aset-open").value;
    const close = $("aset-close").value;
    const duration = Number($("aset-duration").value);
    if (open && open !== String(loaded?.appointment_open_time || "").slice(0, 5)) changes.appointment_open_time = open;
    if (close && close !== String(loaded?.appointment_close_time || "").slice(0, 5)) changes.appointment_close_time = close;
    if (duration !== Number(loaded?.default_appointment_duration ?? 30)) changes.default_appointment_duration = duration;
    return changes;
  }

  function markDirty() {
    if (!loaded) return;
    $("aset-identity-dirty").hidden = Object.keys(identityChanges()).length === 0;
    $("aset-hours-dirty").hidden = Object.keys(hoursChanges()).length === 0;
  }

  /* ---------- Identity save ---------- */

  async function submitIdentity(event) {
    event.preventDefault();
    const errEl = $("aset-identity-error");
    errEl.hidden = true;
    const changes = identityChanges();
    if (Object.keys(changes).length === 0) {
      toast("Nothing to save — the identity already matches the server.", "info");
      return;
    }
    if (changes.hospital_name !== undefined && changes.hospital_name === "") {
      errEl.textContent = "Hospital name cannot be empty.";
      errEl.hidden = false;
      return;
    }
    const btn = $("aset-identity-save");
    btn.disabled = true;
    try {
      const payload = await api("api/settings/update-hospital.php", { method: "POST", body: JSON.stringify(changes) });
      toast(payload.message || "Hospital settings updated successfully.", "success");
      await load();
    } catch (err) {
      errEl.textContent = err.message;
      errEl.hidden = false;
    } finally {
      btn.disabled = false;
    }
  }

  /* ---------- Hours save + conflict flow ---------- */

  function openConflict(payload) {
    $("aset-conflict-message").textContent = payload.message || "Changing appointment hours would affect some doctor schedules. Please review and confirm.";
    const conflicts = Array.isArray(payload.conflicts) ? payload.conflicts : [];
    $("aset-conflict-list").innerHTML = conflicts.map((c) =>
      "<li><strong>" + esc(c.doctor_name || "Unknown doctor") + "</strong>" +
      "<span>" + esc(DAY_NAMES[Number(c.day_of_week) - 1] || "") + "</span>" +
      "<span>" + esc(String(c.start_time || "").slice(0, 5)) + " – " + esc(String(c.end_time || "").slice(0, 5)) + "</span></li>"
    ).join("");
    const total = Number(payload.conflict_count) || conflicts.length;
    const more = $("aset-conflict-more");
    if (total > conflicts.length) {
      more.textContent = "Showing the first " + conflicts.length + " of " + total + " affected schedules.";
      more.hidden = false;
    } else {
      more.hidden = true;
    }
    const el = $("aset-conflict-overlay");
    el.hidden = false;
    requestAnimationFrame(() => el.classList.add("is-open"));
  }

  function closeConflict() {
    const el = $("aset-conflict-overlay");
    el.classList.remove("is-open");
    el.hidden = true;
  }

  async function postHours(payload, isConfirmResubmit) {
    const errEl = $("aset-hours-error");
    const { response, payload: result } = await rawPost("api/settings/update-hospital.php", payload);
    if (result?.requires_confirmation) {
      pendingHoursPayload = payload;
      openConflict(result);
      return;
    }
    if (!response.ok || !result?.success) {
      if (isConfirmResubmit) closeConflict();
      errEl.textContent = result?.message || "The hours could not be saved.";
      errEl.hidden = false;
      return;
    }
    if (isConfirmResubmit) closeConflict();
    pendingHoursPayload = null;
    toast(result.message || "Hospital settings updated successfully.", "success");
    await load();
  }

  async function submitHours(event) {
    event.preventDefault();
    const errEl = $("aset-hours-error");
    errEl.hidden = true;
    const changes = hoursChanges();
    if (Object.keys(changes).length === 0) {
      toast("Nothing to save — the hours already match the server.", "info");
      return;
    }
    const open = $("aset-open").value;
    const close = $("aset-close").value;
    if (!open || !close || open >= close) {
      errEl.textContent = "Appointment opening time must be before closing time.";
      errEl.hidden = false;
      return;
    }
    const btn = $("aset-hours-save");
    btn.disabled = true;
    try {
      await postHours(changes, false);
    } catch (err) {
      errEl.textContent = "The hours could not be saved. Check your connection and try again.";
      errEl.hidden = false;
    } finally {
      btn.disabled = false;
    }
  }

  async function confirmConflict() {
    if (!pendingHoursPayload) return;
    const btn = $("aset-conflict-ok");
    btn.disabled = true;
    try {
      await postHours({ ...pendingHoursPayload, confirmed: true }, true);
    } catch (err) {
      toast("The hours could not be saved. Check your connection and try again.", "error");
    } finally {
      btn.disabled = false;
    }
  }

  function cancelConflict() {
    pendingHoursPayload = null;
    closeConflict();
    toast("Hours not saved — the current hours are unchanged.", "info");
  }

  /* ---------- Wire up ---------- */

  function start() {
    for (const [id] of IDENTITY_FIELDS) $(id).addEventListener("input", markDirty);
    ["aset-open", "aset-close", "aset-duration"].forEach((id) => {
      $(id).addEventListener("input", markDirty);
      $(id).addEventListener("change", markDirty);
    });
    $("aset-identity-form").addEventListener("submit", submitIdentity);
    $("aset-hours-form").addEventListener("submit", submitHours);
    $("aset-conflict-ok").addEventListener("click", confirmConflict);
    $("aset-conflict-cancel").addEventListener("click", cancelConflict);
    $("aset-conflict-close").addEventListener("click", cancelConflict);
    $("aset-conflict-overlay").addEventListener("click", (event) => {
      if (event.target === event.currentTarget) cancelConflict();
    });
    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape" && !$("aset-conflict-overlay").hidden) cancelConflict();
    });
    load();
  }

  document.addEventListener("admin:shell-ready", start, { once: true });
})();
