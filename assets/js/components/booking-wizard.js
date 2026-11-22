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

function isBookingRecord(value) {
  return value !== null && typeof value === "object" && !Array.isArray(value);
}

function isBookingTime(value) {
  if (typeof value !== "string" || !/^([01]\d|2[0-3]):[0-5]\d$/.test(value)) return false;
  return true;
}

function isBookingDate(value) {
  if (typeof value !== "string" || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return false;
  const [year, month, day] = value.split("-").map(Number);
  const date = new Date(Date.UTC(year, month - 1, day));
  return date.getUTCFullYear() === year && date.getUTCMonth() === month - 1 && date.getUTCDate() === day;
}

function normalizeBookingDoctor(value) {
  if (!isBookingRecord(value)) return null;
  const userId = safePositiveId(value.user_id);
  if (!userId) return null;

  const scalarText = (candidate, fallback = "") =>
    typeof candidate === "string" ? candidate : fallback;
  const rawRating = value.rating;
  const ratingText = typeof rawRating === "string" ? rawRating.trim() : rawRating;
  const validRating = (typeof ratingText === "number" || typeof ratingText === "string")
    && (typeof ratingText !== "string" || /^(?:\d+(?:\.\d+)?|\.\d+)$/.test(ratingText))
    && Number.isFinite(Number(ratingText)) && Number(ratingText) >= 0 && Number(ratingText) <= 5;
  const duration = safeCount(value.duration, 30);
  const workingDays = Array.isArray(value.working_days)
    ? value.working_days.filter(day => typeof day === "string").slice(0, 7).join(", ")
    : scalarText(value.working_days, "Mon - Fri");

  return {
    user_id: userId,
    name: scalarText(value.name, "Doctor").slice(0, 160),
    specialty: scalarText(value.specialty).slice(0, 160),
    department_name: scalarText(value.department_name).slice(0, 160),
    available: value.available === true || value.available === 1 || value.available === "1" ? 1 : 0,
    rating: validRating ? Number(ratingText) : 0,
    rating_count: safeCount(value.rating_count, 0),
    exp: safeCount(value.exp, 0),
    duration: duration > 0 && duration <= 240 ? duration : 30,
    working_days: workingDays.slice(0, 160),
    next_available: scalarText(value.next_available).slice(0, 100),
    available_date: scalarText(value.available_date).slice(0, 40),
  };
}

function getBookingDoctorRatingLabel(doctor) {
  if (!isBookingRecord(doctor)) return "No ratings yet";
  const ratingCount = safeCount(doctor.rating_count, 0);
  if (ratingCount === 0) return "No ratings yet";

  const rating = typeof doctor.rating === "number" ? doctor.rating : null;
  if (rating === null || !Number.isFinite(rating) || rating < 0 || rating > 5) return "No ratings yet";
  return rating.toFixed(1);
}

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
    maxStep: 1,
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
      maxStep: 1,
      dirty: false,
      completed: false,
      restoring: false,
      resetting: false,
    };
  },

  setDirty(value) { this.state.dirty = value; },
  setRestoring(value) { this.state.restoring = value; },
  setResetting(value) { this.state.resetting = value; },
  setCompleted(value) { this.state.completed = value; },

  get department() { return this.state.department; },
  get doctor() { return this.state.doctor; },
  get doctorId() { return this.state.doctorId; },
  get doctorData() { return this.state.doctorData; },
  get date() { return this.state.date; },
  get time() { return this.state.time; },
  get timeLabel() { return this.state.timeLabel; },
  get step() { return this.state.step; },
  get maxStep() { return this.state.maxStep; },
  get dirty() { return this.state.dirty; },
  get restoring() { return this.state.restoring; },
  get resetting() { return this.state.resetting; },
  get completed() { return this.state.completed; },
};

const DraftManager = {
  STORAGE_KEY: "healthbridge_booking_draft",
  SAVE_DELAY: 300,
  saveTimer: null,

  save(state) {
    if (state.restoring || state.resetting || state.completed) return;
    if (sessionStorage.getItem("bookingCompleted") === "1") return;
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
      try { localStorage.setItem(this.STORAGE_KEY, JSON.stringify(draftData)); } catch (e) { console.error("Failed to save booking draft:", e); }
    }, this.SAVE_DELAY);
  },

  clear() {
    try { localStorage.removeItem(this.STORAGE_KEY); clearTimeout(this.saveTimer); } catch (e) { console.error("Failed to clear booking draft:", e); }
  },

  load() {
    try {
      const raw = localStorage.getItem(this.STORAGE_KEY);
      if (!raw) return null;
      const draft = JSON.parse(raw);
      return isBookingRecord(draft) ? draft : null;
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
    window.onbeforeunload = function (e) { e.preventDefault(); e.returnValue = ""; };
    this.isEnabled = true;
  },
  disable() { window.onbeforeunload = null; this.isEnabled = false; },
  update(state) { if (state.dirty && !state.completed && !state.resetting) { this.enable(); } else { this.disable(); } },
};

const BookingWizard = {
  initialized: false,
  isOpen: false,
  onSuccess: null,
  onClose: null,
  rescheduleMode: false,
  rescheduleAppt: null,
  initPromise: null,

  async init(options = {}) {
    if (this.initialized) return;
    if (this.initPromise) return this.initPromise;

    this.onSuccess = typeof options.onSuccess === "function" ? options.onSuccess : null;
    this.onClose = typeof options.onClose === "function" ? options.onClose : null;
    this.initPromise = (async () => {
      await this.loadDoctors();
      if (typeof loadDepartmentsDropdown === "function") {
        await loadDepartmentsDropdown("appt-department", true);
        this.renderDepartmentCards();
      }
      this.attachEventListeners();
      await this.restoreDraft();
      this.initialized = true;
    })();

    try {
      await this.initPromise;
    } finally {
      this.initPromise = null;
    }
  },

  async open() {
    const modal = document.getElementById("book-appointment-modal");
    if (!modal) return;
    if (!this.initialized) await this.init();
    modal.classList.add("open");
    document.body.classList.add("modal-open");
    this.isOpen = true;
    this.resetBookingWizard();
    try {
      const patientNameInput = document.getElementById("appt-patient");
      const user = typeof getUser === "function" ? getUser() : null;
      if (patientNameInput && typeof user?.name === "string") patientNameInput.value = user.name.slice(0, patientNameInput.maxLength > 0 ? patientNameInput.maxLength : 160);
    } catch (e) { console.warn("Failed to set appt-patient value:", e); }
  },

  async openReschedule(appt) {
    const appointmentId = isBookingRecord(appt) ? safePositiveId(appt.id) : 0;
    if (!appointmentId) return;
    this.rescheduleMode = true;
    this.rescheduleAppt = {
      id: appointmentId,
      department: typeof appt.department === "string" ? appt.department.slice(0, 160) : "",
      department_id: safePositiveId(appt.department_id),
      doctor: typeof appt.doctor === "string" ? appt.doctor.slice(0, 160) : "",
      doctor_id: safePositiveId(appt.doctor_id),
    };
    await this.open();
    const departmentInput = document.getElementById("appt-department");
    if (departmentInput && appt.department) {
      const trySetDept = () => {
        const options = [...departmentInput.options];
        const match = options.find(o => o.textContent.trim() === appt.department || o.value === String(appt.department_id));
        if (match) {
          departmentInput.value = match.value;
          departmentInput.dispatchEvent(new Event("change", { bubbles: true }));
          setTimeout(() => {
            const doctorInput = document.getElementById("appt-doctor");
            if (doctorInput && appt.doctor) {
              const docOptions = [...doctorInput.options];
              const docMatch = appt.doctor_id
                ? docOptions.find((option) => option.value === String(appt.doctor_id))
                : null;
              if (docMatch) { doctorInput.value = docMatch.value; doctorInput.dispatchEvent(new Event("change", { bubbles: true })); this.lockRescheduleFields(); }
              else { setTimeout(trySetDoc, 300); }
            }
          }, 300);
        } else { setTimeout(trySetDept, 500); }
      };
      const trySetDoc = () => {
        const doctorInput = document.getElementById("appt-doctor");
        if (doctorInput && appt.doctor) {
          const docOptions = [...doctorInput.options];
          const docMatch = appt.doctor_id
            ? docOptions.find((option) => option.value === String(appt.doctor_id))
            : null;
          if (docMatch) { doctorInput.value = docMatch.value; doctorInput.dispatchEvent(new Event("change", { bubbles: true })); this.lockRescheduleFields(); }
          else { setTimeout(trySetDoc, 500); }
        }
      };
      setTimeout(trySetDept, 500);
    }
    this.updateRescheduleUI();
  },

  lockRescheduleFields() {
    const departmentInput = document.getElementById("appt-department");
    const doctorInput = document.getElementById("appt-doctor");
    const departmentCards = document.getElementById("department-card-grid");
    const doctorCards = document.getElementById("doctor-card-grid");
    if (departmentInput) departmentInput.disabled = true;
    if (doctorInput) doctorInput.disabled = true;
    if (departmentCards) departmentCards.querySelectorAll(".selection-card").forEach(card => card.classList.add("is-locked"));
    if (doctorCards) doctorCards.querySelectorAll(".doctor-card").forEach(card => card.classList.add("is-locked"));
  },

  unlockRescheduleFields() {
    const departmentInput = document.getElementById("appt-department");
    const doctorInput = document.getElementById("appt-doctor");
    const departmentCards = document.getElementById("department-card-grid");
    const doctorCards = document.getElementById("doctor-card-grid");
    if (departmentInput) departmentInput.disabled = false;
    if (doctorInput) doctorInput.disabled = false;
    if (departmentCards) departmentCards.querySelectorAll(".selection-card").forEach(card => card.classList.remove("is-locked"));
    if (doctorCards) doctorCards.querySelectorAll(".doctor-card").forEach(card => card.classList.remove("is-locked"));
  },

  updateRescheduleUI() {
    const modalTitle = document.querySelector(".modal-header h3");
    const modalSubtitle = document.querySelector(".modal-subtitle");
    if (modalTitle) modalTitle.innerHTML = '<i class="fas fa-calendar-days" aria-hidden="true"></i> Reschedule Appointment';
    if (modalSubtitle) modalSubtitle.textContent = 'Select a new date and time for your appointment. Doctor and department cannot be changed.';
    const stepLabels = document.querySelectorAll(".step-label");
    if (stepLabels.length >= 2) { stepLabels[0].textContent = "Department (locked)"; stepLabels[1].textContent = "Doctor (locked)"; }
    const reviewCard = document.querySelector(".review-card");
    if (reviewCard && !document.getElementById("reschedule-reason")) {
      const reasonRow = document.createElement("div");
      reasonRow.className = "form-group booking-reschedule-reason";
      reasonRow.innerHTML = '<label for="reschedule-reason">Reason for Rescheduling (Optional)</label><textarea id="reschedule-reason" class="form-input" placeholder="Tell the doctor why you need to reschedule..." rows="3"></textarea>';
      reviewCard.parentNode.insertBefore(reasonRow, reviewCard.nextSibling);
    }
  },

  restoreBookingUI() {
    const modalTitle = document.querySelector(".modal-header h3");
    const modalSubtitle = document.querySelector(".modal-subtitle");
    if (modalTitle) modalTitle.innerHTML = '<i class="fas fa-calendar-days" aria-hidden="true"></i> Book Appointment';
    if (modalSubtitle) modalSubtitle.textContent = 'Quickly schedule your next visit with a specialist in four simple steps.';
    const stepLabels = document.querySelectorAll(".step-label");
    if (stepLabels.length >= 2) { stepLabels[0].textContent = "Department"; stepLabels[1].textContent = "Doctor"; }
    const reasonField = document.getElementById("reschedule-reason");
    if (reasonField) reasonField.closest(".form-group")?.remove();
    this.unlockRescheduleFields();
    this.rescheduleMode = false;
    this.rescheduleAppt = null;
  },

  close() {
    const modal = document.getElementById("book-appointment-modal");
    if (!modal) return;
    modal.classList.remove("open");
    document.body.classList.remove("modal-open");
    this.isOpen = false;
    if (this.rescheduleMode) this.restoreBookingUI();
    if (typeof this.onClose === "function") this.onClose();
  },

  toggleModal() {
    const modal = document.getElementById("book-appointment-modal");
    if (!modal) return;
    if (modal.classList.contains("open")) this.close(); else this.open();
  },

  async openWithAppointment(appt) {
    if (!isBookingRecord(appt)) return;
    await this.open();
    const departmentInput = document.getElementById("appt-department");
    const doctorInput = document.getElementById("appt-doctor");
    const dateInput = document.getElementById("appt-date");
    const notesInput = document.getElementById("appt-notes");
    if (departmentInput) {
      const departmentName = typeof appt.department === "string" ? appt.department : "";
      const departmentId = safePositiveId(appt.department_id);
      const matchOption = [...departmentInput.options].find(option =>
        option.value === departmentName || (departmentId > 0 && option.value === String(departmentId))
      );
      departmentInput.value = matchOption?.value || "";
      departmentInput.dispatchEvent(new Event("change", { bubbles: true }));
    }
    if (doctorInput) {
      const doctorId = safePositiveId(appt.doctor_id);
      const doctorOption = doctorId
        ? [...doctorInput.options].find(option => option.value === String(doctorId) && !option.disabled)
        : null;
      doctorInput.value = doctorOption?.value || "";
      doctorInput.dispatchEvent(new Event("change", { bubbles: true }));
    }
    if (dateInput) dateInput.value = "";
    if (notesInput) notesInput.value = "";
    this.hideSlotSummary();
    this.updateSummary();
  },

  async loadDoctors() {
    try {
      const response = await fetch(getBasePath() + "api/doctors/get.php");
      if (!response.ok) throw new Error("Doctor directory request failed.");
      const result = await response.json();
      appointmentDoctors = Array.isArray(result?.doctors)
        ? result.doctors.map(normalizeBookingDoctor).filter(Boolean)
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
    const summarySubmitBtn = document.getElementById("booking-summary-submit-btn");

    const bookingModal = document.getElementById("book-appointment-modal");
    if (bookingModal && bookingModal.dataset.delegatedActionsBound !== "true") {
      bookingModal.dataset.delegatedActionsBound = "true";
      bookingModal.addEventListener("click", (event) => {
        const target = event.target instanceof Element ? event.target : null;
        if (!target) return;

        const stepButton = target.closest("[data-booking-step]");
        if (stepButton && bookingModal.contains(stepButton)) {
          const step = Number(stepButton.dataset.bookingStep);
          if (Number.isInteger(step) && step >= 1 && step <= 4) this.goToStep(step);
          return;
        }

        const closeButton = target.closest('[data-booking-action="close"]');
        if (closeButton && bookingModal.contains(closeButton)) {
          this.close();
          return;
        }

        const tabButton = target.closest("[data-slot-tab]");
        if (tabButton && bookingModal.contains(tabButton)) {
          const tab = tabButton.dataset.slotTab;
          if (["morning", "afternoon", "evening"].includes(tab)) this.switchSlotTab(tab);
          return;
        }

        const slotButton = target.closest("[data-slot-select]");
        if (slotButton && bookingModal.contains(slotButton) && !slotButton.disabled) {
          this.selectSlot(slotButton);
        }
      });
    }

    if (departmentInput) departmentInput.addEventListener("change", () => this.handleDepartmentChange());
    if (departmentCards) {
      departmentCards.addEventListener("click", (event) => {
        const card = event.target.closest(".selection-card");
        if (!card || card.disabled) return;
        const department = card.dataset.department;
        if (department) this.selectDepartment(department);
      });
    }
    if (doctorInput) doctorInput.addEventListener("change", () => this.handleDoctorChange());
    if (doctorCards) {
      doctorCards.addEventListener("click", (event) => {
        const card = event.target.closest(".doctor-card");
        if (!card || card.disabled) return;
        const doctorId = card.dataset.doctorId;
        if (doctorId) this.selectDoctor(doctorId);
      });
    }
    if (dateInput) { dateInput.addEventListener("change", () => this.handleDateChange()); dateInput.min = new Date().toISOString().split("T")[0]; }
    if (form) form.addEventListener("submit", (e) => this.handleFormSubmit(e));
    if (summarySubmitBtn) summarySubmitBtn.addEventListener("click", () => { const formEl = document.getElementById("booking-form"); if (formEl) formEl.requestSubmit(); });
  },

  handleDepartmentChange() {
    const departmentInput = document.getElementById("appt-department");
    const doctorInput = document.getElementById("appt-doctor");
    if (!departmentInput) return;
    const selectedValue = typeof departmentInput.value === "string" ? departmentInput.value : "";
    const val = selectedValue && [...departmentInput.options].some(option => option.value === selectedValue)
      ? selectedValue
      : "";
    if (departmentInput.value !== val) departmentInput.value = val;
    BookingStateManager.update({ department: val });
    this.renderDepartmentCards();
    this.renderDoctorOptions();
    this.clearDateAndSlots();
    const btn = document.getElementById("step-1-next");
    if (btn) btn.disabled = !val;
    this.updateSummary();
    this.updateNavButtons();
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
    const selectedDoctorId = safePositiveId(doctorInput.value);
    const selectedOption = [...doctorInput.options].find(option => option.value === String(selectedDoctorId));
    const selected = selectedDoctorId && selectedOption && !selectedOption.disabled
      ? appointmentDoctors.find(doctor => safePositiveId(doctor.user_id) === selectedDoctorId && doctor.available === 1)
      : null;
    if (selectedDoctorId > 0 && selected) {
      BookingStateManager.update({ doctor: selected.name, doctorId: selectedDoctorId, doctorData: selected });
      this.renderDoctorPreview(selected);
      this.renderDoctorCards();
      const selectedDepartment = selected.department_name || selected.specialty || "";
      if (selectedDepartment && departmentInput) {
        departmentInput.value = selectedDepartment;
        BookingStateManager.update({ department: selectedDepartment });
        this.renderDepartmentCards();
      }
      const btn = document.getElementById("step-2-next");
      if (btn) btn.disabled = false;
      this.clearDateAndSlots();
    } else {
      if (doctorInput.value) doctorInput.value = "";
      BookingStateManager.update({ doctor: "", doctorId: 0, doctorData: null });
      this.clearDoctorPreview();
      this.renderDoctorCards();
      const btn = document.getElementById("step-2-next");
      if (btn) btn.disabled = true;
      this.clearDateAndSlots();
    }
    this.updateSummary();
    this.updateNavButtons();
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
    const requestedDate = typeof dateInput.value === "string" ? dateInput.value : "";
    const val = isBookingDate(requestedDate) ? requestedDate : "";
    if (dateInput.value !== val) dateInput.value = val;
    BookingStateManager.update({ date: val });
    if (val && safePositiveId(BookingStateManager.doctorId)) {
      const btn = document.getElementById("step-3-next");
      if (btn) btn.disabled = false;
      this.loadSlots();
    } else {
      const btn = document.getElementById("step-3-next");
      if (btn) btn.disabled = true;
      this.hideSlotUI();
    }
    this.updateSummary();
    this.updateNavButtons();
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
    const departmentValue = typeof departmentInput?.value === "string" ? departmentInput.value : "";
    const departmentOption = departmentInput
      ? [...departmentInput.options].find(option => option.value === departmentValue && departmentValue !== "")
      : null;
    const doctorId = safePositiveId(doctorInput?.value);
    const doctorOption = doctorInput && doctorId
      ? [...doctorInput.options].find(option => option.value === String(doctorId) && !option.disabled)
      : null;
    const selectedDoctor = doctorId
      ? appointmentDoctors.find(doctor => safePositiveId(doctor.user_id) === doctorId && doctor.available === 1)
      : null;
    const dateCandidate = typeof dateInput?.value === "string" ? dateInput.value : "";
    const timeCandidate = typeof timeInput?.value === "string" ? timeInput.value : "";
    const dateValue = isBookingDate(dateCandidate) && dateCandidate === BookingStateManager.date
      ? dateCandidate
      : "";
    const timeValue = isBookingTime(timeCandidate) && timeCandidate === BookingStateManager.time
      ? timeCandidate
      : "";
    const currentUser = typeof getUser === "function" ? getUser() : null;
    const patientNameValue = typeof patientNameInput?.value === "string" && patientNameInput.value.trim()
      ? patientNameInput.value.trim()
      : (typeof currentUser?.name === "string" ? currentUser.name.trim() : "");
    const notesValue = typeof notesInput?.value === "string" ? notesInput.value.slice(0, 2000) : "";
    const selectedDoctorDepartment = selectedDoctor
      ? (selectedDoctor.department_name || selectedDoctor.specialty || "")
      : "";
    const doctorMatchesDepartment = Boolean(selectedDoctorDepartment && selectedDoctorDepartment === departmentOption?.value);
    const data = {
      department: departmentOption?.value || "",
      doctor: selectedDoctor?.name || "",
      doctor_id: selectedDoctor && doctorOption && doctorMatchesDepartment ? doctorId : 0,
      date: dateValue,
      time: timeValue,
      patientName: patientNameValue.slice(0, 160),
      notes: notesValue,
    };
    if (!data.department || !data.doctor_id || !data.doctor || !data.date || !data.time || !data.patientName) {
      showToast("Please select a valid department, available doctor, date and time, and provide a patient name.", "error");
      return;
    }
    const submitBtn = document.getElementById("booking-submit-btn");
    const originalText = submitBtn?.textContent || "Confirm Booking";
    setLoading(submitBtn, true, "Booking...");
    let endpoint, bodyData;
    if (this.rescheduleMode && this.rescheduleAppt) {
      const appointmentId = safePositiveId(this.rescheduleAppt.id);
      if (!appointmentId) {
        setLoading(submitBtn, false, originalText);
        showToast("The appointment reference is no longer valid.", "error");
        return;
      }
      const reasonInput = document.getElementById("reschedule-reason");
      const reason = typeof reasonInput?.value === "string" ? reasonInput.value.trim().slice(0, 500) : "";
      endpoint = getBasePath() + "api/appointments/request-reschedule.php";
      bodyData = { appointment_id: appointmentId, new_date: data.date, new_time: data.time, reason: reason };
    } else {
      endpoint = getBasePath() + "api/appointments/book.php";
      bodyData = data;
    }
    const result = await apiFetch(endpoint, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(bodyData) }, "Service unavailable.");
    if (result.data?.success) {
      BookingStateManager.setResetting(true);
      clearTimeout(DraftManager.saveTimer);
      DraftManager.clear();
      LeaveProtectionManager.disable();
      _summaryLocked = true;
      const successEl = document.getElementById("booking-success");
      const refNumber = document.getElementById("booking-ref-number");
      if (refNumber && result.data?.appointment?.id) refNumber.textContent = `#${result.data.appointment.id}`;
      if (this.rescheduleMode) {
        const successTitle = successEl?.querySelector("h3");
        const successDesc = successEl?.querySelector("p");
        if (successTitle) successTitle.textContent = "Reschedule Request Submitted!";
        if (successDesc) successDesc.textContent = "Your reschedule request has been sent to the doctor for approval.";
      }
      document.querySelectorAll(".booking-step").forEach((el) => el.classList.remove("active"));
      document.querySelectorAll(".booking-step-indicator").forEach((ind) => { ind.classList.remove("active", "completed", "locked"); });
      if (successEl) successEl.classList.add("visible");
      try {
        const docId = BookingStateManager.doctorId || (result.data?.appointment?.doctor_id || 0);
        const apptDate = data.date; const apptTime = data.time;
        if (docId && apptDate && apptTime) bookedSlots.add(`${docId}|${apptDate}|${apptTime}`);
      } catch (e) { console.warn('Failed to cache booked slot:', e); }
      try { this.loadSlots(); } catch (e) {}
      if (typeof this.onSuccess === "function") this.onSuccess();
    } else {
      showToast(result.data?.message || "Booking failed.", "error");
    }
    setLoading(submitBtn, false, originalText);
  },

  renderDoctorOptions() {
    const doctorInput = document.getElementById("appt-doctor");
    const departmentInput = document.getElementById("appt-department");
    if (!doctorInput) return;
    const department = typeof departmentInput?.value === "string" ? departmentInput.value : "";
    const doctorsToShow = department
      ? appointmentDoctors.filter(doctor => (doctor.department_name || doctor.specialty) === department)
      : appointmentDoctors;
    const currentDoctor = safePositiveId(doctorInput.value);
    doctorInput.replaceChildren(new Option("Select a doctor", ""));
    doctorsToShow.forEach(doctor => {
      const doctorId = safePositiveId(doctor.user_id);
      if (!doctorId) return;
      const doctorName = typeof doctor.name === "string" ? doctor.name : "Doctor";
      const specialty = typeof doctor.specialty === "string" ? doctor.specialty : "";
      const unavailable = doctor.available === 0;
      const option = new Option(`${doctorName}${unavailable ? " (Not available)" : ""}`, String(doctorId));
      option.disabled = unavailable;
      option.dataset.specialty = specialty;
      doctorInput.add(option);
    });
    const retainedDoctor = currentDoctor
      ? doctorsToShow.find(doctor => safePositiveId(doctor.user_id) === currentDoctor)
      : null;
    if (retainedDoctor) {
      doctorInput.value = String(retainedDoctor.user_id);
      BookingStateManager.update({
        doctor: retainedDoctor.name,
        doctorId: Number(retainedDoctor.user_id),
        doctorData: retainedDoctor,
      });
      this.renderDoctorPreview(retainedDoctor);
    } else {
      BookingStateManager.update({ doctor: "", doctorId: 0, doctorData: null });
      this.clearDoctorPreview();
    }
    this.renderDoctorCards();
    this.clearDateAndSlots();
  },

  selectDepartment(department) {
    const departmentInput = document.getElementById("appt-department");
    if (!departmentInput || typeof department !== "string") return;
    const option = [...departmentInput.options].find(candidate => candidate.value === department);
    if (!option) return;
    departmentInput.value = option.value;
    departmentInput.dispatchEvent(new Event("change", { bubbles: true }));
  },

  selectDoctor(doctorId) {
    const doctorInput = document.getElementById("appt-doctor");
    const safeId = safePositiveId(doctorId);
    if (!doctorInput || !safeId) return;
    const option = [...doctorInput.options].find(candidate => candidate.value === String(safeId) && !candidate.disabled);
    if (!option) return;
    doctorInput.value = option.value;
    doctorInput.dispatchEvent(new Event("change", { bubbles: true }));
  },

  getDepartmentIcon(department) {
    const icons = { cardiology: "fa-heart-pulse", dermatology: "fa-hand-sparkles", neurology: "fa-brain", pediatrics: "fa-baby", orthopedics: "fa-bone", dentistry: "fa-tooth", ophthalmology: "fa-eye", gynecology: "fa-venus", "general practice": "fa-stethoscope" };
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
    const selectedDepartment = departmentInput.value || BookingStateManager.department || "";
    const options = [...departmentInput.options].filter(option => option.value);
    if (!options.length) { cardGrid.innerHTML = `<div class="empty-state">No departments available.</div>`; return; }
    cardGrid.innerHTML = options.map((option) => {
      const dept = option.value;
      const isActive = dept === selectedDepartment;
      const iconClass = this.getDepartmentIcon(dept);
      return `<button type="button" class="selection-card${isActive ? " selected" : ""}" data-department="${escapeHTML(dept)}" aria-pressed="${isActive ? "true" : "false"}"><div class="selection-card-icon"><i class="fas ${escapeHTML(iconClass)}" aria-hidden="true"></i></div><div class="selection-card-body"><span class="selection-card-title">${escapeHTML(dept)}</span><span class="selection-card-desc">${escapeHTML(this.getDepartmentDescription(dept))}</span></div></button>`;
    }).join("");
  },

  renderDoctorCards() {
    const departmentInput = document.getElementById("appt-department");
    const doctorInput = document.getElementById("appt-doctor");
    const cardGrid = document.getElementById("doctor-card-grid");
    if (!doctorInput || !cardGrid) return;
    const department = typeof departmentInput?.value === "string" ? departmentInput.value : "";
    const doctorsToShow = department
      ? appointmentDoctors.filter(doctor => (doctor.department_name || doctor.specialty) === department)
      : appointmentDoctors;
    if (!doctorsToShow.length) { cardGrid.innerHTML = `<div class="empty-state">No doctors found for this department.</div>`; return; }
    const selectedDoctorId = safePositiveId(BookingStateManager.doctorId) || safePositiveId(doctorInput.value);
    cardGrid.innerHTML = doctorsToShow.map(doctor => {
      const doctorId = safePositiveId(doctor.user_id);
      if (!doctorId) return "";
      const doctorName = typeof doctor.name === "string" && doctor.name.trim() ? doctor.name : "Doctor";
      const doctorSpecialty = typeof doctor.specialty === "string" && doctor.specialty.trim()
        ? doctor.specialty
        : (typeof doctor.department_name === "string" && doctor.department_name.trim() ? doctor.department_name : "General Practice");
      const isSelected = doctorId === selectedDoctorId;
      const available = doctor.available !== 0;
      const rating = getBookingDoctorRatingLabel(doctor);
      const ratingMarkup = rating === "No ratings yet"
        ? "No ratings yet"
        : `<i class="fas fa-star" aria-hidden="true"></i> ${escapeHTML(rating)}`;
      const experienceYears = safeCount(doctor.exp, 0);
      const experience = experienceYears ? `${experienceYears} yrs` : "";
      const nextAvailable = typeof doctor.next_available === "string" && doctor.next_available
        ? doctor.next_available
        : (typeof doctor.available_date === "string" ? doctor.available_date : "");
      const initials = doctorName.split(/\s+/).filter(Boolean).map(part => part[0]).join("").substring(0, 2).toUpperCase() || "DR";
      return `<button type="button" class="doctor-card${isSelected ? " selected" : ""}${available ? "" : " disabled"}" data-doctor-id="${doctorId}" ${available ? "" : "disabled"} aria-pressed="${isSelected ? "true" : "false"}"><div class="doctor-card-avatar">${escapeHTML(initials)}</div><div class="doctor-card-body"><div class="doctor-card-header"><div><h4>${escapeHTML(doctorName)}</h4><p>${escapeHTML(doctorSpecialty)}</p></div><span class="doctor-card-badge ${available ? "available" : "unavailable"}">${available ? "Available" : "Unavailable"}</span></div><div class="doctor-card-meta"><span>${ratingMarkup}</span>${experience ? `<span><i class="fas fa-briefcase" aria-hidden="true"></i> ${experience}</span>` : ""}${nextAvailable ? `<span><i class="fas fa-calendar-check" aria-hidden="true"></i> ${escapeHTML(nextAvailable)}</span>` : ""}</div></div></button>`;
    }).join("");
  },

  clearDoctorPreview() { const container = document.getElementById("doctor-preview-container"); if (container) container.innerHTML = ""; },

  renderDoctorPreview(doctor) {
    const container = document.getElementById("doctor-preview-container");
    if (!container || !isBookingRecord(doctor)) return;
    const doctorName = typeof doctor.name === "string" && doctor.name.trim() ? doctor.name : "Doctor";
    const specialty = typeof doctor.specialty === "string" && doctor.specialty.trim() ? doctor.specialty : "General Practice";
    const initials = doctorName.split(/\s+/).filter(Boolean).map(part => part[0]).join("").substring(0, 2).toUpperCase() || "DR";
    const rating = getBookingDoctorRatingLabel(doctor);
    const ratingMarkup = rating === "No ratings yet"
      ? "No ratings yet"
      : `<i class="fas fa-star" aria-hidden="true"></i> ${escapeHTML(rating)} Rating`;
    const isAvail = doctor.available !== 0;
    const availText = isAvail ? "Available" : "Not Available";
    const availClass = isAvail ? "available" : "unavailable";
    const workingDays = typeof doctor.working_days === "string" && doctor.working_days.trim()
      ? doctor.working_days.slice(0, 160)
      : "Mon - Fri";
    const rawDuration = safeCount(doctor.duration, 30);
    const duration = rawDuration > 0 && rawDuration <= 240 ? rawDuration : 30;
    container.innerHTML = `<div class="doctor-preview-card"><div class="doctor-preview-avatar">${escapeHTML(initials)}</div><div class="doctor-preview-body"><h4 class="doctor-preview-name">${escapeHTML(doctorName)}</h4><p class="doctor-preview-specialty">${escapeHTML(specialty)}</p><div class="doctor-preview-meta"><span>${ratingMarkup}</span><span><i class="fas fa-clock" aria-hidden="true"></i> ${duration} min</span><span><i class="fas fa-calendar-week" aria-hidden="true"></i> ${escapeHTML(workingDays)}</span></div><span class="doctor-preview-badge ${availClass}"><i class="fas ${isAvail ? "fa-check-circle" : "fa-times-circle"}" aria-hidden="true"></i> ${escapeHTML(availText)}</span></div></div>`;
  },

  clearDateAndSlots() {
    const dateInput = document.getElementById("appt-date");
    const timeInput = document.getElementById("appt-time");
    if (dateInput) dateInput.value = "";
    if (timeInput) timeInput.value = "";
    BookingStateManager.update({ date: "", time: "", timeLabel: "" });
    this.hideSlotSummary();
    this.hideSlotUI();
    this.updateSummary();
  },

  hideSlotUI() {
    lastSlotRequestId += 1;
    const slotContainer = document.getElementById("slot-picker-container");
    if (!slotContainer) return;
    slotContainer.innerHTML = '<p class="slot-placeholder">Select a doctor and date to see available slots.</p>';
  },

  async ensureDepartmentsLoaded() {
    const departmentInput = document.getElementById("appt-department");
    if (!departmentInput) return;
    if (departmentInput.options.length <= 1 && typeof loadDepartmentsDropdown === "function") await loadDepartmentsDropdown("appt-department", true);
  },

  async restoreDraft() {
    const draft = DraftManager.load();
    if (!draft) return;
    BookingStateManager.setRestoring(true);
    try {
      const departmentInput = document.getElementById("appt-department");
      const doctorInput = document.getElementById("appt-doctor");
      const dateInput = document.getElementById("appt-date");
      const notesInput = document.getElementById("appt-notes");

      if (departmentInput && typeof draft.department === "string") {
        const departmentOption = [...departmentInput.options].find(option => option.value === draft.department);
        departmentInput.value = departmentOption ? departmentOption.value : "";
      }
      this.renderDepartmentCards();
      this.renderDoctorOptions();

      const draftDoctorId = safePositiveId(draft.doctorId);
      if (doctorInput && draftDoctorId) {
        const matchingDoctor = appointmentDoctors.find(doctor => safePositiveId(doctor.user_id) === draftDoctorId && doctor.available === 1);
        if (matchingDoctor) {
          doctorInput.value = String(draftDoctorId);
          this.handleDoctorChange();
        }
      }
      if (dateInput && isBookingDate(draft.date) && safePositiveId(BookingStateManager.doctorId)) {
        dateInput.value = draft.date;
        if (dateInput.value === draft.date) {
          BookingStateManager.update({ date: draft.date });
          await this.loadSlots();
          const draftTime = isBookingTime(draft.time) ? draft.time : "";
          if (draftTime) {
            const matchingSlot = [...document.querySelectorAll("#slot-picker-container [data-slot-select]")]
              .find(slot => slot.dataset.time === draftTime);
            if (matchingSlot) this.selectSlot(matchingSlot);
          }
        }
      }
      if (notesInput && typeof draft.notes === "string") {
        notesInput.value = draft.notes.slice(0, notesInput.maxLength > 0 ? notesInput.maxLength : 2000);
      }
      this.updateSummary();
    } finally {
      BookingStateManager.setRestoring(false);
    }
  },

  async loadSlots() {
    const doctorId = safePositiveId(BookingStateManager.doctorId);
    const date = BookingStateManager.date;
    const slotContainer = document.getElementById("slot-picker-container");
    const slotMessage = document.getElementById("slot-picker-message");
    if (!slotContainer || !slotMessage) return;
    if (!doctorId || !isBookingDate(date)) { this.hideSlotUI(); return; }
    const requestId = ++lastSlotRequestId;
    slotMessage.textContent = "Loading available slots...";
    try {
      const response = await fetch(getBasePath() + "api/appointments/get-available-slots.php", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ doctor_id: doctorId, date }) });
      if (!response.ok) throw new Error("Available slots request failed.");
      const result = await response.json();
      if (requestId !== lastSlotRequestId) return;
      const sourceSlots = Array.isArray(result?.slots) ? result.slots : [];
      const slots = sourceSlots
        .filter(slot => isBookingRecord(slot) && isBookingTime(slot.time))
        .map(slot => ({
          time: slot.time,
          label: typeof slot.label === "string" && slot.label.trim()
            ? slot.label.trim().slice(0, 80)
            : slot.time,
        }))
        .filter(slot => !bookedSlots.has(`${doctorId}|${date}|${slot.time}`));
      if (!slots.length) {
        const message = typeof result?.message === "string" && result.message.trim()
          ? result.message.slice(0, 300)
          : "No appointment slots are available for this date.";
        this.renderSlots([], message, requestId);
        slotMessage.textContent = message;
        return;
      }
      this.renderSlots(slots, "", requestId);
      slotMessage.textContent = "Select one of the available time slots.";
    } catch (error) {
      console.error("Failed to load appointment slots:", error);
      if (requestId !== lastSlotRequestId) return;
      this.renderSlots([], "Unable to load slots. Please try again.", requestId);
      slotMessage.textContent = "Unable to load available slots. Please try again later.";
    }
  },

  renderSlots(slots, message, requestId) {
    const slotContainer = document.getElementById("slot-picker-container");
    if (!slotContainer) return;
    const groups = this.groupSlotsByPeriod(slots);
    let html = '';
    html += '<div class="slot-tabs" id="slot-tabs">';
    html += `<button class="slot-tab active" id="slot-tab-morning" type="button" data-slot-tab="morning" aria-selected="true"><span>Morning</span><span class="slot-tab-count">${groups.Morning.length}</span></button>`;
    html += `<button class="slot-tab" id="slot-tab-afternoon" type="button" data-slot-tab="afternoon" aria-selected="false"><span>Afternoon</span><span class="slot-tab-count">${groups.Afternoon.length}</span></button>`;
    html += `<button class="slot-tab" id="slot-tab-evening" type="button" data-slot-tab="evening" aria-selected="false"><span>Evening</span><span class="slot-tab-count">${groups.Evening.length}</span></button>`;
    html += '</div><div class="slot-scroll-area" id="slot-scroll-area">';
    const periods = [{ id: "morning", name: "Morning" }, { id: "afternoon", name: "Afternoon" }, { id: "evening", name: "Evening" }];
    periods.forEach((period, index) => {
      const periodSlots = groups[period.name] || [];
      html += `<div class="slot-panel${index === 0 ? " active" : ""}" id="slot-panel-${period.id}" role="tabpanel">`;
      if (periodSlots.length === 0) {
        html += `<div class="slot-empty-card"><div class="slot-empty-icon"><i class="fas fa-calendar" aria-hidden="true"></i></div><h4 class="slot-empty-title">No Available Slots</h4><p class="slot-empty-desc">${escapeHTML(message || "No appointment slots are available for this time period.")}</p></div>`;
      } else {
        html += '<div class="slot-grid" role="radiogroup">';
        periodSlots.forEach((slot) => {
          html += `<button type="button" class="slot-chip" data-time="${escapeHTML(slot.time)}" data-label="${escapeHTML(slot.label)}" data-slot-select="true" role="radio" aria-checked="false" tabindex="0"><span class="slot-check" aria-hidden="true"><i class="fas fa-check"></i></span><span class="slot-label">${escapeHTML(slot.label)}</span></button>`;
        });
        html += '</div>';
      }
      html += '</div>';
    });
    html += '</div>';
    if (requestId !== lastSlotRequestId) return;
    slotContainer.innerHTML = html;
    const firstActive = groups.Morning.length > 0 ? "morning" : groups.Afternoon.length > 0 ? "afternoon" : "evening";
    this.switchSlotTab(firstActive);
    this.hideSlotSummary();

    this.disableSubmitButtons();
  },

  groupSlotsByPeriod(slots) {
    const groups = { Morning: [], Afternoon: [], Evening: [] };
    const validSlots = Array.isArray(slots)
      ? slots.filter(slot => isBookingRecord(slot) && isBookingTime(slot.time))
        .map(slot => ({
          time: slot.time,
          label: typeof slot.label === "string" && slot.label.trim()
            ? slot.label.trim().slice(0, 80)
            : slot.time,
        }))
      : [];

    validSlots.forEach(slot => {
      const hour = Number(slot.time.slice(0, 2));
      if (hour >= 17) groups.Evening.push(slot);
      else if (hour >= 12) groups.Afternoon.push(slot);
      else groups.Morning.push(slot);
    });
    return groups;
  },

  resetBookingWizard() {
    BookingStateManager.reset();
    const successEl = document.getElementById("booking-success");
    if (successEl) successEl.classList.remove("visible");
    document.querySelectorAll(".booking-step").forEach((el) => el.classList.remove("active"));
    const step1 = document.getElementById("booking-step-1");
    if (step1) step1.classList.add("active");
    document.querySelectorAll(".booking-step-indicator").forEach((ind) => {
      const s = parseInt(ind.dataset.step, 10);
      ind.classList.remove("completed", "active", "locked");
      if (s === 1) ind.classList.add("active"); else ind.classList.add("locked");
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
    this.updateNavButtons();
    const dateInput = document.getElementById("appt-date");
    if (dateInput) dateInput.min = new Date().toISOString().split("T")[0];
    document.querySelectorAll(".slot-chip.selected").forEach((c) => { c.classList.remove("selected"); c.setAttribute("aria-checked", "false"); });
  },

  /**
   * goToStep — robust step navigation.
   * - Forward steps update maxStep (unlock next step)
   * - Backward steps are always allowed to any previously visited step
   * - Steps can never exceed 4 (the final confirmation step)
   * - Step 1 disables Previous; step 4 replaces Next with Confirm
   */
  goToStep(step) {
    if (step < 1 || step > 4) return;
    const currentMaxStep = BookingStateManager.maxStep;
    // Can only go forward to steps we've unlocked, or backward to any visited step
    if (step > currentMaxStep + 1) return; // Can't skip more than 1 ahead

    document.querySelectorAll(".booking-step").forEach((el) => el.classList.remove("active"));
    const targetStepEl = document.getElementById(`booking-step-${step}`);
    if (targetStepEl) targetStepEl.classList.add("active");

    document.querySelectorAll(".booking-step-indicator").forEach((indicator) => {
      const s = parseInt(indicator.dataset.step, 10);
      indicator.classList.remove("active", "completed", "locked");
      if (s === step) {
        indicator.classList.add("active");
      } else if (s < step || s <= currentMaxStep) {
        indicator.classList.add("completed");
      } else {
        indicator.classList.add("locked");
      }
    });

    // Update maxStep when moving forward (never decreases when going back)
    BookingStateManager.update({ step, maxStep: Math.max(currentMaxStep, step) });

    // Update navigation buttons (Previous/Next/Confirm states)
    this.updateNavButtons();

    const bookingWizard = document.querySelector(".booking-wizard");
    if (bookingWizard) {
      setTimeout(() => { bookingWizard.scrollIntoView({ behavior: "smooth", block: "start" }); }, 50);
    }
    if (!BookingStateManager.restoring && !BookingStateManager.resetting) {
      DraftManager.save(BookingStateManager.state);
    }
  },

  /**
   * updateNavButtons — authoritative Previous/Next/Confirm state.
   * - Previous: disabled on step 1, enabled on steps 2–4
   * - Next: shown on steps 1–3, hidden on step 4
   * - Confirm: hidden on steps 1–3, shown on step 4
   * - Next buttons are only enabled when their step's validation passes
   */
  updateNavButtons() {
    const currentStep = BookingStateManager.step;

    // ── Previous (Back) buttons ──
    document.querySelectorAll(".step-actions .btn-outline").forEach((btn) => {
      const parentActions = btn.closest(".step-actions");
      if (!parentActions) return;
      const parentStep = parentActions.closest(".booking-step");
      if (!parentStep) return;
      const stepNum = parseInt(parentStep.dataset.step, 10);
      // Only manage the current active step's buttons
      if (stepNum === currentStep) {
        const isBack = btn.textContent.includes("Back") || btn.innerHTML.includes("arrow-left");
        if (isBack) {
          btn.disabled = currentStep <= 1;
        }
      }
    });

    // ── Next buttons (steps 1–3) ──
    document.querySelectorAll(".step-actions .btn-primary").forEach((btn) => {
      const parentActions = btn.closest(".step-actions");
      if (!parentActions) return;
      const parentStep = parentActions.closest(".booking-step");
      if (!parentStep) return;
      const stepNum = parseInt(parentStep.dataset.step, 10);
      if (stepNum !== currentStep) return;

      // The submit/confirm button lives inside step 4's .step-actions
      const isConfirm = btn.id === "booking-submit-btn";

      if (currentStep === 4) {
        // On the final step: hide Next, show Confirm
        if (!isConfirm) btn.classList.add("is-hidden");
        const confirmBtn = document.getElementById("booking-submit-btn");
        if (confirmBtn) confirmBtn.classList.remove("is-hidden");
      } else {
        // On steps 1–3: show Next, hide Confirm
        if (!isConfirm) btn.classList.remove("is-hidden");
        const confirmBtn = document.getElementById("booking-submit-btn");
        if (confirmBtn) confirmBtn.classList.add("is-hidden");
      }
    });

    // ── Enforce per-step Next validation (never allow skipping incomplete steps) ──
    const step1Next = document.getElementById("step-1-next");
    if (step1Next) step1Next.disabled = !BookingStateManager.department;
    const step2Next = document.getElementById("step-2-next");
    if (step2Next) step2Next.disabled = !BookingStateManager.doctor;
    const step3Next = document.getElementById("step-3-next");
    if (step3Next) step3Next.disabled = !(BookingStateManager.date && BookingStateManager.time);
  },

  selectSlot(btn) {
    const slotContainer = document.getElementById("slot-picker-container");
    if (!(btn instanceof HTMLButtonElement) || !slotContainer?.contains(btn)
      || btn.dataset.slotSelect !== "true" || !isBookingTime(btn.dataset.time)) return;
    const slotLabel = typeof btn.dataset.label === "string" && btn.dataset.label.trim()
      ? btn.dataset.label.trim().slice(0, 80)
      : (btn.querySelector(".slot-label")?.textContent || btn.dataset.time).slice(0, 80);
    document.querySelectorAll(".slot-chip.selected").forEach((c) => { c.classList.remove("selected"); c.setAttribute("aria-checked", "false"); });
    btn.classList.add("selected");
    btn.setAttribute("aria-checked", "true");
    const timeInput = document.getElementById("appt-time");
    if (timeInput) timeInput.value = btn.dataset.time;
    BookingStateManager.update({ time: btn.dataset.time, timeLabel: slotLabel });
    this.updateSlotSummary(btn);
    this.enableSubmitButtons();
    this.updateSummary();
    this.updateNavButtons();
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
    const doctorName = BookingStateManager.doctor || document.getElementById("appt-doctor")?.value || "";
    const dateVal = BookingStateManager.date || document.getElementById("appt-date")?.value || "";
    const slotLabel = BookingStateManager.timeLabel;
    let dateDisplay = dateVal;
    try { const d = new Date(dateVal + "T12:00:00"); dateDisplay = d.toLocaleDateString("en-US", { weekday: "long", year: "numeric", month: "long", day: "numeric" }); } catch (e) {}
    document.getElementById("summary-doctor-name").textContent = doctorName;
    document.getElementById("summary-date-text").textContent = dateDisplay;
    document.getElementById("summary-time-text").textContent = slotLabel;
    wrapper.classList.add("visible");
  },

  hideSlotSummary() { const wrapper = document.getElementById("slot-summary-wrapper"); if (wrapper) wrapper.classList.remove("visible"); },

  switchSlotTab(tabId) {
    if (!["morning", "afternoon", "evening"].includes(tabId)) return;
    document.querySelectorAll(".slot-tab").forEach((tab) => { const isActive = tab.id === `slot-tab-${tabId}`; tab.classList.toggle("active", isActive); tab.setAttribute("aria-selected", isActive ? "true" : "false"); tab.setAttribute("tabindex", isActive ? "0" : "-1"); });
    document.querySelectorAll(".slot-panel").forEach((panel) => { panel.classList.toggle("active", panel.id === `slot-panel-${tabId}`); });
  },

  updateSummary() {
    if (_summaryLocked) return;
    const emptyState = document.getElementById("summary-empty-state");
    const content = document.getElementById("summary-content");
    const detailsContainer = document.getElementById("summary-details-container");
    if (!emptyState || !content || !detailsContainer) return;
    const departmentLabel = typeof BookingStateManager.department === "string" ? BookingStateManager.department : "";
    const doctorLabel = typeof BookingStateManager.doctor === "string" ? BookingStateManager.doctor : "";
    const dateValue = isBookingDate(BookingStateManager.date) ? BookingStateManager.date : "";
    const timeValue = isBookingTime(BookingStateManager.time) ? BookingStateManager.time : "";
    const timeLabel = typeof BookingStateManager.timeLabel === "string" ? BookingStateManager.timeLabel.slice(0, 80) : timeValue;
    const doctorData = isBookingRecord(BookingStateManager.doctorData) ? BookingStateManager.doctorData : null;
    const hasDept = Boolean(departmentLabel);
    const hasDoctor = Boolean(doctorLabel && doctorData);
    const hasDate = Boolean(dateValue);
    const hasTime = Boolean(timeValue);
    let selectedDuration = 30;
    if (!hasDept && !hasDoctor && !hasDate && !hasTime) {
      emptyState.classList.remove("is-hidden");
      content.classList.remove("is-visible");
      return;
    }
    emptyState.classList.add("is-hidden");
    content.classList.add("is-visible");
    let html = '';
    if (hasDept) html += `<div class="summary-row"><i class="fas fa-building" aria-hidden="true"></i><div class="summary-row-content"><div class="summary-row-label">Department</div><div class="summary-row-value">${escapeHTML(departmentLabel)}</div></div></div>`;
    if (hasDoctor) html += `<div class="summary-row"><i class="fas fa-user-doctor" aria-hidden="true"></i><div class="summary-row-content"><div class="summary-row-label">Doctor</div><div class="summary-row-value">${escapeHTML(doctorLabel)}</div></div></div>`;
    let dateDisplay = '';
    if (hasDate) {
      dateDisplay = dateValue;
      try { const d = new Date(dateDisplay + "T12:00:00"); dateDisplay = d.toLocaleDateString("en-US", { weekday: "long", year: "numeric", month: "long", day: "numeric" }); } catch (e) {}
      html += `<div class="summary-row"><i class="fas fa-calendar" aria-hidden="true"></i><div class="summary-row-content"><div class="summary-row-label">Date</div><div class="summary-row-value">${escapeHTML(dateDisplay)}</div></div></div>`;
    }
    if (hasTime) {
      const rawDuration = safeCount(doctorData?.duration, 30);
      const duration = rawDuration > 0 && rawDuration <= 240 ? rawDuration : 30;
      selectedDuration = duration;
      let endTime = '';
      try { const [h, m] = BookingStateManager.time.split(":").map(Number); const totalMin = h * 60 + m + duration; const endH = Math.floor(totalMin / 60) % 24; const endM = totalMin % 60; const ampm = endH >= 12 ? "PM" : "AM"; const displayH = endH % 12 || 12; endTime = ` – ${displayH}:${String(endM).padStart(2, "0")} ${ampm}`; } catch (e) {}
      html += `<div class="summary-divider"></div><div class="summary-row"><i class="fas fa-clock" aria-hidden="true"></i><div class="summary-row-content"><div class="summary-row-label">Time</div><div class="summary-row-value">${escapeHTML(timeLabel)}${escapeHTML(endTime)}</div></div></div><div class="summary-row"><i class="fas fa-hourglass-half" aria-hidden="true"></i><div class="summary-row-content"><div class="summary-row-label">Duration</div><div class="summary-row-value">${duration} Minutes</div></div></div>`;
    }
    detailsContainer.innerHTML = html;
    const reviewDepartment = document.getElementById("review-department");
    const reviewDoctor = document.getElementById("review-doctor");
    const reviewDate = document.getElementById("review-date");
    const reviewTime = document.getElementById("review-time");
    const reviewDuration = document.getElementById("review-duration");
    if (reviewDepartment) reviewDepartment.textContent = hasDept ? departmentLabel : "—";
    if (reviewDoctor) reviewDoctor.textContent = hasDoctor ? doctorLabel : "—";
    if (reviewDate) reviewDate.textContent = hasDate ? dateDisplay : "—";
    if (reviewTime) reviewTime.textContent = hasTime ? timeLabel : "—";
    if (reviewDuration) reviewDuration.textContent = hasTime ? `${selectedDuration} Minutes` : "—";
    const submitBtn = document.getElementById("booking-summary-submit-btn");
    if (submitBtn) submitBtn.disabled = !(hasDept && hasDoctor && hasDate && hasTime);
  },

  enableSubmitButtons() { const btn1 = document.getElementById("booking-submit-btn"); const btn2 = document.getElementById("booking-summary-submit-btn"); if (btn1) btn1.disabled = false; if (btn2) btn2.disabled = false; },
  disableSubmitButtons() { const btn1 = document.getElementById("booking-submit-btn"); const btn2 = document.getElementById("booking-summary-submit-btn"); if (btn1) btn1.disabled = true; if (btn2) btn2.disabled = true; },
};

window.BookingWizard = BookingWizard;
