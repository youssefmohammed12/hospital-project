/**
 * HealthBridge — Admin Departments Loader Enhancement
 * Extends admin.js to load departments dynamically in booking modal.
 *
 * Used by: admin.html
 */

"use strict";

// Override loadAdminDoctorDropdown to include department loading
const originalLoadAdminDoctorDropdown = window.loadAdminDoctorDropdown;

window.loadAdminDoctorDropdown = async function() {
  const doctorSelect = document.getElementById("admin-appt-doctor");
  const deptSelect = document.getElementById("admin-appt-department");
  if (!doctorSelect) return;

  // Load departments dynamically
  if (typeof loadDepartmentsDropdown === 'function') {
    loadDepartmentsDropdown("admin-appt-department", true);
  }

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
};

