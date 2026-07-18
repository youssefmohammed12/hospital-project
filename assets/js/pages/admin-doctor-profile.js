/**
 * HealthBridge — Admin Doctor Profile Page
 * Dedicated full-page Doctor Profile, following Patient EMR architecture.
 *
 * Reuses:
 *   - apiFetch(), escapeHTML(), formatDate(), showToast()
 *   - formatApptTime() from main.js helpers
 *   - ScheduleManager from schedule.js for live schedule editing
 *   - Existing PHP endpoints (no duplicates)
 *
 * Used by: admin-doctor-profile.html
 */

"use strict";

/** Local helper: set textContent of an element by ID */
function setText(id, text) {
  const el = document.getElementById(id);
  if (el) el.textContent = text;
}

// Module state
let _currentDoctorData = null;
let _profileDoctorId = null;
let _profileDepartments = [];
let _loadedTabs = {};
let adminDoctorsList = [];

document.addEventListener("DOMContentLoaded", async () => {
  const user = await requireServerRole("admin");
  if (!user) return;

  setText("admin-name", user.name || "Admin");
  setText("admin-email", user.email || "admin@healthbridge.com");

  // Read doctor ID from URL
  const params = new URLSearchParams(window.location.search);
  const doctorId = parseInt(params.get("id"), 10);

  if (!doctorId || doctorId <= 0) {
    showError("Invalid Doctor ID", "No valid doctor ID was provided in the URL.");
    return;
  }

  // Hide error state initially, show loading
  document.getElementById("dp-error").style.display = "none";
  document.getElementById("dp-header-card").style.display = "none";
  document.getElementById("dp-tabs").style.display = "none";
  document.getElementById("dp-tab-contents").style.display = "none";

  await loadDoctorProfile(doctorId);
  initEditDoctorForm();
  initDoctorReassignForm();
  initBookAppointmentModal();
});

/**
 * Show a professional error state.
 */
function showError(title, message) {
  document.getElementById("dp-error-title").textContent = title;
  document.getElementById("dp-error-message").textContent = message;
  document.getElementById("dp-error").style.display = "";
  document.getElementById("dp-header-card").style.display = "none";
  document.getElementById("dp-tabs").style.display = "none";
  document.getElementById("dp-tab-contents").style.display = "none";
}

/**
 * Load and render the full doctor profile.
 * Reuses get_doctor_profile.php which is admin-protected and returns
 * doctor data, appointments, AND reviews — avoiding separate doctor-only endpoints.
 */
async function loadDoctorProfile(id) {
  _profileDoctorId = id;
  _loadedTabs = {};

  // Load departments for modals
  _profileDepartments = [];
  const deptResult = await apiFetch((getBasePath() + "api/departments/get.php"), {}, "Failed to load departments.");
  if (deptResult.ok && deptResult.data?.departments) {
    _profileDepartments = deptResult.data.departments.filter(d => d.status === 'active');
  }

  const result = await apiFetch(
    `${getBasePath()}api/doctors/profile.php?id=${id}`,
    {},
    "Failed to load doctor profile.",
  );
  if (!result.ok || !result.data?.success) {
    showError("Doctor Not Found", result.data?.message || "Failed to load doctor profile.");
    return;
  }

  const data = result.data;
  const doc = data.doctor;
  _currentDoctorData = data;
  window._currentDoctorProfile = doc;

  // Show UI
  document.getElementById("dp-error").style.display = "none";
  document.getElementById("dp-header-card").style.display = "";
  document.getElementById("dp-tabs").style.display = "";
  document.getElementById("dp-tab-contents").style.display = "";

  // --- Profile Header ---
  setText("dp-full-name", doc.name || "Unknown");
  setText("dp-department-display", doc.department_name || doc.specialty || "—");
  setText("dp-rating-display", `${(parseFloat(doc.rating) || 0).toFixed(1)} ★`);
  setText("dp-exp-display", `${doc.exp || 0} years`);
  setText("dp-email-display", doc.email || "—");
  setText("dp-phone-display", doc.phone || "—");
  setText("dp-joined-display", doc.created_at ? formatDate(doc.created_at) : "—");

  // Status badge
  const statusBadge = document.getElementById("dp-status-badge");
  if (statusBadge) {
    const isActive = doc.is_active == 1;
    statusBadge.className = `dp-badge ${isActive ? 'dp-badge-active' : 'dp-badge-inactive'}`;
    statusBadge.innerHTML = `<i class="fas fa-circle" aria-hidden="true"></i> ${isActive ? 'Active' : 'Inactive'}`;
  }

  // Availability badge
  const availBadge = document.getElementById("dp-availability-badge");
  if (availBadge) {
    const isAvail = doc.available == 1;
    availBadge.className = `dp-badge ${isAvail ? 'dp-badge-available' : 'dp-badge-unavailable'}`;
    availBadge.innerHTML = isAvail
      ? '<i class="fas fa-check-circle" aria-hidden="true"></i> Available'
      : '<i class="fas fa-times-circle" aria-hidden="true"></i> Unavailable';
  }

  // Toggle status button
  const statusBtn = document.getElementById("dp-toggle-status-btn");
  if (statusBtn) {
    statusBtn.innerHTML = doc.is_active == 1
      ? '<i class="fas fa-toggle-on" aria-hidden="true"></i> Deactivate'
      : '<i class="fas fa-toggle-off" aria-hidden="true"></i> Activate';
  }

  // --- Overview Tab Stats ---
  const completedCount = data.completed_count || 0;
  const totalAppts = (data.appointments || []).length;
  const totalReviews = (data.reviews || []).length;
  setText("dp-overview-rating", `${(parseFloat(doc.rating) || 0).toFixed(1)}`);
  setText("dp-overview-completed", completedCount);
  setText("dp-overview-total", totalAppts);
  setText("dp-overview-reviews", totalReviews);

  setText("dp-info-name", doc.name || "—");
  setText("dp-info-email", doc.email || "—");
  setText("dp-info-phone", doc.phone || "—");
  setText("dp-info-dept", doc.department_name || doc.specialty || "—");
  setText("dp-info-status", doc.is_active == 1 ? "Active" : "Inactive");
  setText("dp-info-availability", doc.available == 1 ? "Available" : "Unavailable");
  setText("dp-info-joined", doc.created_at ? formatDate(doc.created_at) : "—");

  // --- Professional Tab ---
  setText("dp-prof-dept", doc.department_name || doc.specialty || "—");
  setText("dp-prof-specialty", doc.specialty || "General Practice");
  setText("dp-prof-exp", `${doc.exp || 0} years`);

  // Pre-populate edit/reassign modals
  document.getElementById("dp-edit-id").value = doc.id;
  document.getElementById("dp-edit-name").value = doc.name;
  document.getElementById("dp-edit-email").value = doc.email;
  document.getElementById("dp-edit-phone").value = doc.phone || "";
  document.getElementById("dp-reassign-id").value = doc.id;
  document.getElementById("dp-reassign-name").textContent = doc.name;
  document.getElementById("admin-appt-context-id").value = doc.id;

  // Pre-render Appointments tab using data already loaded from get_doctor_profile.php
  renderProfileAppointments(data.appointments || []);
  // Pre-render Reviews tab using data already loaded from get_doctor_profile.php
  renderProfileReviews(data.reviews || []);

  // Switch to overview tab
  switchDoctorTab("overview");
}

/* ── TAB SWITCHING ──────────────────────────────────────── */

function switchDoctorTab(tabId) {
  document.querySelectorAll(".dp-tab").forEach(tab => {
    tab.classList.toggle("active", tab.dataset.tab === tabId);
  });
  document.querySelectorAll(".dp-tab-content").forEach(content => {
    content.classList.toggle("active", content.id === `dp-tab-${tabId}`);
  });
  // Lazy-load data tabs (only once per profile session)
  if (tabId === "schedule" && !_loadedTabs.schedule) {
    _loadedTabs.schedule = true;
    loadProfileSchedule();
  }
  if (tabId === "audit" && !_loadedTabs.audit) {
    _loadedTabs.audit = true;
    loadProfileAudit();
  }
}

/* ── LAZY TAB LOADERS ───────────────────────────────────── */

/**
 * Load the Schedule & Availability tab using the shared ScheduleManager.
 * Builds a summary card + editable schedule via ScheduleManager.init()
 * + schedule change history from audit log.
 */
async function loadProfileSchedule() {
  const container = document.getElementById("dp-schedule-content");
  if (!container || !_profileDoctorId) return;

  // Build the combined schedule view
  container.innerHTML = `
    <div id="dp-schedule-summary" class="dp-schedule-summary">
      <div class="empty-state"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i><p>Loading schedule summary...</p></div>
    </div>
    <div class="dp-section" style="margin-top: var(--s6)">
      <h4 class="dp-section-title"><i class="fas fa-pen" aria-hidden="true"></i> Edit Weekly Schedule</h4>
      <div id="dp-schedule-editor"></div>
    </div>
    <div class="dp-section" style="margin-top: var(--s6)">
      <h4 class="dp-section-title"><i class="fas fa-history" aria-hidden="true"></i> Schedule Change History</h4>
      <div id="dp-schedule-history"><div class="empty-state"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i><p>Loading history...</p></div></div>
    </div>
  `;

  // 1. Load schedule summary
  await loadScheduleSummary();

  // 2. Initialize the ScheduleManager for editing
  try {
    ScheduleManager.init("dp-schedule-editor", "admin", _profileDoctorId);
  } catch (e) {
    document.getElementById("dp-schedule-editor").innerHTML =
      '<div class="empty-state"><i class="fas fa-exclamation-triangle" aria-hidden="true"></i><p>Failed to initialize schedule editor.</p></div>';
  }

  // 3. Load schedule change history
  await loadScheduleHistory();
}

/**
 * Load and display a professional schedule summary card.
 */
async function loadScheduleSummary() {
  const summaryEl = document.getElementById("dp-schedule-summary");
  if (!summaryEl) return;

  try {
    const result = await apiFetch(
      (getBasePath() + "api/schedule/get.php"),
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ doctor_id: _profileDoctorId }),
      },
      "Failed to load schedule."
    );

    if (!result.ok || !result.data?.success) {
      summaryEl.innerHTML = `
        <div class="dp-schedule-summary-inner">
          <div class="dp-summary-item"><span class="dp-summary-label">Availability</span><span class="dp-summary-value" style="color:var(--text-muted)">No schedule</span></div>
        </div>
      `;
      return;
    }

    const sched = result.data.schedule;
    const settings = sched.settings || {};
    const weekly = sched.weekly || [];
    const workingDays = weekly.filter(d => parseInt(d.is_working) === 1);
    const isAvail = parseInt(settings.is_available) === 1;
    const hospitalHours = document.getElementById("schedule-hospital-hours");
    const hospitalHoursText = hospitalHours ? hospitalHours.textContent : "08:00 AM – 10:00 PM";

    // Format break times
    const breakStr = settings.break_start && settings.break_end
      ? `${formatTime(settings.break_start)} – ${formatTime(settings.break_end)}`
      : "None";

    summaryEl.innerHTML = `
      <div class="dp-schedule-summary-inner">
        <div class="dp-summary-item">
          <span class="dp-summary-label">Current Status</span>
          <span class="dp-summary-value">
            <span class="dp-badge ${isAvail ? 'dp-badge-available' : 'dp-badge-unavailable'}">
              <i class="fas fa-circle" aria-hidden="true"></i> ${isAvail ? 'Available' : 'Unavailable'}
            </span>
          </span>
        </div>
        <div class="dp-summary-item">
          <span class="dp-summary-label">Appointment Duration</span>
          <span class="dp-summary-value">${settings.appointment_duration || 30} minutes</span>
        </div>
        <div class="dp-summary-item">
          <span class="dp-summary-label">Working Days</span>
          <span class="dp-summary-value">${workingDays.length} / 7</span>
        </div>
        <div class="dp-summary-item">
          <span class="dp-summary-label">Hospital Hours</span>
          <span class="dp-summary-value">${escapeHTML(hospitalHoursText)}</span>
        </div>
        <div class="dp-summary-item">
          <span class="dp-summary-label">Break Time</span>
          <span class="dp-summary-value">${escapeHTML(breakStr)}</span>
        </div>
        <div class="dp-summary-item">
          <span class="dp-summary-label">Max Appts / Day</span>
          <span class="dp-summary-value">${settings.max_appointments_per_day || 25}</span>
        </div>
      </div>
      <div class="dp-schedule-working-days">
        ${workingDays.map(d => {
          const dayNames = {1:"Mon",2:"Tue",3:"Wed",4:"Thu",5:"Fri",6:"Sat",7:"Sun"};
          const dayFull = {1:"Monday",2:"Tuesday",3:"Wednesday",4:"Thursday",5:"Friday",6:"Saturday",7:"Sunday"};
          const name = dayNames[parseInt(d.day_of_week)] || d.day_of_week;
          const fullName = dayFull[parseInt(d.day_of_week)] || d.day_of_week;
          return `<div class="dp-work-day-badge" title="${fullName}">
            <span class="dp-work-day-name">${escapeHTML(name)}</span>
            <span class="dp-work-day-hours">${escapeHTML(d.start_time || '—')} – ${escapeHTML(d.end_time || '—')}</span>
          </div>`;
        }).join('')}
      </div>
    `;
  } catch (e) {
    summaryEl.innerHTML = '<div class="empty-state"><i class="fas fa-exclamation-triangle" aria-hidden="true"></i><p>Failed to load schedule summary.</p></div>';
  }
}

/**
 * Load schedule change history from audit log.
 * Displays human-readable changes using the description field.
 * Never shows raw JSON.
 */
async function loadScheduleHistory() {
  const historyEl = document.getElementById("dp-schedule-history");
  if (!historyEl || !_profileDoctorId) return;

  try {
    // Query audit log for schedule-related changes
    const result = await apiFetch(
      `${getBasePath()}api/audit/get.php?entity_type=doctor&entity_id=${_profileDoctorId}&limit=50`,
      {},
      "Failed to load history."
    );

    const entries = (result.ok && result.data?.entries) ? result.data.entries : [];
    
    // Filter for schedule-related actions (update_schedule)
    const scheduleEntries = entries.filter(e => {
      const action = (e.action || '').toLowerCase();
      return action.includes('schedule');
    });

    if (scheduleEntries.length === 0) {
      historyEl.innerHTML = '<div class="empty-state"><i class="fas fa-history" aria-hidden="true"></i><p>No schedule changes have been recorded yet. Schedule updates made here will appear in this history.</p></div>';
      return;
    }

    // Use the description field (human-readable, set by AuditService.logScheduleChange)
    // Fall back to action name if description is null (legacy entries)
    historyEl.innerHTML = scheduleEntries.map(e => {
      const actorDisplay = e.actor_name || 'System';
      const roleBadge = e.actor_role === 'doctor'
        ? '<span class="dp-role-badge dp-role-doctor">Doctor</span>'
        : e.actor_role === 'admin'
          ? '<span class="dp-role-badge dp-role-admin">Admin</span>'
          : '';
      
      // Use description if available (new entries), otherwise show action + date only
      const changeText = e.description
        ? escapeHTML(e.description)
        : 'Schedule updated';

      return `
        <div class="dp-audit-entry">
          <div class="dp-audit-icon"><i class="fas fa-calendar" aria-hidden="true"></i></div>
          <div class="dp-audit-body">
            <div class="dp-audit-action">
              ${escapeHTML(actorDisplay)} ${roleBadge}
            </div>
            <div class="dp-audit-description">${changeText}</div>
            <div class="dp-audit-meta">${escapeHTML(formatDate(e.created_at))}</div>
          </div>
        </div>
      `;
    }).join('');
  } catch (e) {
    historyEl.innerHTML = '<div class="empty-state"><i class="fas fa-exclamation-triangle" aria-hidden="true"></i><p>Failed to load schedule history.</p></div>';
  }
}

/**
 * Render appointments from data already loaded via get_doctor_profile.php.
 * This avoids calling get_doctor_appointments.php which is doctor-only.
 */
function renderProfileAppointments(appts) {
  const container = document.getElementById("dp-appointments-content");
  if (!container) return;
  if (!appts || appts.length === 0) {
    container.innerHTML = '<div class="empty-state"><i class="fas fa-calendar" aria-hidden="true"></i><p>No appointments found.</p></div>';
    return;
  }
  const tableHtml = `<div class="table-wrap"><table>
    <thead><tr><th>Patient</th><th>Date & Time</th><th>Status</th></tr></thead>
    <tbody>${appts.map(a => {
      const statusLower = (a.status || "pending").toLowerCase();
      const patientDisplay = a.patient_id
        ? `<a href="${getBasePath()}pages/patient/patient-emr.html?patient_id=${a.patient_id}" style="font-weight:600;color:var(--primary);text-decoration:none">${escapeHTML(a.patient_name)}</a>`
        : `<span style="font-weight:600">${escapeHTML(a.patient_name)}</span>`;
      return `<tr>
        <td>${patientDisplay}</td>
        <td style="font-size:0.85rem">${escapeHTML(formatDate(a.date))} ${escapeHTML(formatApptTime(a))}</td>
        <td><span class="status status-${statusLower}">${escapeHTML(a.status)}</span></td>
      </tr>`;
    }).join('')}</tbody>
  </table></div>`;
  container.innerHTML = tableHtml;
}

/**
 * Render reviews from data already loaded via get_doctor_profile.php.
 * This avoids calling get_doctor_ratings.php which is doctor-only.
 */
function renderProfileReviews(reviews) {
  const container = document.getElementById("dp-reviews-content");
  if (!container) return;
  if (!reviews || reviews.length === 0) {
    container.innerHTML = '<div class="empty-state"><i class="fas fa-star" aria-hidden="true"></i><p>No reviews yet.</p></div>';
    return;
  }
  const tableHtml = `<div class="table-wrap"><table>
    <thead><tr><th>Patient</th><th>Rating</th><th>Comment</th><th>Date</th></tr></thead>
    <tbody>${reviews.map(r => {
      const starsVal = parseInt(r.stars) || 0;
      const starDisplay = `<span style="color:var(--warning)">${Array(starsVal).fill('<i class="fas fa-star" aria-hidden="true"></i>').join('')}${Array(5 - starsVal).fill('<i class="far fa-star" aria-hidden="true"></i>').join('')}</span>`;
      const comment = r.review
        ? escapeHTML(r.review)
        : '<span style="color:var(--text-muted);font-style:italic">No written comment.</span>';
      return `<tr>
        <td><div style="font-weight:600">${escapeHTML(r.patient_name || 'Unknown')}</div></td>
        <td>${starDisplay} (${starsVal}/5)</td>
        <td style="max-width:300px;word-break:break-word">${comment}</td>
        <td style="font-size:0.8rem">${escapeHTML(formatDate(r.review_date))}</td>
      </tr>`;
    }).join('')}</tbody>
  </table></div>`;
  container.innerHTML = tableHtml;
}

async function loadProfileAudit() {
  const container = document.getElementById("dp-audit-content");
  if (!container || !_profileDoctorId) return;
  try {
    // Use doctor_id filter to get ALL activity related to this doctor
    const result = await apiFetch(
      `${getBasePath()}api/audit/get.php?doctor_id=${_profileDoctorId}&limit=50`,
      {},
      "Failed to load audit log."
    );
    const entries = (result.ok && result.data?.entries) ? result.data.entries : [];
    if (entries.length === 0) {
      container.innerHTML = '<div class="empty-state"><i class="fas fa-history" aria-hidden="true"></i><p>No administrative activity recorded for this doctor.</p></div>';
      return;
    }
    const actionIcons = { create:'fa-plus-circle', update:'fa-pen', update_schedule:'fa-calendar', delete:'fa-trash', activate:'fa-toggle-on', deactivate:'fa-toggle-off', reassign:'fa-right-left', book:'fa-calendar-plus', approve:'fa-check', decline:'fa-ban' };
    const roleBadge = (role) => {
      if (role === 'doctor') return '<span class="dp-role-badge dp-role-doctor" style="font-size:0.7rem;padding:1px 8px;border-radius:var(--r-full);background:var(--primary-light);color:var(--primary)">Doctor</span>';
      if (role === 'admin') return '<span class="dp-role-badge dp-role-admin" style="font-size:0.7rem;padding:1px 8px;border-radius:var(--r-full);background:var(--success-light, #e8f5e9);color:var(--success, #2e7d32)">Admin</span>';
      return '';
    };
    const html = entries.map(e => {
      const icon = actionIcons[e.action] || 'fa-circle';
      const desc = e.description ? escapeHTML(e.description) : `${escapeHTML(e.action)} on ${escapeHTML(e.entity_type)}`;
      return `<div class="dp-audit-entry">
        <div class="dp-audit-icon"><i class="fas ${icon}" aria-hidden="true"></i></div>
        <div class="dp-audit-body">
          <div class="dp-audit-action">${escapeHTML(e.actor_name || 'System')} ${roleBadge(e.actor_role)}</div>
          <div class="dp-audit-description" style="font-size:0.85rem;color:var(--text-secondary);margin:var(--s1) 0">${desc}</div>
          <div class="dp-audit-meta" style="font-size:0.75rem;color:var(--text-muted)">${escapeHTML(formatDate(e.created_at))}</div>
        </div>
      </div>`;
    }).join('');
    container.innerHTML = html;
  } catch (e) {
    container.innerHTML = '<div class="empty-state"><i class="fas fa-exclamation-triangle" aria-hidden="true"></i><p>Failed to load audit log.</p></div>';
  }
}

/* ── PROFILE ACTIONS ────────────────────────────────────── */

function editCurrentDoctor() {
  if (!_currentDoctorData) return;
  const doc = _currentDoctorData.doctor;
  document.getElementById("dp-edit-id").value = doc.id;
  document.getElementById("dp-edit-name").value = doc.name || "";
  document.getElementById("dp-edit-email").value = doc.email || "";
  document.getElementById("dp-edit-phone").value = doc.phone || "";

  const deptSelect = document.getElementById("dp-edit-department");
  if (deptSelect) {
    deptSelect.innerHTML = '<option value="">Select department</option>';
    _profileDepartments.forEach(dept => {
      deptSelect.innerHTML += `<option value="${dept.id}"${dept.id == doc.department_id ? ' selected' : ''}>${escapeHTML(dept.name)}</option>`;
    });
  }
  const modal = document.getElementById("dp-edit-modal");
  if (modal) modal.classList.add("open");
}

function closeDoctorEditModal() {
  const modal = document.getElementById("dp-edit-modal");
  if (modal) modal.classList.remove("open");
}

function reassignCurrentDoctorDepartment() {
  if (!_currentDoctorData) return;
  const doc = _currentDoctorData.doctor;
  document.getElementById("dp-reassign-id").value = doc.id;
  document.getElementById("dp-reassign-name").textContent = doc.name;

  const deptSelect = document.getElementById("dp-reassign-department");
  if (deptSelect) {
    deptSelect.innerHTML = '<option value="">Select department</option>';
    _profileDepartments.forEach(dept => {
      deptSelect.innerHTML += `<option value="${dept.id}"${dept.id == doc.department_id ? ' selected' : ''}>${escapeHTML(dept.name)}</option>`;
    });
  }
  const modal = document.getElementById("dp-reassign-modal");
  if (modal) modal.classList.add("open");
}

function closeDoctorReassignModal() {
  const modal = document.getElementById("dp-reassign-modal");
  if (modal) modal.classList.remove("open");
}

async function toggleCurrentDoctorStatus() {
  if (!_currentDoctorData) return;
  const doc = _currentDoctorData.doctor;
  const isActive = doc.is_active == 1;
  const msg = isActive
    ? `Deactivate Dr. ${doc.name}? They will not be able to log in.`
    : `Activate Dr. ${doc.name}?`;
  if (!confirm(msg)) return;

  const result = await apiFetch((getBasePath() + "api/doctors/toggle-status.php"), {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ id: doc.id }),
  }, "Status update failed.");

  if (result.data?.success) {
    showToast(`Dr. ${doc.name} updated.`, "success");
    _loadedTabs = {};
    loadDoctorProfile(doc.id);
  } else {
    showToast(result.data?.message || "Status update failed.", "error");
  }
}

async function deleteCurrentDoctor() {
  if (!_currentDoctorData) return;
  const doc = _currentDoctorData.doctor;
  if (!confirm(`Delete Dr. ${doc.name}? This will also cancel all their pending and confirmed appointments.`)) return;

  const result = await apiFetch((getBasePath() + "api/doctors/delete.php"), {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ id: doc.id }),
  }, "Delete failed.");

  if (result.data?.success) {
    showToast(`Dr. ${doc.name} has been deleted.`, "success");
    window.location.href = getBasePath() + "pages/admin/admin.html#doctors";
  } else {
    showToast(result.data?.message || "Delete failed.", "error");
  }
}

/* ── EDIT FORM ──────────────────────────────────────────── */

function initEditDoctorForm() {
  const form = document.getElementById("dp-edit-form");
  if (!form) return;
  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    const id = document.getElementById("dp-edit-id")?.value;
    const name = document.getElementById("dp-edit-name")?.value.trim();
    const email = document.getElementById("dp-edit-email")?.value.trim();
    const phone = document.getElementById("dp-edit-phone")?.value.trim();
    const departmentId = document.getElementById("dp-edit-department")?.value;

    if (!name || !email) { showToast("Name and email are required.", "error"); return; }

    const result = await apiFetch((getBasePath() + "api/doctors/update.php"), {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ id, name, email, phone, department_id: departmentId }),
    }, "Failed to update doctor details.");

    if (result.data?.success) {
      showToast("Doctor details updated successfully.", "success");
      closeDoctorEditModal();
      _loadedTabs = {};
      loadDoctorProfile(id);
    } else {
      showToast(result.data?.message || "Failed to update doctor details.", "error");
    }
  });
}

/* ── REASSIGN FORM ──────────────────────────────────────── */

function initDoctorReassignForm() {
  const form = document.getElementById("dp-reassign-form");
  if (!form) return;
  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    const doctorId = document.getElementById("dp-reassign-id")?.value;
    const departmentId = document.getElementById("dp-reassign-department")?.value;
    if (!doctorId || !departmentId) { showToast("Please select a department.", "error"); return; }

    const result = await apiFetch((getBasePath() + "api/departments/assign-doctor.php"), {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ doctor_id: doctorId, department_id: departmentId }),
    }, "Failed to reassign department.");

    if (result.data?.success) {
      showToast("Department reassigned successfully.", "success");
      closeDoctorReassignModal();
      _loadedTabs = {};
      loadDoctorProfile(doctorId);
    } else {
      showToast(result.data?.message || "Failed to reassign department.", "error");
    }
  });
}

/* ── BOOKING ─────────────────────────────────────────────── */

function toggleBookAppointmentModal() {
  const modal = document.getElementById("book-appointment-modal");
  if (!modal) return;
  modal.classList.toggle("open");
  if (modal.classList.contains("open")) {
    document.getElementById("admin-book-form")?.reset();
    const dateInput = document.getElementById("admin-appt-date");
    if (dateInput) dateInput.min = new Date().toISOString().split("T")[0];
    loadAdminDoctorDropdown();
    loadDepartmentsForBooking();
  }
}

async function loadAdminDoctorDropdown() {
  const doctorSelect = document.getElementById("admin-appt-doctor");
  const deptSelect = document.getElementById("admin-appt-department");
  if (!doctorSelect) return;
  try {
    const res = await fetch((getBasePath() + "api/doctors/get.php"));
    const result = await res.json();
    adminDoctorsList = result.doctors || result || [];
  } catch { adminDoctorsList = []; }
  renderAdminDoctorOptions();
  if (deptSelect) {
    deptSelect.removeEventListener("change", renderAdminDoctorOptions);
    deptSelect.addEventListener("change", renderAdminDoctorOptions);
  }
}

async function loadDepartmentsForBooking() {
  const deptSelect = document.getElementById("admin-appt-department");
  if (!deptSelect) return;
  const deptResult = await apiFetch((getBasePath() + "api/departments/get.php"), {}, "Failed to load departments.");
  if (deptResult.ok && deptResult.data?.departments) {
    deptSelect.innerHTML = '<option value="">Select department</option>';
    deptResult.data.departments.forEach(dept => {
      if (dept.status === 'active') {
        deptSelect.innerHTML += `<option value="${dept.id}">${escapeHTML(dept.name)}</option>`;
      }
    });
  }
}

function renderAdminDoctorOptions() {
  const doctorSelect = document.getElementById("admin-appt-doctor");
  const deptId = document.getElementById("admin-appt-department")?.value || "";
  if (!doctorSelect) return;
  doctorSelect.innerHTML = '<option value="">Select a doctor</option>';
  const filtered = deptId
    ? adminDoctorsList.filter((d) => d.department_id == deptId)
    : adminDoctorsList;
  filtered.forEach((d) => {
    const label = `${d.name} — ${d.specialty}${d.available == 0 ? " (Not available)" : ""}`;
    const opt = new Option(label, d.name);
    opt.disabled = d.available == 0;
    doctorSelect.add(opt);
  });
}

function initBookAppointmentModal() {
  const form = document.getElementById("admin-book-form");
  if (!form) return;
  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    const ctxId = parseInt(document.getElementById("admin-appt-context-id")?.value || "0");
    const patientIdInput = document.getElementById("admin-appt-patient-id");
    const patientId = patientIdInput ? parseInt(patientIdInput.value || "0") : 0;

    const data = {
      department_id: document.getElementById("admin-appt-department")?.value.trim() || "",
      doctor: document.getElementById("admin-appt-doctor")?.value.trim() || "",
      date: document.getElementById("admin-appt-date")?.value.trim() || "",
      time: document.getElementById("admin-appt-time")?.value.trim() || "",
      patientName: document.getElementById("admin-appt-patient")?.value.trim() || "",
      notes: document.getElementById("admin-appt-notes")?.value.trim() || "",
      patient_id: patientId,
    };

    if (!data.department || !data.doctor || !data.date || !data.time || !data.patientName) {
      showToast("Please fill in all required fields.", "error");
      return;
    }

    const submitBtn = form.querySelector('[type="submit"]');
    const originalText = submitBtn?.textContent || "Confirm Booking";
    setLoading(submitBtn, true, "Booking...");

    const result = await apiFetch((getBasePath() + "api/appointments/book.php"), {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(data),
    }, "Booking failed.");

    if (result.data?.success) {
      showToast("Appointment booked successfully!", "success");
      toggleBookAppointmentModal();
      if (ctxId && _currentDoctorData) {
        _loadedTabs = {};
        loadDoctorProfile(ctxId);
      }
    } else {
      showToast(result.data?.message || "Booking failed.", "error");
    }
    setLoading(submitBtn, false, originalText);
  });
}

function openBookingForDoctor() {
  const doc = window._currentDoctorProfile;
  if (!doc) return;
  document.getElementById("admin-appt-context-id").value = doc.id;
  document.getElementById("book-modal-title").innerHTML =
    `<i class="fas fa-calendar-days" aria-hidden="true"></i> Book Appointment with Dr. ${escapeHTML(doc.name)}`;
  toggleBookAppointmentModal();

  requestAnimationFrame(() => {
    const deptSel = document.getElementById("admin-appt-department");
    if (deptSel && doc.department_id) {
      deptSel.value = doc.department_id;
      deptSel.dispatchEvent(new Event("change"));
    }
    setTimeout(() => {
      const doctorSel = document.getElementById("admin-appt-doctor");
      if (doctorSel) {
        const opt = [...doctorSel.options].find((o) => o.text.startsWith(doc.name));
        if (opt) doctorSel.value = opt.value;
      }
    }, 400);
  });
}

// Expose to global scope for HTML onclick attributes
window.switchDoctorTab = switchDoctorTab;
window.editCurrentDoctor = editCurrentDoctor;
window.closeDoctorEditModal = closeDoctorEditModal;
window.reassignCurrentDoctorDepartment = reassignCurrentDoctorDepartment;
window.closeDoctorReassignModal = closeDoctorReassignModal;
window.toggleCurrentDoctorStatus = toggleCurrentDoctorStatus;
window.deleteCurrentDoctor = deleteCurrentDoctor;
window.openBookingForDoctor = openBookingForDoctor;
window.toggleBookAppointmentModal = toggleBookAppointmentModal;





