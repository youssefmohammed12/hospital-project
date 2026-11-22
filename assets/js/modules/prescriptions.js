/**
 * HealthBridge — Prescriptions Shared JavaScript
 * Handles prescription creation (doctor), viewing (patient, doctor, admin),
 * editing, completing, canceling, and the prescription modal UI.
 *
 * Dependencies: main.js (escapeHTML, formatDate, showToast, apiFetch, setLoading)
 *
 * Used by: doctor-dashboard.html, dashboard.html, admin.html
 */

"use strict";

function isPrescriptionRecord(value) {
  return Boolean(value && typeof value === "object" && !Array.isArray(value));
}

function prescriptionText(value, fallback = "") {
  return typeof value === "string" ? value : fallback;
}

/**
 * Prescriptions — Namespace for all prescription functionality.
 */
const Prescriptions = {
  /** Currently loaded prescriptions list */
  list: [],
  /** Currently viewed prescription detail (with items) */
  detail: null,

  // ── Lifecycle Helpers ────────────────────────────────────

  /**
   * Check if a prescription status allows editing.
   */
  isEditable(status) {
    return status === "Active";
  },

  // ── Doctor: Issue Prescription ───────────────────────────

  openIssueModal(appointmentId, doctorName, apptDate, patientName) {
    const safeAppointmentId = safePositiveId(appointmentId);
    if (!safeAppointmentId) {
      showToast("A valid appointment ID is required to issue a prescription.", "error");
      return;
    }
    let modal = document.getElementById("rx-issue-modal");
    if (!modal) {
      modal = document.createElement("div");
      modal.id = "rx-issue-modal";
      modal.className = "modal-overlay";
      modal.innerHTML = `
        <div class="modal rx-modal--large">
          <div class="flex-between rx-modal-header">
            <h3><i class="fas fa-prescription" aria-hidden="true"></i> Issue Prescription</h3>
            <button class="btn btn-outline btn-sm" type="button" data-prescription-action="close-issue">Close</button>
          </div>
          <form id="rx-issue-form" novalidate>
            <input type="hidden" id="rx-appointment-id" />
            <div class="rx-appointment-context">
              <p class="rx-appointment-context-text">
                <strong>Appointment:</strong> <span id="rx-doctor-name"></span>
                &middot; <span id="rx-appt-date"></span>
                &middot; Patient: <span id="rx-patient-name"></span>
              </p>
            </div>
            <div id="rx-items-container"></div>
            <button type="button" class="btn btn-outline btn-sm rx-add-medication-action" data-prescription-action="add-medication" data-container-id="rx-items-container">
              <i class="fas fa-plus" aria-hidden="true"></i> Add Another Medication
            </button>
            <div class="form-group">
              <label for="rx-notes">Prescription Notes (Optional)</label>
              <textarea id="rx-notes" placeholder="General instructions or notes for the patient..." maxlength="2000" class="rx-textarea--short"></textarea>
            </div>
            <div class="rx-form-actions">
              <button type="submit" class="btn btn-primary">Issue Prescription</button>
              <button type="button" class="btn btn-outline" data-prescription-action="close-issue">Cancel</button>
            </div>
          </form>
        </div>
      `;
      document.body.appendChild(modal);
      document
        .getElementById("rx-issue-form")
        .addEventListener("submit", async (e) => {
          e.preventDefault();
          await Prescriptions.submitPrescription();
        });
    }

    document.getElementById("rx-appointment-id").value = String(safeAppointmentId);
    document.getElementById("rx-doctor-name").textContent = prescriptionText(doctorName, "Doctor");
    document.getElementById("rx-appt-date").textContent = prescriptionText(apptDate);
    document.getElementById("rx-patient-name").textContent = prescriptionText(patientName, "Patient");
    document.getElementById("rx-notes").value = "";

    const container = document.getElementById("rx-items-container");
    container.innerHTML = "";
    this.addMedicationItem();
    modal.classList.add("open");
  },

  closeIssueModal() {
    const modal = document.getElementById("rx-issue-modal");
    if (modal) modal.classList.remove("open");
  },

  addMedicationItem(containerId) {
    const container = document.getElementById(
      containerId || "rx-items-container",
    );
    if (!container) return;

    const index = container.children.length + 1;
    const itemDiv = document.createElement("div");
    itemDiv.className = "rx-medication-item";

    itemDiv.innerHTML = `
      <div class="rx-medication-item-header">
        <strong class="rx-medication-item-title"><i class="fas fa-capsules" aria-hidden="true"></i> Medication #${index}</strong>
        ${index > 1 ? `<button type="button" class="btn btn-outline btn-sm rx-medication-remove-button" data-prescription-action="remove-medication"><i class="fas fa-trash" aria-hidden="true"></i> Remove</button>` : ""}
      </div>
      <div class="grid-2 rx-medication-grid">
        <div class="form-group rx-medication-field"><label>Medication Name *</label><input type="text" class="rx-med-name" placeholder="e.g. Amoxicillin" required maxlength="200" /></div>
        <div class="form-group rx-medication-field"><label>Strength *</label><input type="text" class="rx-med-strength" placeholder="e.g. 500 mg" required maxlength="100" /></div>
      </div>
      <div class="grid-2 rx-medication-grid">
        <div class="form-group rx-medication-field"><label>Dosage *</label><input type="text" class="rx-med-dosage" placeholder="e.g. 1 tablet" required maxlength="100" /></div>
        <div class="form-group rx-medication-field"><label>Frequency *</label><input type="text" class="rx-med-frequency" placeholder="e.g. 3 times daily" required maxlength="100" /></div>
      </div>
      <div class="grid-2 rx-medication-grid">
        <div class="form-group rx-medication-field"><label>Duration *</label><input type="text" class="rx-med-duration" placeholder="e.g. 7 days" required maxlength="100" /></div>
        <div class="form-group rx-medication-field--last"><label>Instructions</label><input type="text" class="rx-med-instructions" placeholder="e.g. Take after meals" maxlength="500" /></div>
      </div>
    `;
    container.appendChild(itemDiv);
  },

  collectMedicationItems(containerId) {
    const items = [];
    const itemDivs = document.querySelectorAll(
      `#${containerId || "rx-items-container"} .rx-medication-item`,
    );
    itemDivs.forEach((div) => {
      const name = div.querySelector(".rx-med-name")?.value.trim() || "";
      const strength =
        div.querySelector(".rx-med-strength")?.value.trim() || "";
      const dosage = div.querySelector(".rx-med-dosage")?.value.trim() || "";
      const frequency =
        div.querySelector(".rx-med-frequency")?.value.trim() || "";
      const duration =
        div.querySelector(".rx-med-duration")?.value.trim() || "";
      const instructions =
        div.querySelector(".rx-med-instructions")?.value.trim() || "";
      if (name || strength || dosage || frequency || duration) {
        items.push({
          medication_name: name,
          strength,
          dosage,
          frequency,
          duration,
          instructions: instructions || null,
        });
      }
    });
    return items;
  },

  async submitPrescription() {
    const appointmentId = safePositiveId(document.getElementById("rx-appointment-id")?.value);
    const notes = document.getElementById("rx-notes")?.value.trim() || "";
    const items = this.collectMedicationItems();

    if (!appointmentId) {
      showToast("Appointment ID is missing.", "error");
      return;
    }
    if (items.length === 0) {
      showToast("Please add at least one medication.", "error");
      return;
    }

    for (let i = 0; i < items.length; i++) {
      const item = items[i];
      if (!item.medication_name) {
        showToast(
          `Medication #${i + 1}: Medication name is required.`,
          "error",
        );
        return;
      }
      if (!item.strength) {
        showToast(`Medication #${i + 1}: Strength is required.`, "error");
        return;
      }
      if (!item.dosage) {
        showToast(`Medication #${i + 1}: Dosage is required.`, "error");
        return;
      }
      if (!item.frequency) {
        showToast(`Medication #${i + 1}: Frequency is required.`, "error");
        return;
      }
      if (!item.duration) {
        showToast(`Medication #${i + 1}: Duration is required.`, "error");
        return;
      }
    }

    const submitBtn = document.querySelector("#rx-issue-form [type='submit']");
    const originalText = submitBtn?.textContent || "Issue Prescription";
    setLoading(submitBtn, true, "Issuing...");

    const result = await apiFetch(
      (getBasePath() + "api/prescriptions/create.php"),
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          appointment_id: appointmentId,
          notes,
          items,
        }),
      },
      "Failed to issue prescription.",
    );

    if (result.data?.success) {
      showToast("Prescription issued successfully.", "success");
      this.closeIssueModal();
      if (typeof loadDoctorPrescriptions === "function")
        loadDoctorPrescriptions();
      if (typeof loadDoctorAppointments === "function")
        loadDoctorAppointments();
    } else {
      showToast(
        result.data?.message || "Failed to issue prescription.",
        "error",
      );
    }
    setLoading(submitBtn, false, originalText);
  },

  // ── View Prescription Detail ─────────────────────────────

  async openViewModal(prescriptionId) {
    const safePrescriptionId = safePositiveId(prescriptionId);
    if (!safePrescriptionId) {
      showToast("Invalid prescription ID.", "error");
      return;
    }

    const result = await apiFetch(
      `${getBasePath()}api/prescriptions/get.php?id=${safePrescriptionId}`,
      {},
      "Failed to load prescription details.",
    );
    if (!result.ok || !result.data?.success) {
      showToast(
        result.data?.message || "Failed to load prescription.",
        "error",
      );
      return;
    }
    const rx = result.data.prescription;
    if (!isPrescriptionRecord(rx) || !safePositiveId(rx.id)) {
      showToast("Prescription response was invalid or not found.", "error");
      return;
    }

    this.detail = rx;
    let modal = document.getElementById("rx-view-modal");
    if (!modal) {
      modal = document.createElement("div");
      modal.id = "rx-view-modal";
      modal.className = "modal-overlay";
      modal.innerHTML = `<div class="modal rx-modal--view"><div class="flex-between rx-modal-header"><h3><i class="fas fa-prescription" aria-hidden="true"></i> Prescription Details</h3><button class="btn btn-outline btn-sm" type="button" data-prescription-action="close-view">Close</button></div><div id="rx-view-content"></div></div>`;
      document.body.appendChild(modal);
    }
    const user = getUser();
    const role = user?.role || "patient";
    document.getElementById("rx-view-content").innerHTML = this.renderDetail(
      rx,
      role,
    );
    modal.classList.add("open");
  },

  closeViewModal() {
    const modal = document.getElementById("rx-view-modal");
    if (modal) modal.classList.remove("open");
  },

  /**
   * Print the prescription by opening a standalone print page
   * in a new tab. The page auto-prints and auto-closes.
   * No dashboard UI, no DOM cloning, no CSS hacks.
   */
  printPrescription() {
    const rx = this.detail;
    const prescriptionId = safePositiveId(rx?.id);
    if (!prescriptionId) {
      showToast("No prescription to print.", "error");
      return;
    }
    window.open(
      `${getBasePath()}api/prescriptions/print.php?id=${prescriptionId}`,
      "_blank",
      "noopener,noreferrer",
    );
  },

  /**
   * Format a prescription ID professionally: RX-2026-000001
   */
  formatPrescriptionId(id) {
    const prescriptionId = safePositiveId(id);
    if (!prescriptionId) return "RX-UNKNOWN";
    const year = new Date().getFullYear();
    const padded = String(prescriptionId).padStart(6, "0");
    return `RX-${year}-${padded}`;
  },

  renderDetail(rx, role) {
    if (!isPrescriptionRecord(rx)) {
      return '<div class="rx-empty-state"><h3>Prescription details are unavailable.</h3></div>';
    }
    const status = prescriptionText(rx.status);
    const statusClass = safeClassToken(status || "active");
    const prescriptionId = safePositiveId(rx.id);
    const items = Array.isArray(rx.items)
      ? rx.items.filter(isPrescriptionRecord)
      : [];
    const isEditable = this.isEditable(status);
    const isDoctor = role === "doctor";
    const rxIdFormatted = this.formatPrescriptionId(rx.id);

    // Action buttons for doctor lifecycle management
    let actionButtons = "";
    if (isDoctor && isEditable && prescriptionId) {
      actionButtons = `<div class="rx-print-btn-container"><button class="btn btn-primary btn-sm" data-prescription-action="open-edit" data-prescription-id="${prescriptionId}"><i class="fas fa-pen" aria-hidden="true"></i> Edit</button><button class="btn btn-success btn-sm" data-prescription-action="complete" data-prescription-id="${prescriptionId}"><i class="fas fa-check-circle" aria-hidden="true"></i> Complete</button><button class="btn btn-danger btn-sm" data-prescription-action="open-cancel" data-prescription-id="${prescriptionId}"><i class="fas fa-ban" aria-hidden="true"></i> Cancel</button><button class="btn btn-outline btn-sm" data-prescription-action="print"><i class="fas fa-print" aria-hidden="true"></i> Print</button></div>`;
    } else {
      actionButtons = `<div class="rx-print-btn-container"><button class="btn btn-outline btn-sm" data-prescription-action="print"><i class="fas fa-print" aria-hidden="true"></i> Print</button></div>`;
    }

    // Cancellation reason banner
    let cancelBanner = "";
    if (rx.status === "Cancelled" && rx.cancellation_reason) {
      cancelBanner = `<div class="rx-cancellation-banner"><strong><i class="fas fa-ban" aria-hidden="true"></i> Cancellation Reason</strong><p>${escapeHTML(rx.cancellation_reason)}</p></div>`;
    }

    // Medications HTML
    let medsHtml = "";
    if (items.length === 0) {
      medsHtml = `<div class="rx-empty-state"><i class="fas fa-capsules" aria-hidden="true"></i><h3>No Medications</h3><p>This prescription has no medication items.</p></div>`;
    } else {
      medsHtml = items
        .map(
          (item, i) => `
        <div class="rx-professional-card">
          <div class="rx-professional-card-header">
            <span class="rx-med-name">${escapeHTML(item.medication_name)}</span>
            <span class="rx-med-number">#${i + 1}</span>
          </div>
          <div class="rx-professional-card-body">
            <div class="rx-professional-field">
              <span class="rx-professional-field-label">Strength</span>
              <span class="rx-professional-field-value">${escapeHTML(item.strength)}</span>
            </div>
            <div class="rx-professional-field">
              <span class="rx-professional-field-label">Dosage</span>
              <span class="rx-professional-field-value">${escapeHTML(item.dosage)}</span>
            </div>
            <div class="rx-professional-field">
              <span class="rx-professional-field-label">Frequency</span>
              <span class="rx-professional-field-value">${escapeHTML(item.frequency)}</span>
            </div>
            <div class="rx-professional-field">
              <span class="rx-professional-field-label">Duration</span>
              <span class="rx-professional-field-value">${escapeHTML(item.duration)}</span>
            </div>
            ${item.instructions ? `<div class="rx-professional-card-footer"><div class="rx-professional-field"><span class="rx-professional-field-label">Instructions</span><span class="rx-professional-field-value">${escapeHTML(item.instructions)}</span></div></div>` : ""}
          </div>
        </div>
      `,
        )
        .join("");
    }

    // Notes section
    const notesHtml = rx.notes
      ? `
      <div class="rx-notes-section">
        <div class="rx-notes-section-title"><i class="fas fa-sticky-note" aria-hidden="true"></i> Prescription Notes</div>
        <div class="rx-notes-section-content">${escapeHTML(rx.notes)}</div>
      </div>
    `
      : "";

    // Doctor specialty (placeholder - extendable)
    const doctorSpecialty = prescriptionText(rx.appt_department, "Medical Department");

    return `
      ${actionButtons}
      ${cancelBanner}
      <div class="rx-document">
        <div class="rx-document-inner">
          <!-- Hospital Header -->
          <div class="rx-hospital-header">
            <div class="rx-hospital-logo">
              <i class="fas fa-hospital" aria-hidden="true"></i>
            </div>
            <div class="rx-hospital-info">
              <h2>HealthBridge Hospital</h2>
              <p>123 Healthcare Avenue, Medical District &middot; Tel: +1 (555) 123-4567 &middot; Email: info@healthbridge.com</p>
            </div>
          </div>

          <!-- Prescription Meta -->
          <div class="rx-meta-row">
            <div class="rx-meta-left">
              <span class="rx-id">${escapeHTML(rxIdFormatted)}</span>
              <span class="rx-meta-issued">
                <i class="fas fa-calendar" aria-hidden="true"></i> Issued: ${escapeHTML(formatDate(rx.created_at))}
                ${rx.updated_at && rx.updated_at !== rx.created_at ? `&middot; Updated: ${escapeHTML(formatDate(rx.updated_at))}` : ""}
              </span>
            </div>
            <div class="rx-meta-right">
              <span class="status status-${statusClass}">${escapeHTML(rx.status || "")}</span>
            </div>
          </div>

          <!-- Patient, Doctor, Appointment Info Grid -->
          <div class="rx-info-grid">
            <div class="rx-info-section">
              <div class="rx-info-section-title"><i class="fas fa-user" aria-hidden="true"></i> Patient Information</div>
              <div class="rx-info-row"><span class="rx-info-label">Name</span><span class="rx-info-value">${escapeHTML(rx.patient_name || "N/A")}</span></div>
              <div class="rx-info-row"><span class="rx-info-label">Email</span><span class="rx-info-value">${escapeHTML(rx.patient_email || "—")}</span></div>
            </div>
            <div class="rx-info-section">
              <div class="rx-info-section-title"><i class="fas fa-user-doctor" aria-hidden="true"></i> Doctor Information</div>
              <div class="rx-info-row"><span class="rx-info-label">Name</span><span class="rx-info-value">${escapeHTML(rx.doctor_name || rx.appt_doctor_name || "N/A")}</span></div>
              <div class="rx-info-row"><span class="rx-info-label">Department</span><span class="rx-info-value">${escapeHTML(doctorSpecialty)}</span></div>
            </div>
            <div class="rx-info-section">
              <div class="rx-info-section-title"><i class="fas fa-calendar-check" aria-hidden="true"></i> Appointment</div>
              <div class="rx-info-row"><span class="rx-info-label">Date</span><span class="rx-info-value">${escapeHTML(formatDate(rx.appt_date))}</span></div>
              <div class="rx-info-row"><span class="rx-info-label">Time</span><span class="rx-info-value">${escapeHTML(rx.appointment_time_range || formatTime(rx.appt_time || ""))}</span></div>
              <div class="rx-info-row"><span class="rx-info-label">Department</span><span class="rx-info-value">${escapeHTML(rx.appt_department || "—")}</span></div>
            </div>
          </div>

          <!-- Medications -->
          <div class="rx-meds-section">
            <div class="rx-meds-title"><i class="fas fa-capsules" aria-hidden="true"></i> Prescribed Medications <span class="rx-meds-count">(${items.length} item${items.length !== 1 ? "s" : ""})</span></div>
            ${medsHtml}
          </div>

          <!-- Notes -->
          ${notesHtml}

          <!-- Doctor Signature -->
          <div class="rx-signature">
            <div class="rx-signature-left">
              <div class="rx-signature-title"><i class="fas fa-pen" aria-hidden="true"></i> Prescribing Doctor</div>
              <div class="rx-signature-name">${escapeHTML(rx.doctor_name || rx.appt_doctor_name || "N/A")}</div>
              <div class="rx-signature-details">${escapeHTML(doctorSpecialty)}</div>
              <div class="rx-signature-line">Electronic Signature</div>
            </div>
            <div class="rx-signature-right">
              <div class="rx-signature-code">
                <i class="fas fa-qrcode" aria-hidden="true"></i><br>
                ${escapeHTML(rxIdFormatted)}
              </div>
            </div>
          </div>
        </div>
      </div>
    `;
  },

  // ── Doctor: Edit Prescription ────────────────────────────

  async openEditModal(prescriptionId) {
    const safePrescriptionId = safePositiveId(prescriptionId);
    if (!safePrescriptionId) {
      showToast("Invalid prescription ID.", "error");
      return;
    }
    const result = await apiFetch(
      `${getBasePath()}api/prescriptions/get.php?id=${safePrescriptionId}`,
      {},
      "Failed to load prescription.",
    );
    if (!result.ok || !result.data?.success) {
      showToast("Failed to load prescription.", "error");
      return;
    }
    const rx = result.data.prescription;
    if (!isPrescriptionRecord(rx) || safePositiveId(rx.id) !== safePrescriptionId) {
      showToast("Prescription response was invalid.", "error");
      return;
    }

    let modal = document.getElementById("rx-edit-modal");
    if (!modal) {
      modal = document.createElement("div");
      modal.id = "rx-edit-modal";
      modal.className = "modal-overlay";
      modal.innerHTML = `<div class="modal rx-modal--large"><div class="flex-between rx-modal-header"><h3><i class="fas fa-pen" aria-hidden="true"></i> Edit Prescription</h3><button class="btn btn-outline btn-sm" type="button" data-prescription-action="close-edit">Close</button></div><form id="rx-edit-form" novalidate><input type="hidden" id="rx-edit-id" /><div id="rx-edit-items-container"></div><button type="button" class="btn btn-outline btn-sm rx-add-medication-action" data-prescription-action="add-medication" data-container-id="rx-edit-items-container"><i class="fas fa-plus" aria-hidden="true"></i> Add Another Medication</button><div class="form-group"><label for="rx-edit-notes">Prescription Notes (Optional)</label><textarea id="rx-edit-notes" placeholder="General instructions or notes for the patient..." maxlength="2000" class="rx-textarea--short"></textarea></div><div class="rx-form-actions"><button type="submit" class="btn btn-primary">Save Changes</button><button type="button" class="btn btn-outline" data-prescription-action="close-edit">Cancel</button></div></form></div>`;
      document.body.appendChild(modal);
      document
        .getElementById("rx-edit-form")
        .addEventListener("submit", async (e) => {
          e.preventDefault();
          await Prescriptions.submitEdit();
        });
    }

    document.getElementById("rx-edit-id").value = String(safePrescriptionId);
    document.getElementById("rx-edit-notes").value = prescriptionText(rx.notes);

    const container = document.getElementById("rx-edit-items-container");
    container.innerHTML = "";
    const editItems = Array.isArray(rx.items) ? rx.items.filter(isPrescriptionRecord) : [];
    editItems.forEach((item, i) => {
      const index = i + 1;
      const itemDiv = document.createElement("div");
      itemDiv.className = "rx-medication-item";
      itemDiv.innerHTML = `<div class="rx-medication-item-header"><strong class="rx-medication-item-title"><i class="fas fa-capsules" aria-hidden="true"></i> Medication #${index}</strong>${index > 1 ? `<button type="button" class="btn btn-outline btn-sm rx-medication-remove-button" data-prescription-action="remove-medication"><i class="fas fa-trash" aria-hidden="true"></i> Remove</button>` : ""}</div><div class="grid-2 rx-medication-grid"><div class="form-group rx-medication-field"><label>Medication Name *</label><input type="text" class="rx-med-name" value="${escapeHTML(item.medication_name)}" required maxlength="200" /></div><div class="form-group rx-medication-field"><label>Strength *</label><input type="text" class="rx-med-strength" value="${escapeHTML(item.strength)}" required maxlength="100" /></div></div><div class="grid-2 rx-medication-grid"><div class="form-group rx-medication-field"><label>Dosage *</label><input type="text" class="rx-med-dosage" value="${escapeHTML(item.dosage)}" required maxlength="100" /></div><div class="form-group rx-medication-field"><label>Frequency *</label><input type="text" class="rx-med-frequency" value="${escapeHTML(item.frequency)}" required maxlength="100" /></div></div><div class="grid-2 rx-medication-grid"><div class="form-group rx-medication-field"><label>Duration *</label><input type="text" class="rx-med-duration" value="${escapeHTML(item.duration)}" required maxlength="100" /></div><div class="form-group rx-medication-field--last"><label>Instructions</label><input type="text" class="rx-med-instructions" value="${escapeHTML(item.instructions || "")}" maxlength="500" /></div></div>`;
      container.appendChild(itemDiv);
    });

    modal.classList.add("open");
  },

  closeEditModal() {
    const modal = document.getElementById("rx-edit-modal");
    if (modal) modal.classList.remove("open");
  },

  async submitEdit() {
    const prescriptionId = safePositiveId(document.getElementById("rx-edit-id")?.value);
    const notes = document.getElementById("rx-edit-notes")?.value.trim() || "";
    const items = this.collectMedicationItems("rx-edit-items-container");

    if (!prescriptionId) {
      showToast("Prescription ID is missing.", "error");
      return;
    }
    if (items.length === 0) {
      showToast("Please add at least one medication.", "error");
      return;
    }

    const submitBtn = document.querySelector("#rx-edit-form [type='submit']");
    const originalText = submitBtn?.textContent || "Save Changes";
    setLoading(submitBtn, true, "Saving...");

    const result = await apiFetch(
      (getBasePath() + "api/prescriptions/update.php"),
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          prescription_id: prescriptionId,
          notes,
          items,
        }),
      },
      "Failed to update prescription.",
    );

    if (result.data?.success) {
      showToast("Prescription updated successfully.", "success");
      this.closeEditModal();
      if (typeof window.refreshWS === "function") {
        await window.refreshWS();
      } else if (typeof loadDoctorPrescriptions === "function") {
        loadDoctorPrescriptions();
      }
    } else {
      showToast(
        result.data?.message || "Failed to update prescription.",
        "error",
      );
    }
    setLoading(submitBtn, false, originalText);
  },

  // ── Doctor: Complete Prescription ────────────────────────

  confirmComplete(prescriptionId) {
    const safePrescriptionId = safePositiveId(prescriptionId);
    if (!safePrescriptionId) return;
    if (
      !confirm("Mark this prescription as Completed? It will become read-only.")
    )
      return;
    this.completePrescription(safePrescriptionId);
  },

  async completePrescription(prescriptionId) {
    const safePrescriptionId = safePositiveId(prescriptionId);
    if (!safePrescriptionId) return;
    const result = await apiFetch(
      (getBasePath() + "api/prescriptions/complete.php"),
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ prescription_id: safePrescriptionId }),
      },
      "Failed to complete prescription.",
    );

    if (result.data?.success) {
      showToast("Prescription marked as completed.", "success");
      this.closeViewModal();
      if (typeof window.refreshWS === "function") {
        await window.refreshWS();
      } else if (typeof loadDoctorPrescriptions === "function") {
        loadDoctorPrescriptions();
      }
    } else {
      showToast(
        result.data?.message || "Failed to complete prescription.",
        "error",
      );
    }
  },

  // ── Doctor: Cancel Prescription ──────────────────────────

  openCancelModal(prescriptionId) {
    const safePrescriptionId = safePositiveId(prescriptionId);
    if (!safePrescriptionId) return;
    let modal = document.getElementById("rx-cancel-modal");
    if (!modal) {
      modal = document.createElement("div");
      modal.id = "rx-cancel-modal";
      modal.className = "modal-overlay";
      modal.innerHTML = `<div class="modal rx-modal--compact"><div class="flex-between rx-modal-header"><h3><i class="fas fa-ban" aria-hidden="true"></i> Cancel Prescription</h3><button class="btn btn-outline btn-sm" type="button" data-prescription-action="close-cancel">Close</button></div><form id="rx-cancel-form" novalidate><input type="hidden" id="rx-cancel-id" /><p class="rx-cancel-description">Please provide a reason for cancelling this prescription.</p><div class="form-group"><label for="rx-cancel-reason">Cancellation Reason *</label><textarea id="rx-cancel-reason" placeholder="Explain why this prescription is being cancelled..." required maxlength="2000" class="rx-textarea--medium"></textarea></div><div class="rx-form-actions"><button type="submit" class="btn btn-danger">Cancel Prescription</button><button type="button" class="btn btn-outline" data-prescription-action="close-cancel">Go Back</button></div></form></div>`;
      document.body.appendChild(modal);
      document
        .getElementById("rx-cancel-form")
        .addEventListener("submit", async (e) => {
          e.preventDefault();
          await Prescriptions.submitCancel();
        });
    }
    document.getElementById("rx-cancel-id").value = String(safePrescriptionId);
    document.getElementById("rx-cancel-reason").value = "";
    modal.classList.add("open");
  },

  closeCancelModal() {
    const modal = document.getElementById("rx-cancel-modal");
    if (modal) modal.classList.remove("open");
  },

  async submitCancel() {
    const prescriptionId = safePositiveId(document.getElementById("rx-cancel-id")?.value);
    const reason =
      document.getElementById("rx-cancel-reason")?.value.trim() || "";

    if (!prescriptionId) {
      showToast("Prescription ID is missing.", "error");
      return;
    }
    if (!reason) {
      showToast("Cancellation reason is required.", "error");
      return;
    }

    const submitBtn = document.querySelector("#rx-cancel-form [type='submit']");
    const originalText = submitBtn?.textContent || "Cancel Prescription";
    setLoading(submitBtn, true, "Cancelling...");

    const result = await apiFetch(
      (getBasePath() + "api/prescriptions/cancel.php"),
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          prescription_id: prescriptionId,
          reason,
        }),
      },
      "Failed to cancel prescription.",
    );

    if (result.data?.success) {
      showToast("Prescription cancelled.", "success");
      this.closeCancelModal();
      this.closeViewModal();
      if (typeof window.refreshWS === "function") {
        await window.refreshWS();
      } else if (typeof loadDoctorPrescriptions === "function") {
        loadDoctorPrescriptions();
      }
    } else {
      showToast(
        result.data?.message || "Failed to cancel prescription.",
        "error",
      );
    }
    setLoading(submitBtn, false, originalText);
  },

  // ── Render Prescription List ─────────────────────────────

  renderList(prescriptions, tbodyId, role) {
    const tbody = document.getElementById(tbodyId);
    if (!tbody) return;

    const rows = Array.isArray(prescriptions)
      ? prescriptions.filter(isPrescriptionRecord)
      : [];
    if (rows.length === 0) {
      tbody.innerHTML = `<tr><td colspan="${role === "admin" ? 8 : 7}" class="text-center rx-empty-cell">No prescriptions found.</td></tr>`;
      return;
    }

    tbody.innerHTML = rows
      .map((rx) => {
        const statusClass = safeClassToken(rx.status || "active");
        const prescriptionId = safePositiveId(rx.id);
        const itemCount = safeCount(rx.items_count, 0);
        const viewBtn = prescriptionId
          ? `<button class="btn btn-outline btn-sm" data-prescription-action="open-view" data-prescription-id="${prescriptionId}"><i class="fas fa-eye" aria-hidden="true"></i> View</button>`
          : '<span class="text-muted">Unavailable</span>';

        let extraCols = "";
        if (role === "admin") {
          extraCols = `<td>${escapeHTML(rx.doctor_name || rx.appt_doctor_name || "N/A")}</td><td>${escapeHTML(rx.patient_name || "N/A")}</td>`;
        } else if (role === "doctor") {
          extraCols = `<td>${escapeHTML(rx.patient_name || "N/A")}</td>`;
        }

        const colSpan = role === "admin" ? 8 : 7;

        return `<tr><td>${escapeHTML(formatDate(rx.created_at))}</td>${extraCols}<td>${escapeHTML(formatDate(rx.appt_date))}</td><td>${escapeHTML(rx.appt_department || "")}</td><td><span class="status status-${statusClass}">${escapeHTML(rx.status || "")}</span></td><td><span class="rx-list-item-count">${itemCount} item${itemCount !== 1 ? "s" : ""}</span></td><td>${viewBtn}</td></tr>`;
      })
      .join("");
  },
};

/**
 * Delegate shared Prescription module controls through a fixed action allowlist.
 * Modal/form methods continue to enforce role, ID and state checks.
 */
function bindPrescriptionDelegatedActions() {
  if (window._hbPrescriptionDelegatedActionsBound) return;

  const medicationContainers = new Set([
    "rx-items-container",
    "rx-edit-items-container",
  ]);

  document.addEventListener("click", (event) => {
    const target = event.target instanceof Element ? event.target : null;
    const control = target?.closest("[data-prescription-action]");
    if (!control) return;

    const action = control.dataset.prescriptionAction;
    const prescriptionId = safePositiveId(control.dataset.prescriptionId);
    event.preventDefault();

    switch (action) {
      case "close-issue":
        Prescriptions.closeIssueModal();
        break;
      case "add-medication": {
        const containerId = control.dataset.containerId;
        if (medicationContainers.has(containerId)) {
          Prescriptions.addMedicationItem(containerId);
        }
        break;
      }
      case "remove-medication": {
        const item = control.closest(".rx-medication-item");
        const container = item?.parentElement;
        if (item && container && medicationContainers.has(container.id)) {
          item.remove();
        }
        break;
      }
      case "close-view":
        Prescriptions.closeViewModal();
        break;
      case "open-view":
        if (prescriptionId) Prescriptions.openViewModal(prescriptionId);
        break;
      case "open-edit":
        if (prescriptionId) Prescriptions.openEditModal(prescriptionId);
        break;
      case "close-edit":
        Prescriptions.closeEditModal();
        break;
      case "complete":
        if (prescriptionId) Prescriptions.confirmComplete(prescriptionId);
        break;
      case "open-cancel":
        if (prescriptionId) Prescriptions.openCancelModal(prescriptionId);
        break;
      case "close-cancel":
        Prescriptions.closeCancelModal();
        break;
      case "print":
        Prescriptions.printPrescription();
        break;
    }
  });

  window._hbPrescriptionDelegatedActionsBound = true;
}

// Expose to global scope
window.Prescriptions = Prescriptions;
bindPrescriptionDelegatedActions();


