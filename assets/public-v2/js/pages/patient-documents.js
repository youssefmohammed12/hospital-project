/**
 * HealthBridge Public V2 — Patient Document Center (Phase 4)
 * Built new for Public V2; loads nothing from the legacy frontend.
 * Lists the documents that actually exist for the patient (prescriptions,
 * appointment confirmations, visit summaries) from the dashboard service.
 * Doctor names are rendered exactly as the service returns them — they
 * already carry the "Dr." title, so nothing is prepended (the old page
 * doubled it to "Dr. Dr.").
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

  const FILTERS = [
    ["all", "All documents"],
    ["prescription", "Prescriptions"],
    ["confirmation", "Confirmations"],
    ["visit_summary", "Visit summaries"],
  ];
  const GROUP_ORDER = ["prescription", "confirmation", "visit_summary"];
  const GROUP_TITLES = {
    prescription: "Prescriptions",
    confirmation: "Appointment confirmations",
    visit_summary: "Visit summaries",
  };
  // Document types the platform supports but this patient has none of yet.
  const EMPTY_TYPES = ["Lab reports", "Imaging reports", "Insurance claims", "Invoices", "Discharge summaries"];

  function formatDate(value) { return window.HBHelpers.formatDate(value, ""); }

  const state = { documents: [], filter: "all", query: "" };

  function setView(view) {
    $("dc-loading").hidden = view !== "loading";
    $("dc-error").hidden = view !== "error";
    $("dc-empty").hidden = view !== "empty";
    $("dc-content").hidden = view !== "list";
  }

  function visibleDocuments() {
    const q = state.query.trim().toLowerCase();
    return state.documents.filter((doc) => {
      if (state.filter !== "all" && doc.type !== state.filter) return false;
      if (!q) return true;
      return (doc.label + " " + doc.doctor + " " + doc.department).toLowerCase().includes(q);
    });
  }

  function cardHtml(doc, i) {
    const meta = [doc.doctor, doc.department, formatDate(doc.date)]
      .filter(Boolean)
      .map(esc)
      .join(" · ");
    const details = [];
    if (doc.type === "prescription" && doc.items_count != null) {
      details.push(doc.items_count + (Number(doc.items_count) === 1 ? " medication" : " medications"));
    }
    if (doc.type === "visit_summary" && doc.diagnosis) {
      details.push("Diagnosis: " + doc.diagnosis);
    }
    if (doc.type === "confirmation" && doc.time) {
      details.push(doc.time);
    }
    return (
      '<article class="dc-card" style="--dc-i:' + Math.min(i, 10) + '">' +
      '<div class="dc-card-main"><h3>' + esc(doc.label) + "</h3>" +
      (meta ? '<p class="dc-meta">' + meta + "</p>" : "") +
      (details.length ? '<p class="dc-detail">' + esc(details.join(" · ")) + "</p>" : "") +
      '</div><div class="dc-card-actions">' +
      (doc.status ? '<span class="dc-status">' + esc(doc.status) + "</span>" : "") +
      (doc.url ? '<a class="dc-open" href="' + esc(ROOT + doc.url) + '" target="_blank" rel="noopener">Open / print</a>' : "") +
      "</div></article>"
    );
  }

  function render() {
    const counts = { prescription: 0, confirmation: 0, visit_summary: 0 };
    state.documents.forEach((d) => { if (d.type in counts) counts[d.type] += 1; });
    $("dc-stat-total").textContent = state.documents.length;
    $("dc-stat-rx").textContent = counts.prescription;
    $("dc-stat-conf").textContent = counts.confirmation;
    $("dc-stat-visit").textContent = counts.visit_summary;

    $("dc-filters").innerHTML = FILTERS.map(
      ([key, label]) =>
        '<button type="button" class="dc-filter' + (state.filter === key ? " is-active" : "") +
        '" data-dc-filter="' + key + '" aria-pressed="' + (state.filter === key) + '">' + label + "</button>"
    ).join("");

    const visible = visibleDocuments();
    $("dc-count").textContent =
      "Showing " + visible.length + " of " + state.documents.length + " documents · newest first";

    let index = 0;
    $("dc-groups").innerHTML = GROUP_ORDER.map((type) => {
      const items = visible.filter((d) => d.type === type);
      if (!items.length) return "";
      return (
        '<h2 class="dc-group-title">' + GROUP_TITLES[type] + '</h2><div class="dc-list">' +
        items.map((doc) => cardHtml(doc, index++)).join("") +
        "</div>"
      );
    }).join("") || '<div class="dc-state"><h2>No documents match</h2><p>Try a different search or filter.</p></div>';

    $("dc-note").innerHTML =
      "<strong>Other document types:</strong> " + EMPTY_TYPES.join(", ").toLowerCase() +
      " will appear here automatically when one is issued for you. Nothing is missing — " +
      "you simply have no documents of those types yet.";
    setView(state.documents.length ? "list" : "empty");
  }

  async function load() {
    setView("loading");
    try {
      const response = await fetch(API, {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ sections: ["downloads"] }),
      });
      const payload = await response.json();
      if (!response.ok || !payload.success) {
        throw new Error(payload?.error?.message || payload?.message || "Could not load your documents.");
      }
      const dl = payload.data?.downloads || {};
      const collect = (list) => (Array.isArray(list) ? list : []);
      state.documents = [
        ...collect(dl.prescriptions),
        ...collect(dl.confirmations),
        ...collect(dl.visit_summaries),
      ].map((doc) => ({
        id: doc.id,
        type: text(doc.type),
        label: text(doc.label, "Document"),
        doctor: text(doc.doctor),
        department: text(doc.department),
        date: text(doc.date),
        time: text(doc.time),
        status: text(doc.status),
        items_count: doc.items_count,
        diagnosis: text(doc.diagnosis),
        url: text(doc.url),
      }));
      // Newest first inside each group.
      state.documents.sort((a, b) => text(b.date).localeCompare(text(a.date)));
      render();
    } catch (error) {
      console.error("Patient documents load failed:", error);
      $("dc-error-message").textContent = error.message || "Something went wrong while loading your documents.";
      setView("error");
    }
  }

  document.addEventListener("click", (event) => {
    const filter = event.target.closest("[data-dc-filter]");
    if (filter) { state.filter = filter.dataset.dcFilter; render(); return; }
    if (event.target.closest('[data-dc-action="retry"]')) load();
  });
  document.addEventListener("input", (event) => {
    if (event.target.id === "dc-search-input") { state.query = event.target.value; render(); }
  });

  load();
})();
