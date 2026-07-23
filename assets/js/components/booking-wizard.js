/*
 * HealthBridge — Shared Booking Wizard Component
 * Extracted from the legacy patient dashboard booking flow.
 * Provides a reusable booking modal with department, doctor, date,
 * and time slot selection for the patient appointments page.
 */

"use strict";

let appointmentDoctors = [];
let lastSlotRequestId = 0;
let _summaryLocked = false;
// Cache of slots booked in this client session to prevent double-booking UI collisions
const bookedSlots = new Set();

const BookingStateManager = {
  state: {
    department: "",
    doctor: "",
    doctorId: 0,
    doctorData: null,
    date: "",
    time: "",
    timeLabel: "",
    step: 1,
    dirty: false,
    completed: false,
    restoring: false,
    resetting: false,
  },

  update(updates) {
    Object.assign(this.state, updates);
  },

  reset() {
    this.state = {
      department: "",
      doctor: "",
      doctorId: 0,
      doctorData: null,
      date: "",
      time: "",
      timeLabel: "",
      step: 1,
      dirty: false,
      completed: false,
      restoring: false,
      resetting: false,
    };
  },

  setDirty(value) {
    this.state.dirty = value;
  },

  setRestoring(value) {
    this.state.restoring = value;
  },

  setResetting(value) {
    this.state.resetting = value;
  },

  setCompleted(value) {
    this.state.completed = value;
  },

  get department() {
    return this.state.department;
  },
  get doctor() {
    return this.state.doctor;
  },
  get doctorId() {
    return this.state.doctorId;
  },
  get doctorData() {
    return this.state.doctorData;
  },
  get date() {
    return this.state.date;
  },
  get time() {
    return this.state.time;
  },
  get timeLabel() {
    return this.state.timeLabel;
  },
  get step() {
    return this.state.step;
  },
  get dirty() {
    return this.state.dirty;
  },
  get restoring() {
    return this.state.restoring;
  },
  get resetting() {
    return this.state.resetting;
  },
  get completed() {
    return this.state.completed;
  },
};

const DraftManager = {
  STORAGE_KEY: "healthbridge_booking_draft",
  SAVE_DELAY: 300,
  saveTimer: null,

  save(state) {
    if (state.restoring || state.resetting || state.completed) {
      return;
    }

    if (sessionStorage.getItem("bookingCompleted") === "1") {
      return;
    }

    const draftData = {
      department: state.department || "",
      doctor: state.doctor || "",
      doctorId: state.doctorId || 0,
      date: state.date || "",
      time: state.time || "",
      timeLabel: state.timeLabel || "",
      step: state.step || 1,
    };

    clearTimeout(this.saveTimer);
    this.saveTimer = setTimeout(() => {
      try {
        localStorage.setItem(this.STORAGE_KEY, JSON.stringify(draftData));
      } catch (e) {
        console.error("Failed to save booking draft:", e);
      }
    }, this.SAVE_DELAY);
  },

  clear() {
    try {
      localStorage.removeItem(this.STORAGE_KEY);
      clearTimeout(this.saveTimer);
    } catch (e) {
      console.error("Failed to clear booking draft:", e);
    }
  },

  load() {
    try {
      const raw = localStorage.getItem(this.STORAGE_KEY);
      if (!raw) return null;
      return JSON.parse(raw);
    } catch (e) {
      console.error("Failed to load booking draft:", e);
      return null;
    }
  },
};

const LeaveProtectionManager = {
  isEnabled: false,

  enable() {
    if (this.isEnabled) return;
    window.onbeforeunload = function (e) {
      e.preventDefault();
      e.returnValue = "";
    };
    this.isEnabled = true;
  },

  disable() {
    window.onbeforeunload = null;
    this.isEnabled = false;
  },

  update(state) {
    if (state.dirty && !state.completed && !state.resetting) {
      this.enable();
    } else {
      this.disable();
    }
  },
};

const BookingWizard = {
  initialized: false,
  isOpen: false,
  onSuccess: null,
  onClose: null,
  /** @type {boolean} When true, locks department and doctor for reschedule flow */
  rescheduleMode: false,
  /** @type {Object|null} The appointment being rescheduled */
  rescheduleAppt: null,

  async init(options = {}) {
    if (this.initialized) return;
    this.onSuccess = options.onSuccess || null;
    this.onClose = options.onClose || null;

    await this.loadDoctors();
    if (typeof loadDepartmentsDropdown === "function") {
      await loadDepartmentsDropdown("appt-department", true);
      this.renderDepartmentCards();
    }

    this.attachEventListeners();
    this.restoreDraft();
    this.initialized = true;
  },

  async open() {
    const modal = document.getElementById("book-appointment-modal");
    if (!modal) return;

    if (!this.initialized) {
      await this.init();
    }

    modal.style.display = "flex";
    modal.classList.add("open");
    document.body.classList.add("modal-open");
    this.isOpen = true;
    this.resetBookingWizard();

    // Ensure hidden patient name field is populated so form validation passes
    try {
      const patientNameInput = document.getElementById("appt-patient");
      const user = typeof getUser === "function" ? getUser() : null;
      if (patientNameInput && user && user.name) {
        patientNameInput.value = user.name;
      }
    } catch (e) {
      console.warn("Failed to set appt-patient value:", e);
    }
  },

  /**
   * Open the wizard in reschedule mode.
   * Locks department and doctor, allows only date/time/reason changes.
   * @param {Object} appt - The appointment to reschedule
   */
  async openReschedule(appt) {
    if (!appt) return;
    
    this.rescheduleMode = true;
    this.rescheduleAppt = appt;
    
    await this.open();
    
    // Pre-select department
    const departmentInput = document.getElementById("appt-department");
    if (departmentInput && appt.department) {
      // Wait for departments to load
      const trySetDept = () => {
        const options = [...departmentInput.options];
        const match = options.find(o => o.textContent.trim() === appt.department || o.value === String(appt.department_id));
        if (match) {
          departmentInput.value = match.value;
          departmentInput.dispatchEvent(new Event("change", { bubbles: true }));
          
          // After department is set, pre-select doctor
          setTimeout(() => {
            const doctorInput = document.getElementById("appt-doctor");
            if (doctorInput && appt.doctor) {
              const docOptions = [...doctorInput.options];
              const docMatch = docOptions.find(o => o.textContent.trim() === appt.doctor);
              if (docMatch) {
                doctorInput.value = docMatch.value;
                doctorInput.dispatchEvent(new Event("change", { bubbles: true }));
                
                // Lock the fields
                this.lockRescheduleFields();
              } else {
                setTimeout(trySetDoc, 300);
              }
            }
          }, 300);
        } else {
          setTimeout(trySetDept, 500);
        }
      };
      const trySetDoc = () => {
        const doctorInput = document.getElementById("appt-doctor");
        if (doctorInput && appt.doctor) {
          const docOptions = [...doctorInput.options];
          const docMatch = docOptions.find(o => o.textContent.trim() === appt.doctor);
          if (docMatch) {
            doctorInput.value = docMatch.value;
            doctorInput.dispatchEvent(new Event("change", { bubbles: true }));
            this.lockRescheduleFields();
          } else {
            setTimeout(trySetDoc, 500);
          }
        }
      };
      setTimeout(trySetDept, 500);
    }
    
    // Update modal title to indicate reschedule mode
    this.updateRescheduleUI();
  },

  /**
   * Lock department and doctor fields in reschedule mode.
   */
  lockRescheduleFields() {
    const departmentInput = document.getElementById("appt-department");
    const doctorInput = document.getElementById("appt-doctor");
    const departmentCards = document.getElementById("department-card-grid");
    const doctorCards = document.getElementById("doctor-card-grid");
    
    if (departmentInput) departmentInput.disabled = true;
    if (doctorInput) doctorInput.disabled = true;
    
    // Disable card clicks
    if (departmentCards) {
      departmentCards.querySelectorAll(".selection-card").forEach(c => {
        c.style.pointerEvents = "none";
        c.style.opacity = "0.6";
      });
    }
    if (doctorCards) {
      doctorCards.querySelectorAll(".doctor-card").forEach(c => {
        c.style.pointerEvents = "none";
        c.style.opacity = "0.6";
      });
    }
  },

  /**
   * Unlock reschedule fields when closing.
   */
  unlockRescheduleFields() {
    const departmentInput = document.getElementById("appt-department");
    const doctorInput = document.getElementById("appt-doctor");
    const departmentCards = document.getElementById("department-card-grid");
    const doctorCards = document.getElementById("doctor-card-grid");
    
    if (departmentInput) departmentInput.disabled = false;
    if (doctorInput) doctorInput.disabled = false;
    
    if (departmentCards) {
      departmentCards.querySelectorAll(".selection-card").forEach(c => {
        c.style.pointerEvents = "";
        c.style.opacity = "";
      });
    }
    if (doctorCards) {
      doctorCards.querySelectorAll(".doctor-card").forEach(c => {
        c.style.pointerEvents = "";
        c.style.opacity = "";
      });
    }
  },

  /**
   * Update UI to indicate reschedule mode.
   */
  updateRescheduleUI() {
    const modalTitle = document.querySelector(".modal-header h3");
    const modalSubtitle = document.querySelector(".modal-subtitle");
    
    if (modalTitle) {
      modalTitle.innerHTML = '<i class="fas fa-calendar-days" aria-hidden="true"></i> Reschedule Appointment';
    }
    if (modalSubtitle) {
      modalSubtitle.textContent = 'Select a new date and time for your appointment. Doctor and department cannot be changed.';
    }
    
    // Update step labels
    const stepLabels = document.querySelectorAll(".step-label");
    if (stepLabels.length >= 2) {
      stepLabels[0].textContent = "Department (locked)";
      stepLabels[1].textContent = "Doctor (locked)";
    }
    
    // Add reason field to step 4
    const reviewCard = document.querySelector(".review-card");
    if (reviewCard && !document.getElementById("review-reschedule-reason")) {
      const reasonRow = document.createElement("div");
      reasonRow.className = "form-group";
      reasonRow.style.marginTop = "var(--s4)";
      reasonRow.innerHTML = `
        <label for="reschedule-reason">Reason for Rescheduling (Optional)</label>
        <textarea id="reschedule-reason" class="form-input" placeholder="Tell the doctor why you need to reschedule..." rows="3"></textarea>
      `;
      reviewCard.parentNode.insertBefore(reasonRow, reviewCard.nextSibling);
    }
  },

  /**
   * Restore normal booking UI.
   */
  restoreBookingUI() {
    const modalTitle = document.querySelector(".modal-header h3");
    const modalSubtitle = document.querySelector(".modal-subtitle");
    
    if (modalTitle) {
      modalTitle.innerHTML = '<i class="fas fa-calendar-days" aria-hidden="true"></i> Book Appointment';
    }
    if (modalSubtitle) {
      modalSubtitle.textContent = 'Quickly schedule your next visit with a specialist in four simple steps.';
    }
    
    const stepLabels = document.querySelectorAll(".step-label");
    if (stepLabels.length >= 2) {
      stepLabels[0].textContent = "Department";
      stepLabels[1].textContent = "Doctor";
    }
    
    // Remove reason field
    const reasonField = document.getElementById("reschedule-reason");
    if (reasonField) {
      reasonField.closest(".form-group")?.remove();
    }
    
    this.unlockRescheduleFields();
    this.rescheduleMode = false;
    this.rescheduleAppt = null;
  },

  close() {
    const modal = document.getElementById("book-appointment-modal");
    if (!modal) return;
    modal.classList.remove("open");
    modal.style.display = "none";
    document.body.classList.remove("modal-open");
    this.isOpen = false;
    
    // Restore normal booking UI if in reschedule mode
    if (this.rescheduleMode) {
      this.restoreBookingUI();
    }
    
    if (typeof this.onClose === "function") {
      this.onClose();
    }
  },

  toggleModal() {
    const modal = document.getElementById("book-appointment-modal");
    if (!modal) return;
    if (modal.classList.contains("open")) {
      this.close();
    } else {
      this.open();
    }
  },

  async openWithAppointment(appt) {
    if (!appt) return;
    await this.open();

    const departmentInput = document.getElementById("appt-department");
    const doctorInput = document.getElementById("appt-doctor");
    const dateInput = document.getElementById("appt-date");
    const notesInput = document.getElementById("appt-notes");

    if (departmentInput) {
      if (appt.department) {
        departmentInput.value = appt.department;
      }
      if (!departmentInput.value && appt.department_id) {
        const matchOption = [...departmentInput.options].find(
          (option) =>
            option.textContent.trim() === appt.department ||
            option.value === String(appt.department_id),
        );
        if (matchOption) {
          departmentInput.value = matchOption.value;
        }
      }
      departmentInput.dispatchEvent(new Event("change"));
    }

    if (doctorInput) {
      if (appt.doctor) {
        doctorInput.value = appt.doctor;
      }
      doctorInput.dispatchEvent(new Event("change"));
    }

    if (dateInput) {
      dateInput.value = "";
    }
    if (notesInput) {
      notesInput.value = "";
    }

    this.hideSlotSummary();
    this.updateSummary();
  },

  async loadDoctors() {
    try {
      const response = await fetch(getBasePath() + "api/doctors/get.php");
      const result = await response.json();
      appointmentDoctors =
        Array.isArray(result.doctors) && result.doctors.length
          ? result.doctors
          : [];
      this.renderDoctorOptions();
      this.renderDoctorCards();
    } catch (error) {
      console.error("Failed to load doctors:", error);
      appointmentDoctors = [];
      this.renderDoctorOptions();
      this.renderDoctorCards();
    }
  },

  attachEventListeners() {
    const departmentInput = document.getElementById("appt-department");
    const doctorInput = document.getElementById("appt-doctor");
    const departmentCards = document.getElementById("department-card-grid");
    const doctorCards = document.getElementById("doctor-card-grid");
    const dateInput = document.getElementById("appt-date");
    const form = document.getElementById("booking-form");
    const summarySubmitBtn = document.getElementById(
      "booking-summary-submit-btn",
    );

    if (departmentInput) {
      departmentInput.addEventListener("change", () =>
        this.handleDepartmentChange(),
      );
    }

    if (departmentCards) {
      departmentCards.addEventListener("click", (event) => {
        const card = event.target.closest(".selection-card");
        if (!card || card.disabled) return;
        const department = card.dataset.department;
        if (department) {
          this.selectDepartment(department);
        }
      });
    }

    if (doctorInput) {
      doctorInput.addEventListener("change", () => this.handleDoctorChange());
    }

    if (doctorCards) {
      doctorCards.addEventListener("click", (event) => {
        const card = event.target.closest(".doctor-card");
        if (!card || card.disabled) return;
        const doctorName = card.dataset.doctor;
        if (doctorName) {
          this.selectDoctor(doctorName);
        }
      });
    }

    if (dateInput) {
      dateInput.addEventListener("change", () => this.handleDateChange());
      dateInput.min = new Date().toISOString().split("T")[0];
    }

    if (form) {
      form.addEventListener("submit", (e) => this.handleFormSubmit(e));
    }

    if (summarySubmitBtn) {
      summarySubmitBtn.addEventListener("click", () => {
        const formEl = document.getElementById("booking-form");
        if (formEl) formEl.requestSubmit();
      });
    }
  },

  handleDepartmentChange() {
    const departmentInput = document.getElementById("appt-department");
    const doctorInput = document.getElementById("appt-doctor");

    if (!departmentInput) return;

    const val = departmentInput.value;
    BookingStateManager.update({ department: val });
    this.renderDepartmentCards();
    this.renderDoctorOptions();
    this.clearDateAndSlots();

    const btn = document.getElementById("step-1-next");
    if (btn) btn.disabled = !val;

    this.updateSummary();
    if (!BookingStateManager.restoring && !BookingStateManager.resetting) {
      _summaryLocked = false;
      BookingStateManager.setCompleted(false);
      sessionStorage.removeItem("bookingCompleted");
      BookingStateManager.setDirty(true);
      LeaveProtectionManager.update(BookingStateManager.state);
      DraftManager.save(BookingStateManager.state);
    }
  },

  handleDoctorChange() {
    const doctorInput = document.getElementById("appt-doctor");
    const departmentInput = document.getElementById("appt-department");

    if (!doctorInput) return;

    const selectedName = doctorInput.value || "";
    const selected = appointmentDoctors.find((d) => d.name === selectedName);

    if (selectedName && selected) {
      BookingStateManager.update({
        doctor: selectedName,
        doctorId: selected.user_id || selected.id || 0,
        doctorData: selected,
      });
      this.renderDoctorPreview(selected);
      this.renderDoctorCards();
      if (selected.specialty && departmentInput) {
        departmentInput.value = selected.specialty;
        BookingStateManager.update({ department: selected.specialty });
        this.renderDepartmentCards();
      }
      const btn = document.getElementById("step-2-next");
      if (btn) btn.disabled = false;
      this.clearDateAndSlots();
    } else {
      BookingStateManager.update({
        doctor: "",
        doctorId: 0,
        doctorData: null,
      });
      this.clearDoctorPreview();
      this.renderDoctorCards();
      const btn = document.getElementById("step-2-next");
      if (btn) btn.disabled = true;
      this.clearDateAndSlots();
    }

    this.updateSummary();
    if (!BookingStateManager.restoring && !BookingStateManager.resetting) {
      _summaryLocked = false;
      BookingStateManager.setDirty(true);
      LeaveProtectionManager.update(BookingStateManager.state);
      DraftManager.save(BookingStateManager.state);
    }
  },

  handleDateChange() {
    const dateInput = document.getElementById("appt-date");
    if (!dateInput) return;

    const val = dateInput.value;
    BookingStateManager.update({ date: val });
    if (val && BookingStateManager.doctorId) {
      const btn = document.getElementById("step-3-next");
      if (btn) btn.disabled = false;
      this.loadSlots();
    } else {
      const btn = document.getElementById("step-3-next");
      if (btn) btn.disabled = true;
      this.hideSlotUI();
    }

    this.updateSummary();
    if (!BookingStateManager.restoring && !BookingStateManager.resetting) {
      _summaryLocked = false;
      BookingStateManager.setDirty(true);
      LeaveProtectionManager.update(BookingStateManager.state);
      DraftManager.save(BookingStateManager.state);
    }
  },

  async handleFormSubmit(e) {
    e.preventDefault();

    const departmentInput = document.getElementById("appt-department");
    const doctorInput = document.getElementById("appt-doctor");
    const dateInput = document.getElementById("appt-date");
    const timeInput = document.getElementById("appt-time");
    const patientNameInput = document.getElementById("appt-patient");
    const notesInput = document.getElementById("appt-notes");

    const data = {
      department:
        BookingStateManager.department || departmentInput?.value || "",
      doctor: BookingStateManager.doctor || doctorInput?.value || "",
      date: BookingStateManager.date || dateInput?.value || "",
      time: BookingStateManager.time || timeInput?.value || "",
      patientName: patientNameInput?.value || (typeof getUser === "function" ? getUser()?.name : "") || "",
      notes: notesInput?.value || "",
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

    const submitBtn = document.getElementById("booking-submit-btn");
    const originalText = submitBtn?.textContent || "Confirm Booking";
    setLoading(submitBtn, true, "Booking...");

    let endpoint, bodyData;

    if (this.rescheduleMode && this.rescheduleAppt) {
      // Reschedule mode: submit to request-reschedule.php
      const reasonInput = document.getElementById("reschedule-reason");
      const reason = reasonInput?.value?.trim() || "";
      
      endpoint = getBasePath() + "api/appointments/request-reschedule.php";
      bodyData = {
        appointment_id: this.rescheduleAppt.id,
        new_date: data.date,
        new_time: data.time,
        reason: reason,
      };
    } else {
      // Normal booking mode
      endpoint = getBasePath() + "api/appointments/book.php";
      bodyData = data;
    }

    const result = await apiFetch(
      endpoint,
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(bodyData),
      },
      "Service unavailable.",
    );

    if (result.data?.success) {
      BookingStateManager.setResetting(true);
      clearTimeout(DraftManager.saveTimer);
      DraftManager.clear();
      LeaveProtectionManager.disable();
      _summaryLocked = true;

      // Show success confirmation screen
      const successEl = document.getElementById("booking-success");
      const refNumber = document.getElementById("booking-ref-number");
      if (refNumber && result.data?.appointment?.id) {
        refNumber.textContent = `#${result.data.appointment.id}`;
      }
      
      // Update success message for reschedule
      if (this.rescheduleMode) {
        const successTitle = successEl?.querySelector("h3");
        const successDesc = successEl?.querySelector("p");
        if (successTitle) successTitle.textContent = "Reschedule Request Submitted!";
        if (successDesc) successDesc.textContent = "Your reschedule request has been sent to the doctor for approval.";
      }
      
      // Hide all wizard steps, show success
      document.querySelectorAll(".booking-step").forEach((el) => el.classList.remove("active"));
      document.querySelectorAll(".booking-step-indicator").forEach((ind) => {
        ind.classList.remove("active", "completed", "locked");
      });
      if (successEl) successEl.classList.add("visible");

      // Mark the booked slot in the client cache so it's no longer offered
      try {
        const docId = BookingStateManager.doctorId || (result.data?.appointment?.doctor_id || 0);
        const apptDate = data.date;
        const apptTime = data.time;
        if (docId && apptDate && apptTime) {
          bookedSlots.add(`${docId}|${apptDate}|${apptTime}`);
        }
      } catch (e) {
        console.warn('Failed to cache booked slot:', e);
      }

      // The onSuccess callback will trigger AppointmentsPage.load() which fetches
      // fresh data from the API and calls renderAppointments(). No optimistic UI
      // insertion here to avoid double rendering of the same appointment.

      // Refresh slots UI so the just-booked slot is no longer selectable
      try {
        this.loadSlots();
      } catch (e) {}

      if (typeof this.onSuccess === "function") {
        this.onSuccess();
      }
    } else {
      showToast(result.data?.message || "Booking failed.", "error");
    }
    setLoading(submitBtn, false, originalText);
  },

  renderDoctorOptions() {
    const doctorInput = document.getElementById("appt-doctor");
    const departmentInput = document.getElementById("appt-department");
    if (!doctorInput) return;

    const department = departmentInput?.value || "";
    const doctorsToShow = department
      ? appointmentDoctors.filter((d) => {
          const deptName = d.department_name || d.specialty || "";
          return deptName === department;
        })
      : appointmentDoctors;

    const currentDoctor = doctorInput.value;
    doctorInput.innerHTML = '<option value="">Select a doctor</option>';
    doctorsToShow.forEach((d) => {
      const label = `${d.name}${d.available == 0 ? " (Not available)" : ""}`;
      const option = new Option(label, d.name);
      option.disabled = d.available == 0;
      if (d.id) option.value = d.name;
      option.dataset.specialty = d.specialty;
      doctorInput.add(option);
    });

    if (
      currentDoctor &&
      [...doctorInput.options].some((o) => o.value === currentDoctor)
    ) {
      doctorInput.value = currentDoctor;
    }

    BookingStateManager.update({
      doctor: "",
      doctorId: 0,
      doctorData: null,
    });
    this.clearDoctorPreview();
    this.renderDoctorCards();
    this.clearDateAndSlots();
  },

  selectDepartment(department) {
    const departmentInput = document.getElementById("appt-department");
    if (!departmentInput) return;
    departmentInput.value = department;
    departmentInput.dispatchEvent(new Event("change", { bubbles: true }));
  },

  selectDoctor(doctorName) {
    const doctorInput = document.getElementById("appt-doctor");
    if (!doctorInput) return;
    doctorInput.value = doctorName;
    doctorInput.dispatchEvent(new Event("change", { bubbles: true }));
  },

  getDepartmentIcon(department) {
    const icons = {
      cardiology: "fa-heart-pulse",
      dermatology: "fa-hand-sparkles",
      neurology: "fa-brain",
      pediatrics: "fa-baby",
      orthopedics: "fa-bone",
      dentistry: "fa-tooth",
      ophthalmology: "fa-eye",
      gynecology: "fa-venus",
      "general practice": "fa-stethoscope",
    };
    const key = String(department || "").toLowerCase();
    return icons[key] || "fa-hospital";
  },

  getDepartmentDescription(department) {
    if (!department) return "Choose the right specialist for your care.";
    return `Expert care for ${department.toLowerCase()}.`;
  },

  renderDepartmentCards() {
    const departmentInput = document.getElementById("appt-department");
    const cardGrid = document.getElementById("department-card-grid");
    if (!departmentInput || !cardGrid) return;

    const selectedDepartment =
      departmentInput.value || BookingStateManager.department || "";
    const options = [...departmentInput.options].filter(
      (option) => option.value,
    );
    if (!options.length) {
      cardGrid.innerHTML = `<div class="empty-state">No departments available.</div>`;
      return;
    }

    cardGrid.innerHTML = options
      .map((option) => {
        const dept = option.value;
        const isActive = dept === selectedDepartment;
        const iconClass = this.getDepartmentIcon(dept);
        return `
          <button
            type="button"
            class="selection-card${isActive ? " selected" : ""}"
            data-department="${escapeHTML(dept)}"
            aria-pressed="${isActive ? "true" : "false"}"
          >
            <div class="selection-card-icon"><i class="fas ${escapeHTML(iconClass)}" aria-hidden="true"></i></div>
            <div class="selection-card-body">
              <span class="selection-card-title">${escapeHTML(dept)}</span>
              <span class="selection-card-desc">${escapeHTML(this.getDepartmentDescription(dept))}</span>
            </div>
          </button>`;
      })
      .join("");
  },

  renderDoctorCards() {
    const departmentInput = document.getElementById("appt-department");
    const doctorInput = document.getElementById("appt-doctor");
    const cardGrid = document.getElementById("doctor-card-grid");
    if (!doctorInput || !cardGrid) return;

    const department = departmentInput?.value || "";
    const doctorsToShow = department
      ? appointmentDoctors.filter((d) => {
          const deptName = d.department_name || d.specialty || "";
          return deptName === department;
        })
      : appointmentDoctors;

    if (!doctorsToShow.length) {
      cardGrid.innerHTML = `<div class="empty-state">No doctors found for this department.</div>`;
      return;
    }

    const selectedDoctor =
      BookingStateManager.doctor || doctorInput.value || "";
    cardGrid.innerHTML = doctorsToShow
      .map((doctor) => {
        const isSelected = doctor.name === selectedDoctor;
        const available = doctor.available != 0;
        const rating = doctor.rating || "4.5";
        const experience = doctor.experience
          ? `${escapeHTML(String(doctor.experience))} yrs`
          : "";
        const nextAvailable =
          doctor.next_available || doctor.available_date || "";
        return `
          <button
            type="button"
            class="doctor-card${isSelected ? " selected" : ""}${available ? "" : " disabled"}"
            data-doctor="${escapeHTML(doctor.name)}"
            ${available ? "" : "disabled"}
            aria-pressed="${isSelected ? "true" : "false"}"
          >
            <div class="doctor-card-avatar">${escapeHTML(
              doctor.name
                ? doctor.name
                    .split(" ")
                    .map((part) => part[0])
                    .join("")
                    .substring(0, 2)
                    .toUpperCase()
                : "DR",
            )}</div>
            <div class="doctor-card-body">
              <div class="doctor-card-header">
                <div>
                  <h4>${escapeHTML(doctor.name || "Doctor")}</h4>
                  <p>${escapeHTML(doctor.specialty || doctor.department_name || "General Practice")}</p>
                </div>
                <span class="doctor-card-badge ${available ? "available" : "unavailable"}">${available ? "Available" : "Unavailable"}</span>
              </div>
              <div class="doctor-card-meta">
                <span><i class="fas fa-star" aria-hidden="true"></i> ${escapeHTML(String(rating))}</span>
                ${experience ? `<span><i class="fas fa-briefcase" aria-hidden="true"></i> ${experience}</span>` : ""}
                ${nextAvailable ? `<span><i class="fas fa-calendar-check" aria-hidden="true"></i> ${escapeHTML(nextAvailable)}</span>` : ""}
              </div>
            </div>
          </button>`;
      })
      .join("");
  },

  clearDoctorPreview() {
    const container = document.getElementById("doctor-preview-container");
    if (container) container.innerHTML = "";
  },

  renderDoctorPreview(doctor) {
    const container = document.getElementById("doctor-preview-container");
    if (!container || !doctor) return;

    const initials = doctor.name
      ? doctor.name
          .split(" ")
          .map((n) => n[0])
          .join("")
          .substring(0, 2)
          .toUpperCase()
      : "DR";

    const rating = doctor.rating || "4.5";
    const isAvail = doctor.available != 0;
    const availText = isAvail ? "Available" : "Not Available";
    const availClass = isAvail ? "available" : "unavailable";
    const workingDays = doctor.working_days
      ? Array.isArray(doctor.working_days)
        ? doctor.working_days.join(", ")
        : doctor.working_days
      : "Mon - Fri";
    const duration = doctor.duration ? `${doctor.duration} min` : "30 min";

    container.innerHTML = `
      <div class="doctor-preview-card">
        <div class="doctor-preview-avatar">${escapeHTML(initials)}</div>
        <div class="doctor-preview-body">
          <h4 class="doctor-preview-name">${escapeHTML(doctor.name || "Doctor")}</h4>
          <p class="doctor-preview-specialty">${escapeHTML(doctor.specialty || "General Practice")}</p>
          <div class="doctor-preview-meta">
            <span><i class="fas fa-star" aria-hidden="true"></i> ${escapeHTML(String(rating))} Rating</span>
            <span><i class="fas fa-clock" aria-hidden="true"></i> ${escapeHTML(duration)}</span>
            <span><i class="fas fa-calendar-week" aria-hidden="true"></i> ${escapeHTML(workingDays)}</span>
          </div>
          <span class="doctor-preview-badge ${escapeHTML(availClass)}">
            <i class="fas ${isAvail ? "fa-check-circle" : "fa-times-circle"}" aria-hidden="true"></i>
            ${escapeHTML(availText)}
          </span>
        </div>
      </div>`;
  },

  clearDateAndSlots() {
    const dateInput = document.getElementById("appt-date");
    const timeInput = document.getElementById("appt-time");
    if (dateInput) dateInput.value = "";
    if (timeInput) timeInput.value = "";
    BookingStateManager.update({
      date: "",
      time: "",
      timeLabel: "",
    });
    this.hideSlotSummary();
    this.hideSlotUI();
    this.updateSummary();
  },

  hideSlotUI() {
    const slotContainer = document.getElementById("slot-picker-container");
    if (!slotContainer) return;

    const durationBar = document.getElementById("slot-duration-bar");
    if (durationBar) durationBar.style.display = "none";
    const tabs = document.getElementById("slot-tabs");
    if (tabs) tabs.style.display = "none";
    const scrollArea = document.getElementById("slot-scroll-area");
    if (scrollArea) scrollArea.style.display = "none";
    slotContainer.innerHTML =
      '<p class="slot-placeholder" style="color:var(--text-muted);font-size:0.85rem">Select a doctor and date to see available slots.</p>';
  },

  async ensureDepartmentsLoaded() {
    const departmentInput = document.getElementById("appt-department");
    if (!departmentInput) return;
    if (
      departmentInput.options.length <= 1 &&
      typeof loadDepartmentsDropdown === "function"
    ) {
      await loadDepartmentsDropdown("appt-department", true);
    }
  },

  async restoreDraft() {
    const draft = DraftManager.load();
    if (!draft) return;

    BookingStateManager.setRestoring(true);
    const departmentInput = document.getElementById("appt-department");
    const doctorInput = document.getElementById("appt-doctor");
    const dateInput = document.getElementById("appt-date");
    const notesInput = document.getElementById("appt-notes");

    if (departmentInput && draft.department) {
      departmentInput.value = draft.department;
    }
    this.renderDepartmentCards();
    this.renderDoctorOptions();

    if (doctorInput && draft.doctor) {
      doctorInput.value = draft.doctor;
      this.handleDoctorChange();
    }

    if (dateInput && draft.date) {
      dateInput.value = draft.date;
    }

    if (notesInput && draft.notes) {
      notesInput.value = draft.notes;
    }

    BookingStateManager.setRestoring(false);
  },

  async loadSlots() {
    const doctorId = BookingStateManager.doctorId;
    const date = BookingStateManager.date;
    const slotContainer = document.getElementById("slot-picker-container");
    const slotMessage = document.getElementById("slot-picker-message");
    if (!slotContainer || !slotMessage) return;

    if (!doctorId || !date) {
      this.hideSlotUI();
      return;
    }

    const requestId = ++lastSlotRequestId;
    slotMessage.textContent = "Loading available slots...";

    try {
      const response = await fetch(
        getBasePath() + "api/appointments/get-available-slots.php",
        {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ doctor_id: doctorId, date }),
        },
      );
      const result = await response.json();

      let slots = Array.isArray(result.slots) ? result.slots : [];
      // Filter out slots that have been booked already in this session
      slots = slots.filter((s) => !bookedSlots.has(`${doctorId}|${date}|${s.time}`));

      if (!slots.length) {
        const message =
          result.message || "No appointment slots are available for this date.";
        this.renderSlots([], message, requestId);
        slotMessage.textContent =
          result.message ||
          "No available slots for the selected doctor on this date.";
        return;
      }

      this.renderSlots(slots, "", requestId);
      slotMessage.textContent = "Select one of the available time slots.";
    } catch (error) {
      console.error("Failed to load appointment slots:", error);
      this.renderSlots(
        [],
        "Unable to load slots. Please try again.",
        requestId,
      );
      slotMessage.textContent =
        "Unable to load available slots. Please try again later.";
    }
  },

  renderSlots(slots, message, requestId) {
    const slotContainer = document.getElementById("slot-picker-container");
    const slotTabs = document.getElementById("slot-tabs");
    const slotScrollArea = document.getElementById("slot-scroll-area");
    if (!slotContainer) return;

    const groups = this.groupSlotsByPeriod(slots);

    let html = "";
    html += `<div class="slot-tabs" id="slot-tabs">`;
    html += `<button class="slot-tab active" id="slot-tab-morning" type="button" onclick="switchSlotTab('morning')" aria-selected="true">`;
    html += `<span>Morning</span><span class="slot-tab-count">${groups.Morning.length}</span>`;
    html += `</button>`;
    html += `<button class="slot-tab" id="slot-tab-afternoon" type="button" onclick="switchSlotTab('afternoon')" aria-selected="false">`;
    html += `<span>Afternoon</span><span class="slot-tab-count">${groups.Afternoon.length}</span>`;
    html += `</button>`;
    html += `<button class="slot-tab" id="slot-tab-evening" type="button" onclick="switchSlotTab('evening')" aria-selected="false">`;
    html += `<span>Evening</span><span class="slot-tab-count">${groups.Evening.length}</span>`;
    html += `</button>`;
    html += `</div>`;
    html += `<div class="slot-scroll-area" id="slot-scroll-area">`;

    const periods = [
      { id: "morning", name: "Morning" },
      { id: "afternoon", name: "Afternoon" },
      { id: "evening", name: "Evening" },
    ];

    periods.forEach((period, index) => {
      const periodSlots = groups[period.name] || [];
      html += `<div class="slot-panel${index === 0 ? " active" : ""}" id="slot-panel-${period.id}" role="tabpanel">`;
      if (periodSlots.length === 0) {
        html += `
          <div class="slot-empty-card">
            <div class="slot-empty-icon"><i class="fas fa-calendar" aria-hidden="true"></i></div>
            <h4 class="slot-empty-title">No Available Slots</h4>
            <p class="slot-empty-desc">${escapeHTML(message || "No appointment slots are available for this time period.")}</p>
          </div>`;
      } else {
        html += `<div class="slot-grid" role="radiogroup" aria-label="${escapeHTML(period.name)} slots">`;
        periodSlots.forEach((slot) => {
          html += `
            <button
              type="button"
              class="slot-chip"
              data-time="${escapeHTML(slot.time)}"
              data-label="${escapeHTML(slot.label)}"
              onclick="selectSlot(this)"
              role="radio"
              aria-checked="false"
              aria-label="Select ${escapeHTML(slot.label)}"
              tabindex="0"
            >
              <span class="slot-check" aria-hidden="true"><i class="fas fa-check"></i></span>
              <span class="slot-label">${escapeHTML(slot.label)}</span>
            </button>`;
        });
        html += `</div>`;
      }
      html += `</div>`;
    });

    html += `</div>`;

    if (requestId !== lastSlotRequestId) return;
    slotContainer.innerHTML = html;

    const firstActive =
      groups.Morning.length > 0
        ? "morning"
        : groups.Afternoon.length > 0
          ? "afternoon"
          : "evening";
    switchSlotTab(firstActive);
    this.hideSlotSummary();

    slotContainer.querySelectorAll(".slot-chip").forEach((chip) => {
      chip.addEventListener("keydown", (e) => {
        if (e.key === "Enter" || e.key === " ") {
          e.preventDefault();
          chip.click();
        }
      });
    });

    this.disableSubmitButtons();
  },

  groupSlotsByPeriod(slots) {
    const groups = { Morning: [], Afternoon: [], Evening: [] };
    slots.forEach((slot) => {
      const [hour] = (slot.time || "").split(":").map(Number);
      if (hour >= 17) {
        groups.Evening.push(slot);
      } else if (hour >= 12) {
        groups.Afternoon.push(slot);
      } else {
        groups.Morning.push(slot);
      }
    });
    return groups;
  },

  resetBookingWizard() {
    BookingStateManager.reset();
    // Hide success screen if visible
    const successEl = document.getElementById("booking-success");
    if (successEl) successEl.classList.remove("visible");
    document
      .querySelectorAll(".booking-step")
      .forEach((el) => el.classList.remove("active"));
    const step1 = document.getElementById("booking-step-1");
    if (step1) step1.classList.add("active");
    document.querySelectorAll(".booking-step-indicator").forEach((ind) => {
      const s = parseInt(ind.dataset.step, 10);
      ind.classList.remove("completed", "active", "locked");
      if (s === 1) ind.classList.add("active");
      else ind.classList.add("locked");
    });
    this.clearDoctorPreview();
    this.hideSlotUI();
    this.hideSlotSummary();
    this.disableSubmitButtons();
    const step1Next = document.getElementById("step-1-next");
    if (step1Next) step1Next.disabled = true;
    const step2Next = document.getElementById("step-2-next");
    if (step2Next) step2Next.disabled = true;
    const step3Next = document.getElementById("step-3-next");
    if (step3Next) step3Next.disabled = true;
    this.updateSummary();
    const dateInput = document.getElementById("appt-date");
    if (dateInput) dateInput.min = new Date().toISOString().split("T")[0];
    this.updateSummary();
    document.querySelectorAll(".slot-chip.selected").forEach((c) => {
      c.classList.remove("selected");
      c.setAttribute("aria-checked", "false");
    });
  },

  goToStep(step) {
    if (step < 1 || step > 4) return;
    document
      .querySelectorAll(".booking-step")
      .forEach((el) => el.classList.remove("active"));
    const targetStep = document.getElementById(`booking-step-${step}`);
    if (targetStep) targetStep.classList.add("active");

    document
      .querySelectorAll(".booking-step-indicator")
      .forEach((indicator) => {
        const s = parseInt(indicator.dataset.step, 10);
        indicator.classList.remove("active", "completed", "locked");
        if (s === step) {
          indicator.classList.add("active");
        } else if (s < step) {
          indicator.classList.add("completed");
        } else {
          indicator.classList.add("locked");
        }
      });

    BookingStateManager.update({ step });
    const bookingWizard = document.querySelector(".booking-wizard");
    if (bookingWizard) {
      setTimeout(() => {
        bookingWizard.scrollIntoView({ behavior: "smooth", block: "start" });
      }, 50);
    }

    if (!BookingStateManager.restoring && !BookingStateManager.resetting) {
      DraftManager.save(BookingStateManager.state);
    }
  },

  selectSlot(btn) {
    document.querySelectorAll(".slot-chip.selected").forEach((c) => {
      c.classList.remove("selected");
      c.setAttribute("aria-checked", "false");
    });

    btn.classList.add("selected");
    btn.setAttribute("aria-checked", "true");

    const timeInput = document.getElementById("appt-time");
    if (timeInput) {
      timeInput.value = btn.dataset.time;
    }

    BookingStateManager.update({
      time: btn.dataset.time,
      timeLabel:
        btn.dataset.label ||
        btn.querySelector(".slot-label")?.textContent ||
        "",
    });

    this.updateSlotSummary(btn);
    this.enableSubmitButtons();
    this.updateSummary();

    if (!BookingStateManager.restoring && !BookingStateManager.resetting) {
      _summaryLocked = false;
      BookingStateManager.setDirty(true);
      LeaveProtectionManager.update(BookingStateManager.state);
      DraftManager.save(BookingStateManager.state);
    }
  },

  updateSlotSummary(btn) {
    const wrapper = document.getElementById("slot-summary-wrapper");
    if (!wrapper) return;

    const doctorName =
      BookingStateManager.doctor ||
      document.getElementById("appt-doctor")?.value ||
      "";
    const dateVal =
      BookingStateManager.date ||
      document.getElementById("appt-date")?.value ||
      "";
    const slotLabel = BookingStateManager.timeLabel;

    let dateDisplay = dateVal;
    try {
      const d = new Date(dateVal + "T12:00:00");
      const options = {
        weekday: "long",
        year: "numeric",
        month: "long",
        day: "numeric",
      };
      dateDisplay = d.toLocaleDateString("en-US", options);
    } catch (e) {}

    document.getElementById("summary-doctor-name").textContent = doctorName;
    document.getElementById("summary-date-text").textContent = dateDisplay;
    document.getElementById("summary-time-text").textContent = slotLabel;
    wrapper.classList.add("visible");
  },

  hideSlotSummary() {
    const wrapper = document.getElementById("slot-summary-wrapper");
    if (wrapper) wrapper.classList.remove("visible");
  },

  switchSlotTab(tabId) {
    document.querySelectorAll(".slot-tab").forEach((tab) => {
      const isActive = tab.id === `slot-tab-${tabId}`;
      tab.classList.toggle("active", isActive);
      tab.setAttribute("aria-selected", isActive ? "true" : "false");
      tab.setAttribute("tabindex", isActive ? "0" : "-1");
    });

    document.querySelectorAll(".slot-panel").forEach((panel) => {
      panel.classList.toggle("active", panel.id === `slot-panel-${tabId}`);
    });
  },

  updateSummary() {
    if (_summaryLocked) {
      return;
    }

    const emptyState = document.getElementById("summary-empty-state");
    const content = document.getElementById("summary-content");
    const detailsContainer = document.getElementById(
      "summary-details-container",
    );
    if (!emptyState || !content || !detailsContainer) return;

    const hasDept = BookingStateManager.department;
    const hasDoctor =
      BookingStateManager.doctor && BookingStateManager.doctorData;
    const hasDate = BookingStateManager.date;
    const hasTime = BookingStateManager.time;

    if (!hasDept && !hasDoctor && !hasDate && !hasTime) {
      emptyState.style.display = "flex";
      content.style.display = "none";
      return;
    }

    emptyState.style.display = "none";
    content.style.display = "block";

    let html = "";
    if (hasDept) {
      html += `
        <div class="summary-row">
          <i class="fas fa-building" aria-hidden="true"></i>
          <div class="summary-row-content">
            <div class="summary-row-label">Department</div>
            <div class="summary-row-value">${escapeHTML(BookingStateManager.department)}</div>
          </div>
        </div>`;
    }

    if (hasDoctor) {
      html += `
        <div class="summary-row">
          <i class="fas fa-user-doctor" aria-hidden="true"></i>
          <div class="summary-row-content">
            <div class="summary-row-label">Doctor</div>
            <div class="summary-row-value">${escapeHTML(BookingStateManager.doctor)}</div>
          </div>
        </div>`;
    }

    let dateDisplay = "";
    if (hasDate) {
      dateDisplay = BookingStateManager.date || "";
      try {
        const d = new Date(dateDisplay + "T12:00:00");
        const options = {
          weekday: "long",
          year: "numeric",
          month: "long",
          day: "numeric",
        };
        dateDisplay = d.toLocaleDateString("en-US", options);
      } catch (e) {}
      html += `
        <div class="summary-row">
          <i class="fas fa-calendar" aria-hidden="true"></i>
          <div class="summary-row-content">
            <div class="summary-row-label">Date</div>
            <div class="summary-row-value">${escapeHTML(dateDisplay)}</div>
          </div>
        </div>`;
    }

    if (hasTime) {
      const duration = BookingStateManager.doctorData?.duration || 30;
      let endTime = "";
      try {
        const [h, m] = BookingStateManager.time.split(":").map(Number);
        const totalMin = h * 60 + m + duration;
        const endH = Math.floor(totalMin / 60) % 24;
        const endM = totalMin % 60;
        const ampm = endH >= 12 ? "PM" : "AM";
        const displayH = endH % 12 || 12;
        endTime = ` – ${displayH}:${String(endM).padStart(2, "0")} ${ampm}`;
      } catch (e) {}
      html += `
        <div class="summary-divider"></div>
        <div class="summary-row">
          <i class="fas fa-clock" aria-hidden="true"></i>
          <div class="summary-row-content">
            <div class="summary-row-label">Time</div>
            <div class="summary-row-value">${escapeHTML(BookingStateManager.timeLabel)}${endTime}</div>
          </div>
        </div>
        <div class="summary-row">
          <i class="fas fa-hourglass-half" aria-hidden="true"></i>
          <div class="summary-row-content">
            <div class="summary-row-label">Duration</div>
            <div class="summary-row-value">${duration} Minutes</div>
          </div>
        </div>`;
    }

    detailsContainer.innerHTML = html;

    const reviewDepartment = document.getElementById("review-department");
    const reviewDoctor = document.getElementById("review-doctor");
    const reviewDate = document.getElementById("review-date");
    const reviewTime = document.getElementById("review-time");
    const reviewDuration = document.getElementById("review-duration");
    if (reviewDepartment)
      reviewDepartment.textContent = hasDept
        ? BookingStateManager.department
        : "—";
    if (reviewDoctor)
      reviewDoctor.textContent = hasDoctor ? BookingStateManager.doctor : "—";
    if (reviewDate) reviewDate.textContent = hasDate ? dateDisplay : "—";
    if (reviewTime)
      reviewTime.textContent = hasTime ? BookingStateManager.timeLabel : "—";
    if (reviewDuration)
      reviewDuration.textContent = hasTime
        ? `${BookingStateManager.doctorData?.duration || 30} Minutes`
        : "—";

    const submitBtn = document.getElementById("booking-summary-submit-btn");
    if (submitBtn) {
      submitBtn.disabled = !(hasDept && hasDoctor && hasDate && hasTime);
    }
  },

  enableSubmitButtons() {
    const btn1 = document.getElementById("booking-submit-btn");
    const btn2 = document.getElementById("booking-summary-submit-btn");
    if (btn1) btn1.disabled = false;
    if (btn2) btn2.disabled = false;
  },

  disableSubmitButtons() {
    const btn1 = document.getElementById("booking-submit-btn");
    const btn2 = document.getElementById("booking-summary-submit-btn");
    if (btn1) btn1.disabled = true;
    if (btn2) btn2.disabled = true;
  },
};

function goToStep(step) {
  BookingWizard.goToStep(step);
}

function selectSlot(btn) {
  BookingWizard.selectSlot(btn);
}

function switchSlotTab(tabId) {
  BookingWizard.switchSlotTab(tabId);
}

window.BookingWizard = BookingWizard;
window.goToStep = goToStep;
window.selectSlot = selectSlot;
window.switchSlotTab = switchSlotTab;
