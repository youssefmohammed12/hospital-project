/**
 * HealthBridge Public V2 — Patient Prescriptions (Phase 3)
 * Built new for Public V2; loads nothing from the legacy frontend.
 * Data: POST api/patient/dashboard.php { sections: ["prescriptions"] }.
 */
(function () {
  "use strict";

  const ROOT = window.location.pathname.includes("/pages/patient/")
    ? "../../"
    : "../";
  const API = ROOT + "api/patient/dashboard.php";
  const PRINT_URL = ROOT + "api/prescriptions/print.php?id=";

  const $ = (id) => document.getElementById(id);
  const text = (v, fallback = "") => (v == null ? fallback : String(v));
  const esc = (v) =>
    text(v)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  const formatDate = (value) => {
    const m = text(value).match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (!m) return text(value, "Date to be confirmed");
    return new Intl.DateTimeFormat("en", {
      month: "short",
      day: "numeric",
      year: "numeric",
    }).format(new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3])));
  };
  const initials = (name) =>
    text(name)
      .replace(/^Dr\.\s*/i, "")
      .split(/\s+/)
      .filter(Boolean)
      .slice(0, 2)
      .map((p) => p.charAt(0))
      .join("")
      .toUpperCase() || "DR";

  const state = { prescriptions: [], activeMedications: [], counts: {}, refillRequested: new Set() };

  function setView(view) {
    $("rx-loading").hidden = view !== "loading";
    $("rx-error").hidden = view !== "error";
    $("rx-empty").hidden = view !== "empty";
    $("rx-content").hidden = view !== "list";
  }

  function statusTone(status) {
    const s = text(status).toLowerCase();
    if (s === "active") return "active";
    if (s === "completed") return "completed";
    return "other";
  }

  function renderSummary() {
    const counts = state.counts || {};
    $("rx-total").textContent = String(counts.total ?? state.prescriptions.length);
    $("rx-active-count").textContent = String(counts.active ?? 0);
    const latest = state.prescriptions[0];
    $("rx-latest").textContent = latest
      ? text(latest.doctor_name, "Your doctor")
      : "None yet";
    $("rx-latest-note").textContent = latest
      ? formatDate(latest.appointment_date || latest.created_at)
      : "New prescriptions will appear here";
  }

  function renderActive() {
    const wrap = $("rx-active-list");
    const note = $("rx-active-note");
    if (!state.activeMedications.length) {
      note.textContent =
        "No active medications right now. When a doctor prescribes an ongoing medication, it will be tracked here — and refills can be requested from its card below.";
      wrap.innerHTML = "";
      return;
    }
    note.textContent = "Medications you are currently taking:";
    wrap.innerHTML = state.activeMedications
      .map((med) => {
        const name = text(med.medication_name || med.name, "Medication");
        const detail = [med.dosage, med.frequency, med.duration]
          .map((x) => text(x))
          .filter(Boolean)
          .join(" · ");
        return (
          '<div class="rx-active-item"><strong>' + esc(name) + "</strong>" +
          (detail ? "<span>" + esc(detail) + "</span>" : "") + "</div>"
        );
      })
      .join("");
  }

  function renderCard(rx, index) {
    const items = Array.isArray(rx.items) ? rx.items : [];
    const itemsHtml = items.length
      ? '<ul class="rx-items">' +
        items
          .map((item) => {
            const name = text(item.medication_name, "Medication");
            const strength = text(item.strength);
            const schedule = [item.dosage, item.frequency, item.duration]
              .map((x) => text(x))
              .filter(Boolean)
              .join(" · ");
            const instructions = text(item.instructions);
            return (
              '<li class="rx-item"><div class="rx-item-head"><strong>' + esc(name) + "</strong>" +
              (strength ? "<span>" + esc(strength) + "</span>" : "") + "</div>" +
              (schedule ? "<p>" + esc(schedule) + "</p>" : "") +
              (instructions ? "<p>" + esc(instructions) + "</p>" : "") +
              "</li>"
            );
          })
          .join("") +
        "</ul>"
      : "";
    const isActive = text(rx.status).toLowerCase() === "active";
    const refill =
      isActive && !state.refillRequested.has(rx.id)
        ? '<button type="button" class="rx-button is-primary" data-rx-action="refill" data-rx-id="' + rx.id + '">Request refill</button>'
        : isActive
          ? '<button type="button" class="rx-button" disabled>Refill requested</button>'
          : "";
    return (
      '<article class="rx-card" style="--rx-i:' + Math.min(index, 8) + '">' +
      '<div class="rx-card-accent" aria-hidden="true"></div>' +
      '<div class="rx-card-main">' +
      '<div class="rx-card-topline"><span class="rx-reference">RX · #' + rx.id + '</span>' +
      '<span class="rx-status" data-status="' + statusTone(rx.status) + '">' + esc(text(rx.status, "Recorded")) + "</span></div>" +
      '<div class="rx-doctor"><span class="rx-avatar" aria-hidden="true">' + esc(initials(rx.doctor_name)) + "</span>" +
      "<div><h3>" + esc(text(rx.doctor_name, "Your doctor")) + "</h3>" +
      "<p>" + esc([rx.doctor_specialty, rx.department].map((x) => text(x)).filter(Boolean).join(" · ")) + "</p></div></div>" +
      '<div class="rx-meta">' +
      "<span><small>VISIT DATE</small><strong>" + esc(formatDate(rx.appointment_date)) + "</strong></span>" +
      "<span><small>MEDICATIONS</small><strong>" + (rx.items_count ?? items.length) + "</strong></span>" +
      "<span><small>DEPARTMENT</small><strong>" + esc(text(rx.department, "—")) + "</strong></span>" +
      "</div>" +
      itemsHtml +
      (rx.notes ? '<p class="rx-notes">' + esc(rx.notes) + "</p>" : "") +
      '<div class="rx-actions">' + refill +
      '<a class="rx-button" href="' + PRINT_URL + rx.id + '" target="_blank" rel="noopener">Print summary</a>' +
      "</div>" +
      "</div></article>"
    );
  }

  function render() {
    renderSummary();
    renderActive();
    $("rx-list").innerHTML = state.prescriptions.map(renderCard).join("");
    setView(state.prescriptions.length ? "list" : "empty");
  }

  async function load() {
    setView("loading");
    try {
      const response = await fetch(API, {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ sections: ["prescriptions"] }),
      });
      const payload = await response.json();
      if (!response.ok || !payload.success) {
        throw new Error(payload?.data?.message || payload?.message || "Could not load prescriptions.");
      }
      const section = payload.data?.prescriptions || {};
      state.prescriptions = Array.isArray(section.prescriptions) ? section.prescriptions : [];
      state.activeMedications = Array.isArray(section.active_medications) ? section.active_medications : [];
      state.counts = section.counts || {};
      render();
    } catch (error) {
      console.error("Patient prescriptions load failed:", error);
      $("rx-error-message").textContent = error.message || "Something went wrong while loading your prescriptions.";
      setView("error");
    }
  }

  document.addEventListener("click", (event) => {
    const refillButton = event.target.closest('[data-rx-action="refill"]');
    if (refillButton) {
      // Demo behaviour: no refill endpoint exists, so the request is shown
      // as an in-page state only — nothing is sent or stored.
      state.refillRequested.add(Number(refillButton.dataset.rxId));
      if (typeof window.showToast === "function") {
        window.showToast("Refill request noted. In this demo it is shown here only and is not sent to the clinic.", "info");
      }
      render();
      return;
    }
    if (event.target.closest('[data-rx-action="retry"]')) load();
  });

  load();
})();
