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

  attachDoctorProfileActions();
  setText("admin-name", user.name || "Admin");
  setText("admin-email", user.email || "admin@healthbridge.com");

  // Read doctor ID from URL
  const params = new URLSearchParams(window.location.search);
  const doctorId = safePositiveId(params.get("id"));

  if (!doctorId || doctorId <= 0) {
    showError("Invalid Doctor ID", "No valid doctor ID was provided in the URL.");
    return;
  }

  // Hide error state initially, show loading
  document.getElementById("dp-error").classList.add("is-hidden");
  document.getElementById("dp-header-card").classList.add("is-hidden");
  document.getElementById("dp-tabs").classList.add("is-hidden");
  document.getElementById("dp-tab-contents").classList.add("is-hidden");

  await loadDoctorProfile(doctorId);
  initEditDoctorForm();
  initDoctorReassignForm();
  initBookAppointmentModal();
});

/**
 * Bind page-owned actions through one delegated listener. The admin role check
 * completes before the listener is attached; action values are fixed allowlist
 * tokens rather than executable markup.
 */
function attachDoctorProfileActions() {
  if (window._hbDoctorProfileActionsBound) return;

  document.addEventListener("click", (event) => {
    const target = event.target instanceof Element ? event.target : null;
    if (!target) return;

    const tabControl = target.closest("[data-doctor-profile-tab]");
    if (tabControl) {
      event.preventDefault();
      const tabId = tabControl.dataset.doctorProfileTab;
      if (["overview", "professional", "schedule", "appointments", "reviews", "audit"].includes(tabId)) {
        switchDoctorTab(tabId);
      }
      return;
    }

    const actionControl = target.closest("[data-doctor-profile-action]");
    if (!actionControl) return;

    switch (actionControl.dataset.doctorProfileAction) {
      case "edit-doctor":
        editCurrentDoctor();
        break;
      case "reassign-doctor":
        reassignCurrentDoctorDepartment();
        break;
      case "toggle-status":
        toggleCurrentDoctorStatus();
        break;
      case "open-booking":
        openBookingForDoctor();
        break;
      case "delete-doctor":
        deleteCurrentDoctor();
        break;
      case "close-edit":
        closeDoctorEditModal();
        break;
      case "close-reassign":
        closeDoctorReassignModal();
        break;
      case "toggle-book-modal":
        toggleBookAppointmentModal();
        break;
    }
  });

  window._hbDoctorProfileActionsBound = true;
}

/**
 * Show a professional error state.
 */
function showError(title, message) {
  document.getElementById("dp-error-title").textContent = title;
  document.getElementById("dp-error-message").textContent = message;
  document.getElementById("dp-error").classList.remove("is-hidden");
  document.getElementById("dp-header-card").classList.add("is-hidden");
  document.getElementById("dp-tabs").classList.add("is-hidden");
  document.getElementById("dp-tab-contents").classList.add("is-hidden");
}

/**
 * Load and render the full doctor profile.
 * Reuses get_doctor_profile.php which is admin-protected and returns
 * doctor data, appointments, AND reviews — avoiding separate doctor-only endpoints.
 */
async function loadDoctorProfile(id) {
  const safeDoctorId = safePositiveId(id);
  if (!safeDoctorId) {
    showError("Invalid Doctor ID", "No valid doctor ID was provided in the URL.");
    return;
  }
  _profileDoctorId = safeDoctorId;
  _loadedTabs = {};

  // Load departments for modals
  _profileDepartments = [];
  const deptResult = await apiFetch((getBasePath() + "api/departments/get.php"), {}, "Failed to load departments.");
  if (deptResult.ok && Array.isArray(deptResult.data?.departments)) {
    _profileDepartments = deptResult.data.departments.filter(department =>
      department && typeof department === "object" && !Array.isArray(department)
      && department.status === "active"
      && safePositiveId(department.id) > 0
      && typeof department.name === "string"
    );
  }

  const result = await apiFetch(
    `${getBasePath()}api/doctors/profile.php?id=${safeDoctorId}`,
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
  document.getElementById("dp-error").classList.add("is-hidden");
  document.getElementById("dp-header-card").classList.remove("is-hidden");
  document.getElementById("dp-tabs").classList.remove("is-hidden");
  document.getElementById("dp-tab-contents").classList.remove("is-hidden");

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
  const reviews = Array.isArray(data.reviews) ? data.reviews : [];
  const totalReviews = reviews.length;
  const validReviewStars = reviews
    .map((review) => Number(review.stars))
    .filter((stars) => Number.isInteger(stars) && stars >= 1 && stars <= 5);
  const averageRating = validReviewStars.length
    ? validReviewStars.reduce((sum, stars) => sum + stars, 0) / validReviewStars.length
    : null;
  const ratingLabel = averageRating === null ? "No ratings yet" : averageRating.toFixed(1);
  setText("dp-rating-display", `${ratingLabel}${averageRating === null ? "" : " ★"}`);
  setText("dp-overview-rating", averageRating === null ? "—" : ratingLabel);
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
    <div class="dp-section" data-csp-style="profile-0035580c7512">
      <h4 class="dp-section-title"><i class="fas fa-pen" aria-hidden="true"></i> Edit Weekly Schedule</h4>
      <div id="dp-schedule-editor"></div>
    </div>
    <div class="dp-section" data-csp-style="profile-0035580c7512">
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
      getBasePath() + "api/schedule/get.php?doctor_id=" + encodeURIComponent(_profileDoctorId),
      {},
      "Failed to load schedule."
    );

    if (!result.ok || !result.data?.success) {
      summaryEl.innerHTML = `
        <div class="dp-schedule-summary-inner">
          <div class="dp-summary-item"><span class="dp-summary-label">Availability</span><span class="dp-summary-value" data-csp-style="profile-5872de20d564">No schedule</span></div>
        </div>
      `;
      return;
    }

    const sched = result.data.schedule;
    const settings = sched.settings || {};
    const weekly = Array.isArray(sched.weekly)
      ? sched.weekly.filter((day) => day && typeof day === "object" && !Array.isArray(day))
      : [];
    const workingDays = weekly.filter(d => parseInt(d.is_working) === 1);
    const rawDuration = safeCount(settings.appointment_duration, 30);
    const appointmentDuration = rawDuration >= 1 && rawDuration <= 240 ? rawDuration : 30;
    const rawDailyLimit = safeCount(settings.max_appointments_per_day, 25);
    const dailyLimit = rawDailyLimit >= 1 && rawDailyLimit <= 1000 ? rawDailyLimit : 25;
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
          <span class="dp-summary-value">${appointmentDuration} minutes</span>
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
          <span class="dp-summary-value">${dailyLimit}</span>
        </div>
      </div>
      <div class="dp-schedule-working-days">
        ${workingDays.map(d => {
          const dayNames = {1:"Mon",2:"Tue",3:"Wed",4:"Thu",5:"Fri",6:"Sat",7:"Sun"};
          const dayFull = {1:"Monday",2:"Tuesday",3:"Wednesday",4:"Thursday",5:"Friday",6:"Saturday",7:"Sunday"};
          const dayNumber = safePositiveId(d.day_of_week);
          const name = dayNames[dayNumber] || "Unknown";
          const fullName = dayFull[dayNumber] || "Unknown";
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

    const entries = result.ok && Array.isArray(result.data?.entries)
      ? result.data.entries.filter((entry) => entry && typeof entry === "object" && !Array.isArray(entry))
      : [];

    // Filter for schedule-related actions (update_schedule)
    const scheduleEntries = entries.filter(e => {
      const action = typeof e.action === "string" ? e.action.toLowerCase() : "";
      return action.includes("schedule");
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
      const statusLower = safeClassToken(a.status || "pending");
      const patientId = safePositiveId(a.patient_id);
      const patientDisplay = patientId
        ? `<a href="${getBasePath()}pages/patient/patient-emr.html?patient_id=${patientId}" data-csp-style="profile-5a74e195054b">${escapeHTML(a.patient_name)}</a>`
        : `<span data-csp-style="profile-eed0f8fb89cd">${escapeHTML(a.patient_name)}</span>`;
      return `<tr>
        <td>${patientDisplay}</td>
        <td data-csp-style="profile-1e8f47ba0c0b">${escapeHTML(formatDate(a.date))} ${escapeHTML(formatApptTime(a))}</td>
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
    <thead><tr><th>Patient</th><th>Rating</th><th>Visibility</th><th>Comment</th><th>Date</th></tr></thead>
    <tbody>${reviews.map(r => {
      const parsedStars = safeCount(r.stars, 0);
      const hasValidStars = parsedStars >= 1 && parsedStars <= 5;
      const starsVal = hasValidStars ? parsedStars : 0;
      const starDisplay = hasValidStars
        ? `<span data-csp-style="profile-52df2b00a094">${Array(starsVal).fill('<i class="fas fa-star" aria-hidden="true"></i>').join('')}${Array(5 - starsVal).fill('<i class="far fa-star" aria-hidden="true"></i>').join('')}</span>`
        : '<span data-csp-style="profile-5872de20d564">Not rated</span>';
      const comment = r.review
        ? escapeHTML(r.review)
        : '<span data-csp-style="profile-e0086d5c9f97">No written comment.</span>';
      const visibility = Number(r.is_hidden) === 1
        ? '<span class="status status-cancelled">Hidden</span>'
        : '<span class="status status-confirmed">Public</span>';
      return `<tr>
        <td><div data-csp-style="profile-eed0f8fb89cd">${escapeHTML(r.patient_name || 'Unknown')}</div></td>
        <td>${starDisplay}${hasValidStars ? ` (${starsVal}/5)` : ""}</td>
        <td>${visibility}</td>
        <td data-csp-style="profile-312d54ef958b">${comment}</td>
        <td data-csp-style="profile-c9565925f3a7">${escapeHTML(formatDate(r.review_date))}</td>
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
    const entries = result.ok && Array.isArray(result.data?.entries)
      ? result.data.entries.filter((entry) => entry && typeof entry === "object" && !Array.isArray(entry))
      : [];
    if (entries.length === 0) {
      container.innerHTML = '<div class="empty-state"><i class="fas fa-history" aria-hidden="true"></i><p>No administrative activity recorded for this doctor.</p></div>';
      return;
    }
    const actionIcons = { create:'fa-plus-circle', update:'fa-pen', update_schedule:'fa-calendar', delete:'fa-trash', activate:'fa-toggle-on', deactivate:'fa-toggle-off', reassign:'fa-right-left', book:'fa-calendar-plus', approve:'fa-check', decline:'fa-ban' };
    const roleBadge = (role) => {
      if (role === 'doctor') return '<span class="dp-role-badge dp-role-doctor" data-csp-style="profile-af88b74636a8">Doctor</span>';
      if (role === 'admin') return '<span class="dp-role-badge dp-role-admin" data-csp-style="profile-99bda4f95b52">Admin</span>';
      return '';
    };
    const html = entries.map(e => {
      const action = typeof e.action === "string" ? e.action : "";
      const icon = Object.prototype.hasOwnProperty.call(actionIcons, action)
        ? actionIcons[action]
        : "fa-circle";
      const desc = e.description
        ? escapeHTML(e.description)
        : `${escapeHTML(action)} on ${escapeHTML(e.entity_type)}`;
      return `<div class="dp-audit-entry">
        <div class="dp-audit-icon"><i class="fas ${icon}" aria-hidden="true"></i></div>
        <div class="dp-audit-body">
          <div class="dp-audit-action">${escapeHTML(e.actor_name || 'System')} ${roleBadge(e.actor_role)}</div>
          <div class="dp-audit-description" data-csp-style="profile-7658f09f4074">${desc}</div>
          <div class="dp-audit-meta" data-csp-style="profile-4416f979f7e2">${escapeHTML(formatDate(e.created_at))}</div>
        </div>
      </div>`;
    }).join('');
    container.innerHTML = html;
  } catch (e) {
    container.innerHTML = '<div class="empty-state"><i class="fas fa-exclamation-triangle" aria-hidden="true"></i><p>Failed to load audit log.</p></div>';
  }
}

/* ── PROFILE ACTIONS ────────────────────────────────────── */

function populateDoctorProfileDepartmentSelect(select, currentDepartmentId) {
  if (!select) return;

  select.replaceChildren(new Option("Select department", ""));
  const selectedId = safePositiveId(currentDepartmentId);
  const departments = Array.isArray(_profileDepartments) ? _profileDepartments : [];

  departments.forEach(department => {
    if (!department || typeof department !== "object" || Array.isArray(department)) return;
    const departmentId = safePositiveId(department.id);
    if (!departmentId || typeof department.name !== "string") return;

    const option = new Option(department.name, String(departmentId));
    option.selected = departmentId === selectedId;
    select.add(option);
  });
}

function editCurrentDoctor() {
  if (!_currentDoctorData) return;
  const doc = _currentDoctorData.doctor;
  document.getElementById("dp-edit-id").value = doc.id;
  document.getElementById("dp-edit-name").value = doc.name || "";
  document.getElementById("dp-edit-email").value = doc.email || "";
  document.getElementById("dp-edit-phone").value = doc.phone || "";

  const deptSelect = document.getElementById("dp-edit-department");
  populateDoctorProfileDepartmentSelect(deptSelect, doc.department_id);
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
  populateDoctorProfileDepartmentSelect(deptSelect, doc.department_id);
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
    ? `Deactivate Dr. ${doc.name}? Sign-in will be revoked, not-started appointments will be cancelled, and an active clinical visit must be completed first.`
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
  if (!confirm(`Archive Dr. ${doc.name}? Sign-in will be disabled and not-started appointments will be cancelled. Clinical and audit history will be retained.`)) return;

  const result = await apiFetch((getBasePath() + "api/doctors/delete.php"), {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ id: doc.id }),
  }, "Archive failed.");

  if (result.data?.success) {
    showToast(`Dr. ${doc.name} has been archived. Clinical and audit history was retained.`, "success");
    window.location.href = getBasePath() + "pages/admin/admin.html#doctors";
  } else {
    showToast(result.data?.message || "Archive failed.", "error");
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
    return Promise.all([loadAdminDoctorDropdown(), loadDepartmentsForBooking()]);
  }
}

async function loadAdminDoctorDropdown() {
  const doctorSelect = document.getElementById("admin-appt-doctor");
  const deptSelect = document.getElementById("admin-appt-department");
  if (!doctorSelect) return;
  try {
    const res = await fetch((getBasePath() + "api/doctors/get.php"));
    const result = await res.json();
    adminDoctorsList = Array.isArray(result.doctors)
      ? result.doctors.filter(doctor => doctor && typeof doctor === "object" && !Array.isArray(doctor))
      : [];
    if (!Array.isArray(result.doctors)) {
      throw new Error("The doctor directory returned an invalid response.");
    }
  } catch {
    adminDoctorsList = [];
    showToast("Doctor list could not be loaded. Please try again.", "error");
  }
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
  if (deptResult.ok && Array.isArray(deptResult.data?.departments)) {
    const departments = deptResult.data.departments.filter(department =>
      department && typeof department === "object" && !Array.isArray(department)
      && department.status === "active"
      && safePositiveId(department.id) > 0
      && typeof department.name === "string"
    );
    deptSelect.replaceChildren(new Option("Select department", ""));
    departments.forEach(department => {
      deptSelect.add(new Option(department.name, String(safePositiveId(department.id))));
    });
  }
}

function renderAdminDoctorOptions() {
  const doctorSelect = document.getElementById("admin-appt-doctor");
  const departmentSelect = document.getElementById("admin-appt-department");
  const deptId = departmentSelect?.value || "";
  if (!doctorSelect) return;
  doctorSelect.replaceChildren(new Option("Select a doctor", ""));
  const departmentName = departmentSelect?.selectedOptions[0]?.textContent?.trim() || "";
  const selectedDepartmentId = safePositiveId(deptId);
  const doctorObjects = Array.isArray(adminDoctorsList)
    ? adminDoctorsList.filter(doctor => doctor && typeof doctor === "object" && !Array.isArray(doctor))
    : [];
  const filtered = deptId
    ? (selectedDepartmentId
      ? doctorObjects.filter((doctor) => {
          const doctorDepartmentId = safePositiveId(doctor.department_id);
          if (doctorDepartmentId) return doctorDepartmentId === selectedDepartmentId;
          const specialty = typeof doctor.specialty === "string" ? doctor.specialty.trim() : "";
          return specialty.toLowerCase() === departmentName.toLowerCase();
        })
      : [])
    : doctorObjects;
  const eligibleDoctors = filtered.filter((doctor) => safePositiveId(doctor.user_id) > 0);
  eligibleDoctors.forEach((doctor) => {
    const doctorId = safePositiveId(doctor.user_id);
    const doctorName = typeof doctor.name === "string" && doctor.name ? doctor.name : "Unnamed doctor";
    const specialty = typeof doctor.specialty === "string" ? doctor.specialty : "";
    const label = `${doctorName} — ${specialty}${doctor.available == 0 ? " (Not available)" : ""}`;
    const option = new Option(label, String(doctorId));
    option.disabled = doctor.available == 0;
    doctorSelect.add(option);
  });
  if (eligibleDoctors.length === 0) {
    const option = new Option("No bookable doctors found for this department", "");
    option.disabled = true;
    doctorSelect.add(option);
  }
}

function initBookAppointmentModal() {
  const form = document.getElementById("admin-book-form");
  if (!form) return;
  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    const ctxId = parseInt(document.getElementById("admin-appt-context-id")?.value || "0");
    const patientIdInput = document.getElementById("admin-appt-patient-id");
    const patientId = patientIdInput ? parseInt(patientIdInput.value || "0") : 0;

    const selectedDoctorId = Number.parseInt(document.getElementById("admin-appt-doctor")?.value || "0", 10);
    const selectedDoctor = adminDoctorsList.find((doctor) => Number(doctor.user_id) === selectedDoctorId);
    const data = {
      department_id: document.getElementById("admin-appt-department")?.value.trim() || "",
      doctor_id: selectedDoctorId,
      doctor: selectedDoctor?.name || "",
      date: document.getElementById("admin-appt-date")?.value.trim() || "",
      time: document.getElementById("admin-appt-time")?.value.trim() || "",
      patientName: document.getElementById("admin-appt-patient")?.value.trim() || "",
      notes: document.getElementById("admin-appt-notes")?.value.trim() || "",
      patient_id: patientId,
    };

    if (!data.department_id || !data.doctor_id || !data.doctor || !data.date || !data.time || !data.patientName) {
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

async function openBookingForDoctor() {
  const doc = window._currentDoctorProfile;
  if (!doc) return;
  document.getElementById("admin-appt-context-id").value = doc.id;
  const title = document.getElementById("book-modal-title");
  if (title) {
    const icon = document.createElement("i");
    icon.className = "fas fa-calendar-days";
    icon.setAttribute("aria-hidden", "true");
    const doctorName = typeof doc.name === "string" ? doc.name : "Unknown";
    title.replaceChildren(icon, document.createTextNode(" Book Appointment with Dr. " + doctorName));
  }

  // Wait for the department and doctor options before applying profile defaults.
  await toggleBookAppointmentModal();

  const departmentSelect = document.getElementById("admin-appt-department");
  const departmentId = safePositiveId(doc.department_id);
  if (departmentSelect && departmentId) {
    departmentSelect.value = String(departmentId);
    departmentSelect.dispatchEvent(new Event("change", { bubbles: true }));
  }

  const doctorSelect = document.getElementById("admin-appt-doctor");
  const doctorId = safePositiveId(doc.id);
  if (doctorSelect && doctorId) {
    const option = [...doctorSelect.options].find((candidate) =>
      candidate.value === String(doctorId) && !candidate.disabled
    );
    if (option) doctorSelect.value = option.value;
  }
}

// Preserve legacy global entry points for other page integrations.
window.switchDoctorTab = switchDoctorTab;
window.editCurrentDoctor = editCurrentDoctor;
window.closeDoctorEditModal = closeDoctorEditModal;
window.reassignCurrentDoctorDepartment = reassignCurrentDoctorDepartment;
window.closeDoctorReassignModal = closeDoctorReassignModal;
window.toggleCurrentDoctorStatus = toggleCurrentDoctorStatus;
window.deleteCurrentDoctor = deleteCurrentDoctor;
window.openBookingForDoctor = openBookingForDoctor;
window.toggleBookAppointmentModal = toggleBookAppointmentModal;





