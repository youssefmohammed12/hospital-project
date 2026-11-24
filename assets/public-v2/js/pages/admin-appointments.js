/*
 * HealthBridge Admin — Appointments (Phase 4).
 *
 * The full book from api/appointments/get.php (488 rows, unpaginated —
 * filtered and paged client-side) with the admin's real queue first:
 *
 *   - Pending bookings (53 in the seed) with Approve / Decline on
 *     api/appointments/approve.php / decline.php (both admin-only).
 *     Server side effects, stated in the confirm copy before acting:
 *       approve → booking_status pending → confirmed, the visit's
 *         clinical workflow is created in the same transaction, an
 *         audit entry + appointment event are written, and the patient
 *         gets an in-app "Appointment Confirmed" notification.
 *       decline → booking_status pending → cancelled (the row stays in
 *         the book), the optional reason (≤ 2000 chars) is kept in the
 *         audit record and appended to the patient's in-app
 *         "Appointment Declined" notification.
 *     Only pending bookings can transition; the server refuses others
 *     with 409 and its message is shown verbatim.
 *   - Pending reschedule requests from
 *     api/appointments/get-pending-reschedules.php (12 in the seed, all
 *     patient-requested) with Approve / Reject on approve-reschedule.php
 *     / reject-reschedule.php: approve moves the appointment to the
 *     requested time (slot re-validated server-side; the appointment
 *     stays Confirmed), reject leaves it at its current time. Both
 *     write an audit entry and notify the patient in-app.
 *
 * Book-on-behalf (api/appointments/book.php) is deliberately NOT built
 * here — decision D8 defers it to a possible Phase 4b.
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
    ? window.HBHelpers.fmtTime(v) : String(v || "");
  const initialsOf = (name) => String(name || "").trim().split(/\s+/).filter(Boolean)
    .slice(0, 2).map((part) => part.charAt(0).toUpperCase()).join("") || "—";
  // "2026-12-11 11:30:00" -> "Fri, Dec 11, 2026 · 11:30 AM"
  const fmtDateTime = (v) => {
    const s = String(v || "");
    const date = s.slice(0, 10);
    const time = s.slice(11, 16);
    if (!date) return "—";
    return fmtDate(date) + (time ? " · " + fmtTime(time) : "");
  };
  const fmtWhen = (a) => fmtDate(a.date) + " · " + esc(a.appointment_time_range || fmtTime(a.time));

  const PAGE_SIZE = 25;
  let appointments = [];
  let requests = [];
  let page = 1;

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

  const pillClass = (status) => ({
    Pending: "is-pending",
    Confirmed: "is-progress",
    Completed: "is-done",
    Cancelled: "is-cancelled",
  }[status] || "");

  function patientCell(name) {
    return "<div class=\"aapt-pat\">" +
      "<span class=\"aapt-avatar\" aria-hidden=\"true\">" + esc(initialsOf(name)) + "</span>" +
      "<span class=\"aapt-pat__name\">" + esc(name || "—") + "</span></div>";
  }

  /* ---------- Pending bookings ---------- */

  function pendingBookings() {
    return appointments
      .filter((a) => a.status === "Pending")
      .sort((x, y) => (String(x.date) + String(x.time)).localeCompare(String(y.date) + String(y.time)));
  }

  function renderPending() {
    const list = pendingBookings();
    $("aapt-pending-count").textContent = list.length + (list.length === 1 ? " waiting" : " waiting");
    const tbody = $("aapt-pending-tbody");
    if (!list.length) {
      tbody.innerHTML = "<tr><td colspan=\"6\" class=\"aapt-empty-cell\">No bookings are waiting on a decision. New patient bookings will appear here.</td></tr>";
      return;
    }
    tbody.innerHTML = list.map((a) =>
      "<tr data-id=\"" + num(a.id) + "\">" +
      "<td>" + patientCell(a.patient_name) + "</td>" +
      "<td>" + esc(a.doctor || "—") + "</td>" +
      "<td>" + esc(a.department || "—") + "</td>" +
      "<td>" + fmtWhen(a) + "</td>" +
      "<td>" + esc(fmtDate(String(a.created_at || "").slice(0, 10))) + "</td>" +
      "<td><div class=\"aapt-row-actions\">" +
        "<button class=\"admin-btn\" type=\"button\" data-action=\"approve\" data-id=\"" + num(a.id) + "\">Approve</button>" +
        "<button class=\"admin-btn admin-btn--danger\" type=\"button\" data-action=\"decline\" data-id=\"" + num(a.id) + "\">Decline</button>" +
      "</div></td></tr>"
    ).join("");
  }

  /* ---------- Reschedule requests ---------- */

  function renderRequests() {
    $("aapt-resched-count").textContent = requests.length + (requests.length === 1 ? " waiting" : " waiting");
    const tbody = $("aapt-resched-tbody");
    if (!requests.length) {
      tbody.innerHTML = "<tr><td colspan=\"6\" class=\"aapt-empty-cell\">No reschedule requests are waiting. When a patient asks to move an appointment, the request appears here.</td></tr>";
      return;
    }
    tbody.innerHTML = requests.map((r) =>
      "<tr data-id=\"" + num(r.id) + "\">" +
      "<td>" + patientCell(r.patient_name) + "</td>" +
      "<td>" + esc(r.doctor_name || "—") + "</td>" +
      "<td>" + esc(fmtDateTime(r.original_starts_at)) + "</td>" +
      "<td>" + esc(fmtDateTime(r.requested_starts_at)) + "</td>" +
      "<td class=\"aapt-reason-cell\">" + esc(r.request_reason || "—") + "</td>" +
      "<td><div class=\"aapt-row-actions\">" +
        "<button class=\"admin-btn\" type=\"button\" data-action=\"approve-resched\" data-id=\"" + num(r.id) + "\">Approve</button>" +
        "<button class=\"admin-btn admin-btn--danger\" type=\"button\" data-action=\"reject-resched\" data-id=\"" + num(r.id) + "\">Reject</button>" +
      "</div></td></tr>"
    ).join("");
  }

  /* ---------- Full book ---------- */

  function buildFilterOptions() {
    const keep = (id) => $(id).value;
    const doctors = [...new Set(appointments.map((a) => a.doctor).filter(Boolean))].sort();
    const departments = [...new Set(appointments.map((a) => a.department).filter(Boolean))].sort();
    const fill = (id, values, allLabel) => {
      const sel = $(id);
      const current = keep(id);
      sel.innerHTML = "<option value=\"\">" + allLabel + "</option>" +
        values.map((v) => "<option value=\"" + esc(v) + "\">" + esc(v) + "</option>").join("");
      sel.value = values.includes(current) ? current : "";
    };
    fill("aapt-doctor", doctors, "All doctors");
    fill("aapt-department", departments, "All departments");
  }

  function filteredBook() {
    const q = String($("aapt-search").value || "").trim().toLowerCase();
    const status = $("aapt-status").value;
    const doctor = $("aapt-doctor").value;
    const department = $("aapt-department").value;
    return appointments.filter((a) => {
      if (status && a.status !== status) return false;
      if (doctor && a.doctor !== doctor) return false;
      if (department && a.department !== department) return false;
      if (q) {
        const hay = [a.patient_name, a.doctor, a.department, a.notes].join(" ").toLowerCase();
        if (!hay.includes(q)) return false;
      }
      return true;
    });
  }

  function bookRowHtml(a) {
    const actions = a.status === "Pending"
      ? "<div class=\"aapt-row-actions\">" +
          "<button class=\"admin-btn\" type=\"button\" data-action=\"approve\" data-id=\"" + num(a.id) + "\">Approve</button>" +
          "<button class=\"admin-btn admin-btn--danger\" type=\"button\" data-action=\"decline\" data-id=\"" + num(a.id) + "\">Decline</button>" +
        "</div>"
      : "<span class=\"aapt-no-actions\">—</span>";
    return "<tr data-id=\"" + num(a.id) + "\">" +
      "<td>" + patientCell(a.patient_name) + "</td>" +
      "<td>" + esc(a.doctor || "—") + "</td>" +
      "<td>" + esc(a.department || "—") + "</td>" +
      "<td>" + fmtWhen(a) + "</td>" +
      "<td><span class=\"admin-pill " + pillClass(a.status) + "\">" + esc(a.status) + "</span></td>" +
      "<td>" + actions + "</td></tr>";
  }

  function renderBook() {
    const list = filteredBook();
    const pages = Math.max(1, Math.ceil(list.length / PAGE_SIZE));
    if (page > pages) page = pages;
    const slice = list.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE);
    const tbody = $("aapt-tbody");
    if (!slice.length) {
      tbody.innerHTML = "<tr><td colspan=\"6\" class=\"aapt-empty-cell\">No appointments match these filters. <button class=\"admin-btn admin-btn--ghost\" type=\"button\" id=\"aapt-reset\">Clear filters</button></td></tr>";
      $("aapt-reset")?.addEventListener("click", () => {
        $("aapt-search").value = ""; $("aapt-status").value = "";
        $("aapt-doctor").value = ""; $("aapt-department").value = "";
        page = 1; renderBook();
      });
    } else {
      tbody.innerHTML = slice.map(bookRowHtml).join("");
    }
    $("aapt-count").textContent = "Showing " + list.length + " of " + appointments.length;
    $("aapt-page-info").textContent = list.length ? ("Page " + page + " of " + pages) : "";
    $("aapt-prev").disabled = page <= 1;
    $("aapt-next").disabled = page >= pages;
  }

  function renderSummary() {
    const by = (s) => appointments.filter((a) => a.status === s).length;
    $("aapt-summary").innerHTML = "<strong>" + appointments.length + " appointments</strong> · " +
      by("Pending") + " pending · " + by("Confirmed") + " confirmed · " + by("Completed") + " completed · " +
      by("Cancelled") + " cancelled · " + requests.length + " reschedule requests waiting";
  }

  /* ---------- Decision modal ---------- */

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

  let pendingAction = null; // { kind, appt?, req? }

  function askDecision(kind, id) {
    const errEl = $("aapt-confirm-error");
    errEl.hidden = true;
    $("aapt-reason").value = "";
    const reasonField = $("aapt-reason-field");
    const okBtn = $("aapt-confirm-ok");
    okBtn.className = "admin-btn";

    if (kind === "approve" || kind === "decline") {
      const a = appointments.find((x) => num(x.id) === id);
      if (!a) return;
      pendingAction = { kind, appt: a };
      const when = fmtDate(a.date) + " at " + fmtTime(a.time);
      if (kind === "approve") {
        reasonField.hidden = true;
        $("aapt-confirm-title").textContent = "Approve this booking?";
        $("aapt-confirm-text").textContent = "This confirms " + a.patient_name + "'s appointment with " + a.doctor + " (" + a.department + ") on " + when + ". The visit's clinical record is created, and the patient is notified in-app that the appointment is confirmed.";
        okBtn.textContent = "Approve booking";
      } else {
        reasonField.hidden = false;
        $("aapt-reason-label").textContent = "Reason (optional)";
        $("aapt-reason-hint").textContent = "If you add a reason, it is included in the patient's notification and kept in the audit record.";
        $("aapt-confirm-title").textContent = "Decline this booking?";
        $("aapt-confirm-text").textContent = "This cancels " + a.patient_name + "'s pending request for " + a.doctor + " (" + a.department + ") on " + when + ". The booking is not deleted — it stays in the book as Cancelled — and the patient is notified in-app that it was declined.";
        okBtn.textContent = "Decline booking";
        okBtn.classList.add("admin-btn--danger");
      }
    } else {
      const r = requests.find((x) => num(x.id) === id);
      if (!r) return;
      pendingAction = { kind, req: r };
      const from = fmtDateTime(r.original_starts_at);
      const to = fmtDateTime(r.requested_starts_at);
      if (kind === "approve-resched") {
        reasonField.hidden = false;
        $("aapt-reason-label").textContent = "Note (optional)";
        $("aapt-reason-hint").textContent = "If you add a note, it is included in the patient's notification and kept on the request record.";
        $("aapt-confirm-title").textContent = "Approve the new time?";
        $("aapt-confirm-text").textContent = r.patient_name + "'s appointment with " + r.doctor_name + " moves from " + from + " to " + to + ". The appointment stays Confirmed, and the patient is notified in-app of the new time. The requested slot is checked again when you confirm — if another booking took it, the server refuses and says so.";
        okBtn.textContent = "Approve new time";
      } else {
        reasonField.hidden = false;
        $("aapt-reason-label").textContent = "Note (optional)";
        $("aapt-reason-hint").textContent = "If you add a note, it is included in the patient's notification and kept on the request record.";
        $("aapt-confirm-title").textContent = "Reject the new time?";
        $("aapt-confirm-text").textContent = "The appointment stays at " + from + " and remains Confirmed. " + r.patient_name + " is notified in-app that the requested move to " + to + " was not approved.";
        okBtn.textContent = "Reject request";
        okBtn.classList.add("admin-btn--danger");
      }
    }
    openOverlay("aapt-confirm-overlay");
  }

  async function runDecision() {
    if (!pendingAction) return;
    const { kind, appt, req } = pendingAction;
    const okBtn = $("aapt-confirm-ok");
    const errEl = $("aapt-confirm-error");
    const note = $("aapt-reason").value.trim();
    okBtn.disabled = true;
    errEl.hidden = true;
    try {
      let payload;
      if (kind === "approve") {
        payload = await api("api/appointments/approve.php", { method: "POST", body: JSON.stringify({ id: num(appt.id) }) });
      } else if (kind === "decline") {
        payload = await api("api/appointments/decline.php", { method: "POST", body: JSON.stringify({ id: num(appt.id), reason: note }) });
      } else if (kind === "approve-resched") {
        payload = await api("api/appointments/approve-reschedule.php", { method: "POST", body: JSON.stringify({ appointment_id: num(req.id), notes: note }) });
      } else {
        payload = await api("api/appointments/reject-reschedule.php", { method: "POST", body: JSON.stringify({ appointment_id: num(req.id), notes: note }) });
      }
      pendingAction = null;
      closeOverlay("aapt-confirm-overlay");
      toast(payload.message || "Done.", "success");
      await load();
    } catch (err) {
      errEl.textContent = err.message;
      errEl.hidden = false;
    } finally {
      okBtn.disabled = false;
    }
  }

  /* ---------- Load ---------- */

  async function load() {
    try {
      const [apptPayload, reqPayload] = await Promise.all([
        api("api/appointments/get.php"),
        api("api/appointments/get-pending-reschedules.php"),
      ]);
      appointments = Array.isArray(apptPayload.appointments) ? apptPayload.appointments : [];
      requests = Array.isArray(reqPayload.requests) ? reqPayload.requests : [];
      buildFilterOptions();
      renderSummary();
      renderPending();
      renderRequests();
      renderBook();
      document.getElementById("admin-main")?.removeAttribute("aria-busy");
    } catch (err) {
      $("aapt-error").hidden = false;
      const cell = "<tr><td colspan=\"6\" class=\"aapt-empty-cell\">Could not be loaded.</td></tr>";
      $("aapt-pending-tbody").innerHTML = cell;
      $("aapt-resched-tbody").innerHTML = cell;
      $("aapt-tbody").innerHTML = cell;
      document.getElementById("admin-main")?.removeAttribute("aria-busy");
    }
  }

  function start() {
    ["aapt-search", "aapt-status", "aapt-doctor", "aapt-department"].forEach((id) => {
      $(id).addEventListener("input", () => { page = 1; renderBook(); });
      $(id).addEventListener("change", () => { page = 1; renderBook(); });
    });
    $("aapt-prev").addEventListener("click", () => { if (page > 1) { page--; renderBook(); } });
    $("aapt-next").addEventListener("click", () => { page++; renderBook(); });
    document.querySelector("main").addEventListener("click", (event) => {
      const btn = event.target.closest("button[data-action]");
      if (!btn) return;
      askDecision(btn.dataset.action, num(btn.dataset.id));
    });
    $("aapt-confirm-close").addEventListener("click", () => closeOverlay("aapt-confirm-overlay"));
    $("aapt-confirm-cancel").addEventListener("click", () => closeOverlay("aapt-confirm-overlay"));
    $("aapt-confirm-ok").addEventListener("click", runDecision);
    $("aapt-confirm-overlay").addEventListener("click", (event) => {
      if (event.target === event.currentTarget) closeOverlay("aapt-confirm-overlay");
    });
    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape") closeOverlay("aapt-confirm-overlay");
    });
    load();
  }

  document.addEventListener("admin:shell-ready", start, { once: true });
})();
