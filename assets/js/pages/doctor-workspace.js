/**
 * HealthBridge — Doctor Clinical Workspace (Phase 5.3)
 * Professional consultation workflow with visit states, autosave, validation,
 * progress bar, summary card, review dialog, and post-visit actions.
 *
 * Reuses: apiFetch(), escapeHTML(), formatDate(), formatApptTime(),
 *         showToast(), getUser(), setLoading(), requireServerRole() from main.js
 *
 * No duplicate backend logic — all existing PHP endpoints are reused.
 */

"use strict";

let ws = null;          // workspace data
let patientId = 0;
let appointmentId = 0;
let activeTab = 'overview';
let wsUser = null;      // current doctor user

// ── Autosave state (Phase 5.4 - Database-based drafts) ──
let autosaveTimer = null;
let autosaveDebounceTimer = null;
let autosaveDirty = false;
let lastSavedSnapshot = '';
const AUTOSAVE_INTERVAL = 12000;   // 12 seconds
const AUTOSAVE_DEBOUNCE = 2000;    // 2 seconds after typing stops

// ── Unsaved changes (Part 4) ──
let hasUnsavedChanges = false;
let isCompletingVisit = false;

const TABS = ['overview', 'medical-history', 'visit', 'prescriptions', 'timeline', 'lab-results', 'files'];

/* ═══════════════════════════════════════════════════════════
   INIT
   ═══════════════════════════════════════════════════════════ */

document.addEventListener('DOMContentLoaded', async () => {
  const p = new URLSearchParams(window.location.search);
  patientId = parseInt(p.get('patient_id') || '0');
  appointmentId = parseInt(p.get('appointment_id') || '0');

  wsUser = await requireServerRole('doctor');
  if (!wsUser) return;

  setText('ws-sb-name', wsUser.name || 'Doctor');
  setText('ws-sb-email', wsUser.email || '');

  if (!patientId) {
    document.getElementById('ws-main').innerHTML =
      '<div class="ws-empty" style="padding:var(--s10)"><i class="fas fa-user-slash"></i><h4>No Patient Selected</h4><p>Please select a patient from your appointments.</p><a href="' + getBasePath() + 'pages/doctor/doctor-dashboard.html#appointments" class="btn btn-primary">Go to Appointments</a></div>';
    return;
  }

  initTabs();
  initModals();
  initRxForm();
  initReviewModal();
  initKeyboardShortcuts();   // Part 11
  initBeforeUnload();        // Part 4
  initClinicalHistoryForms(); // Phase 5.3.2

  const logoutBtn = document.getElementById('doctor-ws-logout-btn');
  if (logoutBtn) {
    logoutBtn.addEventListener('click', (e) => {
      e.preventDefault();
      if (typeof window.logoutUser === 'function') window.logoutUser();
    });
  }

  await loadData();

  const hash = window.location.hash.substring(1);
  if (hash && TABS.includes(hash)) switchTab(hash);
});

function setText(id, t) { const e = document.getElementById(id); if (e) e.textContent = t; }

/* ═══════════════════════════════════════════════════════════
   TAB SYSTEM
   ═══════════════════════════════════════════════════════════ */

function initTabs() {
  document.querySelectorAll('.ws-tab').forEach(tab => {
    tab.addEventListener('click', () => {
      const id = tab.getAttribute('data-tab');
      if (id) switchTab(id);
    });
    tab.addEventListener('keydown', e => {
      const arr = Array.from(document.querySelectorAll('.ws-tab'));
      const idx = arr.indexOf(tab);
      let next = -1;
      if (e.key === 'ArrowRight' || e.key === 'ArrowDown') { e.preventDefault(); next = (idx + 1) % arr.length; }
      else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') { e.preventDefault(); next = (idx - 1 + arr.length) % arr.length; }
      else if (e.key === 'Home') { e.preventDefault(); next = 0; }
      else if (e.key === 'End') { e.preventDefault(); next = arr.length - 1; }
      if (next >= 0) { switchTab(arr[next].getAttribute('data-tab')); arr[next].focus(); }
    });
  });
}

function switchTab(id) {
  if (!TABS.includes(id)) return;
  // Part 4: No warning when navigating inside workspace tabs
  activeTab = id;
  document.querySelectorAll('.ws-tab').forEach(t => {
    const a = t.getAttribute('data-tab') === id;
    t.classList.toggle('active', a);
    t.setAttribute('aria-selected', a);
    t.setAttribute('tabindex', a ? '0' : '-1');
  });
  document.querySelectorAll('.ws-tab-content').forEach(c => c.classList.toggle('active', c.id === 'ws-' + id));
  if (window.location.hash !== '#' + id) history.replaceState(null, '', '#' + id);
  const main = document.querySelector('.main-content');
  if (main) main.scrollTop = 0;
}

/* ═══════════════════════════════════════════════════════════
   DATA LOADING
   ═══════════════════════════════════════════════════════════ */

async function loadData() {
  showSkeletons();
  let url = `${getBasePath()}api/doctors/workspace-data.php?patient_id=${patientId}`;
  if (appointmentId) url += `&appointment_id=${appointmentId}`;

  const r = await apiFetch(url, {}, 'Failed to load patient data.');
  if (!r.ok || !r.data?.success) {
    hideSkeletons();
    document.getElementById('ws-main').innerHTML =
      `<div class="ws-empty" style="padding:var(--s10)"><i class="fas fa-exclamation-triangle"></i><h4>Failed to Load Data</h4><p>${escapeHTML(r.data?.message || 'Could not load patient data.')}</p><button class="btn btn-primary" onclick="location.reload()">Try Again</button></div>`;
    return;
  }

  ws = r.data;
  hideSkeletons();
  renderAll();

  // Part 3: Restore draft after data loads
  restoreDraft();
}

function refreshWS() {
  showToast('Refreshing...', 'info', 1000);
  loadData();
}

/* ═══════════════════════════════════════════════════════════
   SKELETONS
   ═══════════════════════════════════════════════════════════ */

function showSkeletons() {
  document.getElementById('ws-summary-inner').innerHTML =
    '<div class="ws-summary-skeleton"><i class="fas fa-spinner fa-spin"></i> Loading patient summary...</div>';
  document.getElementById('ws-summary-actions').innerHTML = '';
}

function hideSkeletons() {}

/* ═══════════════════════════════════════════════════════════
   RENDER ALL
   ═══════════════════════════════════════════════════════════ */

function renderAll() {
  if (!ws) return;
  renderSummaryCard();       // Part 7 (single summary with actions)
  updateProgressBar();       // Part 2
  renderOverview();
  renderMedicalHistory();
  renderVisit();
  renderPrescriptions();     // Part 6
  renderTimeline();          // Part 10
  // renderSticky() removed - duplicate summary card (Phase 5.3.2 cleanup)
}

/* ═══════════════════════════════════════════════════════════
   PART 7 — CLINICAL SUMMARY CARD
   ═══════════════════════════════════════════════════════════ */

function renderSummaryCard() {
  const p = ws.patient;
  const mr = ws.medical_record;
  const cv = ws.current_visit;
  if (!p) return;

  const age = ws.age !== null ? ws.age + 'y' : '—';
  const gender = mr?.gender || '—';
  const blood = mr?.blood_type || '—';
  const allergies = ws.allergies || 'None';
  const chronic = ws.chronic_diseases || 'None';
  const apptTime = cv ? `${escapeHTML(cv.date)} ${escapeHTML(formatApptTime(cv))}` : '—';
  const doctorName = wsUser?.name || '—';
  
  // Phase 5.4: Use workflow status only
  const wf = cv?.visit_workflow;
  const statusLabel = getVisitStatusLabel(cv);
  const statusClass = wf ? (wf.status || '').toLowerCase().replace(/\s+/g, '_') : 'pending';

  document.getElementById('ws-summary-inner').innerHTML = `
    <div class="ws-summary-item" title="Patient Name">
      <i class="fas fa-user"></i>
      <span class="ws-summary-label">Patient</span>
      <span class="ws-summary-value">${escapeHTML(p.name)}</span>
    </div>
    <div class="ws-summary-divider"></div>
    <div class="ws-summary-item" title="Age">
      <i class="fas fa-cake-candles"></i>
      <span class="ws-summary-label">Age</span>
      <span class="ws-summary-value">${age}</span>
    </div>
    <div class="ws-summary-divider"></div>
    <div class="ws-summary-item" title="Gender">
      <i class="fas fa-venus-mars"></i>
      <span class="ws-summary-label">Gender</span>
      <span class="ws-summary-value">${escapeHTML(gender)}</span>
    </div>
    <div class="ws-summary-divider"></div>
    <div class="ws-summary-item" title="Blood Type">
      <i class="fas fa-droplet"></i>
      <span class="ws-summary-label">Blood</span>
      <span class="ws-summary-value">${escapeHTML(blood)}</span>
    </div>
    <div class="ws-summary-divider"></div>
    <div class="ws-summary-item" title="Allergies">
      <i class="fas fa-allergies"></i>
      <span class="ws-summary-label">Allergies</span>
      <span class="ws-summary-value">${escapeHTML(allergies.length > 20 ? allergies.substring(0, 20) + '...' : allergies)}</span>
    </div>
    <div class="ws-summary-divider"></div>
    <div class="ws-summary-item" title="Chronic Diseases">
      <i class="fas fa-heart-pulse"></i>
      <span class="ws-summary-label">Chronic</span>
      <span class="ws-summary-value">${escapeHTML(chronic.length > 20 ? chronic.substring(0, 20) + '...' : chronic)}</span>
    </div>
    <div class="ws-summary-divider"></div>
    <div class="ws-summary-item" title="Appointment Time">
      <i class="fas fa-clock"></i>
      <span class="ws-summary-label">Appt</span>
      <span class="ws-summary-value">${apptTime}</span>
    </div>
    <div class="ws-summary-divider"></div>
    <div class="ws-summary-item" title="Doctor">
      <i class="fas fa-user-md"></i>
      <span class="ws-summary-label">Doctor</span>
      <span class="ws-summary-value">${escapeHTML(doctorName)}</span>
    </div>
    <div class="ws-summary-status">
      <span class="status status-${statusClass}">${escapeHTML(statusLabel)}</span>
    </div>`;

  // Render actions into the summary card
  const isWaiting = statusClass === 'waiting';
  const isInProgress = statusClass === 'in_progress';
  const isReady = statusClass === 'ready_to_complete';
  const isCompleted = statusClass === 'completed';

  let actionsHtml = '';
  if (isWaiting) {
    actionsHtml = `<button class="btn btn-primary btn-sm" onclick="startVisit()" title="Start the visit"><i class="fas fa-play"></i> Start Visit</button>`;
  } else if (isInProgress || isReady) {
    actionsHtml = `<button class="btn btn-primary btn-sm" onclick="switchTab('visit')" title="Continue visit"><i class="fas fa-stethoscope"></i> Visit</button>`;
  } else if (isCompleted) {
    actionsHtml = `<button class="btn btn-outline btn-sm" disabled title="Visit completed"><i class="fas fa-check-circle"></i> Completed</button>`;
  }
  actionsHtml += `<button class="btn btn-outline btn-sm" onclick="openRxModal()" title="Issue prescription"><i class="fas fa-prescription"></i> Rx</button>`;
  actionsHtml += `<button class="btn btn-outline btn-sm" onclick="refreshWS()" title="Refresh"><i class="fas fa-rotate"></i></button>`;

  document.getElementById('ws-summary-actions').innerHTML = actionsHtml;
}

function getVisitStatusLabel(cv) {
  if (!cv) return 'No Visit';
  // Phase 5.4: Only use visit_workflow status - no legacy fallback
  const wf = cv.visit_workflow;
  if (wf && wf.status) return wf.status;
  // If no workflow exists yet, return based on appointment status
  const s = (cv.status || '').toLowerCase();
  if (s === 'confirmed') return 'Waiting';
  return cv.status;
}

/* ═══════════════════════════════════════════════════════════
   PART 2 — STICKY CLINICAL PROGRESS BAR
   ═══════════════════════════════════════════════════════════ */

function updateProgressBar() {
  const cv = ws.current_visit;
  const cvn = ws.current_visit_note;
  const rx = ws.prescriptions || [];
  
  // Phase 5.4: Use workflow status only
  const wf = cv?.visit_workflow;
  const status = wf ? (wf.status || '').toLowerCase().replace(/\s+/g, '_') : 'waiting';

  // Determine which step is active based on workflow status
  let activeStep = 1; // Waiting (default)

  if (status === 'in_progress') {
    activeStep = 2; // In Progress
  } else if (status === 'ready_to_complete') {
    activeStep = 3; // Ready to Complete
  } else if (status === 'completed') {
    activeStep = 4; // Completed
  }

  // If visit is completed, mark all as completed
  const isCompleted = status === 'completed';

  document.querySelectorAll('.ws-progress-step').forEach(step => {
    const stepNum = parseInt(step.getAttribute('data-step'));
    step.classList.remove('active', 'completed');
    if (isCompleted || stepNum < activeStep) {
      step.classList.add('completed');
    } else if (stepNum === activeStep) {
      step.classList.add('active');
    }
  });

  // Update connectors
  document.querySelectorAll('.ws-progress-connector').forEach((conn, idx) => {
    conn.classList.toggle('completed', isCompleted || (idx + 1) < activeStep);
  });

  // Update ARIA
  const bar = document.getElementById('ws-progress-bar');
  if (bar) {
    bar.setAttribute('aria-valuenow', isCompleted ? 4 : activeStep);
  }
}

/* ═══════════════════════════════════════════════════════════
   PART 1 — VISIT STATUS WORKFLOW
   ═══════════════════════════════════════════════════════════ */

async function startVisit() {
  const cv = ws.current_visit;
  if (!cv) { showToast('No active visit to start.', 'error'); return; }

  const btn = document.querySelector('#ws-summary-actions .btn-primary');
  const orig = btn?.textContent || 'Start';
  setLoading(btn, true, 'Starting...');

  // Update workflow status to 'In Progress' (Phase 5.3.2)
  const r = await apiFetch((getBasePath() + "api/medical/transition-workflow.php"), {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ appointment_id: cv.id, status: 'In Progress' }),
  }, 'Could not start visit.');

  setLoading(btn, false, orig);
  if (r.data?.success) {
    showToast('Visit started!', 'success');
    
    // Update local workflow data immediately
    if (r.data.workflow && ws.current_visit) {
      ws.current_visit.visit_workflow = r.data.workflow;
    }
    
    // Re-render UI components
    renderSummaryCard();
    updateProgressBar();
    renderVisit();
    switchTab('visit');
    
    // Focus the Diagnosis field when the visit starts
    setTimeout(() => {
      const dx = document.getElementById('ws-vf-dx');
      if (dx) dx.focus();
    }, 300);
  } else {
    showToast(r.data?.message || 'Could not start visit.', 'error');
  }
}

/* ═══════════════════════════════════════════════════════════
   OVERVIEW TAB
   ═══════════════════════════════════════════════════════════ */

function renderOverview() {
  const p = ws.patient;
  const mr = ws.medical_record;
  const allergies = ws.allergies;
  const chronic = ws.chronic_diseases;
  const meds = ws.current_medications;
  const cv = ws.current_visit;
  const cvn = ws.current_visit_note;
  const latestAppt = ws.appointments?.[0] || null;
  const latestRx = ws.prescriptions?.[0] || null;
  const latestNote = ws.visit_notes?.[0] || null;

  // Clinical alerts
  let alerts = '';
  if (allergies) alerts += `<div class="ws-alert ws-alert-danger"><i class="fas fa-allergies"></i><div class="ws-alert-content"><h4>Allergies</h4><p>${escapeHTML(allergies)}</p></div></div>`;
  if (chronic) alerts += `<div class="ws-alert ws-alert-warning"><i class="fas fa-heart-pulse"></i><div class="ws-alert-content"><h4>Chronic Conditions</h4><p>${escapeHTML(chronic)}</p></div></div>`;
  if (meds) alerts += `<div class="ws-alert ws-alert-info"><i class="fas fa-pills"></i><div class="ws-alert-content"><h4>Current Medications</h4><p>${escapeHTML(meds)}</p></div></div>`;

  // Current visit summary
  let visitHtml = '';
  if (cv) {
    const statusLabel = getVisitStatusLabel(cv);
    const statusClass = (cv.status || 'pending').toLowerCase();
    visitHtml = `<div class="ws-card">
      <div class="ws-card-header"><h3 class="ws-card-title"><i class="fas fa-stethoscope"></i> Current Visit</h3><span class="status status-${statusClass}">${escapeHTML(statusLabel)}</span></div>
      <div class="ws-info-grid">
        <div class="ws-info-item"><div class="ws-info-label">Date</div><div class="ws-info-value">${escapeHTML(cv.date)}</div></div>
        <div class="ws-info-item"><div class="ws-info-label">Time</div><div class="ws-info-value">${escapeHTML(formatApptTime(cv))}</div></div>
        <div class="ws-info-item"><div class="ws-info-label">Department</div><div class="ws-info-value">${escapeHTML(cv.department)}</div></div>
        <div class="ws-info-item"><div class="ws-info-label">Visit Note</div><div class="ws-info-value">${cvn ? '<span style="color:var(--success)">Recorded</span>' : '<span style="color:var(--warning)">Pending</span>'}</div></div>
      </div>
    </div>`;
  }

  document.getElementById('ws-overview').innerHTML = `
    ${alerts}
    ${visitHtml}
    <div class="ws-card">
      <div class="ws-card-header"><h3 class="ws-card-title"><i class="fas fa-user"></i> Patient Information <span style="font-size:0.7rem;color:var(--text-muted);font-weight:400;margin-left:auto">Administrative — Read Only</span></h3></div>
      <div class="ws-info-grid">
        <div class="ws-info-item"><div class="ws-info-label">Full Name</div><div class="ws-info-value">${escapeHTML(p.name)}</div></div>
        <div class="ws-info-item"><div class="ws-info-label">Gender</div><div class="ws-info-value">${escapeHTML(mr?.gender || 'Not specified')}</div></div>
        <div class="ws-info-item"><div class="ws-info-label">Age</div><div class="ws-info-value">${ws.age !== null ? ws.age : '—'}</div></div>
        <div class="ws-info-item"><div class="ws-info-label">Blood Type</div><div class="ws-info-value">${escapeHTML(mr?.blood_type || 'Not recorded')}</div></div>
        <div class="ws-info-item"><div class="ws-info-label">Email</div><div class="ws-info-value">${escapeHTML(p.email || '—')}</div></div>
        <div class="ws-info-item"><div class="ws-info-label">Phone</div><div class="ws-info-value">${escapeHTML(p.phone || '—')}</div></div>
        <div class="ws-info-item"><div class="ws-info-label">Emergency Contact</div><div class="ws-info-value">${escapeHTML(mr?.emergency_contact_name || 'None')}</div></div>
        <div class="ws-info-item"><div class="ws-info-label">Emergency Phone</div><div class="ws-info-value">${escapeHTML(mr?.emergency_contact_phone || 'None')}</div></div>
      </div>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:var(--s4)">
      <div class="ws-card">
        <div class="ws-card-header"><h3 class="ws-card-title"><i class="fas fa-calendar-days"></i> Latest Appointment</h3></div>
        ${latestAppt ? `<div class="ws-info-grid"><div class="ws-info-item"><div class="ws-info-label">Date</div><div class="ws-info-value">${escapeHTML(latestAppt.date)}</div></div><div class="ws-info-item"><div class="ws-info-label">Department</div><div class="ws-info-value">${escapeHTML(latestAppt.department)}</div></div><div class="ws-info-item"><div class="ws-info-label">Status</div><div class="ws-info-value"><span class="status status-${latestAppt.status.toLowerCase()}">${escapeHTML(latestAppt.status)}</span></div></div></div>` : `<div class="ws-empty" style="padding:var(--s4)"><p>No appointments.</p></div>`}
      </div>
      <div class="ws-card">
        <div class="ws-card-header"><h3 class="ws-card-title"><i class="fas fa-prescription"></i> Latest Prescription</h3></div>
        ${latestRx ? `<div class="ws-info-grid"><div class="ws-info-item"><div class="ws-info-label">Date</div><div class="ws-info-value">${formatDate(latestRx.created_at)}</div></div><div class="ws-info-item"><div class="ws-info-label">Items</div><div class="ws-info-value">${latestRx.item_count || 0} meds</div></div><div class="ws-info-item"><div class="ws-info-label">Status</div><div class="ws-info-value"><span class="status status-${(latestRx.status||'Active').toLowerCase()}">${escapeHTML(latestRx.status)}</span></div></div></div>` : `<div class="ws-empty" style="padding:var(--s4)"><p>No prescriptions.</p></div>`}
      </div>
      <div class="ws-card">
        <div class="ws-card-header"><h3 class="ws-card-title"><i class="fas fa-file-lines"></i> Latest Visit Note</h3></div>
        ${latestNote ? `<div class="ws-info-grid"><div class="ws-info-item"><div class="ws-info-label">Date</div><div class="ws-info-value">${formatDate(latestNote.appt_date)}</div></div><div class="ws-info-item"><div class="ws-info-label">Doctor</div><div class="ws-info-value">${escapeHTML(latestNote.doctor_name)}</div></div><div class="ws-info-item" style="grid-column:1/-1"><div class="ws-info-label">Diagnosis</div><div class="ws-info-value">${escapeHTML(latestNote.diagnosis || '—')}</div></div></div>` : `<div class="ws-empty" style="padding:var(--s4)"><p>No visit notes.</p></div>`}
      </div>
    </div>
    <div style="display:flex;gap:var(--s2);margin-top:var(--s4);flex-wrap:wrap">
      <button class="btn btn-primary" onclick="switchTab('visit')"><i class="fas fa-stethoscope"></i> Start Visit</button>
      <button class="btn btn-outline" onclick="switchTab('medical-history')"><i class="fas fa-notes-medical"></i> Medical History Management</button>
      <button class="btn btn-outline" onclick="switchTab('prescriptions')"><i class="fas fa-prescription"></i> Prescriptions</button>
      <button class="btn btn-outline" onclick="switchTab('timeline')"><i class="fas fa-clock-rotate"></i> Timeline</button>
    </div>`;
}

/* ═══════════════════════════════════════════════════════════
   MEDICAL HISTORY TAB
   ═══════════════════════════════════════════════════════════ */

function renderMedicalHistory() {
  const mr = ws.medical_record;
  const bloodType = mr?.blood_type || '';
  const hasBloodType = bloodType && bloodType.trim() !== '';

  document.getElementById('ws-medical-history').innerHTML = `
    <div class="ws-mh-grid">
      <!-- Blood Type - conditional editing -->
      <div class="ws-mh-card">
        <div class="ws-mh-header">
          <i class="fas fa-droplet"></i>
          Blood Type
          ${hasBloodType ? '<span class="ws-mh-badge" style="background:rgba(16,185,129,0.1);color:#10b981;font-size:0.65rem;padding:2px 6px;border-radius:10px;margin-left:auto">Verified</span>' : ''}
        </div>
        <div class="ws-mh-body">
          ${hasBloodType ? escapeHTML(bloodType) : 'Not recorded'}
        </div>
        <div class="ws-mh-actions">
          ${!hasBloodType ? `<button class="btn btn-outline btn-sm" onclick="openBloodTypeModal()"><i class="fas fa-plus"></i> Set Blood Type</button>` : ''}
        </div>
      </div>
      
      <!-- Allergies -->
      <div class="ws-mh-card">
        <div class="ws-mh-header"><i class="fas fa-allergies"></i> Allergies</div>
        <div class="ws-mh-body">${ws.allergies ? escapeHTML(ws.allergies) : 'None recorded'}</div>
        <div class="ws-mh-actions">
          <button class="btn btn-outline btn-sm" onclick="openAllergiesModal()"><i class="fas fa-pen"></i> Edit</button>
        </div>
      </div>
      
      <!-- Chronic Diseases -->
      <div class="ws-mh-card">
        <div class="ws-mh-header"><i class="fas fa-heart-pulse"></i> Chronic Conditions</div>
        <div class="ws-mh-body">${ws.chronic_diseases ? escapeHTML(ws.chronic_diseases) : 'None recorded'}</div>
        <div class="ws-mh-actions">
          <button class="btn btn-outline btn-sm" onclick="openChronicModal()"><i class="fas fa-pen"></i> Edit</button>
        </div>
      </div>
      
      <!-- Current Medications -->
      <div class="ws-mh-card">
        <div class="ws-mh-header"><i class="fas fa-pills"></i> Current Medications</div>
        <div class="ws-mh-body">${ws.current_medications ? escapeHTML(ws.current_medications) : 'None recorded'}</div>
        <div class="ws-mh-actions">
          <button class="btn btn-outline btn-sm" onclick="openMedicationsModal()"><i class="fas fa-pen"></i> Edit</button>
        </div>
      </div>
      
      <!-- Previous Surgeries -->
      <div class="ws-mh-card">
        <div class="ws-mh-header"><i class="fas fa-scalpel"></i> Previous Surgeries</div>
        <div class="ws-mh-body">${mr?.previous_surgeries ? escapeHTML(mr.previous_surgeries) : 'None recorded'}</div>
        <div class="ws-mh-actions">
          <button class="btn btn-outline btn-sm" onclick="openSurgeriesModal()"><i class="fas fa-pen"></i> Edit</button>
        </div>
      </div>
      
      <!-- Family History -->
      <div class="ws-mh-card">
        <div class="ws-mh-header"><i class="fas fa-family"></i> Family History</div>
        <div class="ws-mh-body">${mr?.family_history ? escapeHTML(mr.family_history) : 'None recorded'}</div>
        <div class="ws-mh-actions">
          <button class="btn btn-outline btn-sm" onclick="openFamilyModal()"><i class="fas fa-pen"></i> Edit</button>
        </div>
      </div>
      
      <!-- Medical Notes -->
      <div class="ws-mh-card" style="grid-column:1/-1">
        <div class="ws-mh-header"><i class="fas fa-stethoscope"></i> Medical Notes</div>
        <div class="ws-mh-body">${mr?.medical_notes ? escapeHTML(mr.medical_notes) : 'No notes'}</div>
        <div class="ws-mh-actions">
          <button class="btn btn-outline btn-sm" onclick="openNotesModal()"><i class="fas fa-pen"></i> Edit Notes</button>
        </div>
      </div>
    </div>
    
    <!-- Audit Information -->
    <div class="ws-mh-audit">
      <div class="ws-mh-audit-label">Last Updated</div>
      <div class="ws-mh-audit-value">
        ${mr?.updated_at ? `<i class="fas fa-user-md"></i> ${wsUser?.name || 'Doctor'} · ${formatDate(mr.updated_at)}` : 'Not yet updated'}
      </div>
    </div>`;
}

/* ═══════════════════════════════════════════════════════════
   CURRENT VISIT TAB — Main working area
   ═══════════════════════════════════════════════════════════ */

function renderVisit() {
  const cv = ws.current_visit;
  const cvn = ws.current_visit_note;

  if (!cv) {
    document.getElementById('ws-visit').innerHTML = `
      <div class="ws-card">
        <div class="ws-card-header"><h3 class="ws-card-title"><i class="fas fa-stethoscope"></i> Current Visit</h3></div>
        <div class="ws-empty"><i class="fas fa-calendar-xmark"></i><h4>No Active Visit</h4><p>There is no confirmed appointment for this patient. Select a confirmed appointment to begin a visit.</p><a href="\${getBasePath()}pages/doctor/doctor-dashboard.html#appointments" class="btn btn-primary"><i class="fas fa-calendar-days"></i> Appointments</a></div>
      </div>`;
    return;
  }

  // Phase 5.4: Use workflow status only
  const wf = cv.visit_workflow;
  const status = wf ? (wf.status || '').toLowerCase().replace(/\s+/g, '_') : 'waiting';
  const statusClass = status;
  const isWaiting = status === 'waiting';
  const isInProgress = status === 'in_progress';
  const isReady = status === 'ready_to_complete';
  const isCompleted = status === 'completed';
  const isNew = !cvn;
  const note = cvn || {};
  const statusLabel = getVisitStatusLabel(cv);

  // If waiting, show start visit prompt
  if (isWaiting) {
    document.getElementById('ws-visit').innerHTML = `
      <div class="ws-card">
        <div class="ws-card-header">
          <h3 class="ws-card-title"><i class="fas fa-stethoscope"></i> Current Visit</h3>
          <span class="status status-${statusClass}">${escapeHTML(statusLabel)}</span>
        </div>
        <div class="ws-empty" style="padding:var(--s6)">
          <i class="fas fa-play-circle" style="font-size:2.5rem;opacity:0.5;color:var(--primary)"></i>
          <h4>Ready to Start</h4>
          <p>This appointment is confirmed and waiting for you to begin.</p>
          <button class="btn btn-primary" onclick="startVisit()" style="margin-top:var(--s3);padding:var(--s3) var(--s8);font-size:1rem">
            <i class="fas fa-play"></i> Start Visit
          </button>
        </div>
      </div>`;
    return;
  }

  // If completed, show read-only view
  if (isCompleted) {
    document.getElementById('ws-visit').innerHTML = `
      <div class="ws-card">
        <div class="ws-card-header">
          <h3 class="ws-card-title"><i class="fas fa-stethoscope"></i> Current Visit</h3>
          <span class="status status-completed">Completed</span>
        </div>
        <div class="ws-info-grid">
          <div class="ws-info-item"><div class="ws-info-label">Appointment ID</div><div class="ws-info-value">#${cv.id}</div></div>
          <div class="ws-info-item"><div class="ws-info-label">Department</div><div class="ws-info-value">${escapeHTML(cv.department)}</div></div>
          <div class="ws-info-item"><div class="ws-info-label">Date</div><div class="ws-info-value">${escapeHTML(cv.date)}</div></div>
          <div class="ws-info-item"><div class="ws-info-label">Time</div><div class="ws-info-value">${escapeHTML(formatApptTime(cv))}</div></div>
        </div>
      </div>
      ${cvn ? `
      <div class="ws-card">
        <div class="ws-card-header"><h3 class="ws-card-title"><i class="fas fa-file-pen"></i> Visit Note</h3><span class="status status-completed" style="font-size:0.7rem">Completed</span></div>
        <div class="ws-info-grid">
          <div class="ws-info-item" style="grid-column:1/-1"><div class="ws-info-label">Diagnosis</div><div class="ws-info-value">${escapeHTML(cvn.diagnosis || '—')}</div></div>
          <div class="ws-info-item" style="grid-column:1/-1"><div class="ws-info-label">Symptoms</div><div class="ws-info-value">${escapeHTML(cvn.symptoms || '—')}</div></div>
          <div class="ws-info-item" style="grid-column:1/-1"><div class="ws-info-label">Treatment Plan</div><div class="ws-info-value">${escapeHTML(cvn.treatment || '—')}</div></div>
          ${cvn.doctor_notes ? `<div class="ws-info-item" style="grid-column:1/-1"><div class="ws-info-label">Doctor's Notes</div><div class="ws-info-value">${escapeHTML(cvn.doctor_notes)}</div></div>` : ''}
        </div>
      </div>` : ''}
      <div class="ws-card" style="text-align:center;padding:var(--s6)">
        <i class="fas fa-check-circle" style="font-size:2rem;color:var(--success);opacity:0.5"></i>
        <h4 style="margin:var(--s2) 0 0;color:var(--text-secondary)">Visit Completed</h4>
        <p style="color:var(--text-muted);font-size:0.82rem">This visit has been completed and the patient record has been updated.</p>
      </div>`;
    return;
  }

  // In Progress or Ready to Complete — show the form
  document.getElementById('ws-visit').innerHTML = `
    <div class="ws-card">
      <div class="ws-card-header">
        <h3 class="ws-card-title"><i class="fas fa-stethoscope"></i> Current Visit</h3>
        <div><span class="status status-${statusClass}">${escapeHTML(statusLabel)}</span><span style="margin-left:var(--s2);color:var(--text-muted);font-size:0.8rem">#${cv.id} · ${escapeHTML(cv.date)} ${escapeHTML(formatApptTime(cv))}</span></div>
      </div>
      <div class="ws-info-grid">
        <div class="ws-info-item"><div class="ws-info-label">Appointment ID</div><div class="ws-info-value">#${cv.id}</div></div>
        <div class="ws-info-item"><div class="ws-info-label">Department</div><div class="ws-info-value">${escapeHTML(cv.department)}</div></div>
        <div class="ws-info-item"><div class="ws-info-label">Date</div><div class="ws-info-value">${escapeHTML(cv.date)}</div></div>
        <div class="ws-info-item"><div class="ws-info-label">Time</div><div class="ws-info-value">${escapeHTML(formatApptTime(cv))}</div></div>
        ${cv.notes ? `<div class="ws-info-item" style="grid-column:1/-1"><div class="ws-info-label">Patient Notes</div><div class="ws-info-value">${escapeHTML(cv.notes)}</div></div>` : ''}
      </div>
    </div>

    <div class="ws-card">
      <div class="ws-card-header">
        <h3 class="ws-card-title"><i class="fas fa-file-pen"></i> ${isNew ? 'Add Visit Note' : 'Edit Visit Note'}</h3>
        <div style="display:flex;align-items:center;gap:var(--s2)">
          ${!isNew ? '<span class="status status-confirmed" style="font-size:0.7rem">Saved</span>' : ''}
          <span class="ws-kbd-hint"><kbd>Ctrl</kbd>+<kbd>S</kbd> save</span>
        </div>
      </div>
      <form id="ws-vf" class="ws-visit-form" novalidate>
        <input type="hidden" id="ws-vf-appt" value="${cv.id}" />
        <div class="form-group full-w">
          <label for="ws-vf-dx">Diagnosis <span style="color:var(--text-muted);font-weight:400">(primary diagnosis or impression) <span style="color:var(--danger)">*</span></span></label>
          <input type="text" id="ws-vf-dx" placeholder="e.g. Acute bronchitis, Hypertension stage 1" value="${escapeHTML(note.diagnosis || '')}" maxlength="500" />
        </div>
        <div class="form-group full-w">
          <label for="ws-vf-sx">Symptoms <span style="color:var(--text-muted);font-weight:400">(patient-reported complaints) <span style="color:var(--danger)">*</span></span></label>
          <textarea id="ws-vf-sx" placeholder="e.g. Cough with sputum, fever 38.5°C for 3 days" maxlength="2000">${escapeHTML(note.symptoms || '')}</textarea>
        </div>
        <div class="form-group full-w">
          <label for="ws-vf-tx">Treatment Plan <span style="color:var(--text-muted);font-weight:400">(procedures, referrals, follow-up) <span style="color:var(--danger)">*</span></span></label>
          <textarea id="ws-vf-tx" placeholder="e.g. Prescribed antibiotics, follow-up in 7 days" maxlength="2000">${escapeHTML(note.treatment || '')}</textarea>
        </div>
        <div class="form-group full-w">
          <label for="ws-vf-notes">Doctor's Notes <span style="color:var(--text-muted);font-weight:400">(clinical observations, assessment — optional)</span></label>
          <textarea id="ws-vf-notes" placeholder="Clinical observations and assessment..." maxlength="5000" style="min-height:90px">${escapeHTML(note.doctor_notes || '')}</textarea>
        </div>
        <div class="form-group full-w">
          <label for="ws-vf-followup">Follow-up Instructions <span style="color:var(--text-muted);font-weight:400">(optional)</span></label>
          <textarea id="ws-vf-followup" placeholder="e.g. Return in 2 weeks for blood work, call if symptoms worsen" maxlength="1000" style="min-height:50px">${escapeHTML(note.follow_up_instructions || '')}</textarea>
        </div>
        <div class="ws-visit-actions">
          <button type="submit" class="btn btn-primary" style="padding:var(--s3) var(--s6);font-weight:600"><i class="fas fa-floppy-disk"></i> ${isNew ? 'Save Visit Note' : 'Update Visit Note'}</button>
          <button type="button" class="btn btn-outline" onclick="openRxModal()"><i class="fas fa-prescription"></i> Issue Prescription</button>
          ${isInProgress || isReady ? `<button type="button" class="btn btn-success" onclick="openReviewModal()" style="margin-left:auto"><i class="fas fa-check-circle"></i> Review & Complete</button>` : ''}
        </div>
      </form>
    </div>`;

  document.getElementById('ws-vf').addEventListener('submit', handleVisitSave);

  // Part 3: Set up autosave listeners on the form
  setupAutosave();

  // Phase 5.4.1: Set up real-time workflow status updates as user types
  const dxField = document.getElementById('ws-vf-dx');
  const sxField = document.getElementById('ws-vf-sx');
  const txField = document.getElementById('ws-vf-tx');
  
  if (dxField) dxField.addEventListener('input', debouncedStatusCheck);
  if (sxField) sxField.addEventListener('input', debouncedStatusCheck);
  if (txField) txField.addEventListener('input', debouncedStatusCheck);

  // Part 11: Focus the Diagnosis field
  setTimeout(() => {
    const dx = document.getElementById('ws-vf-dx');
    if (dx) dx.focus();
  }, 200);
}

/* ═══════════════════════════════════════════════════════════
   VISIT NOTE SAVE
   ═══════════════════════════════════════════════════════════ */

async function handleVisitSave(e) {
  e.preventDefault();
  const apptId = document.getElementById('ws-vf-appt')?.value;
  const diagnosis = document.getElementById('ws-vf-dx')?.value.trim() || '';
  const symptoms = document.getElementById('ws-vf-sx')?.value.trim() || '';
  const treatment = document.getElementById('ws-vf-tx')?.value.trim() || '';
  const doctorNotes = document.getElementById('ws-vf-notes')?.value.trim() || '';
  const followup = document.getElementById('ws-vf-followup')?.value.trim() || '';

  if (!diagnosis && !symptoms && !treatment && !doctorNotes) {
    showToast('Please fill in at least one field.', 'error');
    return;
  }

  const btn = e.target.querySelector('[type="submit"]');
  const orig = btn?.textContent || 'Save';
  setLoading(btn, true, 'Saving...');

  const r = await apiFetch((getBasePath() + "api/medical/add-visit-note.php"), {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      appointment_id: parseInt(apptId),
      patient_id: patientId,
      diagnosis, symptoms, treatment,
      doctor_notes: doctorNotes,
      follow_up_instructions: followup,
    }),
  }, 'Failed to save visit note.');

  setLoading(btn, false, orig);
  if (r.data?.success) {
    showToast('Visit note saved!', 'success');
    // Part 1: Check if required fields are filled -> mark as Ready to Complete
    await checkAndUpdateVisitStatus(diagnosis, symptoms, treatment);
    // Reload data to get updated visit note
    await loadData();
  } else {
    showToast(r.data?.message || 'Failed to save.', 'error');
  }
}

/* ═══════════════════════════════════════════════════════════
   PART 5 — CLINICAL VALIDATION
   ═══════════════════════════════════════════════════════════ */

function validateVisit() {
  const diagnosis = document.getElementById('ws-vf-dx')?.value.trim() || '';
  const symptoms = document.getElementById('ws-vf-sx')?.value.trim() || '';
  const treatment = document.getElementById('ws-vf-tx')?.value.trim() || '';

  const errors = [];
  if (!diagnosis) errors.push('Diagnosis is required.');
  if (!symptoms) errors.push('Symptoms are required.');
  if (!treatment) errors.push('Treatment Plan is required.');

  // Highlight missing fields
  const dxField = document.getElementById('ws-vf-dx');
  const sxField = document.getElementById('ws-vf-sx');
  const txField = document.getElementById('ws-vf-tx');

  [dxField, sxField, txField].forEach(f => {
    if (f) {
      f.classList.remove('ws-field-error');
      const msg = f.parentElement.querySelector('.ws-field-error-message');
      if (msg) msg.remove();
    }
  });

  if (!diagnosis && dxField) {
    dxField.classList.add('ws-field-error');
    const msg = document.createElement('div');
    msg.className = 'ws-field-error-message';
    msg.innerHTML = '<i class="fas fa-exclamation-circle"></i> Diagnosis is required';
    dxField.parentElement.appendChild(msg);
  }
  if (!symptoms && sxField) {
    sxField.classList.add('ws-field-error');
    const msg = document.createElement('div');
    msg.className = 'ws-field-error-message';
    msg.innerHTML = '<i class="fas fa-exclamation-circle"></i> Symptoms are required';
    sxField.parentElement.appendChild(msg);
  }
  if (!treatment && txField) {
    txField.classList.add('ws-field-error');
    const msg = document.createElement('div');
    msg.className = 'ws-field-error-message';
    msg.innerHTML = '<i class="fas fa-exclamation-circle"></i> Treatment Plan is required';
    txField.parentElement.appendChild(msg);
  }

  return { valid: errors.length === 0, errors };
}

/* ═══════════════════════════════════════════════════════════
   PART 1 — AUTO-UPDATE VISIT STATUS
   ═══════════════════════════════════════════════════════════ */

async function checkAndUpdateVisitStatus(diagnosis, symptoms, treatment) {
  const cv = ws.current_visit;
  if (!cv) return;
  
  // Phase 5.4: Use workflow status only
  const wf = cv.visit_workflow;
  const status = wf ? (wf.status || '').toLowerCase().replace(/\s+/g, '_') : 'waiting';
  if (status === 'completed') return;

  // If all required fields are filled, mark as Ready to Complete
  if (diagnosis && symptoms && treatment) {
    if (status !== 'ready_to_complete') {
      const r = await apiFetch((getBasePath() + "api/medical/transition-workflow.php"), {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ appointment_id: cv.id, status: 'Ready to Complete' }),
      }, '');
      
      // Update local state and UI if transition succeeded
      if (r.data?.success && r.data.workflow) {
        ws.current_visit.visit_workflow = r.data.workflow;
        renderSummaryCard();
        updateProgressBar();
      }
    }
  } else {
    // If not all filled but in progress, ensure it's In Progress
    if (status !== 'in_progress' && status !== 'waiting') {
      const r = await apiFetch((getBasePath() + "api/medical/transition-workflow.php"), {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ appointment_id: cv.id, status: 'In Progress' }),
      }, '');
      
      // Update local state and UI if transition succeeded
      if (r.data?.success && r.data.workflow) {
        ws.current_visit.visit_workflow = r.data.workflow;
        renderSummaryCard();
        updateProgressBar();
      }
    }
  }
}

// Debounced version for real-time updates as user types
let statusCheckTimeout = null;
function debouncedStatusCheck() {
  if (statusCheckTimeout) clearTimeout(statusCheckTimeout);
  statusCheckTimeout = setTimeout(() => {
    const diagnosis = document.getElementById('ws-vf-dx')?.value.trim() || '';
    const symptoms = document.getElementById('ws-vf-sx')?.value.trim() || '';
    const treatment = document.getElementById('ws-vf-tx')?.value.trim() || '';
    checkAndUpdateVisitStatus(diagnosis, symptoms, treatment);
  }, 500); // Wait 500ms after user stops typing
}

/* ═══════════════════════════════════════════════════════════
   PART 8 — FINISH VISIT REVIEW MODAL
   ═══════════════════════════════════════════════════════════ */

function initReviewModal() {
  const handler = async (btn) => {
    if (!btn) return;
    setLoading(btn, true, 'Completing...');
    await completeVisit();
    setLoading(btn, false, 'Complete Visit');
  };

  const btn1 = document.getElementById('ws-review-confirm-btn');
  if (btn1) btn1.addEventListener('click', () => handler(btn1));

  const btn2 = document.getElementById('ws-confirm-complete-btn');
  if (btn2) btn2.addEventListener('click', () => handler(btn2));
}

function openReviewModal() {
  // Part 5: Validate first
  const validation = validateVisit();
  const cv = ws.current_visit;
  const cvn = ws.current_visit_note;
  const rx = ws.prescriptions || [];

  const hasDiagnosis = !!(document.getElementById('ws-vf-dx')?.value.trim());
  const hasSymptoms = !!(document.getElementById('ws-vf-sx')?.value.trim());
  const hasTreatment = !!(document.getElementById('ws-vf-tx')?.value.trim());
  const hasPrescription = rx.length > 0;

  const checklist = document.getElementById('ws-review-checklist');
  checklist.innerHTML = `
    <div class="ws-review-item ${hasDiagnosis ? 'checked' : 'unchecked'}">
      <i class="fas ${hasDiagnosis ? 'fa-check-circle' : 'fa-times-circle'}"></i>
      <span>Diagnosis</span>
    </div>
    <div class="ws-review-item ${hasSymptoms ? 'checked' : 'unchecked'}">
      <i class="fas ${hasSymptoms ? 'fa-check-circle' : 'fa-times-circle'}"></i>
      <span>Symptoms</span>
    </div>
    <div class="ws-review-item ${hasTreatment ? 'checked' : 'unchecked'}">
      <i class="fas ${hasTreatment ? 'fa-check-circle' : 'fa-times-circle'}"></i>
      <span>Treatment Plan</span>
    </div>
    <div class="ws-review-item ${hasPrescription ? 'checked' : 'unchecked'}">
      <i class="fas ${hasPrescription ? 'fa-check-circle' : 'fa-times-circle'}"></i>
      <span>Prescription ${hasPrescription ? `(${rx.length} issued)` : '(none issued)'}</span>
    </div>`;

  // Show errors if validation failed
  const errorsDiv = document.getElementById('ws-review-errors');
  if (!validation.valid) {
    errorsDiv.style.display = 'block';
    errorsDiv.innerHTML = `<i class="fas fa-exclamation-triangle"></i> Please complete the following before finishing:<ul>${validation.errors.map(e => `<li>${escapeHTML(e)}</li>`).join('')}</ul>`;
    document.getElementById('ws-review-confirm-btn').disabled = true;
  } else {
    errorsDiv.style.display = 'none';
    document.getElementById('ws-review-confirm-btn').disabled = false;
  }

  openModal('ws-review-modal');
}

/* ═══════════════════════════════════════════════════════════
   PART 9 — AUTOMATIC POST-VISIT ACTIONS
   ═══════════════════════════════════════════════════════════ */

async function completeVisit() {
  const cv = ws.current_visit;
  if (!cv) { showToast('No active visit.', 'error'); return; }

  isCompletingVisit = true;

  const diagnosis = document.getElementById('ws-vf-dx')?.value.trim() || '';
  const symptoms = document.getElementById('ws-vf-sx')?.value.trim() || '';
  const treatment = document.getElementById('ws-vf-tx')?.value.trim() || '';
  const doctorNotes = document.getElementById('ws-vf-notes')?.value.trim() || '';
  const followup = document.getElementById('ws-vf-followup')?.value.trim() || '';

  // Phase 5.4: Use atomic complete_visit endpoint
  const r = await apiFetch((getBasePath() + "api/medical/complete-visit.php"), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      appointment_id: cv.id,
      diagnosis,
      symptoms,
      treatment,
      doctor_notes: doctorNotes,
      follow_up_instructions: followup,
    }),
  }, 'Could not complete visit.');

  if (r.data?.success) {
    showToast('Visit completed successfully!', 'success');
    closeModal('ws-review-modal');
    await loadData();
    switchTab('overview');
  } else {
    showToast(r.data?.message || 'Could not complete visit.', 'error');
  }

  isCompletingVisit = false;
}

/* ═══════════════════════════════════════════════════════════
   PART 6 — SMART PRESCRIPTION WORKFLOW
   ═══════════════════════════════════════════════════════════ */

function renderPrescriptions() {
  const rx = ws.prescriptions || [];
  const cv = ws.current_visit;
  const isCompleted = cv && (cv.status || '').toLowerCase() === 'completed';
  const c = document.getElementById('ws-prescriptions');

  if (rx.length === 0) {
    c.innerHTML = `
      <div class="ws-rx-empty-state">
        <i class="fas fa-prescription"></i>
        <h4>No Prescription Issued</h4>
        <p>No prescriptions have been issued for this visit.</p>
        <button class="btn btn-primary" onclick="openRxModal()" ${isCompleted ? 'disabled' : ''}>
          <i class="fas fa-plus"></i> Issue Prescription
        </button>
      </div>`;
    return;
  }

  // Show summary card for latest prescription
  const latest = rx[0];
  const sl = (latest.status || 'Active').toLowerCase();
  const items = (latest.items || []).map(i =>
    `<div class="ws-rx-item"><span class="ws-rx-item-name">${escapeHTML(i.medication_name)}</span><span class="ws-rx-item-detail">${escapeHTML(i.strength)} — ${escapeHTML(i.dosage)} — ${escapeHTML(i.frequency)} — ${escapeHTML(i.duration)}</span></div>`
  ).join('') || '<p style="color:var(--text-muted);font-size:0.75rem">No details</p>';

  c.innerHTML = `
    <div class="ws-rx-summary-card">
      <div class="ws-rx-summary-icon"><i class="fas fa-prescription"></i></div>
      <div class="ws-rx-summary-info">
        <div class="ws-rx-summary-title">Prescription Issued</div>
        <div class="ws-rx-summary-meta">
          <span><i class="fas fa-pills"></i> ${latest.item_count || 0} medication(s)</span>
          <span><i class="fas fa-calendar"></i> ${formatDate(latest.created_at)}</span>
          <span><span class="status status-${sl}" style="font-size:0.65rem">${escapeHTML(latest.status)}</span></span>
        </div>
      </div>
      <div class="ws-rx-summary-actions">
        <button class="btn btn-outline btn-sm" onclick="openRxView(${latest.id})"><i class="fas fa-eye"></i> View</button>
        <button class="btn btn-outline btn-sm" onclick="printRx(${latest.id})"><i class="fas fa-print"></i> Print</button>
        ${!isCompleted ? `<button class="btn btn-outline btn-sm" onclick="openRxModal()"><i class="fas fa-pen"></i> Edit</button>` : ''}
      </div>
    </div>
    <div style="display:flex;justify-content:flex-end;margin-bottom:var(--s3)">
      <button class="btn btn-primary" onclick="openRxModal()" ${isCompleted ? 'disabled' : ''}>
        <i class="fas fa-plus"></i> Issue Prescription
      </button>
    </div>
    <div class="ws-rx-grid">${rx.map(r => {
      const sl2 = (r.status || 'Active').toLowerCase();
      const items2 = (r.items || []).map(i =>
        `<div class="ws-rx-item"><span class="ws-rx-item-name">${escapeHTML(i.medication_name)}</span><span class="ws-rx-item-detail">${escapeHTML(i.strength)} — ${escapeHTML(i.dosage)} — ${escapeHTML(i.frequency)} — ${escapeHTML(i.duration)}</span></div>`
      ).join('') || '<p style="color:var(--text-muted);font-size:0.75rem">No details</p>';
      return `<div class="ws-rx-card">
        <div class="ws-rx-header"><div><div class="ws-rx-doctor">${escapeHTML(r.doctor_name)}</div><div class="ws-rx-id">RX-${String(r.id).padStart(5,'0')} · ${escapeHTML(r.department)}</div></div><span class="status status-${sl2}">${escapeHTML(r.status)}</span></div>
        <div class="ws-rx-meta"><span><i class="fas fa-calendar"></i> ${formatDate(r.created_at)}</span><span><i class="fas fa-pills"></i> ${r.item_count || 0} med(s)</span></div>
        <div class="ws-rx-items">${items2}</div>
        <div style="display:flex;gap:var(--s2);margin-top:var(--s2);padding-top:var(--s2);border-top:1px solid var(--border-light)">
          <button class="btn btn-outline btn-sm" onclick="openRxView(${r.id})"><i class="fas fa-eye"></i> View</button>
          <button class="btn btn-outline btn-sm" onclick="printRx(${r.id})"><i class="fas fa-print"></i> Print</button>
          ${!isCompleted ? `<button class="btn btn-outline btn-sm" onclick="editRx(${r.id})"><i class="fas fa-pen"></i> Edit</button>` : ''}
        </div>
      </div>`;
    }).join('')}</div>`;
}

/* ═══════════════════════════════════════════════════════════
   PART 10 — BETTER TIMELINE
   ═══════════════════════════════════════════════════════════ */

function renderTimeline() {
  const appointments = ws.appointments || [];
  const visitNotes = ws.visit_notes || [];
  const prescriptions = ws.prescriptions || [];
  const mhUpdates = ws.medical_history_updates || [];
  const c = document.getElementById('ws-timeline');

  const entries = [];

  // Appointment events
  appointments.forEach(a => {
    const statusLower = (a.status || '').toLowerCase();
    let icon = 'fa-calendar-check';
    let dotClass = 'appointment';
    if (statusLower === 'completed') { icon = 'fa-check-circle'; dotClass = 'completed'; }
    else if (statusLower === 'cancelled') { icon = 'fa-ban'; dotClass = 'cancelled'; }

    entries.push({
      date: a.date + ' ' + (a.time || '00:00'),
      type: 'appointment',
      dotClass,
      icon,
      title: `<i class="fas ${icon}"></i> Appointment ${statusLower === 'completed' ? 'Completed' : statusLower === 'cancelled' ? 'Cancelled' : 'Confirmed'}`,
      text: `${escapeHTML(a.department)} · ${escapeHTML(a.doctor_user_name || a.doctor)}`,
      detail: `${escapeHTML(a.date)} ${escapeHTML(formatApptTime(a))}`,
      doctor: a.doctor_user_name || a.doctor || '—',
      quickView: `Status: ${a.status} · Department: ${escapeHTML(a.department)}`
    });
  });

  // Visit note events
  visitNotes.forEach(v => {
    entries.push({
      date: v.appt_date + ' ' + (v.appt_time || '00:00'),
      type: 'visit-note',
      dotClass: 'visit-note',
      icon: 'fa-file-pen',
      title: `<i class="fas fa-file-pen"></i> ${v.diagnosis ? 'Diagnosis Saved' : 'Visit Documented'}`,
      text: v.diagnosis ? `Diagnosis: ${escapeHTML(v.diagnosis)}` : 'Visit documented',
      detail: escapeHTML(v.department),
      doctor: v.doctor_name || '—',
      quickView: v.diagnosis ? `Diagnosis: ${escapeHTML(v.diagnosis)}${v.symptoms ? ' · Symptoms: ' + escapeHTML(v.symptoms.substring(0, 50)) : ''}` : 'No diagnosis recorded'
    });
  });

  // Prescription events
  prescriptions.forEach(r => {
    entries.push({
      date: r.created_at,
      type: 'prescription',
      dotClass: 'prescription',
      icon: 'fa-prescription',
      title: `<i class="fas fa-prescription"></i> Prescription Issued`,
      text: `${r.item_count || 0} medication(s) · ${r.status}`,
      detail: `RX-${String(r.id).padStart(5, '0')}`,
      doctor: r.doctor_name || '—',
      quickView: `Status: ${r.status} · Items: ${r.item_count || 0}${r.notes ? ' · Notes: ' + escapeHTML(r.notes.substring(0, 80)) : ''}`
    });
  });

  // Medical History Update events (Phase 5.3.2)
  mhUpdates.forEach(mhu => {
    const fieldLabels = {
      'allergies': 'Allergies',
      'chronic_diseases': 'Chronic Conditions',
      'current_medications': 'Current Medications',
      'previous_surgeries': 'Previous Surgeries',
      'family_history': 'Family History',
      'medical_notes': 'Medical Notes',
      'blood_type': 'Blood Type'
    };
    const fieldLabel = fieldLabels[mhu.field_name] || mhu.field_name;
    
    entries.push({
      date: mhu.created_at,
      type: 'medical-history-update',
      dotClass: 'medical-history',
      icon: 'fa-notes-medical',
      title: `<i class="fas fa-notes-medical"></i> Medical History Updated`,
      text: `${fieldLabel} updated`,
      detail: formatDate(mhu.created_at),
      doctor: mhu.doctor_name || '—',
      quickView: `Updated: ${fieldLabel}${mhu.new_value ? ' · New: ' + escapeHTML(mhu.new_value.substring(0, 50)) : ''}`
    });
  });

  entries.sort((a, b) => b.date.localeCompare(a.date));

  if (entries.length === 0) {
    c.innerHTML = `<div class="ws-empty"><i class="fas fa-clock-rotate"></i><h4>No Timeline Entries</h4><p>No activity recorded for this patient yet.</p></div>`;
    return;
  }

  const items = entries.map((e, idx) => `
    <div class="ws-tl-item">
      <div class="ws-tl-dot ${e.dotClass}"><i class="fas ${e.icon}"></i></div>
      <div class="ws-tl-date"><i class="fas fa-clock"></i> ${e.detail} · ${escapeHTML(e.doctor)}</div>
      <div class="ws-tl-content">
        <div class="ws-tl-title">${e.title}</div>
        <div class="ws-tl-text">${e.text}</div>
        <div class="ws-tl-meta">
          <span><i class="fas fa-user-md"></i> ${escapeHTML(e.doctor)}</span>
          <span><i class="fas fa-calendar"></i> ${e.detail}</span>
        </div>
        <div class="ws-tl-quick-view" id="ws-tl-qv-${idx}">${e.quickView}</div>
        <button class="ws-tl-toggle" onclick="toggleTimelineView(${idx})" aria-expanded="false" aria-controls="ws-tl-qv-${idx}">
          <i class="fas fa-chevron-down"></i> Quick View
        </button>
      </div>
    </div>`).join('');

  c.innerHTML = `<div class="ws-timeline">${items}</div>`;
}

function toggleTimelineView(idx) {
  const qv = document.getElementById('ws-tl-qv-' + idx);
  const btn = qv?.nextElementSibling;
  if (qv) {
    qv.classList.toggle('visible');
    if (btn) {
      btn.setAttribute('aria-expanded', qv.classList.contains('visible'));
      btn.innerHTML = qv.classList.contains('visible')
        ? '<i class="fas fa-chevron-up"></i> Hide Details'
        : '<i class="fas fa-chevron-down"></i> Quick View';
    }
  }
}

/* ═══════════════════════════════════════════════════════════
   PART 3 — AUTOSAVE DRAFT
   ═══════════════════════════════════════════════════════════ */

function setupAutosave() {
  // Clear any existing timers
  if (autosaveTimer) { clearInterval(autosaveTimer); autosaveTimer = null; }
  if (autosaveDebounceTimer) { clearTimeout(autosaveDebounceTimer); autosaveDebounceTimer = null; }

  const form = document.getElementById('ws-vf');
  if (!form) return;

  // Listen for input changes on the form
  const inputs = form.querySelectorAll('input, textarea');
  inputs.forEach(input => {
    input.addEventListener('input', () => {
      autosaveDirty = true;
      hasUnsavedChanges = true;
      // Debounce: save 2 seconds after typing stops
      if (autosaveDebounceTimer) clearTimeout(autosaveDebounceTimer);
      autosaveDebounceTimer = setTimeout(() => {
        if (autosaveDirty) saveDraftNow();
      }, AUTOSAVE_DEBOUNCE);
    });
  });

  // Periodic autosave every 12 seconds
  autosaveTimer = setInterval(() => {
    if (autosaveDirty) saveDraftNow();
  }, AUTOSAVE_INTERVAL);
}

function getFormSnapshot() {
  const diagnosis = document.getElementById('ws-vf-dx')?.value || '';
  const symptoms = document.getElementById('ws-vf-sx')?.value || '';
  const treatment = document.getElementById('ws-vf-tx')?.value || '';
  const doctorNotes = document.getElementById('ws-vf-notes')?.value || '';
  const followup = document.getElementById('ws-vf-followup')?.value || '';
  return JSON.stringify({ diagnosis, symptoms, treatment, doctorNotes, followup });
}

function isFormEmpty() {
  const diagnosis = document.getElementById('ws-vf-dx')?.value.trim() || '';
  const symptoms = document.getElementById('ws-vf-sx')?.value.trim() || '';
  const treatment = document.getElementById('ws-vf-tx')?.value.trim() || '';
  const doctorNotes = document.getElementById('ws-vf-notes')?.value.trim() || '';
  const followup = document.getElementById('ws-vf-followup')?.value.trim() || '';
  return !diagnosis && !symptoms && !treatment && !doctorNotes && !followup;
}

async function saveDraftNow() {
  if (!appointmentId) return;
  if (isFormEmpty()) {
    autosaveDirty = false;
    return;
  }

  const snapshot = getFormSnapshot();
  if (snapshot === lastSavedSnapshot) {
    autosaveDirty = false;
    return;
  }

  showAutosaveIndicator('saving');
  
  const diagnosis = document.getElementById('ws-vf-dx')?.value.trim() || '';
  const symptoms = document.getElementById('ws-vf-sx')?.value.trim() || '';
  const treatment = document.getElementById('ws-vf-tx')?.value.trim() || '';
  const doctorNotes = document.getElementById('ws-vf-notes')?.value.trim() || '';
  const followup = document.getElementById('ws-vf-followup')?.value.trim() || '';

  try {
    const r = await apiFetch((getBasePath() + "api/medical/save-visit-draft.php"), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        appointment_id: appointmentId,
        diagnosis,
        symptoms,
        treatment,
        doctor_notes: doctorNotes,
        follow_up_instructions: followup,
      }),
    }, '');

    if (r.data?.success) {
      lastSavedSnapshot = snapshot;
      autosaveDirty = false;
      hasUnsavedChanges = false;
      showAutosaveIndicator('saved');
    } else {
      showAutosaveIndicator('error');
    }
  } catch (e) {
    showAutosaveIndicator('error');
  }
}

async function restoreDraft() {
  if (!appointmentId) return;
  const cv = ws.current_visit;
  if (!cv) return;
  
  // Check workflow status - don't restore if completed
  const wf = cv.visit_workflow;
  const status = wf ? (wf.status || '').toLowerCase() : 'waiting';
  if (status === 'completed') {
    removeDraft();
    return;
  }

  try {
    const r = await apiFetch(`${getBasePath()}api/medical/get-visit-draft.php?appointment_id=${appointmentId}`, {}, '');
    if (!r.data?.success || !r.data.draft) return;

    const draft = r.data.draft;

    // Check if there's already a saved visit note (don't overwrite)
    if (ws.current_visit_note) {
      const note = ws.current_visit_note;
      if (note.diagnosis || note.symptoms || note.treatment) return;
    }

    const dx = document.getElementById('ws-vf-dx');
    const sx = document.getElementById('ws-vf-sx');
    const tx = document.getElementById('ws-vf-tx');
    const notes = document.getElementById('ws-vf-notes');
    const followup = document.getElementById('ws-vf-followup');

    if (dx && draft.diagnosis) dx.value = draft.diagnosis;
    if (sx && draft.symptoms) sx.value = draft.symptoms;
    if (tx && draft.treatment) tx.value = draft.treatment;
    if (notes && draft.doctor_notes) notes.value = draft.doctor_notes;
    if (followup && draft.follow_up_instructions) followup.value = draft.follow_up_instructions;

    lastSavedSnapshot = getFormSnapshot();
    showAutosaveIndicator('restored');
  } catch (e) {
    // Silently fail
  }
}

async function removeDraft() {
  if (!appointmentId) return;
  try {
    await apiFetch((getBasePath() + "api/medical/delete-visit-draft.php"), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ appointment_id: appointmentId }),
    }, '');
  } catch (e) {}
  lastSavedSnapshot = '';
  autosaveDirty = false;
  hasUnsavedChanges = false;
}

function showAutosaveIndicator(type) {
  const indicator = document.getElementById('ws-autosave-indicator');
  const text = document.getElementById('ws-autosave-text');
  if (!indicator || !text) return;

  indicator.classList.remove('saving', 'error');
  if (type === 'saved') {
    text.textContent = 'Draft saved';
    indicator.querySelector('i').className = 'fas fa-cloud';
  } else if (type === 'saving') {
    text.textContent = 'Saving...';
    indicator.querySelector('i').className = 'fas fa-spinner';
    indicator.classList.add('saving');
  } else if (type === 'error') {
    text.textContent = 'Save failed';
    indicator.querySelector('i').className = 'fas fa-exclamation-triangle';
    indicator.classList.add('error');
  } else if (type === 'restored') {
    text.textContent = 'Draft restored';
    indicator.querySelector('i').className = 'fas fa-cloud-arrow-up';
  }

  indicator.classList.add('visible');
  clearTimeout(indicator._hideTimer);
  indicator._hideTimer = setTimeout(() => {
    indicator.classList.remove('visible');
  }, 3000);
}

/* ═══════════════════════════════════════════════════════════
   PART 4 — UNSAVED CHANGES PROTECTION
   ═══════════════════════════════════════════════════════════ */

function initBeforeUnload() {
  window.addEventListener('beforeunload', (e) => {
    if (hasUnsavedChanges && !isCompletingVisit) {
      e.preventDefault();
      e.returnValue = 'You have unsaved changes. Are you sure you want to leave?';
    }
  });
}

/* ═══════════════════════════════════════════════════════════
   PART 11 — KEYBOARD SHORTCUTS
   ═══════════════════════════════════════════════════════════ */

function initKeyboardShortcuts() {
  document.addEventListener('keydown', (e) => {
    // Ctrl+S: Save current visit
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
      e.preventDefault();
      const form = document.getElementById('ws-vf');
      if (form && activeTab === 'visit') {
        form.dispatchEvent(new Event('submit'));
      }
    }
  });
}

/* ═══════════════════════════════════════════════════════════
   PRESCRIPTION MODAL (Issue)
   ═══════════════════════════════════════════════════════════ */

function openRxModal() {
  const cv = ws.current_visit;
  if (!cv) { showToast('No active visit to issue a prescription for.', 'error'); return; }

  document.getElementById('ws-rx-appt-id').value = cv.id;
  document.getElementById('ws-rx-pt-id').value = patientId;
  document.getElementById('ws-rx-pt-name').value = ws.patient.name || '';
  document.getElementById('ws-rx-appt-date').value = `${cv.date} ${formatApptTime(cv)}`;
  document.getElementById('ws-rx-notes-h').value = '';

  // Reset to one row
  document.getElementById('ws-rx-items').innerHTML = `
    <div class="ws-rx-row form-grid" style="margin-bottom:var(--s2)">
      <div class="form-group"><input type="text" class="rx-med" placeholder="Medication name" required /></div>
      <div class="form-group"><input type="text" class="rx-str" placeholder="Strength" /></div>
      <div class="form-group"><input type="text" class="rx-dos" placeholder="Dosage" /></div>
      <div class="form-group"><input type="text" class="rx-freq" placeholder="Frequency" /></div>
      <div class="form-group"><input type="text" class="rx-dur" placeholder="Duration" /></div>
      <div class="form-group" style="display:flex;align-items:flex-end">
        <button type="button" class="btn btn-outline btn-sm" onclick="this.closest('.ws-rx-row').remove()" style="color:var(--danger)"><i class="fas fa-trash-can"></i></button>
      </div>
    </div>`;

  openModal('ws-rx-modal');
}

function wsRxAddRow() {
  const c = document.getElementById('ws-rx-items');
  if (!c) return;
  const row = document.createElement('div');
  row.className = 'ws-rx-row form-grid';
  row.style.marginBottom = 'var(--s2)';
  row.innerHTML = `
    <div class="form-group"><input type="text" class="rx-med" placeholder="Medication name" required /></div>
    <div class="form-group"><input type="text" class="rx-str" placeholder="Strength" /></div>
    <div class="form-group"><input type="text" class="rx-dos" placeholder="Dosage" /></div>
    <div class="form-group"><input type="text" class="rx-freq" placeholder="Frequency" /></div>
    <div class="form-group"><input type="text" class="rx-dur" placeholder="Duration" /></div>
    <div class="form-group" style="display:flex;align-items:flex-end">
      <button type="button" class="btn btn-outline btn-sm rx-remove-row-btn" style="color:var(--danger)"><i class="fas fa-trash-can" aria-hidden="true"></i></button>
    </div>`;
  c.appendChild(row);
}

function initRxForm() {
  const addBtn = document.getElementById('ws-rx-add-row-btn');
  if (addBtn) {
    addBtn.addEventListener('click', wsRxAddRow);
  }

  const itemsContainer = document.getElementById('ws-rx-items');
  if (itemsContainer) {
    itemsContainer.addEventListener('click', (e) => {
      const btn = e.target.closest('.rx-remove-row-btn');
      if (btn) {
        const row = btn.closest('.ws-rx-row');
        if (row) {
          const allRows = itemsContainer.querySelectorAll('.ws-rx-row');
          if (allRows.length > 1) {
            row.remove();
          } else {
            showToast('Prescription must contain at least one medication row.', 'info');
          }
        }
      }
    });
  }

  const form = document.getElementById('ws-rx-form');
  if (!form) return;
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const apptId = parseInt(document.getElementById('ws-rx-appt-id')?.value || '0');
    const ptId = parseInt(document.getElementById('ws-rx-pt-id')?.value || '0');
    const notes = document.getElementById('ws-rx-notes-h')?.value.trim() || '';

    const rows = document.querySelectorAll('.ws-rx-row');
    const items = [];
    rows.forEach(row => {
      const name = row.querySelector('.rx-med')?.value?.trim();
      if (!name) return;
      items.push({ medication_name: name, strength: row.querySelector('.rx-str')?.value?.trim() || '', dosage: row.querySelector('.rx-dos')?.value?.trim() || '', frequency: row.querySelector('.rx-freq')?.value?.trim() || '', duration: row.querySelector('.rx-dur')?.value?.trim() || '' });
    });

    if (items.length === 0) { showToast('Add at least one medication.', 'error'); return; }

    const btn = form.querySelector('[type="submit"]');
    const orig = btn?.textContent || 'Issue';
    setLoading(btn, true, 'Issuing...');

    const r = await apiFetch((getBasePath() + "api/prescriptions/create.php"), {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ appointment_id: apptId, patient_id: ptId, items, notes }),
    }, 'Failed to create prescription.');

    setLoading(btn, false, orig);
    if (r.data?.success) {
      showToast('Prescription issued!', 'success');
      closeModal('ws-rx-modal');
      // Prescription issued -> move to Ready to Complete
      const cv = ws.current_visit;
      if (cv) {
        const status = (cv.status || '').toLowerCase();
        if (status !== 'completed' && status !== 'ready_to_complete' && status !== 'ready to complete') {
          await apiFetch((getBasePath() + "api/appointments/update.php"), {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: cv.id, status: 'Ready to Complete' }),
          }, '');
        }
      }
      await loadData();
      switchTab('prescriptions');
    } else {
      showToast(r.data?.message || 'Failed.', 'error');
    }
  });
}

/* ═══════════════════════════════════════════════════════════
   PRESCRIPTION VIEW & EDIT
   ═══════════════════════════════════════════════════════════ */

function openRxView(rxId) {
  // Use the shared prescription viewer from prescriptions.js
  Prescriptions.openViewModal(rxId);
}

/* ═══════════════════════════════════════════════════════════
   PHASE 5.3.2 — CLINICAL MEDICAL HISTORY MANAGEMENT
   ═══════════════════════════════════════════════════════════ */

// ── Modal Opening Functions ──

function openAllergiesModal() {
  const mr = ws.medical_record;
  document.getElementById('ws-mh-allergies-pt-id').value = patientId;
  document.getElementById('ws-mh-allergies-input').value = ws.allergies || '';
  openModal('ws-mh-allergies-modal');
}

function openChronicModal() {
  const mr = ws.medical_record;
  document.getElementById('ws-mh-chronic-pt-id').value = patientId;
  document.getElementById('ws-mh-chronic-input').value = ws.chronic_diseases || '';
  openModal('ws-mh-chronic-modal');
}

function openMedicationsModal() {
  const mr = ws.medical_record;
  document.getElementById('ws-mh-medications-pt-id').value = patientId;
  document.getElementById('ws-mh-medications-input').value = ws.current_medications || '';
  openModal('ws-mh-medications-modal');
}

function openSurgeriesModal() {
  const mr = ws.medical_record;
  document.getElementById('ws-mh-surgeries-pt-id').value = patientId;
  document.getElementById('ws-mh-surgeries-input').value = mr?.previous_surgeries || '';
  openModal('ws-mh-surgeries-modal');
}

function openFamilyModal() {
  const mr = ws.medical_record;
  document.getElementById('ws-mh-family-pt-id').value = patientId;
  document.getElementById('ws-mh-family-input').value = mr?.family_history || '';
  openModal('ws-mh-family-modal');
}

function openNotesModal() {
  const mr = ws.medical_record;
  document.getElementById('ws-mh-notes-pt-id').value = patientId;
  document.getElementById('ws-mh-notes-input').value = mr?.medical_notes || '';
  openModal('ws-mh-notes-modal');
}

function openBloodTypeModal() {
  const mr = ws.medical_record;
  document.getElementById('ws-mh-blood-pt-id').value = patientId;
  document.getElementById('ws-mh-blood-input').value = '';
  openModal('ws-mh-blood-modal');
}

// ── Form Initialization ──

function initClinicalHistoryForms() {
  // Allergies form
  const allergiesForm = document.getElementById('ws-mh-allergies-form');
  if (allergiesForm) {
    allergiesForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      await saveClinicalField('allergies', document.getElementById('ws-mh-allergies-input').value.trim());
    });
  }

  // Chronic diseases form
  const chronicForm = document.getElementById('ws-mh-chronic-form');
  if (chronicForm) {
    chronicForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      await saveClinicalField('chronic_diseases', document.getElementById('ws-mh-chronic-input').value.trim());
    });
  }

  // Medications form
  const medsForm = document.getElementById('ws-mh-medications-form');
  if (medsForm) {
    medsForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      await saveClinicalField('current_medications', document.getElementById('ws-mh-medications-input').value.trim());
    });
  }

  // Surgeries form
  const surgeriesForm = document.getElementById('ws-mh-surgeries-form');
  if (surgeriesForm) {
    surgeriesForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      await saveClinicalField('previous_surgeries', document.getElementById('ws-mh-surgeries-input').value.trim());
    });
  }

  // Family history form
  const familyForm = document.getElementById('ws-mh-family-form');
  if (familyForm) {
    familyForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      await saveClinicalField('family_history', document.getElementById('ws-mh-family-input').value.trim());
    });
  }

  // Medical notes form
  const notesForm = document.getElementById('ws-mh-notes-form');
  if (notesForm) {
    notesForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      await saveClinicalField('medical_notes', document.getElementById('ws-mh-notes-input').value.trim());
    });
  }

  // Blood type form
  const bloodForm = document.getElementById('ws-mh-blood-form');
  if (bloodForm) {
    bloodForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const bloodType = document.getElementById('ws-mh-blood-input').value;
      if (!bloodType) {
        showToast('Please select a blood type.', 'error');
        return;
      }
      await saveClinicalField('blood_type', bloodType);
    });
  }
}

// ── Save Clinical Field ──

async function saveClinicalField(field, value) {
  const btn = document.activeElement;
  const orig = btn?.textContent || 'Save';
  if (btn) setLoading(btn, true, 'Saving...');

  // Get old value for tracking
  const mr = ws.medical_record;
  const oldValue = mr?.[field] || '';

  const r = await apiFetch((getBasePath() + "api/medical/update-record.php"), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      patient_id: patientId,
      [field]: value || null,
    }),
  }, 'Failed to update medical record.');

  if (btn) setLoading(btn, false, orig);

  if (r.data?.success) {
    showToast('Medical record updated successfully.', 'success');
    
    // Record the update for timeline
    await recordMedicalHistoryUpdate(field, oldValue, value);
    
    // Close the modal
    const modalId = btn.closest('.modal-overlay')?.id;
    if (modalId) closeModal(modalId);
    
    // Reload data and re-render
    await loadData();
  } else {
    showToast(r.data?.message || 'Failed to update medical record.', 'error');
  }
}

// ── Record Medical History Update for Timeline ──

async function recordMedicalHistoryUpdate(field, oldValue, newValue) {
  try {
    await apiFetch((getBasePath() + "api/medical/add-history-update.php"), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        patient_id: patientId,
        field: field,
        old_value: oldValue,
        new_value: newValue,
      }),
    }, '');
  } catch (e) {
    // Silently fail - the update was still successful
    console.error('Failed to record medical history update:', e);
  }
}

function editRx(rxId) {
  // For simplicity, open the issue modal — the doctor can create a new one
  openRxModal();
}

function printRx(id) {
  // Use the shared print functionality from prescriptions.js
  // First load the prescription detail, then print
  apiFetch(`${getBasePath()}api/prescriptions/get.php?id=${id}`, {}, 'Failed to load prescription.')
    .then(result => {
      if (result.ok && result.data?.success && result.data.prescription) {
        Prescriptions.detail = result.data.prescription;
        Prescriptions.printPrescription();
      } else {
        showToast('Failed to load prescription for printing.', 'error');
      }
    });
}

/* ═══════════════════════════════════════════════════════════
   MODAL SYSTEM
   ═══════════════════════════════════════════════════════════ */

function initModals() {
  document.addEventListener('click', e => {
    const o = e.target.closest('.modal-overlay.open');
    if (o && e.target === o) closeModal(o.id);
  });
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
      const o = document.querySelector('.modal-overlay.open');
      if (o) closeModal(o.id);
    }
  });
  document.querySelectorAll('.modal-close-btn').forEach(b => {
    b.addEventListener('click', () => {
      const o = b.closest('.modal-overlay');
      if (o) closeModal(o.id);
    });
  });
}

function openModal(id) {
  const m = document.getElementById(id);
  if (!m) return;
  m.classList.add('open');
  document.body.classList.add('modal-open');
  const fi = m.querySelector('input, select, button');
  setTimeout(() => fi?.focus(), 100);
}

function closeModal(id) {
  const m = document.getElementById(id);
  if (!m) return;
  m.classList.remove('open');
  document.body.classList.remove('modal-open');
}

/* ═══════════════════════════════════════════════════════════
   EXPOSE GLOBALS
   ═══════════════════════════════════════════════════════════ */

window.switchTab = switchTab;
window.refreshWS = refreshWS;
window.openRxModal = openRxModal;
window.wsRxAddRow = wsRxAddRow;
window.openRxView = openRxView;
window.printRx = printRx;
window.editRx = editRx;
window.finishVisit = openReviewModal;
window.startVisit = startVisit;
window.openReviewModal = openReviewModal;
window.toggleTimelineView = toggleTimelineView;
window.closeModal = closeModal;


