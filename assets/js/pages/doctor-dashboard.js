/**
 * HealthBridge — Doctor Dashboard JavaScript
 * Phase 5.2 — Simplified professional workflow.
 *
 * The doctor dashboard is now just a launchpad for the Clinical Workspace.
 * Appointments -> Open Patient -> Clinical Workspace (everything there).
 *
 * Uses: apiFetch(), renderTable(), initTabNavigation(), filterData(),
 *       escapeHTML(), formatDate(), formatApptTime(), showToast() from main.js
 */

"use strict";

let allAppointments = [];

document.addEventListener("DOMContentLoaded", async () => {
  const user = await requireServerRole("doctor");
  if (!user) return;

  setText("doctor-sidebar-name", user.name || "Doctor");
  const welcome = document.getElementById("doctor-welcome-name");
  if (welcome)
    welcome.innerHTML = `<i class="fas fa-user-doctor" aria-hidden="true"></i> Welcome, ${escapeHTML(user.name || "Doctor")}`;
  setText("doctor-sidebar-email", user.email || "doctor@healthbridge.com");
  setText("doctor-profile-email", user.email || "-");

  initTabNavigation("hb_doctor_active_tab");
  loadDoctorAvailability();
  await loadDoctorAppointments();
  await loadDoctorRatings();
  ScheduleManager.init("doctor-schedule-container", "doctor", user.id);
});

function setText(id, text) {
  const el = document.getElementById(id);
  if (el) el.textContent = text;
}

/* ============================================================
   APPOINTMENTS — Simplified: Open Patient + Accept/Reject/Complete
   ============================================================ */

async function loadDoctorAppointments() {
  const result = await apiFetch((getBasePath() + "api/appointments/get-doctor.php"), {}, "Failed to load appointments");
  if (!result.ok || !result.data?.success) {
    showToast(result.data?.message || "Failed to load appointments", "error");
    return;
  }

  allAppointments = result.data.appointments || [];
  setText("total-appts", allAppointments.length);
  setText("pending-appts", allAppointments.filter(a => a.status === "Pending").length);
  setText("confirmed-appts", allAppointments.filter(a => a.status === "Confirmed").length);
  filterDoctorAppointments();
}

function filterDoctorAppointments() {
  const query = document.getElementById("doctor-appt-search")?.value || "";
  const status = document.getElementById("doctor-status-filter")?.value || "";
  let filtered = allAppointments;
  filtered = filterData(filtered, query, ["patient_name", "notes"]);
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
    const url = `${getBasePath()}pages/doctor/doctor-workspace.html?patient_id=${appt.user_id}&appointment_id=${appt.id}`;

    // Phase 5.4: Separate booking status from clinical workflow status
    const wf = appt.visit_workflow;
    const wfStatus = wf ? wf.status : null;
    const wfClass = wfStatus ? wfStatus.toLowerCase().replace(/\s+/g, '_') : '';
    
    // Booking status column
    const bookingStatus = `<span class="status status-${sc}">${appt.status}</span>`;
    
    // Clinical status column
    let clinicalStatus = '<span style="color:var(--text-muted)">—</span>';
    if (isConfirmed && wfStatus) {
      clinicalStatus = `<span class="status status-${wfClass}">${escapeHTML(wfStatus)}</span>`;
    }

    // Build action buttons based on combined state
    let actions = '';
    
    if (isPending) {
      actions = `
        <button class="btn btn-primary btn-sm" onclick="doctorUpdateAppointment(${appt.id}, 'accept')">Accept</button>
        <button class="btn btn-outline btn-sm" onclick="doctorUpdateAppointment(${appt.id}, 'reject')">Reject</button>`;
    } else if (isConfirmed) {
      if (wfStatus === 'Waiting') {
        actions = `<a href="${url}" class="btn btn-primary btn-sm"><i class="fas fa-briefcase-medical"></i> Open Patient</a>`;
      } else if (wfStatus === 'In Progress') {
        actions = `<a href="${url}#visit" class="btn btn-primary btn-sm"><i class="fas fa-stethoscope"></i> Continue Visit</a>`;
      } else if (wfStatus === 'Ready to Complete') {
        actions = `<a href="${url}#visit" class="btn btn-success btn-sm"><i class="fas fa-check-circle"></i> Review</a>`;
      } else if (wfStatus === 'Completed') {
        actions = `<a href="${url}" class="btn btn-outline btn-sm"><i class="fas fa-eye"></i> View Summary</a>`;
      } else {
        // Fallback for confirmed appointments without workflow
        actions = `<a href="${url}" class="btn btn-primary btn-sm"><i class="fas fa-briefcase-medical"></i> Open Patient</a>`;
      }
    }

    return `<tr>
      <td>${escapeHTML(appt.patient_name)}</td>
      <td>${formatDate(appt.date)}</td>
      <td>${escapeHTML(formatApptTime(appt))}</td>
      <td>${escapeHTML(appt.department)}</td>
      <td>${bookingStatus}</td>
      <td>${clinicalStatus}</td>
      <td style="color:var(--text-secondary);font-size:0.88rem;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${escapeHTML(appt.notes || "")}">${escapeHTML(appt.notes || "—")}</td>
      <td><div style="display:flex;gap:0.35rem;flex-wrap:wrap;align-items:center">${actions}</div></td>
    </tr>`;
  }).join("");

  container.innerHTML = `
    <div class="table-wrap" style="border:none;border-radius:0">
      <table style="width:100%">
        <thead><tr><th>Patient</th><th>Date</th><th>Time</th><th>Department</th><th>Booking</th><th>Clinical</th><th>Notes</th><th>Actions</th></tr></thead>
        <tbody>${rows}</tbody>
      </table>
    </div>`;
}

async function doctorUpdateAppointment(id, action) {
  const label = action === "accept" ? "accept" : "reject";
  if (!confirm(`Are you sure you want to ${label} this appointment?`)) return;
  const status = action === "accept" ? "Confirmed" : "Cancelled";
  const result = await apiFetch((getBasePath() + "api/appointments/update.php"), {
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

/* ============================================================
   AVAILABILITY TOGGLE
   ============================================================ */

async function loadDoctorAvailability() {
  const result = await apiFetch((getBasePath() + "api/doctors/get-availability.php"), {}, "Failed to load availability");
  if (result.data?.success) updateAvailabilityUI(result.data.available);
}

function updateAvailabilityUI(isAvailable) {
  const toggleSwitch = document.getElementById("avail-toggle-switch");
  const statusIcon = document.getElementById("avail-status-icon");
  const statusText = document.getElementById("avail-status-text");
  const switchLabel = document.getElementById("avail-switch-label");
  if (toggleSwitch) toggleSwitch.checked = isAvailable == 1;
  const available = isAvailable == 1;
  const c = available ? "var(--success)" : "var(--danger)";
  const b = available ? "rgba(134, 239, 172, 0.08)" : "rgba(252, 165, 165, 0.08)";
  const bd = available ? "rgba(134, 239, 172, 0.15)" : "rgba(252, 165, 165, 0.15)";
  if (statusIcon) { statusIcon.innerHTML = '<i class="fas fa-circle" aria-hidden="true"></i>'; statusIcon.style.cssText = `color:${c};background:${b};border-color:${bd}`; }
  if (statusText) { statusText.textContent = available ? "Available" : "Unavailable"; statusText.style.color = c; }
  if (switchLabel) { switchLabel.textContent = available ? "I am available for bookings" : "I am NOT available for bookings"; switchLabel.style.color = available ? "var(--primary)" : "var(--text-muted)"; }
}

async function handleAvailabilityToggle() {
  const toggleSwitch = document.getElementById("avail-toggle-switch");
  if (!toggleSwitch) return;
  const originalState = toggleSwitch.checked;
  toggleSwitch.disabled = true;
  const result = await apiFetch((getBasePath() + "api/doctors/toggle-availability.php"), {
    method: "POST", headers: { "Content-Type": "application/json" }
  }, "Network error. Could not toggle availability.");
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
   REVIEWS
   ============================================================ */

async function loadDoctorRatings() {
  const result = await apiFetch((getBasePath() + "api/doctors/get-doctor-ratings.php"), {}, "Failed to load reviews.");
  const ratings = result.data?.ratings || [];
  renderTable("doctor-reviews", ratings, (r) => {
    const starsVal = parseInt(r.stars) || 0;
    const starDisplay = `<span style="color:var(--warning)">${Array(starsVal).fill('<i class="fas fa-star" aria-hidden="true"></i>').join("")}${Array(5 - starsVal).fill('<i class="far fa-star" aria-hidden="true"></i>').join("")}</span>`;
    const comment = r.review ? escapeHTML(r.review) : `<span style="color:var(--text-muted);font-style:italic">No written comment left.</span>`;
    return `<tr>
      <td style="font-weight:600">${starDisplay} (${starsVal}/5)</td>
      <td style="max-width:400px;word-break:break-word">${comment}</td>
      <td>${escapeHTML(formatDate(r.review_date))}</td>
      <td>Date: ${escapeHTML(formatDate(r.appt_date))}<br><small style="color:var(--text-secondary)">Time: ${escapeHTML(formatApptTime(r))}</small></td>
    </tr>`;
  }, "No reviews found.", 4);
}

/* ============================================================
   EXPOSE GLOBALS
   ============================================================ */

window.doctorUpdateAppointment = doctorUpdateAppointment;

function refreshDoctorPage() {
  showToast("Refreshing data...", "info");
  loadDoctorAppointments();
  loadDoctorRatings();
}
window.refreshDoctorPage = refreshDoctorPage;


