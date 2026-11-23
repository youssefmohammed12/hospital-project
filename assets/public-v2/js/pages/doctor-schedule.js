/**
 * HealthBridge Public V2 — Doctor schedule & availability (Phase 4).
 * Weekly hours editor on the existing schedule endpoints:
 *   GET  api/schedule/get.php            — the doctor's own schedule
 *   POST api/schedule/update.php         — save weekly hours + settings
 *   GET  api/settings/get-hospital.php   — hospital open/close bounds
 * Availability is NOT part of the save: the switch calls the same
 * endpoints as the Overview (get-availability / toggle-availability)
 * and applies immediately, so the two pages can never disagree.
 *
 * Reset: api/schedule/reset.php is admin-only (the legacy doctor UI
 * never even rendered the button for doctors), so "Reset to defaults"
 * here posts the server's own factory defaults — ScheduleService::
 * resetToDefault: Mon–Fri 09:00–17:00, weekend off, 30-minute
 * appointments, max 25/day, no break, available — through update.php.
 *
 * Honest feedback: the saved note appears only after the server
 * confirms, and server validation messages are shown verbatim.
 * Nothing is hardcoded: days, times, bounds and counts all come from
 * the payloads above.
 */
"use strict";

(() => {
  const ROOT = typeof getBasePath === "function" ? getBasePath() : "../../";
  const $ = (id) => document.getElementById(id);
  const esc = (value) => String(value ?? "").replace(/[&<>"']/g, (c) => ({
    "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;",
  })[c]);

  const DAY_NAMES = { 1: "Monday", 2: "Tuesday", 3: "Wednesday", 4: "Thursday", 5: "Friday", 6: "Saturday", 7: "Sunday" };
  const VALID_DURATIONS = [15, 20, 30, 45, 60];
  const FACTORY_WEEKLY = [1, 2, 3, 4, 5, 6, 7].map((d) => ({
    day_of_week: d, start_time: "09:00", end_time: "17:00", is_working: d <= 5 ? 1 : 0,
  }));
  const FACTORY_SETTINGS = {
    appointment_duration: 30, max_appointments_per_day: 25,
    break_start: null, break_end: null, is_available: 1,
  };

  let hospital = { open: "08:00", close: "22:00" };
  let savedSnapshot = "";
  let resetArmed = false;
  let resetArmTimer = 0;
  let availabilityBusy = false;

  const toMin = (hhmm) => {
    const m = /^(\d{1,2}):(\d{2})/.exec(String(hhmm || ""));
    return m ? Number(m[1]) * 60 + Number(m[2]) : NaN;
  };
  const toHHMM = (mins) =>
    String(Math.floor(mins / 60)).padStart(2, "0") + ":" + String(mins % 60).padStart(2, "0");
  const fmtTime = (hhmm) => {
    const m = /^(\d{1,2}):(\d{2})/.exec(String(hhmm || ""));
    if (!m) return String(hhmm || "");
    let h = Number(m[1]);
    const suffix = h >= 12 ? "PM" : "AM";
    h = h % 12 === 0 ? 12 : h % 12;
    return h + ":" + m[2] + " " + suffix;
  };
  const fmtHours = (mins) => {
    const h = mins / 60;
    return (Number.isInteger(h) ? String(h) : h.toFixed(1)) + (h === 1 ? " hr" : " hrs");
  };

  /* Fetch helper that keeps the server's own message on failure, so
     validation errors can be surfaced exactly as the API sent them. */
  async function api(url, options) {
    const response = await fetch(ROOT + url, Object.assign({
      credentials: "same-origin", headers: { Accept: "application/json" },
    }, options || {}));
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload?.success) {
      throw new Error(payload?.message || "Something went wrong. Please try again.");
    }
    return payload;
  }
  const postJson = (url, body) => api(url, {
    method: "POST",
    headers: { Accept: "application/json", "Content-Type": "application/json" },
    body: JSON.stringify(body ?? {}),
  });

  /* ---------- Time option lists ---------- */

  /* 30-minute steps across hospital hours, inclusive of both bounds
     (the legacy editor's granularity). A saved value that falls off
     the grid is inserted in order so nothing is silently re-mapped. */
  function timeOptions(extraValues) {
    const values = [];
    const open = toMin(hospital.open);
    const close = toMin(hospital.close);
    if (Number.isFinite(open) && Number.isFinite(close) && close >= open) {
      for (let m = open; m <= close; m += 30) values.push(toHHMM(m));
    }
    (extraValues || []).forEach((v) => {
      if (v && /^\d{2}:\d{2}$/.test(v) && !values.includes(v)) values.push(v);
    });
    values.sort((a, b) => toMin(a) - toMin(b));
    return values.map((v) => ({ value: v, label: fmtTime(v) }));
  }

  function fillSelect(select, options, current, emptyLabel) {
    if (!select) return;
    const parts = [];
    if (emptyLabel !== undefined) {
      parts.push('<option value="">' + esc(emptyLabel) + "</option>");
    }
    options.forEach((o) => {
      parts.push('<option value="' + esc(o.value) + '"' +
        (o.value === current ? " selected" : "") + ">" + esc(o.label) + "</option>");
    });
    select.innerHTML = parts.join("");
    select.value = current ?? "";
  }

  /* ---------- Day rows ---------- */

  function buildDayRows() {
    const host = $("sched-days");
    if (!host) return;
    host.replaceChildren();
    for (let d = 1; d <= 7; d++) {
      const row = document.createElement("div");
      row.className = "doctor-sday";
      row.dataset.day = String(d);
      row.innerHTML =
        '<div class="doctor-sday__id">' +
          '<span class="doctor-sday__name">' + esc(DAY_NAMES[d]) + "</span>" +
          '<span class="doctor-sday__meta" id="sday-meta-' + d + '"></span>' +
        "</div>" +
        '<div class="doctor-sday__times">' +
          '<label class="doctor-sday__field"><span>Start</span>' +
            '<select class="sday-start" data-day="' + d + '" aria-label="' + esc(DAY_NAMES[d]) + ' start time"></select></label>' +
          '<span class="doctor-sday__dash" aria-hidden="true">–</span>' +
          '<label class="doctor-sday__field"><span>End</span>' +
            '<select class="sday-end" data-day="' + d + '" aria-label="' + esc(DAY_NAMES[d]) + ' end time"></select></label>' +
        "</div>" +
        '<label class="doctor-switch">' +
          '<input type="checkbox" class="sday-working" data-day="' + d + '" aria-label="Working on ' + esc(DAY_NAMES[d]) + '" />' +
          '<span class="doctor-switch__track"></span>' +
        "</label>";
      host.appendChild(row);
    }
  }

  function dayRow(d) {
    return {
      working: document.querySelector('.sday-working[data-day="' + d + '"]'),
      start: document.querySelector('.sday-start[data-day="' + d + '"]'),
      end: document.querySelector('.sday-end[data-day="' + d + '"]'),
      wrap: document.querySelector('.doctor-sday[data-day="' + d + '"]'),
      meta: $("sday-meta-" + d),
    };
  }

  /* ---------- Collect / render ---------- */

  function collect() {
    const weekly = [];
    for (let d = 1; d <= 7; d++) {
      const r = dayRow(d);
      weekly.push({
        day_of_week: d,
        start_time: r.start ? r.start.value : "09:00",
        end_time: r.end ? r.end.value : "17:00",
        is_working: r.working && r.working.checked ? 1 : 0,
      });
    }
    const breakStart = $("sched-break-start")?.value || "";
    const breakEnd = $("sched-break-end")?.value || "";
    return {
      weekly,
      settings: {
        appointment_duration: Number($("sched-duration")?.value || 30),
        max_appointments_per_day: Number($("sched-max")?.value || 25),
        break_start: breakStart === "" ? null : breakStart,
        break_end: breakEnd === "" ? null : breakEnd,
      },
    };
  }

  /* Slot simulation identical in spirit to the server's generator:
     step from the day's start by the appointment length; a slot
     counts when it ends by the day's end and does not overlap the
     break. Bookings are not subtracted — this is capacity, labelled
     "estimated". */
  function estimateSlots(day, settings) {
    if (!day.is_working) return 0;
    const start = toMin(day.start_time);
    const end = toMin(day.end_time);
    const dur = Number(settings.appointment_duration) || 30;
    if (!Number.isFinite(start) || !Number.isFinite(end) || end <= start || dur <= 0) return 0;
    const bS = settings.break_start ? toMin(settings.break_start) : null;
    const bE = settings.break_end ? toMin(settings.break_end) : null;
    let count = 0;
    for (let t = start; t + dur <= end; t += dur) {
      if (bS !== null && bE !== null && t < bE && t + dur > bS) continue;
      count++;
    }
    return count;
  }

  function refreshDerived() {
    const { weekly, settings } = collect();
    let workingDays = 0;
    let weeklyMinutes = 0;
    let weeklySlots = 0;
    weekly.forEach((day) => {
      const r = dayRow(day.day_of_week);
      const slots = estimateSlots(day, settings);
      if (day.is_working) {
        workingDays++;
        const mins = Math.max(0, toMin(day.end_time) - toMin(day.start_time));
        weeklyMinutes += mins;
        weeklySlots += slots;
        if (r.meta) {
          r.meta.textContent = fmtHours(mins) + " · " + slots + (slots === 1 ? " slot" : " slots");
        }
      } else if (r.meta) {
        r.meta.textContent = "Off duty";
      }
      if (r.wrap) r.wrap.classList.toggle("is-off", !day.is_working);
      if (r.start) r.start.disabled = !day.is_working;
      if (r.end) r.end.disabled = !day.is_working;
    });
    const setText = (id, text) => { const el = $(id); if (el) el.textContent = text; };
    setText("sched-sum-days", String(workingDays));
    setText("sched-sum-hours", fmtHours(weeklyMinutes));
    setText("sched-sum-slots", String(weeklySlots));
    setText("sched-sum-hospital", fmtTime(hospital.open) + " – " + fmtTime(hospital.close));
  }

  function markDirty() {
    const dirty = JSON.stringify(collect()) !== savedSnapshot;
    const pill = $("sched-dirty");
    if (pill) pill.hidden = !dirty;
    const save = $("sched-save");
    if (save) save.disabled = !dirty;
    if (dirty) {
      const saved = $("sched-saved");
      if (saved) saved.hidden = true;
      disarmReset();
    }
    refreshDerived();
  }

  function renderSchedule(schedule) {
    const weekly = Array.isArray(schedule?.weekly) ? schedule.weekly : [];
    const settings = schedule?.settings || {};
    const byDay = {};
    weekly.forEach((day) => { byDay[Number(day.day_of_week)] = day; });

    const extras = [];
    weekly.forEach((day) => { extras.push(day.start_time, day.end_time); });
    if (settings.break_start) extras.push(settings.break_start);
    if (settings.break_end) extras.push(settings.break_end);
    const options = timeOptions(extras);

    for (let d = 1; d <= 7; d++) {
      const day = byDay[d] || { start_time: "09:00", end_time: "17:00", is_working: 0 };
      const r = dayRow(d);
      fillSelect(r.start, options, String(day.start_time || "09:00").slice(0, 5));
      fillSelect(r.end, options, String(day.end_time || "17:00").slice(0, 5));
      if (r.working) r.working.checked = Number(day.is_working) === 1;
    }

    const duration = $("sched-duration");
    if (duration) {
      duration.innerHTML = VALID_DURATIONS.map((mins) =>
        '<option value="' + mins + '">' + mins + " minutes</option>").join("");
      duration.value = String(Number(settings.appointment_duration) || 30);
    }
    const max = $("sched-max");
    if (max) max.value = String(Number(settings.max_appointments_per_day) || 25);
    fillSelect($("sched-break-start"), options, settings.break_start ? String(settings.break_start).slice(0, 5) : "", "No break");
    fillSelect($("sched-break-end"), options, settings.break_end ? String(settings.break_end).slice(0, 5) : "", "No break");

    applyAvailability(Number(settings.is_available) === 1);
    savedSnapshot = JSON.stringify(collect());
    const pill = $("sched-dirty");
    if (pill) pill.hidden = true;
    const save = $("sched-save");
    if (save) save.disabled = true;
    refreshDerived();
  }

  /* ---------- Availability (shared with the Overview) ---------- */

  function applyAvailability(available) {
    const toggle = $("sched-avail-toggle");
    const status = $("sched-avail-status");
    if (toggle) {
      toggle.checked = available;
      toggle.disabled = false;
      toggle.setAttribute("aria-label", available ? "Accepting appointments" : "Not accepting appointments");
    }
    if (status) {
      status.textContent = available ? "Accepting appointments" : "Not accepting appointments";
      status.classList.toggle("is-off", !available);
    }
  }

  async function onAvailabilityToggle() {
    const toggle = $("sched-avail-toggle");
    if (!toggle || availabilityBusy) return;
    availabilityBusy = true;
    toggle.disabled = true;
    try {
      const payload = await postJson("api/doctors/toggle-availability.php", {});
      applyAvailability(Number(payload.available) === 1);
    } catch {
      // Revert the switch to the state the server still holds.
      toggle.checked = !toggle.checked;
      toggle.disabled = false;
      showError("Your availability could not be changed. Please try again.");
    } finally {
      availabilityBusy = false;
    }
  }

  /* ---------- Feedback ---------- */

  function showError(message) {
    const error = $("sched-error");
    if (error) {
      error.textContent = message;
      error.hidden = false;
    }
  }
  function clearError() {
    const error = $("sched-error");
    if (error) error.hidden = true;
  }
  function showSaved(message) {
    const saved = $("sched-saved");
    if (saved) {
      saved.textContent = message;
      saved.hidden = false;
    }
  }

  /* ---------- Save / reset / refresh ---------- */

  async function onSave(event) {
    event.preventDefault();
    clearError();
    const save = $("sched-save");
    const original = save ? save.textContent : "";
    if (save) { save.disabled = true; save.textContent = "Saving…"; }
    try {
      const { weekly, settings } = collect();
      const payload = await postJson("api/schedule/update.php", { weekly, settings });
      if (payload.schedule) renderSchedule(payload.schedule);
      showSaved(payload.message || "Schedule updated successfully.");
    } catch (err) {
      showError(err.message);
      if (save) save.disabled = false;
    } finally {
      if (save) save.textContent = original || "Save changes";
    }
  }

  function disarmReset() {
    resetArmed = false;
    window.clearTimeout(resetArmTimer);
    const btn = $("sched-reset");
    if (btn) {
      btn.textContent = "Reset to defaults";
      btn.classList.remove("is-arming");
    }
  }

  async function onReset() {
    const btn = $("sched-reset");
    if (!resetArmed) {
      resetArmed = true;
      if (btn) {
        btn.textContent = "Confirm reset — Mon–Fri, 9 AM–5 PM";
        btn.classList.add("is-arming");
      }
      resetArmTimer = window.setTimeout(disarmReset, 5000);
      return;
    }
    disarmReset();
    clearError();
    if (btn) { btn.disabled = true; btn.textContent = "Resetting…"; }
    try {
      const payload = await postJson("api/schedule/update.php", {
        weekly: FACTORY_WEEKLY, settings: FACTORY_SETTINGS,
      });
      if (payload.schedule) renderSchedule(payload.schedule);
      showSaved("Schedule reset to defaults — Mon–Fri, 9:00 AM–5:00 PM, weekends off.");
    } catch (err) {
      showError(err.message);
    } finally {
      if (btn) { btn.disabled = false; btn.textContent = "Reset to defaults"; }
    }
  }

  async function loadAll() {
    clearError();
    try {
      const [hospitalPayload, schedulePayload] = await Promise.all([
        api("api/settings/get-hospital.php").catch(() => null),
        api("api/schedule/get.php"),
      ]);
      const hs = hospitalPayload?.settings;
      if (hs?.appointment_open_time && hs?.appointment_close_time) {
        hospital = {
          open: String(hs.appointment_open_time).slice(0, 5),
          close: String(hs.appointment_close_time).slice(0, 5),
        };
      }
      renderSchedule(schedulePayload.schedule);
      const form = $("sched-form");
      if (form) form.hidden = false;
      const loadError = $("sched-load-error");
      if (loadError) loadError.hidden = true;
    } catch {
      const loadError = $("sched-load-error");
      if (loadError) loadError.hidden = false;
    }
  }

  /* ---------- Start ---------- */

  function start() {
    const root = document.querySelector(".doctor-sched");
    if (root) {
      root.classList.add("is-entering");
      window.setTimeout(() => root.classList.remove("is-entering"), 1600);
    }
    buildDayRows();

    const form = $("sched-form");
    if (form) {
      form.addEventListener("submit", onSave);
      form.addEventListener("change", markDirty);
      form.addEventListener("input", markDirty);
    }
    const toggle = $("sched-avail-toggle");
    if (toggle) toggle.addEventListener("change", onAvailabilityToggle);
    const reset = $("sched-reset");
    if (reset) reset.addEventListener("click", onReset);
    const refresh = $("sched-refresh");
    if (refresh) refresh.addEventListener("click", () => { disarmReset(); loadAll(); });

    loadAll();
  }

  document.addEventListener("doctor:shell-ready", start);
})();
