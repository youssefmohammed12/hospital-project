/**
 * HealthBridge — Departments Loader
 * Shared utility to load active departments from API and populate dropdowns.
 *
 * Used by: dashboard.js, admin.js, patient-emr.js
 */

"use strict";

/**
 * Load active departments from API and populate a dropdown select element.
 * @param {string} selectId - The ID of the select element to populate
 * @param {boolean} includeEmptyOption - Whether to include an empty "Select department" option
 */
async function loadDepartmentsDropdown(selectId, includeEmptyOption = true) {
  const select = document.getElementById(selectId);
  if (!select) return;

  try {
    const response = await fetch((getBasePath() + "api/departments/get.php"));
    const result = await response.json();

    if (result.success && result.departments) {
      select.innerHTML = "";
      
      if (includeEmptyOption) {
        const emptyOption = document.createElement("option");
        emptyOption.value = "";
        emptyOption.textContent = "Select department";
        select.appendChild(emptyOption);
      }

      result.departments.forEach((dept) => {
        const option = document.createElement("option");
        option.value = dept.name;
        option.textContent = dept.name;
        select.appendChild(option);
      });
    }
  } catch (error) {
    console.error("Failed to load departments:", error);
    // Fallback to hardcoded departments if API fails
    const fallbackDepartments = [
      "Cardiology",
      "Dermatology", 
      "Neurology",
      "Pediatrics",
      "Orthopedics",
      "Dentistry",
      "Ophthalmology",
      "Gynecology",
      "General Practice"
    ];

    select.innerHTML = "";
    if (includeEmptyOption) {
      const emptyOption = document.createElement("option");
      emptyOption.value = "";
      emptyOption.textContent = "Select department";
      select.appendChild(emptyOption);
    }

    fallbackDepartments.forEach((deptName) => {
      const option = document.createElement("option");
      option.value = deptName;
      option.textContent = deptName;
      select.appendChild(option);
    });
  }
}

// Expose to global scope
window.loadDepartmentsDropdown = loadDepartmentsDropdown;

