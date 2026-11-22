/**
 * HealthBridge — Medical Records Shared JavaScript
 * Reusable module for viewing and editing medical records.
 * Used by: dashboard.js (patient), doctor-dashboard.js (doctor), admin.js (admin)
 *
 * Dependencies: main.js (escapeHTML, formatDate, showToast, apiFetch, setLoading)
 */

"use strict";

/**
 * MedicalRecords — Namespace for all medical record functionality.
 * Each role gets different capabilities:
 *   - patient: view only
 *   - doctor: view + edit + add visit notes
 *   - admin: view + edit
 */
const MedicalRecords = {
  /** Current patient ID being viewed */
  patientId: 0,
  /** Current user's role */
  role: "",
  /** Whether the current user can edit */
  canEdit: false,
  /** Cached medical record data */
  data: null,
  /** Currently active tab name */
  activeTab: "overview",

  /**
   * Initialize the medical records module.
   * @param {number} patientId - The patient to load records for
   * @param {string} role - Current user role ('patient', 'doctor', 'admin')
   * @param {string} containerId - ID of the container element
   */
  async init(patientId, role, containerId = "medical-record-container") {
    this.patientId = safePositiveId(patientId);
    if (!this.patientId) return;
    this.role = ["patient", "doctor", "admin"].includes(role) ? role : "";
    this.containerId = containerId;

    const container = document.getElementById(containerId);
    if (!container) return;

    container.innerHTML =
      '<p class="mr-state-message mr-state-message--loading">Loading medical record...</p>';

    const result = await apiFetch(
      `${getBasePath()}api/medical/get-record.php?patient_id=${this.patientId}`,
      {},
      "Failed to load medical record.",
    );

    if (!result.ok || !result.data?.success) {
      container.innerHTML = `<p class="mr-state-message mr-state-message--error">${
        escapeHTML(result.data?.message || "Failed to load medical record.")
      }</p>`;
      return;
    }

    this.data = result.data;
    this.canEdit = result.data.can_edit === true
      && ["doctor", "admin"].includes(this.role);
    this.render();
  },

  /**
   * Render the full medical record UI.
   */
  render() {
    const container = document.getElementById(this.containerId);
    if (!container || !this.data) return;

    const mr = this.data.medical_record && typeof this.data.medical_record === "object" && !Array.isArray(this.data.medical_record)
      ? this.data.medical_record : {};
    const patient = this.data.patient && typeof this.data.patient === "object" && !Array.isArray(this.data.patient)
      ? this.data.patient : {};
    const visits = Array.isArray(this.data.visit_history)
      ? this.data.visit_history.filter((visit) => visit && typeof visit === "object" && !Array.isArray(visit))
      : [];

    container.innerHTML = `
      <div class="medical-record-container">
        ${this.renderHeader(patient)}
        ${this.renderTabs()}
        <div class="mr-tab-content active" data-mr-tab="overview">
          ${this.renderOverview(mr)}
        </div>
        <div class="mr-tab-content" data-mr-tab="history">
          ${this.renderMedicalHistory(mr)}
        </div>
        <div class="mr-tab-content" data-mr-tab="allergies">
          ${this.renderAllergies(mr)}
        </div>
        <div class="mr-tab-content" data-mr-tab="medications">
          ${this.renderMedications(mr)}
        </div>
        <div class="mr-tab-content" data-mr-tab="visits">
          ${this.renderVisitHistory(visits)}
        </div>
        <div class="mr-tab-content" data-mr-tab="emergency">
          ${this.renderEmergency(mr)}
        </div>
      </div>
    `;

    this.bindTabEvents();
    this.bindEditButtons();
  },

  /**
   * Render the header with patient name and action buttons.
   */
  renderHeader(patient) {
    const editBtn = this.canEdit === true && ["doctor", "admin"].includes(this.role)
      ? `<button class="btn btn-outline btn-sm" data-mr-action="open-edit">
           <i class="fas fa-pen" aria-hidden="true"></i> Edit Record
         </button>`
      : "";

    return `
      <div class="mr-header">
        <div class="mr-header-info">
          <h2><i class="fas fa-notes-medical" aria-hidden="true"></i> Medical Record</h2>
          <p>${escapeHTML(patient.name || "Patient")} &middot; ${escapeHTML(patient.email || "")}</p>
        </div>
        <div class="mr-header-actions">
          ${editBtn}
        </div>
      </div>
    `;
  },

  /**
   * Render the tab navigation.
   */
  renderTabs() {
    const tabs = [
      { id: "overview", icon: "fa-heart-pulse", label: "Overview" },
      { id: "history", icon: "fa-notes-medical", label: "Medical History" },
      { id: "allergies", icon: "fa-triangle-exclamation", label: "Allergies" },
      { id: "medications", icon: "fa-capsules", label: "Medications" },
      { id: "visits", icon: "fa-calendar-check", label: "Visit History" },
      { id: "emergency", icon: "fa-truck-medical", label: "Emergency" },
    ];

    return `
      <div class="mr-tabs">
        ${tabs
          .map(
            (t) => `
          <button class="mr-tab ${t.id === this.activeTab ? "active" : ""}"
                  data-mr-tab="${t.id}">
            <i class="fas ${t.icon}" aria-hidden="true"></i> ${t.label}
          </button>
        `,
          )
          .join("")}
      </div>
    `;
  },

  /**
   * Switch between tabs.
   */
  switchTab(tabId) {
    this.activeTab = tabId;

    // Update tab buttons
    document.querySelectorAll(".mr-tab").forEach((btn) => {
      btn.classList.toggle("active", btn.dataset.mrTab === tabId);
    });

    // Update tab content
    document.querySelectorAll(".mr-tab-content").forEach((content) => {
      content.classList.toggle("active", content.dataset.mrTab === tabId);
    });
  },

  /**
   * Bind tab click events (fallback for dynamically created tabs).
   */
  bindTabEvents() {
    this.bindDelegatedActions();
  },

  /**
   * Bind edit button events.
   */
  bindEditButtons() {
    this.bindDelegatedActions();
  },

  /**
   * Delegated listener covers the record container and dynamic modal dialogs.
   * Existing action methods continue to enforce role and capability checks.
   */
  bindDelegatedActions() {
    if (window._hbMedicalRecordsDelegatedActionsBound) return;

    document.addEventListener("click", (event) => {
      const target = event.target instanceof Element ? event.target : null;
      if (!target) return;

      const tab = target.closest(".mr-tab[data-mr-tab]");
      if (tab) {
        event.preventDefault();
        const tabId = tab.dataset.mrTab;
        if (["overview", "history", "allergies", "medications", "visits", "emergency"].includes(tabId)) {
          MedicalRecords.switchTab(tabId);
        }
        return;
      }

      const action = target.closest("[data-mr-action]");
      if (!action) return;
      event.preventDefault();

      switch (action.dataset.mrAction) {
        case "open-edit":
          MedicalRecords.openEditModal();
          break;
        case "close-edit":
          MedicalRecords.closeEditModal();
          break;
        case "close-visit-note":
          MedicalRecords.closeVisitNoteModal();
          break;
      }
    });

    window._hbMedicalRecordsDelegatedActionsBound = true;
  },

  /**
   * Render the Overview tab.
   */
  renderOverview(mr) {
    const items = [
      { label: "Blood Type", value: mr.blood_type },
      { label: "Height", value: mr.height_cm ? `${mr.height_cm} cm` : null },
      { label: "Weight", value: mr.weight_kg ? `${mr.weight_kg} kg` : null },
      {
        label: "Date of Birth",
        value: mr.date_of_birth ? formatDate(mr.date_of_birth) : null,
      },
      { label: "Gender", value: mr.gender },
      {
        label: "Last Updated",
        value: mr.updated_at ? formatDate(mr.updated_at) : "Not yet updated",
      },
    ];

    return `
      <h4 class="mr-section-heading">
        <i class="fas fa-heart-pulse" aria-hidden="true"></i> Basic Information
      </h4>
      <div class="mr-info-grid">
        ${items
          .map(
            (item) => `
          <div class="mr-info-card">
            <div class="label">${item.label}</div>
            <div class="value${item.value ? "" : " empty"}">${item.value ? escapeHTML(item.value) : "—"}</div>
          </div>
        `,
          )
          .join("")}
      </div>
      ${
        mr.medical_notes
          ? `
        <div class="mr-info-card mr-info-card--wide mr-info-card--spaced">
          <div class="label">Medical Notes</div>
          <div class="value mr-pre-wrap">${escapeHTML(mr.medical_notes)}</div>
        </div>
      `
          : ""
      }
    `;
  },

  /**
   * Render the Medical History tab.
   */
  renderMedicalHistory(mr) {
    const items = [
      { label: "Chronic Diseases", value: mr.chronic_diseases },
      { label: "Previous Surgeries", value: mr.previous_surgeries },
      { label: "Family History", value: mr.family_history },
    ];

    const hasContent = items.some((i) => i.value);

    if (!hasContent) {
      return `
        <div class="mr-empty">
          <i class="fas fa-notes-medical" aria-hidden="true"></i>
          <h4>No Medical History Recorded</h4>
          <p>Medical history information will appear here once added by a healthcare provider.</p>
        </div>
      `;
    }

    return `
      <h4 class="mr-section-heading">
        <i class="fas fa-notes-medical" aria-hidden="true"></i> Medical History
      </h4>
      <div class="mr-info-grid">
        ${items
          .map(
            (item) => `
          <div class="mr-info-card${item.label === "Family History" ? " mr-info-card--wide" : ""}">
            <div class="label">${item.label}</div>
            <div class="value${item.value ? "" : " empty"}">${item.value ? escapeHTML(item.value) : "None recorded"}</div>
          </div>
        `,
          )
          .join("")}
      </div>
    `;
  },

  /**
   * Render the Allergies tab.
   */
  renderAllergies(mr) {
    if (!mr.allergies) {
      return `
        <div class="mr-empty">
          <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
          <h4>No Allergies Recorded</h4>
          <p>Allergy information will appear here once added by a healthcare provider.</p>
        </div>
      `;
    }

    const allergies = (typeof mr.allergies === "string" ? mr.allergies : "")
      .split(",")
      .map((a) => a.trim())
      .filter(Boolean);

    return `
      <h4 class="mr-section-heading">
        <i class="fas fa-triangle-exclamation" aria-hidden="true"></i> Known Allergies
      </h4>
      <div class="mr-chip-list">
        ${allergies
          .map(
            (a) => `
          <span class="badge mr-chip mr-chip--allergy">
            <i class="fas fa-ban" aria-hidden="true"></i> ${escapeHTML(a)}
          </span>
        `,
          )
          .join("")}
      </div>
    `;
  },

  /**
   * Render the Medications tab.
   */
  renderMedications(mr) {
    if (!mr.current_medications) {
      return `
        <div class="mr-empty">
          <i class="fas fa-capsules" aria-hidden="true"></i>
          <h4>No Medications Recorded</h4>
          <p>Current medications will appear here once added by a healthcare provider.</p>
        </div>
      `;
    }

    const meds = (typeof mr.current_medications === "string" ? mr.current_medications : "")
      .split(",")
      .map((m) => m.trim())
      .filter(Boolean);

    return `
      <h4 class="mr-section-heading">
        <i class="fas fa-capsules" aria-hidden="true"></i> Current Medications
      </h4>
      <div class="mr-chip-list">
        ${meds
          .map(
            (m) => `
          <span class="badge mr-chip mr-chip--medication">
            <i class="fas fa-prescription" aria-hidden="true"></i> ${escapeHTML(m)}
          </span>
        `,
          )
          .join("")}
      </div>
    `;
  },

  /**
   * Render the Visit History tab.
   */
  renderVisitHistory(visits) {
    if (!visits || visits.length === 0) {
      return `
        <div class="mr-empty">
          <i class="fas fa-calendar-check" aria-hidden="true"></i>
          <h4>No Visit History</h4>
          <p>Completed appointments with doctor notes will appear here.</p>
        </div>
      `;
    }

    return `
      <h4 class="mr-section-heading">
        <i class="fas fa-calendar-check" aria-hidden="true"></i> Visit History
        <span class="mr-visit-count">(${visits.length} visit${visits.length !== 1 ? "s" : ""})</span>
      </h4>
      <div class="visit-timeline">
        ${visits
          .map(
            (v) => `
          <div class="visit-card">
            <div class="visit-card-header">
              <span class="visit-doctor">
                <i class="fas fa-user-doctor" aria-hidden="true"></i> ${escapeHTML(v.doctor_display_name || v.doctor_name || "Doctor")}
              </span>
              <span class="visit-date">
                <i class="fas fa-calendar" aria-hidden="true"></i> ${escapeHTML(formatDate(v.appt_date))} at ${escapeHTML(formatApptTime(v))}
                &middot; ${escapeHTML(v.department || "")}
              </span>
            </div>
            <div class="visit-card-body">
              <div class="visit-field">
                <div class="field-label">Diagnosis</div>
                <div class="field-value${v.diagnosis ? "" : " empty"}">${v.diagnosis ? escapeHTML(v.diagnosis) : "Not specified"}</div>
              </div>
              <div class="visit-field">
                <div class="field-label">Symptoms</div>
                <div class="field-value${v.symptoms ? "" : " empty"}">${v.symptoms ? escapeHTML(v.symptoms) : "Not specified"}</div>
              </div>
              <div class="visit-field">
                <div class="field-label">Treatment</div>
                <div class="field-value${v.treatment ? "" : " empty"}">${v.treatment ? escapeHTML(v.treatment) : "Not specified"}</div>
              </div>
              <div class="visit-field">
                <div class="field-label">Doctor's Notes</div>
                <div class="field-value${v.doctor_notes ? "" : " empty"}">${v.doctor_notes ? escapeHTML(v.doctor_notes) : "No additional notes"}</div>
              </div>
              <div class="visit-field">
                <div class="field-label">Follow-up Instructions</div>
                <div class="field-value${v.follow_up_instructions ? "" : " empty"}">${v.follow_up_instructions ? escapeHTML(v.follow_up_instructions) : "None provided"}</div>
              </div>
            </div>
          </div>
        `,
          )
          .join("")}
      </div>
    `;
  },

  /**
   * Render the Emergency Contact tab.
   */
  renderEmergency(mr) {
    const hasEmergency = mr.emergency_contact_name;

    if (!hasEmergency) {
      return `
        <div class="mr-empty">
          <i class="fas fa-truck-medical" aria-hidden="true"></i>
          <h4>No Emergency Contact</h4>
          <p>Emergency contact information will appear here once added.</p>
        </div>
      `;
    }

    const items = [
      { label: "Contact Name", value: mr.emergency_contact_name },
      { label: "Relationship", value: mr.emergency_contact_rel },
      { label: "Phone Number", value: mr.emergency_contact_phone },
    ];

    return `
      <h4 class="mr-section-heading">
        <i class="fas fa-truck-medical" aria-hidden="true"></i> Emergency Contact
      </h4>
      <div class="mr-info-grid">
        ${items
          .map(
            (item) => `
          <div class="mr-info-card">
            <div class="label">${item.label}</div>
            <div class="value${item.value ? "" : " empty"}">${item.value ? escapeHTML(item.value) : "—"}</div>
          </div>
        `,
          )
          .join("")}
      </div>
    `;
  },

  /**
   * Open the Edit Medical Record modal.
   * Only available for doctors and admins.
   */
  openEditModal() {
    if (this.canEdit !== true || !["doctor", "admin"].includes(this.role) || !this.data) return;

    const mr = this.data.medical_record || {};

    // Create modal if it doesn't exist
    let modal = document.getElementById("mr-edit-modal");
    if (!modal) {
      modal = document.createElement("div");
      modal.id = "mr-edit-modal";
      modal.className = "modal-overlay";
      modal.innerHTML = `
        <div class="modal mr-medical-record-modal">
          <div class="flex-between mr-modal-header">
            <h3><i class="fas fa-notes-medical" aria-hidden="true"></i> Edit Medical Record</h3>
            <button class="btn btn-outline btn-sm" type="button" data-mr-action="close-edit">Close</button>
          </div>
          <form id="mr-edit-form" class="mr-edit-form" novalidate>
            <div class="form-group">
              <label for="mr-blood-type">Blood Type</label>
              <select id="mr-blood-type">
                <option value="">Select</option>
                <option value="A+">A+</option>
                <option value="A-">A-</option>
                <option value="B+">B+</option>
                <option value="B-">B-</option>
                <option value="AB+">AB+</option>
                <option value="AB-">AB-</option>
                <option value="O+">O+</option>
                <option value="O-">O-</option>
              </select>
            </div>
            <div class="form-group">
              <label for="mr-height">Height (cm)</label>
              <input type="number" id="mr-height" step="0.1" min="50" max="250" placeholder="e.g. 175" />
            </div>
            <div class="form-group">
              <label for="mr-weight">Weight (kg)</label>
              <input type="number" id="mr-weight" step="0.1" min="20" max="300" placeholder="e.g. 70" />
            </div>
            <div class="form-group">
              <label for="mr-dob">Date of Birth</label>
              <input type="date" id="mr-dob" />
            </div>
            <div class="form-group">
              <label for="mr-gender">Gender</label>
              <select id="mr-gender">
                <option value="">Select</option>
                <option value="Male">Male</option>
                <option value="Female">Female</option>
                <option value="Other">Other</option>
              </select>
            </div>
            <div class="form-group full-width">
              <label for="mr-allergies">Allergies (comma-separated)</label>
              <textarea id="mr-allergies" placeholder="e.g. Penicillin, Peanuts, Latex" maxlength="1000"></textarea>
            </div>
            <div class="form-group full-width">
              <label for="mr-chronic">Chronic Diseases (comma-separated)</label>
              <textarea id="mr-chronic" placeholder="e.g. Diabetes Type 2, Hypertension" maxlength="1000"></textarea>
            </div>
            <div class="form-group full-width">
              <label for="mr-medications">Current Medications (comma-separated)</label>
              <textarea id="mr-medications" placeholder="e.g. Metformin 500mg, Lisinopril 10mg" maxlength="1000"></textarea>
            </div>
            <div class="form-group full-width">
              <label for="mr-surgeries">Previous Surgeries (comma-separated)</label>
              <textarea id="mr-surgeries" placeholder="e.g. Appendectomy (2019), Knee Arthroscopy (2021)" maxlength="1000"></textarea>
            </div>
            <div class="form-group full-width">
              <label for="mr-family">Family Medical History</label>
              <textarea id="mr-family" placeholder="e.g. Father: Hypertension, Mother: Diabetes" maxlength="2000"></textarea>
            </div>
            <div class="form-group">
              <label for="mr-emergency-name">Emergency Contact Name</label>
              <input type="text" id="mr-emergency-name" placeholder="Full name" maxlength="100" />
            </div>
            <div class="form-group">
              <label for="mr-emergency-rel">Relationship</label>
              <input type="text" id="mr-emergency-rel" placeholder="e.g. Spouse, Parent" maxlength="50" />
            </div>
            <div class="form-group">
              <label for="mr-emergency-phone">Emergency Phone</label>
              <input type="tel" id="mr-emergency-phone" placeholder="+1234567890" maxlength="20" />
            </div>
            <div class="form-group full-width">
              <label for="mr-notes">Medical Notes</label>
              <textarea id="mr-notes" class="mr-textarea--large" placeholder="General medical notes..." maxlength="5000"></textarea>
            </div>
            <div class="full-width mr-modal-actions">
              <button type="submit" class="btn btn-primary">Save Changes</button>
              <button type="button" class="btn btn-outline" data-mr-action="close-edit">Cancel</button>
            </div>
          </form>
        </div>
      `;
      document.body.appendChild(modal);

      // Form submit handler
      document
        .getElementById("mr-edit-form")
        .addEventListener("submit", async (e) => {
          e.preventDefault();
          await MedicalRecords.saveEdit();
        });
    }

    // Populate form with current values
    document.getElementById("mr-blood-type").value = mr.blood_type || "";
    document.getElementById("mr-height").value = mr.height_cm || "";
    document.getElementById("mr-weight").value = mr.weight_kg || "";
    document.getElementById("mr-dob").value = mr.date_of_birth || "";
    document.getElementById("mr-gender").value = mr.gender || "";
    document.getElementById("mr-allergies").value = mr.allergies || "";
    document.getElementById("mr-chronic").value = mr.chronic_diseases || "";
    document.getElementById("mr-medications").value =
      mr.current_medications || "";
    document.getElementById("mr-surgeries").value = mr.previous_surgeries || "";
    document.getElementById("mr-family").value = mr.family_history || "";
    document.getElementById("mr-emergency-name").value =
      mr.emergency_contact_name || "";
    document.getElementById("mr-emergency-rel").value =
      mr.emergency_contact_rel || "";
    document.getElementById("mr-emergency-phone").value =
      mr.emergency_contact_phone || "";
    document.getElementById("mr-notes").value = mr.medical_notes || "";

    modal.classList.add("open");
  },

  /**
   * Close the Edit Medical Record modal.
   */
  closeEditModal() {
    const modal = document.getElementById("mr-edit-modal");
    if (modal) modal.classList.remove("open");
  },

  /**
   * Save the edited medical record.
   */
  async saveEdit() {
    const patientId = safePositiveId(this.patientId);
    if (!patientId || this.canEdit !== true || !["doctor", "admin"].includes(this.role)) {
      showToast("You are not allowed to edit this medical record.", "error");
      return;
    }
    const data = {
      patient_id: patientId,
      blood_type: document.getElementById("mr-blood-type")?.value || "",
      height_cm: document.getElementById("mr-height")?.value || "",
      weight_kg: document.getElementById("mr-weight")?.value || "",
      date_of_birth: document.getElementById("mr-dob")?.value || "",
      gender: document.getElementById("mr-gender")?.value || "",
      allergies: document.getElementById("mr-allergies")?.value || "",
      chronic_diseases: document.getElementById("mr-chronic")?.value || "",
      current_medications:
        document.getElementById("mr-medications")?.value || "",
      previous_surgeries: document.getElementById("mr-surgeries")?.value || "",
      family_history: document.getElementById("mr-family")?.value || "",
      emergency_contact_name:
        document.getElementById("mr-emergency-name")?.value || "",
      emergency_contact_rel:
        document.getElementById("mr-emergency-rel")?.value || "",
      emergency_contact_phone:
        document.getElementById("mr-emergency-phone")?.value || "",
      medical_notes: document.getElementById("mr-notes")?.value || "",
    };

    const submitBtn = document.querySelector("#mr-edit-form [type='submit']");
    const originalText = submitBtn?.textContent || "Save Changes";
    setLoading(submitBtn, true, "Saving...");

    const result = await apiFetch(
      (getBasePath() + "api/medical/update-record.php"),
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(data),
      },
      "Failed to save medical record.",
    );

    if (result.data?.success) {
      showToast("Medical record updated successfully.", "success");
      this.closeEditModal();
      await this.init(this.patientId, this.role, this.containerId);
    } else {
      showToast(result.data?.message || "Failed to save.", "error");
    }

    setLoading(submitBtn, false, originalText);
  },

  /**
   * Open the Add Visit Note modal for a specific appointment.
   * @param {number} appointmentId
   * @param {string} doctorName
   * @param {string} apptDate
   */
  openVisitNoteModal(appointmentId, doctorName, apptDate) {
    const safeAppointmentId = safePositiveId(appointmentId);
    if (!safeAppointmentId || this.role !== "doctor" || this.canEdit !== true) return;
    // Create modal if it doesn't exist
    let modal = document.getElementById("vn-modal");
    if (!modal) {
      modal = document.createElement("div");
      modal.id = "vn-modal";
      modal.className = "modal-overlay";
      modal.innerHTML = `
        <div class="modal mr-visit-note-modal">
          <div class="flex-between mr-modal-header">
            <h3><i class="fas fa-notes-medical" aria-hidden="true"></i> Add Visit Note</h3>
            <button class="btn btn-outline btn-sm" type="button" data-mr-action="close-visit-note">Close</button>
          </div>
          <form id="vn-form" class="visit-note-form" novalidate>
            <input type="hidden" id="vn-appointment-id" />
            <div class="mr-visit-context">
              <p class="mr-visit-context-text">
                <strong>Appointment:</strong> <span id="vn-doctor-name"></span>
                &middot; <span id="vn-appt-date"></span>
              </p>
            </div>
            <div class="form-group">
              <label for="vn-diagnosis">Diagnosis *</label>
              <textarea id="vn-diagnosis" placeholder="What was diagnosed?" maxlength="2000" required></textarea>
            </div>
            <div class="form-group">
              <label for="vn-symptoms">Symptoms</label>
              <textarea id="vn-symptoms" placeholder="Reported symptoms..." maxlength="2000"></textarea>
            </div>
            <div class="form-group">
              <label for="vn-treatment">Treatment Plan</label>
              <textarea id="vn-treatment" placeholder="Describe the treatment plan, recommendations, lifestyle advice, or procedures performed. Medications should be issued through the Prescription System." maxlength="2000"></textarea>
            </div>
            <div class="form-group">
              <label for="vn-notes">Doctor's Notes</label>
              <textarea id="vn-notes" class="mr-textarea--large" placeholder="Additional notes..." maxlength="5000"></textarea>
            </div>
            <div class="mr-modal-actions">
              <button type="submit" class="btn btn-primary">Save Visit Note</button>
              <button type="button" class="btn btn-outline" data-mr-action="close-visit-note">Cancel</button>
            </div>
          </form>
        </div>
      `;
      document.body.appendChild(modal);

      document
        .getElementById("vn-form")
        .addEventListener("submit", async (e) => {
          e.preventDefault();
          await MedicalRecords.saveVisitNote();
        });
    }

    document.getElementById("vn-appointment-id").value = String(safeAppointmentId);
    document.getElementById("vn-doctor-name").textContent =
      typeof doctorName === "string" ? doctorName : "Doctor";
    document.getElementById("vn-appt-date").textContent =
      typeof apptDate === "string" ? apptDate : "";
    document.getElementById("vn-diagnosis").value = "";
    document.getElementById("vn-symptoms").value = "";
    document.getElementById("vn-treatment").value = "";
    document.getElementById("vn-notes").value = "";

    modal.classList.add("open");
  },

  /**
   * Close the Add Visit Note modal.
   */
  closeVisitNoteModal() {
    const modal = document.getElementById("vn-modal");
    if (modal) modal.classList.remove("open");
  },

  /**
   * Save a visit note.
   */
  async saveVisitNote() {
    const appointmentId = safePositiveId(document.getElementById("vn-appointment-id")?.value);
    const diagnosis =
      document.getElementById("vn-diagnosis")?.value.trim() || "";
    const symptoms = document.getElementById("vn-symptoms")?.value.trim() || "";
    const treatment =
      document.getElementById("vn-treatment")?.value.trim() || "";
    const doctorNotes = document.getElementById("vn-notes")?.value.trim() || "";

    if (!appointmentId || this.role !== "doctor" || this.canEdit !== true || !diagnosis) {
      showToast("Diagnosis is required.", "error");
      return;
    }

    const submitBtn = document.querySelector("#vn-form [type='submit']");
    const originalText = submitBtn?.textContent || "Save Visit Note";
    setLoading(submitBtn, true, "Saving...");

    const result = await apiFetch(
      (getBasePath() + "api/medical/add-visit-note.php"),
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          appointment_id: appointmentId,
          diagnosis,
          symptoms,
          treatment,
          doctor_notes: doctorNotes,
        }),
      },
      "Failed to save visit note.",
    );

    if (result.data?.success) {
      showToast("Visit note saved successfully.", "success");
      this.closeVisitNoteModal();
      await this.init(this.patientId, this.role, this.containerId);
    } else {
      showToast(result.data?.message || "Failed to save.", "error");
    }

    setLoading(submitBtn, false, originalText);
  },
};

// Expose to global scope
window.MedicalRecords = MedicalRecords;


