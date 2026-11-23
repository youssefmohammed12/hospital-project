/*
 * HealthBridge Admin — Prescriptions audit view (Phase 4).
 *
 * READ-ONLY by design (plan Finding 8): prescription writes are
 * author-only in PrescriptionService's SQL, so an admin edit/complete/
 * cancel button could only ever 403. This page therefore offers no
 * write controls at all — it lists, filters, inspects and prints.
 *
 * Data: api/prescriptions/get.php as admin returns all 99 prescriptions
 * with their medication items inline; the detail view re-reads the
 * single record via get.php?id= so what is shown is the server's own
 * record. Print goes to api/prescriptions/print.php, which explicitly
 * permits an admin read of every prescription.
 * Filters (search incl. medication names, status, prescribing doctor,
 * issued-date range) run client-side over the one payload — at 99 rows
 * that is honest and sufficient.
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
  const rxLabel = (id) => "RX-" + String(num(id)).padStart(5, "0");
  const initialsOf = (name) => String(name || "").trim().split(/\s+/).filter(Boolean)
    .slice(0, 2).map((part) => part.charAt(0).toUpperCase()).join("") || "—";

  const PAGE_SIZE = 25;
  let prescriptions = [];
  let page = 1;

  async function api(url) {
    const response = await fetch(ROOT + url, {
      credentials: "same-origin",
      headers: { Accept: "application/json" },
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
    Active: "is-active",
    Completed: "is-done",
    Cancelled: "is-cancelled",
  }[status] || "");
  const pill = (status) => "<span class=\"admin-pill " + pillClass(status) + "\">" + esc(status || "—") + "</span>";

  /* ---------- List ---------- */

  function buildDoctorOptions() {
    const sel = $("arx-doctor");
    const current = sel.value;
    const doctors = [...new Set(prescriptions.map((p) => p.doctor_name).filter(Boolean))].sort();
    sel.innerHTML = "<option value=\"\">All doctors</option>" +
      doctors.map((d) => "<option value=\"" + esc(d) + "\">" + esc(d) + "</option>").join("");
    sel.value = doctors.includes(current) ? current : "";
  }

  function filtered() {
    const q = String($("arx-search").value || "").trim().toLowerCase();
    const status = $("arx-status").value;
    const doctor = $("arx-doctor").value;
    const from = $("arx-from").value;
    const to = $("arx-to").value;
    return prescriptions.filter((p) => {
      if (status && p.status !== status) return false;
      if (doctor && p.doctor_name !== doctor) return false;
      const issued = String(p.created_at || "").slice(0, 10);
      if (from && issued < from) return false;
      if (to && issued > to) return false;
      if (q) {
        const meds = (p.items || []).map((i) => i.medication_name).join(" ");
        const hay = [p.patient_name, p.doctor_name, p.department, meds].join(" ").toLowerCase();
        if (!hay.includes(q)) return false;
      }
      return true;
    });
  }

  function rowHtml(p) {
    const meds = (p.items || []).map((i) => i.medication_name).filter(Boolean);
    const medText = meds.length
      ? esc(meds.slice(0, 3).join(", ")) + (meds.length > 3 ? " +" + (meds.length - 3) + " more" : "")
      : "—";
    return "<tr data-id=\"" + num(p.id) + "\">" +
      "<td><span class=\"arx-id\">" + esc(rxLabel(p.id)) + "</span>" +
        "<span class=\"arx-id__sub\">Issued " + esc(fmtDate(String(p.created_at || "").slice(0, 10))) + "</span></td>" +
      "<td><div class=\"arx-pat\">" +
        "<span class=\"arx-avatar\" aria-hidden=\"true\">" + esc(initialsOf(p.patient_name)) + "</span>" +
        "<span class=\"arx-pat__name\">" + esc(p.patient_name || "—") + "</span></div></td>" +
      "<td>" + esc(p.doctor_name || "—") +
        "<span class=\"arx-id__sub\">" + esc(p.department || "") + "</span></td>" +
      "<td class=\"arx-meds\">" + medText + "</td>" +
      "<td>" + pill(p.status) + "</td>" +
      "<td><div class=\"arx-row-actions\">" +
        "<button class=\"admin-btn admin-btn--ghost\" type=\"button\" data-action=\"view\" data-id=\"" + num(p.id) + "\">View</button>" +
        "<a class=\"admin-btn admin-btn--ghost\" href=\"" + ROOT + "api/prescriptions/print.php?id=" + num(p.id) + "\" target=\"_blank\" rel=\"noopener\">Print</a>" +
      "</div></td></tr>";
  }

  function render() {
    const list = filtered();
    const pages = Math.max(1, Math.ceil(list.length / PAGE_SIZE));
    if (page > pages) page = pages;
    const slice = list.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE);
    const tbody = $("arx-tbody");
    if (!slice.length) {
      tbody.innerHTML = "<tr><td colspan=\"6\" class=\"arx-empty-cell\">No prescriptions match these filters. <button class=\"admin-btn admin-btn--ghost\" type=\"button\" id=\"arx-reset\">Clear filters</button></td></tr>";
      $("arx-reset")?.addEventListener("click", () => {
        $("arx-search").value = ""; $("arx-status").value = ""; $("arx-doctor").value = "";
        $("arx-from").value = ""; $("arx-to").value = "";
        page = 1; render();
      });
    } else {
      tbody.innerHTML = slice.map(rowHtml).join("");
    }
    $("arx-count").textContent = "Showing " + list.length + " of " + prescriptions.length;
    $("arx-page-info").textContent = list.length ? ("Page " + page + " of " + pages) : "";
    $("arx-prev").disabled = page <= 1;
    $("arx-next").disabled = page >= pages;
  }

  function renderSummary() {
    const by = (s) => prescriptions.filter((p) => p.status === s).length;
    $("arx-summary").innerHTML = "<strong>" + prescriptions.length + " prescriptions</strong> · " +
      by("Active") + " active · " + by("Completed") + " completed · " + by("Cancelled") + " cancelled";
  }

  /* ---------- Detail (read-only) ---------- */

  function openOverlay() {
    const el = $("arx-detail-overlay");
    el.hidden = false;
    requestAnimationFrame(() => el.classList.add("is-open"));
  }
  function closeOverlay() {
    const el = $("arx-detail-overlay");
    el.classList.remove("is-open");
    el.hidden = true;
  }

  function detailHtml(p) {
    const items = Array.isArray(p.items) ? p.items : [];
    const meds = items.length
      ? "<div class=\"admin-table-wrap arx-meds-wrap\"><table class=\"admin-table arx-meds-table\"><thead><tr>" +
        "<th scope=\"col\">Medication</th><th scope=\"col\">Strength</th><th scope=\"col\">Dosage</th>" +
        "<th scope=\"col\">Frequency</th><th scope=\"col\">Duration</th><th scope=\"col\">Instructions</th></tr></thead><tbody>" +
        items.map((i) =>
          "<tr><td>" + esc(i.medication_name || "—") + "</td><td>" + esc(i.strength || "—") + "</td>" +
          "<td>" + esc(i.dosage || "—") + "</td><td>" + esc(i.frequency || "—") + "</td>" +
          "<td>" + esc(i.duration || "—") + "</td><td>" + esc(i.instructions || "—") + "</td></tr>"
        ).join("") + "</tbody></table></div>"
      : "<p class=\"admin-modal__note\">No medications are recorded on this prescription.</p>";
    const dl = [
      ["Patient", p.patient_name],
      ["Prescribed by", p.doctor_name],
      ["Department", p.department],
      ["Appointment date", p.appt_date ? fmtDate(p.appt_date) : null],
      ["Issued", p.created_at ? fmtDate(String(p.created_at).slice(0, 10)) : null],
      ["Last updated", p.updated_at ? fmtDate(String(p.updated_at).slice(0, 10)) : null],
      ["Notes", p.notes],
      ["Cancellation reason", p.cancellation_reason],
    ].filter(([, v]) => v !== null && v !== undefined && String(v).trim() !== "");
    return "<dl class=\"arx-dl\">" +
      dl.map(([k, v]) => "<dt>" + esc(k) + "</dt><dd>" + esc(v) + "</dd>").join("") +
      "</dl><h4 class=\"arx-meds-title\">Medications (" + items.length + ")</h4>" + meds;
  }

  async function openDetail(id) {
    openOverlay();
    $("arx-detail-title").textContent = rxLabel(id);
    $("arx-detail-pill").innerHTML = "";
    $("arx-detail-note").textContent = "";
    $("arx-detail-print").href = ROOT + "api/prescriptions/print.php?id=" + id;
    $("arx-detail-body").innerHTML = "<p class=\"admin-modal__note\">Loading the prescription…</p>";
    try {
      const payload = await api("api/prescriptions/get.php?id=" + id);
      const p = payload.prescription;
      if (!p) throw new Error("Prescription not found.");
      $("arx-detail-pill").innerHTML = pill(p.status);
      $("arx-detail-body").innerHTML = detailHtml(p);
      $("arx-detail-note").textContent = "Read-only — only " + (p.doctor_name || "the prescribing doctor") + " can change this prescription.";
    } catch (err) {
      $("arx-detail-body").innerHTML = "<p class=\"admin-modal__note\">" + esc(err.message) + "</p>";
    }
  }

  /* ---------- Load ---------- */

  async function load() {
    try {
      const payload = await api("api/prescriptions/get.php");
      prescriptions = Array.isArray(payload.prescriptions) ? payload.prescriptions : [];
      buildDoctorOptions();
      renderSummary();
      render();
      document.getElementById("admin-main")?.removeAttribute("aria-busy");
    } catch (err) {
      $("arx-error").hidden = false;
      $("arx-tbody").innerHTML = "<tr><td colspan=\"6\" class=\"arx-empty-cell\">The prescriptions could not be loaded.</td></tr>";
      document.getElementById("admin-main")?.removeAttribute("aria-busy");
    }
  }

  function start() {
    ["arx-search", "arx-status", "arx-doctor", "arx-from", "arx-to"].forEach((id) => {
      $(id).addEventListener("input", () => { page = 1; render(); });
      $(id).addEventListener("change", () => { page = 1; render(); });
    });
    $("arx-prev").addEventListener("click", () => { if (page > 1) { page--; render(); } });
    $("arx-next").addEventListener("click", () => { page++; render(); });
    $("arx-tbody").addEventListener("click", (event) => {
      const btn = event.target.closest("button[data-action=\"view\"]");
      if (btn) openDetail(num(btn.dataset.id));
    });
    $("arx-detail-close").addEventListener("click", closeOverlay);
    $("arx-detail-done").addEventListener("click", closeOverlay);
    $("arx-detail-overlay").addEventListener("click", (event) => {
      if (event.target === event.currentTarget) closeOverlay();
    });
    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape") closeOverlay();
    });
    load();
  }

  document.addEventListener("admin:shell-ready", start, { once: true });
})();
