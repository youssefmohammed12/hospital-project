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
}

async function loadDepartments() {
  const result = await apiFetch(
    `${getBasePath()}api/departments/get.php?include_inactive=1`,
    {},
    "Failed to load departments"
  );

  if (result.ok && result.data?.success) {
    allDepartments = result.data.departments || [];
    renderDepartmentsTable(allDepartments);
  } else {
    showToast(result.data?.message || "Failed to load departments", "error");
  }
}

function renderDepartmentsTable(departments) {
  const tbody = document.getElementById("admin-departments");
  if (!tbody) return;

  if (departments.length === 0) {
    tbody.innerHTML = `<tr><td colspan="6" class="text-center" style="padding: var(--s8); color: var(--text-muted)">No departments found.</td></tr>`;
    return;
  }

  tbody.innerHTML = departments
    .map((dept) => {
      const statusClass = dept.status === "active" ? "status-active" : "status-inactive";
      const statusLabel = dept.status === "active" ? "Active" : "Inactive";
      const doctorCount = dept.doctor_count || 0;
      const apptCount = dept.appointment_count || 0;

      const actions = `
        <button class="btn btn-outline btn-sm" onclick="openEditDepartmentModal(${dept.id})" title="Edit">
          <i class="fas fa-edit" aria-hidden="true"></i>
        </button>
        ${dept.status === "active" 
          ? `<button class="btn btn-outline btn-sm" onclick="deactivateDepartment(${dept.id})" title="Deactivate">
               <i class="fas fa-ban" aria-hidden="true"></i>
             </button>`
          : `<button class="btn btn-outline btn-sm" onclick="activateDepartment(${dept.id})" title="Activate">
               <i class="fas fa-check" aria-hidden="true"></i>
             </button>`
        }
        ${doctorCount === 0 && apptCount === 0
          ? `<button class="btn btn-danger btn-sm" onclick="deleteDepartment(${dept.id})" title="Delete">
               <i class="fas fa-trash" aria-hidden="true"></i>
             </button>`
          : ""
        }
      `;

      return `
        <tr>
          <td><strong>${escapeHTML(dept.name)}</strong></td>
          <td>${escapeHTML(dept.description || "—")}</td>
          <td>${doctorCount}</td>
          <td>${apptCount}</td>
          <td><span class="status ${statusClass}">${statusLabel}</span></td>
          <td><div style="display:flex;gap:4px">${actions}</div></td>
        </tr>
      `;
    })
    .join("");
}

function filterDepartments() {
  const query = document.getElementById("department-search")?.value || "";
  const status = document.getElementById("department-status-filter")?.value || "";

  let filtered = allDepartments;
  filtered = filterData(filtered, query, ["name", "description"]);
  if (status) {
    filtered = filtered.filter((d) => d.status === status);
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

    const id = document.getElementById("edit-department-id").value;
    const name = document.getElementById("edit-department-name").value.trim();
    const description = document.getElementById("edit-department-description").value.trim();
    const status = document.getElementById("edit-department-status").value;

    if (!name) {
      showToast("Department name is required", "error");
      return;
    }

    const result = await apiFetch(
      `${getBasePath()}api/departments/update.php?id=${id}`,
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ name, description, status }),
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
  const dept = allDepartments.find((d) => d.id === id);
  if (!dept) {
    showToast("Department not found", "error");
    return;
  }

  document.getElementById("edit-department-id").value = dept.id;
  document.getElementById("edit-department-name").value = dept.name;
  document.getElementById("edit-department-description").value = dept.description || "";
  document.getElementById("edit-department-status").value = dept.status;

  toggleEditDepartmentModal();
}

async function deactivateDepartment(id) {
  if (!confirm("Are you sure you want to deactivate this department? It will not be available for new bookings.")) {
    return;
  }

  const result = await apiFetch(
    `${getBasePath()}api/departments/deactivate.php?id=${id}`,
    {},
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
  const result = await apiFetch(
    `${getBasePath()}api/departments/activate.php?id=${id}`,
    {},
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
  if (!confirm("Are you sure you want to permanently delete this department? This action cannot be undone.")) {
    return;
  }

  const result = await apiFetch(
    `${getBasePath()}api/departments/delete.php?id=${id}`,
    {},
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



