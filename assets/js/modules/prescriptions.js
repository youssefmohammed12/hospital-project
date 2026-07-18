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
    let modal = document.getElementById("rx-issue-modal");
    if (!modal) {
      modal = document.createElement("div");
      modal.id = "rx-issue-modal";
      modal.className = "modal-overlay";
      modal.innerHTML = `
        <div class="modal" style="max-width:700px;width:min(700px,100%);max-height:90vh;overflow-y:auto">
          <div class="flex-between" style="margin-bottom:var(--s6)">
            <h3><i class="fas fa-prescription" aria-hidden="true"></i> Issue Prescription</h3>
            <button class="btn btn-outline btn-sm" type="button" onclick="Prescriptions.closeIssueModal()">Close</button>
          </div>
          <form id="rx-issue-form" novalidate>
            <input type="hidden" id="rx-appointment-id" />
            <div style="background:var(--bg-surface);padding:var(--s4);border-radius:var(--r-md);border:1px solid var(--border-light);margin-bottom:var(--s5)">
              <p style="margin:0;font-size:0.88rem">
                <strong>Appointment:</strong> <span id="rx-doctor-name"></span>
                &middot; <span id="rx-appt-date"></span>
                &middot; Patient: <span id="rx-patient-name"></span>
              </p>
            </div>
            <div id="rx-items-container"></div>
            <button type="button" class="btn btn-outline btn-sm" onclick="Prescriptions.addMedicationItem()" style="margin-bottom:var(--s5)">
              <i class="fas fa-plus" aria-hidden="true"></i> Add Another Medication
            </button>
            <div class="form-group">
              <label for="rx-notes">Prescription Notes (Optional)</label>
              <textarea id="rx-notes" placeholder="General instructions or notes for the patient..." maxlength="2000" style="min-height:80px"></textarea>
            </div>
            <div style="display:flex;gap:var(--s3);margin-top:var(--s2)">
              <button type="submit" class="btn btn-primary">Issue Prescription</button>
              <button type="button" class="btn btn-outline" onclick="Prescriptions.closeIssueModal()">Cancel</button>
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

    document.getElementById("rx-appointment-id").value = appointmentId;
    document.getElementById("rx-doctor-name").textContent =
      doctorName || "Doctor";
    document.getElementById("rx-appt-date").textContent = apptDate || "";
    document.getElementById("rx-patient-name").textContent =
      patientName || "Patient";
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
    itemDiv.style.cssText =
      "background:var(--bg-surface);border:1px solid var(--border-light);border-radius:var(--r-md);padding:var(--s4);margin-bottom:var(--s4)";

    itemDiv.innerHTML = `
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:var(--s3)">
        <strong style="color:var(--text-primary)"><i class="fas fa-capsules" aria-hidden="true"></i> Medication #${index}</strong>
        ${index > 1 ? `<button type="button" class="btn btn-outline btn-sm" onclick="this.closest('.rx-medication-item').remove()" style="color:var(--danger);border-color:rgba(252,165,165,0.3)"><i class="fas fa-trash" aria-hidden="true"></i> Remove</button>` : ""}
      </div>
      <div class="grid-2" style="gap:var(--s3)">
        <div class="form-group" style="margin-bottom:var(--s3)"><label>Medication Name *</label><input type="text" class="rx-med-name" placeholder="e.g. Amoxicillin" required maxlength="200" /></div>
        <div class="form-group" style="margin-bottom:var(--s3)"><label>Strength *</label><input type="text" class="rx-med-strength" placeholder="e.g. 500 mg" required maxlength="100" /></div>
      </div>
      <div class="grid-2" style="gap:var(--s3)">
        <div class="form-group" style="margin-bottom:var(--s3)"><label>Dosage *</label><input type="text" class="rx-med-dosage" placeholder="e.g. 1 tablet" required maxlength="100" /></div>
        <div class="form-group" style="margin-bottom:var(--s3)"><label>Frequency *</label><input type="text" class="rx-med-frequency" placeholder="e.g. 3 times daily" required maxlength="100" /></div>
      </div>
      <div class="grid-2" style="gap:var(--s3)">
        <div class="form-group" style="margin-bottom:var(--s3)"><label>Duration *</label><input type="text" class="rx-med-duration" placeholder="e.g. 7 days" required maxlength="100" /></div>
        <div class="form-group" style="margin-bottom:0"><label>Instructions</label><input type="text" class="rx-med-instructions" placeholder="e.g. Take after meals" maxlength="500" /></div>
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
    const appointmentId = document.getElementById("rx-appointment-id")?.value;
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
          appointment_id: parseInt(appointmentId),
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
    const result = await apiFetch(
      `${getBasePath()}api/prescriptions/get.php?id=${prescriptionId}`,
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
    if (!rx) {
      showToast("Prescription not found.", "error");
      return;
    }

    this.detail = rx;
    let modal = document.getElementById("rx-view-modal");
    if (!modal) {
      modal = document.createElement("div");
      modal.id = "rx-view-modal";
      modal.className = "modal-overlay";
      modal.innerHTML = `<div class="modal" style="max-width:750px;width:min(750px,100%);max-height:90vh;overflow-y:auto"><div class="flex-between" style="margin-bottom:var(--s6)"><h3><i class="fas fa-prescription" aria-hidden="true"></i> Prescription Details</h3><button class="btn btn-outline btn-sm" type="button" onclick="Prescriptions.closeViewModal()">Close</button></div><div id="rx-view-content"></div></div>`;
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
    if (!rx || !rx.id) {
      showToast("No prescription to print.", "error");
      return;
    }
    window.open(`${getBasePath()}api/prescriptions/print.php?id=${rx.id}`, "_blank");
  },

  /**
   * Format a prescription ID professionally: RX-2026-000001
   */
  formatPrescriptionId(id) {
    const year = new Date().getFullYear();
    const padded = String(id).padStart(6, "0");
    return `RX-${year}-${padded}`;
  },

  renderDetail(rx, role) {
    const statusClass = (rx.status || "active").toLowerCase();
    const items = rx.items || [];
    const isEditable = this.isEditable(rx.status);
    const isDoctor = role === "doctor";
    const rxIdFormatted = this.formatPrescriptionId(rx.id);

    // Action buttons for doctor lifecycle management
    let actionButtons = "";
    if (isDoctor && isEditable) {
      actionButtons = `<div class="rx-print-btn-container"><button class="btn btn-primary btn-sm" onclick="Prescriptions.openEditModal(${rx.id})"><i class="fas fa-pen" aria-hidden="true"></i> Edit</button><button class="btn btn-success btn-sm" onclick="Prescriptions.confirmComplete(${rx.id})"><i class="fas fa-check-circle" aria-hidden="true"></i> Complete</button><button class="btn btn-danger btn-sm" onclick="Prescriptions.openCancelModal(${rx.id})"><i class="fas fa-ban" aria-hidden="true"></i> Cancel</button><button class="btn btn-outline btn-sm" onclick="Prescriptions.printPrescription()"><i class="fas fa-print" aria-hidden="true"></i> Print</button></div>`;
    } else {
      actionButtons = `<div class="rx-print-btn-container"><button class="btn btn-outline btn-sm" onclick="Prescriptions.printPrescription()"><i class="fas fa-print" aria-hidden="true"></i> Print</button></div>`;
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
    const doctorSpecialty = rx.appt_department || "Medical Department";

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
              <span style="font-size:0.82rem;color:var(--text-muted);margin-top:var(--s1)">
                <i class="fas fa-calendar" aria-hidden="true"></i> Issued: ${escapeHTML(formatDate(rx.created_at))}
                ${rx.updated_at && rx.updated_at !== rx.created_at ? `&middot; Updated: ${escapeHTML(formatDate(rx.updated_at))}` : ""}
              </span>
            </div>
            <div class="rx-meta-right">
              <span class="status status-${statusClass}">${rx.status}</span>
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
            <div class="rx-meds-title"><i class="fas fa-capsules" aria-hidden="true"></i> Prescribed Medications <span style="font-weight:400;font-size:0.8rem;color:var(--text-muted)">(${items.length} item${items.length !== 1 ? "s" : ""})</span></div>
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
              <div style="font-size:0.78rem;color:var(--text-muted)">
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
    const result = await apiFetch(
      `${getBasePath()}api/prescriptions/get.php?id=${prescriptionId}`,
      {},
      "Failed to load prescription.",
    );
    if (!result.ok || !result.data?.success) {
      showToast("Failed to load prescription.", "error");
      return;
    }
    const rx = result.data.prescription;

    let modal = document.getElementById("rx-edit-modal");
    if (!modal) {
      modal = document.createElement("div");
      modal.id = "rx-edit-modal";
      modal.className = "modal-overlay";
      modal.innerHTML = `<div class="modal" style="max-width:700px;width:min(700px,100%);max-height:90vh;overflow-y:auto"><div class="flex-between" style="margin-bottom:var(--s6)"><h3><i class="fas fa-pen" aria-hidden="true"></i> Edit Prescription</h3><button class="btn btn-outline btn-sm" type="button" onclick="Prescriptions.closeEditModal()">Close</button></div><form id="rx-edit-form" novalidate><input type="hidden" id="rx-edit-id" /><div id="rx-edit-items-container"></div><button type="button" class="btn btn-outline btn-sm" onclick="Prescriptions.addMedicationItem('rx-edit-items-container')" style="margin-bottom:var(--s5)"><i class="fas fa-plus" aria-hidden="true"></i> Add Another Medication</button><div class="form-group"><label for="rx-edit-notes">Prescription Notes (Optional)</label><textarea id="rx-edit-notes" placeholder="General instructions or notes for the patient..." maxlength="2000" style="min-height:80px"></textarea></div><div style="display:flex;gap:var(--s3);margin-top:var(--s2)"><button type="submit" class="btn btn-primary">Save Changes</button><button type="button" class="btn btn-outline" onclick="Prescriptions.closeEditModal()">Cancel</button></div></form></div>`;
      document.body.appendChild(modal);
      document
        .getElementById("rx-edit-form")
        .addEventListener("submit", async (e) => {
          e.preventDefault();
          await Prescriptions.submitEdit();
        });
    }

    document.getElementById("rx-edit-id").value = rx.id;
    document.getElementById("rx-edit-notes").value = rx.notes || "";

    const container = document.getElementById("rx-edit-items-container");
    container.innerHTML = "";
    (rx.items || []).forEach((item, i) => {
      const index = i + 1;
      const itemDiv = document.createElement("div");
      itemDiv.className = "rx-medication-item";
      itemDiv.style.cssText =
        "background:var(--bg-surface);border:1px solid var(--border-light);border-radius:var(--r-md);padding:var(--s4);margin-bottom:var(--s4)";
      itemDiv.innerHTML = `<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:var(--s3)"><strong style="color:var(--text-primary)"><i class="fas fa-capsules" aria-hidden="true"></i> Medication #${index}</strong>${index > 1 ? `<button type="button" class="btn btn-outline btn-sm" onclick="this.closest('.rx-medication-item').remove()" style="color:var(--danger);border-color:rgba(252,165,165,0.3)"><i class="fas fa-trash" aria-hidden="true"></i> Remove</button>` : ""}</div><div class="grid-2" style="gap:var(--s3)"><div class="form-group" style="margin-bottom:var(--s3)"><label>Medication Name *</label><input type="text" class="rx-med-name" value="${escapeHTML(item.medication_name)}" required maxlength="200" /></div><div class="form-group" style="margin-bottom:var(--s3)"><label>Strength *</label><input type="text" class="rx-med-strength" value="${escapeHTML(item.strength)}" required maxlength="100" /></div></div><div class="grid-2" style="gap:var(--s3)"><div class="form-group" style="margin-bottom:var(--s3)"><label>Dosage *</label><input type="text" class="rx-med-dosage" value="${escapeHTML(item.dosage)}" required maxlength="100" /></div><div class="form-group" style="margin-bottom:var(--s3)"><label>Frequency *</label><input type="text" class="rx-med-frequency" value="${escapeHTML(item.frequency)}" required maxlength="100" /></div></div><div class="grid-2" style="gap:var(--s3)"><div class="form-group" style="margin-bottom:var(--s3)"><label>Duration *</label><input type="text" class="rx-med-duration" value="${escapeHTML(item.duration)}" required maxlength="100" /></div><div class="form-group" style="margin-bottom:0"><label>Instructions</label><input type="text" class="rx-med-instructions" value="${escapeHTML(item.instructions || "")}" maxlength="500" /></div></div>`;
      container.appendChild(itemDiv);
    });

    modal.classList.add("open");
  },

  closeEditModal() {
    const modal = document.getElementById("rx-edit-modal");
    if (modal) modal.classList.remove("open");
  },

  async submitEdit() {
    const prescriptionId = document.getElementById("rx-edit-id")?.value;
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
          prescription_id: parseInt(prescriptionId),
          notes,
          items,
        }),
      },
      "Failed to update prescription.",
    );

    if (result.data?.success) {
      showToast("Prescription updated successfully.", "success");
      this.closeEditModal();
      if (typeof loadDoctorPrescriptions === "function")
        loadDoctorPrescriptions();
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
    if (
      !confirm("Mark this prescription as Completed? It will become read-only.")
    )
      return;
    this.completePrescription(prescriptionId);
  },

  async completePrescription(prescriptionId) {
    const result = await apiFetch(
      (getBasePath() + "api/prescriptions/complete.php"),
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ prescription_id: prescriptionId }),
      },
      "Failed to complete prescription.",
    );

    if (result.data?.success) {
      showToast("Prescription marked as completed.", "success");
      this.closeViewModal();
      if (typeof loadDoctorPrescriptions === "function")
        loadDoctorPrescriptions();
    } else {
      showToast(
        result.data?.message || "Failed to complete prescription.",
        "error",
      );
    }
  },

  // ── Doctor: Cancel Prescription ──────────────────────────

  openCancelModal(prescriptionId) {
    let modal = document.getElementById("rx-cancel-modal");
    if (!modal) {
      modal = document.createElement("div");
      modal.id = "rx-cancel-modal";
      modal.className = "modal-overlay";
      modal.innerHTML = `<div class="modal" style="max-width:500px;width:min(500px,100%)"><div class="flex-between" style="margin-bottom:var(--s6)"><h3><i class="fas fa-ban" aria-hidden="true"></i> Cancel Prescription</h3><button class="btn btn-outline btn-sm" type="button" onclick="Prescriptions.closeCancelModal()">Close</button></div><form id="rx-cancel-form" novalidate><input type="hidden" id="rx-cancel-id" /><p style="margin-bottom:var(--s4);color:var(--text-secondary)">Please provide a reason for cancelling this prescription.</p><div class="form-group"><label for="rx-cancel-reason">Cancellation Reason *</label><textarea id="rx-cancel-reason" placeholder="Explain why this prescription is being cancelled..." required maxlength="2000" style="min-height:100px"></textarea></div><div style="display:flex;gap:var(--s3);margin-top:var(--s2)"><button type="submit" class="btn btn-danger">Cancel Prescription</button><button type="button" class="btn btn-outline" onclick="Prescriptions.closeCancelModal()">Go Back</button></div></form></div>`;
      document.body.appendChild(modal);
      document
        .getElementById("rx-cancel-form")
        .addEventListener("submit", async (e) => {
          e.preventDefault();
          await Prescriptions.submitCancel();
        });
    }
    document.getElementById("rx-cancel-id").value = prescriptionId;
    document.getElementById("rx-cancel-reason").value = "";
    modal.classList.add("open");
  },

  closeCancelModal() {
    const modal = document.getElementById("rx-cancel-modal");
    if (modal) modal.classList.remove("open");
  },

  async submitCancel() {
    const prescriptionId = document.getElementById("rx-cancel-id")?.value;
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
          prescription_id: parseInt(prescriptionId),
          reason,
        }),
      },
      "Failed to cancel prescription.",
    );

    if (result.data?.success) {
      showToast("Prescription cancelled.", "success");
      this.closeCancelModal();
      this.closeViewModal();
      if (typeof loadDoctorPrescriptions === "function")
        loadDoctorPrescriptions();
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

    if (!prescriptions || prescriptions.length === 0) {
      tbody.innerHTML = `<tr><td colspan="${role === "admin" ? 8 : 7}" class="text-center" style="padding:var(--s8);color:var(--text-muted)">No prescriptions found.</td></tr>`;
      return;
    }

    tbody.innerHTML = prescriptions
      .map((rx) => {
        const statusClass = (rx.status || "active").toLowerCase();
        const viewBtn = `<button class="btn btn-outline btn-sm" onclick="Prescriptions.openViewModal(${rx.id})"><i class="fas fa-eye" aria-hidden="true"></i> View</button>`;

        let extraCols = "";
        if (role === "admin") {
          extraCols = `<td>${escapeHTML(rx.doctor_name || rx.appt_doctor_name || "N/A")}</td><td>${escapeHTML(rx.patient_name || "N/A")}</td>`;
        } else if (role === "doctor") {
          extraCols = `<td>${escapeHTML(rx.patient_name || "N/A")}</td>`;
        }

        const colSpan = role === "admin" ? 8 : 7;

        return `<tr><td>${escapeHTML(formatDate(rx.created_at))}</td>${extraCols}<td>${escapeHTML(formatDate(rx.appt_date))}</td><td>${escapeHTML(rx.appt_department || "")}</td><td><span class="status status-${statusClass}">${rx.status}</span></td><td><span style="color:var(--text-secondary)">${rx.items_count || 0} item${(rx.items_count || 0) !== 1 ? "s" : ""}</span></td><td>${viewBtn}</td></tr>`;
      })
      .join("");
  },
};

// Expose to global scope
window.Prescriptions = Prescriptions;


