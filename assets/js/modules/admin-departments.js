/**
 * HealthBridge — Admin Department Management JavaScript
 * Handles department CRUD operations in the Admin Dashboard.
 *
 * Used by: admin.html
 */

"use strict";

let allDepartments = [];

// Auto-initialize when DOM is ready
document.addEventListener("DOMContentLoaded", () => {
  // Wait a bit to ensure admin.js is loaded first
  setTimeout(() => {
    initDepartmentsTab();
  }, 100);
});

function initDepartmentsTab() {
  document
    .querySelectorAll(".sidebar-nav a[href='#departments']")
    .forEach((link) => {
      link.addEventListener("click", () => {
        setTimeout(() => loadDepartments(), 100);
      });
    });

  const savedTab = localStorage.getItem("hb_admin_active_tab") || "overview";
  if (savedTab === "departments") {
    setTimeout(() => loadDepartments(), 150);
  }

  initAddDepartmentForm();
  initEditDepartmentForm();
  bindDepartmentRowActions();
}

/**
 * Fixed delegated dispatcher for department-row actions.
 */
function bindDepartmentRowActions() {
  const tbody = document.getElementById("admin-departments");
  if (!tbody || tbody.dataset.delegatedActionsBound === "true") return;

  tbody.addEventListener("click", (event) => {
    const target = event.target instanceof Element ? event.target : null;
    const control = target?.closest("[data-department-action]");
    if (!control || !tbody.contains(control)) return;

    const departmentId = safePositiveId(control.dataset.departmentId);
    if (!departmentId) return;
    event.preventDefault();

    switch (control.dataset.departmentAction) {
      case "edit":
        openEditDepartmentModal(departmentId);
        break;
      case "deactivate":
        deactivateDepartment(departmentId);
        break;
      case "activate":
        activateDepartment(departmentId);
        break;
      case "delete":
        deleteDepartment(departmentId);
        break;
    }
  });

  tbody.dataset.delegatedActionsBound = "true";
}

async function loadDepartments() {
  const result = await apiFetch(
    `${getBasePath()}api/departments/get.php?include_inactive=1`,
    {},
    "Failed to load departments"
  );

  if (result.ok && result.data?.success) {
    allDepartments = Array.isArray(result.data.departments)
      ? result.data.departments.filter((department) =>
          department && typeof department === "object" && !Array.isArray(department)
        )
      : [];
    renderDepartmentsTable(allDepartments);
  } else {
    showToast(result.data?.message || "Failed to load departments", "error");
  }
}

function renderDepartmentsTable(departments) {
  const tbody = document.getElementById("admin-departments");
  if (!tbody) return;
  bindDepartmentRowActions();

  const rows = Array.isArray(departments)
    ? departments.filter((department) =>
        department && typeof department === "object" && !Array.isArray(department)
      )
    : [];
  if (rows.length === 0) {
    tbody.innerHTML = `<tr><td colspan="6" class="text-center admin-departments-empty-cell">No departments found.</td></tr>`;
    return;
  }

  tbody.innerHTML = rows
    .map((dept) => {
      const departmentId = safePositiveId(dept.id);
      if (!departmentId) return "";
      const statusValue = typeof dept.status === "string" ? dept.status : "";
      const hasValidStatus = statusValue === "active" || statusValue === "inactive";
      const isActive = statusValue === "active";
      const statusClass = isActive ? "status-active" : "status-inactive";
      const statusLabel = hasValidStatus ? (isActive ? "Active" : "Inactive") : "Unknown";
      const doctorCount = safeCount(dept.doctor_count, 0);
      const apptCount = safeCount(dept.appointment_count, 0);
      const hasValidCounts = [dept.doctor_count, dept.appointment_count].every((value) => {
        if (typeof value !== "number" && typeof value !== "string") return false;
        const count = Number(value);
        return Number.isSafeInteger(count) && count >= 0;
      });
      const name = typeof dept.name === "string" && dept.name.trim()
        ? dept.name : "Unnamed department";
      const description = typeof dept.description === "string" ? dept.description : "—";

      const actions = `
        <button class="btn btn-outline btn-sm" data-department-action="edit" data-department-id="${departmentId}" title="Edit">
          <i class="fas fa-edit" aria-hidden="true"></i>
        </button>
        ${hasValidStatus ? (isActive
          ? `<button class="btn btn-outline btn-sm" data-department-action="deactivate" data-department-id="${departmentId}" title="Deactivate">
               <i class="fas fa-ban" aria-hidden="true"></i>
             </button>`
          : `<button class="btn btn-outline btn-sm" data-department-action="activate" data-department-id="${departmentId}" title="Activate">
               <i class="fas fa-check" aria-hidden="true"></i>
             </button>`)
          : ""}
        ${hasValidCounts && doctorCount === 0 && apptCount === 0
          ? `<button class="btn btn-danger btn-sm" data-department-action="delete" data-department-id="${departmentId}" title="Delete">
               <i class="fas fa-trash" aria-hidden="true"></i>
             </button>`
          : ""
        }
      `;

      return `
        <tr>
          <td><strong>${escapeHTML(name)}</strong></td>
          <td>${escapeHTML(description)}</td>
          <td>${doctorCount}</td>
          <td>${apptCount}</td>
          <td><span class="status ${statusClass}">${statusLabel}</span></td>
          <td><div class="admin-department-actions">${actions}</div></td>
        </tr>
      `;
    })
    .join("");
}

function filterDepartments() {
  const query = document.getElementById("department-search")?.value || "";
  const normalizedQuery = typeof query === "string" ? query.trim().toLowerCase() : "";
  const status = document.getElementById("department-status-filter")?.value || "";

  let filtered = allDepartments.filter((department) =>
    department && typeof department === "object" && !Array.isArray(department)
  );

  if (normalizedQuery) {
    filtered = filtered.filter((department) => {
      const name = typeof department.name === "string" ? department.name : "";
      const description = typeof department.description === "string" ? department.description : "";
      return name.toLowerCase().includes(normalizedQuery)
        || description.toLowerCase().includes(normalizedQuery);
    });
  }

  if (status) {
    filtered = filtered.filter((department) => department.status === status);
  }

  renderDepartmentsTable(filtered);
}

function toggleAddDepartmentModal() {
  const modal = document.getElementById("add-department-modal");
  if (modal) {
    modal.classList.toggle("open");
    if (modal.classList.contains("open")) {
      document.getElementById("add-department-form").reset();
    }
  }
}

function toggleEditDepartmentModal() {
  const modal = document.getElementById("edit-department-modal");
  if (modal) {
    modal.classList.toggle("open");
  }
}

function initAddDepartmentForm() {
  const form = document.getElementById("add-department-form");
  if (!form) return;

  form.addEventListener("submit", async (e) => {
    e.preventDefault();

    const name = document.getElementById("department-name").value.trim();
    const description = document.getElementById("department-description").value.trim();
    const status = document.getElementById("department-status").value;

    if (!name) {
      showToast("Department name is required", "error");
      return;
    }

    const result = await apiFetch(
      (getBasePath() + "api/departments/create.php"),
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ name, description, status }),
      },
      "Failed to create department"
    );

    if (result.data?.success) {
      showToast("Department created successfully", "success");
      toggleAddDepartmentModal();
      loadDepartments();
    } else {
      showToast(result.data?.message || "Failed to create department", "error");
    }
  });
}

function initEditDepartmentForm() {
  const form = document.getElementById("edit-department-form");
  if (!form) return;

  form.addEventListener("submit", async (e) => {
    e.preventDefault();

    const id = safePositiveId(document.getElementById("edit-department-id")?.value);
    const name = document.getElementById("edit-department-name").value.trim();
    const description = document.getElementById("edit-department-description").value.trim();
    const status = document.getElementById("edit-department-status").value;

    if (!id) {
      showToast("A valid department ID is required", "error");
      return;
    }
    if (!name) {
      showToast("Department name is required", "error");
      return;
    }

    const result = await apiFetch(
      `${getBasePath()}api/departments/update.php`,
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ id, name, description, status }),
      },
      "Failed to update department"
    );

    if (result.data?.success) {
      showToast("Department updated successfully", "success");
      toggleEditDepartmentModal();
      loadDepartments();
    } else {
      showToast(result.data?.message || "Failed to update department", "error");
    }
  });
}

async function openEditDepartmentModal(id) {
  const departmentId = safePositiveId(id);
  if (!departmentId) {
    showToast("A valid department ID is required", "error");
    return;
  }
  const dept = allDepartments.find((department) =>
    department && typeof department === "object" && !Array.isArray(department)
    && safePositiveId(department.id) === departmentId
  );
  if (!dept) {
    showToast("Department not found", "error");
    return;
  }

  document.getElementById("edit-department-id").value = String(departmentId);
  document.getElementById("edit-department-name").value =
    typeof dept.name === "string" ? dept.name : "";
  document.getElementById("edit-department-description").value =
    typeof dept.description === "string" ? dept.description : "";
  document.getElementById("edit-department-status").value =
    typeof dept.status === "string" ? dept.status : "inactive";

  toggleEditDepartmentModal();
}

async function deactivateDepartment(id) {
  const departmentId = safePositiveId(id);
  if (!departmentId) return;
  if (!confirm("Are you sure you want to deactivate this department? It will not be available for new bookings.")) {
    return;
  }

  const result = await apiFetch(
    `${getBasePath()}api/departments/deactivate.php`,
    {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ id: departmentId }),
    },
    "Failed to deactivate department"
  );

  if (result.data?.success) {
    showToast("Department deactivated successfully", "success");
    loadDepartments();
  } else {
    showToast(result.data?.message || "Failed to deactivate department", "error");
  }
}

async function activateDepartment(id) {
  const departmentId = safePositiveId(id);
  if (!departmentId) return;
  const result = await apiFetch(
    `${getBasePath()}api/departments/activate.php`,
    {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ id: departmentId }),
    },
    "Failed to activate department"
  );

  if (result.data?.success) {
    showToast("Department activated successfully", "success");
    loadDepartments();
  } else {
    showToast(result.data?.message || "Failed to activate department", "error");
  }
}

async function deleteDepartment(id) {
  const departmentId = safePositiveId(id);
  if (!departmentId) return;
  if (!confirm("Are you sure you want to permanently delete this department? This action cannot be undone.")) {
    return;
  }

  const result = await apiFetch(
    `${getBasePath()}api/departments/delete.php`,
    {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ id: departmentId }),
    },
    "Failed to delete department"
  );

  if (result.data?.success) {
    showToast("Department deleted successfully", "success");
    loadDepartments();
  } else {
    showToast(result.data?.message || "Failed to delete department", "error");
  }
}

// Expose to global scope
window.toggleAddDepartmentModal = toggleAddDepartmentModal;
window.toggleEditDepartmentModal = toggleEditDepartmentModal;
window.openEditDepartmentModal = openEditDepartmentModal;
window.deactivateDepartment = deactivateDepartment;
window.activateDepartment = activateDepartment;
window.deleteDepartment = deleteDepartment;
window.filterDepartments = filterDepartments;



