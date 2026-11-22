/**
 * HealthBridge Public V2 — Doctor overview controller (Phase 1).
 * Renders the greeting, four honest stat cards, today's queue with
 * what's up next, and anything waiting on the doctor's decision.
 * Data: api/appointments/get-doctor.php, api/doctors/get-doctor-ratings.php,
 * api/doctors/get-availability.php — all unchanged.
 */
"use strict";

(() => {
  const ROOT = typeof getBasePath === "function" ? getBasePath() : "../../";
  const $ = (id) => document.getElementById(id);
  const esc = (value) => String(value ?? "").replace(/[&<>"']/g, (c) => ({
    "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;",
  })[c]);

  const pad = (n) => String(n).padStart(2, "0");
  const todayStr = () => {
    const d = new Date();
    return d.getFullYear() + "-" + pad(d.getMonth() + 1) + "-" + pad(d.getDate());
  };
  const fmtDay = (dateStr) => {
    const d = new Date(String(dateStr || "") + "T00:00:00");
    if (Number.isNaN(d.getTime())) return "";
    const today = todayStr();
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    const tmr = tomorrow.getFullYear() + "-" + pad(tomorrow.getMonth() + 1) + "-" + pad(tomorrow.getDate());
    if (dateStr === today) return "Today";
    if (dateStr === tmr) return "Tomorrow";
    return d.toLocaleDateString("en-US", { weekday: "short", month: "short", day: "numeric" });
  };
  const fmtTime = (hhmm) => {
    const m = /^(\d{1,2}):(\d{2})/.exec(String(hhmm || ""));
    if (!m) return String(hhmm || "");
    let h = Number(m[1]);
    const suffix = h >= 12 ? "PM" : "AM";
    h = h % 12 === 0 ? 12 : h % 12;
    return h + ":" + m[2] + " " + suffix;
  };
  const fmtRange = (appt) => {
    const range = String(appt.appointment_time_range || "");
    const parts = range.split("-").map((s) => s.trim());
    if (parts.length === 2 && parts[0]) return fmtTime(parts[0]) + " – " + fmtTime(parts[1]);
    return fmtTime(appt.time);
  };

  function countUp(el, target) {
    if (!el) return;
    const reduced = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    if (reduced || target <= 0) { el.textContent = String(target); return; }
    const start = performance.now();
    const duration = 650;
    const tick = (now) => {
      const p = Math.min(1, (now - start) / duration);
      el.textContent = String(Math.round(target * (1 - Math.pow(1 - p, 3))));
      if (p < 1) requestAnimationFrame(tick);
    };
    requestAnimationFrame(tick);
  }

  async function fetchJson(url) {
    const response = await fetch(ROOT + url, {
      method: "GET", credentials: "same-origin", headers: { Accept: "application/json" },
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload?.success) throw new Error(url);
    return payload;
  }

  function renderQueue(appointments) {
    const host = $("ov-queue");
    if (!host) return;
    const today = todayStr();
    const confirmed = appointments.filter((a) => a.status === "Confirmed");
    const todays = confirmed.filter((a) => a.date === today)
      .sort((a, b) => String(a.time).localeCompare(String(b.time)));
    const upcoming = confirmed.filter((a) => a.date > today)
      .sort((a, b) => (a.date + a.time).localeCompare(b.date + b.time));
    const list = [...todays, ...upcoming].slice(0, 5);
    if (!list.length) {
      host.innerHTML = '<p class="doctor-empty">No appointments coming up. New bookings will appear here.</p>';
      return;
    }
    host.replaceChildren();
    list.forEach((appt) => {
      const row = document.createElement("div");
      row.className = "doctor-queue-row";
      const workflow = appt.visit_workflow?.status || "";
      const pillClass = workflow === "Waiting" ? " is-waiting"
        : workflow === "In Progress" ? " is-progress" : "";
      const pillText = workflow || "Confirmed";
      row.innerHTML =
        '<div class="doctor-queue-row__when"><strong>' + esc(fmtRange(appt)) + '</strong><span>' + esc(fmtDay(appt.date)) + '</span></div>' +
        '<div class="doctor-queue-row__who"><strong>' + esc(appt.patient_name || "Patient") + '</strong><span>' + esc(appt.department || "") + '</span></div>' +
        '<span class="doctor-pill' + pillClass + '">' + esc(pillText) + '</span>' +
        '<a class="doctor-consult-btn" href="doctor-workspace.html?patient_id=' +
          encodeURIComponent(appt.patient_id ?? appt.user_id ?? "") +
          "&appointment_id=" + encodeURIComponent(appt.id) + '">Consult</a>';
      host.appendChild(row);
    });
  }

  function renderDecisions(appointments) {
    const host = $("ov-decisions");
    if (!host) return;
    const pending = appointments.filter((a) =>
      a.reschedule_status === "pending" || a.status === "Pending");
    if (!pending.length) {
      host.innerHTML = '<p class="doctor-empty">Nothing is waiting on you — no pending bookings or reschedule requests.</p>';
      return;
    }
    host.replaceChildren();
    pending.forEach((appt) => {
      const row = document.createElement("div");
      row.className = "doctor-decision-row";
      let text;
      if (appt.reschedule_status === "pending") {
        text = "<strong>" + esc(appt.patient_name || "Patient") + "</strong><span>wants to move " +
          esc(fmtDay(appt.date)) + ", " + esc(fmtTime(appt.time)) + " → " +
          esc(fmtDay(appt.pending_reschedule_date)) + ", " + esc(fmtTime(appt.pending_reschedule_time)) + "</span>" +
          (appt.reschedule_reason ? '<span class="doctor-decision-row__reason">“' + esc(appt.reschedule_reason) + "”</span>" : "");
      } else {
        text = "<strong>" + esc(appt.patient_name || "Patient") + "</strong><span>new booking request — " +
          esc(fmtDay(appt.date)) + ", " + esc(fmtTime(appt.time)) + "</span>";
      }
      row.innerHTML = "<div>" + text + '</div><a href="appointments.html">Review</a>';
      host.appendChild(row);
    });
  }

  async function start() {
    const now = new Date();
    const dateNode = $("ov-date");
    if (dateNode) {
      dateNode.textContent = "Doctor workspace · " + now.toLocaleDateString("en-US", {
        weekday: "long", month: "long", day: "numeric", year: "numeric",
      });
    }

    let doctorPayload;
    try {
      doctorPayload = await fetchJson("api/appointments/get-doctor.php");
    } catch {
      const error = $("ov-error");
      if (error) error.hidden = false;
      const queue = $("ov-queue");
      if (queue) queue.innerHTML = '<p class="doctor-empty">Your appointments could not be loaded.</p>';
      const decisions = $("ov-decisions");
      if (decisions) decisions.innerHTML = '<p class="doctor-empty">Your requests could not be loaded.</p>';
      return;
    }

    const appointments = Array.isArray(doctorPayload.appointments) ? doctorPayload.appointments : [];
    const doctorName = doctorPayload.doctor?.name || "";
    const greeting = $("ov-greeting");
    if (greeting) {
      const hour = now.getHours();
      const dayPart = hour < 12 ? "Good morning" : hour < 18 ? "Good afternoon" : "Good evening";
      greeting.textContent = doctorName ? dayPart + ", " + doctorName : dayPart;
    }

    const today = todayStr();
    const confirmed = appointments.filter((a) => a.status === "Confirmed");
    const todayCount = confirmed.filter((a) => a.date === today).length;
    const waitingCount = confirmed.filter((a) => a.visit_workflow?.status === "Waiting").length;
    const pendingCount = appointments.filter((a) =>
      a.reschedule_status === "pending" || a.status === "Pending").length;

    countUp($("ov-stat-today"), todayCount);
    countUp($("ov-stat-waiting"), waitingCount);
    countUp($("ov-stat-pending"), pendingCount);

    renderQueue(appointments);
    renderDecisions(appointments);

    // Rating + availability fill in independently; neither blocks the page.
    fetchJson("api/doctors/get-doctor-ratings.php")
      .then((payload) => {
        const ratings = Array.isArray(payload.ratings) ? payload.ratings : [];
        const value = $("ov-stat-rating");
        const hint = $("ov-stat-rating-hint");
        if (!ratings.length) {
          if (value) value.textContent = "—";
          if (hint) hint.textContent = "No reviews yet";
          return;
        }
        const avg = ratings.reduce((sum, r) => sum + (Number(r.stars) || 0), 0) / ratings.length;
        if (value) value.textContent = avg.toFixed(1);
        if (hint) hint.textContent = "from " + ratings.length + (ratings.length === 1 ? " review" : " reviews");
      })
      .catch(() => { /* the rating card keeps its neutral state */ });

    fetchJson("api/doctors/get-availability.php")
      .then((payload) => {
        const wrap = $("ov-availability");
        const text = $("ov-availability-text");
        if (!wrap || !text) return;
        const available = Number(payload.available) === 1;
        text.textContent = available ? "Accepting appointments" : "Not accepting appointments";
        wrap.classList.toggle("is-off", !available);
        wrap.hidden = false;
      })
      .catch(() => { /* no pill shown if availability cannot be read */ });
  }

  document.addEventListener("doctor:shell-ready", start);
})();
