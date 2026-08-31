/**
 * HealthBridge — Admin Panel JavaScript
 * Handles: overview stats, appointments table, doctor management, patient management,
 *          booking modal, add doctor/patient modals.
 *
 * Uses shared helpers from main.js:
 *   - filterData(), renderTable(), apiFetch(), initTabNavigation()
 *   - escapeHTML(), formatDate(), showToast(), setLoading(), DOCTORS_FALLBACK
 *
 * Used by: admin.html
 */

"use strict";

// Module-level data stores
let allAppointments = [];
let allDoctors = [];
let allPatients = [];
let adminDoctorsList = [];

document.addEventListener("DOMContentLoaded", async () => {
  const user = await requireServerRole("admin");
  if (!user) return;

  setText("admin-name", user.name || "Admin");
  setText("admin-email", user.email || "admin@healthbridge.com");

  initTabNavigation("hb_admin_active_tab");
  loadDoctorsTable();
  loadPatientsTable();
  initAdminPage();
  initBookAppointmentModal();
  initAddDoctorForm();
  initAddPatientForm();
  initSupportMessagesSubsystem();
  initEditDoctorForm();
  initAdminPrescriptionsTab();
  initAdminSchedulesTab();
  initHospitalSettingsTab();
  initAuditLogTab();

  // Initialize the new analytics dashboard (replaces old overview stats)
  if (typeof initAdminDashboard === 'function') {
    initAdminDashboard();
  }
});

function setText(id, text) {
  const el = document.getElementById(id);
  if (el) el.textContent = text;
}

/* ============================================================
   OVERVIEW — Stats & Appointments
   ============================================================ */

async function initAdminPage() {
  // Delegate to the new analytics dashboard
  // Keep this function signature for backward compatibility.
  // The old initAdminPage loaded appointments for the old overview stats.
  // Those stats are now handled by the dashboard module.
  // We still load appointments for legacy sections that depend on allAppointments.
  const result = await apiFetch(
    (getBasePath() + "api/appointments/get.php"),
    {},
    "Failed to load appointments",
  );
  allAppointments =
    result.data?.appointments ||
    (Array.isArray(result.data) ? result.data : []);
  filterAppointments();
}

function filterAppointments() {
  const query = document.getElementById("appt-search")?.value || "";
  const status = document.getElementById("appt-status-filter")?.value || "";

  let filtered = allAppointments;
  filtered = filterData(filtered, query, [
    "patient_name",
    "doctor",
    "department",
  ]);
  if (status)
    filtered = filtered.filter(
      (a) => (a.status || "Pending").toLowerCase() === status.toLowerCase(),
    );

  renderTable(
    "admin-appointments",
    filtered,
    (a) => {
      const statusLower = (a.status || "pending").toLowerCase();
      const actions =
        (a.status || "Pending") === "Pending"
          ? `<button class="btn btn-outline btn-sm" style="margin-right:5px" onclick="adminApprove(${a.id})">Approve</button>
         <button class="btn btn-outline btn-sm" onclick="adminDecline(${a.id})">Decline</button>`
          : '<span style="color:var(--text-muted)">-</span>';
      return `
      <tr>
        <td>${escapeHTML(a.patient_name || a.patientName || "—")}</td>
        <td>${escapeHTML(a.doctor || "—")}</td>
        <td>${escapeHTML(a.department || "—")}</td>
        <td>${escapeHTML(a.date || "—")} ${escapeHTML(formatApptTime(a))}</td>
        <td><span class="status status-${statusLower}">${escapeHTML(a.status || "Pending")}</span></td>
        <td>${actions}</td>
      </tr>`;
    },
    "No appointments found.",
    6,
  );
}

function refreshAdminPage() {
  showToast("Refreshing data...", "info");
  initAdminPage();
  loadDoctorsTable();
  loadPatientsTable();
}

/* ---- Approve / Decline ---- */

function adminApprove(id) {
  if (!confirm("Approve this appointment?")) return;
  fetchAction(
    (getBasePath() + "api/appointments/approve.php"),
    { id },
    "Appointment confirmed!",
    "Approval failed.",
    initAdminPage,
  );
}

function adminDecline(id) {
  if (!confirm("Decline this appointment?")) return;
  fetchAction(
    (getBasePath() + "api/appointments/decline.php"),
    { id, reason: "Appointment declined by admin." },
    "Appointment declined!",
    "Decline failed.",
    initAdminPage,
  );
}

/**
 * Generic action wrapper with offline fallback.
 * On network error, updates localStorage and shows offline confirmation.
 */
function fetchAction(url, body, successMsg, errorMsg, onSuccess) {
  fetch(url, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    credentials: "same-origin",
    body: JSON.stringify(body),
  })
    .then((r) => r.json())
    .then((result) => {
      showToast(
        result.success ? successMsg : result.message || errorMsg,
        result.success ? "success" : "error",
      );
      onSuccess();
    })
    .catch(() => {
      // Offline fallback
      const key = "hb_appointments";
      const list = JSON.parse(localStorage.getItem(key) || "[]");
      const updated = list.map((a) =>
        a.id === body.id
          ? {
              ...a,
              status: successMsg.includes("confirmed")
                ? "Confirmed"
                : "Cancelled",
            }
          : a,
      );
      localStorage.setItem(key, JSON.stringify(updated));
      showToast(`${successMsg} (Offline mode)`, "info");
      onSuccess();
    });
}

/* ============================================================
   DOCTOR MANAGEMENT
   ============================================================ */

async function loadDoctorsTable() {
  const result = await apiFetch(
    (getBasePath() + "api/doctors/get_all.php"),
    {},
    "Admin login required.",
  );
  const tbody = document.getElementById("admin-doctors");
  if (!tbody) return;

  if (!result.ok || !result.data?.success) {
    tbody.innerHTML =
      '<tr><td colspan="6" class="text-center" style="padding:var(--s8);color:var(--danger)">Admin login required.</td></tr>';
    return;
  }

  allDoctors = Array.isArray(result.data.doctors) ? result.data.doctors : [];
  setText("admin-doctors-count", allDoctors.length);
  filterDoctors();
}

function filterDoctors() {
  const query = document.getElementById("doctor-search")?.value || "";
  const specialty =
    document.getElementById("doctor-specialty-filter")?.value || "";

  let filtered = allDoctors;
  filtered = filterData(filtered, query, ["name", "email"]);
  if (specialty)
    filtered = filtered.filter((d) => (d.specialty || "") === specialty);

  renderTable(
    "admin-doctors",
    filtered,
    (d) => `
    <tr>
      <td>${escapeHTML(d.name)}</td>
      <td>${escapeHTML(d.email)}</td>
      <td>${escapeHTML(d.phone || "-")}</td>
      <td>${formatDate(d.created_at)}</td>
      <td><span class="status status-${d.is_active ? "confirmed" : "cancelled"}">${d.is_active ? "Active" : "Disabled"}</span></td>
      <td>
        <a href="admin-doctor-profile.html?id=${d.id}" class="btn btn-profile btn-sm"><i class="fas fa-id-card" aria-hidden="true"></i> Profile</a>
        <button class="btn ${d.is_active ? "btn-outline" : "btn-danger"} btn-sm" style="margin-right:5px" onclick="toggleDoctorStatus(${d.id}, '${escapeHTML(d.name)}')">${d.is_active ? "Disable" : "Enable"}</button>
        <button class="btn btn-outline btn-sm" onclick="adminDeleteDoctor(${d.id}, '${escapeHTML(d.name)}')">Delete</button>
      </td>
    </tr>
  `,
    "No doctors found.",
    6,
  );
}

function adminDeleteDoctor(id, name) {
  if (
    !confirm(
      `Delete Dr. ${name}? This will also cancel all their pending and confirmed appointments.`,
    )
  )
    return;
  fetchAction(
    (getBasePath() + "api/doctors/delete.php"),
    { id },
    `Dr. ${name} has been deleted.`,
    "Delete failed.",
    () => {
      loadDoctorsTable();
      initAdminPage();
    },
  );
}

function toggleDoctorStatus(id, name) {
  const isActive = document
    .querySelector(
      `button[onclick="toggleDoctorStatus(${id}, '${escapeHTML(name)}')"]`,
    )
    ?.textContent.includes("Disable");
  const msg = isActive
    ? `Disable Dr. ${name}? They will not be able to log in.`
    : `Enable Dr. ${name}?`;
  if (!confirm(msg)) return;

  fetchAction(
    (getBasePath() + "api/doctors/toggle-status.php"),
    { id },
    `Dr. ${name} updated.`,
    "Status update failed.",
    loadDoctorsTable,
  );
}

/* Add Doctor Modal */
function toggleAddDoctorModal() {
  const modal = document.getElementById("add-doctor-modal");
  if (!modal) return;
  modal.classList.toggle("open");
  if (modal.classList.contains("open")) {
    document.getElementById("add-doctor-form")?.reset();
    loadDepartmentsForAddDoctor();
  }
}

async function loadDepartmentsForAddDoctor() {
  const deptSelect = document.getElementById("doctor-department");
  if (!deptSelect) return;

  const deptResult = await apiFetch(
    (getBasePath() + "api/departments/get.php"),
    {},
    "Failed to load departments."
  );
  if (deptResult.ok && deptResult.data?.departments) {
    deptSelect.innerHTML = '<option value="">Select department</option>';
    deptResult.data.departments.forEach(dept => {
      if (dept.status === 'active') {
        deptSelect.innerHTML += `<option value="${dept.id}">${escapeHTML(dept.name)}</option>`;
      }
    });
  }
}

function initAddDoctorForm() {
  const form = document.getElementById("add-doctor-form");
  if (!form) return;

  form.addEventListener("submit", async (e) => {
    e.preventDefault();

    const name = document.getElementById("doctor-name")?.value.trim() || "";
    const email = document.getElementById("doctor-email")?.value.trim() || "";
    const password = document.getElementById("doctor-password")?.value || "";
    const departmentId = document.getElementById("doctor-department")?.value;

    if (!name || !email || !password || !departmentId) {
      showToast("All fields are required.", "error");
      return;
    }
    if (password.length < 6) {
      showToast("Password must be at least 6 characters.", "error");
      return;
    }

    const result = await apiFetch(
      (getBasePath() + "api/doctors/add.php"),
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ name, email, password, department_id: departmentId }),
      },
      "Error adding doctor.",
    );

    if (result.data?.success) {
      showToast("Doctor added successfully.", "success");
      toggleAddDoctorModal();
      loadDoctorsTable();
    } else {
      showToast(result.data?.message || "Failed to add doctor.", "error");
    }
  });
}

/* ============================================================
   PATIENT MANAGEMENT
   ============================================================ */

async function loadPatientsTable() {
  const result = await apiFetch(
    (getBasePath() + "api/patients/get_all.php"),
    {},
    "Admin login required.",
  );
  const tbody = document.getElementById("admin-patients");
  if (!tbody) return;

  if (!result.ok || !result.data?.success) {
    tbody.innerHTML =
      '<tr><td colspan="6" class="text-center" style="padding:var(--s8);color:var(--danger)">Admin login required.</td></tr>';
    return;
  }

  allPatients = Array.isArray(result.data.patients) ? result.data.patients : [];
  filterPatients();
}

function filterPatients() {
  const query = document.getElementById("patient-search")?.value || "";
  renderTable(
    "admin-patients",
    filterData(allPatients, query, ["name", "email", "phone"]),
    (p) => `
    <tr>
      <td>${escapeHTML(p.name)}</td>
      <td>${escapeHTML(p.email)}</td>
      <td>${escapeHTML(p.phone || "-")}</td>
      <td>${formatDate(p.created_at)}</td>
      <td><span class="status status-${p.is_active ? "confirmed" : "cancelled"}">${p.is_active ? "Active" : "Disabled"}</span></td>
      <td>
        <a href="${getBasePath()}pages/patient/patient-emr.html?patient_id=${p.id}" class="btn btn-primary btn-sm" style="margin-right:5px" title="Open Electronic Medical Record" aria-label="Open EMR for ${escapeHTML(p.name)}"><i class="fas fa-notes-medical" aria-hidden="true"></i> EMR</a>
        <button class="btn ${p.is_active ? "btn-outline" : "btn-danger"} btn-sm" style="margin-right:5px" onclick="togglePatientStatus(${p.id}, '${escapeHTML(p.name)}')">${p.is_active ? "Disable" : "Enable"}</button>
        <button class="btn btn-outline btn-sm" onclick="adminDeletePatient(${p.id}, '${escapeHTML(p.name)}')">Delete</button>
      </td>
    </tr>
  `,
    "No patients found.",
    5,
  );
}

function adminDeletePatient(id, name) {
  if (
    !confirm(
      `Delete patient "${name}"? This will also cancel all their pending and confirmed appointments.`,
    )
  )
    return;
  fetchAction(
    (getBasePath() + "api/patients/delete.php"),
    { id },
    `Patient "${name}" deleted.`,
    "Delete failed.",
    () => {
      loadPatientsTable();
      initAdminPage();
    },
  );
}

function togglePatientStatus(id, name) {
  const isActive = document
    .querySelector(
      `button[onclick="togglePatientStatus(${id}, '${escapeHTML(name)}')"]`,
    )
    ?.textContent.includes("Disable");
  const msg = isActive
    ? `Disable patient "${name}"?`
    : `Enable patient "${name}"?`;
  if (!confirm(msg)) return;
  fetchAction(
    (getBasePath() + "api/patients/toggle-status.php"),
    { id },
    `Patient "${name}" updated.`,
    "Status update failed.",
    loadPatientsTable,
  );
}

/* Add Patient Modal */
function toggleAddPatientModal() {
  const modal = document.getElementById("add-patient-modal");
  if (!modal) return;
  modal.classList.toggle("open");
  if (modal.classList.contains("open"))
    document.getElementById("add-patient-form")?.reset();
}

function initAddPatientForm() {
  const form = document.getElementById("add-patient-form");
  if (!form) return;

  form.addEventListener("submit", async (e) => {
    e.preventDefault();

    const name = document.getElementById("patient-name")?.value.trim() || "";
    const email = document.getElementById("patient-email")?.value.trim() || "";
    const password = document.getElementById("patient-password")?.value || "";
    const phone = document.getElementById("patient-phone")?.value.trim() || "";

    if (!name || !email || !password) {
      showToast("Name, email, and password are required.", "error");
      return;
    }
    if (password.length < 6) {
      showToast("Password must be at least 6 characters.", "error");
      return;
    }

    const result = await apiFetch(
      (getBasePath() + "api/patients/add.php"),
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ name, email, password, phone }),
      },
      "Error adding patient.",
    );

    if (result.data?.success) {
      showToast("Patient added successfully.", "success");
      toggleAddPatientModal();
      loadPatientsTable();
    } else {
      showToast(result.data?.message || "Failed to add patient.", "error");
    }
  });
}

/* ============================================================
   BOOK APPOINTMENT MODAL (Admin inline booking)
   ============================================================ */

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
  } else {
    // Reset context when closing without booking
    const ctx = document.getElementById("admin-appt-context");
    const ctxId = document.getElementById("admin-appt-context-id");
    const title = document.getElementById("book-modal-title");
    if (ctx) ctx.value = "";
    if (ctxId) ctxId.value = "";
    if (title)
      title.innerHTML =
        '<i class="fas fa-calendar-days" aria-hidden="true"></i> Book Appointment';
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
  } catch {
    adminDoctorsList = DOCTORS_FALLBACK;
  }

  renderAdminDoctorOptions();
  // Remove old listener before adding to prevent duplicates
  if (deptSelect) {
    deptSelect.removeEventListener("change", renderAdminDoctorOptions);
    deptSelect.addEventListener("change", renderAdminDoctorOptions);
  }
}

async function loadDepartmentsForBooking() {
  const deptSelect = document.getElementById("admin-appt-department");
  if (!deptSelect) return;

  const deptResult = await apiFetch(
    (getBasePath() + "api/departments/get.php"),
    {},
    "Failed to load departments."
  );
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

    const ctx = document.getElementById("admin-appt-context")?.value || "";
    const ctxId = parseInt(
      document.getElementById("admin-appt-context-id")?.value || "0",
    );
    const patientIdInput = document.getElementById("admin-appt-patient-id");
    const patientId = patientIdInput
      ? parseInt(patientIdInput.value || "0")
      : 0;

    const data = {
      department_id:
        document.getElementById("admin-appt-department")?.value.trim() || "",
      doctor: document.getElementById("admin-appt-doctor")?.value.trim() || "",
      date: document.getElementById("admin-appt-date")?.value.trim() || "",
      time: document.getElementById("admin-appt-time")?.value.trim() || "",
      patientName:
        document.getElementById("admin-appt-patient")?.value.trim() || "",
      notes: document.getElementById("admin-appt-notes")?.value.trim() || "",
      patient_id: patientId,
    };

    if (
      !data.department ||
      !data.doctor ||
      !data.date ||
      !data.time ||
      !data.patientName
    ) {
      showToast("Please fill in all required fields.", "error");
      return;
    }

    const submitBtn = form.querySelector('[type="submit"]');
    const originalText = submitBtn?.textContent || "Confirm Booking";
    setLoading(submitBtn, true, "Booking...");

    const result = await apiFetch(
      (getBasePath() + "api/appointments/book.php"),
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(data),
      },
      "Booking failed.",
    );

    if (result.data?.success) {
      showToast("Appointment booked successfully!", "success");
      toggleBookAppointmentModal();
      initAdminPage();

      // Reset context fields & title
      document.getElementById("admin-appt-context").value = "";
      document.getElementById("admin-appt-context-id").value = "";
      document.getElementById("book-modal-title").innerHTML =
        '<i class="fas fa-calendar-days" aria-hidden="true"></i> Book Appointment';

      // Re-open the originating profile if we came from a doctor profile
      if (ctx === "doctor" && ctxId) {
        openDoctorProfile(ctxId);
      }
    } else {
      showToast(result.data?.message || "Booking failed.", "error");
    }
    setLoading(submitBtn, false, originalText);
  });
}

/* ============================================================
   SCHEDULE MANAGEMENT (Admin View)
   ============================================================ */

let allDoctorSchedules = [];

function initAdminSchedulesTab() {
  // Bind sidebar nav click
  document
    .querySelectorAll(".sidebar-nav a[href='#schedules']")
    .forEach((link) => {
      link.addEventListener("click", () => {
        setTimeout(() => loadAdminSchedules(), 100);
      });
    });

  // If the saved tab is schedules, load immediately
  const savedTab = localStorage.getItem("hb_admin_active_tab") || "overview";
  if (savedTab === "schedules") {
    setTimeout(() => loadAdminSchedules(), 150);
  }
}

async function loadAdminSchedules() {
  const container = document.getElementById("admin-schedules-container");
  if (!container) return;

  container.innerHTML =
    '<p style="color: var(--text-secondary); text-align: center; padding: var(--s8)">Loading schedules...</p>';

  const result = await apiFetch(
    (getBasePath() + "api/schedule/get.php"),
    {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "same-origin",
      body: JSON.stringify({ doctor_id: 0 }), // 0 = get all schedules
    },
    "Failed to load schedules.",
  );

  if (!result.ok || !result.data?.success) {
    container.innerHTML = `<p style="color: var(--danger); text-align: center; padding: var(--s8)">${escapeHTML(result.data?.message || "Failed to load schedules.")}</p>`;
    return;
  }

  allDoctorSchedules = result.data.schedules || [];

  if (allDoctorSchedules.length === 0) {
    container.innerHTML =
      '<p style="color: var(--text-muted); text-align: center; padding: var(--s8)">No doctors found.</p>';
    return;
  }

  renderAdminSchedules(allDoctorSchedules);
}

function renderAdminSchedules(schedules) {
  const container = document.getElementById("admin-schedules-container");
  if (!container) return;

  const dayNamesShort = {
    1: "Mon",
    2: "Tue",
    3: "Wed",
    4: "Thu",
    5: "Fri",
    6: "Sat",
    7: "Sun",
  };

  container.innerHTML = `
    <div class="schedule-admin-overview">
      <div class="schedule-admin-grid">
        ${schedules
          .map(
            (s) => `
          <div class="schedule-admin-card" data-doctor-id="${escapeHTML(s.doctor_id)}">
            <div class="schedule-admin-card-header">
              <div>
                <h4 class="schedule-admin-card-name">${escapeHTML(s.doctor_name)}</h4>
                <p class="schedule-admin-card-email">${escapeHTML(s.doctor_email)}</p>
              </div>
              <div class="schedule-admin-card-badges">
                <span class="status status-${parseInt(s.is_available) === 1 ? "confirmed" : "cancelled"}" style="font-size:0.7rem">
                  ${parseInt(s.is_available) === 1 ? "Available" : "Unavailable"}
                </span>
              </div>
            </div>
            <div class="schedule-admin-days-list">
              ${(s.weekly || [])
                .map(
                  (d) => `
                <span class="schedule-day-badge${parseInt(d.is_working) !== 1 ? " off" : ""}">
                  ${dayNamesShort[parseInt(d.day_of_week)] || "?"}
                </span>
              `,
                )
                .join("")}
            </div>
            <div class="schedule-admin-card-info">
              <span><i class="fas fa-clock" aria-hidden="true"></i> ${escapeHTML(s.appointment_duration || 30)} min</span>
              <span><i class="fas fa-users" aria-hidden="true"></i> Max ${escapeHTML(s.max_appointments_per_day || 25)}/day</span>
              <span><i class="fas fa-calendar-day" aria-hidden="true"></i> ${s.working_days_count || 0} days</span>
              ${
                s.break_start && s.break_end
                  ? `<span><i class="fas fa-mug-saucer" aria-hidden="true"></i> Break ${escapeHTML(s.break_start)}-${escapeHTML(s.break_end)}</span>`
                  : ""
              }
            </div>
            <div class="schedule-admin-card-actions">
              <button class="btn btn-primary btn-sm" onclick="openAdminScheduleEditor(${escapeHTML(s.doctor_id)})">
                <i class="fas fa-pen" aria-hidden="true"></i> Edit
              </button>
              <button class="btn btn-outline btn-sm" onclick="adminResetSchedule(${escapeHTML(s.doctor_id)}, '${escapeHTML(s.doctor_name)}')">
                <i class="fas fa-rotate-left" aria-hidden="true"></i> Reset
              </button>
            </div>
          </div>
        `,
          )
          .join("")}
      </div>
    </div>
  `;
}

let _scheduleEditorDoctorId = 0;

function openAdminScheduleEditor(doctorId) {
  _scheduleEditorDoctorId = doctorId;

  // Find the doctor card
  const card = document.querySelector(
    `.schedule-admin-card[data-doctor-id="${doctorId}"]`,
  );
  if (!card) return;

  // Remove any existing inline editor
  const existing = card.querySelector(".schedule-inline-container");
  if (existing) {
    existing.remove();
    return; // Toggle close
  }

  // Create inline editor container
  const editorDiv = document.createElement("div");
  editorDiv.className = "schedule-inline-container";
  editorDiv.id = `schedule-editor-${doctorId}`;
  card.appendChild(editorDiv);

  // Initialize the schedule manager in the editor
  ScheduleManager.init(`schedule-editor-${doctorId}`, "admin", doctorId);
}

async function adminResetSchedule(doctorId, doctorName) {
  if (
    !confirm(
      `Reset schedule for ${doctorName} to factory defaults? This cannot be undone.`,
    )
  ) {
    return;
  }

  const result = await apiFetch(
    (getBasePath() + "api/schedule/reset.php"),
    {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "same-origin",
      body: JSON.stringify({ doctor_id: doctorId }),
    },
    "Failed to reset schedule.",
  );

  if (result.data?.success) {
    showToast(result.data.message || "Schedule reset successfully!", "success");
    // Reload the schedules overview
    loadAdminSchedules();
  } else {
    showToast(result.data?.message || "Failed to reset schedule.", "error");
  }
}

// Expose to global scope
window.openAdminScheduleEditor = openAdminScheduleEditor;
window.adminResetSchedule = adminResetSchedule;

/* ============================================================
   SUPPORT MESSAGES & INQUIRIES (Admin View)
   ============================================================ */

let allContactMessages = [];

function initSupportMessagesSubsystem() {
  const form = document.getElementById("reply-message-form");
  if (!form) return;

  form.addEventListener("submit", async (e) => {
    e.preventDefault();

    const id = document.getElementById("reply-msg-id")?.value;
    const replyText = document.getElementById("reply-text")?.value.trim() || "";
    const submitBtn = form.querySelector('[type="submit"]');

    if (!id || !replyText) {
      showToast("Please enter a reply.", "error");
      return;
    }

    setLoading(submitBtn, true, "Sending reply...");

    const result = await apiFetch(
      (getBasePath() + "api/settings/reply-contact-message.php"),
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ id, reply: replyText }),
      },
      "Failed to send reply.",
    );

    if (result.data?.success) {
      showToast("Reply submitted successfully!", "success");
      toggleReplyMessageModal();
      loadSupportMessages();
    } else {
      showToast(result.data?.message || "Failed to submit reply.", "error");
    }
    setLoading(submitBtn, false, "Send Reply");
  });

  // Load immediately if support messages tab is active
  if (
    window.location.hash === "#support-messages" ||
    localStorage.getItem("hb_admin_active_tab") === "support-messages"
  ) {
    loadSupportMessages();
  }

  // Bind sidebar nav click
  document
    .querySelectorAll(".sidebar-nav a[href='#support-messages']")
    .forEach((link) => {
      link.addEventListener("click", loadSupportMessages);
    });
}

async function loadSupportMessages() {
  const result = await apiFetch(
    (getBasePath() + "api/settings/get-contact-messages.php"),
    {},
    "Failed to load support inquiries.",
  );
  allContactMessages =
    result.data?.messages || (Array.isArray(result.data) ? result.data : []);
  filterSupportMessages();
}

function filterSupportMessages() {
  const query = document.getElementById("support-search")?.value || "";
  const status = document.getElementById("support-status-filter")?.value || "";

  let filtered = allContactMessages;
  filtered = filterData(filtered, query, [
    "name",
    "email",
    "subject",
    "message",
  ]);

  if (status === "replied") {
    filtered = filtered.filter((m) => !!m.reply);
  } else if (status === "pending") {
    filtered = filtered.filter((m) => !m.reply);
  }

  renderTable(
    "admin-support-messages",
    filtered,
    (m) => {
      const isReplied = !!m.reply;
      const statusText = isReplied ? "Replied" : "Pending Reply";
      const statusClass = isReplied ? "confirmed" : "pending";
      const badge = m.user_id
        ? '<span class="badge" style="font-size:0.62rem;padding:2px 6px;margin-left:4px">Patient</span>'
        : '<span style="font-size:0.62rem;color:var(--text-muted);margin-left:4px">(Guest)</span>';
      const phoneLine = m.phone
        ? `<div style="font-size:0.75rem;color:var(--text-muted);margin-top:2px"><i class="fas fa-phone" aria-hidden="true"></i> ${escapeHTML(m.phone)}</div>`
        : "";
      const deptLine = m.department
        ? `<div style="font-size:0.72rem;margin-top:3px"><span style="background:rgba(34,211,238,0.1);border:1px solid rgba(34,211,238,0.25);color:var(--primary);padding:1px 7px;border-radius:99px;font-size:0.68rem;font-weight:600">${escapeHTML(m.department)}</span></div>`
        : "";
      const msgPreview =
        (m.message || "").length > 120
          ? escapeHTML(m.message.substring(0, 120)) + "…"
          : escapeHTML(m.message || "");
      const replyPreview = isReplied
        ? `<div style="margin-top:4px;font-size:0.72rem;color:var(--primary)">↩ <em>${(m.reply || "").length > 80 ? escapeHTML(m.reply.substring(0, 80)) + "…" : escapeHTML(m.reply || "")}</em></div>`
        : "";
      const actionButton = `<button class="btn btn-outline btn-sm" onclick="openReplyModal(${m.id})">${isReplied ? '<i class="fas fa-pen-to-square" aria-hidden="true"></i> Edit Reply' : '<i class="fas fa-comments" aria-hidden="true"></i> Reply'}</button>`;

      return `
      <tr>
        <td style="min-width:90px">${escapeHTML(formatDate(m.created_at))}</td>
        <td>
          <div style="font-weight:600;font-size:0.88rem">${escapeHTML(m.name || "Guest")}${badge}</div>
          <div style="font-size:0.78rem;color:var(--text-muted);margin-top:2px">${escapeHTML(m.email)}</div>
          ${phoneLine}
          ${deptLine}
        </td>
        <td style="max-width:320px">
          <div style="font-weight:600;font-size:0.88rem;color:var(--text-primary)">${escapeHTML(m.subject || "No Subject")}</div>
          <div style="font-size:0.78rem;color:var(--text-secondary);margin-top:3px;line-height:1.4">${msgPreview}</div>
          ${replyPreview}
        </td>
        <td><span class="status status-${statusClass}">${statusText}</span></td>
        <td>${actionButton}</td>
      </tr>`;
    },
    "No support messages found.",
    5,
  );
}

function toggleReplyMessageModal() {
  const modal = document.getElementById("reply-message-modal");
  if (!modal) {
    console.error("Modal element #reply-message-modal not found.");
    return;
  }
  modal.classList.toggle("open");
}

function openReplyModal(id) {
  const msg = allContactMessages.find((m) => Number(m.id) === Number(id));
  if (!msg) {
    console.error("No message matching ID found:", id);
    showToast("Error: Could not locate message details.", "error");
    return;
  }

  const idInput = document.getElementById("reply-msg-id");
  const senderEl = document.getElementById("reply-msg-sender");
  const emailEl = document.getElementById("reply-msg-email");
  const dateEl = document.getElementById("reply-msg-date");
  const subjectEl = document.getElementById("reply-msg-subject");
  const bodyEl = document.getElementById("reply-msg-body");
  const replyInput = document.getElementById("reply-text");

  if (idInput) idInput.value = msg.id;
  if (senderEl) senderEl.textContent = msg.name || "Guest";
  if (emailEl) emailEl.textContent = msg.email;
  if (dateEl) dateEl.textContent = formatDate(msg.created_at);
  if (subjectEl) subjectEl.textContent = msg.subject || "No Subject";
  if (bodyEl) bodyEl.textContent = msg.message;
  if (replyInput) replyInput.value = msg.reply || "";

  toggleReplyMessageModal();
}

// Expose openReplyModal to global scope
window.openReplyModal = openReplyModal;
window.toggleReplyMessageModal = toggleReplyMessageModal;
window.filterSupportMessages = filterSupportMessages;

/* ── DOCTOR PROFILE MODAL (NEW PROFESSIONAL TABBED DESIGN) ── */

// Currently loaded doctor data
let _currentDoctorData = null;
let _profileDoctorId = null;
let _profileDepartments = [];
let _loadedTabs = {};

function openDoctorProfileModal() {
  const modal = document.getElementById("doctor-profile-modal");
  if (modal) modal.classList.add("open");
}

function closeDoctorProfile() {
  const modal = document.getElementById("doctor-profile-modal");
  if (modal) modal.classList.remove("open");
  _currentDoctorData = null;
  _profileDoctorId = null;
  _loadedTabs = {};
}

async function openDoctorProfile(id) {
  _profileDoctorId = id;
  _loadedTabs = {};
  openDoctorProfileModal();

  // Reset content to loading state
  setText("dp-full-name", "Loading...");
  setText("dp-department-display", "—");
  setText("dp-rating-display", "—");
  setText("dp-exp-display", "—");
  setText("dp-email-display", "—");
  setText("dp-phone-display", "—");
  setText("dp-joined-display", "—");
  setText("dp-overview-rating", "—");
  setText("dp-overview-completed", "—");
  setText("dp-overview-total", "—");
  setText("dp-overview-reviews", "—");
  setText("dp-info-name", "—");
  setText("dp-info-email", "—");
  setText("dp-info-phone", "—");
  setText("dp-info-dept", "—");
  setText("dp-info-status", "—");
  setText("dp-info-availability", "—");
  setText("dp-info-joined", "—");
  setText("dp-prof-dept", "—");
  setText("dp-prof-specialty", "—");
  setText("dp-prof-exp", "—");

  document.getElementById("dp-schedule-content").innerHTML =
    '<div class="empty-state"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i><p>Loading schedule...</p></div>';
  document.getElementById("dp-appointments-content").innerHTML =
    '<div class="empty-state"><i class="fas fa-calendar" aria-hidden="true"></i><p>No appointments found.</p></div>';
  document.getElementById("dp-reviews-content").innerHTML =
    '<div class="empty-state"><i class="fas fa-star" aria-hidden="true"></i><p>No reviews yet.</p></div>';
  document.getElementById("dp-audit-content").innerHTML =
    '<div class="empty-state"><i class="fas fa-history" aria-hidden="true"></i><p>Loading audit log...</p></div>';

  // Load departments for modals
  _profileDepartments = [];
  const deptResult = await apiFetch((getBasePath() + "api/departments/get.php"), {}, "Failed to load departments.");
  if (deptResult.ok && deptResult.data?.departments) {
    _profileDepartments = deptResult.data.departments.filter(d => d.status === 'active');
  }

  // Fetch doctor profile
  const result = await apiFetch(
    `${getBasePath()}api/doctors/profile.php?id=${id}`,
    {},
    "Failed to load doctor profile.",
  );
  if (!result.ok || !result.data?.success) {
    showToast(result.data?.message || "Failed to load doctor profile.", "error");
    closeDoctorProfile();
    return;
  }

  const data = result.data;
  const doc = data.doctor;
  _currentDoctorData = data;
  window._currentDoctorProfile = doc;
  window._currentPatientProfile = null;

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
    statusBadge.className = `doctor-badge ${isActive ? 'doctor-badge-active' : 'doctor-badge-inactive'}`;
    statusBadge.innerHTML = `<i class="fas fa-circle" aria-hidden="true"></i> ${isActive ? 'Active' : 'Inactive'}`;
  }

  // Availability badge
  const availBadge = document.getElementById("dp-availability-badge");
  if (availBadge) {
    const isAvail = doc.available == 1;
    availBadge.className = `doctor-badge ${isAvail ? 'doctor-badge-available' : 'doctor-badge-unavailable'}`;
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

  // Pre-populate edit modal fields
  document.getElementById("dp-edit-id").value = doc.id;
  document.getElementById("dp-edit-name").value = doc.name;
  document.getElementById("dp-edit-email").value = doc.email;
  document.getElementById("dp-edit-phone").value = doc.phone || "";

  // Populate reassign modal
  document.getElementById("dp-reassign-id").value = doc.id;
  document.getElementById("dp-reassign-name").textContent = doc.name;

  // Switch to overview tab
  switchDoctorTab("overview");
}

/* ── TAB SWITCHING ──────────────────────────────────────── */

function switchDoctorTab(tabId) {
  // Update tab buttons
  document.querySelectorAll(".doctor-profile-tab").forEach(tab => {
    tab.classList.toggle("active", tab.dataset.tab === tabId);
  });
  // Update tab content panels
  document.querySelectorAll(".doctor-profile-tab-content").forEach(content => {
    content.classList.toggle("active", content.id === `dp-tab-${tabId}`);
  });
  // Lazy-load data tabs (only once per profile session)
  if (tabId === "schedule" && !_loadedTabs.schedule) {
    _loadedTabs.schedule = true;
    loadProfileSchedule();
  }
  if (tabId === "appointments" && !_loadedTabs.appointments) {
    _loadedTabs.appointments = true;
    loadProfileAppointments();
  }
  if (tabId === "reviews" && !_loadedTabs.reviews) {
    _loadedTabs.reviews = true;
    loadProfileReviews();
  }
  if (tabId === "audit" && !_loadedTabs.audit) {
    _loadedTabs.audit = true;
    loadProfileAudit();
  }
}

/* ── LAZY TAB LOADERS ───────────────────────────────────── */

async function loadProfileSchedule() {
  const container = document.getElementById("dp-schedule-content");
  if (!container || !_profileDoctorId) return;
  try {
    const result = await apiFetch(
      `${getBasePath()}api/schedule/get.php?doctor_id=${_profileDoctorId}`,
      {},
      "Failed to load schedule."
    );
    if (!result.ok || !result.data?.success) {
      container.innerHTML = '<div class="empty-state"><i class="fas fa-clock" aria-hidden="true"></i><p>No schedule configured.</p></div>';
      return;
    }
    const sched = result.data;
    if (!sched.days || sched.days.length === 0) {
      container.innerHTML = '<div class="empty-state"><i class="fas fa-clock" aria-hidden="true"></i><p>No schedule configured.</p></div>';
      return;
    }
    let html = '<div class="schedule-info-list">';
    const dayNames = { mon:'Monday', tue:'Tuesday', wed:'Wednesday', thu:'Thursday', fri:'Friday', sat:'Saturday', sun:'Sunday' };
    sched.days.forEach(day => {
      const dayLabel = dayNames[day.day] || day.day;
      if (day.active) {
        html += `<div class="schedule-info-row">
          <span class="schedule-day-label">${escapeHTML(dayLabel)}</span>
          <span class="schedule-time-label">${escapeHTML(day.start || '—')} – ${escapeHTML(day.end || '—')}</span>
          ${day.break_start && day.break_end
            ? `<span class="schedule-break-label">Break: ${escapeHTML(day.break_start)} – ${escapeHTML(day.break_end)}</span>`
            : ''}
        </div>`;
      } else {
        html += `<div class="schedule-info-row">
          <span class="schedule-day-label">${escapeHTML(dayLabel)}</span>
          <span style="color:var(--text-muted);font-style:italic">Off</span>
        </div>`;
      }
    });
    html += '</div>';
    container.innerHTML = html;
  } catch (e) {
    container.innerHTML = '<div class="empty-state"><i class="fas fa-exclamation-triangle" aria-hidden="true"></i><p>Failed to load schedule.</p></div>';
  }
}

async function loadProfileAppointments() {
  const container = document.getElementById("dp-appointments-content");
  if (!container || !_profileDoctorId) return;
  try {
    const result = await apiFetch(
      `${getBasePath()}api/appointments/get-doctor.php?doctor_id=${_profileDoctorId}`,
      {},
      "Failed to load appointments."
    );
    const appts = (result.ok && result.data?.appointments) ? result.data.appointments : [];
    if (appts.length === 0) {
      container.innerHTML = '<div class="empty-state"><i class="fas fa-calendar" aria-hidden="true"></i><p>No appointments found.</p></div>';
      return;
    }
    const tableHtml = `<div class="table-wrap" style="max-height:350px;overflow-y:auto"><table>
      <thead><tr><th>Patient</th><th>Date & Time</th><th>Status</th></tr></thead>
      <tbody>${appts.map(a => {
        const statusLower = (a.status || "pending").toLowerCase();
        // If patient_id exists, link to Patient EMR
        const patientDisplay = a.patient_id
          ? `<a href="${getBasePath()}pages/patient/patient-emr.html?patient_id=${a.patient_id}" style="font-weight:600;color:var(--primary);text-decoration:none">${escapeHTML(a.patient_name)}</a>`
          : `<span style="font-weight:600">${escapeHTML(a.patient_name)}</span>`;
        return `<tr>
          <td>${patientDisplay}</td>
          <td style="font-size:0.8rem">${escapeHTML(formatDate(a.date))} ${escapeHTML(formatApptTime(a))}</td>
          <td><span class="status status-${statusLower}">${escapeHTML(a.status)}</span></td>
        </tr>`;
      }).join('')}</tbody>
    </table></div>`;
    container.innerHTML = tableHtml;
  } catch (e) {
    container.innerHTML = '<div class="empty-state"><i class="fas fa-exclamation-triangle" aria-hidden="true"></i><p>Failed to load appointments.</p></div>';
  }
}

async function loadProfileReviews() {
  const container = document.getElementById("dp-reviews-content");
  if (!container || !_profileDoctorId) return;
  try {
    const result = await apiFetch(
      `${getBasePath()}api/doctors/get-doctor-ratings.php?doctor_id=${_profileDoctorId}`,
      {},
      "Failed to load reviews."
    );
    const reviews = (result.ok && result.data?.ratings) ? result.data.ratings : [];
    if (reviews.length === 0) {
      container.innerHTML = '<div class="empty-state"><i class="fas fa-star" aria-hidden="true"></i><p>No reviews yet.</p></div>';
      return;
    }
    const tableHtml = `<div class="table-wrap" style="max-height:350px;overflow-y:auto"><table>
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
          <td style="max-width:250px;word-break:break-word">${comment}</td>
          <td style="font-size:0.8rem">${escapeHTML(formatDate(r.created_at || r.review_date))}</td>
        </tr>`;
      }).join('')}</tbody>
    </table></div>`;
    container.innerHTML = tableHtml;
  } catch (e) {
    container.innerHTML = '<div class="empty-state"><i class="fas fa-exclamation-triangle" aria-hidden="true"></i><p>Failed to load reviews.</p></div>';
  }
}

async function loadProfileAudit() {
  const container = document.getElementById("dp-audit-content");
  if (!container || !_profileDoctorId) return;
  try {
    const result = await apiFetch(
      `${getBasePath()}api/audit/get.php?entity_type=doctor&entity_id=${_profileDoctorId}&limit=20`,
      {},
      "Failed to load audit log."
    );
    const entries = (result.ok && result.data?.entries) ? result.data.entries : [];
    if (entries.length === 0) {
      container.innerHTML = '<div class="empty-state"><i class="fas fa-history" aria-hidden="true"></i><p>No administrative activity recorded for this doctor.</p></div>';
      return;
    }
    const actionIcons = { create:'fa-plus-circle', update:'fa-pen', delete:'fa-trash', activate:'fa-toggle-on', deactivate:'fa-toggle-off', reassign:'fa-right-left' };
    const html = entries.map(e => {
      const icon = actionIcons[e.action] || 'fa-circle';
      return `<div class="audit-entry">
        <div class="audit-entry-icon"><i class="fas ${icon}" aria-hidden="true"></i></div>
        <div class="audit-entry-body">
          <div class="audit-entry-action">${escapeHTML(e.action)} by ${escapeHTML(e.admin_name || 'System')}</div>
          <div class="audit-entry-meta">${escapeHTML(formatDate(e.created_at))}${e.old_value ? ' — ' + escapeHTML(e.old_value) : ''}${e.new_value ? ' → ' + escapeHTML(e.new_value) : ''}</div>
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

  // Populate department dropdown
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
  // Reload departments in background for next open
  loadDepartmentsForEdit();
}

async function loadDepartmentsForEdit() {
  const deptSelect = document.getElementById("dp-edit-department");
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

  fetchAction(
    (getBasePath() + "api/doctors/toggle-status.php"),
    { id: doc.id },
    `Dr. ${doc.name} updated.`,
    "Status update failed.",
    () => {
      loadDoctorsTable();
      openDoctorProfile(doc.id);
    },
  );
}

async function deleteCurrentDoctor() {
  if (!_currentDoctorData) return;
  const doc = _currentDoctorData.doctor;
  if (!confirm(`Delete Dr. ${doc.name}? This will also cancel all their pending and confirmed appointments.`)) return;

  closeDoctorProfile();
  fetchAction(
    (getBasePath() + "api/doctors/delete.php"),
    { id: doc.id },
    `Dr. ${doc.name} has been deleted.`,
    "Delete failed.",
    () => {
      loadDoctorsTable();
      initAdminPage();
    },
  );
}

/* ── EDIT FORM (submitted from edit modal) ──────────────── */

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

    if (!name || !email) {
      showToast("Name and email are required.", "error");
      return;
    }

    const result = await apiFetch(
      (getBasePath() + "api/doctors/update.php"),
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ id, name, email, phone, department_id: departmentId }),
      },
      "Failed to update doctor details.",
    );

    if (result.data?.success) {
      showToast("Doctor details updated successfully.", "success");
      closeDoctorEditModal();
      loadDoctorsTable();
      _loadedTabs = {};
      openDoctorProfile(id);
    } else {
      showToast(result.data?.message || "Failed to update doctor details.", "error");
    }
  });
}

/* ── REASSIGN FORM ──────────────────────────────────────── */

(function initDoctorReassignForm() {
  document.addEventListener("DOMContentLoaded", () => {
    const form = document.getElementById("dp-reassign-form");
    if (!form) return;
    form.addEventListener("submit", async (e) => {
      e.preventDefault();
      const id = document.getElementById("dp-reassign-id")?.value;
      const departmentId = document.getElementById("dp-reassign-department")?.value;
      if (!id || !departmentId) {
        showToast("Please select a department.", "error");
        return;
      }

      const result = await apiFetch(
        (getBasePath() + "api/doctors/update.php"),
        {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ id, department_id: departmentId }),
        },
        "Failed to reassign department.",
      );

      if (result.data?.success) {
        showToast("Department reassigned successfully.", "success");
        closeDoctorReassignModal();
        loadDoctorsTable();
        _loadedTabs = {};
        openDoctorProfile(id);
      } else {
        showToast(result.data?.message || "Failed to reassign department.", "error");
      }
    });
  });
})();

// Expose profile functions to global scope
window.openDoctorProfile = openDoctorProfile;
window.closeDoctorProfile = closeDoctorProfile;
window.switchDoctorTab = switchDoctorTab;
window.editCurrentDoctor = editCurrentDoctor;
window.closeDoctorEditModal = closeDoctorEditModal;
window.reassignCurrentDoctorDepartment = reassignCurrentDoctorDepartment;
window.closeDoctorReassignModal = closeDoctorReassignModal;
window.toggleCurrentDoctorStatus = toggleCurrentDoctorStatus;
window.deleteCurrentDoctor = deleteCurrentDoctor;

/* ── PROFILE APPOINTMENT ACTIONS ─────────────────────────── */

// Approve an appointment from within a profile modal
async function profileApprove(apptId, context, profileId) {
  if (!confirm("Approve this appointment?")) return;
  fetchAction(
    (getBasePath() + "api/appointments/approve.php"),
    { id: apptId },
    "Appointment confirmed!",
    "Approval failed.",
    () => {
      initAdminPage();
      if (context === "doctor") {
        _loadedTabs = {};
        openDoctorProfile(profileId);
      }
    },
  );
}

// Decline an appointment from within a profile modal
async function profileDecline(apptId, context, profileId) {
  if (!confirm("Decline this appointment?")) return;
  fetchAction(
    (getBasePath() + "api/appointments/decline.php"),
    { id: apptId, reason: "Appointment declined by admin." },
    "Appointment declined!",
    "Decline failed.",
    () => {
      initAdminPage();
      if (context === "doctor") {
        _loadedTabs = {};
        openDoctorProfile(profileId);
      }
    },
  );
}

// Cancel a confirmed appointment from within a profile modal
async function profileCancel(apptId, context, profileId) {
  if (!confirm("Cancel this confirmed appointment?")) return;
  fetchAction(
    (getBasePath() + "api/appointments/decline.php"),
    { id: apptId, reason: "Appointment cancelled by admin." },
    "Appointment cancelled!",
    "Cancel failed.",
    () => {
      initAdminPage();
      if (context === "doctor") {
        _loadedTabs = {};
        openDoctorProfile(profileId);
      }
    },
  );
}

/* ── PROFILE BOOKING HELPERS ─────────────────────────────── */

// Opens the booking modal pre-filling data from the currently open Doctor profile
function openBookingForDoctor() {
  const doc = window._currentDoctorProfile;
  if (!doc) return;
  closeDoctorProfile();

  // Set context so that after booking we reload this doctor's profile
  document.getElementById("admin-appt-context").value = "doctor";
  document.getElementById("admin-appt-context-id").value = doc.id;
  document.getElementById("book-modal-title").innerHTML =
    `<i class="fas fa-calendar-days" aria-hidden="true"></i> Book Appointment with Dr. ${escapeHTML(doc.name)}`;

  toggleBookAppointmentModal();

  // Pre-fill the department and doctor after the modal renders
  requestAnimationFrame(() => {
    const deptSel = document.getElementById("admin-appt-department");
    if (deptSel && doc.department_id) {
      deptSel.value = doc.department_id;
      deptSel.dispatchEvent(new Event("change"));
    }
    // After doctors load, pre-select this doctor by name
    setTimeout(() => {
      const doctorSel = document.getElementById("admin-appt-doctor");
      if (doctorSel) {
        const opt = [...doctorSel.options].find((o) =>
          o.text.startsWith(doc.name),
        );
        if (opt) doctorSel.value = opt.value;
      }
    }, 400);
  });
}

// Opens the booking modal pre-filling data from the currently open Patient profile
function openBookingForPatient() {
  const pat = window._currentPatientProfile;
  if (!pat) return;

  // Set context so that after booking we reload this patient's profile
  document.getElementById("admin-appt-context").value = "patient";
  document.getElementById("admin-appt-context-id").value = pat.id;
  document.getElementById("book-modal-title").innerHTML =
    `<i class="fas fa-calendar-days" aria-hidden="true"></i> Book Appointment for ${escapeHTML(pat.name)}`;

  document.getElementById("admin-appt-patient").value = pat.name;
  // Set the patient ID so the backend links the appointment to this patient
  const patientIdInput = document.getElementById("admin-appt-patient-id");
  if (patientIdInput) patientIdInput.value = pat.id;

  toggleBookAppointmentModal();
}

/* ============================================================
   PRESCRIPTIONS TAB (Admin Audit)
   ============================================================ */

function initAdminPrescriptionsTab() {
  document
    .querySelectorAll(".sidebar-nav a[href='#prescriptions']")
    .forEach((link) => {
      link.addEventListener("click", () => {
        setTimeout(() => loadAdminPrescriptions(), 100);
      });
    });

  const savedTab = localStorage.getItem("hb_admin_active_tab") || "overview";
  if (savedTab === "prescriptions") {
    setTimeout(() => loadAdminPrescriptions(), 150);
  }
}

let adminPrescriptionsCache = [];

async function loadAdminPrescriptions() {
  const status = document.getElementById("admin-rx-status-filter")?.value || "";
  const search = document.getElementById("admin-rx-search")?.value || "";

  const params = new URLSearchParams();
  if (status) params.set("status", status);
  if (search) params.set("search", search);

  const result = await apiFetch(
    `${getBasePath()}api/prescriptions/get.php?${params.toString()}`,
    {},
    "Failed to load prescriptions",
  );
  if (!result.ok || !result.data?.success) {
    showToast(result.data?.message || "Failed to load prescriptions", "error");
    return;
  }

  const prescriptions = result.data.prescriptions || [];
  adminPrescriptionsCache = prescriptions;
  Prescriptions.renderList(prescriptions, "admin-prescriptions-list", "admin");
}

function filterAdminPrescriptions() {
  loadAdminPrescriptions();
}
window.filterAdminPrescriptions = filterAdminPrescriptions;

window.profileApprove = profileApprove;
window.profileDecline = profileDecline;
window.profileCancel = profileCancel;
window.openBookingForDoctor = openBookingForDoctor;
window.openBookingForPatient = openBookingForPatient;

/* ═══════════════════════════════════════════════════════════
   SYSTEM SETTINGS — Hospital Appointment Hours
   ═══════════════════════════════════════════════════════════ */

/**
 * Generate time options for dropdowns (12:00 AM - 11:30 PM, 30-min steps).
 * Returns array of { value, label } objects.
 */
function generateFullTimeOptions() {
  const options = [];
  for (let h = 0; h < 24; h++) {
    for (let m = 0; m < 60; m += 30) {
      const value = `${String(h).padStart(2, "0")}:${String(m).padStart(2, "0")}`;
      const ampm = h >= 12 ? "PM" : "AM";
      const displayHour = h % 12 || 12;
      const label = `${displayHour}:${String(m).padStart(2, "0")} ${ampm}`;
      options.push({ value, label });
    }
  }
  return options;
}

/**
 * Initialize the hospital settings tab.
 */
function initHospitalSettingsTab() {
  // Bind sidebar nav click
  document
    .querySelectorAll(".sidebar-nav a[href='#system-settings']")
    .forEach((link) => {
      link.addEventListener("click", () => {
        setTimeout(() => loadHospitalSettings(), 100);
      });
    });

  // If the saved tab is system-settings, load immediately
  const savedTab = localStorage.getItem("hb_admin_active_tab") || "overview";
  if (savedTab === "system-settings") {
    setTimeout(() => loadHospitalSettings(), 150);
  }
}

/**
 * Load hospital settings from the API and populate the form.
 */
async function loadHospitalSettings() {
  const openSelect = document.getElementById("hospital-open-time");
  const closeSelect = document.getElementById("hospital-close-time");
  const durationSelect = document.getElementById("hospital-default-duration");
  const saveBtn = document.getElementById("hospital-settings-save-btn");

  if (!openSelect || !closeSelect) return;

  // Populate time dropdowns once
  if (openSelect.options.length === 0) {
    const timeOptions = generateFullTimeOptions();
    timeOptions.forEach((t) => {
      openSelect.add(new Option(t.label, t.value));
      closeSelect.add(new Option(t.label, t.value));
    });
  }

  setLoading(saveBtn, true, "Loading...");

  const result = await apiFetch(
    (getBasePath() + "api/settings/get-hospital.php"),
    {},
    "Failed to load settings.",
  );

  setLoading(saveBtn, false, "Save Settings");

  if (!result.ok || !result.data?.success) {
    showToast(result.data?.message || "Failed to load settings.", "error");
    return;
  }

  const settings = result.data.settings;
  if (settings) {
    openSelect.value = settings.appointment_open_time || "08:00";
    closeSelect.value = settings.appointment_close_time || "22:00";
    if (durationSelect) {
      durationSelect.value = settings.default_appointment_duration || "30";
    }
  }
}

/**
 * Save hospital settings via the API.
 */
async function saveHospitalSettings() {
  const openSelect = document.getElementById("hospital-open-time");
  const closeSelect = document.getElementById("hospital-close-time");
  const durationSelect = document.getElementById("hospital-default-duration");
  const saveBtn = document.getElementById("hospital-settings-save-btn");

  if (!openSelect || !closeSelect || !durationSelect) {
    showToast("Settings form not found.", "error");
    return;
  }

  const openTime = openSelect.value;
  const closeTime = closeSelect.value;
  const duration = parseInt(durationSelect.value, 10);

  if (openTime >= closeTime) {
    showToast("Opening time must be before closing time.", "error");
    return;
  }

  setLoading(saveBtn, true, "Saving...");

  const result = await apiFetch(
    (getBasePath() + "api/settings/update-hospital.php"),
    {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        appointment_open_time: openTime,
        appointment_close_time: closeTime,
        default_appointment_duration: duration,
      }),
    },
    "Failed to save settings.",
  );

  setLoading(saveBtn, false, "Save Settings");

  if (result.data?.success) {
    showToast("Hospital settings updated successfully!", "success");
  } else {
    showToast(result.data?.message || "Failed to save settings.", "error");
  }
}

/* ═══════════════════════════════════════════════════════════
   AUDIT LOG — Activity History
   ═══════════════════════════════════════════════════════════ */

let auditCurrentPage = 1;
let auditTotalPages = 1;
let auditFilterOptions = { entity_types: [], actions: [], admins: [] };

/**
 * Initialize the audit log tab.
 */
function initAuditLogTab() {
  document
    .querySelectorAll(".sidebar-nav a[href='#audit-log']")
    .forEach((link) => {
      link.addEventListener("click", () => {
        setTimeout(() => loadAuditLog(), 100);
      });
    });

  const savedTab = localStorage.getItem("hb_admin_active_tab") || "overview";
  if (savedTab === "audit-log") {
    setTimeout(() => loadAuditLog(), 150);
  }
}

/**
 * Load audit log entries from the API.
 */
async function loadAuditLog() {
  const tbody = document.getElementById("audit-log-entries");
  if (!tbody) return;

  tbody.innerHTML =
    '<tr><td colspan="7" class="text-center" style="padding: var(--s8); color: var(--text-muted)">Loading audit log...</td></tr>';

  const search = document.getElementById("audit-search")?.value || "";
  const action = document.getElementById("audit-action-filter")?.value || "";
  const entityType =
    document.getElementById("audit-entity-filter")?.value || "";
  const adminId = document.getElementById("audit-admin-filter")?.value || "";
  const dateFrom = document.getElementById("audit-date-from")?.value || "";
  const dateTo = document.getElementById("audit-date-to")?.value || "";

  const params = new URLSearchParams();
  params.set("page", auditCurrentPage);
  params.set("limit", "20");
  if (search) params.set("search", search);
  if (action) params.set("action", action);
  if (entityType) params.set("entity_type", entityType);
  if (adminId) params.set("admin_id", adminId);
  if (dateFrom) params.set("date_from", dateFrom);
  if (dateTo) params.set("date_to", dateTo);

  const result = await apiFetch(
    `${getBasePath()}api/audit/get.php?${params.toString()}`,
    {},
    "Failed to load audit log.",
  );

  if (!result.ok || !result.data?.success) {
    tbody.innerHTML = `<tr><td colspan="7" class="text-center" style="padding: var(--s8); color: var(--danger)">${escapeHTML(result.data?.message || "Failed to load audit log.")}</td></tr>`;
    return;
  }

  const data = result.data;
  auditTotalPages = data.total_pages || 1;

  // Populate filter dropdowns on first load
  if (data.filter_options) {
    populateAuditFilterDropdowns(data.filter_options);
  }

  renderAuditLog(data.entries || []);
  updateAuditPagination(data);
}

/**
 * Populate the filter dropdowns with options from the API.
 */
function populateAuditFilterDropdowns(options) {
  if (!options) return;

  // Actions
  const actionSelect = document.getElementById("audit-action-filter");
  if (actionSelect && options.actions && actionSelect.options.length <= 1) {
    (options.actions || []).forEach((a) => {
      const opt = document.createElement("option");
      opt.value = a;
      opt.textContent = a.charAt(0).toUpperCase() + a.slice(1);
      actionSelect.appendChild(opt);
    });
  }

  // Entity types
  const entitySelect = document.getElementById("audit-entity-filter");
  if (entitySelect && options.entity_types && entitySelect.options.length <= 1) {
    (options.entity_types || []).forEach((et) => {
      const opt = document.createElement("option");
      opt.value = et;
      opt.textContent = et.charAt(0).toUpperCase() + et.slice(1);
      entitySelect.appendChild(opt);
    });
  }

  // Admins
  const adminSelect = document.getElementById("audit-admin-filter");
  if (adminSelect && options.admins && adminSelect.options.length <= 1) {
    (options.admins || []).forEach((a) => {
      const opt = document.createElement("option");
      opt.value = a.admin_id;
      opt.textContent = escapeHTML(a.name);
      adminSelect.appendChild(opt);
    });
  }
}

/**
 * Render audit log entries in the table.
 */
function renderAuditLog(entries) {
  const tbody = document.getElementById("audit-log-entries");
  if (!tbody) return;

  if (entries.length === 0) {
    tbody.innerHTML =
      '<tr><td colspan="7" class="text-center" style="padding: var(--s8); color: var(--text-muted)">No audit entries found.</td></tr>';
    return;
  }

  tbody.innerHTML = entries
    .map((entry) => {
      const actionLabel = (entry.action || "")
        .replace(/_/g, " ")
        .replace(/\b\w/g, (c) => c.toUpperCase());
      const entityLabel = (entry.entity_type || "")
        .replace(/_/g, " ")
        .replace(/\b\w/g, (c) => c.toUpperCase());
      const description = buildAuditDescription(entry);
      const dateStr = formatDate(entry.created_at);
      const timeStr = entry.created_at
        ? new Date(entry.created_at).toLocaleTimeString(undefined, {
            hour: "2-digit",
            minute: "2-digit",
          })
        : "";

      return `
      <tr>
        <td style="white-space: nowrap; font-size: 0.82rem;">${escapeHTML(dateStr)} ${escapeHTML(timeStr)}</td>
        <td style="font-weight: 600;">${escapeHTML(entry.actor_name || entry.admin_name || "Unknown")}</td>
        <td><span class="status status-${getAuditActionClass(entry.action)}">${escapeHTML(actionLabel)}</span></td>
        <td>${escapeHTML(entityLabel)}</td>
        <td style="font-size: 0.82rem; color: var(--text-secondary);">${entry.entity_id ? escapeHTML(String(entry.entity_id)) : "—"}</td>
        <td style="max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 0.85rem;">${escapeHTML(description)}</td>
        <td>
          <button class="btn btn-outline btn-sm" onclick="openAuditDetails(${entry.id})">
            <i class="fas fa-eye" aria-hidden="true"></i> View
          </button>
        </td>
      </tr>`;
    })
    .join("");
}

/**
 * Build a human-readable description from an audit entry.
 */
function buildAuditDescription(entry) {
  const action = entry.action || "";
  const entityType = entry.entity_type || "";

  // Simple actions with string old/new values
  if (action === "activate" || action === "deactivate") {
    return `${action === "activate" ? "Activated" : "Deactivated"} ${entityType}`;
  }

  if (action === "create") {
    return `Created new ${entityType}`;
  }

  if (action === "delete") {
    return `Deleted ${entityType}`;
  }

  if (action === "reassign") {
    return `Reassigned ${entityType}`;
  }

  // Try to parse JSON for meaningful description
  if (entry.new_value) {
    const parsed = tryParseJSON(entry.new_value);
    if (parsed && typeof parsed === "object") {
      const changed = Object.keys(parsed)
        .filter((k) => k !== "updated_at" && k !== "created_at")
        .slice(0, 3)
        .map((k) => k.replace(/_/g, " "))
        .join(", ");
      if (changed) return `Updated: ${changed}`;
    }
  }

  return `${action} ${entityType}`;
}

/**
 * Get CSS class for an action status badge.
 */
function getAuditActionClass(action) {
  const map = {
    create: "confirmed",
    update: "pending",
    delete: "cancelled",
    activate: "confirmed",
    deactivate: "cancelled",
    reassign: "pending",
  };
  return map[action] || "pending";
}

/**
 * Update pagination controls.
 */
function updateAuditPagination(data) {
  const info = document.getElementById("audit-pagination-info");
  const current = document.getElementById("audit-page-current");
  const prevBtn = document.getElementById("audit-prev-btn");
  const nextBtn = document.getElementById("audit-next-btn");

  if (info) {
    info.textContent = `${data.total || 0} total entries — Page ${data.page || 1} of ${data.total_pages || 1}`;
  }
  if (current) {
    current.textContent = `Page ${data.page || 1}`;
  }
  if (prevBtn) {
    prevBtn.disabled = (data.page || 1) <= 1;
  }
  if (nextBtn) {
    nextBtn.disabled = (data.page || 1) >= (data.total_pages || 1);
  }
}

/**
 * Navigate audit log pages.
 */
function goAuditPage(direction) {
  const newPage = auditCurrentPage + direction;
  if (newPage < 1 || newPage > auditTotalPages) return;
  auditCurrentPage = newPage;
  loadAuditLog();
}

/**
 * Filter the audit log (reset to page 1).
 */
function filterAuditLog() {
  auditCurrentPage = 1;
  loadAuditLog();
}

/**
 * Refresh the audit log.
 */
function refreshAuditLog() {
  loadAuditLog();
}

/**
 * Open the audit details modal for a specific entry.
 */
function openAuditDetails(entryId) {
  // Find the entry by ID from the current page's rendered data
  const row = document.querySelector(`#audit-log-entries button[onclick*="${entryId}"]`)?.closest("tr");
  if (!row) {
    showToast("Audit entry not found.", "error");
    return;
  }

  const cells = row.querySelectorAll("td");
  if (cells.length < 7) {
    showToast("Audit entry data incomplete.", "error");
    return;
  }

  // Build entry data from the DOM
  const entry = {
    id: entryId,
    admin_name: cells[1]?.textContent?.trim() || "Unknown",
    action: cells[2]?.textContent?.trim()?.toLowerCase().replace(/\s+/g, '_') || "update",
    entity_type: cells[3]?.textContent?.trim()?.toLowerCase().replace(/\s+/g, '_') || "",
    entity_id: parseInt(cells[4]?.textContent?.trim()) || null,
    created_at: cells[0]?.textContent?.trim() || null,
    ip_address: "",
    user_agent: "",
    old_value: "",
    new_value: "",
  };

  // Fetch full details from API
  _loadAuditDetailsForModal(entry);
}

/**
 * Fetch and display full audit details in modal.
 */
async function _loadAuditDetailsForModal(entry) {
  const result = await apiFetch(
    `${getBasePath()}api/audit/get.php?page=1&limit=50`,
    {},
    "Failed to load audit details.",
  );

  if (!result.ok || !result.data?.success) {
    showToast("Failed to load audit details.", "error");
    return;
  }

  const fullEntry = (result.data.entries || []).find(
    (e) => parseInt(e.id) === parseInt(entry.id)
  );
  const displayEntry = fullEntry || entry;

  if (!displayEntry) {
    showToast("Audit entry not found.", "error");
    return;
  }

  const content = document.getElementById("audit-details-content");
  if (!content) return;

  const actionLabel = (displayEntry.action || "")
    .replace(/_/g, " ")
    .replace(/\b\w/g, (c) => c.toUpperCase());
  const entityLabel = (displayEntry.entity_type || "")
    .replace(/_/g, " ")
    .replace(/\b\w/g, (c) => c.toUpperCase());
  const dateStr = displayEntry.created_at
    ? new Date(displayEntry.created_at).toLocaleString(undefined, {
        year: "numeric",
        month: "short",
        day: "numeric",
        hour: "2-digit",
        minute: "2-digit",
      })
    : "—";

  // Parse old/new values
  const oldValueHtml = formatAuditValue(displayEntry.old_value, "Old Value");
  const newValueHtml = formatAuditValue(displayEntry.new_value, "New Value");

  content.innerHTML = `
    <div class="audit-detail-grid">
      <div class="audit-detail-row">
        <span class="audit-detail-label">Admin</span>
        <span class="audit-detail-value">${escapeHTML(displayEntry.admin_name || "Unknown")}</span>
      </div>
      <div class="audit-detail-row">
        <span class="audit-detail-label">Action</span>
        <span class="audit-detail-value"><span class="status status-${getAuditActionClass(displayEntry.action)}">${escapeHTML(actionLabel)}</span></span>
      </div>
      <div class="audit-detail-row">
        <span class="audit-detail-label">Entity</span>
        <span class="audit-detail-value">${escapeHTML(entityLabel)}</span>
      </div>
      <div class="audit-detail-row">
        <span class="audit-detail-label">Entity ID</span>
        <span class="audit-detail-value">${displayEntry.entity_id ? escapeHTML(String(displayEntry.entity_id)) : "—"}</span>
      </div>
      <div class="audit-detail-row">
        <span class="audit-detail-label">Date & Time</span>
        <span class="audit-detail-value">${escapeHTML(dateStr)}</span>
      </div>
      <div class="audit-detail-row">
        <span class="audit-detail-label">IP Address</span>
        <span class="audit-detail-value">${escapeHTML(displayEntry.ip_address || "—")}</span>
      </div>
      <div class="audit-detail-row">
        <span class="audit-detail-label">User Agent</span>
        <span class="audit-detail-value" style="font-size: 0.78rem; word-break: break-all;">${escapeHTML(displayEntry.user_agent || "—")}</span>
      </div>
    </div>
    ${oldValueHtml}
    ${newValueHtml}
  `;

  toggleAuditDetailsModal();
}

/**
 * Format an audit value (old_value or new_value) for display.
 * Generic recursive renderer that handles any data type:
 *   null, boolean, number, string, array, nested object, array of objects, JSON strings.
 * Never produces "[object Object]".
 */
function formatAuditValue(value, label) {
  if (!value) return "";

  // Try to parse JSON strings into structured data
  const parsed = tryParseJSON(value);
  const data = (parsed !== null && typeof parsed === "object") ? parsed : value;

  return `
    <div class="audit-detail-section">
      <h4 class="audit-detail-section-title">${escapeHTML(label)}</h4>
      <div class="audit-change-grid">${renderAuditNode(data, 0)}</div>
    </div>`;
}

/**
 * Recursively render any JavaScript value into readable HTML.
 * @param {*} val - The value to render
 * @param {number} depth - Current nesting depth (0 = top level)
 * @returns {string} HTML string
 */
function renderAuditNode(val, depth) {
  // null / undefined
  if (val === null || val === undefined) {
    return '<span class="audit-null">\u2014</span>';
  }

  // boolean
  if (typeof val === "boolean") {
    const cls = val ? "audit-true" : "audit-false";
    const icon = val ? "fa-check-circle" : "fa-xmark-circle";
    const text = val ? "Yes" : "No";
    return `<span class="${cls}"><i class="fas ${icon}" aria-hidden="true"></i> ${text}</span>`;
  }

  // number
  if (typeof val === "number") {
    return `<span class="audit-number">${escapeHTML(String(val))}</span>`;
  }

  // string
  if (typeof val === "string") {
    return `<span class="audit-string">${escapeHTML(val)}</span>`;
  }

  // array
  if (Array.isArray(val)) {
    return renderAuditArray(val, depth);
  }

  // object (plain)
  if (typeof val === "object") {
    return renderAuditObject(val, depth);
  }

  // fallback (should never reach here)
  return `<span class="audit-string">${escapeHTML(String(val))}</span>`;
}

/**
 * Render an array as a formatted list.
 * Arrays of primitives render inline. Arrays of objects render as sub-tables.
 */
function renderAuditArray(arr, depth) {
  if (arr.length === 0) {
    return '<span class="audit-empty">(empty array)</span>';
  }

  // Check if array contains only primitives (not objects/arrays)
  const allPrimitives = arr.every(
    (item) => item === null || item === undefined || typeof item !== "object"
  );

  if (allPrimitives && arr.length <= 8) {
    // Render inline: "value1, value2, value3"
    const items = arr.map((item) => renderAuditNode(item, depth)).join('<span class="audit-sep">, </span>');
    return `<span>[ ${items} ]</span>`;
  }

  // Array of objects or long arrays — render as list
  const items = arr
    .map((item, idx) => {
      const rendered = renderAuditNode(item, depth + 1);
      return `<div class="audit-array-item">
        <span class="audit-array-index">#${idx + 1}</span>
        <div class="audit-array-value">${rendered}</div>
      </div>`;
    })
    .join("");

  return `<div class="audit-array">${items}</div>`;
}

/**
 * Render an object as a set of key/value rows.
 * Nested objects appear as indented sub-sections.
 */
function renderAuditObject(obj, depth) {
  const keys = Object.keys(obj).filter(
    (k) => k !== "updated_at" && k !== "created_at"
  );

  if (keys.length === 0) {
    return '<span class="audit-empty">(empty object)</span>';
  }

  const isTopLevel = depth === 0;

  const rows = keys
    .map((key) => {
      const displayKey = key.replace(/_/g, " ").replace(/\b\w/g, (c) =>
        c.toUpperCase()
      );
      const val = obj[key];
      const renderedVal = renderAuditNode(val, depth + 1);

      // Check if the rendered value is complex (contains HTML block elements)
      const isComplex = val !== null && typeof val === "object";

      if (isComplex) {
        // Nested object/array — render as sub-section
        return `
          <div class="audit-subsection" style="margin-left: ${Math.min(depth * 12, 36)}px">
            <div class="audit-subsection-title"><i class="fas fa-folder-open" aria-hidden="true"></i> ${escapeHTML(displayKey)}</div>
            <div class="audit-subsection-body">${renderedVal}</div>
          </div>`;
      }

      return `
        <div class="audit-change-row" style="padding-left: ${Math.min(depth * 12, 36)}px">
          <span class="audit-change-field">${escapeHTML(displayKey)}</span>
          <span class="audit-change-value">${renderedVal}</span>
        </div>`;
    })
    .join("");

  // Top-level objects get no extra wrapper; nested objects get a sub-section wrapper
  return isTopLevel
    ? rows
    : `<div class="audit-nested-object">${rows}</div>`;
}

/**
 * Safely try to parse a JSON string.
 */
function tryParseJSON(str) {
  if (!str) return null;
  try {
    const parsed = JSON.parse(str);
    if (typeof parsed === "object" && parsed !== null) return parsed;
    return null;
  } catch {
    return null;
  }
}

/**
 * Toggle the audit details modal.
 */
function toggleAuditDetailsModal() {
  const modal = document.getElementById("audit-details-modal");
  if (modal) modal.classList.toggle("open");
}

// Expose audit functions to global scope
window.loadAuditLog = loadAuditLog;
window.filterAuditLog = filterAuditLog;
window.goAuditPage = goAuditPage;
window.refreshAuditLog = refreshAuditLog;
window.openAuditDetails = openAuditDetails;
window.toggleAuditDetailsModal = toggleAuditDetailsModal;





