/**
 * HealthBridge — Patient Prescriptions Page Controller
 *
 * Full prescription management: doctor details, medication details,
 * dosage/frequency instructions, search & status filtering, print & PDF export.
 */
"use strict";

document.addEventListener("DOMContentLoaded", async () => {
  requireAuth();

  const user = getUser();
  if (!user || user.role !== "patient") {
    window.location.href = getBasePath() + "pages/auth/login.html";
    return;
  }

  const container = document.getElementById("prescriptions-container");
  if (!container) return;

  let allPrescriptions = [];
  let currentFilter = "all";
  let searchQuery = "";

  const MAX_PRESCRIPTIONS = 20;
  const MAX_MEDICATION_ITEMS = 20;
  const STATUS_LABELS = {
    active: "Active",
    completed: "Completed",
    cancelled: "Cancelled",
  };

  function scalarText(value, fallback = "", maxLength = 500) {
    return typeof value === "string" ? value.slice(0, maxLength) : fallback;
  }

  function normalizePrescription(value) {
    if (!value || typeof value !== "object" || Array.isArray(value)) return null;

    const rawStatus = scalarText(value.status).trim().toLowerCase();
    const status = STATUS_LABELS[rawStatus] || "Unknown";
    const department = scalarText(value.department, "")
      || scalarText(value.appt_department, "")
      || "General Medical";
    const rawItems = Array.isArray(value.items) ? value.items : [];
    const items = rawItems
      .filter(item => item && typeof item === "object" && !Array.isArray(item))
      .slice(0, MAX_MEDICATION_ITEMS)
      .map(item => ({
        medication_name: scalarText(item.medication_name, "", 160),
        strength: scalarText(item.strength, "", 80),
        dosage: scalarText(item.dosage, "", 120),
        frequency: scalarText(item.frequency, "", 120),
        duration: scalarText(item.duration, "", 80),
        instructions: scalarText(item.instructions, "", 1000),
      }));

    return {
      id: safePositiveId(value.id),
      doctor_name: scalarText(value.doctor_name, "Doctor", 160) || "Doctor",
      department: department.slice(0, 160),
      doctor_specialty: scalarText(value.doctor_specialty, "", 160),
      status,
      created_at: scalarText(value.created_at, scalarText(value.appointment_date, "", 40), 40),
      notes: scalarText(value.notes, "", 1000),
      items,
    };
  }

  // 1. Fetch prescriptions from API
  const result = await apiFetch(
    getBasePath() + "api/prescriptions/get.php",
    {},
    "Failed to load prescriptions."
  );

  container.innerHTML = "";

  if (result.ok && result.data?.success) {
    allPrescriptions = Array.isArray(result.data.prescriptions)
      ? result.data.prescriptions.map(normalizePrescription).filter(Boolean).slice(0, MAX_PRESCRIPTIONS)
      : [];
    render();
  } else {
    // Fallback: use dashboard API if prescriptions API returns empty
    const dashResult = await apiFetch(
      getBasePath() + "api/patient/dashboard.php",
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ sections: ["prescriptions"] })
      },
      "Failed to load prescriptions."
    );

    if (dashResult.ok && dashResult.data?.success) {
      const fallbackPrescriptions = dashResult.data.data?.prescriptions?.prescriptions;
      allPrescriptions = Array.isArray(fallbackPrescriptions)
        ? fallbackPrescriptions.map(normalizePrescription).filter(Boolean).slice(0, MAX_PRESCRIPTIONS)
        : [];
      render();
    } else {
      showError();
    }
  }

  // 2. Event Listeners for Filter Chips and Search Input
  const searchInput = document.getElementById("rx-search-input");
  if (searchInput) {
    searchInput.addEventListener("input", (e) => {
      searchQuery = e.target.value.trim().toLowerCase();
      render();
    });
  }

  const filterChips = document.querySelectorAll("#rx-filter-chips .filter-chip");
  filterChips.forEach((chip) => {
    chip.addEventListener("click", () => {
      filterChips.forEach((c) => c.classList.remove("active"));
      chip.classList.add("active");
      currentFilter = chip.getAttribute("data-filter") || "all";
      render();
    });
  });

  // 3. Render Function
  function render() {
    let filtered = allPrescriptions.slice();

    // Filter by status
    if (currentFilter !== "all") {
      filtered = filtered.filter((rx) =>
        (typeof rx.status === "string" ? rx.status : "").toLowerCase() === currentFilter.toLowerCase()
      );
    }

    // Filter by search query
    if (searchQuery) {
      filtered = filtered.filter((rx) => {
        const docName = typeof rx.doctor_name === "string" ? rx.doctor_name.toLowerCase() : "";
        const deptValue = typeof rx.department === "string"
          ? rx.department
          : typeof rx.appt_department === "string" ? rx.appt_department : "";
        const dept = deptValue.toLowerCase();
        const rxItems = Array.isArray(rx.items)
          ? rx.items.filter((item) => item && typeof item === "object" && !Array.isArray(item))
          : [];
        const itemsMatch = rxItems.some((item) => {
          const medicationName = typeof item.medication_name === "string" ? item.medication_name : "";
          const instructions = typeof item.instructions === "string" ? item.instructions : "";
          return medicationName.toLowerCase().includes(searchQuery)
            || instructions.toLowerCase().includes(searchQuery);
        });
        return docName.includes(searchQuery) || dept.includes(searchQuery) || itemsMatch;
      });
    }

    if (filtered.length === 0) {
      if (allPrescriptions.length === 0) {
        container.innerHTML = `
          <div class="empty-state">
            <div class="empty-icon"><i class="fas fa-prescription-bottle" aria-hidden="true"></i></div>
            <h4>No Prescriptions Yet</h4>
            <p>Your doctors will publish prescriptions here after your consultations.</p>
            <a href="dashboard.html" class="btn btn-primary btn-sm">Back to Dashboard</a>
          </div>`;
      } else {
        container.innerHTML = `
          <div class="empty-state">
            <div class="empty-icon"><i class="fas fa-search" aria-hidden="true"></i></div>
            <h4>No Matching Prescriptions</h4>
            <p>Try adjusting your search query or status filter.</p>
          </div>`;
      }
      return;
    }

    container.innerHTML = filtered
      .map((rx) => {
        const items = Array.isArray(rx.items)
          ? rx.items.filter((item) => item && typeof item === "object" && !Array.isArray(item))
          : [];
        const status = typeof rx.status === "string" ? rx.status : "Active";
        const doctorName = typeof rx.doctor_name === "string" && rx.doctor_name.trim()
          ? rx.doctor_name
          : "Doctor";
        const prescriptionId = safePositiveId(rx.id);
        const statusClass = {
          Active: "rx-status-badge rx-status-active",
          Completed: "rx-status-badge rx-status-completed",
          Cancelled: "rx-status-badge rx-status-cancelled",
          Unknown: "rx-status-badge rx-status-unknown",
        }[status] || "rx-status-badge rx-status-unknown";
        const docInitials = doctorName
          .split(/\s+/)
          .filter(Boolean)
          .map((n) => n[0])
          .join("")
          .substring(0, 2)
          .toUpperCase();

        return `
        <article class="card prescription-card patient-rx-card">
          <header class="patient-rx-card__header">
            <div class="patient-rx-card__doctor">
              <div class="patient-rx-card__avatar" aria-hidden="true">
                ${escapeHTML(docInitials)}
              </div>
              <div>
                <h3 class="patient-rx-card__name">${escapeHTML(doctorName)}</h3>
                <p class="patient-rx-card__department">
                  <i class="fas fa-stethoscope patient-rx-card__department-icon" aria-hidden="true"></i> ${escapeHTML(rx.department || rx.appt_department || "General Medical")}
                  ${rx.doctor_specialty ? ` • ${escapeHTML(rx.doctor_specialty)}` : ""}
                </p>
                <div class="patient-rx-card__issued">
                  <i class="fas fa-calendar-alt" aria-hidden="true"></i> Issued ${escapeHTML(formatDate(rx.created_at || rx.appointment_date))}
                </div>
              </div>
            </div>
            <div class="patient-rx-card__side-meta">
              <span class="${statusClass} patient-rx-card__status">${escapeHTML(status)}</span>
              <span class="patient-rx-card__item-count">${items.length} Medication${items.length !== 1 ? "s" : ""}</span>
            </div>
          </header>

          <div class="patient-rx-card__body">
            <h4 class="patient-rx-card__section-title">
              <i class="fas fa-pills patient-rx-card__section-icon" aria-hidden="true"></i> Prescribed Medications
            </h4>
            <div class="patient-rx-card__items">
              ${items
                .map(
                  (item) => `
                <div class="patient-rx-card__medication">
                  <div class="patient-rx-card__medication-content">
                    <div class="patient-rx-card__medication-name">
                      ${escapeHTML(item.medication_name || "")} ${item.strength ? `<span class="patient-rx-card__strength">(${escapeHTML(item.strength)})</span>` : ""}
                    </div>
                    <div class="patient-rx-card__details">
                      <i class="fas fa-clock patient-rx-card__details-icon" aria-hidden="true"></i> ${escapeHTML(item.dosage || "")} • ${escapeHTML(item.frequency || "")}
                      ${item.duration ? ` • Duration: ${escapeHTML(item.duration)}` : ""}
                    </div>
                    ${
                      item.instructions
                        ? `<div class="patient-rx-card__instructions">
                            <i class="fas fa-info-circle patient-rx-card__instructions-icon" aria-hidden="true"></i> Instructions: ${escapeHTML(item.instructions)}
                           </div>`
                        : ""
                    }
                  </div>
                </div>`
                )
                .join("")}
            </div>
          </div>

          ${
            rx.notes
              ? `<div class="patient-rx-card__notes">
                  <strong>Doctor's Note:</strong> ${escapeHTML(rx.notes)}
                 </div>`
              : ""
          }

          <footer class="patient-rx-card__footer">
            ${prescriptionId ? `
              <a class="btn btn-outline btn-sm" href="${getBasePath()}api/prescriptions/print.php?id=${prescriptionId}" target="_blank" rel="noopener noreferrer">
                <i class="fas fa-print" aria-hidden="true"></i> Print Prescription
              </a>
              <a class="btn btn-primary btn-sm" href="${getBasePath()}api/prescriptions/print.php?id=${prescriptionId}" target="_blank" rel="noopener noreferrer">
                <i class="fas fa-file-pdf" aria-hidden="true"></i> Download PDF
              </a>
            ` : '<span class="text-muted">Print unavailable</span>'}
          </footer>
        </article>`;
      })
      .join("");
  }

  function showError() {
    container.innerHTML = `
      <div class="empty-state">
        <div class="empty-icon"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i></div>
        <h4>Unable to Load Prescriptions</h4>
        <p>Please try refreshing the page.</p>
        <button class="btn btn-outline btn-sm" data-page-action="retry">Retry</button>
      </div>`;
  }
});