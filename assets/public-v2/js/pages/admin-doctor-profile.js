/*
 * HealthBridge Admin — Doctor Profile (Phase 2, decisions D2 + D6).
 *
 * The single consolidated profile (the legacy standalone page and the
 * copy embedded in admin.html both retire here). Six tabs:
 *   Overview     — account/contact + the admin-maintained record.
 *   Professional — edits name/email/phone and the department via
 *                  api/doctors/update.php. Specialty is NOT a separate
 *                  field: the repository syncs it to the department
 *                  name on assignment. Experience is recorded at
 *                  account creation; no endpoint changes it later —
 *                  both are shown read-only rather than faked.
 *   Schedule     — the per-doctor schedule editor on
 *                  api/schedule/get|update.php, mirroring the doctor's
 *                  own Schedule page, plus the admin-only reset
 *                  (api/schedule/reset.php) behind a two-step confirm.
 *   Appointments — the doctor's full list from the profile payload.
 *   Reviews      — moderation (D6): the doctor's slice of
 *                  api/doctors/get-admin-ratings.php with hide/show on
 *                  api/doctors/toggle-rating-visibility.php — the
 *                  first UI these endpoints have had. NOTE: the
 *                  ratings payload carries doctor_name but no doctor
 *                  id, so the slice is matched by name (names are
 *                  unique in the directory).
 *   Audit        — this doctor's slice of api/audit/get.php.
 * Depends on the Public V2 shared site.js runtime.
 */
"use strict";

(() => {
  const ROOT = typeof getBasePath === "function" ? getBasePath() : "../../";
  const $ = (id) => document.getElementById(id);
  const esc = window.HBHelpers.esc;
  const num = (v) => { const n = Number(v); return Number.isFinite(n) ? n : 0; };
  const initialsOf = (name) => String(name || "").trim().split(/\s+/).filter(Boolean)
    .slice(0, 2).map((part) => part.charAt(0).toUpperCase()).join("") || "—";
  const fmtDate = (v) => (typeof window.HBHelpers.formatDate === "function" && v)
    ? window.HBHelpers.formatDate(v, "—") : String(v || "—");
  const fmtTime = (v) => (typeof window.HBHelpers.fmtTime === "function" && v)
    ? window.HBHelpers.fmtTime(v) : String(v || "");

  const DAY_NAMES = { 1: "Monday", 2: "Tuesday", 3: "Wednesday", 4: "Thursday", 5: "Friday", 6: "Saturday", 7: "Sunday" };
  const doctorId = num(new URLSearchParams(window.location.search).get("id"));

  let doctor = null;
  let appointments = [];
  let completedCount = 0;
  let departments = [];
  let schedule = null;
  let ratings = [];
  let schedDirty = false;
  let resetArmed = false;
  let resetTimer = null;

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

  const TABS = ["overview", "professional", "schedule", "appointments", "reviews", "audit"];
  function activateTab(name, focus) {
    if (!TABS.includes(name)) name = "overview";
    TABS.forEach((t) => {
      const btn = $("adp-tabbtn-" + t);
      const panel = $("adp-tab-" + t);
      const on = t === name;
      btn.classList.toggle("is-active", on);
      btn.setAttribute("aria-selected", on ? "true" : "false");
      panel.hidden = !on;
      if (on && focus) btn.focus();
    });
    try { history.replaceState(null, "", "#" + name); } catch { /* file contexts */ }
  }

  /* ---------- Header / stats / overview ---------- */

  function renderHeader() {
    const active = num(doctor.is_active) === 1;
    const available = num(doctor.available) === 1;
    $("adp-header").innerHTML =
      "<span class=\"adp-header__avatar\" aria-hidden=\"true\">" + esc(initialsOf(doctor.name)) + "</span>" +
      "<div class=\"adp-header__main\">" +
        "<h1>" + esc(doctor.name) + "</h1>" +
        "<p class=\"adp-header__sub\">" + esc(doctor.department_name || "—") + " · " + esc(doctor.specialty || "—") +
          " · " + (doctor.exp === null || doctor.exp === undefined ? "—" : num(doctor.exp) + " years of experience") + "</p>" +
        "<div class=\"adp-header__pills\">" +
          "<span class=\"admin-pill " + (active ? "is-active" : "is-inactive") + "\">" + (active ? "Active" : "Inactive") + "</span>" +
          "<span class=\"admin-pill " + (available ? "is-progress" : "") + "\">" + (available ? "Available" : "Unavailable") + "</span>" +
          "<span class=\"adoc-stars\" aria-label=\"Rated " + esc(String(doctor.rating ?? "0")) + " out of 5\">" + stars(doctor.rating) + " <small>" + esc(Number(num(doctor.rating).toFixed(1))) + "</small></span>" +
        "</div>" +
      "</div>";
    document.title = doctor.name + " — Doctor Profile — HealthBridge";

    const publicReviews = ratings.filter((r) => num(r.is_public) === 1).length;
    $("adp-stats").innerHTML =
      "<div class=\"adp-stat\"><strong>" + esc(Number(num(doctor.rating).toFixed(1))) + "</strong><span>Average rating</span></div>" +
      "<div class=\"adp-stat\"><strong>" + appointments.length + "</strong><span>Appointments</span></div>" +
      "<div class=\"adp-stat\"><strong>" + completedCount + "</strong><span>Completed visits</span></div>" +
      "<div class=\"adp-stat\"><strong>" + ratings.length + "</strong><span>Reviews (" + publicReviews + " public)</span></div>";

    $("adp-account").innerHTML =
      "<dt>Email</dt><dd>" + esc(doctor.email || "—") + "</dd>" +
      "<dt>Phone</dt><dd>" + esc(doctor.phone || "—") + "</dd>" +
      "<dt>Member since</dt><dd>" + esc(fmtDate(String(doctor.created_at || "").slice(0, 10))) + "</dd>" +
      "<dt>Account</dt><dd>" + (active ? "Active" : "Inactive") + "</dd>" +
      "<dt>Bookings</dt><dd>" + (available ? "Available for bookings" : "Not available") + "</dd>";
    $("adp-record").innerHTML =
      "<dt>Department</dt><dd>" + esc(doctor.department_name || "—") + "</dd>" +
      "<dt>Specialty</dt><dd>" + esc(doctor.specialty || "—") + "</dd>" +
      "<dt>Experience</dt><dd>" + (doctor.exp === null || doctor.exp === undefined ? "—" : num(doctor.exp) + " years") + "</dd>" +
      "<dt>Rating</dt><dd>" + esc(Number(num(doctor.rating).toFixed(1))) + " out of 5</dd>";

    const recent = appointments.slice(0, 5);
    $("adp-recent").innerHTML = recent.length ? apptTable(recent) :
      "<p class=\"adp-empty\">No appointments yet for this doctor.</p>";
    $("adp-appts-title").textContent = "Appointments (" + appointments.length + ")";
    $("adp-appts").innerHTML = appointments.length ? apptTable(appointments) :
      "<p class=\"adp-empty\">No appointments yet for this doctor.</p>";
  }

  function apptTable(list) {
    return "<div class=\"admin-table-wrap\"><table class=\"admin-table\">" +
      "<thead><tr><th scope=\"col\">Patient</th><th scope=\"col\">Date</th><th scope=\"col\">Time</th><th scope=\"col\">Department</th><th scope=\"col\">Status</th></tr></thead><tbody>" +
      list.map((a) =>
        "<tr><td>" + esc(a.patient_name || "—") + "</td>" +
        "<td>" + esc(fmtDate(a.date)) + "</td>" +
        "<td>" + esc(a.appointment_time_range || fmtTime(a.time)) + "</td>" +
        "<td>" + esc(a.department || "—") + "</td>" +
        "<td>" + pillFor(a.status) + "</td></tr>").join("") +
      "</tbody></table></div>";
  }

  /* ---------- Professional ---------- */

  function renderProfForm() {
    $("adp-prof-name").value = doctor.name || "";
    $("adp-prof-email").value = doctor.email || "";
    $("adp-prof-phone").value = doctor.phone || "";
    $("adp-prof-dept").innerHTML = departments.map((d) =>
      "<option value=\"" + num(d.id) + "\"" + (num(d.id) === num(doctor.department_id) ? " selected" : "") + ">" + esc(d.name) + "</option>").join("");
    $("adp-prof-specialty").value = doctor.specialty || "";
    $("adp-prof-exp").value = doctor.exp === null || doctor.exp === undefined ? "" : String(num(doctor.exp));
  }

  async function saveProf(event) {
    event.preventDefault();
    const errEl = $("adp-prof-error");
    errEl.hidden = true;
    $("adp-prof-saved").textContent = "";
    const body = {
      id: doctorId,
      name: $("adp-prof-name").value.trim(),
      email: $("adp-prof-email").value.trim(),
      phone: $("adp-prof-phone").value.trim(),
      department_id: num($("adp-prof-dept").value) || "",
    };
    if (!body.name || !body.email) {
      errEl.textContent = "Name and email are required.";
      errEl.hidden = false;
      return;
    }
    const btn = $("adp-prof-save");
    btn.disabled = true;
    try {
      const payload = await api("api/doctors/update.php", { method: "POST", body: JSON.stringify(body) });
      toast(payload.message || "Doctor details updated.", "success");
      await reloadProfile();
    } catch (err) {
      errEl.textContent = err.message;
      errEl.hidden = false;
    } finally {
      btn.disabled = false;
    }
  }

  /* ---------- Schedule ---------- */

  function toMinutes(hhmm) {
    const m = String(hhmm || "").match(/^(\d{1,2}):(\d{2})/);
    return m ? Number(m[1]) * 60 + Number(m[2]) : null;
  }
  function toHHMM(mins) {
    return String(Math.floor(mins / 60)).padStart(2, "0") + ":" + String(mins % 60).padStart(2, "0");
  }

  function timeOptions(extraValues) {
    const values = new Set();
    for (let m = 8 * 60; m <= 22 * 60; m += 30) values.add(toHHMM(m));
    (extraValues || []).forEach((v) => { if (v) values.add(String(v).slice(0, 5)); });
    return [...values].sort();
  }

  function fillSelect(select, options, current, emptyLabel) {
    select.innerHTML = (emptyLabel !== undefined ? "<option value=\"\">" + esc(emptyLabel) + "</option>" : "") +
      options.map((v) => "<option value=\"" + esc(v) + "\"" + (v === current ? " selected" : "") + ">" + esc(fmtTime(v)) + "</option>").join("");
    if (current) select.value = current;
  }

  function buildDayRows() {
    $("adp-days").innerHTML = [1, 2, 3, 4, 5, 6, 7].map((d) =>
      "<div class=\"adp-day\" data-day=\"" + d + "\">" +
        "<span class=\"adp-day__name\">" + DAY_NAMES[d] + "</span>" +
        "<label>Starts<select class=\"adp-day-start\" data-day=\"" + d + "\" aria-label=\"" + DAY_NAMES[d] + " start time\"></select></label>" +
        "<label>Ends<select class=\"adp-day-end\" data-day=\"" + d + "\" aria-label=\"" + DAY_NAMES[d] + " end time\"></select></label>" +
        "<span class=\"adp-day__cell\"><label class=\"adp-day__toggle\"><input type=\"checkbox\" class=\"adp-day-working\" data-day=\"" + d + "\" /> Working</label>" +
        "<span class=\"adp-day__slots\" data-day=\"" + d + "\"></span></span>" +
      "</div>").join("");
    $("adp-days").querySelectorAll("select, input").forEach((el) => {
      el.addEventListener("change", () => { markSchedDirty(); refreshSlots(); });
    });
  }

  function markSchedDirty() {
    schedDirty = true;
    $("adp-sched-dirty").hidden = false;
  }

  function collectSchedule() {
    const weekly = [1, 2, 3, 4, 5, 6, 7].map((d) => ({
      day_of_week: d,
      is_working: document.querySelector(".adp-day-working[data-day=\"" + d + "\"]")?.checked ? 1 : 0,
      start_time: document.querySelector(".adp-day-start[data-day=\"" + d + "\"]")?.value || "09:00",
      end_time: document.querySelector(".adp-day-end[data-day=\"" + d + "\"]")?.value || "17:00",
    }));
    const breakStart = $("adp-break-start").value || null;
    const breakEnd = $("adp-break-end").value || null;
    return {
      weekly,
      settings: {
        appointment_duration: num($("adp-duration").value) || 30,
        max_appointments_per_day: Math.min(100, Math.max(1, num($("adp-max").value) || 25)),
        break_start: breakStart,
        break_end: breakEnd,
        is_available: $("adp-avail").checked ? 1 : 0,
      },
    };
  }

  function refreshSlots() {
    const { weekly, settings } = collectSchedule();
    weekly.forEach((day) => {
      const cell = document.querySelector(".adp-day__slots[data-day=\"" + day.day_of_week + "\"]");
      const row = document.querySelector(".adp-day[data-day=\"" + day.day_of_week + "\"]");
      if (!cell || !row) return;
      row.classList.toggle("is-off", !day.is_working);
      if (!day.is_working) { cell.textContent = "Day off"; return; }
      const span = (toMinutes(day.end_time) ?? 0) - (toMinutes(day.start_time) ?? 0);
      const slots = span > 0 ? Math.floor(span / (settings.appointment_duration || 30)) : 0;
      cell.textContent = slots + " slots";
    });
  }

  function renderSchedule() {
    if (!schedule) return;
    const settings = schedule.settings || {};
    const extras = [];
    (schedule.weekly || []).forEach((d) => { extras.push(d.start_time, d.end_time); });
    extras.push(settings.break_start, settings.break_end);
    const options = timeOptions(extras);

    (schedule.weekly || []).forEach((day) => {
      const d = num(day.day_of_week);
      const start = document.querySelector(".adp-day-start[data-day=\"" + d + "\"]");
      const end = document.querySelector(".adp-day-end[data-day=\"" + d + "\"]");
      const working = document.querySelector(".adp-day-working[data-day=\"" + d + "\"]");
      if (start) fillSelect(start, options, String(day.start_time || "09:00").slice(0, 5));
      if (end) fillSelect(end, options, String(day.end_time || "17:00").slice(0, 5));
      if (working) working.checked = num(day.is_working) === 1;
    });
    fillSelect($("adp-break-start"), options, settings.break_start ? String(settings.break_start).slice(0, 5) : "", "No break");
    fillSelect($("adp-break-end"), options, settings.break_end ? String(settings.break_end).slice(0, 5) : "", "No break");
    $("adp-duration").value = String(num(settings.appointment_duration) || 30);
    $("adp-max").value = String(num(settings.max_appointments_per_day) || 25);
    $("adp-avail").checked = num(settings.is_available) === 1;
    $("adp-avail-label").textContent = num(settings.is_available) === 1 ? "Available for bookings" : "Not available";

    const workingDays = (schedule.weekly || []).filter((d) => num(d.is_working) === 1);
    $("adp-sched-summary").textContent = workingDays.length
      ? "Works " + workingDays.map((d) => DAY_NAMES[num(d.day_of_week)].slice(0, 3)).join(", ") +
        " · " + (num(settings.appointment_duration) || 30) + "-minute appointments · up to " +
        (num(settings.max_appointments_per_day) || 25) + " per day" +
        (settings.break_start ? " · break " + fmtTime(settings.break_start) + "–" + fmtTime(settings.break_end) : " · no break") + "."
      : "This doctor has no working days set.";
    schedDirty = false;
    $("adp-sched-dirty").hidden = true;
    refreshSlots();
  }

  async function loadSchedule() {
    const payload = await api("api/schedule/get.php?doctor_id=" + doctorId);
    schedule = payload.schedule;
    renderSchedule();
  }

  async function saveSchedule() {
    const btn = $("adp-sched-save");
    btn.disabled = true;
    try {
      const { weekly, settings } = collectSchedule();
      const payload = await api("api/schedule/update.php", {
        method: "POST",
        body: JSON.stringify({ doctor_id: doctorId, weekly, settings }),
      });
      schedule = payload.schedule || schedule;
      renderSchedule();
      toast(payload.message || "Schedule updated successfully.", "success");
    } catch (err) {
      toast(err.message, "error");
    } finally {
      btn.disabled = false;
    }
  }

  async function toggleAvailability() {
    if (schedDirty) {
      toast("Save or refresh the schedule before changing availability.", "error");
      $("adp-avail").checked = num(schedule?.settings?.is_available) === 1;
      return;
    }
    const target = $("adp-avail").checked ? 1 : 0;
    try {
      const { weekly } = collectSchedule();
      const s = schedule.settings || {};
      const payload = await api("api/schedule/update.php", {
        method: "POST",
        body: JSON.stringify({
          doctor_id: doctorId,
          weekly,
          settings: {
            appointment_duration: num(s.appointment_duration) || 30,
            max_appointments_per_day: num(s.max_appointments_per_day) || 25,
            break_start: s.break_start || null,
            break_end: s.break_end || null,
            is_available: target,
          },
        }),
      });
      schedule = payload.schedule || schedule;
      renderSchedule();
      toast(target ? "Doctor is now available for bookings." : "Doctor is now unavailable for bookings.", "success");
    } catch (err) {
      $("adp-avail").checked = !target;
      toast(err.message, "error");
    }
  }

  function armReset() {
    const btn = $("adp-sched-reset");
    if (!resetArmed) {
      resetArmed = true;
      btn.textContent = "Click again to confirm reset";
      resetTimer = window.setTimeout(() => {
        resetArmed = false;
        btn.textContent = "Reset to defaults";
      }, 4000);
      return;
    }
    window.clearTimeout(resetTimer);
    resetArmed = false;
    btn.textContent = "Reset to defaults";
    btn.disabled = true;
    api("api/schedule/reset.php", { method: "POST", body: JSON.stringify({ doctor_id: doctorId }) })
      .then(async (payload) => {
        toast(payload.message || "Schedule reset to defaults.", "success");
        await loadSchedule();
      })
      .catch((err) => toast(err.message, "error"))
      .finally(() => { btn.disabled = false; });
  }

  /* ---------- Reviews (D6) ---------- */

  function renderReviews() {
    const pub = ratings.filter((r) => num(r.is_public) === 1);
    const avg = pub.length ? (pub.reduce((s, r) => s + num(r.stars), 0) / pub.length) : 0;
    $("adp-reviews-summary").textContent = ratings.length
      ? ratings.length + " review(s) · " + pub.length + " public · " + (ratings.length - pub.length) +
        " hidden" + (pub.length ? " · public average " + avg.toFixed(1) + " out of 5" : "") +
        ". Hidden reviews disappear from the public pages and the doctor's own ratings view."
      : "No reviews yet for this doctor.";
    $("adp-reviews").innerHTML = ratings.length ? ratings.map((r) => {
      const hidden = num(r.is_hidden) === 1;
      return "<article class=\"adp-review" + (hidden ? " is-hidden" : "") + "\" data-rating=\"" + num(r.id) + "\">" +
        "<div class=\"adp-review__head\">" +
          "<span class=\"adoc-stars\" aria-label=\"" + num(r.stars) + " out of 5 stars\">" + stars(r.stars) + "</span>" +
          "<strong>" + esc(r.patient_name || "Unknown Patient") + "</strong>" +
          "<span class=\"adp-review__meta\">" + esc(fmtDate(String(r.review_date || "").slice(0, 10))) + " · visit " + esc(fmtDate(r.appt_date)) + "</span>" +
          "<span class=\"admin-pill " + (hidden ? "is-inactive" : "is-active") + "\">" + (hidden ? "Hidden" : "Public") + "</span>" +
          "<button class=\"admin-btn admin-btn--ghost\" type=\"button\" data-toggle-rating=\"" + num(r.id) + "\" data-hidden=\"" + (hidden ? "1" : "0") + "\">" + (hidden ? "Show review" : "Hide review") + "</button>" +
        "</div>" +
        (r.review ? "<p class=\"adp-review__text\">" + esc(r.review) + "</p>" : "<p class=\"adp-review__text adp-review__meta\">No written review — stars only.</p>") +
      "</article>";
    }).join("") : "<p class=\"adp-empty\">No reviews to moderate for this doctor.</p>";
  }

  async function toggleRating(btn) {
    const id = num(btn.dataset.toggleRating);
    const targetHidden = btn.dataset.hidden === "1" ? 0 : 1;
    btn.disabled = true;
    try {
      const payload = await api("api/doctors/toggle-rating-visibility.php", {
        method: "POST",
        body: JSON.stringify({ id, is_hidden: targetHidden }),
      });
      const rating = ratings.find((r) => num(r.id) === id);
      if (rating) {
        rating.is_hidden = num(payload.is_hidden);
        rating.is_public = num(payload.is_public);
      }
      renderReviews();
      renderHeader();
      toast(payload.message || "Rating visibility updated.", "success");
      // The header average comes from the server summary — refresh it
      // so hiding the only review updates the displayed rating too.
      reloadProfile().catch(() => { /* local state already updated */ });
    } catch (err) {
      toast(err.message, "error");
    } finally {
      btn.disabled = false;
    }
  }

  /* ---------- Audit ---------- */

  function renderAudit(entries) {
    if (!entries.length) {
      $("adp-audit").innerHTML = "<p class=\"adp-empty\">No audit entries mention this doctor yet. The trail is young — it only holds a handful of entries system-wide.</p>";
      return;
    }
    $("adp-audit").innerHTML = "<div class=\"admin-table-wrap\"><table class=\"admin-table\">" +
      "<thead><tr><th scope=\"col\">When</th><th scope=\"col\">Actor</th><th scope=\"col\">Action</th><th scope=\"col\">Entity</th><th scope=\"col\">Description</th></tr></thead><tbody>" +
      entries.map((e) =>
        "<tr><td>" + esc(fmtDate(String(e.created_at || "").slice(0, 10))) + " " + esc(String(e.created_at || "").slice(11, 16)) + "</td>" +
        "<td>" + esc(e.actor_name || "—") + " <span class=\"adp-review__meta\">(" + esc(e.actor_role || "") + ")</span></td>" +
        "<td>" + esc(e.action || "—") + "</td>" +
        "<td>" + esc(e.entity_type || "—") + (e.entity_id ? " #" + esc(e.entity_id) : "") + "</td>" +
        "<td>" + esc(e.description || "—") + "</td></tr>").join("") +
      "</tbody></table></div>";
  }

  /* ---------- Load ---------- */

  async function reloadProfile() {
    const payload = await api("api/doctors/profile.php?id=" + doctorId);
    doctor = payload.doctor;
    appointments = Array.isArray(payload.appointments) ? payload.appointments : [];
    completedCount = num(payload.completed_count);
    renderHeader();
    renderProfForm();
    renderReviews();
  }

  async function load() {
    if (!doctorId) {
      $("adp-error-text").textContent = "No doctor was specified in the page address.";
      $("adp-error").hidden = false;
      document.getElementById("admin-main")?.removeAttribute("aria-busy");
      return;
    }
    try {
      const [profilePayload, deptPayload] = await Promise.all([
        api("api/doctors/profile.php?id=" + doctorId),
        api("api/departments/get.php"),
      ]);
      doctor = profilePayload.doctor;
      appointments = Array.isArray(profilePayload.appointments) ? profilePayload.appointments : [];
      completedCount = num(profilePayload.completed_count);
      departments = Array.isArray(deptPayload.departments) ? deptPayload.departments : [];

      // Ratings + audit are independent: a failure in either must not
      // take the whole profile down with it.
      try {
        const ratingsPayload = await api("api/doctors/get-admin-ratings.php");
        const all = Array.isArray(ratingsPayload.ratings) ? ratingsPayload.ratings : [];
        ratings = all.filter((r) => String(r.doctor_name || "") === String(doctor.name || ""));
      } catch { ratings = []; }
      try {
        const auditPayload = await api("api/audit/get.php?doctor_id=" + doctorId + "&limit=100");
        renderAudit(Array.isArray(auditPayload.entries) ? auditPayload.entries : []);
      } catch {
        $("adp-audit").innerHTML = "<p class=\"adp-empty\">The audit trail could not be loaded for this doctor.</p>";
      }

      renderHeader();
      renderProfForm();
      renderReviews();
      buildDayRows();
      try {
        await loadSchedule();
      } catch (err) {
        $("adp-sched-summary").textContent = "The schedule could not be loaded: " + err.message;
      }
      $("adp-body").hidden = false;
    } catch (err) {
      $("adp-error-text").textContent = err.message;
      $("adp-error").hidden = false;
    } finally {
      document.getElementById("admin-main")?.removeAttribute("aria-busy");
    }
  }

  function start() {
    TABS.forEach((t) => {
      $("adp-tabbtn-" + t).addEventListener("click", () => activateTab(t));
    });
    document.querySelector(".adp-tabs")?.addEventListener("keydown", (event) => {
      if (event.key !== "ArrowRight" && event.key !== "ArrowLeft") return;
      const current = TABS.indexOf(TABS.find((t) => $("adp-tabbtn-" + t).classList.contains("is-active")));
      const next = (current + (event.key === "ArrowRight" ? 1 : -1) + TABS.length) % TABS.length;
      activateTab(TABS[next], true);
    });
    const fromHash = String(window.location.hash || "").replace("#", "");
    if (TABS.includes(fromHash)) activateTab(fromHash);

    $("adp-prof-form").addEventListener("submit", saveProf);
    $("adp-sched-save").addEventListener("click", saveSchedule);
    $("adp-sched-refresh").addEventListener("click", () => loadSchedule().catch((err) => toast(err.message, "error")));
    $("adp-sched-reset").addEventListener("click", armReset);
    $("adp-avail").addEventListener("change", toggleAvailability);
    ["adp-duration", "adp-max", "adp-break-start", "adp-break-end"].forEach((id) => {
      $(id).addEventListener("change", () => { markSchedDirty(); refreshSlots(); });
    });
    $("adp-reviews").addEventListener("click", (event) => {
      const btn = event.target.closest("button[data-toggle-rating]");
      if (btn) toggleRating(btn);
    });
    load();
  }

  document.addEventListener("admin:shell-ready", start, { once: true });
})();
