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
  setText("doctor-sidebar-name", user.name || "Doctor");
  setText("doctor-sidebar-email", user.email || "doctor@healthbridge.com");
  setText("doctor-sidebar-dept", user.department || user.specialty || "Medical Specialist");
  setText("doctor-profile-email-val", user.email || "-");
  setText("doctor-profile-phone-val", user.phone || "-");

  const avatarText = getInitials(user.name || "Doctor");
  const sidebarAvatar = document.getElementById("doctor-sidebar-avatar");
  if (sidebarAvatar) sidebarAvatar.textContent = avatarText;

  const profileAvatarLarge = document.getElementById("doctor-profile-avatar-large");
  if (profileAvatarLarge) profileAvatarLarge.textContent = avatarText;

  const welcome = document.getElementById("doctor-welcome-name");
  if (welcome) {
    welcome.innerHTML = `<i class="fas fa-stethoscope" aria-hidden="true"></i> Welcome, ${escapeHTML(user.name || "Doctor")}`;
  }

  const profileName = document.getElementById("doctor-profile-fullname");
  if (profileName) profileName.textContent = user.name || "Doctor";

  const profileDept = document.getElementById("doctor-profile-dept-spec");
  if (profileDept) {
    profileDept.textContent = `${user.department || "General Practice"} • ${user.specialty || "Senior Specialist"}`;
  }

  const dateEl = document.getElementById("doctor-hero-date");
  if (dateEl) {
    const todayStr = new Date().toLocaleDateString("en-US", {
      weekday: "long",
      year: "numeric",
      month: "long",
      day: "numeric"
    });
    dateEl.innerHTML = `<i class="far fa-calendar-alt" aria-hidden="true"></i> ${todayStr}`;
  }
}

function getInitials(name) {
  if (!name) return "DR";
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

  allAppointments = result.data.appointments || [];

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
  if (status) filtered = filtered.filter(a => (a.status || "").toLowerCase() === status.toLowerCase());
  renderDoctorAppointments(filtered);
}

function renderDoctorAppointments(appointments) {
  const container = document.getElementById("appointments-container");
  if (!container) return;

  if (appointments.length === 0) {
    container.innerHTML = '<p style="color:var(--text-secondary);text-align:center;padding:var(--s8)">No matching appointments found.</p>';
    return;
  }

  const rows = appointments.map((appt) => {
    const sc = (appt.status || "pending").toLowerCase();
    const isPending = appt.status === "Pending";
    const isConfirmed = appt.status === "Confirmed";
    const workspaceUrl = `${getBasePath()}pages/doctor/doctor-workspace.html?patient_id=${appt.user_id}&appointment_id=${appt.id}`;

    const wf = appt.visit_workflow;
    const wfStatus = wf ? wf.status : null;
    const wfClass = wfStatus ? wfStatus.toLowerCase().replace(/\s+/g, '_') : '';

    const bookingStatusBadge = `<span class="status status-${sc}">${escapeHTML(appt.status)}</span>`;

    let clinicalStatusBadge = '<span style="color:var(--text-muted)">-</span>';
    if (isConfirmed && wfStatus) {
      clinicalStatusBadge = `<span class="status status-${wfClass}">${escapeHTML(wfStatus)}</span>`;
    }

    const hasReschedule = appt.reschedule_status === "pending";
    const rescheduleInfo = hasReschedule ?
      `<div style="font-size:0.78rem;color:#f59e0b;margin-top:2px;">
        <i class="fas fa-clock"></i> Reschedule Request: ${appt.pending_reschedule_date || ''} ${appt.pending_reschedule_time || ''}
      </div>` : '';

    let actions = "";
    if (isPending) {
      actions = `
        <button type="button" class="btn btn-success btn-xs" data-action="accept" data-id="${appt.id}"><i class="fas fa-check"></i> Accept</button>
        <button type="button" class="btn btn-outline btn-xs" data-action="reject" data-id="${appt.id}" style="color:var(--danger)"><i class="fas fa-times"></i> Decline</button>
      `;
    } else if (isConfirmed) {
      actions = `
        <a href="${workspaceUrl}" class="btn btn-primary btn-xs"><i class="fas fa-folder-open"></i> Open EMR</a>
        <button type="button" class="btn btn-outline btn-xs" data-action="suggest-reschedule" data-id="${appt.id}"><i class="fas fa-calendar-plus"></i> Reschedule</button>
      `;
    }

    if (hasReschedule) {
      actions += `
        <button type="button" class="btn btn-success btn-xs" data-action="approve-reschedule" data-id="${appt.id}"><i class="fas fa-check"></i> Approve Time</button>
        <button type="button" class="btn btn-outline btn-xs" data-action="reject-reschedule" data-id="${appt.id}"><i class="fas fa-times"></i> Reject</button>
      `;
    }

    return `<tr>
      <td style="font-weight:600">${escapeHTML(appt.patient_name || "Patient")} ${rescheduleInfo}</td>
      <td>${escapeHTML(formatDate(appt.date))}</td>
      <td>${escapeHTML(formatApptTime(appt))}</td>
      <td>${escapeHTML(appt.department || "-")}</td>
      <td>${bookingStatusBadge}</td>
      <td>${clinicalStatusBadge}</td>
      <td style="color:var(--text-secondary);font-size:0.85rem;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${escapeHTML(appt.notes || "")}">${escapeHTML(appt.notes || "-")}</td>
      <td><div style="display:flex;gap:0.35rem;flex-wrap:wrap;align-items:center">${actions}</div></td>
    </tr>`;
  }).join("");

  container.innerHTML = `
    <div class="table-wrap" style="border:none;border-radius:0">
      <table style="width:100%">
        <thead><tr><th>Patient</th><th>Date</th><th>Time</th><th>Department</th><th>Booking Status</th><th>Clinical Workflow</th><th>Notes</th><th>Actions</th></tr></thead>
        <tbody>${rows}</tbody>
      </table>
    </div>`;
}

function handleApptTableActions(e) {
  const btn = e.target.closest("button[data-action]");
  if (!btn) return;

  const action = btn.getAttribute("data-action");
  const id = parseInt(btn.getAttribute("data-id") || "0");
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
    const emptyHtml = '<p class="text-muted text-center" style="padding:var(--s6)">No patients currently waiting in queue.</p>';
    if (dashboardContainer) dashboardContainer.innerHTML = emptyHtml;
    if (fullContainer) fullContainer.innerHTML = emptyHtml;
    return;
  }

  const renderQueueItem = (appt) => {
    const isPending = appt.status === "Pending";
    const wfStatus = appt.visit_workflow?.status || (isPending ? "Waiting" : "Confirmed");
    const workspaceUrl = `${getBasePath()}pages/doctor/doctor-workspace.html?patient_id=${appt.user_id}&appointment_id=${appt.id}`;
    const initials = getInitials(appt.patient_name);
    const hasAllergy = (appt.notes || "").toLowerCase().includes("allerg");

    return `
      <div class="queue-card-item">
        <div class="queue-card-avatar">${initials}</div>
        <div class="queue-card-info">
          <div class="queue-card-name">${escapeHTML(appt.patient_name || "Patient")} <span class="ws-sticky-id">#MRN-${appt.user_id || '00'}</span></div>
          <div class="queue-card-meta">
            <span><i class="far fa-clock"></i> ${escapeHTML(formatApptTime(appt))}</span>
            <span><i class="fas fa-hospital-user"></i> ${escapeHTML(appt.department || "General")}</span>
          </div>
          ${appt.notes ? `<div class="queue-card-reason"><i class="fas fa-comment-medical"></i> ${escapeHTML(appt.notes)}</div>` : ''}
          <div class="queue-card-badge-row">
            ${hasAllergy ? '<span class="queue-chip-danger"><i class="fas fa-allergies"></i> Allergy Alert</span>' : ''}
            ${isPending ? '<span class="queue-chip-warning"><i class="fas fa-hourglass-half"></i> Pending Review</span>' : ''}
          </div>
        </div>
        <div class="queue-card-status">
          <span class="badge ${isPending ? 'badge-warning' : 'badge-primary'}">${escapeHTML(wfStatus)}</span>
          <a href="${workspaceUrl}" class="btn btn-primary btn-xs" style="margin-top:6px;"><i class="fas fa-stethoscope"></i> Consult</a>
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
    container.innerHTML = '<p class="text-muted text-center" style="padding:var(--s4)">No appointments scheduled for today.</p>';
    return;
  }

  container.innerHTML = todayAppts.map(appt => {
    const workspaceUrl = `${getBasePath()}pages/doctor/doctor-workspace.html?patient_id=${appt.user_id}&appointment_id=${appt.id}`;
    return `
      <div class="timeline-session-item">
        <div class="timeline-time">${escapeHTML(formatApptTime(appt))}</div>
        <div class="timeline-body">
          <div class="timeline-title">${escapeHTML(appt.patient_name)}</div>
          <div class="timeline-sub">${escapeHTML(appt.department || "Consultation")} • Status: ${escapeHTML(appt.status)}</div>
        </div>
        <a href="${workspaceUrl}" class="btn btn-outline btn-xs"><i class="fas fa-external-link-alt"></i></a>
      </div>
    `;
  }).join("");
}

function renderRecentActivity() {
  const container = document.getElementById("dashboard-activity-container");
  if (!container) return;

  const recent = allAppointments.slice(0, 5);
  if (recent.length === 0) {
    container.innerHTML = '<p class="text-muted text-center" style="padding:var(--s4)">No recent activity recorded.</p>';
    return;
  }

  container.innerHTML = recent.map(appt => `
    <div class="activity-feed-item">
      <div class="activity-icon"><i class="fas fa-check-circle" style="color:var(--primary)"></i></div>
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

  let notifications = res.notifications || [];
  updateNotificationBadge(res.unread_count || 0);

  // Apply Filter
  const filterVal = document.getElementById("doctor-notif-filter")?.value || "all";
  if (filterVal === "unread") {
    notifications = notifications.filter(n => !n.is_read);
  } else if (filterVal === "appointments") {
    notifications = notifications.filter(n => (n.type || "").startsWith("appointment"));
  } else if (filterVal === "system") {
    notifications = notifications.filter(n => !(n.type || "").startsWith("appointment"));
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
    const dStr = new Date(n.created_at).toDateString();
    if (dStr === todayStr) groups.Today.push(n);
    else if (dStr === yesterdayStr) groups.Yesterday.push(n);
    else groups.Earlier.push(n);
  });

  let html = "";
  ["Today", "Yesterday", "Earlier"].forEach(groupName => {
    if (groups[groupName].length === 0) return;
    html += `<div class="notif-group-header"><i class="far fa-clock"></i> ${groupName}</div>`;
    html += groups[groupName].map(n => {
      const isUrgent = (n.type || "").includes("request") || (n.type || "").includes("cancelled");
      const iconClass = isUrgent ? "icon-warning" : ((n.type || "").includes("confirmed") ? "icon-success" : "icon-primary");
      return `
        <div class="notif-panel-card ${n.is_read ? '' : 'unread'}" data-id="${n.id}">
          <div class="notif-panel-icon ${iconClass}">
            <i class="fas ${isUrgent ? 'fa-exclamation-circle' : 'fa-bell'}"></i>
          </div>
          <div class="notif-panel-body">
            <div class="notif-panel-title-row">
              <div class="notif-panel-title">${escapeHTML(n.title || "Notification")}</div>
              <div class="notif-panel-time">${escapeHTML(NotificationService.getRelativeTime(n.created_at))}</div>
            </div>
            <div class="notif-panel-message">${escapeHTML(n.message || "")}</div>
            <div class="notif-panel-actions">
              ${!n.is_read ? `<button type="button" class="btn btn-xs btn-outline notif-mark-btn" data-id="${n.id}"><i class="fas fa-check"></i> Mark Read</button>` : ''}
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
      const notifId = parseInt(btn.getAttribute("data-id"));
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
    badge.style.display = unreadCount > 0 ? "inline-block" : "none";
  }
}

async function loadDoctorRatings() {
  const result = await apiFetch(getBasePath() + "api/doctors/get-doctor-ratings.php", {}, "Failed to load reviews.");
  const ratings = result.data?.ratings || [];

  let avgRating = "5.0";
  const counts = { 1: 0, 2: 0, 3: 0, 4: 0, 5: 0 };

  if (ratings.length > 0) {
    let sum = 0;
    ratings.forEach(r => {
      const s = Math.min(5, Math.max(1, parseInt(r.stars) || 5));
      counts[s] = (counts[s] || 0) + 1;
      sum += s;
    });
    avgRating = (sum / ratings.length).toFixed(1);
  }

  setText("doctor-avg-rating", avgRating);
  setText("doctor-profile-rating-score", avgRating);
  setText("doctor-reviews-avg-num", avgRating);
  setText("doctor-reviews-total-text", `Based on ${ratings.length} patient reviews`);

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
        if (fill) fill.style.width = `${pct}%`;
      }
    }
  });

  // Render Rating Grid
  const gridContainer = document.getElementById("doctor-reviews-grid");
  if (!gridContainer) return;

  if (ratings.length === 0) {
    gridContainer.innerHTML = '<p class="text-muted text-center" style="grid-column:1/-1; padding:var(--s8)">No patient reviews submitted yet.</p>';
    return;
  }

  gridContainer.innerHTML = ratings.map(r => {
    const starsVal = parseInt(r.stars) || 5;
    const starDisplay = Array(starsVal).fill('<i class="fas fa-star"></i>').join("") + Array(5 - starsVal).fill('<i class="far fa-star"></i>').join("");
    const comment = r.review ? escapeHTML(r.review) : `<span style="color:var(--text-muted);font-style:italic">No written comment left.</span>`;
    const initials = "PT";

    return `
      <div class="review-card-item">
        <div class="review-card-header">
          <div class="review-card-user">
            <div class="review-card-avatar">${initials}</div>
            <div>
              <div style="font-weight:600; font-size:0.9rem;">Verified Patient</div>
              <div class="review-card-stars">${starDisplay} (${starsVal}/5)</div>
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
