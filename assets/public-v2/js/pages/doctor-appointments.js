/**
 * HealthBridge Public V2 — Doctor appointments (Phase 2).
 * The full appointment list from api/appointments/get-doctor.php:
 * every booking, upcoming first (soonest first), then past bookings
 * newest first. Status filter pills partition the list by the same
 * status logic the Overview's pills use (pillFor) — the pill a row
 * shows is the category its filter matches — and the search box
 * matches patient name, department and notes, exactly the fields
 * the legacy page searched. Counts are computed live; nothing is
 * hardcoded, and no MRN is shown (the API has none).
 */
"use strict";

(() => {
  const ROOT = typeof getBasePath === "function" ? getBasePath() : "../../";
  const $ = (id) => document.getElementById(id);
  const esc = window.HBHelpers.esc;

  const pad = (n) => String(n).padStart(2, "0");
  const dateStr = (d) => d.getFullYear() + "-" + pad(d.getMonth() + 1) + "-" + pad(d.getDate());
  const todayStr = () => dateStr(new Date());
  const fmtDateShort = (ds) => {
    const d = new Date(String(ds || "") + "T00:00:00");
    if (Number.isNaN(d.getTime())) return "";
    return d.toLocaleDateString("en-US", { month: "short", day: "numeric", year: "numeric" });
  };
  const fmtWeekday = (ds) => {
    if (ds === todayStr()) return "Today";
    const tmr = new Date();
    tmr.setDate(tmr.getDate() + 1);
    if (ds === dateStr(tmr)) return "Tomorrow";
    const d = new Date(String(ds || "") + "T00:00:00");
    if (Number.isNaN(d.getTime())) return "";
    return d.toLocaleDateString("en-US", { weekday: "long" });
  };
  const fmtTime = window.HBHelpers.fmtTime;
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

  /* Status logic shared with the Overview (pillFor). */
  function pillFor(appt) {
    if (appt.status === "Pending") return { text: "Pending", cls: " is-pending" };
    const wf = appt.visit_workflow?.status || "";
    if (wf === "Waiting") return { text: "Waiting", cls: " is-waiting" };
    if (wf === "In Progress") return { text: "In progress", cls: " is-progress" };
    if (wf === "Completed" || appt.status === "Completed") return { text: "Completed", cls: " is-done" };
    if (appt.status === "Cancelled") return { text: "Cancelled", cls: " is-cancelled" };
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
    { key: "pending", label: "Pending" },
    { key: "confirmed", label: "Confirmed" },
    { key: "waiting", label: "Waiting" },
    { key: "progress", label: "In Progress" },
    { key: "completed", label: "Completed" },
    { key: "cancelled", label: "Cancelled" },
  ];

  async function fetchJson(url, options) {
    const response = await fetch(ROOT + url, Object.assign({
      credentials: "same-origin", headers: { Accept: "application/json" },
    }, options || {}));
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload?.success) throw new Error(url);
    return payload;
  }

  let all = [];
  let activeFilter = "all";
  let query = "";

  function sorted(list) {
    const today = todayStr();
    const upcoming = list.filter((a) => a.date >= today).sort(byDateTime);
    const past = list.filter((a) => a.date < today).sort(byDateTime).reverse();
    return [...upcoming, ...past];
  }

  function matchesQuery(a) {
    if (!query) return true;
    const hay = [a.patient_name, a.department, a.notes]
      .map((v) => String(v || "").toLowerCase()).join(" ");
    return hay.includes(query);
  }

  function renderFilters() {
    const host = $("a-filters");
    if (!host) return;
    host.replaceChildren(...FILTERS.map((f) => {
      const count = f.key === "all" ? all.length : all.filter((a) => catOf(a) === f.key).length;
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
        renderRows(false);
      });
      return btn;
    }));
  }

  function apptRow(appt, animate, idx) {
    const pill = pillFor(appt);
    const name = appt.patient_name || "Patient";
    const row = document.createElement("div");
    row.className = "doctor-arow" + (animate ? " doctor-rise" : "");
    if (animate) row.style.setProperty("--i", Math.min(idx, 16));
    row.innerHTML =
      '<span class="doctor-adate"><strong>' + esc(fmtDateShort(appt.date)) + "</strong><span>" +
        esc(fmtWeekday(appt.date)) + "</span></span>" +
      '<span class="doctor-avatar" aria-hidden="true">' + esc(initialsOf(name)) + "</span>" +
      '<span class="doctor-appt__who"><strong>' + esc(name) + "</strong><span>" +
        esc(appt.department || "") + "</span></span>" +
      '<span class="doctor-qtime">' + esc(fmtRange(appt)) + "</span>" +
      '<span class="doctor-pill' + pill.cls + '">' + esc(pill.text) + "</span>" +
      '<a class="doctor-consult-btn" href="' + esc(workspaceUrl(appt)) + '">Open EMR</a>';
    return row;
  }

  function renderRows(animate) {
    const host = $("a-rows");
    if (!host) return;
    const filtered = sorted(all.filter((a) =>
      (activeFilter === "all" || catOf(a) === activeFilter) && matchesQuery(a)));

    const results = $("a-results");
    if (results) {
      results.textContent = "Showing " + filtered.length + " of " + all.length +
        (all.length === 1 ? " appointment" : " appointments");
    }
    if (!filtered.length) {
      host.innerHTML = '<p class="doctor-empty">No matching appointments found.</p>';
      return;
    }
    host.replaceChildren(...filtered.map((a, i) => apptRow(a, animate, i)));
  }

  async function loadAll() {
    try {
      const payload = await fetchJson("api/appointments/get-doctor.php");
      all = Array.isArray(payload.appointments) ? payload.appointments : [];
      renderFilters();
      renderRows(true);
    } catch {
      const error = $("a-error");
      if (error) error.hidden = false;
      const host = $("a-rows");
      if (host) host.innerHTML = '<p class="doctor-empty">Your appointments could not be loaded.</p>';
    }
  }

  function start() {
    const root = document.querySelector(".doctor-appts");
    if (root) {
      root.classList.add("is-entering");
      window.setTimeout(() => root.classList.remove("is-entering"), 1600);
    }
    const search = $("a-search");
    if (search) {
      search.addEventListener("input", () => {
        query = search.value.trim().toLowerCase();
        renderRows(false);
      });
    }
    loadAll();
  }

  document.addEventListener("doctor:shell-ready", start);
})();
