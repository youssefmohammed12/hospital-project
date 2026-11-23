/**
 * HealthBridge Public V2 — Doctor clinical workspace (Phase 3).
 * One patient + one appointment: patient banner, safety banners,
 * visit workflow stepper, and seven tabs (Overview, Medical History,
 * Current Visit, Prescriptions, Timeline, Lab Results, Files) over
 * the existing doctor/medical/prescription APIs. Presentation only —
 * every endpoint, payload and behaviour mirrors the legacy workspace:
 * workflow transitions, SOAP draft autosave (2s debounce + 12s tick),
 * visit-note save with automatic Ready-to-Complete, the complete-visit
 * review, prescription issue/view/edit/complete/cancel/print, and the
 * medical-history field editors with their timeline audit events.
 * Lab Results and Files have no data source in workspace-data (the
 * legacy tabs were disabled placeholders); they render the same
 * honest "future update" empty states, restyled.
 */
"use strict";

(() => {
  const ROOT = typeof getBasePath === "function" ? getBasePath() : "../../";
  const $ = (id) => document.getElementById(id);
  const esc = (value) => String(value ?? "").replace(/[&<>"']/g, (c) => ({
    "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;",
  })[c]);
  const toast = (message, type) => {
    if (typeof showToast === "function") showToast(message, type || "info");
  };

  const pad = (n) => String(n).padStart(2, "0");
  const fmtTime = (hhmm) => {
    const m = /^(\d{1,2}):(\d{2})/.exec(String(hhmm || ""));
    if (!m) return String(hhmm || "");
    let h = Number(m[1]);
    const suffix = h >= 12 ? "PM" : "AM";
    h = h % 12 === 0 ? 12 : h % 12;
    return h + ":" + m[2] + " " + suffix;
  };
  const fmtRange = (appt) => {
    const parts = String(appt?.appointment_time_range || "").split("-").map((s) => s.trim());
    if (parts.length === 2 && parts[0]) return fmtTime(parts[0]) + " – " + fmtTime(parts[1]);
    return fmtTime(appt?.time);
  };
  const fmtDate = (value) => {
    const raw = String(value || "");
    const d = new Date(raw.replace(" ", "T"));
    if (!raw || Number.isNaN(d.getTime())) return raw;
    return d.toLocaleDateString("en-US", { month: "short", day: "numeric", year: "numeric" });
  };
  const fmtDayLong = (ds) => {
    const d = new Date(String(ds || "") + "T00:00:00");
    if (Number.isNaN(d.getTime())) return String(ds || "");
    return d.toLocaleDateString("en-US", { weekday: "short", month: "short", day: "numeric", year: "numeric" });
  };
  const initialsOf = (name) => String(name || "").trim().split(/\s+/).filter(Boolean)
    .slice(0, 2).map((part) => part.charAt(0).toUpperCase()).join("") || "—";
  const safeId = (value) => {
    const n = Number(value);
    return Number.isSafeInteger(n) && n > 0 ? n : 0;
  };
  const safeCount = (value, fallback = 0) => {
    const n = Number(value);
    return Number.isSafeInteger(n) && n >= 0 ? n : fallback;
  };
  const normStatus = (value) => String(value || "").trim().toLowerCase().replace(/\s+/g, "_");

  async function api(url, options) {
    const response = await fetch(ROOT + url, Object.assign({
      credentials: "same-origin",
      headers: { Accept: "application/json" },
    }, options || {}));
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload?.success) {
      throw new Error(payload?.message || ("Request failed: " + url));
    }
    return payload;
  }
  const apiPost = (url, body) => api(url, {
    method: "POST",
    headers: { Accept: "application/json", "Content-Type": "application/json" },
    body: JSON.stringify(body || {}),
  });

  /* ---------- State ---------- */

  const TABS = ["overview", "medical-history", "visit", "prescriptions", "timeline", "lab-results", "files"];
  let ws = null;
  let patientId = 0;
  let appointmentId = 0;
  let activeTab = "overview";
  let doctorName = "";

  let autosaveTimer = null;
  let autosaveDebounceTimer = null;
  let autosaveDirty = false;
  let lastSavedSnapshot = "";
  let hasUnsavedChanges = false;
  let isCompletingVisit = false;
  const AUTOSAVE_INTERVAL = 12000;
  const AUTOSAVE_DEBOUNCE = 2000;

  /* ---------- Tabs ---------- */

  function switchTab(id) {
    if (!TABS.includes(id)) return;
    activeTab = id;
    document.querySelectorAll(".dws-tab").forEach((t) => {
      const on = t.getAttribute("data-tab") === id;
      t.classList.toggle("is-active", on);
      t.setAttribute("aria-selected", String(on));
      t.setAttribute("tabindex", on ? "0" : "-1");
    });
    document.querySelectorAll(".dws-panel").forEach((p) =>
      p.classList.toggle("is-active", p.id === "dws-" + id));
    if (window.location.hash !== "#" + id) {
      history.replaceState(null, "", "#" + id);
    }
  }

  function initTabs() {
    const tabs = Array.from(document.querySelectorAll(".dws-tab"));
    tabs.forEach((tab) => {
      tab.addEventListener("click", () => switchTab(tab.getAttribute("data-tab")));
      tab.addEventListener("keydown", (e) => {
        const idx = tabs.indexOf(tab);
        let next = -1;
        if (e.key === "ArrowRight" || e.key === "ArrowDown") next = (idx + 1) % tabs.length;
        else if (e.key === "ArrowLeft" || e.key === "ArrowUp") next = (idx - 1 + tabs.length) % tabs.length;
        else if (e.key === "Home") next = 0;
        else if (e.key === "End") next = tabs.length - 1;
        if (next >= 0) {
          e.preventDefault();
          switchTab(tabs[next].getAttribute("data-tab"));
          tabs[next].focus();
        }
      });
    });
  }

  /* ---------- Modals ---------- */

  function openModal(id) {
    const m = $(id);
    if (!m) return;
    m.hidden = false;
    // Next frame so the enter transition runs from the hidden state.
    requestAnimationFrame(() => requestAnimationFrame(() => m.classList.add("is-open")));
    document.body.classList.add("dws-modal-open");
    const first = m.querySelector("textarea, input:not([type='hidden']):not([readonly]), select, button:not(.dws-modal__close)");
    window.setTimeout(() => first?.focus(), 90);
  }

  function closeModal(id) {
    const m = $(id);
    if (!m) return;
    m.classList.remove("is-open");
    window.setTimeout(() => { m.hidden = true; }, 190);
    if (!document.querySelector(".dws-modal-overlay.is-open")) {
      document.body.classList.remove("dws-modal-open");
    }
  }

  function initModals() {
    document.querySelectorAll(".dws-modal-overlay").forEach((overlay) => {
      overlay.addEventListener("click", (e) => {
        if (e.target === overlay) closeModal(overlay.id);
      });
    });
    document.addEventListener("click", (e) => {
      const closer = e.target instanceof Element ? e.target.closest("[data-dws-close]") : null;
      if (closer) {
        const overlay = closer.closest(".dws-modal-overlay");
        if (overlay) closeModal(overlay.id);
      }
    });
    document.addEventListener("keydown", (e) => {
      if (e.key !== "Escape") return;
      const open = document.querySelector(".dws-modal-overlay.is-open");
      if (open) closeModal(open.id);
    });
  }

  /* ---------- Data ---------- */

  async function loadData() {
    let url = "api/doctors/workspace-data.php?patient_id=" + patientId;
    if (appointmentId) url += "&appointment_id=" + appointmentId;
    try {
      ws = await api(url);
    } catch (err) {
      const main = $("dws-root");
      if (main) {
        main.innerHTML =
          '<div class="dws-card dws-empty-state">' +
          '<span class="dws-empty-state__icon">!</span>' +
          "<h3>Failed to Load Data</h3><p>" + esc(err.message || "Could not load patient data.") + "</p>" +
          '<button class="dws-btn dws-btn--teal" type="button" data-dws-action="retry">Try Again</button></div>';
      }
      return;
    }
    renderAll();
    restoreDraft();
  }

  function renderAll() {
    if (!ws) return;
    renderBanner();
    updateStepper();
    renderOverview();
    renderMedicalHistory();
    renderVisit();
    renderPrescriptions();
    renderTimeline();
  }

  /* ---------- Workflow helpers ---------- */

  function workflowOf(appt) {
    const wf = appt?.visit_workflow;
    if (wf && typeof wf.status === "string" && wf.status.trim()) return wf.status;
    const s = String(appt?.status || "");
    if (s.toLowerCase() === "confirmed") return "Waiting";
    return s || "Unknown";
  }

  function pillForWorkflow(statusLabel) {
    const key = normStatus(statusLabel);
    if (key === "waiting") return { text: "Waiting", cls: " is-waiting" };
    if (key === "in_progress") return { text: "In Progress", cls: " is-progress" };
    if (key === "ready_to_complete") return { text: "Ready to Complete", cls: " is-ready" };
    if (key === "completed") return { text: "Completed", cls: " is-done" };
    return { text: statusLabel || "—", cls: "" };
  }

  function pillForApptStatus(status) {
    const key = normStatus(status);
    if (key === "completed") return { text: status, cls: " is-done" };
    if (key === "cancelled") return { text: status, cls: " is-cancelled" };
    if (key === "pending") return { text: status, cls: " is-pending" };
    return { text: status || "—", cls: "" };
  }

  function pillForRxStatus(status) {
    const key = normStatus(status);
    if (key === "active") return { text: status, cls: " is-progress" };
    if (key === "completed") return { text: status, cls: " is-done" };
    if (key === "cancelled") return { text: status, cls: " is-cancelled" };
    return { text: status || "—", cls: "" };
  }

  /* ---------- Patient banner + stepper ---------- */

  function renderBanner() {
    const p = ws.patient;
    const mr = ws.medical_record || {};
    const cv = ws.current_visit;
    if (!p) return;
    const host = $("dws-banner-main");
    const actionsHost = $("dws-banner-actions");
    if (!host || !actionsHost) return;

    const label = workflowOf(cv);
    const pill = pillForWorkflow(label);
    const meta = [];
    if (ws.age !== null && ws.age !== undefined) meta.push(safeCount(ws.age) + "y");
    if (mr.gender) meta.push(mr.gender);
    if (mr.blood_type) meta.push("Blood " + mr.blood_type);
    if (p.phone) meta.push(p.phone);
    const safety = [];
    if (ws.allergies) safety.push("Allergies: " + (ws.allergies.length > 26 ? ws.allergies.slice(0, 26) + "…" : ws.allergies));
    if (ws.chronic_diseases) safety.push("Chronic: " + (ws.chronic_diseases.length > 26 ? ws.chronic_diseases.slice(0, 26) + "…" : ws.chronic_diseases));

    host.innerHTML =
      '<div class="dws-banner__id">' +
        '<span class="dws-banner__avatar" aria-hidden="true">' + esc(initialsOf(p.name)) + "</span>" +
        '<div class="dws-banner__who">' +
          '<p class="doctor-page__eyebrow">Patient</p>' +
          "<h1>" + esc(p.name) + "</h1>" +
          '<p class="dws-banner__meta">' + esc(meta.join(" · ")) + "</p>" +
          (safety.length ? '<p class="dws-banner__safety">' + esc(safety.join("  ·  ")) + "</p>" : "") +
        "</div>" +
        '<span class="doctor-pill dws-banner__pill' + pill.cls + '">' + esc(pill.text) + "</span>" +
      "</div>" +
      (cv ?
        '<div class="dws-banner__visit">' +
          "<div><span>Appointment</span><strong>" + esc(fmtDayLong(cv.date)) + " · " + esc(fmtRange(cv)) + "</strong></div>" +
          "<div><span>Department</span><strong>" + esc(cv.department || "—") + "</strong></div>" +
          "<div><span>Doctor</span><strong>" + esc(doctorName || "—") + "</strong></div>" +
        "</div>" : "");

    const key = normStatus(label);
    let actions = "";
    if (key === "waiting") {
      actions += '<button class="dws-btn dws-btn--teal" type="button" data-dws-action="start-visit">Start Visit</button>';
    } else if (key === "in_progress" || key === "ready_to_complete") {
      actions += '<button class="dws-btn dws-btn--teal" type="button" data-dws-tab="visit">Continue Visit</button>';
    } else if (key === "completed") {
      actions += '<button class="dws-btn dws-btn--ghost" type="button" disabled>Visit Completed</button>';
    }
    actions += '<button class="dws-btn dws-btn--ghost" type="button" data-dws-action="open-rx">Issue Prescription</button>';
    actions += '<button class="dws-icon-btn" type="button" data-dws-action="refresh" aria-label="Refresh workspace" title="Refresh">' +
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 12a8 8 0 1 1-2.34-5.66M20 4v5h-5"/></svg></button>';
    actionsHost.innerHTML = actions;
  }

  function updateStepper() {
    const cv = ws.current_visit;
    const key = normStatus(workflowOf(cv));
    let active = 1;
    if (key === "in_progress") active = 2;
    else if (key === "ready_to_complete") active = 3;
    else if (key === "completed") active = 4;
    const done = key === "completed";
    document.querySelectorAll(".dws-step").forEach((step) => {
      const n = Number(step.getAttribute("data-step"));
      step.classList.toggle("is-done", done || n < active);
      step.classList.toggle("is-active", !done && n === active);
    });
    document.querySelectorAll(".dws-step__fill").forEach((fill, idx) => {
      fill.style.transform = (done || idx + 1 < active) ? "scaleX(1)" : "scaleX(0)";
    });
    const bar = $("dws-steps");
    if (bar) bar.setAttribute("aria-valuenow", String(done ? 4 : active));
  }

  /* ---------- Overview tab ---------- */

  function infoItem(label, valueHtml, wide) {
    return '<div class="dws-info-item' + (wide ? " dws-info-item--wide" : "") + '">' +
      '<span class="dws-info-label">' + esc(label) + "</span>" +
      '<span class="dws-info-value">' + valueHtml + "</span></div>";
  }

  function renderOverview() {
    const host = $("dws-overview");
    if (!host) return;
    const p = ws.patient;
    const mr = ws.medical_record || {};
    const cv = ws.current_visit;
    const cvn = ws.current_visit_note;
    const latestAppt = ws.appointments?.[0] || null;
    const latestRx = ws.prescriptions?.[0] || null;
    const latestNote = ws.visit_notes?.[0] || null;

    let html = "";
    // Safety banners (legacy placement: top of Overview).
    if (ws.allergies) html += '<div class="dws-alert dws-alert--danger"><h4>Allergies</h4><p>' + esc(ws.allergies) + "</p></div>";
    if (ws.chronic_diseases) html += '<div class="dws-alert dws-alert--warning"><h4>Chronic Conditions</h4><p>' + esc(ws.chronic_diseases) + "</p></div>";
    if (ws.current_medications) html += '<div class="dws-alert dws-alert--info"><h4>Current Medications</h4><p>' + esc(ws.current_medications) + "</p></div>";

    if (cv) {
      const pill = pillForWorkflow(workflowOf(cv));
      html += '<div class="dws-card"><div class="dws-card__head"><h3>Current Visit</h3>' +
        '<span class="doctor-pill' + pill.cls + '">' + esc(pill.text) + "</span></div>" +
        '<div class="dws-info-grid">' +
        infoItem("Date", esc(fmtDayLong(cv.date))) +
        infoItem("Time", esc(fmtRange(cv))) +
        infoItem("Department", esc(cv.department || "—")) +
        infoItem("Visit Note", cvn ? '<span class="dws-ok">Recorded</span>' : '<span class="dws-warn">Pending</span>') +
        "</div></div>";
    }

    html += '<div class="dws-card"><div class="dws-card__head"><h3>Patient Information</h3>' +
      '<span class="dws-card__note">Administrative — Read Only</span></div>' +
      '<div class="dws-info-grid">' +
      infoItem("Full Name", esc(p.name)) +
      infoItem("Gender", esc(mr.gender || "Not specified")) +
      infoItem("Age", esc(ws.age !== null && ws.age !== undefined ? String(safeCount(ws.age)) : "—")) +
      infoItem("Blood Type", esc(mr.blood_type || "Not recorded")) +
      infoItem("Email", esc(p.email || "—")) +
      infoItem("Phone", esc(p.phone || "—")) +
      infoItem("Emergency Contact", esc(mr.emergency_contact_name || "None")) +
      infoItem("Emergency Phone", esc(mr.emergency_contact_phone || "None")) +
      "</div></div>";

    html += '<div class="dws-latest-grid">';
    // Latest appointment
    html += '<div class="dws-card"><div class="dws-card__head"><h3>Latest Appointment</h3></div>';
    if (latestAppt) {
      const pill = pillForApptStatus(latestAppt.status);
      html += '<div class="dws-info-grid dws-info-grid--single">' +
        infoItem("Date", esc(fmtDayLong(latestAppt.date))) +
        infoItem("Department", esc(latestAppt.department || "—")) +
        infoItem("Status", '<span class="doctor-pill' + pill.cls + '">' + esc(pill.text) + "</span>") +
        "</div>";
    } else html += '<p class="doctor-empty">No appointments.</p>';
    html += "</div>";
    // Latest prescription
    html += '<div class="dws-card"><div class="dws-card__head"><h3>Latest Prescription</h3></div>';
    if (latestRx) {
      const pill = pillForRxStatus(latestRx.status);
      html += '<div class="dws-info-grid dws-info-grid--single">' +
        infoItem("Date", esc(fmtDate(latestRx.created_at))) +
        infoItem("Items", esc(safeCount(latestRx.item_count) + " meds")) +
        infoItem("Status", '<span class="doctor-pill' + pill.cls + '">' + esc(pill.text) + "</span>") +
        "</div>";
    } else html += '<p class="doctor-empty">No prescriptions.</p>';
    html += "</div>";
    // Latest visit note
    html += '<div class="dws-card"><div class="dws-card__head"><h3>Latest Visit Note</h3></div>';
    if (latestNote) {
      html += '<div class="dws-info-grid dws-info-grid--single">' +
        infoItem("Date", esc(fmtDayLong(latestNote.appt_date))) +
        infoItem("Doctor", esc(latestNote.doctor_name || "—")) +
        infoItem("Diagnosis", esc(latestNote.diagnosis || "—"), true) +
        "</div>";
    } else html += '<p class="doctor-empty">No visit notes.</p>';
    html += "</div></div>";

    html += '<div class="dws-quick-actions">' +
      '<button class="dws-btn dws-btn--teal" type="button" data-dws-tab="visit">Start Visit</button>' +
      '<button class="dws-btn dws-btn--ghost" type="button" data-dws-tab="medical-history">Medical History</button>' +
      '<button class="dws-btn dws-btn--ghost" type="button" data-dws-tab="prescriptions">Prescriptions</button>' +
      '<button class="dws-btn dws-btn--ghost" type="button" data-dws-tab="timeline">Timeline</button>' +
      "</div>";

    host.innerHTML = html;
  }

  /* ---------- Medical History tab ---------- */

  const MH_FIELDS = {
    blood_type: { title: "Set Blood Type", label: "Blood Type", kind: "blood" },
    allergies: { title: "Update Allergies", label: "Known Allergies", placeholder: "List known allergies (e.g., Penicillin, Peanuts, Latex)", max: 2000 },
    chronic_diseases: { title: "Update Chronic Conditions", label: "Chronic Diseases / Conditions", placeholder: "List chronic diseases (e.g., Type 2 Diabetes, Hypertension, Asthma)", max: 2000 },
    current_medications: { title: "Update Current Medications", label: "Current Medications", placeholder: "List current medications (e.g., Metformin 500mg, Lisinopril 10mg)", max: 2000 },
    previous_surgeries: { title: "Update Previous Surgeries", label: "Previous Surgeries", placeholder: "List previous surgeries with dates", max: 2000 },
    family_history: { title: "Update Family History", label: "Family Medical History", placeholder: "List relevant family medical history", max: 2000 },
    medical_notes: { title: "Update Medical Notes", label: "Medical Notes", placeholder: "General medical notes or observations", max: 5000 },
  };

  function mhValue(field) {
    const mr = ws.medical_record || {};
    if (field === "allergies") return ws.allergies || "";
    if (field === "chronic_diseases") return ws.chronic_diseases || "";
    if (field === "current_medications") return ws.current_medications || "";
    return mr[field] || "";
  }

  function renderMedicalHistory() {
    const host = $("dws-medical-history");
    if (!host) return;
    const mr = ws.medical_record || {};
    const card = (field, title, value, emptyText, actionHtml) =>
      '<div class="dws-mh-card"><div class="dws-mh-card__head"><h3>' + esc(title) + "</h3>" +
      (field === "blood_type" && value ? '<span class="dws-mh-badge">Verified</span>' : "") + "</div>" +
      '<p class="dws-mh-card__body">' + (value ? esc(value) : esc(emptyText)) + "</p>" +
      '<div class="dws-mh-card__actions">' + actionHtml + "</div></div>";
    const editBtn = (field, label) =>
      '<button class="dws-btn dws-btn--ghost dws-btn--sm" type="button" data-dws-action="edit-field" data-field="' + field + '">' + esc(label) + "</button>";

    let html = '<div class="dws-mh-grid">';
    html += card("blood_type", "Blood Type", mr.blood_type || "", "Not recorded",
      mr.blood_type ? "" : editBtn("blood_type", "Set Blood Type"));
    html += card("allergies", "Allergies", ws.allergies || "", "None recorded", editBtn("allergies", "Edit"));
    html += card("chronic_diseases", "Chronic Conditions", ws.chronic_diseases || "", "None recorded", editBtn("chronic_diseases", "Edit"));
    html += card("current_medications", "Current Medications", ws.current_medications || "", "None recorded", editBtn("current_medications", "Edit"));
    html += card("previous_surgeries", "Previous Surgeries", mr.previous_surgeries || "", "None recorded", editBtn("previous_surgeries", "Edit"));
    html += card("family_history", "Family History", mr.family_history || "", "None recorded", editBtn("family_history", "Edit"));
    html += card("medical_notes", "Medical Notes", mr.medical_notes || "", "No notes", editBtn("medical_notes", "Edit Notes"));
    html += "</div>";
    html += '<div class="dws-mh-audit"><span>Last Updated</span><strong>' +
      (mr.updated_at ? esc((doctorName || "Doctor") + " · " + fmtDate(mr.updated_at)) : "Not yet updated") +
      "</strong></div>";
    host.innerHTML = html;
  }

  function openFieldEditor(field) {
    const def = MH_FIELDS[field];
    if (!def) return;
    $("dws-mh-field").value = field;
    $("dws-mh-title").textContent = def.title;
    const isBlood = def.kind === "blood";
    $("dws-mh-text-wrap").hidden = isBlood;
    $("dws-mh-blood-wrap").hidden = !isBlood;
    if (isBlood) {
      $("dws-mh-blood").value = "";
      $("dws-mh-submit").textContent = "Set Blood Type";
    } else {
      $("dws-mh-label").textContent = def.label;
      const input = $("dws-mh-input");
      input.value = mhValue(field);
      input.placeholder = def.placeholder || "";
      input.maxLength = def.max || 2000;
      $("dws-mh-submit").textContent = "Save";
    }
    openModal("dws-mh-modal");
  }

  async function saveClinicalField(field, value) {
    const oldValue = mhValue(field);
    const btn = $("dws-mh-submit");
    const orig = btn.textContent;
    btn.disabled = true;
    btn.textContent = "Saving...";
    try {
      await apiPost("api/medical/update-record.php", { patient_id: patientId, [field]: value || null });
    } catch (err) {
      toast(err.message || "Failed to update medical record.", "error");
      btn.disabled = false;
      btn.textContent = orig;
      return;
    }
    btn.disabled = false;
    btn.textContent = orig;
    toast("Medical record updated successfully.", "success");
    // Record the update for the timeline (best-effort, as legacy).
    try {
      await apiPost("api/medical/add-history-update.php", {
        patient_id: patientId, field, old_value: oldValue, new_value: value,
      });
    } catch { /* the update itself already succeeded */ }
    closeModal("dws-mh-modal");
    await loadData();
  }

  /* ---------- Current Visit tab ---------- */

  async function startVisit() {
    const cv = ws.current_visit;
    if (!cv) { toast("No active visit to start.", "error"); return; }
    try {
      const payload = await apiPost("api/medical/transition-workflow.php", {
        appointment_id: cv.id, status: "In Progress",
      });
      if (payload.workflow && ws.current_visit) ws.current_visit.visit_workflow = payload.workflow;
      toast("Visit started!", "success");
      renderBanner();
      updateStepper();
      renderVisit();
      switchTab("visit");
      window.setTimeout(() => $("dws-vf-dx")?.focus(), 300);
    } catch (err) {
      toast(err.message || "Could not start visit.", "error");
    }
  }

  function visitInfoCard(cv, pill) {
    return '<div class="dws-card"><div class="dws-card__head"><h3>Current Visit</h3>' +
      '<span class="doctor-pill' + pill.cls + '">' + esc(pill.text) + "</span></div>" +
      '<div class="dws-info-grid">' +
      infoItem("Appointment ID", "#" + safeId(cv.id)) +
      infoItem("Department", esc(cv.department || "—")) +
      infoItem("Date", esc(fmtDayLong(cv.date))) +
      infoItem("Time", esc(fmtRange(cv))) +
      (cv.notes ? infoItem("Patient Notes", esc(cv.notes), true) : "") +
      "</div></div>";
  }

  function renderVisit() {
    const host = $("dws-visit");
    if (!host) return;
    const cv = ws.current_visit;
    const cvn = ws.current_visit_note;

    if (!cv) {
      host.innerHTML = '<div class="dws-card dws-empty-state">' +
        "<h3>No Active Visit</h3>" +
        "<p>There is no confirmed appointment for this patient. Select a confirmed appointment to begin a visit.</p>" +
        '<a class="dws-btn dws-btn--teal" href="appointments.html">Appointments</a></div>';
      return;
    }
    if (!safeId(cv.id)) {
      host.innerHTML = '<div class="dws-card dws-empty-state">' +
        "<h3>Invalid Appointment Reference</h3>" +
        "<p>The appointment reference could not be validated. Return to the appointment list and reopen the patient workspace.</p></div>";
      return;
    }

    const label = workflowOf(cv);
    const key = normStatus(label);
    const pill = pillForWorkflow(label);

    if (key === "waiting") {
      host.innerHTML = '<div class="dws-card dws-empty-state">' +
        '<span class="doctor-pill' + pill.cls + '">' + esc(pill.text) + "</span>" +
        "<h3>Ready to Start</h3>" +
        "<p>This appointment is confirmed and waiting for you to begin.</p>" +
        '<button class="dws-btn dws-btn--teal" type="button" data-dws-action="start-visit">Start Visit</button></div>';
      return;
    }

    if (key === "completed") {
      let html = visitInfoCard(cv, pill);
      if (cvn) {
        html += '<div class="dws-card"><div class="dws-card__head"><h3>Visit Note</h3>' +
          '<span class="doctor-pill is-done">Completed</span></div>' +
          '<div class="dws-info-grid">' +
          infoItem("Diagnosis", esc(cvn.diagnosis || "—"), true) +
          infoItem("Symptoms", esc(cvn.symptoms || "—"), true) +
          infoItem("Treatment Plan", esc(cvn.treatment || "—"), true) +
          (cvn.doctor_notes ? infoItem("Doctor's Notes", esc(cvn.doctor_notes), true) : "") +
          (cvn.follow_up_instructions ? infoItem("Follow-up Instructions", esc(cvn.follow_up_instructions), true) : "") +
          "</div></div>";
      }
      html += '<div class="dws-card dws-empty-state">' +
        "<h3>Visit Completed</h3>" +
        "<p>This visit has been completed and the patient record has been updated.</p></div>";
      host.innerHTML = html;
      return;
    }

    // In Progress / Ready to Complete — the SOAP form.
    const note = cvn || {};
    const isNew = !cvn;
    host.innerHTML =
      visitInfoCard(cv, pill) +
      '<div class="dws-card"><div class="dws-card__head"><h3>' + (isNew ? "Add Visit Note" : "Edit Visit Note") + "</h3>" +
        '<span class="dws-card__note">' + (isNew ? "" : "Saved · ") + "Ctrl+S to save</span></div>" +
        '<form id="dws-vf" class="dws-visit-form" novalidate>' +
          '<input type="hidden" id="dws-vf-appt" value="' + safeId(cv.id) + '" />' +
          '<div class="dws-field"><label for="dws-vf-dx">Diagnosis <span>(primary diagnosis or impression) *</span></label>' +
            '<input type="text" id="dws-vf-dx" placeholder="e.g. Acute bronchitis, Hypertension stage 1" maxlength="500" value="' + esc(note.diagnosis || "") + '" /></div>' +
          '<div class="dws-field"><label for="dws-vf-sx">Symptoms <span>(patient-reported complaints) *</span></label>' +
            '<textarea id="dws-vf-sx" placeholder="e.g. Cough with sputum, fever 38.5°C for 3 days" maxlength="2000">' + esc(note.symptoms || "") + "</textarea></div>" +
          '<div class="dws-field"><label for="dws-vf-tx">Treatment Plan <span>(procedures, referrals, follow-up) *</span></label>' +
            '<textarea id="dws-vf-tx" placeholder="e.g. Prescribed antibiotics, follow-up in 7 days" maxlength="2000">' + esc(note.treatment || "") + "</textarea></div>" +
          '<div class="dws-field"><label for="dws-vf-notes">Doctor\'s Notes <span>(clinical observations, assessment — optional)</span></label>' +
            '<textarea id="dws-vf-notes" class="dws-field--tall" placeholder="Clinical observations and assessment..." maxlength="5000">' + esc(note.doctor_notes || "") + "</textarea></div>" +
          '<div class="dws-field"><label for="dws-vf-followup">Follow-up Instructions <span>(optional)</span></label>' +
            '<textarea id="dws-vf-followup" placeholder="e.g. Return in 2 weeks for blood work, call if symptoms worsen" maxlength="1000">' + esc(note.follow_up_instructions || "") + "</textarea></div>" +
          '<div class="dws-visit-actions">' +
            '<button type="submit" class="dws-btn dws-btn--teal" id="dws-vf-submit">' + (isNew ? "Save Visit Note" : "Update Visit Note") + "</button>" +
            '<button type="button" class="dws-btn dws-btn--ghost" data-dws-action="open-rx">Issue Prescription</button>' +
            '<button type="button" class="dws-btn dws-btn--gold" data-dws-action="open-review">Review &amp; Complete</button>' +
          "</div>" +
        "</form></div>";

    $("dws-vf").addEventListener("submit", handleVisitSave);
    setupAutosave();
    ["dws-vf-dx", "dws-vf-sx", "dws-vf-tx"].forEach((id) => {
      $(id)?.addEventListener("input", debouncedStatusCheck);
    });
    if (activeTab === "visit") {
      window.setTimeout(() => $("dws-vf-dx")?.focus(), 200);
    }
  }

  async function handleVisitSave(e) {
    e.preventDefault();
    const apptId = safeId($("dws-vf-appt")?.value);
    const diagnosis = $("dws-vf-dx")?.value.trim() || "";
    const symptoms = $("dws-vf-sx")?.value.trim() || "";
    const treatment = $("dws-vf-tx")?.value.trim() || "";
    const doctorNotes = $("dws-vf-notes")?.value.trim() || "";
    const followup = $("dws-vf-followup")?.value.trim() || "";
    if (!diagnosis && !symptoms && !treatment && !doctorNotes) {
      toast("Please fill in at least one field.", "error");
      return;
    }
    const btn = $("dws-vf-submit");
    const orig = btn.textContent;
    btn.disabled = true;
    btn.textContent = "Saving...";
    try {
      await apiPost("api/medical/add-visit-note.php", {
        appointment_id: apptId, patient_id: patientId,
        diagnosis, symptoms, treatment,
        doctor_notes: doctorNotes, follow_up_instructions: followup,
      });
      toast("Visit note saved!", "success");
      hasUnsavedChanges = false;
      await checkAndUpdateVisitStatus(diagnosis, symptoms, treatment);
      await loadData();
    } catch (err) {
      toast(err.message || "Failed to save.", "error");
    } finally {
      btn.disabled = false;
      btn.textContent = orig;
    }
  }

  function validateVisit() {
    const fields = [
      ["dws-vf-dx", "Diagnosis is required."],
      ["dws-vf-sx", "Symptoms are required."],
      ["dws-vf-tx", "Treatment Plan is required."],
    ];
    const errors = [];
    fields.forEach(([id, message]) => {
      const field = $(id);
      if (!field) return;
      field.classList.remove("dws-field--error");
      field.parentElement.querySelector(".dws-field-error")?.remove();
      if (!field.value.trim()) {
        errors.push(message);
        field.classList.add("dws-field--error");
        const msg = document.createElement("p");
        msg.className = "dws-field-error";
        msg.textContent = message.replace(/\.$/, "");
        field.parentElement.appendChild(msg);
      }
    });
    return { valid: errors.length === 0, errors };
  }

  async function checkAndUpdateVisitStatus(diagnosis, symptoms, treatment) {
    const cv = ws.current_visit;
    if (!cv) return;
    const key = normStatus(workflowOf(cv));
    if (key === "completed") return;
    let target = null;
    if (diagnosis && symptoms && treatment) {
      if (key !== "ready_to_complete") target = "Ready to Complete";
    } else if (key !== "in_progress" && key !== "waiting") {
      target = "In Progress";
    }
    if (!target) return;
    try {
      const payload = await apiPost("api/medical/transition-workflow.php", {
        appointment_id: cv.id, status: target,
      });
      if (payload.workflow && ws.current_visit) {
        ws.current_visit.visit_workflow = payload.workflow;
        renderBanner();
        updateStepper();
      }
    } catch { /* status stays as the server has it */ }
  }

  let statusCheckTimeout = null;
  function debouncedStatusCheck() {
    if (statusCheckTimeout) clearTimeout(statusCheckTimeout);
    statusCheckTimeout = setTimeout(() => {
      checkAndUpdateVisitStatus(
        $("dws-vf-dx")?.value.trim() || "",
        $("dws-vf-sx")?.value.trim() || "",
        $("dws-vf-tx")?.value.trim() || "",
      );
    }, 500);
  }

  /* ---------- Complete visit ---------- */

  function openReviewModal() {
    const validation = validateVisit();
    const cv = ws.current_visit;
    const rx = Array.isArray(ws.prescriptions) ? ws.prescriptions : [];
    const has = (id) => !!($(id)?.value.trim());
    const row = (ok, label) =>
      '<div class="dws-review-item ' + (ok ? "is-ok" : "is-missing") + '">' +
      '<span class="dws-review-item__mark" aria-hidden="true">' + (ok ? "✓" : "✕") + "</span><span>" + esc(label) + "</span></div>";
    $("dws-review-checklist").innerHTML =
      row(has("dws-vf-dx"), "Diagnosis") +
      row(has("dws-vf-sx"), "Symptoms") +
      row(has("dws-vf-tx"), "Treatment Plan") +
      row(rx.length > 0, "Prescription " + (rx.length > 0 ? "(" + rx.length + " issued)" : "(none issued)"));
    $("dws-review-summary").innerHTML = cv ?
      '<div class="dws-info-grid dws-info-grid--single">' +
      infoItem("Patient", esc(ws.patient?.name || "—")) +
      infoItem("Appointment", esc(fmtDayLong(cv.date) + " · " + fmtRange(cv))) +
      infoItem("Department", esc(cv.department || "—")) +
      "</div>" : "";
    const errorsDiv = $("dws-review-errors");
    const confirmBtn = $("dws-confirm-complete");
    if (!validation.valid) {
      errorsDiv.hidden = false;
      errorsDiv.innerHTML = "Please complete the following before finishing:<ul>" +
        validation.errors.map((e) => "<li>" + esc(e) + "</li>").join("") + "</ul>";
      confirmBtn.disabled = true;
    } else {
      errorsDiv.hidden = true;
      errorsDiv.innerHTML = "";
      confirmBtn.disabled = false;
    }
    openModal("dws-review-modal");
  }

  async function completeVisit() {
    const cv = ws.current_visit;
    if (!cv) { toast("No active visit.", "error"); return; }
    isCompletingVisit = true;
    const btn = $("dws-confirm-complete");
    const orig = btn.textContent;
    btn.disabled = true;
    btn.textContent = "Completing...";
    try {
      await apiPost("api/medical/complete-visit.php", {
        appointment_id: cv.id,
        diagnosis: $("dws-vf-dx")?.value.trim() || "",
        symptoms: $("dws-vf-sx")?.value.trim() || "",
        treatment: $("dws-vf-tx")?.value.trim() || "",
        doctor_notes: $("dws-vf-notes")?.value.trim() || "",
        follow_up_instructions: $("dws-vf-followup")?.value.trim() || "",
      });
      toast("Visit completed successfully!", "success");
      hasUnsavedChanges = false;
      closeModal("dws-review-modal");
      await loadData();
      switchTab("overview");
    } catch (err) {
      toast(err.message || "Could not complete visit.", "error");
    } finally {
      btn.disabled = false;
      btn.textContent = orig;
      isCompletingVisit = false;
    }
  }

  /* ---------- Draft autosave ---------- */

  function setupAutosave() {
    if (autosaveTimer) { clearInterval(autosaveTimer); autosaveTimer = null; }
    if (autosaveDebounceTimer) { clearTimeout(autosaveDebounceTimer); autosaveDebounceTimer = null; }
    const form = $("dws-vf");
    if (!form) return;
    form.querySelectorAll("input, textarea").forEach((input) => {
      input.addEventListener("input", () => {
        autosaveDirty = true;
        hasUnsavedChanges = true;
        if (autosaveDebounceTimer) clearTimeout(autosaveDebounceTimer);
        autosaveDebounceTimer = setTimeout(() => { if (autosaveDirty) saveDraftNow(); }, AUTOSAVE_DEBOUNCE);
      });
    });
    autosaveTimer = setInterval(() => { if (autosaveDirty) saveDraftNow(); }, AUTOSAVE_INTERVAL);
  }

  function formSnapshot() {
    return JSON.stringify({
      diagnosis: $("dws-vf-dx")?.value || "",
      symptoms: $("dws-vf-sx")?.value || "",
      treatment: $("dws-vf-tx")?.value || "",
      doctorNotes: $("dws-vf-notes")?.value || "",
      followup: $("dws-vf-followup")?.value || "",
    });
  }

  function formIsEmpty() {
    return ["dws-vf-dx", "dws-vf-sx", "dws-vf-tx", "dws-vf-notes", "dws-vf-followup"]
      .every((id) => !($(id)?.value.trim()));
  }

  async function saveDraftNow() {
    if (!appointmentId || formIsEmpty()) { autosaveDirty = false; return; }
    const snapshot = formSnapshot();
    if (snapshot === lastSavedSnapshot) { autosaveDirty = false; return; }
    showAutosave("saving");
    try {
      await apiPost("api/medical/save-visit-draft.php", {
        appointment_id: appointmentId,
        diagnosis: $("dws-vf-dx")?.value.trim() || "",
        symptoms: $("dws-vf-sx")?.value.trim() || "",
        treatment: $("dws-vf-tx")?.value.trim() || "",
        doctor_notes: $("dws-vf-notes")?.value.trim() || "",
        follow_up_instructions: $("dws-vf-followup")?.value.trim() || "",
      });
      lastSavedSnapshot = snapshot;
      autosaveDirty = false;
      hasUnsavedChanges = false;
      showAutosave("saved");
    } catch {
      showAutosave("error");
    }
  }

  async function restoreDraft() {
    if (!appointmentId || !ws.current_visit) return;
    const key = normStatus(workflowOf(ws.current_visit));
    if (key === "completed") {
      try {
        await apiPost("api/medical/delete-visit-draft.php", { appointment_id: appointmentId });
      } catch { /* nothing to clean */ }
      lastSavedSnapshot = "";
      autosaveDirty = false;
      hasUnsavedChanges = false;
      return;
    }
    if (key === "waiting") return; // form not rendered yet
    let payload;
    try {
      payload = await api("api/medical/get-visit-draft.php?appointment_id=" + appointmentId);
    } catch { return; }
    const draft = payload.draft;
    if (!draft) return;
    const note = ws.current_visit_note;
    if (note && (note.diagnosis || note.symptoms || note.treatment)) return;
    if ($("dws-vf-dx") && draft.diagnosis) $("dws-vf-dx").value = draft.diagnosis;
    if ($("dws-vf-sx") && draft.symptoms) $("dws-vf-sx").value = draft.symptoms;
    if ($("dws-vf-tx") && draft.treatment) $("dws-vf-tx").value = draft.treatment;
    if ($("dws-vf-notes") && draft.doctor_notes) $("dws-vf-notes").value = draft.doctor_notes;
    if ($("dws-vf-followup") && draft.follow_up_instructions) $("dws-vf-followup").value = draft.follow_up_instructions;
    lastSavedSnapshot = formSnapshot();
    showAutosave("restored");
  }

  function showAutosave(type) {
    const indicator = $("dws-autosave");
    const text = $("dws-autosave-text");
    if (!indicator || !text) return;
    indicator.classList.remove("is-saving", "is-error");
    if (type === "saved") text.textContent = "Draft saved";
    else if (type === "saving") { text.textContent = "Saving..."; indicator.classList.add("is-saving"); }
    else if (type === "error") { text.textContent = "Save failed"; indicator.classList.add("is-error"); }
    else if (type === "restored") text.textContent = "Draft restored";
    indicator.classList.add("is-visible");
    clearTimeout(indicator._hideTimer);
    indicator._hideTimer = setTimeout(() => indicator.classList.remove("is-visible"), 3000);
  }

  /* ---------- Prescriptions tab ---------- */

  const rxIsEditable = (status) => status === "Active";

  function rxItemsHtml(rx) {
    const items = Array.isArray(rx.items) ? rx.items.filter((i) => i && typeof i === "object") : [];
    if (!items.length) return '<p class="dws-rx-noitems">No details</p>';
    return items.map((i) =>
      '<div class="dws-rx-item"><span class="dws-rx-item__name">' + esc(i.medication_name) + "</span>" +
      '<span class="dws-rx-item__detail">' + esc([i.strength, i.dosage, i.frequency, i.duration].filter(Boolean).join(" — ")) + "</span></div>"
    ).join("");
  }

  function renderPrescriptions() {
    const host = $("dws-prescriptions");
    if (!host) return;
    const rx = (Array.isArray(ws.prescriptions) ? ws.prescriptions : [])
      .filter((r) => r && typeof r === "object");
    const cv = ws.current_visit;
    const isCompleted = normStatus(workflowOf(cv)) === "completed";

    if (!rx.length) {
      host.innerHTML = '<div class="dws-card dws-empty-state">' +
        "<h3>No Prescription Issued</h3>" +
        "<p>No prescriptions have been issued for this visit.</p>" +
        '<button class="dws-btn dws-btn--teal" type="button" data-dws-action="open-rx"' + (isCompleted ? " disabled" : "") + ">Issue Prescription</button></div>";
      return;
    }

    const actions = (r, small) => {
      const id = safeId(r.id);
      let html = '<button class="dws-btn dws-btn--ghost dws-btn--sm" type="button" data-dws-action="view-rx" data-rx-id="' + id + '">View</button>' +
        '<button class="dws-btn dws-btn--ghost dws-btn--sm" type="button" data-dws-action="print-rx" data-rx-id="' + id + '">Print</button>';
      if (!isCompleted && id && rxIsEditable(r.status)) {
        html += '<button class="dws-btn dws-btn--ghost dws-btn--sm" type="button" data-dws-action="edit-rx" data-rx-id="' + id + '">Edit</button>';
      }
      return html;
    };

    const latest = rx[0];
    const latestPill = pillForRxStatus(latest.status);
    let html = '<div class="dws-rx-summary">' +
      '<div class="dws-rx-summary__info">' +
        '<p class="doctor-page__eyebrow">Latest prescription</p>' +
        '<h3>Prescription Issued</h3>' +
        '<p class="dws-rx-summary__meta">' + esc(safeCount(latest.item_count) + " medication(s) · " + fmtDate(latest.created_at)) + "</p>" +
      "</div>" +
      '<span class="doctor-pill' + latestPill.cls + '">' + esc(latestPill.text) + "</span>" +
      '<div class="dws-rx-summary__actions">' + actions(latest) + "</div>" +
      "</div>";
    html += '<div class="dws-rx-issue-row"><button class="dws-btn dws-btn--teal" type="button" data-dws-action="open-rx"' +
      (isCompleted ? " disabled" : "") + ">Issue Prescription</button></div>";
    html += '<div class="dws-rx-grid">' + rx.map((r) => {
      const pill = pillForRxStatus(r.status);
      const label = safeId(r.id) ? "RX-" + String(safeId(r.id)).padStart(5, "0") : "RX—";
      return '<div class="dws-rx-card">' +
        '<div class="dws-rx-card__head"><div><strong>' + esc(r.doctor_name || "—") + "</strong>" +
        '<span class="dws-rx-card__id">' + esc(label + " · " + (r.department || "")) + "</span></div>" +
        '<span class="doctor-pill' + pill.cls + '">' + esc(pill.text) + "</span></div>" +
        '<p class="dws-rx-card__meta">' + esc(fmtDate(r.created_at) + " · " + safeCount(r.item_count) + " med(s)") + "</p>" +
        '<div class="dws-rx-items-list">' + rxItemsHtml(r) + "</div>" +
        '<div class="dws-rx-card__actions">' + actions(r) + "</div></div>";
    }).join("") + "</div>";
    host.innerHTML = html;
  }

  /* ---------- Prescription issue / view / edit / lifecycle ---------- */

  function rxRowHtml(values) {
    const v = values || {};
    return '<div class="dws-rx-row">' +
      '<div class="dws-field"><input type="text" class="rx-med" placeholder="Medication name" value="' + esc(v.medication_name || "") + '" /></div>' +
      '<div class="dws-field"><input type="text" class="rx-str" placeholder="Strength (e.g. 500mg)" value="' + esc(v.strength || "") + '" /></div>' +
      '<div class="dws-field"><input type="text" class="rx-dos" placeholder="Dosage (e.g. 1 tab)" value="' + esc(v.dosage || "") + '" /></div>' +
      '<div class="dws-field"><input type="text" class="rx-freq" placeholder="Frequency (e.g. BID)" value="' + esc(v.frequency || "") + '" /></div>' +
      '<div class="dws-field"><input type="text" class="rx-dur" placeholder="Duration (e.g. 7d)" value="' + esc(v.duration || "") + '" /></div>' +
      '<div class="dws-field dws-field--instructions"><input type="text" class="rx-ins" placeholder="Instructions (optional)" value="' + esc(v.instructions || "") + '" /></div>' +
      '<button type="button" class="dws-icon-btn dws-rx-row__remove" data-dws-action="remove-rx-row" aria-label="Remove medication row">&times;</button>' +
      "</div>";
  }

  function collectRxItems(container) {
    const items = [];
    container?.querySelectorAll(".dws-rx-row").forEach((row) => {
      const name = row.querySelector(".rx-med")?.value.trim();
      if (!name) return;
      items.push({
        medication_name: name,
        strength: row.querySelector(".rx-str")?.value.trim() || "",
        dosage: row.querySelector(".rx-dos")?.value.trim() || "",
        frequency: row.querySelector(".rx-freq")?.value.trim() || "",
        duration: row.querySelector(".rx-dur")?.value.trim() || "",
        instructions: row.querySelector(".rx-ins")?.value.trim() || "",
      });
    });
    return items;
  }

  function openRxModal() {
    const cv = ws.current_visit;
    if (!cv) { toast("No active visit to issue a prescription for.", "error"); return; }
    $("dws-rx-pt-name").value = ws.patient?.name || "";
    $("dws-rx-appt-date").value = cv.date + " " + fmtRange(cv);
    $("dws-rx-notes").value = "";
    $("dws-rx-items").innerHTML = rxRowHtml();
    openModal("dws-rx-modal");
  }

  async function submitRxIssue(e) {
    e.preventDefault();
    const cv = ws.current_visit;
    if (!cv) return;
    const items = collectRxItems($("dws-rx-items"));
    if (!items.length) { toast("Add at least one medication.", "error"); return; }
    const btn = $("dws-rx-submit");
    const orig = btn.textContent;
    btn.disabled = true;
    btn.textContent = "Issuing...";
    try {
      await apiPost("api/prescriptions/create.php", {
        appointment_id: safeId(cv.id), patient_id: patientId,
        items, notes: $("dws-rx-notes")?.value.trim() || "",
      });
      toast("Prescription issued!", "success");
      closeModal("dws-rx-modal");
      // Legacy behaviour: an issued prescription moves the visit to
      // Ready to Complete (unless already there or completed).
      const key = normStatus(workflowOf(ws.current_visit));
      if (key !== "completed" && key !== "ready_to_complete") {
        try {
          const payload = await apiPost("api/medical/transition-workflow.php", {
            appointment_id: cv.id, status: "Ready to Complete",
          });
          if (payload.workflow) ws.current_visit.visit_workflow = payload.workflow;
        } catch { /* workflow stays as the server has it */ }
      }
      await loadData();
      switchTab("prescriptions");
    } catch (err) {
      toast(err.message || "Failed to create prescription.", "error");
    } finally {
      btn.disabled = false;
      btn.textContent = orig;
    }
  }

  let rxDetail = null;

  async function openRxView(rxId) {
    const id = safeId(rxId);
    if (!id) { toast("Invalid prescription ID.", "error"); return; }
    let payload;
    try {
      payload = await api("api/prescriptions/get.php?id=" + id);
    } catch (err) {
      toast(err.message || "Failed to load prescription.", "error");
      return;
    }
    const rx = payload.prescription;
    if (!rx || !safeId(rx.id)) { toast("Prescription response was invalid or not found.", "error"); return; }
    rxDetail = rx;
    const pill = pillForRxStatus(rx.status);
    const items = Array.isArray(rx.items) ? rx.items.filter((i) => i && typeof i === "object") : [];
    const isCompletedVisit = normStatus(workflowOf(ws.current_visit)) === "completed";
    const editable = rxIsEditable(rx.status) && !isCompletedVisit;

    let html = "";
    if (rx.status === "Cancelled" && rx.cancellation_reason) {
      html += '<div class="dws-alert dws-alert--danger"><h4>Cancellation Reason</h4><p>' + esc(rx.cancellation_reason) + "</p></div>";
    }
    html += '<div class="dws-rx-doc">' +
      '<div class="dws-rx-doc__head"><div><p class="doctor-page__eyebrow">HealthBridge Hospital</p>' +
      "<h4>" + esc("RX-" + new Date().getFullYear() + "-" + String(safeId(rx.id)).padStart(6, "0")) + "</h4>" +
      '<p class="dws-rx-doc__issued">Issued: ' + esc(fmtDate(rx.created_at)) +
      (rx.updated_at && rx.updated_at !== rx.created_at ? " · Updated: " + esc(fmtDate(rx.updated_at)) : "") + "</p></div>" +
      '<span class="doctor-pill' + pill.cls + '">' + esc(pill.text) + "</span></div>" +
      '<div class="dws-info-grid">' +
      infoItem("Patient", esc(rx.patient_name || "N/A")) +
      infoItem("Patient Email", esc(rx.patient_email || "—")) +
      infoItem("Doctor", esc(rx.doctor_name || rx.appt_doctor_name || "N/A")) +
      infoItem("Department", esc(rx.appt_department || "Medical Department")) +
      infoItem("Appointment Date", esc(fmtDate(rx.appt_date))) +
      infoItem("Appointment Time", esc(rx.appointment_time_range || fmtTime(rx.appt_time || ""))) +
      "</div>" +
      '<h5 class="dws-rx-doc__meds-title">Prescribed Medications (' + items.length + (items.length === 1 ? " item" : " items") + ")</h5>";
    if (!items.length) {
      html += '<p class="doctor-empty">This prescription has no medication items.</p>';
    } else {
      html += items.map((item, i) =>
        '<div class="dws-rx-med"><div class="dws-rx-med__head"><strong>' + esc(item.medication_name) + "</strong><span>#" + (i + 1) + "</span></div>" +
        '<div class="dws-info-grid">' +
        infoItem("Strength", esc(item.strength || "—")) +
        infoItem("Dosage", esc(item.dosage || "—")) +
        infoItem("Frequency", esc(item.frequency || "—")) +
        infoItem("Duration", esc(item.duration || "—")) +
        (item.instructions ? infoItem("Instructions", esc(item.instructions), true) : "") +
        "</div></div>"
      ).join("");
    }
    if (rx.notes) {
      html += '<div class="dws-rx-notes-block"><h5>Prescription Notes</h5><p>' + esc(rx.notes) + "</p></div>";
    }
    html += '<div class="dws-rx-doc__sign"><span>Prescribing Doctor</span><strong>' +
      esc(rx.doctor_name || rx.appt_doctor_name || "N/A") + "</strong><span>" +
      esc(rx.appt_department || "Medical Department") + " · Electronic Signature</span></div>";
    html += '<div class="dws-modal__foot dws-rx-doc__actions">';
    if (editable) {
      html += '<button class="dws-btn dws-btn--teal" type="button" data-dws-action="edit-rx" data-rx-id="' + safeId(rx.id) + '">Edit</button>' +
        '<button class="dws-btn dws-btn--gold" type="button" data-dws-action="complete-rx" data-rx-id="' + safeId(rx.id) + '">Complete</button>' +
        '<button class="dws-btn dws-btn--danger" type="button" data-dws-action="cancel-rx" data-rx-id="' + safeId(rx.id) + '">Cancel</button>';
    }
    html += '<button class="dws-btn dws-btn--ghost" type="button" data-dws-action="print-rx" data-rx-id="' + safeId(rx.id) + '">Print</button></div>';
    html += "</div>";
    $("dws-rx-view-content").innerHTML = html;
    openModal("dws-rx-view-modal");
  }

  async function openRxEdit(rxId) {
    const id = safeId(rxId);
    if (!id) { toast("A valid prescription ID is required.", "error"); return; }
    const local = (Array.isArray(ws.prescriptions) ? ws.prescriptions : []).find((r) => safeId(r?.id) === id);
    if (!local) { toast("Prescription was not found in this patient workspace. Refresh and try again.", "error"); return; }
    if (!rxIsEditable(local.status)) { toast("Only Active prescriptions can be edited.", "error"); return; }
    if (normStatus(workflowOf(ws.current_visit)) === "completed") {
      toast("Prescriptions cannot be edited after the visit is completed.", "error");
      return;
    }
    let payload;
    try {
      payload = await api("api/prescriptions/get.php?id=" + id);
    } catch (err) {
      toast(err.message || "Failed to load prescription.", "error");
      return;
    }
    const rx = payload.prescription;
    if (!rx || safeId(rx.id) !== id) { toast("Prescription response was invalid.", "error"); return; }
    closeModal("dws-rx-view-modal");
    $("dws-rx-edit-id").value = String(id);
    $("dws-rx-edit-notes").value = rx.notes || "";
    const items = Array.isArray(rx.items) ? rx.items.filter((i) => i && typeof i === "object") : [];
    $("dws-rx-edit-items").innerHTML = items.map((item) => rxRowHtml(item)).join("") || rxRowHtml();
    openModal("dws-rx-edit-modal");
  }

  async function submitRxEdit(e) {
    e.preventDefault();
    const id = safeId($("dws-rx-edit-id")?.value);
    if (!id) { toast("Prescription ID is missing.", "error"); return; }
    const items = collectRxItems($("dws-rx-edit-items"));
    if (!items.length) { toast("Please add at least one medication.", "error"); return; }
    const btn = $("dws-rx-edit-submit");
    const orig = btn.textContent;
    btn.disabled = true;
    btn.textContent = "Saving...";
    try {
      await apiPost("api/prescriptions/update.php", {
        prescription_id: id,
        notes: $("dws-rx-edit-notes")?.value.trim() || "",
        items,
      });
      toast("Prescription updated successfully.", "success");
      closeModal("dws-rx-edit-modal");
      await loadData();
    } catch (err) {
      toast(err.message || "Failed to update prescription.", "error");
    } finally {
      btn.disabled = false;
      btn.textContent = orig;
    }
  }

  let confirmRxId = 0;
  function askCompleteRx(rxId) {
    confirmRxId = safeId(rxId);
    if (!confirmRxId) return;
    openModal("dws-confirm-modal");
  }

  async function completeRx() {
    const id = confirmRxId;
    if (!id) return;
    const btn = $("dws-confirm-yes");
    btn.disabled = true;
    try {
      await apiPost("api/prescriptions/complete.php", { prescription_id: id });
      toast("Prescription marked as completed.", "success");
      closeModal("dws-confirm-modal");
      closeModal("dws-rx-view-modal");
      await loadData();
    } catch (err) {
      toast(err.message || "Failed to complete prescription.", "error");
    } finally {
      btn.disabled = false;
      confirmRxId = 0;
    }
  }

  function openRxCancel(rxId) {
    const id = safeId(rxId);
    if (!id) return;
    $("dws-rx-cancel-id").value = String(id);
    $("dws-rx-cancel-reason").value = "";
    openModal("dws-rx-cancel-modal");
  }

  async function submitRxCancel(e) {
    e.preventDefault();
    const id = safeId($("dws-rx-cancel-id")?.value);
    const reason = $("dws-rx-cancel-reason")?.value.trim() || "";
    if (!id) { toast("Prescription ID is missing.", "error"); return; }
    if (!reason) { toast("Cancellation reason is required.", "error"); return; }
    const btn = $("dws-rx-cancel-submit");
    const orig = btn.textContent;
    btn.disabled = true;
    btn.textContent = "Cancelling...";
    try {
      await apiPost("api/prescriptions/cancel.php", { prescription_id: id, reason });
      toast("Prescription cancelled.", "success");
      closeModal("dws-rx-cancel-modal");
      closeModal("dws-rx-view-modal");
      await loadData();
    } catch (err) {
      toast(err.message || "Failed to cancel prescription.", "error");
    } finally {
      btn.disabled = false;
      btn.textContent = orig;
    }
  }

  async function printRx(rxId) {
    const id = safeId(rxId);
    if (!id) { toast("No prescription to print.", "error"); return; }
    try {
      const payload = await api("api/prescriptions/get.php?id=" + id);
      if (!payload.prescription) throw new Error("invalid");
      window.open(ROOT + "api/prescriptions/print.php?id=" + id, "_blank", "noopener,noreferrer");
    } catch {
      toast("Failed to load prescription for printing.", "error");
    }
  }

  /* ---------- Timeline tab ---------- */

  function renderTimeline() {
    const host = $("dws-timeline");
    if (!host) return;
    const list = (v) => (Array.isArray(v) ? v.filter((r) => r && typeof r === "object") : []);
    const entries = [];

    list(ws.appointments).forEach((a) => {
      const s = String(a.status || "").toLowerCase();
      const kind = s === "completed" ? "completed" : s === "cancelled" ? "cancelled" : "appointment";
      entries.push({
        date: (a.date || "") + " " + (a.time || "00:00"),
        kind,
        title: "Appointment " + (s === "completed" ? "Completed" : s === "cancelled" ? "Cancelled" : "Confirmed"),
        text: (a.department || "") + " · " + (a.doctor_user_name || a.doctor || ""),
        detail: (a.date || "") + " " + fmtRange(a),
        doctor: a.doctor_user_name || a.doctor || "—",
        quickView: "Status: " + (a.status || "") + " · Department: " + (a.department || ""),
      });
    });
    list(ws.visit_notes).forEach((v) => {
      entries.push({
        date: (v.appt_date || "") + " " + (v.appt_time || "00:00"),
        kind: "visit-note",
        title: v.diagnosis ? "Diagnosis Saved" : "Visit Documented",
        text: v.diagnosis ? "Diagnosis: " + v.diagnosis : "Visit documented",
        detail: v.department || "",
        doctor: v.doctor_name || "—",
        quickView: v.diagnosis
          ? "Diagnosis: " + v.diagnosis + (v.symptoms ? " · Symptoms: " + String(v.symptoms).substring(0, 50) : "")
          : "No diagnosis recorded",
      });
    });
    list(ws.prescriptions).forEach((r) => {
      entries.push({
        date: r.created_at || "",
        kind: "prescription",
        title: "Prescription Issued",
        text: safeCount(r.item_count) + " medication(s) · " + (r.status || ""),
        detail: safeId(r.id) ? "RX-" + String(safeId(r.id)).padStart(5, "0") : "RX—",
        doctor: r.doctor_name || "—",
        quickView: "Status: " + (r.status || "") + " · Items: " + safeCount(r.item_count) +
          (r.notes ? " · Notes: " + String(r.notes).substring(0, 80) : ""),
      });
    });
    const fieldLabels = {
      allergies: "Allergies", chronic_diseases: "Chronic Conditions",
      current_medications: "Current Medications", previous_surgeries: "Previous Surgeries",
      family_history: "Family History", medical_notes: "Medical Notes", blood_type: "Blood Type",
    };
    list(ws.medical_history_updates).forEach((mhu) => {
      const fieldName = typeof mhu.field_name === "string" ? mhu.field_name : "Medical history";
      const fieldLabel = fieldLabels[fieldName] || fieldName;
      entries.push({
        date: mhu.created_at || "",
        kind: "medical-history",
        title: "Medical History Updated",
        text: fieldLabel + " updated",
        detail: fmtDate(mhu.created_at),
        doctor: mhu.doctor_name || "—",
        quickView: "Updated: " + fieldLabel + (mhu.new_value ? " · New: " + String(mhu.new_value).substring(0, 50) : ""),
      });
    });

    entries.sort((a, b) => String(b.date).localeCompare(String(a.date)));

    if (!entries.length) {
      host.innerHTML = '<div class="dws-card dws-empty-state"><h3>No Timeline Entries</h3>' +
        "<p>No activity recorded for this patient yet.</p></div>";
      return;
    }
    host.innerHTML = '<div class="dws-timeline">' + entries.map((e, idx) =>
      '<div class="dws-tl-item">' +
        '<span class="dws-tl-dot is-' + e.kind + '" aria-hidden="true"></span>' +
        '<div class="dws-tl-main">' +
          '<p class="dws-tl-date">' + esc(e.detail) + " · " + esc(e.doctor) + "</p>" +
          '<h4 class="dws-tl-title">' + esc(e.title) + "</h4>" +
          '<p class="dws-tl-text">' + esc(e.text) + "</p>" +
          '<div class="dws-tl-qv" id="dws-tl-qv-' + idx + '" hidden>' + esc(e.quickView) + "</div>" +
          '<button class="dws-tl-toggle" type="button" data-dws-action="toggle-timeline" data-timeline-index="' + idx + '" aria-expanded="false" aria-controls="dws-tl-qv-' + idx + '">Quick View</button>' +
        "</div></div>"
    ).join("") + "</div>";
  }

  function toggleTimeline(idx) {
    const qv = $("dws-tl-qv-" + idx);
    if (!qv) return;
    const open = qv.hidden;
    qv.hidden = !open;
    const btn = qv.parentElement.querySelector(".dws-tl-toggle");
    if (btn) {
      btn.setAttribute("aria-expanded", String(open));
      btn.textContent = open ? "Hide Details" : "Quick View";
    }
  }

  /* ---------- Delegated actions ---------- */

  function bindActions() {
    document.addEventListener("click", (event) => {
      const target = event.target instanceof Element ? event.target : null;
      if (!target) return;
      const tabBtn = target.closest("[data-dws-tab]");
      if (tabBtn) {
        event.preventDefault();
        switchTab(tabBtn.getAttribute("data-dws-tab"));
        return;
      }
      const control = target.closest("[data-dws-action]");
      if (!control) return;
      const action = control.getAttribute("data-dws-action");
      const rxId = control.getAttribute("data-rx-id");
      switch (action) {
        case "retry": window.location.reload(); break;
        case "start-visit": startVisit(); break;
        case "open-rx": openRxModal(); break;
        case "refresh": toast("Refreshing...", "info"); loadData(); break;
        case "open-review": openReviewModal(); break;
        case "edit-field": openFieldEditor(control.getAttribute("data-field")); break;
        case "view-rx": if (rxId) openRxView(rxId); break;
        case "print-rx": if (rxId) printRx(rxId); break;
        case "edit-rx": if (rxId) openRxEdit(rxId); break;
        case "complete-rx": if (rxId) askCompleteRx(rxId); break;
        case "cancel-rx": if (rxId) openRxCancel(rxId); break;
        case "toggle-timeline": toggleTimeline(Number(control.getAttribute("data-timeline-index"))); break;
        case "remove-rx-row": {
          const row = control.closest(".dws-rx-row");
          const container = row?.parentElement;
          if (row && container && container.querySelectorAll(".dws-rx-row").length > 1) row.remove();
          else if (row) toast("A prescription needs at least one medication row.", "info");
          break;
        }
        default: break;
      }
    });
  }

  /* ---------- Init ---------- */

  function start() {
    const params = new URLSearchParams(window.location.search);
    patientId = safeId(params.get("patient_id"));
    appointmentId = safeId(params.get("appointment_id"));
    const user = typeof getUser === "function" ? getUser() : null;
    doctorName = user?.name || "";

    bindActions();
    initTabs();
    initModals();

    $("dws-rx-form")?.addEventListener("submit", submitRxIssue);
    $("dws-rx-add-row")?.addEventListener("click", () => {
      $("dws-rx-items")?.insertAdjacentHTML("beforeend", rxRowHtml());
    });
    $("dws-rx-edit-form")?.addEventListener("submit", submitRxEdit);
    $("dws-rx-edit-add")?.addEventListener("click", () => {
      $("dws-rx-edit-items")?.insertAdjacentHTML("beforeend", rxRowHtml());
    });
    $("dws-rx-cancel-form")?.addEventListener("submit", submitRxCancel);
    $("dws-confirm-yes")?.addEventListener("click", completeRx);
    $("dws-confirm-complete")?.addEventListener("click", completeVisit);
    $("dws-mh-form")?.addEventListener("submit", (e) => {
      e.preventDefault();
      const field = $("dws-mh-field").value;
      if (MH_FIELDS[field]?.kind === "blood") {
        const blood = $("dws-mh-blood").value;
        if (!blood) { toast("Please select a blood type.", "error"); return; }
        saveClinicalField(field, blood);
      } else {
        saveClinicalField(field, $("dws-mh-input").value.trim());
      }
    });

    document.addEventListener("keydown", (e) => {
      if ((e.ctrlKey || e.metaKey) && e.key === "s") {
        const form = $("dws-vf");
        if (form && activeTab === "visit") {
          e.preventDefault();
          form.dispatchEvent(new Event("submit", { cancelable: true }));
        }
      }
    });
    window.addEventListener("beforeunload", (e) => {
      if (hasUnsavedChanges && !isCompletingVisit) {
        e.preventDefault();
        e.returnValue = "You have unsaved changes. Are you sure you want to leave?";
      }
    });

    const root = document.querySelector(".doctor-ws");
    if (root) {
      root.classList.add("is-entering");
      window.setTimeout(() => root.classList.remove("is-entering"), 1300);
    }

    if (!patientId) {
      const main = $("dws-root");
      if (main) {
        main.innerHTML = '<div class="dws-card dws-empty-state">' +
          "<h3>No Patient Selected</h3>" +
          "<p>Please select a patient from your appointments.</p>" +
          '<a class="dws-btn dws-btn--teal" href="appointments.html">Go to Appointments</a></div>';
      }
      return;
    }

    loadData().then(() => {
      const hash = window.location.hash.substring(1);
      if (hash && TABS.includes(hash)) switchTab(hash);
    });
  }

  document.addEventListener("doctor:shell-ready", start);
})();
