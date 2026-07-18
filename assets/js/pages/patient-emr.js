/**
 * HealthBridge — Patient EMR (Electronic Medical Record) JavaScript
 * UNIFIED PATIENT MANAGEMENT — Phase 4.2.1
 *
 * The EMR is now the single entry point for all patient management.
 * The old Profile page has been removed.
 *
 * Features:
 * - Tab navigation (Overview, Appointments, Medical Records, Prescriptions, Visit Notes, Ratings, Notifications, Timeline)
 * - Quick Actions sidebar
 * - Inline editing cards for patient info
 * - Appointment booking for admin (reuses existing slot picker from dashboard)
 * - Medical record inline editing
 * - Appointment actions (approve/decline/cancel)
 * - Notification actions (mark read, delete)
 * - Password reset
 * - Delete patient
 * - Account status management
 * - Rating moderation (hide/restore)
 *
 * Reuses: apiFetch(), escapeHTML(), formatDate(), formatTime(), formatApptTime(), showToast() from main.js
 */

"use strict";

// ── State ──
let emrData = null;
let patientId = 0;
let currentNotifFilter = 'all';
let activeTab = 'overview';
let scrollPos = 0;
let isRefreshing = false;
let emrDoctorsList = [];
let emrLastSlotRequestId = 0;

const EMR_TABS = ['overview', 'appointments', 'medical-records', 'prescriptions', 'visit-notes', 'ratings', 'notifications', 'audit', 'timeline'];

document.addEventListener('DOMContentLoaded', async () => {
  const params = new URLSearchParams(window.location.search);
  patientId = parseInt(params.get('patient_id') || '0');

  const user = await requireServerRole('admin');
  if (!user) return;

  setText('emr-user-name', user.name || 'Admin');
  setText('emr-user-email', user.email || '');

  if (!patientId) {
    showToast('No patient selected.', 'error');
    document.getElementById('emr-main-content').innerHTML =
      '<div class="emr-empty-state"><i class="fas fa-user-slash"></i><h4>No Patient Selected</h4><p>Please select a patient from the admin panel.</p><a href=getBasePath() + "pages/admin/admin.html#patients" class="btn btn-primary">Go to Patients</a></div>';
    return;
  }

  initTabSystem();
  initEditForm();
  initModalSystem();
  initPrescriptionModal();
  initAppointmentModal();
  initBookAppointmentModal();
  initResetPasswordForm();
  initDeletePatient();
  initAdminCorrectionForm();
  await loadEmrData();

  // Restore tab from URL hash
  const hash = window.location.hash.substring(1);
  if (hash && EMR_TABS.includes(hash)) {
    switchTab(hash);
  }
});

function setText(id, text) {
  const el = document.getElementById(id);
  if (el) el.textContent = text;
}

/* ═══════════════════════════════════════════════════════════
   TAB SYSTEM — Sidebar + Top Tabs + Content always synced
   ═══════════════════════════════════════════════════════════ */

function initTabSystem() {
  document.querySelectorAll('#emr-sidebar-nav a[data-tab]').forEach(link => {
    link.addEventListener('click', (e) => {
      e.preventDefault();
      const tabId = link.getAttribute('data-tab');
      if (tabId) switchTab(tabId);
    });
  });

  document.querySelectorAll('.emr-tab').forEach(tab => {
    tab.addEventListener('click', () => {
      const tabId = tab.getAttribute('data-tab');
      if (tabId) switchTab(tabId);
    });

    tab.addEventListener('keydown', (e) => {
      const tabs = document.querySelectorAll('.emr-tab');
      const arr = Array.from(tabs);
      const idx = arr.indexOf(tab);
      let next = -1;

      if (e.key === 'ArrowRight' || e.key === 'ArrowDown') { e.preventDefault(); next = (idx + 1) % arr.length; }
      else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') { e.preventDefault(); next = (idx - 1 + arr.length) % arr.length; }
      else if (e.key === 'Home') { e.preventDefault(); next = 0; }
      else if (e.key === 'End') { e.preventDefault(); next = arr.length - 1; }

      if (next >= 0) {
        switchTab(arr[next].getAttribute('data-tab'));
        arr[next].focus();
      }
    });
  });
}

function switchTab(tabId) {
  if (!EMR_TABS.includes(tabId)) return;
  activeTab = tabId;

  document.querySelectorAll('.emr-tab').forEach(t => {
    const isActive = t.getAttribute('data-tab') === tabId;
    t.classList.toggle('active', isActive);
    t.setAttribute('aria-selected', isActive ? 'true' : 'false');
  });

  document.querySelectorAll('.emr-tab-content').forEach(c => {
    c.classList.toggle('active', c.id === `emr-${tabId}`);
  });

  document.querySelectorAll('#emr-sidebar-nav a[data-tab]').forEach(a => {
    a.classList.toggle('active', a.getAttribute('data-tab') === tabId);
  });

  if (window.location.hash !== `#${tabId}`) {
    history.replaceState(null, '', `#${tabId}`);
  }

  const activeTabEl = document.querySelector(`.emr-tab[data-tab="${tabId}"]`);
  if (activeTabEl) activeTabEl.focus();
}

/* ═══════════════════════════════════════════════════════════
   DATA LOADING
   ═══════════════════════════════════════════════════════════ */

async function loadEmrData({ refresh = false, preserveTab = false, preserveScroll = false } = {}) {
  if (refresh) {
    isRefreshing = true;
    const main = document.querySelector('.main-content');
    if (preserveScroll) scrollPos = main?.scrollTop || 0;
  }

  showSkeletons();

  const result = await apiFetch(
    `${getBasePath()}api/medical/get-emr-data.php?patient_id=${patientId}`,
    {},
    'Failed to load patient EMR data.'
  );

  if (!result.ok || !result.data?.success) {
    hideSkeletons();
    document.getElementById('emr-main-content').innerHTML = `
      <div class="emr-empty-state">
        <i class="fas fa-exclamation-triangle"></i>
        <h4>Failed to Load Data</h4>
        <p>${escapeHTML(result.data?.message || 'Could not load patient data.')}</p>
        <button class="btn btn-primary" onclick="location.reload()">Try Again</button>
      </div>`;
    isRefreshing = false;
    return;
  }

  emrData = result.data;
  hideSkeletons();
  renderAll();

  if (preserveTab && activeTab) switchTab(activeTab);
  if (preserveScroll && scrollPos > 0) {
    requestAnimationFrame(() => {
      const main = document.querySelector('.main-content');
      if (main) main.scrollTop = scrollPos;
    });
  }

  isRefreshing = false;
}

async function refreshEmr() {
  if (isRefreshing) return;
  showToast('Refreshing...', 'info', 1000);
  await loadEmrData({ refresh: true, preserveTab: true, preserveScroll: true });
  showToast('Data refreshed.', 'success');
}

/* ═══════════════════════════════════════════════════════════
   SKELETON LOADING
   ═══════════════════════════════════════════════════════════ */

function showSkeletons() {
  document.getElementById('emr-header-content').innerHTML = `
    <div class="emr-header-top">
      <div class="emr-skeleton emr-skeleton-avatar"></div>
      <div class="emr-header-info">
        <div class="emr-skeleton emr-skeleton-text short" style="height:28px;margin-bottom:var(--s3)"></div>
        <div class="emr-skeleton emr-skeleton-text tiny" style="height:14px"></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--s2);margin-top:var(--s4)">
          <div class="emr-skeleton emr-skeleton-text"></div>
          <div class="emr-skeleton emr-skeleton-text"></div>
          <div class="emr-skeleton emr-skeleton-text short"></div>
          <div class="emr-skeleton emr-skeleton-text short"></div>
        </div>
      </div>
    </div>`;

  const statsEl = document.getElementById('emr-stats');
  statsEl.innerHTML = Array(8).fill('').map(() =>
    '<div class="emr-skeleton emr-skeleton-stat"></div>'
  ).join('');
}

function hideSkeletons() {}

/* ═══════════════════════════════════════════════════════════
   RENDER ALL
   ═══════════════════════════════════════════════════════════ */

function renderAll() {
  if (!emrData) return;
  renderHeader();
  renderStats();
  renderOverview();
  renderAppointments();
  renderMedicalRecords();
  renderPrescriptions();
  renderVisitNotes();
  renderRatings();
  renderNotifications();
  renderAudit();
  renderTimeline();

  requestAnimationFrame(() => {
    document.querySelectorAll('.emr-stat-card').forEach((card, i) => {
      setTimeout(() => card.classList.add('animated'), i * 60);
    });
  });
}

/* ═══════════════════════════════════════════════════════════
   HEADER — with Quick Actions sidebar
   ═══════════════════════════════════════════════════════════ */

function renderHeader() {
  const p = emrData.patient;
  const mr = emrData.medical_record;
  if (!p) return;

  const initials = (p.name || '').split(' ').map(s => s[0]).join('').toUpperCase().slice(0, 2) || '?';
  const statusClass = p.is_active ? 'confirmed' : 'cancelled';
  const statusText = p.is_active ? 'Active' : 'Disabled';
  const gender = mr?.gender || '—';
  const bloodType = mr?.blood_type || '—';
  const emergencyName = mr?.emergency_contact_name || '—';
  const emergencyPhone = mr?.emergency_contact_phone || '—';

  document.getElementById('emr-header-content').innerHTML = `
    <div class="emr-header-layout">
      <div class="emr-header-main">
        <div class="emr-header-top">
          <div class="emr-avatar" aria-hidden="true">${escapeHTML(initials)}</div>
          <div class="emr-header-info">
            <h1 class="emr-header-name">
              ${escapeHTML(p.name)}
              <span class="emr-header-id">#${escapeHTML(String(p.id))}</span>
              <span class="status status-${statusClass}" style="font-size:0.7rem;padding:2px 10px;border-radius:var(--r-full)">${statusText}</span>
            </h1>
            <div class="emr-header-meta">
              <span class="emr-meta-item"><i class="fas fa-venus-mars"></i><span class="meta-label">Gender:</span><span class="meta-value">${escapeHTML(gender)}</span></span>
              <span class="emr-meta-item"><i class="fas fa-cake-candles"></i><span class="meta-label">Age:</span><span class="meta-value">${emrData.age !== null ? emrData.age : '—'}</span></span>
              <span class="emr-meta-item"><i class="fas fa-droplet"></i><span class="meta-label">Blood Type:</span><span class="meta-value">${escapeHTML(bloodType)}</span></span>
              <span class="emr-meta-item"><i class="fas fa-calendar-check"></i><span class="meta-label">Registered:</span><span class="meta-value">${formatDate(p.created_at)}</span></span>
              <span class="emr-meta-item"><i class="fas fa-envelope"></i><span class="meta-label">Email:</span><span class="meta-value">${escapeHTML(p.email || '—')}</span></span>
              <span class="emr-meta-item"><i class="fas fa-phone"></i><span class="meta-label">Phone:</span><span class="meta-value">${escapeHTML(p.phone || '—')}</span></span>
              <span class="emr-meta-item"><i class="fas fa-user-shield"></i><span class="meta-label">Emergency:</span><span class="meta-value">${escapeHTML(emergencyName)}</span></span>
              <span class="emr-meta-item"><i class="fas fa-phone-alt"></i><span class="meta-label">Emergency Phone:</span><span class="meta-value">${escapeHTML(emergencyPhone)}</span></span>
            </div>
          </div>
        </div>
        <div class="emr-header-actions" role="toolbar" aria-label="Patient actions">
          <button class="btn btn-outline btn-sm" onclick="openEditModal()" title="Edit patient details"><i class="fas fa-pen"></i> Edit Patient</button>
          <button class="btn ${p.is_active ? 'btn-outline' : 'btn-primary'} btn-sm" onclick="toggleEmrPatientStatus(${p.id}, '${escapeHTML(p.name)}')" title="${p.is_active ? 'Disable' : 'Activate'} account"><i class="fas fa-${p.is_active ? 'ban' : 'check'}"></i> ${p.is_active ? 'Disable' : 'Activate'}</button>
          <button class="btn btn-outline btn-sm" onclick="openResetPasswordModal()" title="Reset Password"><i class="fas fa-lock"></i> Reset Password</button>
          <button class="btn btn-outline btn-sm" onclick="printEmrPage()" title="Open printable EMR"><i class="fas fa-print"></i> Print EMR</button>
          <button class="btn btn-outline btn-sm" onclick="refreshEmr()" title="Refresh data"><i class="fas fa-rotate"></i> Refresh</button>
          <button class="btn btn-danger btn-sm" onclick="openDeleteModal()" title="Delete patient"><i class="fas fa-trash-can"></i> Delete</button>
        </div>
      </div>
      <div class="emr-quick-actions-sidebar">
        <div class="emr-qa-card">
          <h4 class="emr-qa-title"><i class="fas fa-bolt"></i> Quick Actions</h4>
          <div class="emr-qa-list">
            <button class="emr-qa-btn" onclick="openBookAppointmentModal()"><i class="fas fa-calendar-plus"></i> New Appointment</button>
            <button class="emr-qa-btn" onclick="openAdminCorrectionModal()"><i class="fas fa-shield-halved"></i> Correct Administrative Data</button>
            <button class="emr-qa-btn" onclick="switchTab('appointments')"><i class="fas fa-calendar-days"></i> View Appointments</button>
            <button class="emr-qa-btn" onclick="switchTab('prescriptions')"><i class="fas fa-prescription"></i> View Prescriptions</button>
            <button class="emr-qa-btn" onclick="switchTab('visit-notes')"><i class="fas fa-file-lines"></i> View Visit Notes</button>
            <button class="emr-qa-btn" onclick="switchTab('timeline')"><i class="fas fa-timeline"></i> View Timeline</button>
            <button class="emr-qa-btn" onclick="refreshEmr()"><i class="fas fa-rotate"></i> Refresh Data</button>
            <button class="emr-qa-btn" onclick="printEmrPage()"><i class="fas fa-print"></i> Print EMR</button>
          </div>
        </div>
      </div>
    </div>`;
}

/* ═══════════════════════════════════════════════════════════
   STATS
   ═══════════════════════════════════════════════════════════ */

function renderStats() {
  const s = emrData.stats;
  if (!s) return;
  document.getElementById('emr-stats').innerHTML = `
    <div class="emr-stat-card"><div class="emr-stat-icon"><i class="fas fa-calendar-days"></i></div><div class="emr-stat-value">${s.total_appointments}</div><div class="emr-stat-label">Appointments</div></div>
    <div class="emr-stat-card"><div class="emr-stat-icon" style="color:var(--warning)"><i class="fas fa-hourglass-half"></i></div><div class="emr-stat-value">${s.upcoming}</div><div class="emr-stat-label">Upcoming</div></div>
    <div class="emr-stat-card"><div class="emr-stat-icon" style="color:var(--success)"><i class="fas fa-circle-check"></i></div><div class="emr-stat-value">${s.completed}</div><div class="emr-stat-label">Completed</div></div>
    <div class="emr-stat-card"><div class="emr-stat-icon" style="color:var(--danger)"><i class="fas fa-ban"></i></div><div class="emr-stat-value">${s.cancelled}</div><div class="emr-stat-label">Cancelled</div></div>
    <div class="emr-stat-card"><div class="emr-stat-icon"><i class="fas fa-prescription"></i></div><div class="emr-stat-value">${s.total_prescriptions}</div><div class="emr-stat-label">Prescriptions</div></div>
    <div class="emr-stat-card"><div class="emr-stat-icon"><i class="fas fa-notes-medical"></i></div><div class="emr-stat-value">${s.total_medical_records}</div><div class="emr-stat-label">Medical Records</div></div>
    <div class="emr-stat-card"><div class="emr-stat-icon"><i class="fas fa-file-lines"></i></div><div class="emr-stat-value">${s.total_visit_notes}</div><div class="emr-stat-label">Visit Notes</div></div>
    <div class="emr-stat-card"><div class="emr-stat-icon" style="color:var(--warning)"><i class="fas fa-star"></i></div><div class="emr-stat-value">${s.average_rating}</div><div class="emr-stat-label">Avg Rating</div><div class="emr-stat-sub">${s.total_ratings} reviews</div></div>`;
}

/* ═══════════════════════════════════════════════════════════
   OVERVIEW TAB
   ═══════════════════════════════════════════════════════════ */

function renderOverview() {
  const p = emrData.patient;
  const mr = emrData.medical_record;
  const upcoming = emrData.upcoming_appointment;
  const latestAppt = emrData.latest_appointment;
  const latestRx = emrData.latest_prescription;
  const latestVisit = emrData.latest_visit_note;
  const doctors = emrData.doctors_visited || [];
  const recentNotif = emrData.notifications?.[0] || null;

  const noData = (icon, msg) => `<div class="emr-empty-state" style="padding:var(--s6)"><i class="fas ${icon}" style="font-size:1.5rem"></i><p>${msg}</p></div>`;

  const doctorHtml = doctors.length > 0
    ? doctors.map(d => `<span style="display:inline-block;background:var(--bg-surface);border:1px solid var(--border-light);padding:2px 10px;border-radius:var(--r-full);font-size:0.78rem;margin:2px">${escapeHTML(d.name)} <span style="color:var(--text-muted)">(${escapeHTML(d.department)})</span></span>`).join('')
    : '<span style="color:var(--text-muted);font-size:0.83rem">No doctors visited yet.</span>';

  const latestApptHtml = latestAppt
    ? `<div class="emr-info-row"><span class="emr-info-label">Doctor</span><span class="emr-info-value">${escapeHTML(latestAppt.doctor)}</span></div>
       <div class="emr-info-row"><span class="emr-info-label">Department</span><span class="emr-info-value">${escapeHTML(latestAppt.department)}</span></div>
       <div class="emr-info-row"><span class="emr-info-label">Date</span><span class="emr-info-value">${escapeHTML(latestAppt.date)} ${escapeHTML(formatApptTime(latestAppt))}</span></div>
       <div class="emr-info-row"><span class="emr-info-label">Status</span><span class="emr-info-value"><span class="status status-${(latestAppt.status||'Pending').toLowerCase()}">${escapeHTML(latestAppt.status)}</span></span></div>`
    : noData('fa-calendar-xmark', 'No appointments yet.');

  const latestRxHtml = latestRx
    ? `<div class="emr-info-row"><span class="emr-info-label">Doctor</span><span class="emr-info-value">${escapeHTML(latestRx.doctor_name)}</span></div>
       <div class="emr-info-row"><span class="emr-info-label">Date</span><span class="emr-info-value">${formatDate(latestRx.created_at)}</span></div>
       <div class="emr-info-row"><span class="emr-info-label">Status</span><span class="emr-info-value"><span class="status status-${(latestRx.status||'Active').toLowerCase()}">${escapeHTML(latestRx.status)}</span></span></div>
       <div class="emr-info-row"><span class="emr-info-label">Medications</span><span class="emr-info-value">${latestRx.item_count || 0} items</span></div>`
    : noData('fa-prescription-bottle', 'No prescriptions yet.');

  const latestVisitHtml = latestVisit
    ? `<div class="emr-info-row"><span class="emr-info-label">Doctor</span><span class="emr-info-value">${escapeHTML(latestVisit.doctor_name)}</span></div>
       <div class="emr-info-row"><span class="emr-info-label">Department</span><span class="emr-info-value">${escapeHTML(latestVisit.department)}</span></div>
       <div class="emr-info-row"><span class="emr-info-label">Date</span><span class="emr-info-value">${formatDate(latestVisit.appt_date)}</span></div>
       <div class="emr-info-row"><span class="emr-info-label">Diagnosis</span><span class="emr-info-value">${escapeHTML(latestVisit.diagnosis ? (latestVisit.diagnosis.length > 60 ? latestVisit.diagnosis.substring(0,60)+'…' : latestVisit.diagnosis) : '—')}</span></div>`
    : noData('fa-file-pen', 'No visit notes yet.');

  const notifHtml = recentNotif
    ? `<div class="emr-info-row"><span class="emr-info-label">Title</span><span class="emr-info-value">${escapeHTML(recentNotif.title)}</span></div>
       <div class="emr-info-row"><span class="emr-info-label">Date</span><span class="emr-info-value">${formatDate(recentNotif.created_at)}</span></div>
       <div class="emr-info-row"><span class="emr-info-label">Status</span><span class="emr-info-value">${recentNotif.is_read ? 'Read' : 'Unread'}</span></div>`
    : noData('fa-bell', 'No notifications yet.');

  const upcomingHtml = upcoming
    ? `<div class="emr-upcoming-card">
        <div class="upcoming-icon"><i class="fas fa-calendar-check"></i></div>
        <div class="upcoming-info">
          <div class="upcoming-label">Upcoming Appointment</div>
          <div class="upcoming-title">${escapeHTML(upcoming.doctor)} — ${escapeHTML(upcoming.department)}</div>
          <div class="upcoming-meta">
            <i class="fas fa-calendar-day"></i> ${escapeHTML(upcoming.date)}
            <span style="margin:0 var(--s1)">|</span>
            <i class="fas fa-clock"></i> ${escapeHTML(formatApptTime(upcoming))}
            <span style="margin:0 var(--s1)">|</span>
            <span class="status status-${(upcoming.status||'Pending').toLowerCase()}">${escapeHTML(upcoming.status)}</span>
          </div>
        </div>
      </div>`
    : '<div class="emr-empty-state" style="padding:var(--s6)"><i class="fas fa-calendar-plus" style="font-size:1.5rem"></i><p>No upcoming appointments.</p></div>';

  document.getElementById('emr-overview').innerHTML = `
    <div class="emr-overview-grid">
      <div class="emr-info-card"><div class="emr-info-card-title"><i class="fas fa-user"></i> Basic Information</div>${basicInfoHtml()}</div>
      <div class="emr-info-card"><div class="emr-info-card-title"><i class="fas fa-user-doctor"></i> Assigned Doctors</div><div style="display:flex;flex-wrap:wrap;gap:var(--s1)">${doctorHtml}</div></div>
      <div class="emr-info-card"><div class="emr-info-card-title"><i class="fas fa-calendar-days"></i> Latest Appointment</div>${latestApptHtml}</div>
      <div class="emr-info-card"><div class="emr-info-card-title"><i class="fas fa-prescription"></i> Latest Prescription</div>${latestRxHtml}</div>
      <div class="emr-info-card"><div class="emr-info-card-title"><i class="fas fa-file-pen"></i> Latest Visit Note</div>${latestVisitHtml}</div>
      <div class="emr-info-card"><div class="emr-info-card-title"><i class="fas fa-bell"></i> Recent Notification</div>${notifHtml}</div>
    </div>
    <div style="margin-top:var(--s6)">
      <h3 style="font-size:1rem;margin-bottom:var(--s4);color:var(--text-primary)"><i class="fas fa-calendar-alt"></i> Upcoming Appointment</h3>
      ${upcomingHtml}
    </div>
    <div style="margin-top:var(--s4)">
      <h3 style="font-size:1rem;margin-bottom:var(--s3);color:var(--text-primary)"><i class="fas fa-bolt"></i> Quick Actions</h3>
      <div class="emr-quick-actions">
        <button class="btn btn-primary btn-sm" onclick="openBookAppointmentModal()"><i class="fas fa-calendar-plus"></i> Book Appointment</button>
        <button class="btn btn-outline btn-sm" onclick="openAdminCorrectionModal()"><i class="fas fa-shield-halved"></i> Correct Administrative Data</button>
        <button class="btn btn-outline btn-sm" onclick="switchTab('appointments')"><i class="fas fa-calendar-days"></i> View Appointments</button>
        <button class="btn btn-outline btn-sm" onclick="switchTab('prescriptions')"><i class="fas fa-prescription"></i> View Prescriptions</button>
        <button class="btn btn-outline btn-sm" onclick="switchTab('visit-notes')"><i class="fas fa-file-lines"></i> View Visit Notes</button>
        <button class="btn btn-outline btn-sm" onclick="switchTab('timeline')"><i class="fas fa-timeline"></i> View Timeline</button>
      </div>
    </div>`;
}

function basicInfoHtml() {
  const p = emrData.patient;
  const mr = emrData.medical_record;
  return `
    <div class="emr-info-row"><span class="emr-info-label">Full Name</span><span class="emr-info-value">${escapeHTML(p.name)}</span></div>
    <div class="emr-info-row"><span class="emr-info-label">Email</span><span class="emr-info-value" style="word-break:break-all">${escapeHTML(p.email || '—')}</span></div>
    <div class="emr-info-row"><span class="emr-info-label">Phone</span><span class="emr-info-value">${escapeHTML(p.phone || '—')}</span></div>
    <div class="emr-info-row"><span class="emr-info-label">Gender</span><span class="emr-info-value">${escapeHTML(mr?.gender || '—')}</span></div>
    <div class="emr-info-row"><span class="emr-info-label">Age</span><span class="emr-info-value">${emrData.age !== null ? emrData.age : '—'}</span></div>
    <div class="emr-info-row"><span class="emr-info-label">Blood Type</span><span class="emr-info-value">${escapeHTML(mr?.blood_type || '—')}</span></div>
    <div class="emr-info-row"><span class="emr-info-label">Registration</span><span class="emr-info-value">${formatDate(p.created_at)}</span></div>
    <div class="emr-info-row"><span class="emr-info-label">Emergency Contact</span><span class="emr-info-value">${escapeHTML(mr?.emergency_contact_name || '—')}</span></div>
    <div class="emr-info-row"><span class="emr-info-label">Emergency Phone</span><span class="emr-info-value">${escapeHTML(mr?.emergency_contact_phone || '—')}</span></div>`;
}

/* ═══════════════════════════════════════════════════════════
   APPOINTMENTS TAB — with admin actions
   ═══════════════════════════════════════════════════════════ */

function renderAppointments() {
  const appts = emrData.appointments || [];
  const container = document.getElementById('emr-appointments');

  if (appts.length === 0) {
    container.innerHTML = `<div class="emr-empty-state"><i class="fas fa-calendar-xmark"></i><h4>No Appointments Yet</h4><p>This patient has no appointments scheduled.</p><button class="btn btn-primary" onclick="openBookAppointmentModal()"><i class="fas fa-calendar-plus"></i> Book Appointment</button></div>`;
    return;
  }

  const rows = appts.map(a => {
    const status = (a.status || 'Pending').toLowerCase();
    let actionBtns = `<button class="btn btn-outline btn-sm" onclick="openAppointmentModal(${a.id})" title="View details"><i class="fas fa-eye"></i></button>
      <button class="btn btn-outline btn-sm" onclick="printAppointment(${a.id})" title="Print"><i class="fas fa-print"></i></button>`;
    
    if (a.status === 'Pending') {
      actionBtns += `
        <button class="btn btn-outline btn-sm" style="color:var(--success)" onclick="emrApproveAppt(${a.id})" title="Approve"><i class="fas fa-circle-check"></i></button>
        <button class="btn btn-outline btn-sm" style="color:var(--danger)" onclick="emrDeclineAppt(${a.id})" title="Decline"><i class="fas fa-circle-xmark"></i></button>`;
    } else if (a.status === 'Confirmed') {
      actionBtns += `
        <button class="btn btn-outline btn-sm" style="color:var(--danger)" onclick="emrCancelAppt(${a.id})" title="Cancel"><i class="fas fa-ban"></i></button>`;
    }

    return `<tr>
      <td>${escapeHTML(a.date)}</td>
      <td>${escapeHTML(formatApptTime(a))}</td>
      <td>${escapeHTML(a.doctor_user_name || a.doctor)}</td>
      <td>${escapeHTML(a.department)}</td>
      <td><span class="status status-${status}">${escapeHTML(a.status)}</span></td>
      <td>${escapeHTML(a.notes || a.department)}</td>
      <td><div class="emr-quick-actions" style="gap:var(--s1)">${actionBtns}</div></td>
    </tr>`;
  }).join('');

  container.innerHTML = `
    <div class="emr-appts-controls">
      <div class="emr-search-wrapper">
        <i class="fas fa-search search-icon" aria-hidden="true"></i>
        <input type="text" class="emr-appt-search-input" id="emr-appt-search" placeholder="Search appointments..." onkeyup="filterAppointments()" aria-label="Search appointments" />
        <button class="search-clear" id="emr-appt-clear" onclick="clearApptSearch()" aria-label="Clear search">&times;</button>
      </div>
      <select id="emr-appt-status" onchange="filterAppointments()" aria-label="Filter by status">
        <option value="">All Statuses</option>
        <option value="Pending">Pending</option>
        <option value="Confirmed">Confirmed</option>
        <option value="Cancelled">Cancelled</option>
      </select>
      <button class="btn btn-primary btn-sm" onclick="openBookAppointmentModal()"><i class="fas fa-calendar-plus"></i> Book</button>
      <button class="emr-appts-refresh-btn" onclick="refreshAppointments()" title="Refresh appointments"><i class="fas fa-rotate"></i></button>
    </div>
    <div class="emr-table-wrap" role="region" aria-label="Appointments table">
      <table>
        <thead><tr><th>Date</th><th>Time</th><th>Doctor</th><th>Department</th><th>Status</th><th>Type</th><th>Actions</th></tr></thead>
        <tbody id="emr-appt-tbody">${rows}</tbody>
      </table>
    </div>`;
}

let _allApptRows = [];

function filterAppointments() {
  const tbody = document.getElementById('emr-appt-tbody');
  if (!tbody) return;
  if (_allApptRows.length === 0) _allApptRows = Array.from(tbody.querySelectorAll('tr'));

  const input = document.getElementById('emr-appt-search');
  const query = (input?.value || '').toLowerCase();
  const status = document.getElementById('emr-appt-status')?.value || '';

  const clearBtn = document.getElementById('emr-appt-clear');
  if (clearBtn) clearBtn.classList.toggle('visible', query.length > 0);

  const filtered = _allApptRows.filter(row => {
    const cells = Array.from(row.querySelectorAll('td'));
    const text = cells.map(c => c.textContent.toLowerCase()).join(' ');
    const rowStatus = cells[4]?.textContent.trim() || '';
    if (query && !text.includes(query)) return false;
    if (status && rowStatus !== status) return false;
    return true;
  });

  tbody.innerHTML = filtered.length
    ? filtered.map(r => r.outerHTML).join('')
    : '<tr><td colspan="7" class="text-center" style="padding:var(--s8);color:var(--text-muted)">No matching appointments found.</td></tr>';
}

function clearApptSearch() {
  const input = document.getElementById('emr-appt-search');
  if (input) input.value = '';
  const clearBtn = document.getElementById('emr-appt-clear');
  if (clearBtn) clearBtn.classList.remove('visible');
  _allApptRows = [];
  filterAppointments();
}

function refreshAppointments() {
  const btn = document.querySelector('.emr-appts-refresh-btn');
  btn?.classList.add('spinning');
  _allApptRows = [];
  renderAppointments();
  setTimeout(() => btn?.classList.remove('spinning'), 600);
}

/* ── Appointment Actions ── */

async function emrApproveAppt(id) {
  if (!confirm('Approve this appointment?')) return;
  const result = await apiFetch((getBasePath() + "api/appointments/approve.php"), {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id })
  }, 'Approval failed.');
  if (result.data?.success) {
    showToast('Appointment confirmed!', 'success');
    await loadEmrData({ preserveTab: true, preserveScroll: true });
  } else {
    showToast(result.data?.message || 'Approval failed.', 'error');
  }
}

async function emrDeclineAppt(id) {
  if (!confirm('Decline this appointment?')) return;
  const result = await apiFetch((getBasePath() + "api/appointments/decline.php"), {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id, reason: 'Appointment declined by admin.' })
  }, 'Decline failed.');
  if (result.data?.success) {
    showToast('Appointment declined!', 'success');
    await loadEmrData({ preserveTab: true, preserveScroll: true });
  } else {
    showToast(result.data?.message || 'Decline failed.', 'error');
  }
}

async function emrCancelAppt(id) {
  if (!confirm('Cancel this confirmed appointment?')) return;
  const result = await apiFetch((getBasePath() + "api/appointments/decline.php"), {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id, reason: 'Appointment cancelled by admin.' })
  }, 'Cancel failed.');
  if (result.data?.success) {
    showToast('Appointment cancelled!', 'success');
    await loadEmrData({ preserveTab: true, preserveScroll: true });
  } else {
    showToast(result.data?.message || 'Cancel failed.', 'error');
  }
}

/* ═══════════════════════════════════════════════════════════
   MEDICAL RECORDS TAB — with edit button
   ═══════════════════════════════════════════════════════════ */

function renderMedicalRecords() {
  const mr = emrData.medical_record;
  const container = document.getElementById('emr-medical-records');
  if (!mr) {
    container.innerHTML = `<div class="emr-empty-state"><i class="fas fa-notes-medical"></i><h4>No Medical Records Yet</h4><p>This patient has not received a diagnosis yet.</p></div>`;
    return;
  }

  // Last Clinical Update info
  const lastUpdate = emrData.last_clinical_update;
  const lastUpdateHtml = lastUpdate 
    ? `<div style="margin-top:var(--s3);padding:var(--s3);background:rgba(34,211,238,0.05);border:1px solid rgba(34,211,238,0.15);border-radius:var(--r-md);font-size:0.78rem;color:var(--text-secondary)">
        <i class="fas fa-user-doctor"></i> Last Clinical Update: <strong>${escapeHTML(lastUpdate.doctor_name)}</strong> — ${formatDate(lastUpdate.created_at)} ${formatTime(lastUpdate.created_at)}
       </div>`
    : '';

  container.innerHTML = `
    <!-- Administrative Medical Information (Editable by Admin) -->
    <div class="emr-section">
      <div class="flex-between" style="margin-bottom:var(--s4)">
        <h4 style="font-size:1rem;margin:0;color:var(--text-primary)"><i class="fas fa-shield-halved"></i> Administrative Information</h4>
        <button class="btn btn-outline btn-sm" onclick="openAdminCorrectionModal()"><i class="fas fa-pen"></i> Correct Administrative Data</button>
      </div>
      <div class="emr-records-grid">
        <div class="emr-record-card">
          <div class="emr-record-header"><span class="emr-record-doctor"><i class="fas fa-droplet"></i> Blood Type</span></div>
          <p style="font-size:0.83rem;color:var(--text-secondary)">${mr.blood_type ? escapeHTML(mr.blood_type) : 'Not specified'}</p>
        </div>
        <div class="emr-record-card">
          <div class="emr-record-header"><span class="emr-record-doctor"><i class="fas fa-phone"></i> Emergency Contact</span></div>
          <p style="font-size:0.83rem;color:var(--text-secondary)">${mr.emergency_contact_name ? escapeHTML(mr.emergency_contact_name) : 'Not specified'}</p>
        </div>
        <div class="emr-record-card">
          <div class="emr-record-header"><span class="emr-record-doctor"><i class="fas fa-phone-alt"></i> Emergency Phone</span></div>
          <p style="font-size:0.83rem;color:var(--text-secondary)">${mr.emergency_contact_phone ? escapeHTML(mr.emergency_contact_phone) : 'Not specified'}</p>
        </div>
      </div>
    </div>

    <!-- Clinical Information (Read-only for Admin) -->
    <div class="emr-section" style="margin-top:var(--s6)">
      <div class="flex-between" style="margin-bottom:var(--s4)">
        <h4 style="font-size:1rem;margin:0;color:var(--text-primary)">
          <i class="fas fa-stethoscope"></i> Clinical Information
          <span class="badge" style="margin-left:var(--s2);font-size:0.7rem;background:rgba(250,204,21,0.1);color:var(--warning);border:1px solid rgba(250,204,21,0.25)">Managed by Doctors</span>
        </h4>
        <span style="font-size:0.78rem;color:var(--text-muted)">View Only</span>
      </div>
      ${lastUpdateHtml}
      <div class="emr-records-grid">
        <div class="emr-record-card" style="opacity:0.85">
          <div class="emr-record-header"><span class="emr-record-doctor"><i class="fas fa-allergies"></i> Allergies</span></div>
          <p style="font-size:0.83rem;color:var(--text-secondary)">${mr.allergies ? escapeHTML(mr.allergies) : 'None recorded'}</p>
        </div>
        <div class="emr-record-card" style="opacity:0.85">
          <div class="emr-record-header"><span class="emr-record-doctor"><i class="fas fa-heart-pulse"></i> Chronic Diseases</span></div>
          <p style="font-size:0.83rem;color:var(--text-secondary)">${mr.chronic_diseases ? escapeHTML(mr.chronic_diseases) : 'None recorded'}</p>
        </div>
        <div class="emr-record-card" style="opacity:0.85">
          <div class="emr-record-header"><span class="emr-record-doctor"><i class="fas fa-pills"></i> Current Medications</span></div>
          <p style="font-size:0.83rem;color:var(--text-secondary)">${mr.current_medications ? escapeHTML(mr.current_medications) : 'None recorded'}</p>
        </div>
        <div class="emr-record-card" style="opacity:0.85">
          <div class="emr-record-header"><span class="emr-record-doctor"><i class="fas fa-scalpel"></i> Previous Surgeries</span></div>
          <p style="font-size:0.83rem;color:var(--text-secondary)">${mr.previous_surgeries ? escapeHTML(mr.previous_surgeries) : 'None recorded'}</p>
        </div>
        <div class="emr-record-card" style="opacity:0.85">
          <div class="emr-record-header"><span class="emr-record-doctor"><i class="fas fa-family"></i> Family History</span></div>
          <p style="font-size:0.83rem;color:var(--text-secondary)">${mr.family_history ? escapeHTML(mr.family_history) : 'None recorded'}</p>
        </div>
        <div class="emr-record-card" style="opacity:0.85">
          <div class="emr-record-header"><span class="emr-record-doctor"><i class="fas fa-stethoscope"></i> Medical Notes</span></div>
          <p style="font-size:0.83rem;color:var(--text-secondary)">${mr.medical_notes ? escapeHTML(mr.medical_notes) : 'No notes'}</p>
        </div>
      </div>
    </div>

    <!-- Audit History Section -->
    ${renderAuditHistory()}
  `;
}

/* ═══════════════════════════════════════════════════════════
   PRESCRIPTIONS TAB
   ═══════════════════════════════════════════════════════════ */

function renderPrescriptions() {
  const prescriptions = emrData.prescriptions || [];
  const container = document.getElementById('emr-prescriptions');
  if (prescriptions.length === 0) {
    container.innerHTML = `<div class="emr-empty-state"><i class="fas fa-prescription"></i><h4>No Prescriptions Yet</h4><p>This patient has not been prescribed any medications.</p></div>`;
    return;
  }

  const cards = prescriptions.map(rx => {
    const items = (rx.items || []).map(item =>
      `<div class="emr-rx-item"><span class="emr-rx-item-name">${escapeHTML(item.medication_name)}</span><span class="emr-rx-item-detail">${escapeHTML(item.strength)} — ${escapeHTML(item.dosage)} — ${escapeHTML(item.frequency)} — ${escapeHTML(item.duration)}</span></div>`
    ).join('') || '<p style="color:var(--text-muted);font-size:0.78rem">No medication details</p>';

    const statusLower = (rx.status || 'Active').toLowerCase();

    return `<div class="emr-rx-card">
      <div class="emr-rx-header"><div><div class="emr-rx-doctor">${escapeHTML(rx.doctor_name)}</div><div class="emr-rx-id">RX-${String(rx.id).padStart(5,'0')}</div></div><span class="status status-${statusLower}">${escapeHTML(rx.status)}</span></div>
      <div class="emr-rx-meta"><span><i class="fas fa-calendar"></i> ${formatDate(rx.created_at)}</span><span><i class="fas fa-stethoscope"></i> ${escapeHTML(rx.department)}</span><span><i class="fas fa-pills"></i> ${rx.item_count || 0} medication(s)</span></div>
      <div class="emr-rx-items">${items}</div>
      <div class="emr-quick-actions" style="margin-top:var(--s3);padding-top:var(--s3);border-top:1px solid var(--border-light)">
        <button class="btn btn-outline btn-sm" onclick="openPrescriptionModal(${rx.id})" title="View details"><i class="fas fa-eye"></i> View</button>
        <button class="btn btn-outline btn-sm" onclick="printPrescription(${rx.id})" title="Print"><i class="fas fa-print"></i> Print</button>
      </div>
    </div>`;
  }).join('');

  container.innerHTML = `
    <div style="display:flex;justify-content:flex-end;margin-bottom:var(--s4)">
      <span style="font-size:0.78rem;color:var(--text-muted)"><i class="fas fa-eye"></i> View Only</span>
    </div>
    <div class="emr-rx-grid">${cards}</div>
  `;
}

/* ═══════════════════════════════════════════════════════════
   VISIT NOTES TAB
   ═══════════════════════════════════════════════════════════ */

function renderVisitNotes() {
  const notes = emrData.visit_notes || [];
  const container = document.getElementById('emr-visit-notes');
  if (notes.length === 0) {
    container.innerHTML = `<div class="emr-empty-state"><i class="fas fa-file-lines"></i><h4>No Visit Notes Yet</h4><p>This patient has no visit notes recorded.</p></div>`;
    return;
  }

  const timeline = notes.map((note, idx) => {
    let bodyHtml = '';
    if (note.diagnosis) bodyHtml += `<p><strong>Diagnosis:</strong> ${escapeHTML(note.diagnosis)}</p>`;
    if (note.symptoms) bodyHtml += `<p><strong>Symptoms:</strong> ${escapeHTML(note.symptoms)}</p>`;
    if (note.treatment) bodyHtml += `<p><strong>Treatment:</strong> ${escapeHTML(note.treatment)}</p>`;
    if (note.doctor_notes) bodyHtml += `<p><strong>Doctor Notes:</strong> ${escapeHTML(note.doctor_notes)}</p>`;

    const hasDetails = !!bodyHtml;
    const expandable = hasDetails && bodyHtml.length > 200;

    const initialBody = expandable
      ? `<div class="emr-timeline-body">${bodyHtml.substring(0,200)}…</div>
         <button class="emr-record-toggle" onclick="toggleVisitNote(${idx})" aria-expanded="false" aria-controls="visit-note-${idx}"><i class="fas fa-chevron-down"></i> Show More</button>
         <div class="emr-record-expand" id="visit-note-${idx}">${bodyHtml}</div>`
      : `<div class="emr-timeline-body">${bodyHtml}</div>`;

    return `<div class="emr-timeline-card">
      <div class="emr-timeline-header">
        <div><div class="emr-timeline-date"><i class="fas fa-calendar-day"></i> ${formatDate(note.appt_date || note.created_at)}</div><div class="emr-timeline-doctor">${escapeHTML(note.doctor_name)} — ${escapeHTML(note.department)}</div></div>
      </div>
      ${hasDetails ? initialBody : '<p style="color:var(--text-muted);font-size:0.83rem">No detailed notes recorded for this visit.</p>'}
    </div>`;
  }).join('');

  container.innerHTML = `
    <div style="display:flex;justify-content:flex-end;margin-bottom:var(--s4)">
      <span style="font-size:0.78rem;color:var(--text-muted)"><i class="fas fa-eye"></i> View Only</span>
    </div>
    <div class="emr-timeline">${timeline}</div>
  `;
}

function toggleVisitNote(idx) {
  const btn = document.querySelectorAll('.emr-record-toggle')[idx];
  const expand = document.getElementById(`visit-note-${idx}`);
  if (!btn || !expand) return;
  const isOpen = expand.classList.contains('open');
  expand.classList.toggle('open');
  btn.setAttribute('aria-expanded', !isOpen);
  btn.innerHTML = isOpen
    ? '<i class="fas fa-chevron-down"></i> Show More'
    : '<i class="fas fa-chevron-up"></i> Show Less';
}

/* ═══════════════════════════════════════════════════════════
   RATINGS TAB — with moderation (hide/restore)
   ═══════════════════════════════════════════════════════════ */

function renderRatings() {
  const ratings = emrData.ratings || [];
  const container = document.getElementById('emr-ratings');
  if (ratings.length === 0) {
    container.innerHTML = `<div class="emr-empty-state"><i class="fas fa-star"></i><h4>No Ratings Yet</h4><p>This patient has not submitted any ratings.</p></div>`;
    return;
  }
  const rows = ratings.map(r => {
    const starsHtml = Array(5).fill('').map((_, i) => `<span class="${i < r.stars ? '' : 'star-empty'}"><i class="fas fa-star"></i></span>`).join('');
    const hiddenBadge = r.is_hidden ? '<span class="status status-cancelled" style="margin-left:var(--s1);font-size:0.65rem">Hidden</span>' : '';
    const modBtn = r.is_hidden
      ? `<button class="btn btn-outline btn-sm" onclick="emrRestoreRating(${r.id})" style="font-size:0.7rem;padding:2px 8px;color:var(--success)"><i class="fas fa-eye"></i> Restore</button>`
      : `<button class="btn btn-outline btn-sm" onclick="emrHideRating(${r.id})" style="font-size:0.7rem;padding:2px 8px;color:var(--warning)"><i class="fas fa-eye-slash"></i> Hide</button>`;
    return `<tr><td>${escapeHTML(r.doctor_name)}</td><td><div class="emr-rating-stars" aria-label="${r.stars} out of 5 stars">${starsHtml}</div></td><td>${escapeHTML(r.review || '—')}</td><td>${escapeHTML(r.department)}</td><td>${formatDate(r.appt_date)}</td><td>${hiddenBadge} ${modBtn}</td></tr>`;
  }).join('');
  container.innerHTML = `<div class="emr-table-wrap" role="region" aria-label="Patient ratings table"><table><thead><tr><th>Doctor</th><th>Rating</th><th>Review</th><th>Appointment</th><th>Date</th><th>Moderation</th></tr></thead><tbody>${rows}</tbody></table></div>`;
}

async function emrHideRating(id) {
  if (!confirm('Hide this rating from public pages?')) return;
  const result = await apiFetch((getBasePath() + "api/doctors/toggle-rating-visibility.php"), {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id, is_hidden: 1 })
  }, 'Failed to hide rating.');
  if (result.data?.success) {
    showToast('Rating hidden.', 'success');
    await loadEmrData({ preserveTab: true, preserveScroll: true });
  } else {
    showToast(result.data?.message || 'Failed.', 'error');
  }
}

async function emrRestoreRating(id) {
  if (!confirm('Restore this rating to public view?')) return;
  const result = await apiFetch((getBasePath() + "api/doctors/toggle-rating-visibility.php"), {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id, is_hidden: 0 })
  }, 'Failed to restore rating.');
  if (result.data?.success) {
    showToast('Rating restored.', 'success');
    await loadEmrData({ preserveTab: true, preserveScroll: true });
  } else {
    showToast(result.data?.message || 'Failed.', 'error');
  }
}

/* ═══════════════════════════════════════════════════════════
   NOTIFICATIONS TAB — with actions
   ═══════════════════════════════════════════════════════════ */

function renderNotifications() {
  renderNotifList(emrData.notifications || []);
}

function renderNotifList(notifs) {
  const container = document.getElementById('emr-notifications');
  const filter = currentNotifFilter;
  let filtered = notifs;
  if (filter === 'unread') filtered = notifs.filter(n => !n.is_read);
  else if (filter === 'archived') filtered = notifs.filter(n => n.is_read);

  const filterBar = `
    <div class="emr-notif-filters" role="tablist" aria-label="Notification filters">
      <button class="${filter === 'all' ? 'active' : ''}" onclick="setNotifFilter('all')" role="tab" aria-selected="${filter === 'all'}">All (${notifs.length})</button>
      <button class="${filter === 'unread' ? 'active' : ''}" onclick="setNotifFilter('unread')" role="tab" aria-selected="${filter === 'unread'}">Unread (${notifs.filter(n=>!n.is_read).length})</button>
      <button class="${filter === 'archived' ? 'active' : ''}" onclick="setNotifFilter('archived')" role="tab" aria-selected="${filter === 'archived'}">Read (${notifs.filter(n=>n.is_read).length})</button>
    </div>`;

  if (filtered.length === 0) {
    container.innerHTML = filterBar + `<div class="emr-empty-state"><i class="fas fa-bell-slash"></i><h4>No ${filter !== 'all' ? filter : ''} Notifications</h4><p>${filter === 'unread' ? 'All caught up!' : 'No notifications to show.'}</p></div>`;
    return;
  }

  const iconMap = {
    appointment_confirmed: 'fa-calendar-check', appointment_declined: 'fa-calendar-xmark',
    appointment_request: 'fa-calendar-plus', appointment_cancelled: 'fa-ban',
    appointment_time_changed: 'fa-clock-rotate', rating_received: 'fa-star',
    review_received: 'fa-star', support_reply: 'fa-comment',
    password_changed: 'fa-lock', profile_updated: 'fa-user-pen',
    account_status_changed: 'fa-toggle-on', new_patient_registered: 'fa-user-plus',
    new_doctor_registered: 'fa-user-doctor', new_support_ticket: 'fa-ticket',
    medical_record_updated: 'fa-notes-medical', visit_note_added: 'fa-file-pen',
    prescription_issued: 'fa-prescription', prescription_updated: 'fa-prescription',
    prescription_completed: 'fa-check-circle', prescription_cancelled: 'fa-ban',
  };

  const items = filtered.map(n => {
    const icon = iconMap[n.type] || 'fa-bell';
    return `<div class="emr-notif-item ${n.is_read ? 'read' : 'unread'}">
      <div class="emr-notif-icon"><i class="fas ${icon}" aria-hidden="true"></i></div>
      <div class="emr-notif-content">
        <div class="emr-notif-title">${escapeHTML(n.title)}</div>
        <div class="emr-notif-message">${escapeHTML(n.message)}</div>
        <div class="emr-notif-time">${formatDate(n.created_at)}</div>
        <div class="emr-notif-actions" style="margin-top:var(--s1);display:flex;gap:var(--s1)">
          ${!n.is_read ? `<button class="btn btn-outline btn-sm" onclick="emrMarkNotifRead(${n.id})" style="font-size:0.7rem;padding:2px 8px"><i class="fas fa-check"></i> Mark Read</button>` : ''}
          <button class="btn btn-outline btn-sm" onclick="emrDeleteNotif(${n.id})" style="font-size:0.7rem;padding:2px 8px;color:var(--danger)"><i class="fas fa-trash-can"></i> Delete</button>
        </div>
      </div>
    </div>`;
  }).join('');

  container.innerHTML = filterBar + `<div class="emr-notif-list">${items}</div>`;
}

function setNotifFilter(filter) {
  currentNotifFilter = filter;
  renderNotifications();
}

async function emrMarkNotifRead(id) {
  const result = await apiFetch((getBasePath() + "api/notifications/notifications.php"), {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'mark_read', id, patient_id: patientId })
  }, 'Failed to mark notification as read.');
  if (result.data?.success) {
    await loadEmrData({ preserveTab: true, preserveScroll: true });
  }
}

async function emrDeleteNotif(id) {
  if (!confirm('Delete this notification?')) return;
  const result = await apiFetch((getBasePath() + "api/notifications/notifications.php"), {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'delete', id, patient_id: patientId })
  }, 'Failed to delete notification.');
  if (result.data?.success) {
    showToast('Notification deleted.', 'success');
    await loadEmrData({ preserveTab: true, preserveScroll: true });
  } else {
    showToast(result.data?.message || 'Failed to delete notification.', 'error');
  }
}

/* ═══════════════════════════════════════════════════════════
   TIMELINE TAB — Chronological patient activity
   ═══════════════════════════════════════════════════════════ */

function renderTimeline() {
  const appointments = emrData.appointments || [];
  const visitNotes = emrData.visit_notes || [];
  const prescriptions = emrData.prescriptions || [];
  const container = document.getElementById('emr-timeline');

  const entries = [];
  appointments.forEach(a => entries.push({ date: a.date + ' ' + (a.time || '00:00'), type: 'appointment', title: `Appointment: ${escapeHTML(a.department)}`, text: `Status: ${a.status} · ${escapeHTML(a.doctor_user_name || a.doctor)}`, detail: `${escapeHTML(a.date)} ${escapeHTML(formatApptTime(a))}` }));
  visitNotes.forEach(v => entries.push({ date: v.appt_date + ' ' + (v.appt_time || '00:00'), type: 'visit-note', title: `Visit Note: ${escapeHTML(v.doctor_name)}`, text: v.diagnosis ? `Diagnosis: ${escapeHTML(v.diagnosis)}` : 'Visit documented', detail: escapeHTML(v.department) }));
  prescriptions.forEach(r => entries.push({ date: r.created_at, type: 'prescription', title: `Prescription: ${escapeHTML(r.doctor_name)}`, text: `${r.item_count || 0} med(s) · ${r.status}`, detail: `RX-${String(r.id).padStart(5,'0')}` }));

  entries.sort((a, b) => b.date.localeCompare(a.date));

  if (entries.length === 0) {
    container.innerHTML = `<div class="emr-empty-state"><i class="fas fa-clock-rotate"></i><h4>No Timeline Entries</h4><p>No activity recorded for this patient yet.</p></div>`;
    return;
  }

  const items = entries.map(e => `
    <div class="emr-timeline-item" style="position:relative;padding-left:var(--s8);margin-bottom:var(--s5)">
      <div style="position:absolute;left:calc(-1 * var(--s8) + 8px);top:4px;width:16px;height:16px;border-radius:50%;background:${e.type === 'appointment' ? 'var(--primary)' : e.type === 'visit-note' ? 'var(--success)' : 'var(--warning)'};border:3px solid var(--bg-surface);z-index:1;display:flex;align-items:center;justify-content:center">
        <i class="fas ${e.type === 'appointment' ? 'fa-calendar-check' : e.type === 'visit-note' ? 'fa-file-pen' : 'fa-prescription'}" style="font-size:0.45rem;color:#fff"></i>
      </div>
      <div style="font-size:0.75rem;color:var(--text-muted);margin-bottom:var(--s1)">${e.detail}</div>
      <div style="background:var(--bg-surface);border:1px solid var(--border-light);border-radius:var(--r-md);padding:var(--s3) var(--s4)">
        <div style="font-weight:600;font-size:0.85rem;color:var(--text-primary);margin-bottom:2px">${e.title}</div>
        <div style="font-size:0.8rem;color:var(--text-secondary)">${e.text}</div>
      </div>
    </div>`).join('');

  container.innerHTML = `
    <div style="display:flex;justify-content:flex-end;margin-bottom:var(--s4)">
      <span style="font-size:0.78rem;color:var(--text-muted)"><i class="fas fa-eye"></i> View Only</span>
    </div>
    <div style="position:relative;padding-left:var(--s8)"><div style="content:'';position:absolute;left:15px;top:0;bottom:0;width:2px;background:var(--border-light)"></div>${items}</div>
  `;
}

/* ═══════════════════════════════════════════════════════════
   PRINT / EXPORT
   ═══════════════════════════════════════════════════════════ */

function printEmrPage() {
  const w = window.open(`${getBasePath()}api/medical/print-patient-emr.php?patient_id=${patientId}`, '_blank');
  if (!w || w.closed || typeof w.closed === 'undefined') showToast('Popup blocked. Please allow popups for this site.', 'error');
}

function printPrescription(id) {
  const w = window.open(`${getBasePath()}api/prescriptions/print.php?id=${id}`, '_blank');
  if (!w || w.closed || typeof w.closed === 'undefined') showToast('Popup blocked. Please allow popups for this site.', 'error');
}

function printAppointment(id) {
  const w = window.open(`${getBasePath()}api/appointments/print.php?id=${id}`, '_blank');
  if (!w || w.closed || typeof w.closed === 'undefined') showToast('Popup blocked. Please allow popups for this site.', 'error');
}

/* ═══════════════════════════════════════════════════════════
   TOGGLE PATIENT STATUS
   ═══════════════════════════════════════════════════════════ */

async function toggleEmrPatientStatus(id, name) {
  const isActive = document.querySelector('.emr-header-name .status.confirmed');
  const action = isActive ? 'disable' : 'enable';
  if (!confirm(`${action === 'disable' ? 'Disable' : 'Enable'} patient "${name}"?`)) return;

  const result = await apiFetch((getBasePath() + "api/patients/toggle-status.php"), {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id }),
  }, 'Status update failed.');

  if (result.data?.success) {
    showToast(`Patient "${name}" updated.`, 'success');
    await loadEmrData({ preserveTab: true, preserveScroll: true });
  } else {
    showToast(result.data?.message || 'Status update failed.', 'error');
  }
}

/* ═══════════════════════════════════════════════════════════
   STANDARDIZED MODAL SYSTEM
   ═══════════════════════════════════════════════════════════ */

function initModalSystem() {
  document.addEventListener('click', (e) => {
    const overlay = e.target.closest('.modal-overlay.open');
    if (!overlay) return;
    if (e.target === overlay) closeModal(overlay.id);
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      const openModal = document.querySelector('.modal-overlay.open');
      if (openModal) closeModal(openModal.id);
    }
  });

  document.querySelectorAll('.modal-close-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const overlay = btn.closest('.modal-overlay');
      if (overlay) closeModal(overlay.id);
    });
  });
}

function openModal(id) {
  const modal = document.getElementById(id);
  if (!modal) return;
  modal.classList.add('open');
  document.body.classList.add('modal-open');
  const firstInput = modal.querySelector('input, select, button');
  setTimeout(() => firstInput?.focus(), 100);
}

function closeModal(id) {
  const modal = document.getElementById(id);
  if (!modal) return;
  modal.classList.remove('open');
  document.body.classList.remove('modal-open');
}

/* ═══════════════════════════════════════════════════════════
   EDIT PATIENT MODAL
   ═══════════════════════════════════════════════════════════ */

function openEditModal() {
  if (!emrData) return;
  const p = emrData.patient;
  const mr = emrData.medical_record;

  document.getElementById('edit-patient-id').value = p.id;
  document.getElementById('edit-name').value = p.name || '';
  document.getElementById('edit-email').value = p.email || '';
  document.getElementById('edit-phone').value = p.phone || '';
  document.getElementById('edit-gender').value = mr?.gender || '';
  document.getElementById('edit-dob').value = mr?.date_of_birth || '';
  document.getElementById('edit-status').value = p.is_active ? '1' : '0';
  document.getElementById('edit-password').value = '';

  openModal('modal-edit');
}

function initEditForm() {
  const form = document.getElementById('form-edit');
  if (!form) return;

  form.addEventListener('submit', async (e) => {
    e.preventDefault();

    const id = parseInt(document.getElementById('edit-patient-id')?.value || '0');
    const name = document.getElementById('edit-name')?.value.trim();
    const email = document.getElementById('edit-email')?.value.trim();
    const phone = document.getElementById('edit-phone')?.value.trim();
    const gender = document.getElementById('edit-gender')?.value;
    const dob = document.getElementById('edit-dob')?.value;
    const isActive = document.getElementById('edit-status')?.value === '1';
    const password = document.getElementById('edit-password')?.value;

    if (!name || !email) { showToast('Name and email are required.', 'error'); return; }
    if (password && password.length < 6) { showToast('Password must be at least 6 characters.', 'error'); return; }

    const submitBtn = form.querySelector('[type="submit"]');
    const origText = submitBtn?.textContent || 'Save Changes';
    setLoading(submitBtn, true, 'Saving...');

    const result = await apiFetch((getBasePath() + "api/patients/update.php"), {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        patient_id: id, name, email, phone,
        gender: gender || null, date_of_birth: dob || null,
        is_active: isActive, password: password || undefined,
      }),
    }, 'Failed to update patient.');

    setLoading(submitBtn, false, origText);

    if (result.data?.success) {
      showToast('Patient updated successfully!', 'success');
      closeModal('modal-edit');
      await loadEmrData({ preserveTab: true, preserveScroll: true });
    } else {
      showToast(result.data?.message || 'Failed to update patient.', 'error');
    }
  });
}

/* ═══════════════════════════════════════════════════════════
   PRESCRIPTION VIEW MODAL
   ═══════════════════════════════════════════════════════════ */

function initPrescriptionModal() {
  document.getElementById('modal-rx-print-btn')?.addEventListener('click', () => {
    const rxId = document.getElementById('modal-rx-print-btn').getAttribute('data-rx-id');
    if (rxId) printPrescription(parseInt(rxId));
  });
}

function openPrescriptionModal(rxId) {
  const rx = (emrData.prescriptions || []).find(r => r.id === rxId);
  if (!rx) { showToast('Prescription not found.', 'error'); return; }

  const items = (rx.items || []).map(item =>
    `<div class="emr-rx-item"><span class="emr-rx-item-name">${escapeHTML(item.medication_name)}</span><span class="emr-rx-item-detail">${escapeHTML(item.strength)} — ${escapeHTML(item.dosage)} — ${escapeHTML(item.frequency)} — ${escapeHTML(item.duration)}${item.instructions ? ' — ' + escapeHTML(item.instructions) : ''}</span></div>`
  ).join('') || '<p style="color:var(--text-muted)">No medication details</p>';

  document.getElementById('modal-rx-content').innerHTML = `
    <div class="modal-info-card">
      <div class="info-row"><span class="info-label">Prescription ID</span><span class="info-value">RX-${String(rx.id).padStart(5,'0')}</span></div>
      <div class="info-row"><span class="info-label">Doctor</span><span class="info-value">${escapeHTML(rx.doctor_name)}</span></div>
      <div class="info-row"><span class="info-label">Patient</span><span class="info-value">${escapeHTML(emrData.patient.name)}</span></div>
      <div class="info-row"><span class="info-label">Status</span><span class="info-value"><span class="status status-${(rx.status||'Active').toLowerCase()}">${escapeHTML(rx.status)}</span></span></div>
      <div class="info-row"><span class="info-label">Date</span><span class="info-value">${formatDate(rx.created_at)}</span></div>
      <div class="info-row"><span class="info-label">Department</span><span class="info-value">${escapeHTML(rx.department)}</span></div>
      ${rx.notes ? `<div class="info-row"><span class="info-label">Notes</span><span class="info-value">${escapeHTML(rx.notes)}</span></div>` : ''}
    </div>
    <div class="modal-section-title">Medications</div>
    <div class="modal-medications">${items}</div>`;

  document.getElementById('modal-rx-print-btn').setAttribute('data-rx-id', rxId);
  openModal('modal-prescription');
}

/* ═══════════════════════════════════════════════════════════
   APPOINTMENT VIEW MODAL
   ═══════════════════════════════════════════════════════════ */

function initAppointmentModal() {
  document.getElementById('modal-appt-print-btn')?.addEventListener('click', () => {
    const apptId = document.getElementById('modal-appt-print-btn').getAttribute('data-appt-id');
    if (apptId) printAppointment(parseInt(apptId));
  });
}

function openAppointmentModal(apptId) {
  const appt = (emrData.appointments || []).find(a => a.id === apptId);
  if (!appt) { showToast('Appointment not found.', 'error'); return; }

  const visitNote = (emrData.visit_notes || []).find(vn => vn.appointment_id === apptId);
  const rx = (emrData.prescriptions || []).find(r => r.appointment_id === apptId);

  let relatedHtml = '';
  if (visitNote) {
    relatedHtml += `<div class="emr-info-card" style="margin-top:var(--s3)">
      <div class="emr-info-card-title"><i class="fas fa-file-pen"></i> Visit Note</div>
      ${visitNote.diagnosis ? `<div class="emr-info-row"><span class="emr-info-label">Diagnosis</span><span class="emr-info-value">${escapeHTML(visitNote.diagnosis)}</span></div>` : ''}
      ${visitNote.symptoms ? `<div class="emr-info-row"><span class="emr-info-label">Symptoms</span><span class="emr-info-value">${escapeHTML(visitNote.symptoms)}</span></div>` : ''}
      ${visitNote.treatment ? `<div class="emr-info-row"><span class="emr-info-label">Treatment</span><span class="emr-info-value">${escapeHTML(visitNote.treatment)}</span></div>` : ''}
    </div>`;
  }
  if (rx) {
    relatedHtml += `<div class="emr-info-card" style="margin-top:var(--s3)">
      <div class="emr-info-card-title"><i class="fas fa-prescription"></i> Prescription</div>
      <div class="emr-info-row"><span class="emr-info-label">RX ID</span><span class="emr-info-value">RX-${String(rx.id).padStart(5,'0')}</span></div>
      <div class="emr-info-row"><span class="emr-info-label">Status</span><span class="emr-info-value"><span class="status status-${(rx.status||'Active').toLowerCase()}">${escapeHTML(rx.status)}</span></span></div>
      <div class="emr-info-row"><span class="emr-info-label">Medications</span><span class="emr-info-value">${rx.item_count || 0} items</span></div>
    </div>`;
  }

  document.getElementById('modal-appt-content').innerHTML = `
    <div class="emr-info-card" style="margin-bottom:0">
      <div class="emr-info-row"><span class="emr-info-label">Appointment ID</span><span class="emr-info-value">#${appt.id}</span></div>
      <div class="emr-info-row"><span class="emr-info-label">Date</span><span class="emr-info-value">${escapeHTML(appt.date)}</span></div>
      <div class="emr-info-row"><span class="emr-info-label">Time</span><span class="emr-info-value">${escapeHTML(formatApptTime(appt))}</span></div>
      <div class="emr-info-row"><span class="emr-info-label">Doctor</span><span class="emr-info-value">${escapeHTML(appt.doctor_user_name || appt.doctor)}</span></div>
      <div class="emr-info-row"><span class="emr-info-label">Department</span><span class="emr-info-value">${escapeHTML(appt.department)}</span></div>
      <div class="emr-info-row"><span class="emr-info-label">Status</span><span class="emr-info-value"><span class="status status-${(appt.status||'Pending').toLowerCase()}">${escapeHTML(appt.status)}</span></span></div>
      ${appt.notes ? `<div class="emr-info-row"><span class="emr-info-label">Notes</span><span class="emr-info-value">${escapeHTML(appt.notes)}</span></div>` : ''}
    </div>
    ${relatedHtml}`;

  document.getElementById('modal-appt-print-btn').setAttribute('data-appt-id', apptId);
  openModal('modal-appointment');
}

/* ═══════════════════════════════════════════════════════════
   BOOK APPOINTMENT MODAL — Reuses same slot picker as Patient Dashboard
   Uses ${getBasePath()}api/appointments/get-available-slots.php and ${getBasePath()}api/appointments/book.php
   No duplicate booking logic.
   ═══════════════════════════════════════════════════════════ */

function openBookAppointmentModal() {
  document.getElementById('emr-appt-patient-id').value = patientId;
  const dateInput = document.getElementById('emr-appt-date');
  if (dateInput) dateInput.min = new Date().toISOString().split('T')[0];
  loadEmrDoctorDropdown();
  // Reset slot picker
  const slotContainer = document.getElementById('emr-slot-picker-container');
  if (slotContainer) {
    slotContainer.innerHTML = '<p class="slot-placeholder" style="color:var(--text-muted);font-size:0.85rem">Select a doctor and date to see available slots.</p>';
  }
  const emrTimeInput = document.getElementById('emr-appt-time');
  if (emrTimeInput) emrTimeInput.value = '';
  const emrSummaryWrapper = document.getElementById('emr-slot-summary-wrapper');
  if (emrSummaryWrapper) emrSummaryWrapper.classList.remove('visible');
  openModal('emr-book-appointment-modal');
}

async function loadEmrDoctorDropdown() {
  const doctorSelect = document.getElementById('emr-appt-doctor');
  const deptSelect = document.getElementById('emr-appt-department');
  if (!doctorSelect) return;

  // Load departments dynamically
  if (typeof loadDepartmentsDropdown === 'function') {
    loadDepartmentsDropdown('emr-appt-department', true);
  }

  try {
    const res = await fetch((getBasePath() + "api/doctors/get.php"));
    const result = await res.json();
    emrDoctorsList = result.doctors || result || [];
  } catch {
    emrDoctorsList = [];
  }

  renderEmrDoctorOptions();
  if (deptSelect) {
    deptSelect.removeEventListener('change', renderEmrDoctorOptions);
    deptSelect.addEventListener('change', renderEmrDoctorOptions);
  }
}

function renderEmrDoctorOptions() {
  const doctorSelect = document.getElementById('emr-appt-doctor');
  const dept = document.getElementById('emr-appt-department')?.value || '';
  if (!doctorSelect) return;

  doctorSelect.innerHTML = '<option value="">Select a doctor</option>';
  const filtered = dept
    ? emrDoctorsList.filter(d => d.specialty === dept)
    : emrDoctorsList;

  filtered.forEach(d => {
    const label = `${d.name} — ${d.specialty}${d.available == 0 ? ' (Not available)' : ''}`;
    const opt = new Option(label, d.name);
    opt.disabled = d.available == 0;
    doctorSelect.add(opt);
  });
}

// Load available slots using the same API as patient dashboard
async function emrLoadSlots() {
  const doctorSelect = document.getElementById('emr-appt-doctor');
  const dateInput = document.getElementById('emr-appt-date');
  const slotContainer = document.getElementById('emr-slot-picker-container');
  const timeInput = document.getElementById('emr-appt-time');

  const doctorName = doctorSelect?.value || '';
  const date = dateInput?.value || '';
  
  // Find doctor ID
  const doctor = emrDoctorsList.find(d => d.name === doctorName);
  const doctorId = doctor?.user_id || doctor?.id || 0;

  if (!doctorId || !date) {
    if (slotContainer) {
      slotContainer.innerHTML = '<p class="slot-placeholder" style="color:var(--text-muted);font-size:0.85rem">Select a doctor and date to see available slots.</p>';
    }
    return;
  }

  const requestId = ++emrLastSlotRequestId;
  if (timeInput) timeInput.value = '';
  document.getElementById('emr-slot-summary-wrapper')?.classList.remove('visible');

  // Show skeleton
  if (slotContainer) {
    slotContainer.innerHTML = `
      <div class="slot-skeleton-area">
        <div class="slot-skeleton-row">
          <div class="slot-skeleton-chip"></div>
          <div class="slot-skeleton-chip"></div>
          <div class="slot-skeleton-chip"></div>
          <div class="slot-skeleton-chip"></div>
          <div class="slot-skeleton-chip"></div>
          <div class="slot-skeleton-chip"></div>
        </div>
        <div class="slot-skeleton-row">
          <div class="slot-skeleton-chip"></div>
          <div class="slot-skeleton-chip"></div>
          <div class="slot-skeleton-chip"></div>
          <div class="slot-skeleton-chip"></div>
          <div class="slot-skeleton-chip"></div>
          <div class="slot-skeleton-chip"></div>
        </div>
      </div>`;
  }

  const result = await apiFetch((getBasePath() + "api/appointments/get-available-slots.php"), {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ doctor_id: doctorId, date })
  }, 'Failed to load slots.');

  if (requestId !== emrLastSlotRequestId) return;
  if (!result.ok || !result.data?.success) {
    if (slotContainer) {
      slotContainer.innerHTML = '<p class="slot-placeholder" style="color:var(--danger);font-size:0.85rem">Failed to load slots.</p>';
    }
    return;
  }

  const slots = result.data.slots || [];
  const message = result.data.message || '';
  const duration = result.data.duration || 30;

  if (requestId !== emrLastSlotRequestId) return;

  if (slots.length === 0) {
    if (slotContainer) {
      slotContainer.innerHTML = `
        <div class="slot-empty-card">
          <div class="slot-empty-icon"><i class="fas fa-calendar"></i></div>
          <h4 class="slot-empty-title">No Available Slots</h4>
          <p class="slot-empty-desc">${escapeHTML(message || 'No appointment slots are available for this date. Try selecting another day.')}</p>
        </div>`;
    }
    return;
  }

  if (requestId !== emrLastSlotRequestId) return;

  // Group slots by period
  const groups = { Morning: [], Afternoon: [], Evening: [] };
  for (const slot of slots) {
    const hour = parseInt(slot.time.split(':')[0], 10);
    if (hour < 12) groups.Morning.push(slot);
    else if (hour < 17) groups.Afternoon.push(slot);
    else groups.Evening.push(slot);
  }

  let html = '';
  // Duration bar
  html += `
    <div class="slot-duration-bar">
      <i class="fas fa-clock"></i>
      <span>Appointment Duration: <strong>${duration} Minutes</strong></span>
    </div>`;

  // Period tabs
  html += `
    <div class="slot-tabs" role="tablist">
      <button type="button" class="slot-tab active" role="tab" aria-selected="true" onclick="emrSwitchSlotTab('morning')" tabindex="0">
        <i class="fas fa-sun"></i> Morning <span class="slot-tab-count">${groups.Morning.length}</span>
      </button>
      <button type="button" class="slot-tab" role="tab" aria-selected="false" onclick="emrSwitchSlotTab('afternoon')" tabindex="-1">
        <i class="fas fa-cloud-sun"></i> Afternoon <span class="slot-tab-count">${groups.Afternoon.length}</span>
      </button>
      <button type="button" class="slot-tab" role="tab" aria-selected="false" onclick="emrSwitchSlotTab('evening')" tabindex="-1">
        <i class="fas fa-moon"></i> Evening <span class="slot-tab-count">${groups.Evening.length}</span>
      </button>
    </div>`;

  // Scroll area with panels
  html += `<div class="slot-scroll-area">`;
  const tabNames = ['morning', 'afternoon', 'evening'];
  const periodNames = ['Morning', 'Afternoon', 'Evening'];

  for (let i = 0; i < 3; i++) {
    const tabId = tabNames[i];
    const periodName = periodNames[i];
    const periodSlots = groups[periodName] || [];

    html += `<div class="slot-panel${i === 0 ? ' active' : ''}" id="emr-slot-panel-${tabId}" role="tabpanel">`;

    if (periodSlots.length === 0) {
      html += `
        <div class="slot-empty-card">
          <div class="slot-empty-icon"><i class="fas fa-calendar"></i></div>
          <h4 class="slot-empty-title">No Available Slots</h4>
          <p class="slot-empty-desc">${escapeHTML(message || 'No appointment slots are available for this time period.')}</p>
        </div>`;
    } else {
      html += `<div class="slot-grid" role="radiogroup" aria-label="${periodName} slots">`;
      for (const slot of periodSlots) {
        html += `
          <button type="button" class="slot-chip" data-time="${escapeHTML(slot.time)}" data-label="${escapeHTML(slot.label)}" onclick="emrSelectSlot(this)" role="radio" aria-checked="false" tabindex="0">
            <span class="slot-check"><i class="fas fa-check"></i></span>
            <span class="slot-label">${escapeHTML(slot.label)}</span>
          </button>`;
      }
      html += `</div>`;
    }
    html += `</div>`;
  }
  html += `</div>`;

  if (requestId !== emrLastSlotRequestId) return;

  if (slotContainer) {
    slotContainer.innerHTML = html;
  }

  // Activate first tab with slots
  let firstActive = 'morning';
  if (groups.Morning.length === 0) {
    if (groups.Afternoon.length > 0) firstActive = 'afternoon';
    else if (groups.Evening.length > 0) firstActive = 'evening';
  }
  emrSwitchSlotTab(firstActive);

  // Keyboard nav
  slotContainer?.querySelectorAll('.slot-chip').forEach(chip => {
    chip.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        chip.click();
      }
    });
  });

  document.getElementById('emr-book-submit-btn')?.setAttribute('disabled', 'disabled');
}

function emrSwitchSlotTab(tabId) {
  document.querySelectorAll('#emr-book-appointment-modal .slot-tab').forEach(tab => {
    const isActive = tab.getAttribute('onclick')?.includes(tabId);
    tab.classList.toggle('active', isActive);
    tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
    tab.setAttribute('tabindex', isActive ? '0' : '-1');
  });

  document.querySelectorAll('#emr-book-appointment-modal .slot-panel').forEach(panel => {
    panel.classList.toggle('active', panel.id === `emr-slot-panel-${tabId}`);
  });
}

function emrSelectSlot(btn) {
  document.querySelectorAll('#emr-book-appointment-modal .slot-chip.selected').forEach(c => {
    c.classList.remove('selected');
    c.setAttribute('aria-checked', 'false');
  });

  btn.classList.add('selected');
  btn.setAttribute('aria-checked', 'true');

  const timeInput = document.getElementById('emr-appt-time');
  if (timeInput) timeInput.value = btn.dataset.time;

  // Update summary
  const wrapper = document.getElementById('emr-slot-summary-wrapper');
  if (wrapper) {
    const doctorName = document.getElementById('emr-appt-doctor')?.value || '';
    const dateVal = document.getElementById('emr-appt-date')?.value || '';
    const slotLabel = btn.dataset.label || btn.querySelector('.slot-label')?.textContent || '';

    let dateDisplay = dateVal;
    try {
      const d = new Date(dateVal + 'T12:00:00');
      dateDisplay = d.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
    } catch (e) {}

    document.getElementById('emr-summary-doctor-name').textContent = doctorName;
    document.getElementById('emr-summary-date-text').textContent = dateDisplay;
    document.getElementById('emr-summary-time-text').textContent = slotLabel;
    wrapper.classList.add('visible');
  }

  document.getElementById('emr-book-submit-btn')?.removeAttribute('disabled');
}

function initBookAppointmentModal() {
  const form = document.getElementById('emr-book-form');
  if (!form) return;

  // Load slots when doctor or date changes
  document.getElementById('emr-appt-doctor')?.addEventListener('change', emrLoadSlots);
  document.getElementById('emr-appt-date')?.addEventListener('change', emrLoadSlots);

  form.addEventListener('submit', async (e) => {
    e.preventDefault();

    const patientName = emrData?.patient?.name || '';
    const data = {
      department: document.getElementById('emr-appt-department')?.value.trim() || '',
      doctor: document.getElementById('emr-appt-doctor')?.value.trim() || '',
      date: document.getElementById('emr-appt-date')?.value.trim() || '',
      time: document.getElementById('emr-appt-time')?.value.trim() || '',
      patientName: patientName,
      notes: document.getElementById('emr-appt-notes')?.value.trim() || '',
      patient_id: patientId,
    };

    if (!data.department || !data.doctor || !data.date || !data.time || !data.patientName) {
      showToast('Please fill in all required fields.', 'error');
      return;
    }

    const submitBtn = form.querySelector('[type="submit"]');
    const originalText = submitBtn?.textContent || 'Confirm Booking';
    setLoading(submitBtn, true, 'Booking...');

    const result = await apiFetch((getBasePath() + "api/appointments/book.php"), {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data),
    }, 'Booking failed.');

    if (result.data?.success) {
      showToast('Appointment booked successfully!', 'success');
      closeModal('emr-book-appointment-modal');
      await loadEmrData({ preserveTab: true, preserveScroll: true });
    } else {
      showToast(result.data?.message || 'Booking failed.', 'error');
    }
    setLoading(submitBtn, false, originalText);
  });
}

/* ═══════════════════════════════════════════════════════════
   RESET PASSWORD MODAL
   ═══════════════════════════════════════════════════════════ */

function openResetPasswordModal() {
  document.getElementById('emr-reset-pw-patient-id').value = patientId;
  document.getElementById('emr-reset-pw-new').value = '';
  document.getElementById('emr-reset-pw-confirm').value = '';
  document.getElementById('emr-reset-pw-force').checked = false;
  openModal('emr-reset-pw-modal');
}

function initResetPasswordForm() {
  const form = document.getElementById('emr-reset-pw-form');
  if (!form) return;

  form.addEventListener('submit', async (e) => {
    e.preventDefault();

    const newPw = document.getElementById('emr-reset-pw-new')?.value;
    const confirmPw = document.getElementById('emr-reset-pw-confirm')?.value;

    if (!newPw || newPw.length < 6) {
      showToast('Password must be at least 6 characters.', 'error');
      return;
    }
    if (newPw !== confirmPw) {
      showToast('Passwords do not match.', 'error');
      return;
    }

    const submitBtn = form.querySelector('[type="submit"]');
    const originalText = submitBtn?.textContent || 'Reset Password';
    setLoading(submitBtn, true, 'Resetting...');

    const result = await apiFetch((getBasePath() + "api/patients/update.php"), {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ patient_id: patientId, password: newPw }),
    }, 'Failed to reset password.');

    if (result.data?.success) {
      showToast('Password reset successfully!', 'success');
      closeModal('emr-reset-pw-modal');
    } else {
      showToast(result.data?.message || 'Failed to reset password.', 'error');
    }
    setLoading(submitBtn, false, originalText);
  });
}

/* ═══════════════════════════════════════════════════════════
   DELETE PATIENT
   ═══════════════════════════════════════════════════════════ */

function openDeleteModal() {
  if (!emrData) return;
  document.getElementById('emr-delete-name').textContent = emrData.patient.name;
  openModal('emr-delete-modal');
}

function initDeletePatient() {
  const confirmBtn = document.getElementById('emr-delete-confirm-btn');
  if (!confirmBtn) return;

  confirmBtn.addEventListener('click', async () => {
    if (!confirm('Are you absolutely sure? This cannot be undone.')) return;

    const result = await apiFetch((getBasePath() + "api/patients/delete.php"), {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: patientId }),
    }, 'Delete failed.');

    if (result.data?.success) {
      showToast('Patient deleted.', 'success');
      closeModal('emr-delete-modal');
      setTimeout(() => { window.location.href = getBasePath() + 'pages/admin/admin.html#patients'; }, 1000);
    } else {
      showToast(result.data?.message || 'Delete failed.', 'error');
    }
  });
}

/* ═══════════════════════════════════════════════════════════
   AUDIT TAB — Full activity history for this patient
   ═══════════════════════════════════════════════════════════ */

async function renderAudit() {
  const container = document.getElementById('emr-audit');
  if (!container) return;
  
  try {
    const result = await apiFetch(
      `${getBasePath()}api/audit/get.php?patient_id=${patientId}&limit=50`,
      {},
      'Failed to load audit log.'
    );
    const entries = (result.ok && result.data?.entries) ? result.data.entries : [];
    
    if (entries.length === 0) {
      container.innerHTML = '<div class="emr-empty-state"><i class="fas fa-clipboard-list"></i><h4>No Activity Recorded</h4><p>No administrative or clinical activity has been recorded for this patient yet.</p></div>';
      return;
    }
    
    const actionIcons = {
      create: 'fa-plus-circle', update: 'fa-pen', delete: 'fa-trash',
      activate: 'fa-toggle-on', deactivate: 'fa-toggle-off',
      book: 'fa-calendar-plus', approve: 'fa-check', decline: 'fa-ban',
      cancel: 'fa-ban', complete: 'fa-check-circle'
    };
    
    const roleBadge = (role) => {
      if (role === 'doctor') return '<span style="font-size:0.7rem;padding:1px 8px;border-radius:var(--r-full);background:rgba(99,102,241,0.1);color:var(--primary);margin-left:var(--s1)">Doctor</span>';
      if (role === 'admin') return '<span style="font-size:0.7rem;padding:1px 8px;border-radius:var(--r-full);background:rgba(34,197,94,0.1);color:var(--success);margin-left:var(--s1)">Admin</span>';
      if (role === 'patient') return '<span style="font-size:0.7rem;padding:1px 8px;border-radius:var(--r-full);background:rgba(250,204,21,0.1);color:var(--warning);margin-left:var(--s1)">Patient</span>';
      return '';
    };
    
    const items = entries.map(e => {
      const icon = actionIcons[e.action] || 'fa-circle';
      const desc = e.description ? escapeHTML(e.description) : `${escapeHTML(e.action)} on ${escapeHTML(e.entity_type)}`;
      return `
        <div class="dp-audit-entry" style="display:flex;gap:var(--s3);padding:var(--s3) var(--s4);background:var(--bg-surface);border:1px solid var(--border-light);border-radius:var(--r-md);margin-bottom:var(--s2)">
          <div style="width:32px;height:32px;border-radius:50%;background:var(--bg-subtle);display:flex;align-items:center;justify-content:center;flex-shrink:0;color:var(--text-muted)">
            <i class="fas ${icon}" style="font-size:0.8rem"></i>
          </div>
          <div style="flex:1;min-width:0">
            <div style="font-weight:600;font-size:0.85rem;color:var(--text-primary);display:flex;align-items:center;flex-wrap:wrap;gap:var(--s1)">
              ${escapeHTML(e.actor_name || 'System')} ${roleBadge(e.actor_role)}
            </div>
            <div style="font-size:0.82rem;color:var(--text-secondary);margin:var(--s1) 0">${desc}</div>
            <div style="font-size:0.72rem;color:var(--text-muted)">${escapeHTML(formatDate(e.created_at))}</div>
          </div>
        </div>`;
    }).join('');
    
    container.innerHTML = `
      <div style="margin-bottom:var(--s4)">
        <h4 style="font-size:1rem;margin:0;color:var(--text-primary)"><i class="fas fa-clipboard-list"></i> Activity History</h4>
        <p style="font-size:0.78rem;color:var(--text-muted);margin:var(--s1) 0 0 0">All meaningful changes related to this patient</p>
      </div>
      <div style="display:flex;flex-direction:column;gap:var(--s1)">${items}</div>`;
  } catch (e) {
    container.innerHTML = '<div class="emr-empty-state"><i class="fas fa-exclamation-triangle"></i><h4>Failed to Load</h4><p>Could not load audit history.</p></div>';
  }
}

/* ═══════════════════════════════════════════════════════════
   AUDIT HISTORY SECTION (medical_record_audit specialized)
   ═══════════════════════════════════════════════════════════ */

function renderAuditHistory() {
  const audit = emrData.audit_history || [];
  if (audit.length === 0) {
    return `
      <div class="emr-section" style="margin-top:var(--s6)">
        <div class="flex-between" style="margin-bottom:var(--s4)">
          <h4 style="font-size:1rem;margin:0;color:var(--text-primary)"><i class="fas fa-clipboard-list"></i> Administrative Corrections</h4>
        </div>
        <div class="emr-empty-state" style="padding:var(--s4);background:var(--bg-surface);border:1px solid var(--border-light);border-radius:var(--r-md)">
          <i class="fas fa-clipboard-check" style="font-size:1.2rem;color:var(--text-muted)"></i>
          <p style="font-size:0.83rem;color:var(--text-muted);margin:var(--s2) 0 0 0">No administrative corrections have been recorded.</p>
        </div>
      </div>`;
  }

  const rows = audit.map(entry => {
    const fieldLabel = entry.field_name === 'blood_type' ? 'Blood Type' : 
                       entry.field_name === 'emergency_contact_name' ? 'Emergency Contact' :
                       entry.field_name === 'emergency_contact_phone' ? 'Emergency Phone' : entry.field_name;
    
    return `
      <tr>
        <td><strong>${escapeHTML(entry.admin_name)}</strong></td>
        <td>${escapeHTML(fieldLabel)}</td>
        <td>${escapeHTML(entry.old_value || '—')}</td>
        <td>${escapeHTML(entry.new_value || '—')}</td>
        <td>${escapeHTML(entry.reason)}</td>
        <td>${formatDate(entry.created_at)} ${formatTime(entry.created_at)}</td>
      </tr>`;
  }).join('');

  return `
    <div class="emr-section" style="margin-top:var(--s6)">
      <div class="flex-between" style="margin-bottom:var(--s4)">
        <h4 style="font-size:1rem;margin:0;color:var(--text-primary)"><i class="fas fa-clipboard-list"></i> Administrative Corrections</h4>
        <span style="font-size:0.78rem;color:var(--text-muted)">${audit.length} correction${audit.length !== 1 ? 's' : ''}</span>
      </div>
      <div class="emr-table-wrap" style="max-height:300px;overflow-y:auto">
        <table>
          <thead>
            <tr>
              <th>Administrator</th>
              <th>Field</th>
              <th>Old Value</th>
              <th>New Value</th>
              <th>Reason</th>
              <th>Date</th>
            </tr>
          </thead>
          <tbody>${rows}</tbody>
        </table>
      </div>
    </div>`;
}

/* ═══════════════════════════════════════════════════════════
   ADMIN CORRECTION MODAL
   ═══════════════════════════════════════════════════════════ */

function openAdminCorrectionModal() {
  const mr = emrData.medical_record;
  if (!mr) return;

  document.getElementById('admin-corr-blood-type').value = mr.blood_type || '';
  document.getElementById('admin-corr-emergency-name').value = mr.emergency_contact_name || '';
  document.getElementById('admin-corr-emergency-phone').value = mr.emergency_contact_phone || '';
  document.getElementById('admin-corr-reason').value = '';
  
  openModal('admin-correction-modal');
}

function initAdminCorrectionForm() {
  const form = document.getElementById('admin-correction-form');
  if (!form) return;

  form.addEventListener('submit', async (e) => {
    e.preventDefault();

    const bloodType = document.getElementById('admin-corr-blood-type')?.value;
    const emergencyName = document.getElementById('admin-corr-emergency-name')?.value;
    const emergencyPhone = document.getElementById('admin-corr-emergency-phone')?.value;
    const reason = document.getElementById('admin-corr-reason')?.value.trim();

    if (!reason) {
      showToast('Reason for correction is required.', 'error');
      return;
    }

    const mr = emrData.medical_record;
    const corrections = [];

    if (bloodType !== mr.blood_type) {
      corrections.push({ field: 'blood_type', old: mr.blood_type || '', new: bloodType });
    }
    if (emergencyName !== mr.emergency_contact_name) {
      corrections.push({ field: 'emergency_contact_name', old: mr.emergency_contact_name || '', new: emergencyName });
    }
    if (emergencyPhone !== mr.emergency_contact_phone) {
      corrections.push({ field: 'emergency_contact_phone', old: mr.emergency_contact_phone || '', new: emergencyPhone });
    }

    if (corrections.length === 0) {
      showToast('No changes detected.', 'info');
      closeModal('admin-correction-modal');
      return;
    }

    const submitBtn = form.querySelector('[type="submit"]');
    const originalText = submitBtn?.textContent || 'Save Corrections';
    setLoading(submitBtn, true, 'Saving...');

    // Update medical record with audit logging handled server-side in transaction
    const result = await apiFetch((getBasePath() + "api/medical/update-record.php"), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        patient_id: patientId,
        blood_type: bloodType,
        emergency_contact_name: emergencyName,
        emergency_contact_phone: emergencyPhone,
        reason: reason
      })
    }, 'Failed to update medical record.');

    if (result.data?.success) {
      showToast('Administrative corrections saved successfully.', 'success');
      closeModal('admin-correction-modal');
      await loadEmrData({ preserveTab: true, preserveScroll: true });
    } else {
      showToast(result.data?.message || 'Failed to save corrections.', 'error');
    }

    setLoading(submitBtn, false, originalText);
  });
}

/* ═══════════════════════════════════════════════════════════
   EXPOSE GLOBALS
   ═══════════════════════════════════════════════════════════ */

window.switchTab = switchTab;
window.filterAppointments = filterAppointments;
window.clearApptSearch = clearApptSearch;
window.refreshAppointments = refreshAppointments;
window.toggleVisitNote = toggleVisitNote;
window.setNotifFilter = setNotifFilter;
window.refreshEmr = refreshEmr;
window.printEmrPage = printEmrPage;
window.printPrescription = printPrescription;
window.printAppointment = printAppointment;
window.toggleEmrPatientStatus = toggleEmrPatientStatus;
window.openAdminCorrectionModal = openAdminCorrectionModal;
window.openEditModal = openEditModal;
window.openPrescriptionModal = openPrescriptionModal;
window.openAppointmentModal = openAppointmentModal;
window.openBookAppointmentModal = openBookAppointmentModal;
window.openResetPasswordModal = openResetPasswordModal;
window.openDeleteModal = openDeleteModal;
window.emrApproveAppt = emrApproveAppt;
window.emrDeclineAppt = emrDeclineAppt;
window.emrCancelAppt = emrCancelAppt;
window.emrMarkNotifRead = emrMarkNotifRead;
window.emrDeleteNotif = emrDeleteNotif;
window.emrHideRating = emrHideRating;
window.emrRestoreRating = emrRestoreRating;
window.emrLoadSlots = emrLoadSlots;
window.emrSwitchSlotTab = emrSwitchSlotTab;
window.emrSelectSlot = emrSelectSlot;


