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

  attachAdminDirectoryActions();
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

/**
 * Delegate directory modal, password and filter controls after Admin role validation.
 */
function attachAdminDirectoryActions() {
  if (window._hbAdminDirectoryActionsBound) return;

  document.addEventListener("click", (event) => {
    const target = event.target instanceof Element ? event.target : null;
    if (!target) return;

    const action = target.closest("[data-admin-directory-action]");
    if (action) {
      event.preventDefault();
      switch (action.dataset.adminDirectoryAction) {
        case "toggle-add-doctor":
          if (typeof window.toggleAddDoctorModal === "function") window.toggleAddDoctorModal();
          break;
        case "toggle-add-patient":
          if (typeof window.toggleAddPatientModal === "function") window.toggleAddPatientModal();
          break;
      }
      return;
    }

    const pageAction = target.closest("[data-admin-page-action]");
    if (pageAction) {
      event.preventDefault();
      dispatchAdminPageAction(pageAction.dataset.adminPageAction);
      return;
    }

    const doctorTab = target.closest("[data-admin-doctor-tab]");
    if (doctorTab) {
      event.preventDefault();
      const tab = doctorTab.dataset.adminDoctorTab;
      if (["overview", "professional", "schedule", "appointments", "reviews", "audit"].includes(tab)
          && typeof window.switchDoctorTab === "function") {
        window.switchDoctorTab(tab);
      }
      return;
    }

    const overlay = target.closest("[data-admin-page-overlay]");
    if (overlay && event.target === overlay && overlay.dataset.adminPageOverlay === "doctor-profile") {
      if (typeof window.closeDoctorProfile === "function") window.closeDoctorProfile();
      return;
    }

    const recordAction = target.closest("[data-admin-record-action]");
    if (recordAction) {
      event.preventDefault();
      dispatchAdminRecordAction(recordAction);
      return;
    }

    const generatedAction = target.closest("[data-admin-generated-action]");
    if (generatedAction) {
      event.preventDefault();
      dispatchAdminGeneratedAction(generatedAction);
      return;
    }

    const passwordToggle = target.closest("[data-admin-password-toggle]");
    if (passwordToggle && typeof window.togglePasswordVisibility === "function") {
      event.preventDefault();
      window.togglePasswordVisibility(passwordToggle);
    }
  });

  const runDirectoryFilter = (event) => {
    const target = event.target instanceof Element ? event.target : null;
    const field = target?.closest("[data-admin-directory-filter], [data-admin-page-filter]");
    if (!field) return;

    const filterType = field.dataset.adminDirectoryFilter || field.dataset.adminPageFilter;
    switch (filterType) {
      case "doctors":
        if (typeof window.filterDoctors === "function") window.filterDoctors();
        break;
      case "patients":
        if (typeof window.filterPatients === "function") window.filterPatients();
        break;
      case "appointments":
        if (typeof window.filterAppointments === "function") window.filterAppointments();
        break;
      case "prescriptions":
        if (typeof window.filterAdminPrescriptions === "function") window.filterAdminPrescriptions();
        break;
      case "departments":
        if (typeof window.filterDepartments === "function") window.filterDepartments();
        break;
      case "support":
        if (typeof window.filterSupportMessages === "function") window.filterSupportMessages();
        break;
      case "audit":
        if (typeof window.filterAuditLog === "function") window.filterAuditLog();
        break;
    }
  };

  document.addEventListener("input", runDirectoryFilter);
  document.addEventListener("change", runDirectoryFilter);
  window._hbAdminDirectoryActionsBound = true;
}

/**
 * Dispatch page-level Admin modal, audit and hospital settings actions through
 * a fixed allowlist after the Admin role gate.
 */
function dispatchAdminPageAction(action) {
  switch (action) {
    case "toggle-add-department":
      if (typeof window.toggleAddDepartmentModal === "function") window.toggleAddDepartmentModal();
      break;
    case "toggle-edit-department":
      if (typeof window.toggleEditDepartmentModal === "function") window.toggleEditDepartmentModal();
      break;
    case "save-hospital-settings":
      if (typeof window.saveHospitalSettings === "function") window.saveHospitalSettings();
      break;
    case "reload-hospital-settings":
      if (typeof window.loadHospitalSettings === "function") window.loadHospitalSettings();
      break;
    case "refresh-audit-log":
      if (typeof window.refreshAuditLog === "function") window.refreshAuditLog();
      break;
    case "refresh-appointments":
      if (typeof window.refreshAdminAppointments === "function") window.refreshAdminAppointments();
      break;
    case "audit-page-prev":
      if (typeof window.goAuditPage === "function") window.goAuditPage(-1);
      break;
    case "audit-page-next":
      if (typeof window.goAuditPage === "function") window.goAuditPage(1);
      break;
    case "toggle-book-appointment":
      if (typeof window.toggleBookAppointmentModal === "function") window.toggleBookAppointmentModal();
      break;
    case "close-doctor-profile":
      if (typeof window.closeDoctorProfile === "function") window.closeDoctorProfile();
      break;
    case "edit-current-doctor":
      if (typeof window.editCurrentDoctor === "function") window.editCurrentDoctor();
      break;
    case "reassign-current-doctor":
      if (typeof window.reassignCurrentDoctorDepartment === "function") window.reassignCurrentDoctorDepartment();
      break;
    case "toggle-current-doctor-status":
      if (typeof window.toggleCurrentDoctorStatus === "function") window.toggleCurrentDoctorStatus();
      break;
    case "open-booking-for-doctor":
      if (typeof window.openBookingForDoctor === "function") window.openBookingForDoctor();
      break;
    case "delete-current-doctor":
      if (typeof window.deleteCurrentDoctor === "function") window.deleteCurrentDoctor();
      break;
    case "close-doctor-edit":
      if (typeof window.closeDoctorEditModal === "function") window.closeDoctorEditModal();
      break;
    case "close-doctor-reassign":
      if (typeof window.closeDoctorReassignModal === "function") window.closeDoctorReassignModal();
      break;
    case "toggle-reply-message":
      if (typeof window.toggleReplyMessageModal === "function") window.toggleReplyMessageModal();
      break;
    case "toggle-audit-details":
      if (typeof window.toggleAuditDetailsModal === "function") window.toggleAuditDetailsModal();
      break;
  }
}

/**
 * Dispatch remaining generated Admin controls through fixed action tokens.
 * Each action is tied to a current data record or rendered audit row.
 */
function dispatchAdminGeneratedAction(control) {
  const action = control?.dataset?.adminGeneratedAction;
  const recordId = safePositiveId(control?.dataset?.recordId);
  if (!recordId) return;

  switch (action) {
    case "schedule-edit":
    case "schedule-reset": {
      const schedule = allDoctorSchedules.find((item) =>
        item && typeof item === "object" && !Array.isArray(item)
        && safePositiveId(item.doctor_id) === recordId
      );
      if (!schedule) return;
      if (action === "schedule-edit") {
        if (typeof window.openAdminScheduleEditor === "function") {
          window.openAdminScheduleEditor(recordId);
        }
        return;
      }
      const doctorName = typeof schedule.doctor_name === "string" && schedule.doctor_name.trim()
        ? schedule.doctor_name.trim().slice(0, 100)
        : "Doctor";
      if (typeof window.adminResetSchedule === "function") {
        window.adminResetSchedule(recordId, doctorName);
      }
      return;
    }
    case "support-reply": {
      const message = allContactMessages.find((item) =>
        item && typeof item === "object" && !Array.isArray(item)
        && safePositiveId(item.id) === recordId
      );
      if (!message) return;
      if (typeof window.openReplyModal === "function") window.openReplyModal(recordId);
      return;
    }
    case "audit-view": {
      const row = Array.from(document.querySelectorAll("#audit-log-entries tr")).find((candidate) =>
        candidate.querySelector("[data-audit-id]")?.getAttribute("data-audit-id") === String(recordId)
      );
      if (!row) return;
      if (typeof window.openAuditDetails === "function") window.openAuditDetails(recordId);
      return;
    }
  }
}

/**
 * Dispatch Admin table actions only for records currently present in the
 * relevant page data set. IDs are revalidated and names are never trusted
 * from DOM attributes.
 */
function dispatchAdminRecordAction(control) {
  const action = control?.dataset?.adminRecordAction;
  const recordId = safePositiveId(control?.dataset?.recordId);
  if (!recordId) return;

  const findRecord = (records) => records.find((record) =>
    record && typeof record === "object" && !Array.isArray(record)
    && safePositiveId(record.id) === recordId
  );
  const displayName = (record, fallback) =>
    typeof record?.name === "string" && record.name.trim()
      ? record.name.trim().slice(0, 100)
      : fallback;

  switch (action) {
    case "appointment-approve":
    case "appointment-decline": {
      const appointment = findRecord(allAppointments);
      if (!appointment || String(appointment.status || "Pending").toLowerCase() !== "pending") return;
      if (action === "appointment-approve" && typeof window.adminApprove === "function") {
        window.adminApprove(recordId);
      } else if (action === "appointment-decline" && typeof window.adminDecline === "function") {
        window.adminDecline(recordId);
      }
      break;
    }
    case "doctor-toggle-status": {
      const doctor = findRecord(allDoctors);
      if (doctor && typeof window.toggleDoctorStatus === "function") {
        window.toggleDoctorStatus(recordId, displayName(doctor, "Doctor"));
      }
      break;
    }
    case "doctor-archive": {
      const doctor = findRecord(allDoctors);
      if (doctor && typeof window.adminDeleteDoctor === "function") {
        window.adminDeleteDoctor(recordId, displayName(doctor, "Doctor"));
      }
      break;
    }
    case "patient-toggle-status": {
      const patient = findRecord(allPatients);
      if (patient && typeof window.togglePatientStatus === "function") {
        window.togglePatientStatus(recordId, displayName(patient, "Patient"));
      }
      break;
    }
    case "patient-archive": {
      const patient = findRecord(allPatients);
      if (patient && typeof window.adminDeletePatient === "function") {
        window.adminDeletePatient(recordId, displayName(patient, "Patient"));
      }
      break;
    }
  }
}

/**
 * Render table actions only when the row has a valid positive record ID.
 * Names are not copied to attributes; delegated dispatch resolves them from
 * the currently loaded page records.
 */
function renderAdminDoctorRecordActions(doctor) {
  const id = safePositiveId(doctor?.id);
  if (!id) return '<span class="text-muted">Unavailable</span>';
  const isActive = Number(doctor?.is_active) === 1;
  return `<button type="button" class="btn ${isActive ? "btn-outline" : "btn-danger"} btn-sm" data-admin-record-action="doctor-toggle-status" data-record-id="${id}" data-csp-style="admin-8896c95d8b21">${isActive ? "Disable" : "Enable"}</button>
    <button type="button" class="btn btn-outline btn-sm" data-admin-record-action="doctor-archive" data-record-id="${id}">Archive</button>`;
}

function renderAdminPatientRecordActions(patient) {
  const id = safePositiveId(patient?.id);
  if (!id) return '<span class="text-muted">Unavailable</span>';
  const isActive = Number(patient?.is_active) === 1;
  return `<button type="button" class="btn ${isActive ? "btn-outline" : "btn-danger"} btn-sm" data-admin-record-action="patient-toggle-status" data-record-id="${id}" data-csp-style="admin-8896c95d8b21">${isActive ? "Disable" : "Enable"}</button>
    <button type="button" class="btn btn-outline btn-sm" data-admin-record-action="patient-archive" data-record-id="${id}">Archive</button>`;
}

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
    "date",
    "time",
  ]);
  if (status)
    filtered = filtered.filter(
      (a) => String(a.status || "Pending").toLowerCase() === status.toLowerCase(),
    );

  renderTable(
    "admin-appointments",
    filtered,
    (a) => {
      const currentStatus = typeof a.status === "string" && a.status.trim()
        ? a.status.trim()
        : "Pending";
      const statusLower = safeClassToken(currentStatus);
      const appointmentId = safePositiveId(a.id);
      const actions = appointmentId && currentStatus.toLowerCase() === "pending"
        ? `<div class="admin-appointment-actions">
            <button type="button" class="btn btn-primary btn-sm" data-admin-record-action="appointment-approve" data-record-id="${appointmentId}">Approve</button>
            <button type="button" class="btn btn-danger btn-sm" data-admin-record-action="appointment-decline" data-record-id="${appointmentId}">Decline</button>
          </div>`
        : '<span class="text-muted">—</span>';
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

function refreshAdminAppointments() {
  showToast("Refreshing appointments...", "info");
  return initAdminPage();
}

/* ---- Approve / Decline ---- */

function adminApprove(id) {
  return submitAdminAppointmentDecision(id, "approve");
}

function adminDecline(id) {
  return submitAdminAppointmentDecision(id, "decline");
}

const adminAppointmentActionsInFlight = new Set();

async function submitAdminAppointmentDecision(id, decision) {
  const appointmentId = safePositiveId(id);
  const appointment = allAppointments.find((item) =>
    safePositiveId(item?.id) === appointmentId
  );
  const isApprove = decision === "approve";

  if (!appointmentId || !appointment || String(appointment.status || "Pending").toLowerCase() !== "pending") {
    showToast("This appointment is no longer pending. Refresh the list and try again.", "error");
    return;
  }
  if (!isApprove && decision !== "decline") return;
  if (adminAppointmentActionsInFlight.has(appointmentId)) return;

  const actionLabel = isApprove ? "approve" : "decline";
  const patientName = typeof appointment.patient_name === "string" && appointment.patient_name.trim()
    ? appointment.patient_name.trim()
    : "this patient";
  const appointmentDate = [appointment.date, formatApptTime(appointment)].filter(Boolean).join(" ");
  if (!confirm(`Are you sure you want to ${actionLabel} the appointment for ${patientName}${appointmentDate ? ` on ${appointmentDate}` : ""}?`)) {
    return;
  }

  adminAppointmentActionsInFlight.add(appointmentId);
  const actionSelector = `[data-record-id="${appointmentId}"][data-admin-record-action^="appointment-"]`;
  document.querySelectorAll(actionSelector).forEach((button) => {
    button.disabled = true;
    button.setAttribute("aria-busy", "true");
  });

  try {
    const response = await fetch(
      getBasePath() + `api/appointments/${isApprove ? "approve" : "decline"}.php`,
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        credentials: "same-origin",
        body: JSON.stringify(
          isApprove
            ? { id: appointmentId }
            : { id: appointmentId, reason: "Appointment declined by admin." }
        ),
      }
    );

    const result = await response.json().catch(() => null);
    const serverMessage = typeof result?.message === "string"
      ? result.message
      : typeof result?.data?.message === "string"
        ? result.data.message
        : "";

    if (!response.ok || result?.success !== true) {
      throw new Error(serverMessage || (isApprove ? "Approval failed." : "Decline failed."));
    }

    showToast(
      isApprove ? "Appointment approved successfully." : "Appointment declined successfully.",
      "success"
    );
    await initAdminPage();
  } catch (error) {
    showToast(
      error instanceof Error && error.message
        ? error.message
        : (isApprove ? "Approval failed. Please try again." : "Decline failed. Please try again."),
      "error"
    );
  } finally {
    adminAppointmentActionsInFlight.delete(appointmentId);
    document.querySelectorAll(actionSelector).forEach((button) => {
      button.disabled = false;
      button.removeAttribute("aria-busy");
    });
  }
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
      '<tr><td colspan="6" class="text-center" data-csp-style="admin-574315829b3e">Admin login required.</td></tr>';
    return;
  }

  allDoctors = Array.isArray(result.data.doctors)
    ? result.data.doctors.filter((doctor) =>
        doctor && typeof doctor === "object" && !Array.isArray(doctor)
      )
    : [];
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
      <td>${escapeHTML(formatDate(d.created_at))}</td>
      <td><span class="status status-${d.is_active ? "confirmed" : "cancelled"}">${d.is_active ? "Active" : "Disabled"}</span></td>
      <td>
        <a href="admin-doctor-profile.html?id=${safePositiveId(d.id)}" class="btn btn-profile btn-sm"><i class="fas fa-id-card" aria-hidden="true"></i> Profile</a>
        ${renderAdminDoctorRecordActions(d)}
      </td>
    </tr>
  `,
    "No doctors found.",
    6,
  );
}

function adminDeleteDoctor(id, name) {
  const safeId = safePositiveId(id);
  if (!safeId) return;
  if (
    !confirm(
      `Archive Dr. ${name}? Sign-in will be disabled and not-started appointments will be cancelled. Clinical and audit history will be retained.`,
    )
  )
    return;
  fetchAction(
    (getBasePath() + "api/doctors/delete.php"),
    { id: safeId },
    `Dr. ${name} has been archived. Clinical and audit history was retained.`,
    "Archive failed",
    () => {
      loadDoctorsTable();
      initAdminPage();
    },
  );
}

function toggleDoctorStatus(id, name) {
  const safeId = safePositiveId(id);
  if (!safeId) return;
  const doctor = allDoctors.find((entry) => safePositiveId(entry.id) === safeId);
  const isActive = Number(doctor?.is_active) === 1;
  const msg = isActive
    ? `Disable Dr. ${name}? Sign-in will be revoked, not-started appointments will be cancelled, and an active clinical visit must be completed first.`
    : `Enable Dr. ${name}?`;
  if (!confirm(msg)) return;

  fetchAction(
    (getBasePath() + "api/doctors/toggle-status.php"),
    { id: safeId },
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
  if (deptResult.ok && Array.isArray(deptResult.data?.departments)) {
    deptSelect.innerHTML = '<option value="">Select department</option>';
    deptResult.data.departments
      .filter((department) =>
        department && typeof department === "object" && !Array.isArray(department)
        && safePositiveId(department.id)
        && typeof department.name === "string"
        && department.status === "active"
      )
      .forEach((department) => {
        deptSelect.innerHTML += `<option value="${safePositiveId(department.id)}">${escapeHTML(department.name)}</option>`;
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
      '<tr><td colspan="6" class="text-center" data-csp-style="admin-574315829b3e">Admin login required.</td></tr>';
    return;
  }

  allPatients = Array.isArray(result.data.patients)
    ? result.data.patients.filter((patient) =>
        patient && typeof patient === "object" && !Array.isArray(patient)
      )
    : [];
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
      <td>${escapeHTML(formatDate(p.created_at))}</td>
      <td><span class="status status-${p.is_active ? "confirmed" : "cancelled"}">${p.is_active ? "Active" : "Disabled"}</span></td>
      <td>
        <a href="${getBasePath()}pages/patient/patient-emr.html?patient_id=${safePositiveId(p.id)}" class="btn btn-primary btn-sm" title="Open Electronic Medical Record" aria-label="Open EMR for ${escapeHTML(p.name)}" data-csp-style="admin-8896c95d8b21"><i class="fas fa-notes-medical" aria-hidden="true"></i> EMR</a>
        ${renderAdminPatientRecordActions(p)}
      </td>
    </tr>
  `,
    "No patients found.",
    5,
  );
}

function adminDeletePatient(id, name) {
  const safeId = safePositiveId(id);
  if (!safeId) return;
  if (
    !confirm(
      `Archive patient "${name}"? Sign-in will be disabled and not-started appointments will be cancelled. Medical and audit history will be retained.`,
    )
  )
    return;
  fetchAction(
    (getBasePath() + "api/patients/delete.php"),
    { id: safeId },
    `Patient "${name}" archived. Medical and audit history was retained.`,
    "Archive failed.",
    () => {
      loadPatientsTable();
      initAdminPage();
    },
  );
}

function togglePatientStatus(id, name) {
  const safeId = safePositiveId(id);
  if (!safeId) return;
  const patient = allPatients.find((entry) => safePositiveId(entry.id) === safeId);
  const isActive = Number(patient?.is_active) === 1;
  const msg = isActive
    ? `Disable patient "${name}"?`
    : `Enable patient "${name}"?`;
  if (!confirm(msg)) return;
  fetchAction(
    (getBasePath() + "api/patients/toggle-status.php"),
    { id: safeId },
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
    const passwordChecks = [
      password.length >= 8,
      /[A-Z]/.test(password),
      /[a-z]/.test(password),
      /[0-9]/.test(password),
      /[^A-Za-z0-9]/.test(password),
    ];
    if (passwordChecks.filter(Boolean).length < 4) {
      showToast(
        "Use at least 8 characters and meet at least three other strength checks.",
        "error",
      );
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
    adminDoctorsList = Array.isArray(result.doctors)
      ? result.doctors.filter((doctor) =>
          doctor && typeof doctor === "object" && !Array.isArray(doctor)
          && safePositiveId(doctor.user_id)
        )
      : [];
    if (!Array.isArray(result.doctors)) {
      throw new Error("The doctor directory returned an invalid response.");
    }
  } catch {
    adminDoctorsList = [];
    showToast("Doctor list could not be loaded. Please try again.", "error");
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
  if (deptResult.ok && Array.isArray(deptResult.data?.departments)) {
    deptSelect.innerHTML = '<option value="">Select department</option>';
    deptResult.data.departments
      .filter((department) =>
        department && typeof department === "object" && !Array.isArray(department)
        && safePositiveId(department.id)
        && typeof department.name === "string"
        && department.status === "active"
      )
      .forEach((department) => {
        deptSelect.innerHTML += `<option value="${safePositiveId(department.id)}">${escapeHTML(department.name)}</option>`;
      });
  }
}

function renderAdminDoctorOptions() {
  const doctorSelect = document.getElementById("admin-appt-doctor");
  const deptId = document.getElementById("admin-appt-department")?.value || "";
  if (!doctorSelect) return;

  doctorSelect.innerHTML = '<option value="">Select a doctor</option>';
  const departmentName = document.getElementById("admin-appt-department")?.selectedOptions[0]?.textContent?.trim() || "";
  const filtered = deptId
    ? adminDoctorsList.filter((doctor) => {
        if (!doctor || typeof doctor !== "object" || Array.isArray(doctor)) return false;
        if (doctor.department_id !== null && doctor.department_id !== undefined) {
          return String(doctor.department_id) === String(deptId);
        }
        const specialty = typeof doctor.specialty === "string" ? doctor.specialty : "";
        return specialty.trim().toLowerCase() === departmentName.toLowerCase();
      })
    : adminDoctorsList.filter((doctor) =>
        doctor && typeof doctor === "object" && !Array.isArray(doctor)
      );

  const eligibleDoctors = filtered.filter((doctor) => safePositiveId(doctor.user_id));
  eligibleDoctors.forEach((doctor) => {
    const doctorName = typeof doctor.name === "string" && doctor.name.trim() ? doctor.name : "Doctor";
    const specialty = typeof doctor.specialty === "string" && doctor.specialty.trim()
      ? doctor.specialty
      : "General Practice";
    const isUnavailable = Number(doctor.available) === 0;
    const label = `${doctorName} — ${specialty}${isUnavailable ? " (Not available)" : ""}`;
    const option = new Option(label, String(safePositiveId(doctor.user_id)));
    option.disabled = isUnavailable;
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

    const ctx = document.getElementById("admin-appt-context")?.value || "";
    const ctxId = parseInt(
      document.getElementById("admin-appt-context-id")?.value || "0",
    );
    const patientIdInput = document.getElementById("admin-appt-patient-id");
    const patientId = patientIdInput
      ? parseInt(patientIdInput.value || "0")
      : 0;

    const selectedDoctorId = Number.parseInt(document.getElementById("admin-appt-doctor")?.value || "0", 10);
    const selectedDoctor = adminDoctorsList.find((doctor) => Number(doctor.user_id) === selectedDoctorId);
    const data = {
      department_id:
        document.getElementById("admin-appt-department")?.value.trim() || "",
      doctor_id: selectedDoctorId,
      doctor: selectedDoctor?.name || "",
      date: document.getElementById("admin-appt-date")?.value.trim() || "",
      time: document.getElementById("admin-appt-time")?.value.trim() || "",
      patientName:
        document.getElementById("admin-appt-patient")?.value.trim() || "",
      notes: document.getElementById("admin-appt-notes")?.value.trim() || "",
      patient_id: patientId,
    };

    if (
      !data.department_id ||
      !data.doctor_id ||
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
    '<p data-csp-style="admin-2b9794aab12a">Loading schedules...</p>';

  const result = await apiFetch(
    (getBasePath() + "api/schedule/get.php"),
    {
      method: "GET",
      credentials: "same-origin",
    },
    "Failed to load schedules.",
  );

  if (!result.ok || !result.data?.success) {
    container.innerHTML = `<p data-csp-style="admin-e09b287597b9">${escapeHTML(result.data?.message || "Failed to load schedules.")}</p>`;
    return;
  }

  allDoctorSchedules = Array.isArray(result.data.schedules)
    ? result.data.schedules.filter((schedule) =>
        schedule && typeof schedule === "object" && !Array.isArray(schedule)
      )
    : [];

  if (allDoctorSchedules.length === 0) {
    container.innerHTML =
      '<p data-csp-style="admin-87161cf45c6d">No doctors found.</p>';
    return;
  }

  renderAdminSchedules(allDoctorSchedules);
}

function renderAdminSchedules(schedules) {
  const container = document.getElementById("admin-schedules-container");
  if (!container) return;

  const scheduleRows = Array.isArray(schedules)
    ? schedules.filter((schedule) =>
        schedule && typeof schedule === "object" && !Array.isArray(schedule)
      )
    : [];
  if (!scheduleRows.length) {
    container.innerHTML = '<p data-csp-style="admin-87161cf45c6d">No doctors found.</p>';
    return;
  }

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
        ${scheduleRows
          .map((s) => {
            const doctorId = safePositiveId(s.doctor_id);
            const doctorName = typeof s.doctor_name === "string" ? s.doctor_name : "Doctor";
            const doctorEmail = typeof s.doctor_email === "string" ? s.doctor_email : "";
            const weekly = Array.isArray(s.weekly)
              ? s.weekly.filter((day) => day && typeof day === "object" && !Array.isArray(day))
              : [];
            const durationValue = safeCount(s.appointment_duration, 30);
            const duration = durationValue >= 1 && durationValue <= 240 ? durationValue : 30;
            const maxPerDayValue = safeCount(s.max_appointments_per_day, 25);
            const maxPerDay = maxPerDayValue >= 1 && maxPerDayValue <= 1000 ? maxPerDayValue : 25;
            const fallbackWorkingDays = weekly.filter((day) => safeCount(day.is_working, 0) === 1).length;
            const workingDays = Math.min(7, safeCount(s.working_days_count, fallbackWorkingDays));
            const isAvailable = safeCount(s.is_available, 0) === 1;
            const breakStart = typeof s.break_start === "string" ? s.break_start : "";
            const breakEnd = typeof s.break_end === "string" ? s.break_end : "";
            return `
          <div class="schedule-admin-card" data-doctor-id="${doctorId || ""}">
            <div class="schedule-admin-card-header">
              <div>
                <h4 class="schedule-admin-card-name">${escapeHTML(doctorName)}</h4>
                <p class="schedule-admin-card-email">${escapeHTML(doctorEmail)}</p>
              </div>
              <div class="schedule-admin-card-badges">
                <span class="status status-${isAvailable ? "confirmed" : "cancelled"}" data-csp-style="admin-8e87b7532504">
                  ${isAvailable ? "Available" : "Unavailable"}
                </span>
              </div>
            </div>
            <div class="schedule-admin-days-list">
              ${weekly.map((day) => {
                const dayNumber = safePositiveId(day.day_of_week);
                const isWorking = safeCount(day.is_working, 0) === 1;
                return `
                <span class="schedule-day-badge${isWorking ? "" : " off"}">
                  ${dayNamesShort[dayNumber] || "?"}
                </span>`;
              }).join("")}
            </div>
            <div class="schedule-admin-card-info">
              <span><i class="fas fa-clock" aria-hidden="true"></i> ${duration} min</span>
              <span><i class="fas fa-users" aria-hidden="true"></i> Max ${maxPerDay}/day</span>
              <span><i class="fas fa-calendar-day" aria-hidden="true"></i> ${workingDays} days</span>
              ${breakStart && breakEnd
                ? `<span><i class="fas fa-mug-saucer" aria-hidden="true"></i> Break ${escapeHTML(breakStart)}-${escapeHTML(breakEnd)}</span>`
                : ""}
            </div>
            <div class="schedule-admin-card-actions">
              ${doctorId ? `
                <button class="btn btn-primary btn-sm" data-admin-generated-action="schedule-edit" data-record-id="${doctorId}">
                  <i class="fas fa-pen" aria-hidden="true"></i> Edit
                </button>
                <button class="btn btn-outline btn-sm" data-admin-generated-action="schedule-reset" data-record-id="${doctorId}">
                  <i class="fas fa-rotate-left" aria-hidden="true"></i> Reset
                </button>
              ` : '<span class="text-muted">Actions unavailable</span>'}
            </div>
          </div>`;
          })
          .join("")}
      </div>
    </div>
  `;
}

let _scheduleEditorDoctorId = 0;

function openAdminScheduleEditor(doctorId) {
  doctorId = safePositiveId(doctorId);
  if (!doctorId) return;
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
  doctorId = safePositiveId(doctorId);
  if (!doctorId) return;

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

    const id = safePositiveId(document.getElementById("reply-msg-id")?.value);
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
  const sourceMessages = Array.isArray(result.data?.messages)
    ? result.data.messages
    : Array.isArray(result.data) ? result.data : [];
  allContactMessages = sourceMessages.filter((message) =>
    message && typeof message === "object" && !Array.isArray(message)
  );
  filterSupportMessages();
}

function filterSupportMessages() {
  const query = (document.getElementById("support-search")?.value || "").trim().toLowerCase();
  const status = document.getElementById("support-status-filter")?.value || "";

  let filtered = allContactMessages.filter((message) =>
    message && typeof message === "object" && !Array.isArray(message)
  );
  if (query) {
    filtered = filtered.filter((message) => {
      const searchable = [message.name, message.email, message.subject, message.message]
        .filter((value) => typeof value === "string")
        .join(" ")
        .toLowerCase();
      return searchable.includes(query);
    });
  }

  filtered = filtered.filter((message) => {
    const replied = typeof message.reply === "string" && Boolean(message.reply.trim());
    if (status === "replied") return replied;
    if (status === "pending") return !replied;
    return true;
  });

  renderTable(
    "admin-support-messages",
    filtered,
    (message) => {
      const messageId = safePositiveId(message.id);
      const replyText = typeof message.reply === "string" ? message.reply : "";
      const isReplied = Boolean(replyText.trim());
      const statusText = isReplied ? "Replied" : "Pending Reply";
      const statusClass = isReplied ? "confirmed" : "pending";
      const name = typeof message.name === "string" && message.name ? message.name : "Guest";
      const email = typeof message.email === "string" ? message.email : "";
      const phone = typeof message.phone === "string" ? message.phone : "";
      const department = typeof message.department === "string" ? message.department : "";
      const subject = typeof message.subject === "string" && message.subject ? message.subject : "No Subject";
      const body = typeof message.message === "string" ? message.message : "";
      const createdAt = typeof message.created_at === "string" ? message.created_at : "";
      const isPatient = Boolean(safePositiveId(message.user_id));
      const badge = isPatient
        ? '<span class="badge" data-csp-style="admin-24897229c0fd">Patient</span>'
        : '<span data-csp-style="admin-2c6b2be991b1">(Guest)</span>';
      const phoneLine = phone
        ? `<div data-csp-style="admin-2e078d328f3a"><i class="fas fa-phone" aria-hidden="true"></i> ${escapeHTML(phone)}</div>`
        : "";
      const deptLine = department
        ? `<div data-csp-style="admin-da2ec3692aa6"><span data-csp-style="admin-83ea2552a7d8">${escapeHTML(department)}</span></div>`
        : "";
      const msgPreview = body.length > 120
        ? `${escapeHTML(body.substring(0, 120))}…`
        : escapeHTML(body);
      const replyPreview = isReplied
        ? `<div data-csp-style="admin-eff415e8cb87">↩ <em>${replyText.length > 80 ? `${escapeHTML(replyText.substring(0, 80))}…` : escapeHTML(replyText)}</em></div>`
        : "";
      const actionButton = messageId
        ? `<button class="btn btn-outline btn-sm" data-admin-generated-action="support-reply" data-record-id="${messageId}">${isReplied ? '<i class="fas fa-pen-to-square" aria-hidden="true"></i> Edit Reply' : '<i class="fas fa-comments" aria-hidden="true"></i> Reply'}</button>`
        : '<span class="text-muted">Reply unavailable</span>';

      return `
      <tr>
        <td data-csp-style="admin-0143f2a4d1fc">${escapeHTML(createdAt ? formatDate(createdAt) : "—")}</td>
        <td>
          <div data-csp-style="admin-542756ae80f3">${escapeHTML(name)}${badge}</div>
          <div data-csp-style="admin-2e96ace369ab">${escapeHTML(email)}</div>
          ${phoneLine}
          ${deptLine}
        </td>
        <td data-csp-style="admin-3521b3d833f8">
          <div data-csp-style="admin-2ec820839ec9">${escapeHTML(subject)}</div>
          <div data-csp-style="admin-0329bd576f2e">${msgPreview}</div>
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
  const messageId = safePositiveId(id);
  if (!messageId) {
    showToast("A valid message ID is required.", "error");
    return;
  }
  const msg = allContactMessages.find((message) =>
    message && typeof message === "object" && !Array.isArray(message)
    && safePositiveId(message.id) === messageId
  );
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

  if (idInput) idInput.value = String(messageId);
  if (senderEl) senderEl.textContent = typeof msg.name === "string" && msg.name ? msg.name : "Guest";
  if (emailEl) emailEl.textContent = typeof msg.email === "string" ? msg.email : "";
  if (dateEl) dateEl.textContent = typeof msg.created_at === "string" && msg.created_at
    ? formatDate(msg.created_at) : "—";
  if (subjectEl) subjectEl.textContent = typeof msg.subject === "string" && msg.subject ? msg.subject : "No Subject";
  if (bodyEl) bodyEl.textContent = typeof msg.message === "string" ? msg.message : "";
  if (replyInput) replyInput.value = typeof msg.reply === "string" ? msg.reply : "";

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
  const safeDoctorId = safePositiveId(id);
  if (!safeDoctorId) {
    showToast("A valid doctor ID is required to open the profile.", "error");
    return;
  }
  _profileDoctorId = safeDoctorId;
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
  if (deptResult.ok && Array.isArray(deptResult.data?.departments)) {
    _profileDepartments = deptResult.data.departments.filter((department) =>
      department && typeof department === "object" && !Array.isArray(department)
      && safePositiveId(department.id)
      && typeof department.name === "string" && department.name.trim()
      && department.status === "active"
    );
  }

  // Fetch doctor profile
  const result = await apiFetch(
    `${getBasePath()}api/doctors/profile.php?id=${safeDoctorId}`,
    {},
    "Failed to load doctor profile.",
  );
  if (!result.ok || !result.data?.success) {
    showToast(result.data?.message || "Failed to load doctor profile.", "error");
    closeDoctorProfile();
    return;
  }

  const data = result.data && typeof result.data === "object" && !Array.isArray(result.data)
    ? result.data
    : {};
  const doc = data.doctor && typeof data.doctor === "object" && !Array.isArray(data.doctor)
    ? data.doctor
    : null;
  if (!doc) {
    showToast("Doctor profile response was invalid.", "error");
    closeDoctorProfile();
    return;
  }
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
  const doctorId = safePositiveId(_profileDoctorId);
  if (!container || !doctorId) return;
  try {
    const result = await apiFetch(
      `${getBasePath()}api/schedule/get.php?doctor_id=${doctorId}`,
      {},
      "Failed to load schedule."
    );
    if (!result.ok || !result.data?.success) {
      container.innerHTML = '<div class="empty-state"><i class="fas fa-clock" aria-hidden="true"></i><p>No schedule configured.</p></div>';
      return;
    }
    const rawDays = Array.isArray(result.data.days) ? result.data.days : [];
    const days = rawDays.filter((day) =>
      day && typeof day === "object" && !Array.isArray(day)
    );
    if (days.length === 0) {
      container.innerHTML = '<div class="empty-state"><i class="fas fa-clock" aria-hidden="true"></i><p>No schedule configured.</p></div>';
      return;
    }
    let html = '<div class="schedule-info-list">';
    const dayNames = { mon:'Monday', tue:'Tuesday', wed:'Wednesday', thu:'Thursday', fri:'Friday', sat:'Saturday', sun:'Sunday' };
    days.forEach(day => {
      const dayKey = typeof day.day === "string" ? day.day.toLowerCase() : "";
      const dayLabel = Object.prototype.hasOwnProperty.call(dayNames, dayKey)
        ? dayNames[dayKey]
        : "Unknown";
      const isActive = day.active === true || day.active === 1 || day.active === "1";
      const start = typeof day.start === "string" ? day.start : "—";
      const end = typeof day.end === "string" ? day.end : "—";
      const breakStart = typeof day.break_start === "string" ? day.break_start : "";
      const breakEnd = typeof day.break_end === "string" ? day.break_end : "";
      if (isActive) {
        html += `<div class="schedule-info-row">
          <span class="schedule-day-label">${escapeHTML(dayLabel)}</span>
          <span class="schedule-time-label">${escapeHTML(start)} – ${escapeHTML(end)}</span>
          ${breakStart && breakEnd
            ? `<span class="schedule-break-label">Break: ${escapeHTML(breakStart)} – ${escapeHTML(breakEnd)}</span>`
            : ''}
        </div>`;
      } else {
        html += `<div class="schedule-info-row">
          <span class="schedule-day-label">${escapeHTML(dayLabel)}</span>
          <span data-csp-style="admin-e0086d5c9f97">Off</span>
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
  const doctorId = safePositiveId(_profileDoctorId);
  if (!container || !doctorId) return;
  try {
    const result = await apiFetch(
      `${getBasePath()}api/appointments/get-doctor.php?doctor_id=${doctorId}`,
      {},
      "Failed to load appointments."
    );
    const appts = result.ok && Array.isArray(result.data?.appointments)
      ? result.data.appointments.filter((appointment) =>
          appointment && typeof appointment === "object" && !Array.isArray(appointment)
        )
      : [];
    if (appts.length === 0) {
      container.innerHTML = '<div class="empty-state"><i class="fas fa-calendar" aria-hidden="true"></i><p>No appointments found.</p></div>';
      return;
    }
    const tableHtml = `<div class="table-wrap" data-csp-style="admin-b44a6f274679"><table>
      <thead><tr><th>Patient</th><th>Date & Time</th><th>Status</th></tr></thead>
      <tbody>${appts.map(a => {
        const statusText = typeof a.status === "string" ? a.status : "Unknown";
        const statusLower = safeClassToken(statusText || "pending");
        const patientId = safePositiveId(a.patient_id);
        const patientName = typeof a.patient_name === "string" && a.patient_name
          ? a.patient_name
          : "Unknown";
        const appointmentDate = typeof a.date === "string" ? a.date : "";
        // Only verified positive patient IDs receive a navigable EMR link.
        const patientDisplay = patientId
          ? `<a href="${getBasePath()}pages/patient/patient-emr.html?patient_id=${patientId}" data-csp-style="admin-5a74e195054b">${escapeHTML(patientName)}</a>`
          : `<span data-csp-style="admin-eed0f8fb89cd">${escapeHTML(patientName)}</span>`;
        return `<tr>
          <td>${patientDisplay}</td>
          <td data-csp-style="admin-c9565925f3a7">${escapeHTML(appointmentDate ? formatDate(appointmentDate) : "—")} ${escapeHTML(formatApptTime(a))}</td>
          <td><span class="status status-${statusLower}">${escapeHTML(statusText)}</span></td>
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
  const doctorId = safePositiveId(_profileDoctorId);
  if (!container || !doctorId) return;
  try {
    const result = await apiFetch(
      `${getBasePath()}api/doctors/get-doctor-ratings.php?doctor_id=${doctorId}`,
      {},
      "Failed to load reviews."
    );
    const reviews = result.ok && Array.isArray(result.data?.ratings)
      ? result.data.ratings.filter((review) =>
          review && typeof review === "object" && !Array.isArray(review)
        )
      : [];
    if (reviews.length === 0) {
      container.innerHTML = '<div class="empty-state"><i class="fas fa-star" aria-hidden="true"></i><p>No reviews yet.</p></div>';
      return;
    }
    const tableHtml = `<div class="table-wrap" data-csp-style="admin-b44a6f274679"><table>
      <thead><tr><th>Patient</th><th>Rating</th><th>Visibility</th><th>Comment</th><th>Date</th></tr></thead>
      <tbody>${reviews.map(r => {
        const parsedStars = safeCount(r.stars, 0);
        const hasValidStars = parsedStars >= 1 && parsedStars <= 5;
        const starsVal = hasValidStars ? parsedStars : 0;
        const starDisplay = hasValidStars
          ? `<span data-csp-style="admin-52df2b00a094">${Array(starsVal).fill('<i class="fas fa-star" aria-hidden="true"></i>').join('')}${Array(5 - starsVal).fill('<i class="far fa-star" aria-hidden="true"></i>').join('')}</span>`
          : '<span data-csp-style="admin-5872de20d564">Not rated</span>';
        const comment = typeof r.review === "string" && r.review
          ? escapeHTML(r.review)
          : '<span data-csp-style="admin-e0086d5c9f97">No written comment.</span>';
        const patientName = typeof r.patient_name === "string" && r.patient_name
          ? r.patient_name
          : "Unknown";
        const reviewDate = typeof r.created_at === "string" ? r.created_at
          : typeof r.review_date === "string" ? r.review_date : "";
        const visibility = Number(r.is_hidden) === 1
          ? '<span class="status status-cancelled">Hidden</span>'
          : '<span class="status status-confirmed">Public</span>';
        return `<tr>
          <td><div data-csp-style="admin-eed0f8fb89cd">${escapeHTML(patientName)}</div></td>
          <td>${starDisplay}${hasValidStars ? ` (${starsVal}/5)` : ""}</td>
          <td>${visibility}</td>
          <td data-csp-style="admin-16437bf91d09">${comment}</td>
          <td data-csp-style="admin-c9565925f3a7">${escapeHTML(reviewDate ? formatDate(reviewDate) : "—")}</td>
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
    const entries = result.ok && Array.isArray(result.data?.entries)
      ? result.data.entries.filter((entry) =>
          entry && typeof entry === "object" && !Array.isArray(entry)
        )
      : [];
    if (entries.length === 0) {
      container.innerHTML = '<div class="empty-state"><i class="fas fa-history" aria-hidden="true"></i><p>No administrative activity recorded for this doctor.</p></div>';
      return;
    }
    const actionIcons = Object.assign(Object.create(null), {
      create:'fa-plus-circle', update:'fa-pen', delete:'fa-trash',
      activate:'fa-toggle-on', deactivate:'fa-toggle-off', reassign:'fa-right-left'
    });
    const html = entries.map(e => {
      const action = typeof e.action === "string" ? e.action : "";
      const icon = Object.prototype.hasOwnProperty.call(actionIcons, action)
        ? actionIcons[action]
        : 'fa-circle';
      return `<div class="audit-entry">
        <div class="audit-entry-icon"><i class="fas ${icon}" aria-hidden="true"></i></div>
        <div class="audit-entry-body">
          <div class="audit-entry-action">${escapeHTML(action)} by ${escapeHTML(typeof e.admin_name === "string" ? e.admin_name : "System")}</div>
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
      deptSelect.innerHTML += `<option value="${safePositiveId(dept.id)}"${safePositiveId(dept.id) === safePositiveId(doc.department_id) ? ' selected' : ''}>${escapeHTML(dept.name)}</option>`;
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
  if (deptResult.ok && Array.isArray(deptResult.data?.departments)) {
    deptSelect.innerHTML = '<option value="">Select department</option>';
    deptResult.data.departments
      .filter((department) =>
        department && typeof department === "object" && !Array.isArray(department)
        && safePositiveId(department.id)
        && typeof department.name === "string"
        && department.status === "active"
      )
      .forEach((department) => {
        deptSelect.innerHTML += `<option value="${safePositiveId(department.id)}">${escapeHTML(department.name)}</option>`;
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
      deptSelect.innerHTML += `<option value="${safePositiveId(dept.id)}"${safePositiveId(dept.id) === safePositiveId(doc.department_id) ? ' selected' : ''}>${escapeHTML(dept.name)}</option>`;
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
    ? `Deactivate Dr. ${doc.name}? Sign-in will be revoked, not-started appointments will be cancelled, and an active clinical visit must be completed first.`
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
  if (!confirm(`Archive Dr. ${doc.name}? Sign-in will be disabled and not-started appointments will be cancelled. Clinical and audit history will be retained.`)) return;

  closeDoctorProfile();
  fetchAction(
    (getBasePath() + "api/doctors/delete.php"),
    { id: doc.id },
    `Dr. ${doc.name} has been archived. Clinical and audit history was retained.`,
    "Archive failed.",
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
  const doctorId = safePositiveId(doc?.id);
  if (!doc || !doctorId) return;
  const doctorName = typeof doc.name === "string" && doc.name.trim() ? doc.name : "Doctor";
  closeDoctorProfile();

  // Set context so that after booking we reload this doctor's profile
  document.getElementById("admin-appt-context").value = "doctor";
  document.getElementById("admin-appt-context-id").value = String(doctorId);
  document.getElementById("book-modal-title").innerHTML =
    `<i class="fas fa-calendar-days" aria-hidden="true"></i> Book Appointment with Dr. ${escapeHTML(doctorName)}`;

  toggleBookAppointmentModal();

  // Pre-fill the department and doctor after the modal renders
  requestAnimationFrame(() => {
    const deptSel = document.getElementById("admin-appt-department");
    const departmentId = safePositiveId(doc.department_id);
    if (deptSel && departmentId) {
      deptSel.value = String(departmentId);
      deptSel.dispatchEvent(new Event("change"));
    }
    // After doctors load, pre-select by the canonical doctor user ID.
    setTimeout(() => {
      const doctorSel = document.getElementById("admin-appt-doctor");
      if (doctorSel) {
        const opt = [...doctorSel.options].find((option) => option.value === String(doctorId));
        if (opt) doctorSel.value = opt.value;
      }
    }, 400);
  });
}

// Opens the booking modal pre-filling data from the currently open Patient profile
function openBookingForPatient() {
  const pat = window._currentPatientProfile;
  const patientId = safePositiveId(pat?.id);
  if (!pat || !patientId) return;
  const patientName = typeof pat.name === "string" && pat.name.trim() ? pat.name : "Patient";

  // Set context so that after booking we reload this patient's profile
  document.getElementById("admin-appt-context").value = "patient";
  document.getElementById("admin-appt-context-id").value = String(patientId);
  document.getElementById("book-modal-title").innerHTML =
    `<i class="fas fa-calendar-days" aria-hidden="true"></i> Book Appointment for ${escapeHTML(patientName)}`;

  document.getElementById("admin-appt-patient").value = patientName;
  // Set the patient ID so the backend links the appointment to this patient
  const patientIdInput = document.getElementById("admin-appt-patient-id");
  if (patientIdInput) patientIdInput.value = String(patientId);

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

  const prescriptions = Array.isArray(result.data.prescriptions)
    ? result.data.prescriptions.filter((prescription) =>
        prescription && typeof prescription === "object" && !Array.isArray(prescription)
      )
    : [];
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
    '<tr><td colspan="7" class="text-center" data-csp-style="admin-966e18dcede3">Loading audit log...</td></tr>';

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
    tbody.innerHTML = `<tr><td colspan="7" class="text-center" data-csp-style="admin-3915196df464">${escapeHTML(result.data?.message || "Failed to load audit log.")}</td></tr>`;
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
  if (actionSelect && Array.isArray(options.actions) && actionSelect.options.length <= 1) {
    options.actions.filter((action) => typeof action === "string" && action.trim()).forEach((action) => {
      const opt = document.createElement("option");
      opt.value = action;
      opt.textContent = action.charAt(0).toUpperCase() + action.slice(1);
      actionSelect.appendChild(opt);
    });
  }

  // Entity types
  const entitySelect = document.getElementById("audit-entity-filter");
  if (entitySelect && Array.isArray(options.entity_types) && entitySelect.options.length <= 1) {
    options.entity_types.filter((entityType) => typeof entityType === "string" && entityType.trim()).forEach((entityType) => {
      const opt = document.createElement("option");
      opt.value = entityType;
      opt.textContent = entityType.charAt(0).toUpperCase() + entityType.slice(1);
      entitySelect.appendChild(opt);
    });
  }

  // Admins
  const adminSelect = document.getElementById("audit-admin-filter");
  if (adminSelect && Array.isArray(options.admins) && adminSelect.options.length <= 1) {
    options.admins.filter((admin) => admin && typeof admin === "object" && !Array.isArray(admin))
      .forEach((admin) => {
        const adminId = safePositiveId(admin.admin_id);
        if (!adminId) return;
        const opt = document.createElement("option");
        opt.value = String(adminId);
        opt.textContent = String(admin.name ?? "Unknown");
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

  entries = Array.isArray(entries)
    ? entries.filter((entry) => entry && typeof entry === "object" && !Array.isArray(entry))
    : [];

  if (entries.length === 0) {
    tbody.innerHTML =
      '<tr><td colspan="7" class="text-center" data-csp-style="admin-966e18dcede3">No audit entries found.</td></tr>';
    return;
  }

  tbody.innerHTML = entries
    .map((entry) => {
      const actionValue = typeof entry.action === "string" ? entry.action : "";
      const entityTypeValue = typeof entry.entity_type === "string" ? entry.entity_type : "";
      const actionLabel = actionValue
        .replace(/_/g, " ")
        .replace(/\b\w/g, (c) => c.toUpperCase());
      const entityLabel = entityTypeValue
        .replace(/_/g, " ")
        .replace(/\b\w/g, (c) => c.toUpperCase());
      const description = buildAuditDescription(entry);
      const dateStr = formatDate(entry.created_at);
      const auditEntryId = safePositiveId(entry.id);
      const viewAction = auditEntryId
        ? `<button class="btn btn-outline btn-sm" data-audit-id="${auditEntryId}" data-admin-generated-action="audit-view" data-record-id="${auditEntryId}"><i class="fas fa-eye" aria-hidden="true"></i> View</button>`
        : '<span class="text-muted">Unavailable</span>';
      const timeStr = entry.created_at
        ? new Date(entry.created_at).toLocaleTimeString(undefined, {
            hour: "2-digit",
            minute: "2-digit",
          })
        : "";

      return `
      <tr>
        <td data-csp-style="admin-5330544e1779">${escapeHTML(dateStr)} ${escapeHTML(timeStr)}</td>
        <td data-csp-style="admin-5c05f4afaf80">${escapeHTML(entry.actor_name || entry.admin_name || "Unknown")}</td>
        <td><span class="status status-${getAuditActionClass(actionValue)}">${escapeHTML(actionLabel)}</span></td>
        <td>${escapeHTML(entityLabel)}</td>
        <td data-csp-style="admin-ac1c992c14a3">${entry.entity_id ? escapeHTML(String(entry.entity_id)) : "—"}</td>
        <td data-csp-style="admin-842904d5743f">${escapeHTML(description)}</td>
        <td>${viewAction}</td>
      </tr>`;
    })
    .join("");
}

/**
 * Build a human-readable description from an audit entry.
 */
function buildAuditDescription(entry) {
  const action = typeof entry?.action === "string" ? entry.action : "";
  const entityType = typeof entry?.entity_type === "string" ? entry.entity_type : "";

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
  return typeof action === "string" && Object.prototype.hasOwnProperty.call(map, action)
    ? map[action]
    : "pending";
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
  const auditEntryId = safePositiveId(entryId);
  if (!auditEntryId) {
    showToast("Audit entry not found.", "error");
    return;
  }
  const row = Array.from(document.querySelectorAll("#audit-log-entries tr")).find((candidate) =>
    candidate.querySelector("[data-audit-id]")?.getAttribute("data-audit-id") === String(auditEntryId)
  );
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
    id: auditEntryId,
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

  const fullEntry = (Array.isArray(result.data.entries) ? result.data.entries : []).find(
    (candidate) => candidate && safePositiveId(candidate.id) === safePositiveId(entry.id)
  );
  const displayEntry = fullEntry || entry;

  if (!displayEntry) {
    showToast("Audit entry not found.", "error");
    return;
  }

  const content = document.getElementById("audit-details-content");
  if (!content) return;

  const actionValue = typeof displayEntry.action === "string" ? displayEntry.action : "";
  const entityTypeValue = typeof displayEntry.entity_type === "string" ? displayEntry.entity_type : "";
  const actionLabel = actionValue
    .replace(/_/g, " ")
    .replace(/\b\w/g, (c) => c.toUpperCase());
  const entityLabel = entityTypeValue
    .replace(/_/g, " ")
    .replace(/\b\w/g, (c) => c.toUpperCase());
  const createdAt = typeof displayEntry.created_at === "string" ? displayEntry.created_at : "";
  const parsedDate = createdAt ? new Date(createdAt) : null;
  const dateStr = parsedDate && !Number.isNaN(parsedDate.getTime())
    ? parsedDate.toLocaleString(undefined, {
        year: "numeric",
        month: "short",
        day: "numeric",
        hour: "2-digit",
        minute: "2-digit",
      })
    : "—";
  const adminName = typeof displayEntry.admin_name === "string" && displayEntry.admin_name
    ? displayEntry.admin_name
    : "Unknown";
  const ipAddress = typeof displayEntry.ip_address === "string" && displayEntry.ip_address
    ? displayEntry.ip_address
    : "—";
  const userAgent = typeof displayEntry.user_agent === "string" && displayEntry.user_agent
    ? displayEntry.user_agent
    : "—";

  // Parse old/new values
  const oldValueHtml = formatAuditValue(displayEntry.old_value, "Old Value");
  const newValueHtml = formatAuditValue(displayEntry.new_value, "New Value");

  content.innerHTML = `
    <div class="audit-detail-grid">
      <div class="audit-detail-row">
        <span class="audit-detail-label">Admin</span>
        <span class="audit-detail-value">${escapeHTML(adminName)}</span>
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
        <span class="audit-detail-value">${escapeHTML(ipAddress)}</span>
      </div>
      <div class="audit-detail-row">
        <span class="audit-detail-label">User Agent</span>
        <span class="audit-detail-value" data-csp-style="admin-2438c6e13acd">${escapeHTML(userAgent)}</span>
      </div>
    </div>
    ${oldValueHtml}
    ${newValueHtml}
  `;

  toggleAuditDetailsModal();
}

const AUDIT_RENDER_MAX_DEPTH = 5;
const AUDIT_RENDER_MAX_ITEMS = 25;
const AUDIT_RENDER_MAX_STRING_LENGTH = 4000;

/**
 * Format an audit value (old_value or new_value) for display.
 * Generic recursive renderer that handles any data type:
 *   null, boolean, number, string, array, nested object, array of objects, JSON strings.
 * Bounds nested depth, collection sizes and displayed string length so a single
 * oversized audit value cannot expand into an unbounded DOM tree.
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
  if (depth > AUDIT_RENDER_MAX_DEPTH) {
    return '<span class="audit-truncated">Additional nested audit data omitted.</span>';
  }

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
    const displayValue = val.length > AUDIT_RENDER_MAX_STRING_LENGTH
      ? `${val.slice(0, AUDIT_RENDER_MAX_STRING_LENGTH)}… (truncated)`
      : val;
    return `<span class="audit-string">${escapeHTML(displayValue)}</span>`;
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

  // Small primitive arrays can be rendered inline without iterating large collections.
  const allPrimitives = arr.length <= 8 && arr.every(
    (item) => item === null || item === undefined || typeof item !== "object"
  );

  if (allPrimitives) {
    // Render inline: "value1, value2, value3"
    const items = arr.map((item) => renderAuditNode(item, depth)).join('<span class="audit-sep">, </span>');
    return `<span>[ ${items} ]</span>`;
  }

  // Array of objects or long arrays — render a bounded number of entries.
  const visibleItems = arr.slice(0, AUDIT_RENDER_MAX_ITEMS);
  const items = visibleItems
    .map((item, idx) => {
      const rendered = renderAuditNode(item, depth + 1);
      return `<div class="audit-array-item">
        <span class="audit-array-index">#${idx + 1}</span>
        <div class="audit-array-value">${rendered}</div>
      </div>`;
    })
    .join("");
  const omittedCount = arr.length - visibleItems.length;
  const omitted = omittedCount > 0
    ? `<div class="audit-truncated">${omittedCount} additional audit items omitted.</div>`
    : "";

  return `<div class="audit-array">${items}${omitted}</div>`;
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
  const visibleKeys = keys.slice(0, AUDIT_RENDER_MAX_ITEMS);

  const rows = visibleKeys
    .map((key) => {
      const formattedKey = key.replace(/_/g, " ").replace(/\b\w/g, (c) =>
        c.toUpperCase()
      );
      const displayKey = formattedKey.length > 160
        ? `${formattedKey.slice(0, 160)}…`
        : formattedKey;
      const val = obj[key];
      const renderedVal = renderAuditNode(val, depth + 1);

      // Check if the rendered value is complex (contains HTML block elements)
      const isComplex = val !== null && typeof val === "object";

      if (isComplex) {
        // Nested object/array — render as sub-section
        return `
          <div class="audit-subsection" data-csp-style="audit-indent-${Math.min(depth, 3)}">
            <div class="audit-subsection-title"><i class="fas fa-folder-open" aria-hidden="true"></i> ${escapeHTML(displayKey)}</div>
            <div class="audit-subsection-body">${renderedVal}</div>
          </div>`;
      }

      return `
        <div class="audit-change-row" data-csp-style="audit-padding-${Math.min(depth, 3)}">
          <span class="audit-change-field">${escapeHTML(displayKey)}</span>
          <span class="audit-change-value">${renderedVal}</span>
        </div>`;
    })
    .join("");
  const omittedCount = keys.length - visibleKeys.length;
  const omitted = omittedCount > 0
    ? `<div class="audit-truncated">${omittedCount} additional audit fields omitted.</div>`
    : "";

  // Top-level objects get no extra wrapper; nested objects get a sub-section wrapper
  const renderedRows = rows + omitted;
  return isTopLevel
    ? renderedRows
    : `<div class="audit-nested-object">${renderedRows}</div>`;
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





