/**
 * HealthBridge Public V2 — Doctor patient queue (Phase 2).
 * The doctor's working queue from api/appointments/get-doctor.php:
 * every non-cancelled appointment, grouped by day — today first,
 * then upcoming days ascending, then past days descending (D2).
 * Status filter pills partition the queue by the same status logic
 * the Overview's pills use (pillFor): a row's pill is its filter
 * category. The summary strip (waiting now / today / upcoming) and
 * every pill count are computed live from the same payload.
 * Nothing is hardcoded and no MRN is shown — the API has none; the
 * legacy page fabricated one and this page does not.
 */
"use strict";

(() => {
  const ROOT = typeof getBasePath === "function" ? getBasePath() : "../../";
  const $ = (id) => document.getElementById(id);
  const esc = (value) => String(value ?? "").replace(/[&<>"']/g, (c) => ({
    "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;",
  })[c]);

  const pad = (n) => String(n).padStart(2, "0");
  const dateStr = (d) => d.getFullYear() + "-" + pad(d.getMonth() + 1) + "-" + pad(d.getDate());
  const todayStr = () => dateStr(new Date());
  const fmtDay = (ds) => {
    const d = new Date(String(ds || "") + "T00:00:00");
    if (Number.isNaN(d.getTime())) return "";
    if (ds === todayStr()) return "Today";
    const tmr = new Date();
    tmr.setDate(tmr.getDate() + 1);
    if (ds === dateStr(tmr)) return "Tomorrow";
    return d.toLocaleDateString("en-US", { weekday: "short", month: "short", day: "numeric" });
  };
  const fmtDayFull = (ds) => {
    const d = new Date(String(ds || "") + "T00:00:00");
    if (Number.isNaN(d.getTime())) return "";
    return d.toLocaleDateString("en-US", { weekday: "long", month: "long", day: "numeric", year: "numeric" });
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
    const parts = String(appt.appointment_time_range || "").split("-").map((s) => s.trim());
    if (parts.length === 2 && parts[0]) return fmtTime(parts[0]) + " – " + fmtTime(parts[1]);
    return fmtTime(appt.time);
  };
  const workspaceUrl = (appt) =>
    "doctor-workspace.html?patient_id=" + encodeURIComponent(appt.patient_id ?? appt.user_id ?? "") +
    "&appointment_id=" + encodeURIComponent(appt.id ?? "");
  const byDateTime = (a, b) => (String(a.date) + String(a.time)).localeCompare(String(b.date) + String(b.time));
  const initialsOf = (name) => String(name || "").trim().split(/\s+/).filter(Boolean)
    .slice(0, 2).map((part) => part.charAt(0).toUpperCase()).join("") || "—";

  /* Status logic shared with the Overview: the pill a row shows is
     the category its filter matches. */
  function pillFor(appt) {
    if (appt.status === "Pending") return { text: "Pending", cls: " is-pending" };
    const wf = appt.visit_workflow?.status || "";
    if (wf === "Waiting") return { text: "Waiting", cls: " is-waiting" };
    if (wf === "In Progress") return { text: "In progress", cls: " is-progress" };
    if (wf === "Completed" || appt.status === "Completed") return { text: "Completed", cls: " is-done" };
    return { text: appt.status || "Confirmed", cls: "" };
  }
  function catOf(appt) {
    if (appt.status === "Cancelled") return "cancelled";
    if (appt.status === "Pending") return "pending";
    const wf = appt.visit_workflow?.status || "";
    if (wf === "Waiting") return "waiting";
    if (wf === "In Progress") return "progress";
    if (wf === "Completed" || appt.status === "Completed") return "completed";
    return "confirmed";
  }

  const FILTERS = [
    { key: "all", label: "All" },
    { key: "waiting", label: "Waiting" },
    { key: "confirmed", label: "Confirmed" },
    { key: "progress", label: "In Progress" },
    { key: "completed", label: "Completed" },
    { key: "pending", label: "Pending" },
  ];

  function countUp(el, target) {
    if (!el) return;
    const reduced = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    if (reduced || target === 0) { el.textContent = String(target); return; }
    const start = performance.now();
    const duration = 800;
    const tick = (now) => {
      const p = Math.min(1, (now - start) / duration);
      const eased = 1 - Math.pow(1 - p, 3);
      el.textContent = String(Math.round(target * eased));
      if (p < 1) requestAnimationFrame(tick);
    };
    requestAnimationFrame(tick);
  }

  async function fetchJson(url, options) {
    const response = await fetch(ROOT + url, Object.assign({
      credentials: "same-origin", headers: { Accept: "application/json" },
    }, options || {}));
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload?.success) throw new Error(url);
    return payload;
  }

  let queue = [];       // non-cancelled appointments (the working queue)
  let activeFilter = "all";

  function renderSummary() {
    const today = todayStr();
    countUp($("q-sum-waiting"), queue.filter((a) => a.visit_workflow?.status === "Waiting").length);
    countUp($("q-sum-today"), queue.filter((a) => a.date === today).length);
    countUp($("q-sum-upcoming"), queue.filter((a) => a.date > today).length);
  }

  function renderFilters() {
    const host = $("q-filters");
    if (!host) return;
    host.replaceChildren(...FILTERS.map((f) => {
      const count = f.key === "all" ? queue.length : queue.filter((a) => catOf(a) === f.key).length;
      const btn = document.createElement("button");
      btn.type = "button";
      btn.className = "doctor-fpill" + (activeFilter === f.key ? " is-active" : "");
      btn.dataset.filter = f.key;
      btn.setAttribute("aria-pressed", activeFilter === f.key ? "true" : "false");
      btn.innerHTML = esc(f.label) + ' <span class="doctor-fpill__count">' + count + "</span>";
      btn.addEventListener("click", () => {
        if (activeFilter === f.key) return;
        activeFilter = f.key;
        renderFilters();
        renderGroups(false);
      });
      return btn;
    }));
  }

  function queueRow(appt) {
    const pill = pillFor(appt);
    const name = appt.patient_name || "Patient";
    const row = document.createElement("div");
    row.className = "doctor-qrow";
    row.innerHTML =
      '<span class="doctor-qtime">' + esc(fmtRange(appt)) + "</span>" +
      '<span class="doctor-avatar" aria-hidden="true">' + esc(initialsOf(name)) + "</span>" +
      '<span class="doctor-appt__who"><strong>' + esc(name) + "</strong><span>" +
        esc(appt.department || "") + "</span></span>" +
      '<span class="doctor-pill' + pill.cls + '">' + esc(pill.text) + "</span>" +
      '<a class="doctor-consult-btn" href="' + esc(workspaceUrl(appt)) + '">Consult</a>';
    return row;
  }

  function emptyPanel(title, sub) {
    const panel = document.createElement("section");
    panel.className = "doctor-panel doctor-qempty";
    panel.innerHTML =
      '<p class="doctor-empty-title">' + esc(title) + "</p>" +
      '<p class="doctor-empty-sub">' + esc(sub) + "</p>";
    return panel;
  }

  function renderGroups(animate) {
    const host = $("q-groups");
    if (!host) return;
    const today = todayStr();
    const visible = activeFilter === "all" ? queue : queue.filter((a) => catOf(a) === activeFilter);

    if (!queue.length) {
      host.replaceChildren(emptyPanel(
        "No patients in your queue.",
        "When a booking lands on your calendar, the patient will wait for you here."));
      return;
    }
    if (!visible.length) {
      const label = (FILTERS.find((f) => f.key === activeFilter) || {}).label || "";
      host.replaceChildren(emptyPanel(
        "Nothing under " + label + " right now.",
        "No appointments in the queue carry this status — pick another view to see the rest of your day."));
      return;
    }

    // Group by day: today first, upcoming ascending, past descending.
    const byDay = new Map();
    visible.forEach((a) => {
      if (!byDay.has(a.date)) byDay.set(a.date, []);
      byDay.get(a.date).push(a);
    });
    const future = [...byDay.keys()].filter((d) => d >= today).sort();
    const past = [...byDay.keys()].filter((d) => d < today).sort().reverse();
    const ordered = [...future, ...past];

    let rise = 3; // head, summary and filters take the first slots
    host.replaceChildren(...ordered.map((ds) => {
      const rows = byDay.get(ds).sort(byDateTime);
      const panel = document.createElement("section");
      panel.className = "doctor-panel doctor-qgroup" + (animate ? " doctor-rise" : "");
      if (animate) panel.style.setProperty("--i", Math.min(rise++, 14));
      panel.setAttribute("aria-label", fmtDayFull(ds));
      panel.innerHTML =
        '<div class="doctor-panel__head doctor-qgroup__head">' +
          "<h2>" + esc(fmtDay(ds)) + "</h2>" +
          '<span class="doctor-qgroup__date">' + esc(fmtDayFull(ds)) + "</span>" +
          '<span class="doctor-tile__badge">' + rows.length +
            (rows.length === 1 ? " appt" : " appts") + "</span>" +
        "</div>";
      rows.forEach((a) => panel.appendChild(queueRow(a)));
      return panel;
    }));
  }

  async function loadAll() {
    try {
      const payload = await fetchJson("api/appointments/get-doctor.php");
      const appointments = Array.isArray(payload.appointments) ? payload.appointments : [];
      queue = appointments.filter((a) => a.status !== "Cancelled");
      renderSummary();
      renderFilters();
      renderGroups(true);
    } catch {
      const error = $("q-error");
      if (error) error.hidden = false;
      const host = $("q-groups");
      if (host) host.innerHTML = '<p class="doctor-empty">Your queue could not be loaded.</p>';
    }
  }

  function start() {
    const root = document.querySelector(".doctor-queue");
    if (root) {
      root.classList.add("is-entering");
      window.setTimeout(() => root.classList.remove("is-entering"), 1600);
    }
    loadAll();
  }

  document.addEventListener("doctor:shell-ready", start);
})();
