/*
 * HealthBridge Admin — Patient Profile (Phase 3).
 *
 * One consolidated profile per patient, mirroring the Doctor Profile
 * shape. Data comes from api/patients/profile.php (account, full
 * appointment history, doctors visited, reviews submitted, support
 * messages) and the Audit tab is the first UI ever wired to the
 * orphaned api/patients/get-audit.php.
 *
 * Privacy: the admin patient endpoints expose NO national ID or other
 * government identifier, and no gender/date of birth on any read —
 * update.php can write gender/DOB but nothing reads them back, so the
 * Account form edits only name/email/phone (the fields that can be
 * verified after saving).
 *
 * Actions (same write endpoints as the register, same confirm copy
 * rules — the confirm states the server's real side effects):
 *   Edit      — api/patients/update.php { patient_id, name, email, phone }
 *   Toggle    — api/patients/toggle-status.php: flips is_active,
 *               revokes live sessions, notifies the patient; does not
 *               touch appointments.
 *   Archive   — api/patients/delete.php: disables sign-in, revokes
 *               sessions, cancels not-started appointments only;
 *               record and history retained; refused (409) when the
 *               account is already inactive.
 * Depends on the Public V2 shared site.js runtime.
 */
"use strict";

(() => {
  const ROOT = typeof getBasePath === "function" ? getBasePath() : "../../";
  const $ = (id) => document.getElementById(id);
  const esc = window.HBHelpers.esc;
  const num = (v) => { const n = Number(v); return Number.isFinite(n) ? n : 0; };
  const fmtDate = (v) => (typeof window.HBHelpers.formatDate === "function" && v)
    ? window.HBHelpers.formatDate(v, "—") : String(v || "—");
  const fmtTime = (v) => (typeof window.HBHelpers.fmtTime === "function" && v)
    ? window.HBHelpers.fmtTime(v) : String(v || "—");
  const initialsOf = (name) => String(name || "").trim().split(/\s+/).filter(Boolean)
    .slice(0, 2).map((part) => part.charAt(0).toUpperCase()).join("") || "—";

  const params = new URLSearchParams(location.search);
  const patientId = num(params.get("id"));

  let patient = null;
  let appointments = [];
  let doctorsVisited = [];
  let reviews = [];
  let messages = [];

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

  function stars(rating) {
    const r = Math.round(num(rating));
    return "★★★★★".slice(0, r) + "☆☆☆☆☆".slice(0, 5 - r);
  }

  function pillFor(status) {
    const map = { Completed: "is-done", Confirmed: "is-active", Pending: "is-pending", Cancelled: "is-cancelled" };
    return "<span class=\"admin-pill " + (map[status] || "") + "\">" + esc(status || "—") + "</span>";
  }

  /* ---------- Tabs ---------- */

  const TABS = ["overview", "account", "appointments", "activity", "audit"];
  function activateTab(name, focus) {
    if (!TABS.includes(name)) name = "overview";
    TABS.forEach((t) => {
      const btn = $("appf-tabbtn-" + t);
      const panel = $("appf-tab-" + t);
      const on = t === name;
      btn.classList.toggle("is-active", on);
      btn.setAttribute("aria-selected", on ? "true" : "false");
      panel.hidden = !on;
      if (on && focus) btn.focus();
    });
    try { history.replaceState(null, "", "#" + name); } catch { /* file contexts */ }
  }

  /* ---------- Header / stats / overview ---------- */

  function renderAll() {
    const active = num(patient.is_active) === 1;
    $("appf-header").innerHTML =
      "<span class=\"appf-header__avatar\" aria-hidden=\"true\">" + esc(initialsOf(patient.name)) + "</span>" +
      "<div class=\"appf-header__main\">" +
        "<h1>" + esc(patient.name) + "</h1>" +
        "<p class=\"appf-header__sub\">" + esc(patient.email || "—") +
          (patient.phone ? " · " + esc(patient.phone) : "") +
          " · patient since " + esc(fmtDate(String(patient.created_at || "").slice(0, 10))) + "</p>" +
        "<div class=\"appf-header__pills\">" +
          "<span class=\"admin-pill " + (active ? "is-active" : "is-inactive") + "\">" + (active ? "Active" : "Inactive") + "</span>" +
        "</div>" +
      "</div>" +
      "<div class=\"appf-header__actions\">" +
        "<button class=\"admin-btn admin-btn--ghost\" type=\"button\" id=\"appf-head-toggle\">" + (active ? "Deactivate" : "Reactivate") + "</button>" +
        (active ? "<button class=\"admin-btn admin-btn--danger\" type=\"button\" id=\"appf-head-archive\">Archive</button>" : "") +
      "</div>";
    document.title = patient.name + " — Patient Profile — HealthBridge";
    $("appf-head-toggle").addEventListener("click", () => askConfirm("toggle"));
    $("appf-head-archive")?.addEventListener("click", () => askConfirm("archive"));

    const completed = appointments.filter((a) => a.status === "Completed").length;
    const upcoming = appointments.filter((a) => a.status === "Confirmed" || a.status === "Pending").length;
    $("appf-stats").innerHTML =
      "<div class=\"appf-stat\"><strong>" + appointments.length + "</strong><span>Appointments</span></div>" +
      "<div class=\"appf-stat\"><strong>" + completed + "</strong><span>Completed visits</span></div>" +
      "<div class=\"appf-stat\"><strong>" + upcoming + "</strong><span>Confirmed / pending</span></div>" +
      "<div class=\"appf-stat\"><strong>" + reviews.length + "</strong><span>Reviews submitted</span></div>";

    $("appf-account").innerHTML =
      "<dt>Email</dt><dd>" + esc(patient.email || "—") + "</dd>" +
      "<dt>Phone</dt><dd>" + esc(patient.phone || "—") + "</dd>" +
      "<dt>Member since</dt><dd>" + esc(fmtDate(String(patient.created_at || "").slice(0, 10))) + "</dd>" +
      "<dt>Account</dt><dd>" + (active ? "Active" : "Inactive") + "</dd>";

    $("appf-doctors").innerHTML = doctorsVisited.length
      ? "<div class=\"admin-table-wrap\"><table class=\"admin-table\"><thead><tr><th scope=\"col\">Doctor</th><th scope=\"col\">Department</th></tr></thead><tbody>" +
        doctorsVisited.map((d) =>
          "<tr><td><a class=\"apat-pat__name\" href=\"doctor-profile.html?id=" + num(d.id) + "\">" + esc(d.name || "—") + "</a></td>" +
          "<td>" + esc(d.department || "—") + "</td></tr>").join("") +
        "</tbody></table></div>"
      : "<p class=\"appf-empty\">No confirmed visits yet — the doctors-visited list is built from confirmed appointments.</p>";

    const recent = appointments.slice(0, 5);
    $("appf-recent").innerHTML = recent.length ? apptTable(recent) :
      "<p class=\"appf-empty\">No appointments yet for this patient.</p>";
    $("appf-appts-title").textContent = "Appointments (" + appointments.length + ")";
    $("appf-appts").innerHTML = appointments.length ? apptTable(appointments) :
      "<p class=\"appf-empty\">No appointments yet for this patient.</p>";

    renderActivity();
    renderAccountTab();
  }

  function apptTable(list) {
    return "<div class=\"admin-table-wrap\"><table class=\"admin-table\">" +
      "<thead><tr><th scope=\"col\">Doctor</th><th scope=\"col\">Date</th><th scope=\"col\">Time</th><th scope=\"col\">Department</th><th scope=\"col\">Status</th></tr></thead><tbody>" +
      list.map((a) =>
        "<tr><td>" + esc(a.doctor_name || "—") + "</td>" +
        "<td>" + esc(fmtDate(a.date)) + "</td>" +
        "<td>" + esc(a.appointment_time_range || fmtTime(a.time)) + "</td>" +
        "<td>" + esc(a.department || "—") + "</td>" +
        "<td>" + pillFor(a.status) + "</td></tr>").join("") +
      "</tbody></table></div>";
  }

  /* ---------- Reviews & messages ---------- */

  function renderActivity() {
    $("appf-reviews").innerHTML = reviews.length ? reviews.map((r) =>
      "<article class=\"appf-item\">" +
        "<div class=\"appf-item__head\">" +
          "<span class=\"appf-stars\" aria-label=\"" + num(r.stars) + " out of 5 stars\">" + stars(r.stars) + "</span>" +
          "<strong>" + esc(r.doctor_name || "Unknown Doctor") + "</strong>" +
          "<span class=\"appf-item__meta\">reviewed " + esc(fmtDate(String(r.review_date || "").slice(0, 10))) +
            " · visit " + esc(fmtDate(r.appt_date)) + "</span>" +
        "</div>" +
        (r.review ? "<p class=\"appf-item__text\">" + esc(r.review) + "</p>"
                  : "<p class=\"appf-item__text appf-item__meta\">No written review — stars only.</p>") +
      "</article>").join("")
      : "<p class=\"appf-empty\">This patient has not submitted any reviews.</p>";

    $("appf-messages").innerHTML = messages.length ? messages.map((m) =>
      "<article class=\"appf-item\">" +
        "<div class=\"appf-item__head\">" +
          "<strong>" + esc(m.subject || "(no subject)") + "</strong>" +
          "<span class=\"appf-item__meta\">" + esc(fmtDate(String(m.created_at || "").slice(0, 10))) +
            (m.department ? " · " + esc(m.department) : "") + "</span>" +
          (m.reply
            ? "<span class=\"admin-pill is-active\">Replied</span>"
            : "<span class=\"admin-pill is-pending\">Awaiting reply</span>") +
        "</div>" +
        "<p class=\"appf-item__text\">" + esc(m.message || "") + "</p>" +
        (m.reply ? "<div class=\"appf-item__reply\"><span>Reply" +
          (m.replied_at ? " · " + esc(fmtDate(String(m.replied_at).slice(0, 10))) : "") +
          "</span>" + esc(m.reply) + "</div>" : "") +
      "</article>").join("")
      : "<p class=\"appf-empty\">This patient has not sent any support messages.</p>";
  }

  /* ---------- Account tab ---------- */

  function renderAccountTab() {
    const active = num(patient.is_active) === 1;
    $("appf-edit-name").value = patient.name || "";
    $("appf-edit-email").value = patient.email || "";
    $("appf-edit-phone").value = patient.phone || "";
    $("appf-access-note").textContent = active
      ? "This account is active. Deactivating blocks sign-in and revokes live sessions without touching appointments; archiving also cancels appointments that have not started. Both keep the full record."
      : "This account is inactive — the patient cannot sign in. Reactivating restores sign-in with the existing password. Archiving is unavailable while the account is already inactive (the server refuses it).";
    $("appf-toggle").textContent = active ? "Deactivate account" : "Reactivate account";
    $("appf-toggle").className = active ? "admin-btn admin-btn--danger" : "admin-btn";
    $("appf-archive").hidden = !active;
  }

  async function saveEdit(event) {
    event.preventDefault();
    const errEl = $("appf-edit-error");
    errEl.hidden = true;
    $("appf-edit-saved").textContent = "";
    const body = {
      patient_id: patientId,
      name: $("appf-edit-name").value.trim(),
      email: $("appf-edit-email").value.trim(),
      phone: $("appf-edit-phone").value.trim(),
    };
    if (!body.name || !body.email) {
      errEl.textContent = "Name and email are required.";
      errEl.hidden = false;
      return;
    }
    const btn = $("appf-edit-save");
    btn.disabled = true;
    try {
      const payload = await api("api/patients/update.php", { method: "POST", body: JSON.stringify(body) });
      toast(payload.message || "Patient updated.", "success");
      $("appf-edit-saved").textContent = payload.message || "Saved.";
      await reloadProfile();
    } catch (err) {
      errEl.textContent = err.message;
      errEl.hidden = false;
    } finally {
      btn.disabled = false;
    }
  }

  /* ---------- Confirm + toggle/archive ---------- */

  function openOverlay(id) {
    const el = $(id);
    el.hidden = false;
    requestAnimationFrame(() => el.classList.add("is-open"));
  }
  function closeOverlay(id) {
    const el = $(id);
    el.classList.remove("is-open");
    el.hidden = true;
  }

  let pendingKind = null;

  function askConfirm(kind) {
    pendingKind = kind;
    const active = num(patient.is_active) === 1;
    if (kind === "toggle" && active) {
      $("appf-confirm-title").textContent = "Deactivate this patient?";
      $("appf-confirm-text").textContent = "Deactivating " + patient.name + " blocks sign-in and signs them out everywhere (their live sessions are revoked). Their appointments are not changed, and the patient is notified in-app. You can reactivate the account at any time.";
      $("appf-confirm-ok").textContent = "Deactivate patient";
    } else if (kind === "toggle") {
      $("appf-confirm-title").textContent = "Reactivate this patient?";
      $("appf-confirm-text").textContent = patient.name + " will be able to sign in again with their existing password.";
      $("appf-confirm-ok").textContent = "Reactivate patient";
    } else {
      $("appf-confirm-title").textContent = "Archive this patient?";
      $("appf-confirm-text").textContent = "Archiving " + patient.name + " disables sign-in, signs them out everywhere, and cancels their appointments that have not started yet. The account, medical records, prescriptions and audit history are kept — this is the soft delete, nothing is erased. An archived account can be brought back with Reactivate.";
      $("appf-confirm-ok").textContent = "Archive patient";
    }
    openOverlay("appf-confirm-overlay");
  }

  async function runPending() {
    if (!pendingKind) return;
    const kind = pendingKind;
    pendingKind = null;
    closeOverlay("appf-confirm-overlay");
    try {
      const url = kind === "toggle" ? "api/patients/toggle-status.php" : "api/patients/delete.php";
      const payload = await api(url, { method: "POST", body: JSON.stringify({ id: patientId }) });
      let message = payload.message || "Done.";
      if (kind === "archive" && num(payload.cancelled_appointments) > 0) {
        message += " " + payload.cancelled_appointments + " not-started appointment(s) were cancelled.";
      }
      toast(message, "success");
      await reloadProfile();
      await loadAudit();
    } catch (err) {
      toast(err.message, "error");
    }
  }

  /* ---------- Audit (api/patients/get-audit.php — its first UI) ---------- */

  function renderAudit(entries) {
    $("appf-audit-summary").textContent = entries.length
      ? entries.length + " audit entr" + (entries.length === 1 ? "y" : "ies") + " mention this patient (most recent first, up to 100)."
      : "";
    if (!entries.length) {
      $("appf-audit").innerHTML = "<p class=\"appf-empty\">No audit entries mention this patient yet. The trail is young — it only holds a handful of entries system-wide, and most patients have none.</p>";
      return;
    }
    $("appf-audit").innerHTML = "<div class=\"admin-table-wrap\"><table class=\"admin-table\">" +
      "<thead><tr><th scope=\"col\">When</th><th scope=\"col\">Actor</th><th scope=\"col\">Action</th><th scope=\"col\">Entity</th><th scope=\"col\">Description</th></tr></thead><tbody>" +
      entries.map((e) =>
        "<tr><td>" + esc(fmtDate(String(e.created_at || "").slice(0, 10))) + " " + esc(String(e.created_at || "").slice(11, 16)) + "</td>" +
        "<td>" + esc(e.actor_name || "—") + " <span class=\"appf-item__meta\">(" + esc(e.actor_role || "") + ")</span></td>" +
        "<td>" + esc(e.action || "—") + "</td>" +
        "<td>" + esc(e.entity_type || "—") + (e.entity_id ? " #" + esc(e.entity_id) : "") + "</td>" +
        "<td>" + esc(e.description || "—") + "</td></tr>").join("") +
      "</tbody></table></div>";
  }

  async function loadAudit() {
    try {
      const payload = await api("api/patients/get-audit.php?patient_id=" + patientId + "&limit=100");
      renderAudit(Array.isArray(payload.entries) ? payload.entries : []);
    } catch (err) {
      $("appf-audit-summary").textContent = "";
      $("appf-audit").innerHTML = "<p class=\"appf-empty\">The audit trail could not be loaded for this patient.</p>";
    }
  }

  /* ---------- Load ---------- */

  async function reloadProfile() {
    const payload = await api("api/patients/profile.php?id=" + patientId);
    patient = payload.patient;
    appointments = Array.isArray(payload.appointments) ? payload.appointments : [];
    doctorsVisited = Array.isArray(payload.doctors_visited) ? payload.doctors_visited : [];
    reviews = Array.isArray(payload.reviews) ? payload.reviews : [];
    messages = Array.isArray(payload.messages) ? payload.messages : [];
    renderAll();
  }

  async function start() {
    TABS.forEach((t) => {
      $("appf-tabbtn-" + t).addEventListener("click", () => activateTab(t, false));
    });
    $("appf-edit-form").addEventListener("submit", saveEdit);
    $("appf-toggle").addEventListener("click", () => askConfirm("toggle"));
    $("appf-archive").addEventListener("click", () => askConfirm("archive"));
    $("appf-confirm-close").addEventListener("click", () => closeOverlay("appf-confirm-overlay"));
    $("appf-confirm-cancel").addEventListener("click", () => closeOverlay("appf-confirm-overlay"));
    $("appf-confirm-ok").addEventListener("click", runPending);
    $("appf-confirm-overlay").addEventListener("click", (event) => {
      if (event.target === event.currentTarget) closeOverlay("appf-confirm-overlay");
    });
    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape") closeOverlay("appf-confirm-overlay");
    });

    if (!patientId) {
      $("appf-error").hidden = false;
      $("appf-error-text").textContent = "No patient id was given in the address.";
      document.getElementById("admin-main")?.removeAttribute("aria-busy");
      return;
    }
    try {
      await reloadProfile();
      $("appf-body").hidden = false;
      activateTab((location.hash || "").replace("#", "") || "overview", false);
    } catch (err) {
      $("appf-error").hidden = false;
      $("appf-error-text").textContent = err.message;
    }
    loadAudit();
    document.getElementById("admin-main")?.removeAttribute("aria-busy");
  }

  document.addEventListener("admin:shell-ready", start, { once: true });
})();
