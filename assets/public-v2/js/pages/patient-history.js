/**
 * HealthBridge Public V2 — Patient Medical History (Phase 3)
 * Built new for Public V2; loads nothing from the legacy frontend.
 * Data: POST api/patient/dashboard.php { sections: ["medical_timeline"] }.
 * Adds the type filters, search and the Download button the plan restores.
 */
(function () {
  "use strict";

  const ROOT = window.location.pathname.includes("/pages/patient/")
    ? "../../"
    : "../";
  const API = ROOT + "api/patient/dashboard.php";

  const $ = (id) => document.getElementById(id);
  const text = (v, fallback = "") => (v == null ? fallback : String(v));
  const esc = window.HBHelpers.escText;

  const TYPE_LABELS = {
    appointment_booked: "Appointment",
    visit_completed: "Visit",
    prescription_issued: "Prescription",
    rating_submitted: "Rating",
    registration: "Account",
  };
  const FILTERS = [
    ["all", "All events"],
    ["appointment_booked", "Appointments"],
    ["visit_completed", "Visits"],
    ["prescription_issued", "Prescriptions"],
    ["rating_submitted", "Ratings"],
    ["registration", "Account"],
  ];

  // The timeline service emits mixed date strings ("2026-06-08 02:00 PM",
  // "2026-06-08 14:30:00", "2026-10-07 14:00"). Parse tolerantly into a
  // sortable key plus a clean display value.
  function parseEventDate(raw) {
    const m = text(raw).match(
      /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{1,2}):(\d{2})(?::(\d{2}))?\s*(AM|PM)?)?/i
    );
    if (!m) return { key: 0, display: text(raw, "Date not recorded") };
    let hour = m[4] ? Number(m[4]) : 0;
    const minute = m[5] ? Number(m[5]) : 0;
    const suffix = (m[7] || "").toUpperCase();
    if (suffix === "PM" && hour < 12) hour += 12;
    if (suffix === "AM" && hour === 12) hour = 0;
    const date = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]), hour, minute);
    const day = new Intl.DateTimeFormat("en", {
      month: "short",
      day: "numeric",
      year: "numeric",
    }).format(date);
    const hasTime = Boolean(m[4]);
    const time = hasTime
      ? new Intl.DateTimeFormat("en", { hour: "numeric", minute: "2-digit" }).format(date)
      : "";
    return { key: date.getTime(), display: time ? day + " · " + time : day };
  }

  const state = { events: [], filter: "all", search: "", patientName: "" };

  function setView(view) {
    $("mh-loading").hidden = view !== "loading";
    $("mh-error").hidden = view !== "error";
    $("mh-empty").hidden = view !== "empty";
    $("mh-noresults").hidden = view !== "noresults";
    $("mh-content").hidden = !(view === "list" || view === "noresults");
    $("mh-download").disabled = view !== "list" && view !== "noresults";
  }

  function visibleEvents() {
    const q = state.search.trim().toLowerCase();
    return state.events.filter((event) => {
      if (state.filter !== "all" && event.type !== state.filter) return false;
      if (!q) return true;
      return (event.title + " " + event.description).toLowerCase().includes(q);
    });
  }

  function render() {
    const visible = visibleEvents();
    $("mh-count").textContent =
      "Showing " + visible.length + " of " + state.events.length + " events · newest first";
    $("mh-timeline").innerHTML = visible
      .map((event, index) => {
        const parsed = parseEventDate(event.date);
        return (
          '<li class="mh-event" style="--mh-i:' + Math.min(index, 10) + '">' +
          '<span class="mh-dot" data-type="' + esc(event.type) + '" aria-hidden="true"></span>' +
          '<div class="mh-card"><div class="mh-card-topline"><h3>' + esc(event.title) + "</h3>" +
          '<span class="mh-date">' + esc(parsed.display) + "</span></div>" +
          (event.description ? "<p>" + esc(event.description) + "</p>" : "") +
          '<span class="mh-tag">' + esc(TYPE_LABELS[event.type] || "Event") + "</span>" +
          "</div></li>"
        );
      })
      .join("");
    if (!state.events.length) setView("empty");
    else if (!visible.length) setView("noresults");
    else setView("list");
  }

  function downloadHistory() {
    const lines = [
      "HealthBridge — Medical History",
      "Synthetic demo data · Not a real medical record",
      state.patientName ? "Patient: " + state.patientName : "",
      "Generated: " + new Date().toLocaleString("en-GB"),
      "",
    ].filter(Boolean);
    const chronological = [...state.events].sort(
      (a, b) => parseEventDate(a.date).key - parseEventDate(b.date).key
    );
    for (const event of chronological) {
      lines.push(parseEventDate(event.date).display + " — " + event.title);
      if (event.description) lines.push("  " + event.description);
      lines.push("");
    }
    const blob = new Blob([lines.join("\n")], { type: "text/plain;charset=utf-8" });
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.href = url;
    link.download = "healthbridge-medical-history.txt";
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 2000);
  }

  async function load() {
    setView("loading");
    try {
      const response = await fetch(API, {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ sections: ["medical_timeline"] }),
      });
      const payload = await response.json();
      if (!response.ok || !payload.success) {
        throw new Error(payload?.data?.message || payload?.message || "Could not load your medical history.");
      }
      const events = Array.isArray(payload.data?.medical_timeline)
        ? payload.data.medical_timeline
        : [];
      state.events = events
        .map((event) => ({
          type: text(event.type, "event"),
          title: text(event.title, "Event"),
          description: text(event.description),
          date: text(event.date),
        }))
        .sort((a, b) => parseEventDate(b.date).key - parseEventDate(a.date).key);
      render();
    } catch (error) {
      console.error("Patient medical history load failed:", error);
      $("mh-error-message").textContent = error.message || "Something went wrong while loading your medical history.";
      setView("error");
    }
  }

  $("mh-filters").innerHTML = FILTERS.map(
    ([value, label]) =>
      '<button type="button" class="mh-filter" data-mh-filter="' + value + '" aria-pressed="' +
      (value === "all" ? "true" : "false") + '">' + label + "</button>"
  ).join("");

  document.addEventListener("click", (event) => {
    const filterButton = event.target.closest("[data-mh-filter]");
    if (filterButton) {
      state.filter = filterButton.dataset.mhFilter;
      document.querySelectorAll("[data-mh-filter]").forEach((button) =>
        button.setAttribute("aria-pressed", String(button === filterButton))
      );
      render();
      return;
    }
    if (event.target.closest('[data-mh-action="retry"]')) load();
  });
  $("mh-search").addEventListener("input", (event) => {
    state.search = event.target.value;
    render();
  });
  $("mh-download").addEventListener("click", downloadHistory);

  load();
})();
