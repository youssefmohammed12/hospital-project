/**
 * HealthBridge - Doctor Dashboard JavaScript (Phase 3 & Refactor)
 * Commercial EMR Dashboard launchpad for Clinical Workspace & Patient Queue.
 *
 * Uses: apiFetch(), renderTable(), initTabNavigation(), filterData(),
 *       escapeHTML(), formatDate(), formatApptTime(), showToast(),
 *       requireServerRole(), getBasePath() from main.js
 */

"use strict";

let allAppointments = [];
let doctorUser = null;

document.addEventListener("DOMContentLoaded", async () => {
  doctorUser = await requireServerRole("doctor");
  if (!doctorUser) return;

  // Initialize UI Text & User Identity
  initDoctorHeader(doctorUser);

  // Bind Event Listeners (Zero Inline Handlers)
  bindDashboardEvents();

  // Initialize Tab Navigation
  initTabNavigation("hb_doctor_active_tab");

  // Load All Core Data
  await refreshDashboardData();

  // Initialize Schedule Manager
  if (window.ScheduleManager) {
    ScheduleManager.init("doctor-schedule-container", "doctor", doctorUser.id);
  }

  // Initialize Notification Service
  if (window.NotificationService) {
    NotificationService.init((unreadCount) => {
      updateNotificationBadge(unreadCount);
    });
    NotificationService.initDropdown();
  }
});

function initDoctorHeader(user) {
  const doctorName = typeof user?.name === "string" && user.name.trim() ? user.name : "Doctor";
  const doctorEmail = typeof user?.email === "string" && user.email.trim() ? user.email : "doctor@healthbridge.com";
  const doctorDepartment = typeof user?.department === "string" && user.department.trim()
    ? user.department
    : "";
  const doctorSpecialty = typeof user?.specialty === "string" && user.specialty.trim()
    ? user.specialty
    : "Senior Specialist";
  const doctorPhone = typeof user?.phone === "string" && user.phone.trim() ? user.phone : "-";

  setText("doctor-sidebar-name", doctorName);
  setText("doctor-sidebar-email", doctorEmail);
  setText("doctor-sidebar-dept", doctorDepartment || doctorSpecialty || "Medical Specialist");
  setText("doctor-profile-email-val", doctorEmail);
  setText("doctor-profile-phone-val", doctorPhone);

  const avatarText = getInitials(doctorName);
  const sidebarAvatar = document.getElementById("doctor-sidebar-avatar");
  if (sidebarAvatar) sidebarAvatar.textContent = avatarText;

  const profileAvatarLarge = document.getElementById("doctor-profile-avatar-large");
  if (profileAvatarLarge) profileAvatarLarge.textContent = avatarText;

  const welcome = document.getElementById("doctor-welcome-name");
  if (welcome) {
    const icon = document.createElement("i");
    icon.className = "fas fa-stethoscope";
    icon.setAttribute("aria-hidden", "true");
    welcome.replaceChildren(icon, document.createTextNode(` Welcome, ${doctorName}`));
  }

  const profileName = document.getElementById("doctor-profile-fullname");
  if (profileName) profileName.textContent = doctorName;

  const profileDept = document.getElementById("doctor-profile-dept-spec");
  if (profileDept) {
    profileDept.textContent = `${doctorDepartment || "General Practice"} • ${doctorSpecialty}`;
  }

  const dateEl = document.getElementById("doctor-hero-date");
  if (dateEl) {
    const todayStr = new Date().toLocaleDateString("en-US", {
      weekday: "long",
      year: "numeric",
      month: "long",
      day: "numeric"
    });
    const icon = document.createElement("i");
    icon.className = "far fa-calendar-alt";
    icon.setAttribute("aria-hidden", "true");
    dateEl.replaceChildren(icon, document.createTextNode(` ${todayStr}`));
  }
}

function getInitials(name) {
  if (typeof name !== "string" || !name.trim()) return "DR";
  const parts = name.replace(/^Dr\.\s*/i, "").trim().split(/\s+/);
  if (parts.length >= 2) {
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
  }
  return name.substring(0, 2).toUpperCase();
}

function setText(id, text) {
  const el = document.getElementById(id);
  if (el) el.textContent = text;
}

function getDoctorWorkspaceUrl(appointment) {
  const patientId = safePositiveId(appointment?.user_id);
  const appointmentId = safePositiveId(appointment?.id);
  if (!patientId || !appointmentId) return "#";

  const params = new URLSearchParams({
    patient_id: String(patientId),
    appointment_id: String(appointmentId),
  });
  return `${getBasePath()}pages/doctor/doctor-workspace.html?${params.toString()}`;
}

/* ============================================================
   EVENT BINDING & DELEGATION (Zero Inline Handlers)
   ============================================================ */

function bindDashboardEvents() {
  // Search & Filters
  const searchInput = document.getElementById("doctor-appt-search");
  if (searchInput) {
    let debounceTimer;
    searchInput.addEventListener("input", () => {
      clearTimeout(debounceTimer);
      debounceTimer = setTimeout(filterDoctorAppointments, 250);
    });
  }

  const statusFilter = document.getElementById("doctor-status-filter");
  if (statusFilter) {
    statusFilter.addEventListener("change", filterDoctorAppointments);
  }

  // Refresh Button
  const refreshBtn = document.getElementById("doctor-refresh-btn");
  if (refreshBtn) {
    refreshBtn.addEventListener("click", refreshDashboardData);
  }

  // Availability Switch
  const availSwitch = document.getElementById("avail-toggle-switch");
  if (availSwitch) {
    availSwitch.addEventListener("change", handleAvailabilityToggle);
  }

  // Logout Button
  const logoutBtn = document.getElementById("doctor-logout-btn");
  if (logoutBtn) {
    logoutBtn.addEventListener("click", (e) => {
      e.preventDefault();
      if (typeof window.logoutUser === "function") window.logoutUser();
    });
  }

  // Notification Controls
  const notifFilter = document.getElementById("doctor-notif-filter");
  if (notifFilter) {
    notifFilter.addEventListener("change", loadDoctorNotifications);
  }

  const markAllReadBtn = document.getElementById("mark-all-read-btn");
  if (markAllReadBtn) {
    markAllReadBtn.addEventListener("click", async () => {
      if (window.NotificationService) {
        await NotificationService.markAllAsRead();
        showToast("All notifications marked as read.", "success");
        loadDoctorNotifications();
      }
    });
  }

  // KPI Card Clicks -> Switch Tab
  document.querySelectorAll(".doctor-stat-card.clickable[data-tab]").forEach((card) => {
    card.addEventListener("click", () => {
      const tabId = card.getAttribute("data-tab");
      if (tabId && typeof window.switchTab === "function") {
        window.switchTab(tabId);
      }
    });
  });

  // Settings Form Submit
  const settingsForm = document.getElementById("doctor-settings-form");
  if (settingsForm) {
    settingsForm.addEventListener("submit", (e) => {
      e.preventDefault();
      showToast("Doctor settings saved successfully.", "success");
    });
  }

  // Delegated Appointment Action Handlers
  const apptContainer = document.getElementById("appointments-container");
  if (apptContainer) {
    apptContainer.addEventListener("click", handleApptTableActions);
  }

  const queueContainer = document.getElementById("dashboard-queue-container");
  if (queueContainer) {
    queueContainer.addEventListener("click", handleApptTableActions);
  }

  const fullQueueContainer = document.getElementById("queue-full-container");
  if (fullQueueContainer) {
    fullQueueContainer.addEventListener("click", handleApptTableActions);
  }
}

async function refreshDashboardData() {
  showToast("Updating EMR data...", "info");
  await Promise.all([
    loadDoctorAvailability(),
    loadDoctorAppointments(),
    loadDoctorRatings(),
    loadDoctorNotifications()
  ]);
}

/* ============================================================
   APPOINTMENTS & QUEUE MANAGEMENT
   ============================================================ */

async function loadDoctorAppointments() {
  const result = await apiFetch(getBasePath() + "api/appointments/get-doctor.php", {}, "Failed to load appointments");
  if (!result.ok || !result.data?.success) {
    showToast(result.data?.message || "Failed to load appointments", "error");
    return;
  }

  allAppointments = Array.isArray(result.data.appointments)
    ? result.data.appointments.filter((appointment) =>
        appointment && typeof appointment === "object" && !Array.isArray(appointment)
      )
    : [];

  // Update KPI Cards
  const pendingCount = allAppointments.filter(a => a.status === "Pending").length;
  const confirmedCount = allAppointments.filter(a => a.status === "Confirmed").length;
  const completedCount = allAppointments.filter(a => a.visit_workflow?.status === "Completed").length;
  const activeCount = allAppointments.filter(a => a.visit_workflow?.status === "In Progress").length;
  const pendingNotes = allAppointments.filter(a => a.status === "Confirmed" && !a.has_visit_note).length;

  setText("total-appts", allAppointments.length);
  setText("pending-appts", pendingCount);
  setText("confirmed-appts", confirmedCount);
  setText("active-visits-count", activeCount);
  setText("completed-visits-count", completedCount);
  setText("pending-notes-count", pendingNotes);
  setText("doctor-profile-total-visits", completedCount || confirmedCount);

  // Render Dashboard Views
  filterDoctorAppointments();
  renderPatientQueue();
  renderTodayTimeline();
  renderRecentActivity();
}

function filterDoctorAppointments() {
  const query = document.getElementById("doctor-appt-search")?.value || "";
  const status = document.getElementById("doctor-status-filter")?.value || "";
  let filtered = allAppointments;
  filtered = filterData(filtered, query, ["patient_name", "notes", "department"]);
  if (status) filtered = filtered.filter(a =>
    (typeof a.status === "string" ? a.status : "").toLowerCase() === status.toLowerCase()
  );
  renderDoctorAppointments(filtered);
}

function renderDoctorAppointments(appointments) {
  const container = document.getElementById("appointments-container");
  if (!container) return;

  if (appointments.length === 0) {
    container.innerHTML = '<p class="doctor-dashboard-empty--secondary">No matching appointments found.</p>';
    return;
  }

  const rows = appointments.map((appt) => {
    const statusText = typeof appt.status === "string" ? appt.status : "Unknown";
    const sc = safeClassToken(statusText || "pending");
    const isPending = statusText === "Pending";
    const isConfirmed = statusText === "Confirmed";
    const workspaceUrl = getDoctorWorkspaceUrl(appt);
    const safeAppointmentId = safePositiveId(appt.id);

    const wf = appt.visit_workflow && typeof appt.visit_workflow === "object"
      && !Array.isArray(appt.visit_workflow)
      ? appt.visit_workflow
      : null;
    const wfStatus = typeof wf?.status === "string" ? wf.status : null;
    const wfClass = wfStatus ? safeClassToken(wfStatus.replace(/\s+/g, '_')) : '';

    const bookingStatusBadge = `<span class="status status-${sc}">${escapeHTML(statusText)}</span>`;

    let clinicalStatusBadge = '<span class="doctor-dashboard-muted">-</span>';
    if (isConfirmed && wfStatus) {
      clinicalStatusBadge = `<span class="status status-${wfClass}">${escapeHTML(wfStatus)}</span>`;
    }

    const hasReschedule = appt.reschedule_status === "pending";
    const rescheduleInfo = hasReschedule ?
      `<div class="doctor-dashboard-rating-note">
        <i class="fas fa-clock"></i> Reschedule Request: ${escapeHTML(appt.pending_reschedule_date || '')} ${escapeHTML(appt.pending_reschedule_time || '')}
      </div>` : '';

    let actions = "";
    if (safeAppointmentId && isPending) {
      actions = `
        <button type="button" class="btn btn-success btn-xs" data-action="accept" data-id="${safeAppointmentId}"><i class="fas fa-check"></i> Accept</button>
        <button type="button" class="btn btn-outline btn-xs doctor-dashboard-danger" data-action="reject" data-id="${safeAppointmentId}"><i class="fas fa-times"></i> Decline</button>
      `;
    } else if (safeAppointmentId && isConfirmed) {
      actions = `
        ${workspaceUrl !== "#" ? `<a href="${workspaceUrl}" class="btn btn-primary btn-xs"><i class="fas fa-folder-open"></i> Open EMR</a>` : ""}
        <button type="button" class="btn btn-outline btn-xs" data-action="suggest-reschedule" data-id="${safeAppointmentId}"><i class="fas fa-calendar-plus"></i> Reschedule</button>
      `;
    }

    if (safeAppointmentId && hasReschedule) {
      actions += `
        <button type="button" class="btn btn-success btn-xs" data-action="approve-reschedule" data-id="${safeAppointmentId}"><i class="fas fa-check"></i> Approve Time</button>
        <button type="button" class="btn btn-outline btn-xs" data-action="reject-reschedule" data-id="${safeAppointmentId}"><i class="fas fa-times"></i> Reject</button>
      `;
    }

    return `<tr>
      <td class="doctor-dashboard-font-semibold">${escapeHTML(appt.patient_name || "Patient")} ${rescheduleInfo}</td>
      <td>${escapeHTML(formatDate(appt.date))}</td>
      <td>${escapeHTML(formatApptTime(appt))}</td>
      <td>${escapeHTML(appt.department || "-")}</td>
      <td>${bookingStatusBadge}</td>
      <td>${clinicalStatusBadge}</td>
      <td title="${escapeHTML(appt.notes || "")}" class="doctor-dashboard-notes-cell">${escapeHTML(appt.notes || "-")}</td>
      <td><div class="doctor-dashboard-inline-actions">${actions}</div></td>
    </tr>`;
  }).join("");

  container.innerHTML = `
    <div class="table-wrap doctor-table-wrap--flush">
      <table class="doctor-dashboard-table--full-width">
        <thead><tr><th>Patient</th><th>Date</th><th>Time</th><th>Department</th><th>Booking Status</th><th>Clinical Workflow</th><th>Notes</th><th>Actions</th></tr></thead>
        <tbody>${rows}</tbody>
      </table>
    </div>`;
}

function handleApptTableActions(e) {
  const btn = e.target.closest("button[data-action]");
  if (!btn) return;

  const action = btn.getAttribute("data-action");
  const id = safePositiveId(btn.getAttribute("data-id"));
  if (!id) return;

  if (action === "accept") doctorUpdateAppointment(id, "accept");
  else if (action === "reject") doctorUpdateAppointment(id, "reject");
  else if (action === "approve-reschedule") doctorApproveReschedule(id);
  else if (action === "reject-reschedule") doctorRejectReschedule(id);
  else if (action === "suggest-reschedule") doctorSuggestReschedule(id);
}

/* ============================================================
   PATIENT QUEUE & TODAY'S TIMELINE
   ============================================================ */

function renderPatientQueue() {
  const dashboardContainer = document.getElementById("dashboard-queue-container");
  const fullContainer = document.getElementById("queue-full-container");

  const pendingAndConfirmed = allAppointments.filter(a => a.status === "Pending" || a.status === "Confirmed");

  if (pendingAndConfirmed.length === 0) {
    const emptyHtml = '<p class="text-muted text-center doctor-dashboard-empty--large">No patients currently waiting in queue.</p>';
    if (dashboardContainer) dashboardContainer.innerHTML = emptyHtml;
    if (fullContainer) fullContainer.innerHTML = emptyHtml;
    return;
  }

  const renderQueueItem = (appt) => {
    const isPending = appt.status === "Pending";
    const workflowStatus = appt.visit_workflow && typeof appt.visit_workflow === "object"
      && typeof appt.visit_workflow.status === "string"
      ? appt.visit_workflow.status
      : "";
    const wfStatus = workflowStatus || (isPending ? "Waiting" : "Confirmed");
    const workspaceUrl = getDoctorWorkspaceUrl(appt);
    const safePatientId = safePositiveId(appt.user_id);
    const patientName = typeof appt.patient_name === "string" ? appt.patient_name : "Patient";
    const initials = getInitials(patientName);
    const notesText = typeof appt.notes === "string" ? appt.notes : "";
    const hasAllergy = notesText.toLowerCase().includes("allerg");
    const consultAction = workspaceUrl !== "#"
      ? `<a href="${workspaceUrl}" class="btn btn-primary btn-xs doctor-dashboard-open-link"><i class="fas fa-stethoscope"></i> Consult</a>`
      : '<span class="text-muted">Unavailable</span>';

    return `
      <div class="queue-card-item">
        <div class="queue-card-avatar">${escapeHTML(initials)}</div>
        <div class="queue-card-info">
          <div class="queue-card-name">${escapeHTML(patientName)} <span class="ws-sticky-id">#MRN-${safePatientId || '00'}</span></div>
          <div class="queue-card-meta">
            <span><i class="far fa-clock"></i> ${escapeHTML(formatApptTime(appt))}</span>
            <span><i class="fas fa-hospital-user"></i> ${escapeHTML(appt.department || "General")}</span>
          </div>
          ${notesText ? `<div class="queue-card-reason"><i class="fas fa-comment-medical"></i> ${escapeHTML(notesText)}</div>` : ''}
          <div class="queue-card-badge-row">
            ${hasAllergy ? '<span class="queue-chip-danger"><i class="fas fa-allergies"></i> Allergy Alert</span>' : ''}
            ${isPending ? '<span class="queue-chip-warning"><i class="fas fa-hourglass-half"></i> Pending Review</span>' : ''}
          </div>
        </div>
        <div class="queue-card-status">
          <span class="badge ${isPending ? 'badge-warning' : 'badge-primary'}">${escapeHTML(wfStatus)}</span>
          ${consultAction}
        </div>
      </div>
    `;
  };

  if (dashboardContainer) {
    dashboardContainer.innerHTML = pendingAndConfirmed.slice(0, 4).map(renderQueueItem).join("");
  }
  if (fullContainer) {
    fullContainer.innerHTML = pendingAndConfirmed.map(renderQueueItem).join("");
  }
}

function renderTodayTimeline() {
  const container = document.getElementById("dashboard-timeline-container");
  const countBadge = document.getElementById("today-timeline-count");
  if (!container) return;

  const todayStr = new Date().toISOString().split("T")[0];
  const todayAppts = allAppointments.filter(a => a.date === todayStr);

  if (countBadge) countBadge.textContent = `${todayAppts.length} Today`;

  if (todayAppts.length === 0) {
    container.innerHTML = '<p class="text-muted text-center doctor-dashboard-empty--medium">No appointments scheduled for today.</p>';
    return;
  }

  container.innerHTML = todayAppts.map(appt => {
    const workspaceUrl = getDoctorWorkspaceUrl(appt);
    return `
      <div class="timeline-session-item">
        <div class="timeline-time">${escapeHTML(formatApptTime(appt))}</div>
        <div class="timeline-body">
          <div class="timeline-title">${escapeHTML(appt.patient_name)}</div>
          <div class="timeline-sub">${escapeHTML(appt.department || "Consultation")} • Status: ${escapeHTML(appt.status)}</div>
        </div>
        ${workspaceUrl !== "#" ? `<a href="${workspaceUrl}" class="btn btn-outline btn-xs" aria-label="Open clinical workspace"><i class="fas fa-external-link-alt"></i></a>` : '<span class="text-muted">Unavailable</span>'}
      </div>
    `;
  }).join("");
}

function renderRecentActivity() {
  const container = document.getElementById("dashboard-activity-container");
  if (!container) return;

  const recent = allAppointments.slice(0, 5);
  if (recent.length === 0) {
    container.innerHTML = '<p class="text-muted text-center doctor-dashboard-empty--medium">No recent activity recorded.</p>';
    return;
  }

  container.innerHTML = recent.map(appt => `
    <div class="activity-feed-item">
      <div class="activity-icon"><i class="fas fa-check-circle doctor-dashboard-icon--primary"></i></div>
      <div class="activity-content">
        <div class="activity-title">Appointment for ${escapeHTML(appt.patient_name)}</div>
        <div class="activity-time">${escapeHTML(formatDate(appt.date))} at ${escapeHTML(formatApptTime(appt))}</div>
      </div>
    </div>
  `).join("");
}

/* ============================================================
   APPOINTMENT API ACTIONS
   ============================================================ */

async function doctorUpdateAppointment(id, action) {
  const label = action === "accept" ? "accept" : "reject";
  if (!confirm(`Are you sure you want to ${label} this appointment?`)) return;
  const status = action === "accept" ? "Confirmed" : "Cancelled";
  const result = await apiFetch(getBasePath() + "api/appointments/update.php", {
    method: "POST", headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ id, status })
  }, "Could not update appointment.");

  if (result.data?.success) {
    showToast(`Appointment ${result.data.status || label + "ed"}.`, "success");
    await loadDoctorAppointments();
  } else {
    showToast(result.data?.message || "Could not update appointment.", "error");
  }
}

async function doctorApproveReschedule(appointmentId) {
  if (!confirm("Approve this reschedule request? The appointment date/time will be updated.")) return;
  const result = await apiFetch(getBasePath() + "api/appointments/approve-reschedule.php", {
    method: "POST", headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ appointment_id: appointmentId, notes: "Approved by doctor." })
  }, "Could not approve reschedule.");

  if (result.data?.success) {
    showToast("Reschedule approved. Appointment date/time updated.", "success");
    await loadDoctorAppointments();
  } else {
    showToast(result.data?.message || "Failed to approve reschedule.", "error");
  }
}

async function doctorRejectReschedule(appointmentId) {
  const reason = prompt("Reason for rejecting (optional):");
  if (reason === null) return;
  const result = await apiFetch(getBasePath() + "api/appointments/reject-reschedule.php", {
    method: "POST", headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ appointment_id: appointmentId, notes: reason || "Rejected by doctor." })
  }, "Could not reject reschedule.");

  if (result.data?.success) {
    showToast("Reschedule request rejected.", "success");
    await loadDoctorAppointments();
  } else {
    showToast(result.data?.message || "Failed to reject reschedule.", "error");
  }
}

async function doctorSuggestReschedule(appointmentId) {
  const newDate = prompt("Enter suggested date (YYYY-MM-DD):");
  if (!newDate) return;
  const newTime = prompt("Enter suggested time (HH:MM):");
  if (!newTime) return;
  const notes = prompt("Optional note for patient:");
  const result = await apiFetch(getBasePath() + "api/appointments/suggest-reschedule.php", {
    method: "POST", headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ appointment_id: appointmentId, suggested_date: newDate, suggested_time: newTime, notes: notes || "" })
  }, "Could not suggest time.");

  if (result.data?.success) {
    showToast("Alternative appointment time suggested.", "success");
    await loadDoctorAppointments();
  } else {
    showToast(result.data?.message || "Failed to suggest time.", "error");
  }
}

/* ============================================================
   AVAILABILITY STATUS TOGGLE
   ============================================================ */

async function loadDoctorAvailability() {
  const result = await apiFetch(getBasePath() + "api/doctors/get-availability.php", {}, "Failed to load availability");
  if (result.data?.success) updateAvailabilityUI(result.data.available);
}

function updateAvailabilityUI(isAvailable) {
  const toggleSwitch = document.getElementById("avail-toggle-switch");
  const statusText = document.getElementById("avail-status-text");
  const heroStatusPill = document.getElementById("doctor-hero-status-text");

  const available = isAvailable == 1;
  if (toggleSwitch) toggleSwitch.checked = available;
  if (statusText) statusText.textContent = available ? "Available" : "Unavailable";
  if (heroStatusPill) heroStatusPill.textContent = available ? "Available for Bookings" : "Booking Disabled";
}

async function handleAvailabilityToggle() {
  const toggleSwitch = document.getElementById("avail-toggle-switch");
  if (!toggleSwitch) return;

  const originalState = toggleSwitch.checked;
  toggleSwitch.disabled = true;

  const result = await apiFetch(getBasePath() + "api/doctors/toggle-availability.php", {
    method: "POST", headers: { "Content-Type": "application/json" }
  }, "Could not toggle availability.");

  if (result.data?.success) {
    showToast(result.data.message, "success");
    updateAvailabilityUI(result.data.available);
  } else {
    showToast(result.data?.message || "Failed to toggle availability.", "error");
    toggleSwitch.checked = !originalState;
  }
  toggleSwitch.disabled = false;
}

/* ============================================================
   NOTIFICATIONS & REVIEWS
   ============================================================ */

async function loadDoctorNotifications() {
  const container = document.getElementById("doctor-notifications-container");
  const notifSvc = window.NotificationService || (typeof NotificationService !== "undefined" ? NotificationService : null);
  if (!container || !notifSvc) return;

  // Show loading skeleton
  container.innerHTML = `
    <div class="notif-skeleton-item"><div class="notif-skeleton-icon"></div><div class="notif-skeleton-lines"><div class="notif-skeleton-line medium"></div><div class="notif-skeleton-line short"></div></div></div>
    <div class="notif-skeleton-item"><div class="notif-skeleton-icon"></div><div class="notif-skeleton-lines"><div class="notif-skeleton-line medium"></div><div class="notif-skeleton-line short"></div></div></div>
  `;

  const res = await notifSvc.getNotifications(1);
  if (!res) {
    container.innerHTML = '<div class="notif-empty-state"><i class="fas fa-bell-slash"></i><h4>No notifications found</h4><p>You have no notification alerts at this time.</p></div>';
    return;
  }

  let notifications = Array.isArray(res.notifications)
    ? res.notifications.filter(n => n && typeof n === "object" && !Array.isArray(n))
    : [];
  updateNotificationBadge(safeCount(res.unread_count, 0));

  // Apply Filter
  const filterVal = document.getElementById("doctor-notif-filter")?.value || "all";
  if (filterVal === "unread") {
    notifications = notifications.filter(n => !n.is_read);
  } else if (filterVal === "appointments") {
    notifications = notifications.filter(n => (typeof n.type === "string" ? n.type : "").startsWith("appointment"));
  } else if (filterVal === "system") {
    notifications = notifications.filter(n => !(typeof n.type === "string" ? n.type : "").startsWith("appointment"));
  }

  if (notifications.length === 0) {
    container.innerHTML = '<div class="notif-empty-state"><i class="fas fa-bell-slash"></i><h4>No matching notifications</h4><p>There are no notifications under the selected filter.</p></div>';
    return;
  }

  // Date Grouping
  const todayStr = new Date().toDateString();
  const yesterdayStr = new Date(Date.now() - 86400000).toDateString();

  const groups = { Today: [], Yesterday: [], Earlier: [] };
  notifications.forEach(n => {
    const timestamp = typeof n.created_at === "string" ? Date.parse(n.created_at) : NaN;
    const dStr = Number.isFinite(timestamp) ? new Date(timestamp).toDateString() : "";
    if (dStr === todayStr) groups.Today.push(n);
    else if (dStr === yesterdayStr) groups.Yesterday.push(n);
    else groups.Earlier.push(n);
  });

  let html = "";
  ["Today", "Yesterday", "Earlier"].forEach(groupName => {
    if (groups[groupName].length === 0) return;
    html += `<div class="notif-group-header"><i class="far fa-clock"></i> ${groupName}</div>`;
    html += groups[groupName].map(n => {
      const notificationId = safePositiveId(n.id);
      const notificationType = typeof n.type === "string" ? n.type : "";
      const createdAt = typeof n.created_at === "string" && Number.isFinite(Date.parse(n.created_at))
        ? n.created_at
        : null;
      const isUrgent = notificationType.includes("request") || notificationType.includes("cancelled");
      const iconClass = isUrgent ? "icon-warning" : (notificationType.includes("confirmed") ? "icon-success" : "icon-primary");
      return `
        <div class="notif-panel-card ${n.is_read ? '' : 'unread'}" data-id="${notificationId || ""}">
          <div class="notif-panel-icon ${iconClass}">
            <i class="fas ${isUrgent ? 'fa-exclamation-circle' : 'fa-bell'}"></i>
          </div>
          <div class="notif-panel-body">
            <div class="notif-panel-title-row">
              <div class="notif-panel-title">${escapeHTML(n.title || "Notification")}</div>
              <div class="notif-panel-time">${escapeHTML(createdAt ? NotificationService.getRelativeTime(createdAt) : "—")}</div>
            </div>
            <div class="notif-panel-message">${escapeHTML(n.message || "")}</div>
            <div class="notif-panel-actions">
              ${!n.is_read && notificationId ? `<button type="button" class="btn btn-xs btn-outline notif-mark-btn" data-id="${notificationId}"><i class="fas fa-check"></i> Mark Read</button>` : ''}
            </div>
          </div>
        </div>
      `;
    }).join("");
  });

  container.innerHTML = html;

  // Bind mark-as-read buttons
  container.querySelectorAll(".notif-mark-btn").forEach(btn => {
    btn.addEventListener("click", async (e) => {
      e.stopPropagation();
      const notifId = safePositiveId(btn.getAttribute("data-id"));
      if (notifId && window.NotificationService) {
        await NotificationService.markAsRead(notifId);
        loadDoctorNotifications();
      }
    });
  });
}

function updateNotificationBadge(unreadCount) {
  const badge = document.getElementById("doctor-notif-badge");
  if (badge) {
    badge.textContent = unreadCount;
    badge.classList.toggle("is-visible", Number(unreadCount) > 0);
  }
}

async function loadDoctorRatings() {
  const result = await apiFetch(getBasePath() + "api/doctors/get-doctor-ratings.php", {}, "Failed to load reviews.");
  const sourceRatings = Array.isArray(result.data?.ratings)
    ? result.data.ratings.filter(rating => rating && typeof rating === "object" && !Array.isArray(rating))
    : [];
  const ratings = sourceRatings
    .map(rating => ({
      stars: safeCount(rating.stars, 0),
      review: typeof rating.review === "string" ? rating.review : "",
      review_date: typeof rating.review_date === "string" ? rating.review_date : "",
      appt_date: typeof rating.appt_date === "string" ? rating.appt_date : "",
    }))
    .filter(rating => rating.stars >= 1 && rating.stars <= 5);

  let avgRating = ratings.length
    ? (ratings.reduce((sum, r) => sum + r.stars, 0) / ratings.length).toFixed(1)
    : "No ratings yet";
  const counts = { 1: 0, 2: 0, 3: 0, 4: 0, 5: 0 };

  ratings.forEach(r => {
    counts[r.stars] = (counts[r.stars] || 0) + 1;
  });

  setText("doctor-avg-rating", avgRating);
  setText("doctor-profile-rating-score", avgRating);
  setText("doctor-reviews-avg-num", avgRating);
  setText("doctor-reviews-total-text", ratings.length ? `Based on ${ratings.length} patient reviews` : "No patient ratings yet");

  const averageStarsEl = document.getElementById("doctor-reviews-avg-stars");
  if (averageStarsEl) {
    const filledStars = ratings.length ? Math.round(Number(avgRating)) : 0;
    const stars = document.createDocumentFragment();
    for (let index = 0; index < 5; index += 1) {
      const star = document.createElement("i");
      star.className = index < filledStars ? "fas fa-star" : "far fa-star";
      star.setAttribute("aria-hidden", "true");
      stars.appendChild(star);
    }
    averageStarsEl.replaceChildren(stars);
    averageStarsEl.setAttribute(
      "aria-label",
      ratings.length ? `Average rating ${avgRating} out of 5` : "No patient ratings yet"
    );
  }

  // Update Rating Breakdown Progress Bars
  const total = ratings.length || 1;
  [5, 4, 3, 2, 1].forEach(star => {
    const count = counts[star] || 0;
    const pct = Math.round((count / total) * 100);
    const countEl = document.getElementById(`star-${star}-count`);
    if (countEl) {
      countEl.textContent = `${count} (${pct}%)`;
      const row = countEl.closest(".rating-bar-row");
      if (row) {
        const fill = row.querySelector(".rating-bar-fill");
        if (fill) {
          fill.classList.remove(...Array.from(fill.classList).filter(name => /^rating-bar-fill--\d+$/.test(name)));
          fill.classList.add(`rating-bar-fill--${pct}`);
        }
      }
    }
  });

  // Render Rating Grid
  const gridContainer = document.getElementById("doctor-reviews-grid");
  if (!gridContainer) return;

  if (ratings.length === 0) {
    gridContainer.innerHTML = '<p class="text-muted text-center doctor-dashboard-empty--full-row">No patient reviews submitted yet.</p>';
    return;
  }

  gridContainer.innerHTML = ratings.map(r => {
    const starsVal = Math.min(5, Math.max(0, safeCount(r.stars, 0)));
    const starDisplay = Array(starsVal).fill('<i class="fas fa-star"></i>').join("") + Array(5 - starsVal).fill('<i class="far fa-star"></i>').join("");
    const comment = r.review ? escapeHTML(r.review) : `<span class="doctor-dashboard-muted--italic">No written comment left.</span>`;
    const initials = "PT";
    const visibilityBadge = r.is_hidden
      ? '<span class="status status-cancelled">Hidden from public</span>'
      : "";

    return `
      <div class="review-card-item">
        <div class="review-card-header">
          <div class="review-card-user">
            <div class="review-card-avatar">${initials}</div>
            <div>
              <div class="doctor-dashboard-meta--strong">Verified Patient</div>
              <div class="review-card-stars">${starDisplay} (${starsVal}/5)</div>
              ${visibilityBadge}
            </div>
          </div>
        </div>
        <div class="review-card-body">${comment}</div>
        <div class="review-card-footer">
          <span><i class="far fa-calendar-alt"></i> ${escapeHTML(formatDate(r.review_date))}</span>
          <span><i class="far fa-clock"></i> Appt: ${escapeHTML(formatDate(r.appt_date))}</span>
        </div>
      </div>
    `;
  }).join("");
}
