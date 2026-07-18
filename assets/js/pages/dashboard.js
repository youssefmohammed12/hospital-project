/**
 * HealthBridge — Patient Dashboard JavaScript
 * Handles: profile display, stats, upcoming visits, booking wizard, rating modal, history.
 *
 * Uses shared helpers from main.js:
 *   - filterData(items, query, fields) — generic array filtering
 *   - renderTable(tbodyId, items, rowFn, emptyMsg, colSpan) — table rendering
 *   - apiFetch(url, options, errorMsg) — fetch with error handling
 *   - initTabNavigation(storageKey) — sidebar tab system
 *   - escapeHTML(), formatDate(), showToast(), getUser(), saveUser()
 *
 * Used by: dashboard.html
 */

"use strict";

// Module-level state
let allAppointments = [];
let appointmentDoctors = [];
let currentRatingAppointmentId = null;
let currentRatingStars = 0;
let lastSlotRequestId = 0;
let _summaryLocked = false; // Prevent summary updates after booking completion

/* ============================================================
   BOOKING STATE MANAGER
   Single source of truth for booking state
   ============================================================ */

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

  get department() { return this.state.department; },
  get doctor() { return this.state.doctor; },
  get doctorId() { return this.state.doctorId; },
  get doctorData() { return this.state.doctorData; },
  get date() { return this.state.date; },
  get time() { return this.state.time; },
  get timeLabel() { return this.state.timeLabel; },
  get step() { return this.state.step; },
  get dirty() { return this.state.dirty; },
  get restoring() { return this.state.restoring; },
  get resetting() { return this.state.resetting; },
  get completed() { return this.state.completed; },
};


/* ============================================================
   DRAFT MANAGER
   Centralized draft persistence with debouncing
   ============================================================ */

const DraftManager = {
  STORAGE_KEY: "healthbridge_booking_draft",
  SAVE_DELAY: 300,
  saveTimer: null,

  save(state) {
    // Never save while restoring, resetting, or after booking is completed
    if (state.restoring || state.resetting || state.completed) {
      console.log("Draft save blocked - restoring/resetting/completed");
      return;
    }

    // Also check sessionStorage flag for completed booking (persists across page loads)
    if (sessionStorage.getItem("bookingCompleted") === "1") {
      console.log("Draft save blocked - booking completed flag in sessionStorage");
      return;
    }

    // Capture current state values to avoid race conditions
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
        console.log("Draft saved:", draftData);
      } catch (e) {
        console.error("Failed to save draft:", e);
      }
    }, this.SAVE_DELAY);
  },

  clear() {
    try {
      localStorage.removeItem(this.STORAGE_KEY);
      clearTimeout(this.saveTimer);
      console.log("Draft cleared");
    } catch (e) {
      console.error("Failed to clear draft:", e);
    }
  },

  load() {
    try {
      const raw = localStorage.getItem(this.STORAGE_KEY);
      if (!raw) return null;
      return JSON.parse(raw);
    } catch (e) {
      console.error("Failed to load draft:", e);
      return null;
    }
  },
};

/* ============================================================
   LEAVE PROTECTION MANAGER
   Centralized beforeunload handler management
   ============================================================ */

const LeaveProtectionManager = {
  isEnabled: false,

  enable() {
    if (this.isEnabled) return;
    window.onbeforeunload = function (e) {
      e.preventDefault();
      e.returnValue = "";
    };
    this.isEnabled = true;
    console.log("Leave protection enabled");
  },

  disable() {
    window.onbeforeunload = null;
    this.isEnabled = false;
    console.log("Leave protection disabled");
  },

  update(state) {
    // Show warning ONLY when booking has actual unsaved changes
    // Do NOT warn if: booking completed, booking cancelled, booking reset, page freshly loaded
    if (state.dirty && !state.completed && !state.resetting) {
      this.enable();
    } else {
      this.disable();
    }
  },
};

document.addEventListener("DOMContentLoaded", async () => {
  requireAuth();

  const user = getUser();
  if (!user) return;

  // Redirect non-patients to their correct dashboard
  if (user.role === "admin") {
    window.location.href = getBasePath() + "pages/admin/admin.html";
    return;
  }
  if (user.role === "doctor") {
    window.location.href = getBasePath() + "pages/doctor/doctor-dashboard.html";
    return;
  }

  // Populate profile info across the page
  setText("sidebar-name", user.name || "Patient");
  setText("sidebar-email", user.email || "");
  setText("patient-profile-name", user.name || "Patient");
  setText("patient-profile-email", user.email || "");
  const patientWelcome = document.getElementById("patient-welcome-name");
  if (patientWelcome)
    patientWelcome.innerHTML = `<i class="fas fa-chart-line" aria-hidden="true"></i> Welcome, ${escapeHTML(user.name || "Patient")}`;

  // Sidebar avatar initials
  const sidebarAvatar = document.querySelector(".sidebar-avatar");
  if (sidebarAvatar && user.name) {
    const initials = user.name
      .split(" ")
      .map((n) => n[0])
      .join("")
      .substring(0, 2)
      .toUpperCase();
    sidebarAvatar.textContent = initials || "??";
  }

  // Initialize all subsystems
  initMedicalRecordTab();
  initPatientPrescriptionsTab();
  initTabNavigation("hb_patient_active_tab");
  initBookingWizard();
  initRatingModal();
  initDashboardMessages();
  await loadPatientDashboardData();

  // Sync with server (silent failure — localStorage is fallback)
  try {
    await getSessionUser();
  } catch {
    /* ignore */
  }

  // If a booking was just completed, do NOT restore draft
  if (sessionStorage.getItem("bookingCompleted") === "1") {
    console.log("Booking completed flag found — skipping draft restore");
    // Do NOT remove the flag here - it will be removed when user starts new booking
  } else {
    // Restore saved booking draft after everything is initialized
    setTimeout(() => {
      BookingStateManager.setRestoring(true);
      restoreDraft();
      BookingStateManager.setRestoring(false);
    }, 300);
  }
});

/** Helper: set element text by ID */
function setText(id, text) {
  const el = document.getElementById(id);
  if (el) el.textContent = text;
}

/* ============================================================
   TAB NAVIGATION (shortcut functions)
   ============================================================ */

function switchToBookTab() {
  document.querySelector('.sidebar-nav a[href="#book"]')?.click();
}

function switchToHistoryTab() {
  document.querySelector('.sidebar-nav a[href="#history"]')?.click();
}

/* ============================================================
   APPOINTMENTS — Load, filter, render
   ============================================================ */

async function loadPatientDashboardData() {
  const totalEl = document.getElementById("total-appointments");
  const upcomingEl = document.getElementById("upcoming-appointments");

  if (upcomingEl) {
    upcomingEl.innerHTML =
      '<p style="color:var(--text-muted);text-align:center;padding:var(--s4)">Loading appointments...</p>';
  }

  const result = await apiFetch(
    (getBasePath() + "api/appointments/get.php"),
    {},
    "Failed to load appointments.",
  );
  if (!result.ok) {
    if (upcomingEl)
      upcomingEl.innerHTML =
        '<p style="color:var(--danger);text-align:center;padding:var(--s4)">Failed to load appointments.</p>';
    return;
  }

  allAppointments =
    result.data.appointments || (Array.isArray(result.data) ? result.data : []);
  if (totalEl) totalEl.textContent = allAppointments.length;

  // Upcoming = today or future, not cancelled — sorted by date/time, max 3
  const todayStr = new Date().toISOString().split("T")[0];
  const upcoming = allAppointments
    .filter(
      (a) => (a.date || "") >= todayStr && (a.status || "") !== "Cancelled",
    )
    .sort((a, b) => `${a.date} ${a.time}`.localeCompare(`${b.date} ${b.time}`))
    .slice(0, 3);

  renderUpcoming(upcoming, upcomingEl);
  filterPatientAppointments(); // Refresh history table
}

/** Render upcoming appointments cards */
function renderUpcoming(upcoming, container) {
  if (!container) return;
  if (!upcoming.length) {
    container.innerHTML =
      '<p style="color:var(--text-muted);text-align:center;padding:var(--s4)">No upcoming appointments.</p>';
    return;
  }
  container.innerHTML = upcoming
    .map(
      (a) => `
    <div class="card" style="margin-bottom:var(--s4);padding:var(--s5)">
      <div class="flex-between flex-wrap gap-4">
        <div>
          <span class="badge" style="margin-bottom:var(--s2)">${escapeHTML(a.department || "Consultation")}</span>
          <h4 style="margin:0;font-size:1rem">${escapeHTML(a.doctor || "-")}</h4>
          <p style="font-size:0.82rem;margin-top:var(--s2);color:var(--text-secondary)">
            ${escapeHTML(a.date || "-")} at ${escapeHTML(formatApptTime(a))}
          </p>
        </div>
        <span class="status status-${escapeHTML((a.status || "pending").toLowerCase())}">${escapeHTML(a.status || "Pending")}</span>
      </div>
    </div>
  `,
    )
    .join("");
}

/** Filter history by search query and status */
function filterPatientAppointments() {
  const query = document.getElementById("patient-appt-search")?.value || "";
  const status = document.getElementById("patient-status-filter")?.value || "";

  let filtered = allAppointments;
  filtered = filterData(filtered, query, ["doctor", "department", "notes"]);
  if (status) {
    filtered = filtered.filter(
      (a) => (a.status || "").toLowerCase() === status.toLowerCase(),
    );
  }

  renderPatientHistory(filtered);
}

/** Render appointment history table */
function renderPatientHistory(appointments) {
  const today = new Date();
  today.setHours(0, 0, 0, 0);

  renderTable(
    "appointment-history",
    appointments,
    (a) => {
      const appointmentDate = new Date(a.date);
      appointmentDate.setHours(0, 0, 0, 0);
      const canRate = appointmentDate < today && a.status === "Confirmed";

      const actionCell = canRate
        ? `<button class="btn btn-outline btn-sm" onclick="openRatingModal(${a.id}, '${escapeHTML(a.doctor || "Doctor")}')">Rate</button>`
        : '<span style="color:var(--text-muted)">-</span>';

      return `
        <tr>
          <td>${escapeHTML(a.doctor || "-")}</td>
          <td>${escapeHTML(a.department || "-")}</td>
          <td>${escapeHTML(a.date || "-")}</td>
          <td>${escapeHTML(formatApptTime(a))}</td>
          <td><span class="status status-${escapeHTML((a.status || "pending").toLowerCase())}">${escapeHTML(a.status || "Pending")}</span></td>
          <td>${actionCell}</td>
        </tr>`;
    },
    "No appointments found.",
    6,
  );
}

function refreshPatientPage() {
  showToast("Refreshing data...", "info");
  loadPatientDashboardData();
}

/* ============================================================
   BOOKING WIZARD
   Guided step-based booking flow
   ============================================================ */

/* ── LOAD SLOTS ─────────────────────────────────────────── */

// Load slots from server with race condition protection
async function loadSlots() {
  const doctorId = BookingStateManager.doctorId;
  const date = BookingStateManager.date;
  if (!doctorId || !date) return;

  const slotContainer = document.getElementById("slot-picker-container");
  const timeInput = document.getElementById("appt-time");

  // Increment request counter to cancel stale responses
  const requestId = ++lastSlotRequestId;

  hideSlotSummary();

  // Show skeleton inside slotContainer
  if (slotContainer) {
    slotContainer.innerHTML = `
      <div class="slot-skeleton-area">
        <div class="slot-skeleton-row">
          <div class="slot-skeleton-chip"></div>
          <div class="slot-skeleton-chip"></div>
          <div class="slot-skeleton-chip"></div>
          <div class="slot-skeleton-chip"></div>
          <div class="slot-skeleton-chip"></div>
          <div class="slot-skeleton-chip"></div>
        </div>
        <div class="slot-skeleton-row">
          <div class="slot-skeleton-chip"></div>
          <div class="slot-skeleton-chip"></div>
          <div class="slot-skeleton-chip"></div>
          <div class="slot-skeleton-chip"></div>
          <div class="slot-skeleton-chip"></div>
          <div class="slot-skeleton-chip"></div>
        </div>
      </div>`;
  }
  if (timeInput) timeInput.value = "";
  BookingStateManager.update({
    time: "",
    timeLabel: "",
  });

  let result;
  try {
    result = await apiFetch(
      (getBasePath() + "api/appointments/get-available-slots.php"),
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        credentials: "same-origin",
        body: JSON.stringify({ doctor_id: doctorId, date: date }),
      },
      "Failed to load available slots.",
    );
  } catch (err) {
    if (requestId !== lastSlotRequestId) return;
    if (slotContainer) {
      slotContainer.innerHTML =
        '<p class="slot-placeholder" style="color:var(--danger);font-size:0.85rem">Failed to load slots. Please try again.</p>';
    }
    return;
  }

  // Ignore stale responses
  if (requestId !== lastSlotRequestId) return;

  if (!result.ok || !result.data?.success) {
    if (slotContainer) {
      slotContainer.innerHTML =
        '<p class="slot-placeholder" style="color:var(--danger);font-size:0.85rem">Failed to load slots.</p>';
    }
    return;
  }

  const slots = result.data.slots || [];
  const message = result.data.message || "";
  const duration = result.data.duration || 30;

  // Ignore stale responses
  if (requestId !== lastSlotRequestId) return;

  if (slots.length === 0) {
    if (slotContainer) {
      slotContainer.innerHTML = `
        <div class="slot-empty-card">
          <div class="slot-empty-icon"><i class="fas fa-calendar" aria-hidden="true"></i></div>
          <h4 class="slot-empty-title">No Available Slots</h4>
          <p class="slot-empty-desc">${escapeHTML(message || "No appointment slots are available for this date. Try selecting another day.")}</p>
        </div>`;
    }
    return;
  }

  // Ignore stale responses
  if (requestId !== lastSlotRequestId) return;

  // Group slots by period
  const groups = { Morning: [], Afternoon: [], Evening: [] };
  for (const slot of slots) {
    const hour = parseInt(slot.time.split(":")[0], 10);
    if (hour < 12) groups.Morning.push(slot);
    else if (hour < 17) groups.Afternoon.push(slot);
    else groups.Evening.push(slot);
  }

  // Render ALL slot UI inside slotContainer as one complete HTML string
  let html = "";

  // Duration bar
  html += `
    <div class="slot-duration-bar">
      <i class="fas fa-clock" aria-hidden="true"></i>
      <span>Appointment Duration: <strong>${duration} Minutes</strong></span>
    </div>`;

  // Period tabs (type="button" prevents form submission)
  html += `
    <div class="slot-tabs" role="tablist">
      <button type="button" class="slot-tab active" role="tab" aria-selected="true" aria-controls="slot-panel-morning" onclick="switchSlotTab('morning')" tabindex="0">
        <i class="fas fa-sun" aria-hidden="true"></i> Morning <span class="slot-tab-count">${groups.Morning.length}</span>
      </button>
      <button type="button" class="slot-tab" role="tab" aria-selected="false" aria-controls="slot-panel-afternoon" onclick="switchSlotTab('afternoon')" tabindex="-1">
        <i class="fas fa-cloud-sun" aria-hidden="true"></i> Afternoon <span class="slot-tab-count" id="slot-count-afternoon">${groups.Afternoon.length}</span>
      </button>
      <button type="button" class="slot-tab" role="tab" aria-selected="false" aria-controls="slot-panel-evening" onclick="switchSlotTab('evening')" tabindex="-1">
        <i class="fas fa-moon" aria-hidden="true"></i> Evening <span class="slot-tab-count">${groups.Evening.length}</span>
      </button>
    </div>`;

  // Scroll area with panels
  html += `<div class="slot-scroll-area">`;

  const tabNames = ["morning", "afternoon", "evening"];
  const periodNames = ["Morning", "Afternoon", "Evening"];

  for (let i = 0; i < 3; i++) {
    const tabId = tabNames[i];
    const periodName = periodNames[i];
    const periodSlots = groups[periodName] || [];

    html += `<div class="slot-panel${i === 0 ? " active" : ""}" id="slot-panel-${tabId}" role="tabpanel">`;

    if (periodSlots.length === 0) {
      html += `
        <div class="slot-empty-card">
          <div class="slot-empty-icon"><i class="fas fa-calendar" aria-hidden="true"></i></div>
          <h4 class="slot-empty-title">No Available Slots</h4>
          <p class="slot-empty-desc">${escapeHTML(message || "No appointment slots are available for this time period.")}</p>
        </div>`;
    } else {
      html += `<div class="slot-grid" role="radiogroup" aria-label="${periodName} slots">`;
      for (const slot of periodSlots) {
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
      }
      html += `</div>`;
    }

    html += `</div>`;
  }

  html += `</div>`; // close slot-scroll-area

  // Ignore stale responses
  if (requestId !== lastSlotRequestId) return;

  // Write everything into slotContainer at once
  if (slotContainer) {
    slotContainer.innerHTML = html;
  }

  // Activate first tab with slots
  let firstActive = "morning";
  if (groups.Morning.length === 0) {
    if (groups.Afternoon.length > 0) firstActive = "afternoon";
    else if (groups.Evening.length > 0) firstActive = "evening";
  }
  switchSlotTab(firstActive);

  // Reset summary
  hideSlotSummary();

  // Keyboard nav for new chips
  slotContainer.querySelectorAll(".slot-chip").forEach((chip) => {
    chip.addEventListener("keydown", (e) => {
      if (e.key === "Enter" || e.key === " ") {
        e.preventDefault();
        chip.click();
      }
    });
  });

  // Disable submit until slot selected
  disableSubmitButtons();
}

function initBookingWizard() {
  const departmentInput = document.getElementById("appt-department");
  const doctorInput = document.getElementById("appt-doctor");
  const patientNameInput = document.getElementById("appt-patient");
  const notesInput = document.getElementById("appt-notes");
  const dateInput = document.getElementById("appt-date");
  const timeInput = document.getElementById("appt-time");
  const slotContainer = document.getElementById("slot-picker-container");
  const form = document.getElementById("booking-form");
  const currentUser = getUser();

  // Load departments dynamically
  if (typeof loadDepartmentsDropdown === 'function') {
    loadDepartmentsDropdown("appt-department", true);
  }

  // Pre-fill patient name
  if (patientNameInput && currentUser?.name && !patientNameInput.value) {
    patientNameInput.value = currentUser.name;
  }

  // Minimum date = today
  if (dateInput) {
    dateInput.min = new Date().toISOString().split("T")[0];
  }

  // Populate doctor dropdown based on selected department
  function renderDoctorOptions() {
    if (!doctorInput) return;
    const department = departmentInput?.value || "";
    const doctorsToShow = department
      ? appointmentDoctors.filter((d) => {
          // Use department_name if available, fall back to specialty for backward compatibility
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

    // Reset doctor state
    BookingStateManager.update({
      doctor: "",
      doctorId: 0,
      doctorData: null,
    });
    clearDoctorPreview();
    clearDateAndSlots();
  }

  function clearDoctorPreview() {
    const container = document.getElementById("doctor-preview-container");
    if (container) container.innerHTML = "";
  }

  function clearDateAndSlots() {
    if (dateInput) dateInput.value = "";
    if (timeInput) timeInput.value = "";
    BookingStateManager.update({
      date: "",
      time: "",
      timeLabel: "",
    });
    hideSlotSummary();
    hideSlotUI();
    updateSummary();
  }

  function hideSlotUI() {
    const durationBar = document.getElementById("slot-duration-bar");
    if (durationBar) durationBar.style.display = "none";
    const tabs = document.getElementById("slot-tabs");
    if (tabs) tabs.style.display = "none";
    const scrollArea = document.getElementById("slot-scroll-area");
    if (scrollArea) scrollArea.style.display = "none";
    if (slotContainer) {
      slotContainer.innerHTML =
        '<p class="slot-placeholder" style="color:var(--text-muted);font-size:0.85rem">Select a doctor and date to see available slots.</p>';
    }
  }

  // ── DEPARTMENT SELECTION ──
  departmentInput?.addEventListener("change", () => {
    const val = departmentInput.value;
    BookingStateManager.update({ department: val });
    renderDoctorOptions();
    clearDateAndSlots();

    // Enable step 1 next button
    const btn = document.getElementById("step-1-next");
    if (btn) btn.disabled = !val;

    // Update summary
    updateSummary();
    if (!BookingStateManager.restoring && !BookingStateManager.resetting) {
      // Reset completed flag and sessionStorage when user starts making new choices
      BookingStateManager.setCompleted(false);
      sessionStorage.removeItem("bookingCompleted");
      _summaryLocked = false; // Unlock summary for new booking
      BookingStateManager.setDirty(true);
      LeaveProtectionManager.update(BookingStateManager.state);
      DraftManager.save(BookingStateManager.state);
    }
  });

  // ── DOCTOR SELECTION ──
  doctorInput?.addEventListener("change", () => {
    const selectedName = doctorInput.value || "";
    const selected = appointmentDoctors.find((d) => d.name === selectedName);

    if (selectedName && selected) {
      BookingStateManager.update({
        doctor: selectedName,
        doctorId: selected.user_id || selected.id || 0,
        doctorData: selected,
      });
      renderDoctorPreview(selected);
      // Auto-set department
      if (selected.specialty && departmentInput) {
        departmentInput.value = selected.specialty;
        BookingStateManager.update({ department: selected.specialty });
      }
      // Enable step 2 next button
      const btn = document.getElementById("step-2-next");
      if (btn) btn.disabled = false;
      // Reset date/slots
      clearDateAndSlots();
    } else {
      BookingStateManager.update({
        doctor: "",
        doctorId: 0,
        doctorData: null,
      });
      clearDoctorPreview();
      const btn = document.getElementById("step-2-next");
      if (btn) btn.disabled = true;
      clearDateAndSlots();
    }
    updateSummary();
    if (!BookingStateManager.restoring && !BookingStateManager.resetting) {
      // Unlock summary for new booking
      _summaryLocked = false;
      BookingStateManager.setDirty(true);
      LeaveProtectionManager.update(BookingStateManager.state);
      DraftManager.save(BookingStateManager.state);
    }
  });

  // ── DATE SELECTION ──
  dateInput?.addEventListener("change", () => {
    const val = dateInput.value;
    BookingStateManager.update({ date: val });
    if (val && BookingStateManager.doctorId) {
      // Enable step 3 next button
      const btn = document.getElementById("step-3-next");
      if (btn) btn.disabled = false;
      // Load slots
      loadSlots();
    } else {
      const btn = document.getElementById("step-3-next");
      if (btn) btn.disabled = true;
      hideSlotUI();
    }
    updateSummary();
    if (!BookingStateManager.restoring && !BookingStateManager.resetting) {
      // Unlock summary for new booking
      _summaryLocked = false;
      BookingStateManager.setDirty(true);
      LeaveProtectionManager.update(BookingStateManager.state);
      DraftManager.save(BookingStateManager.state);
    }
  });

  // ── FORM SUBMISSION ──
  form.addEventListener("submit", async (e) => {
    e.preventDefault();

    const selectedTime = timeInput?.value || "";
    if (!selectedTime) {
      showToast("Please select an available time slot.", "error");
      return;
    }

    const submitBtn = form.querySelector('[type="submit"]');
    const originalText = submitBtn?.textContent || "Confirm Booking";
    setLoading(submitBtn, true, "Booking...");

    const data = {
      department: BookingStateManager.department || departmentInput?.value || "",
      doctor: BookingStateManager.doctor || doctorInput?.value || "",
      date: BookingStateManager.date || dateInput?.value || "",
      time: selectedTime,
      patientName: patientNameInput?.value || "",
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
      setLoading(submitBtn, false, originalText);
      return;
    }

    const result = await apiFetch(
      (getBasePath() + "api/appointments/book.php"),
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(data),
      },
      "Booking service unavailable.",
    );

    if (result.data?.success) {
      showToast("Appointment booked successfully!", "success");
      // Set flag BEFORE form.reset() to prevent change events from queuing draft saves
      BookingStateManager.setResetting(true);
      // Clear any pending draft save timers immediately
      clearTimeout(DraftManager.saveTimer);
      // Clear draft from localStorage immediately
      DraftManager.clear();
      // Disable leave protection immediately
      LeaveProtectionManager.disable();

      // Lock summary to prevent any updates BEFORE clearing
      _summaryLocked = true;

      // Clear summary content SYNCHRONOUSLY with form reset
      const emptyState = document.getElementById("summary-empty-state");
      const content = document.getElementById("summary-content");
      const detailsContainer = document.getElementById("summary-details-container");
      const slotSummaryWrapper = document.getElementById("slot-summary-wrapper");
      const summaryDoctorName = document.getElementById("summary-doctor-name");
      const summaryDateText = document.getElementById("summary-date-text");
      const summaryTimeText = document.getElementById("summary-time-text");

      if (emptyState) emptyState.style.display = "flex";
      if (content) content.style.display = "none";
      if (detailsContainer) detailsContainer.innerHTML = "";
      if (slotSummaryWrapper) slotSummaryWrapper.classList.remove("visible");
      if (summaryDoctorName) summaryDoctorName.textContent = "";
      if (summaryDateText) summaryDateText.textContent = "";
      if (summaryTimeText) summaryTimeText.textContent = "";

      // Reset form manually to ensure all fields are cleared
      form.reset();
      // Clear all input fields manually
      const departmentInput = document.getElementById("appt-department");
      const doctorInput = document.getElementById("appt-doctor");
      const dateInput = document.getElementById("appt-date");
      const timeInput = document.getElementById("appt-time");
      const notesInput = document.getElementById("appt-notes");
      if (departmentInput) departmentInput.value = "";
      if (doctorInput) {
        doctorInput.value = "";
        doctorInput.innerHTML = '<option value="">Select a doctor</option>';
      }
      if (dateInput) dateInput.value = "";
      if (timeInput) timeInput.value = "";
      if (notesInput) notesInput.value = "";
      if (patientNameInput && currentUser?.name)
        patientNameInput.value = currentUser.name;
      // Clear UI elements immediately
      clearDoctorPreview();
      hideSlotUI();
      hideSlotSummary();
      disableSubmitButtons();
      document.querySelectorAll(".slot-chip.selected").forEach((c) => {
        c.classList.remove("selected");
        c.setAttribute("aria-checked", "false");
      });
      // Reset step indicators
      document.querySelectorAll(".booking-step").forEach((el) => el.classList.remove("active"));
      const step1 = document.getElementById("booking-step-1");
      if (step1) step1.classList.add("active");
      document.querySelectorAll(".booking-step-indicator").forEach((ind) => {
        const s = parseInt(ind.dataset.step);
        ind.classList.remove("completed", "active", "locked");
        if (s === 1) ind.classList.add("active");
        else ind.classList.add("locked");
      });
      // Reset step buttons
      const step1Next = document.getElementById("step-1-next");
      if (step1Next) step1Next.disabled = true;
      const step2Next = document.getElementById("step-2-next");
      if (step2Next) step2Next.disabled = true;
      const step3Next = document.getElementById("step-3-next");
      if (step3Next) step3Next.disabled = true;
      // Reset state
      BookingStateManager.reset();
      BookingStateManager.setDirty(false);
      BookingStateManager.setCompleted(true);
      sessionStorage.setItem("bookingCompleted", "1");
      BookingStateManager.setResetting(false);

      await loadPatientDashboardData();
      switchToHistoryTab();
    } else {
      showToast(result.data?.message || "Booking failed.", "error");
    }
    setLoading(submitBtn, false, originalText);
  });

  // Load doctors
  fetch((getBasePath() + "api/doctors/get.php"))
    .then((res) => res.json())
    .then((result) => {
      const data = result.doctors || result;
      appointmentDoctors =
        Array.isArray(data) && data.length ? data : DOCTORS_FALLBACK;
      renderDoctorOptions();
    })
    .catch(() => {
      appointmentDoctors = DOCTORS_FALLBACK;
      renderDoctorOptions();
    });
}

/* ── DOCTOR PREVIEW ────────────────────────────────────── */

function renderDoctorPreview(doctor) {
  const container = document.getElementById("doctor-preview-container");
  if (!container) return;

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
  const duration = doctor.duration ? doctor.duration + " min" : "30 min";

  container.innerHTML = `
    <div class="doctor-preview-card">
      <div class="doctor-preview-avatar">${initials}</div>
      <div class="doctor-preview-body">
        <h4 class="doctor-preview-name">${escapeHTML(doctor.name || "Doctor")}</h4>
        <p class="doctor-preview-specialty">${escapeHTML(doctor.specialty || "General Practice")}</p>
        <div class="doctor-preview-meta">
          <span><i class="fas fa-star" aria-hidden="true"></i> ${escapeHTML(String(rating))} Rating</span>
          <span><i class="fas fa-clock" aria-hidden="true"></i> ${escapeHTML(duration)}</span>
          <span><i class="fas fa-calendar-week" aria-hidden="true"></i> ${escapeHTML(workingDays)}</span>
        </div>
        <span class="doctor-preview-badge ${availClass}">
          <i class="fas ${isAvail ? "fa-check-circle" : "fa-times-circle"}" aria-hidden="true"></i>
          ${availText}
        </span>
      </div>
    </div>
  `;
}

/* ── STEP NAVIGATION ───────────────────────────────────── */

function goToStep(step) {
  // Validate current step
  if (step < 1 || step > 4) return;

  // Hide all steps
  document.querySelectorAll(".booking-step").forEach((el) => {
    el.classList.remove("active");
  });

  // Show target step
  const targetStep = document.getElementById(`booking-step-${step}`);
  if (targetStep) targetStep.classList.add("active");

  // Update progress indicators
  document.querySelectorAll(".booking-step-indicator").forEach((indicator) => {
    const s = parseInt(indicator.dataset.step);
    indicator.classList.remove("active", "completed", "locked");
    if (s === step) {
      indicator.classList.add("active");
    } else if (s < step) {
      indicator.classList.add("completed");
    } else {
      indicator.classList.add("locked");
    }
  });

  BookingStateManager.update({ step: step });

  // Smooth scroll to step
  const bookingWizard = document.querySelector(".booking-wizard");
  if (bookingWizard) {
    setTimeout(() => {
      bookingWizard.scrollIntoView({ behavior: "smooth", block: "start" });
    }, 50);
  }

  // If going to step 4, ensure slots are loaded
  if (step === 4 && BookingStateManager.doctorId && BookingStateManager.date) {
    const dateInput = document.getElementById("appt-date");
    if (dateInput && dateInput.value) {
      // Slots should already be loaded from date change
    }
  }

  if (!BookingStateManager.restoring && !BookingStateManager.resetting) {
    DraftManager.save(BookingStateManager.state);
  }
}

window.goToStep = goToStep;

/* ── SLOT SELECTION ────────────────────────────────────── */

function selectSlot(btn) {
  // Deselect all
  document.querySelectorAll(".slot-chip.selected").forEach((c) => {
    c.classList.remove("selected");
    c.setAttribute("aria-checked", "false");
  });

  // Select this
  btn.classList.add("selected");
  btn.setAttribute("aria-checked", "true");

  // Update hidden input
  const timeInput = document.getElementById("appt-time");
  if (timeInput) {
    timeInput.value = btn.dataset.time;
  }

  BookingStateManager.update({
    time: btn.dataset.time,
    timeLabel: btn.dataset.label || btn.querySelector(".slot-label")?.textContent || "",
  });

  // Update sticky summary
  updateSlotSummary(btn);

  // Enable submit buttons
  enableSubmitButtons();

  // Update right column summary
  updateSummary();

  if (!BookingStateManager.restoring && !BookingStateManager.resetting) {
    // Unlock summary for new booking
    _summaryLocked = false;
    BookingStateManager.setDirty(true);
    LeaveProtectionManager.update(BookingStateManager.state);
    DraftManager.save(BookingStateManager.state);
  }
}

window.selectSlot = selectSlot;

function updateSlotSummary(btn) {
  const wrapper = document.getElementById("slot-summary-wrapper");
  if (!wrapper) return;

  const doctorName =
    BookingStateManager.doctor || document.getElementById("appt-doctor")?.value || "";
  const dateVal =
    BookingStateManager.date || document.getElementById("appt-date")?.value || "";
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
  } catch (e) {
    /* fallback */
  }

  document.getElementById("summary-doctor-name").textContent = doctorName;
  document.getElementById("summary-date-text").textContent = dateDisplay;
  document.getElementById("summary-time-text").textContent = slotLabel;
  wrapper.classList.add("visible");
}

function hideSlotSummary() {
  const wrapper = document.getElementById("slot-summary-wrapper");
  if (wrapper) wrapper.classList.remove("visible");
}

/* ── TAB SWITCHING ─────────────────────────────────────── */

function switchSlotTab(tabId) {
  document.querySelectorAll(".slot-tab").forEach((tab) => {
    const isActive = tab.id === `slot-tab-${tabId}`;
    tab.classList.toggle("active", isActive);
    tab.setAttribute("aria-selected", isActive ? "true" : "false");
    tab.setAttribute("tabindex", isActive ? "0" : "-1");
  });

  document.querySelectorAll(".slot-panel").forEach((panel) => {
    panel.classList.toggle("active", panel.id === `slot-panel-${tabId}`);
  });
}

window.switchSlotTab = switchSlotTab;

/* ── SUMMARY (RIGHT COLUMN) ────────────────────────────── */

function updateSummary() {
  // Prevent summary updates if locked (after booking completion)
  if (_summaryLocked) {
    console.log("Summary update blocked - summary is locked");
    return;
  }

  const emptyState = document.getElementById("summary-empty-state");
  const content = document.getElementById("summary-content");
  const detailsContainer = document.getElementById("summary-details-container");

  if (!emptyState || !content || !detailsContainer) return;

  const hasDept = BookingStateManager.department;
  const hasDoctor = BookingStateManager.doctor && BookingStateManager.doctorData;
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

  if (hasDate) {
    let dateDisplay = BookingStateManager.date;
    try {
      const d = new Date(BookingStateManager.date + "T12:00:00");
      const options = {
        weekday: "long",
        year: "numeric",
        month: "long",
        day: "numeric",
      };
      dateDisplay = d.toLocaleDateString("en-US", options);
    } catch (e) {
      /* fallback */
    }
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
    // Calculate end time
    let endTime = "";
    try {
      const [h, m] = BookingStateManager.time.split(":").map(Number);
      const totalMin = h * 60 + m + duration;
      const endH = Math.floor(totalMin / 60) % 24;
      const endM = totalMin % 60;
      const ampm = endH >= 12 ? "PM" : "AM";
      const displayH = endH % 12 || 12;
      endTime = ` – ${displayH}:${String(endM).padStart(2, "0")} ${ampm}`;
    } catch (e) {
      /* fallback */
    }

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

  // Enable/disable submit button
  const submitBtn = document.getElementById("booking-summary-submit-btn");
  if (submitBtn) {
    submitBtn.disabled = !(hasDept && hasDoctor && hasDate && hasTime);
  }
}

function enableSubmitButtons() {
  const btn1 = document.getElementById("booking-submit-btn");
  const btn2 = document.getElementById("booking-summary-submit-btn");
  if (btn1) btn1.disabled = false;
  if (btn2) btn2.disabled = false;
}

function disableSubmitButtons() {
  const btn1 = document.getElementById("booking-submit-btn");
  const btn2 = document.getElementById("booking-summary-submit-btn");
  if (btn1) btn1.disabled = true;
  if (btn2) btn2.disabled = true;
}

function resetBookingWizard() {
  BookingStateManager.reset();
  document
    .querySelectorAll(".booking-step")
    .forEach((el) => el.classList.remove("active"));
  const step1 = document.getElementById("booking-step-1");
  if (step1) step1.classList.add("active");
  document.querySelectorAll(".booking-step-indicator").forEach((ind) => {
    const s = parseInt(ind.dataset.step);
    ind.classList.remove("completed", "active", "locked");
    if (s === 1) ind.classList.add("active");
    else ind.classList.add("locked");
  });
  clearDoctorPreview();
  hideSlotUI();
  hideSlotSummary();
  disableSubmitButtons();
  const step1Next = document.getElementById("step-1-next");
  if (step1Next) step1Next.disabled = true;
  const step2Next = document.getElementById("step-2-next");
  if (step2Next) step2Next.disabled = true;
  const step3Next = document.getElementById("step-3-next");
  if (step3Next) step3Next.disabled = true;
  // Reset date min
  const dateInput = document.getElementById("appt-date");
  if (dateInput) dateInput.min = new Date().toISOString().split("T")[0];
  // Force summary update to clear any displayed values
  updateSummary();
  // Clear slot selection visual state
  document.querySelectorAll(".slot-chip.selected").forEach((c) => {
    c.classList.remove("selected");
    c.setAttribute("aria-checked", "false");
  });
}

/* ── CLICK HANDLER FOR SUMMARY SUBMIT ──────────────────── */

document.addEventListener("DOMContentLoaded", () => {
  const summarySubmitBtn = document.getElementById(
    "booking-summary-submit-btn",
  );
  if (summarySubmitBtn) {
    summarySubmitBtn.addEventListener("click", () => {
      const form = document.getElementById("booking-form");
      if (form) form.requestSubmit();
    });
  }
});

/* ============================================================
   RATING MODAL
   Star rating with hover, click, and review text
   ============================================================ */

function initRatingModal() {
  const overlay = document.getElementById("rating-modal-overlay");
  const closeBtn = document.getElementById("rating-modal-close");
  const cancelBtn = document.getElementById("rating-modal-cancel");
  const submitBtn = document.getElementById("rating-modal-submit");
  const reviewText = document.getElementById("rating-review");
  const reviewCount = document.getElementById("review-count");
  const starIcons = document.querySelectorAll(".star-icon");

  if (closeBtn) closeBtn.onclick = closeRatingModal;
  if (cancelBtn) cancelBtn.onclick = closeRatingModal;
  if (overlay)
    overlay.onclick = (e) => {
      if (e.target === overlay) closeRatingModal();
    };

  starIcons.forEach((star) => {
    star.style.color = "var(--text-muted)";
    star.style.transition = "color 0.2s";

    star.onmouseover = () =>
      highlightStars(parseInt(star.getAttribute("data-rating")));
    star.onmouseout = () => highlightStars(currentRatingStars);
    star.onclick = () => {
      currentRatingStars = parseInt(star.getAttribute("data-rating"));
      highlightStars(currentRatingStars);
      if (submitBtn) submitBtn.disabled = currentRatingStars === 0;
    };
  });

  if (reviewText) {
    reviewText.oninput = () => {
      if (reviewCount) reviewCount.textContent = reviewText.value.length;
    };
  }

  if (submitBtn) submitBtn.onclick = submitRating;
}

function highlightStars(count) {
  document.querySelectorAll(".star-icon").forEach((s, i) => {
    s.style.color = i < count ? "var(--primary)" : "var(--text-muted)";
  });
  const ratingValue = document.getElementById("rating-value");
  if (ratingValue) {
    ratingValue.textContent =
      count === 0
        ? "Select a rating"
        : `${count} star${count !== 1 ? "s" : ""}`;
  }
}

async function openRatingModal(appointmentId, doctorName) {
  currentRatingAppointmentId = appointmentId;
  currentRatingStars = 0;

  const overlay = document.getElementById("rating-modal-overlay");
  const doctorNameEl = document.getElementById("rating-doctor-name");
  const reviewText = document.getElementById("rating-review");
  const reviewCount = document.getElementById("review-count");
  const messageEl = document.getElementById("rating-message");
  const submitBtn = document.getElementById("rating-modal-submit");

  if (doctorNameEl)
    doctorNameEl.textContent = `Rate your experience with ${doctorName}`;
  if (reviewText) reviewText.value = "";
  if (reviewCount) reviewCount.textContent = "0";
  if (messageEl) {
    messageEl.style.display = "none";
    messageEl.textContent = "";
  }
  if (submitBtn) {
    submitBtn.textContent = "Submit Rating";
    submitBtn.disabled = true;
  }

  highlightStars(0);

  try {
    const res = await fetch(
      `${getBasePath()}api/doctors/get-rating.php?appointmentId=${appointmentId}`,
      { credentials: "same-origin" },
    );
    const result = await res.json();

    if (result.rating) {
      currentRatingStars = result.rating.stars;
      if (reviewText) reviewText.value = result.rating.review || "";
      if (reviewCount)
        reviewCount.textContent = (result.rating.review || "").length;
      highlightStars(currentRatingStars);
      if (submitBtn) {
        submitBtn.textContent = "Already Rated";
        submitBtn.disabled = true;
      }
      if (messageEl) {
        messageEl.className = "alert-info";
        messageEl.textContent = "You have already rated this appointment.";
        messageEl.style.display = "block";
      }
    }
  } catch (e) {
    console.error("Error checking existing rating:", e);
  }

  overlay?.classList.add("open");
}

function closeRatingModal() {
  document.getElementById("rating-modal-overlay")?.classList.remove("open");
  currentRatingAppointmentId = null;
  currentRatingStars = 0;
}

async function submitRating() {
  if (!currentRatingAppointmentId || currentRatingStars === 0) {
    showToast("Please select a rating.", "error");
    return;
  }

  const reviewText = document.getElementById("rating-review")?.value || "";
  const submitBtn = document.getElementById("rating-modal-submit");
  const originalText = submitBtn?.textContent || "Submit Rating";
  setLoading(submitBtn, true, "Submitting...");

  const result = await apiFetch(
    (getBasePath() + "api/doctors/submit-rating.php"),
    {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        appointment_id: currentRatingAppointmentId,
        stars: currentRatingStars,
        review: reviewText,
      }),
    },
    "Rating service unavailable.",
  );

  if (result.data?.success) {
    showToast(
      "Thank you for rating! Your feedback helps us improve.",
      "success",
    );
    closeRatingModal();
    await loadPatientDashboardData();
  } else {
    showToast(result.data?.message || "Failed to submit rating.", "error");
  }
  setLoading(submitBtn, false, originalText);
}

window.openRatingModal = openRatingModal;

/* ============================================================
   PRESCRIPTIONS TAB
   ============================================================ */

function initPatientPrescriptionsTab() {
  const user = getUser();
  if (!user) return;

  document
    .querySelectorAll(".sidebar-nav a[href='#prescriptions']")
    .forEach((link) => {
      link.addEventListener("click", () => {
        setTimeout(() => loadPatientPrescriptions(), 100);
      });
    });

  const savedTab = localStorage.getItem("hb_patient_active_tab") || "overview";
  if (savedTab === "prescriptions") {
    setTimeout(() => loadPatientPrescriptions(), 150);
  }
}

async function loadPatientPrescriptions() {
  const result = await apiFetch(
    (getBasePath() + "api/prescriptions/get.php"),
    {},
    "Failed to load prescriptions",
  );
  if (!result.ok || !result.data?.success) {
    showToast(result.data?.message || "Failed to load prescriptions", "error");
    return;
  }

  const prescriptions = result.data.prescriptions || [];
  Prescriptions.renderList(
    prescriptions,
    "patient-prescriptions-list",
    "patient",
  );
}

/* ============================================================
   MEDICAL RECORD TAB
   ============================================================ */

function initMedicalRecordTab() {
  const user = getUser();
  if (!user) return;

  document
    .querySelectorAll(".sidebar-nav a[href='#medical-record']")
    .forEach((link) => {
      link.addEventListener("click", () => {
        MedicalRecords.init(user.id, "patient", "medical-record-container");
      });
    });

  const savedTab = localStorage.getItem("hb_patient_active_tab") || "overview";
  if (savedTab === "medical-record") {
    setTimeout(() => {
      MedicalRecords.init(user.id, "patient", "medical-record-container");
    }, 50);
  }
}

/* ============================================================
   SUPPORT MESSAGING SYSTEM
   ============================================================ */

function initDashboardMessages() {
  const form = document.getElementById("dashboard-message-form");
  if (!form) return;

  form.addEventListener("submit", async (e) => {
    e.preventDefault();

    const subjectInput = document.getElementById("dashboard-msg-subject");
    const messageInput = document.getElementById("dashboard-msg-body");
    const phoneInput = document.getElementById("dashboard-msg-phone");
    const deptInput = document.getElementById("dashboard-msg-dept");
    const submitBtn = form.querySelector('[type="submit"]');

    const subject = subjectInput?.value.trim() || "";
    const message = messageInput?.value.trim() || "";
    const phone = phoneInput?.value.trim() || "";
    const department = deptInput?.value || "General Inquiry";

    if (!subject || !message) {
      showToast("Subject and message are required.", "error");
      return;
    }
    if (message.length < 10) {
      showToast("Message must be at least 10 characters.", "error");
      return;
    }

    const user = getUser();
    if (!user) return;

    setLoading(submitBtn, true, "Sending...");

    const result = await apiFetch(
      (getBasePath() + "api/settings/contact.php"),
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          name: user.name,
          email: user.email,
          phone: phone || undefined,
          department: department,
          subject: subject,
          message: message,
        }),
      },
      "Failed to send message.",
    );

    if (result.ok && (result.data?.success || result.data?.id)) {
      showToast("Message sent to Support successfully!", "success");
      form.reset();
      loadUserMessages();
    } else {
      showToast(
        result.data?.message || "Failed to send message. Please try again.",
        "error",
      );
    }
    setLoading(submitBtn, false, "Send to Support");
  });

  if (
    window.location.hash === "#messages" ||
    localStorage.getItem("hb_patient_active_tab") === "messages"
  ) {
    loadUserMessages();
  }

  document
    .querySelectorAll(".sidebar-nav a[href='#messages']")
    .forEach((link) => {
      link.addEventListener("click", loadUserMessages);
    });
}


function restoreDraft() {
  const draft = DraftManager.load();
  if (!draft) return;

  console.log("Restoring booking draft", draft);

  const departmentInput = document.getElementById("appt-department");
  const doctorInput = document.getElementById("appt-doctor");
  const dateInput = document.getElementById("appt-date");
  const timeInput = document.getElementById("appt-time");

  // Restore department if available — directly set value, do NOT dispatch event
  if (draft.department && departmentInput) {
    const deptOption = [...departmentInput.options].find(
      (o) => o.value === draft.department,
    );
    if (deptOption) {
      departmentInput.value = draft.department;
      BookingStateManager.update({ department: draft.department });
      // Enable step 1 next button
      const step1Next = document.getElementById("step-1-next");
      if (step1Next) step1Next.disabled = false;
      // Update summary after department is restored
      updateSummary();
      // Directly populate doctor dropdown instead of dispatching change event
      const doctorSelect = document.getElementById("appt-doctor");
      if (doctorSelect) {
        const department = departmentInput.value || "";
        const doctorsToShow = department
          ? appointmentDoctors.filter((d) => {
              const deptName = d.department_name || d.specialty || "";
              return deptName === department;
            })
          : appointmentDoctors;
        doctorSelect.innerHTML = '<option value="">Select a doctor</option>';
        doctorsToShow.forEach((d) => {
          const label = `${d.name}${d.available == 0 ? " (Not available)" : ""}`;
          const option = new Option(label, d.name);
          option.disabled = d.available == 0;
          if (d.id) option.value = d.name;
          option.dataset.specialty = d.specialty;
          doctorSelect.add(option);
        });
      }
    }
  }

  // Restore doctor — directly set value, do NOT dispatch event
  if (draft.doctor && draft.doctorId && doctorInput) {
    const tryRestoreDoctor = () => {
      const docOption = [...doctorInput.options].find(
        (o) => o.value === draft.doctor,
      );
      if (docOption && !docOption.disabled) {
        doctorInput.value = draft.doctor;
        BookingStateManager.update({
          doctor: draft.doctor,
          doctorId: draft.doctorId,
        });
        const selected = appointmentDoctors.find(
          (d) => d.name === draft.doctor,
        );
        if (selected) {
          BookingStateManager.update({ doctorData: selected });
          renderDoctorPreview(selected);
          if (selected.specialty && departmentInput) {
            departmentInput.value = selected.specialty;
            BookingStateManager.update({ department: selected.specialty });
          }
        }
        const btn = document.getElementById("step-2-next");
        if (btn) btn.disabled = false;
        // Update summary after doctor is restored
        updateSummary();
        return true;
      }
      if (appointmentDoctors.length > 0 && !docOption) {
        showToast(
          "Your previously selected doctor is no longer available. Please choose another.",
          "info",
        );
      }
      return false;
    };

    if (appointmentDoctors.length > 0) {
      setTimeout(tryRestoreDoctor, 100);
    } else {
      const checkInterval = setInterval(() => {
        if (appointmentDoctors.length > 0) {
          clearInterval(checkInterval);
          setTimeout(tryRestoreDoctor, 100);
        }
      }, 200);
      setTimeout(() => clearInterval(checkInterval), 10000);
    }
  }

  // Restore date — directly set value, do NOT dispatch event
  if (draft.date && dateInput) {
    const today = new Date().toISOString().split("T")[0];
    if (draft.date >= today) {
      const tryRestoreDate = () => {
        if (BookingStateManager.doctorId && dateInput) {
          dateInput.value = draft.date;
          BookingStateManager.update({ date: draft.date });
          const btn = document.getElementById("step-3-next");
          if (btn) btn.disabled = false;
          // Load slots after date is restored with a small delay to ensure state is ready
          setTimeout(() => loadSlots(), 100);
          // Update summary after date is restored
          updateSummary();
        }
      };
      let attempts = 0;
      const dateInterval = setInterval(() => {
        attempts++;
        if (BookingStateManager.doctorId) {
          clearInterval(dateInterval);
          tryRestoreDate();
        } else if (attempts > 30) {
          clearInterval(dateInterval);
        }
      }, 200);
    } else {
      showToast(
        "Your previously selected date has passed. Please choose another.",
        "info",
      );
    }
  }

  // Restore step — call goToStep (restoring flag prevents draft save)
  if (draft.step && draft.step > 1 && draft.step <= 4) {
    setTimeout(() => {
      goToStep(draft.step);
    }, 500);
  }

  // Restore time after slots load — directly select slot without triggering draft save
  if (draft.time && draft.timeLabel) {
    const restoreTimeout = setTimeout(() => {
      const chips = document.querySelectorAll(".slot-chip");
      for (const chip of chips) {
        if (chip.dataset.time === draft.time) {
          // Manually select slot without triggering save
          document.querySelectorAll(".slot-chip.selected").forEach((c) => {
            c.classList.remove("selected");
            c.setAttribute("aria-checked", "false");
          });
          chip.classList.add("selected");
          chip.setAttribute("aria-checked", "true");
          const timeInput = document.getElementById("appt-time");
          if (timeInput) {
            timeInput.value = chip.dataset.time;
          }
          BookingStateManager.update({
            time: chip.dataset.time,
            timeLabel: chip.dataset.label || chip.querySelector(".slot-label")?.textContent || "",
          });
          updateSlotSummary(chip);
          enableSubmitButtons();
          updateSummary();
          break;
        }
      }
    }, 1500);

    const observer = new MutationObserver(() => {
      const chips = document.querySelectorAll(".slot-chip");
      for (const chip of chips) {
        if (chip.dataset.time === draft.time) {
          clearTimeout(restoreTimeout);
          observer.disconnect();
          // Manually select slot without triggering save
          document.querySelectorAll(".slot-chip.selected").forEach((c) => {
            c.classList.remove("selected");
            c.setAttribute("aria-checked", "false");
          });
          chip.classList.add("selected");
          chip.setAttribute("aria-checked", "true");
          const timeInput = document.getElementById("appt-time");
          if (timeInput) {
            timeInput.value = chip.dataset.time;
          }
          BookingStateManager.update({
            time: chip.dataset.time,
            timeLabel: chip.dataset.label || chip.querySelector(".slot-label")?.textContent || "",
          });
          updateSlotSummary(chip);
          enableSubmitButtons();
          updateSummary();
          break;
        }
      }
    });
    observer.observe(document.body, { childList: true, subtree: true });
    setTimeout(() => observer.disconnect(), 10000);
  }
}

/* ── CANCEL CONFIRMATION ───────────────────────────────── */

function confirmBookingReset() {
  const hasProgress =
    BookingStateManager.department ||
    BookingStateManager.doctor ||
    BookingStateManager.date ||
    BookingStateManager.time;

  if (!hasProgress) {
    DraftManager.clear();
    return true;
  }

  const overlay = document.createElement("div");
  overlay.className = "modal-overlay";
  overlay.style.cssText =
    "display:flex;align-items:center;justify-content:center;z-index:1000;";

  overlay.innerHTML = `
    <div class="modal" style="max-width:400px;padding:var(--s6);text-align:center">
      <h3 style="margin-bottom:var(--s3)">Discard Progress?</h3>
      <p style="color:var(--text-secondary);margin-bottom:var(--s55);font-size:0.9rem">
        Discard your current booking progress?
      </p>
      <div style="display:flex;gap:var(--s3);justify-content:center">
        <button class="btn btn-outline" id="confirm-cancel-continue">Continue Editing</button>
        <button class="btn btn-primary" id="confirm-cancel-discard" style="background:var(--danger)">Discard</button>
      </div>
    </div>
  `;

  document.body.appendChild(overlay);

  return new Promise((resolve) => {
    overlay.querySelector("#confirm-cancel-continue").onclick = () => {
      overlay.remove();
      resolve(false);
    };
    overlay.querySelector("#confirm-cancel-discard").onclick = () => {
      DraftManager.clear();
      BookingStateManager.setDirty(false);
      LeaveProtectionManager.update(BookingStateManager.state);
      overlay.remove();
      resolve(true);
    };
    overlay.onclick = (e) => {
      if (e.target === overlay) {
        overlay.remove();
        resolve(false);
      }
    };
  });
}

async function loadUserMessages() {
  const listContainer = document.getElementById("support-messages-list");
  if (!listContainer) return;

  listContainer.innerHTML =
    '<p class="text-center" style="color: var(--text-muted); padding: var(--s8)">Loading history...</p>';

  const result = await apiFetch(
    (getBasePath() + "api/settings/get-user-messages.php"),
    {},
    "Failed to load support history.",
  );
  if (!result.ok || !result.data?.success) {
    listContainer.innerHTML =
      '<p class="text-center" style="color: var(--danger); padding: var(--s8)">Failed to load history.</p>';
    return;
  }

  const messages = result.data.messages || [];
  if (!messages.length) {
    listContainer.innerHTML =
      '<p class="text-center" style="color: var(--text-muted); padding: var(--s8)">No messages sent yet. Contact support using the form on the left.</p>';
    return;
  }

  listContainer.innerHTML = messages
    .map((m) => {
      const isReplied = !!m.reply;
      const statusText = isReplied
        ? '<i class="fas fa-circle-check" aria-hidden="true"></i> Replied'
        : '<i class="fas fa-hourglass-half" aria-hidden="true"></i> Awaiting Reply';
      const statusColor = isReplied ? "var(--success)" : "var(--warning)";

      const metaLine = [
        m.department
          ? `<span style="background:rgba(34,211,238,0.1);border:1px solid rgba(34,211,238,0.25);color:var(--primary);padding:1px 8px;border-radius:99px;font-size:0.68rem;font-weight:600"><i class="fas fa-hospital" aria-hidden="true"></i> ${escapeHTML(m.department)}</span>`
          : "",
        m.phone
          ? `<span style="font-size:0.68rem;color:var(--text-muted)"><i class="fas fa-phone" aria-hidden="true"></i> ${escapeHTML(m.phone)}</span>`
          : "",
      ]
        .filter(Boolean)
        .join(" ");

      const replyBlock = isReplied
        ? `
      <div style="margin-top:var(--s4); border-top: 1px solid var(--border-light); padding-top: var(--s4)">
        <div style="display:flex; align-items:center; gap:var(--s2); margin-bottom:var(--s3)">
          <div style="width:28px;height:28px;border-radius:50%;background:rgba(34,211,238,0.15);border:1px solid rgba(34,211,238,0.3);display:flex;align-items:center;justify-content:center;font-size:0.7rem"><i class="fas fa-desktop" aria-hidden="true"></i></div>
          <div>
            <div style="font-weight:700;font-size:0.8rem;color:var(--primary)">Support</div>
            <div style="font-size:0.7rem;color:var(--text-muted)">${formatDate(m.replied_at)}</div>
          </div>
        </div>
        <div style="background:rgba(34,211,238,0.06);border:1px solid rgba(34,211,238,0.15);border-radius:var(--r-md);padding:var(--s4)">
          <p style="margin:0;font-size:0.87rem;line-height:1.6;color:var(--text-primary);white-space:pre-wrap">${escapeHTML(m.reply)}</p>
        </div>
      </div>`
        : `
      <div style="margin-top:var(--s4); border-top:1px solid var(--border-light); padding-top:var(--s3)">
        <p style="margin:0;font-size:0.78rem;color:var(--text-muted);font-style:italic"><i class="fas fa-hourglass-half" aria-hidden="true"></i> Our support team will reply to your message soon. You will see the reply here.</p>
      </div>`;

      return `
    <div style="border:1px solid var(--border-light);border-radius:var(--r-lg);overflow:hidden;background:var(--bg-card)">
      <div style="padding:var(--s4) var(--s5);background:linear-gradient(135deg,rgba(34,211,238,0.05),transparent);border-bottom:1px solid var(--border-light);display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:var(--s2)">
        <div>
          <div style="font-weight:700;font-size:0.95rem;color:var(--text-primary)">${escapeHTML(m.subject || "No Subject")}</div>
          <div style="font-size:0.72rem;color:var(--text-muted);margin-top:2px">Sent on ${formatDate(m.created_at)}</div>
          ${metaLine ? `<div style="display:flex;align-items:center;gap:var(--s2);margin-top:var(--s2);flex-wrap:wrap">${metaLine}</div>` : ""}
        </div>
        <span style="font-size:0.72rem;font-weight:600;color:${statusColor};background:${statusColor}15;border:1px solid ${statusColor}40;padding:3px 10px;border-radius:99px;white-space:nowrap">${statusText}</span>
      </div>

      <div style="padding:var(--s5)">
        <div style="display:flex;align-items:center;gap:var(--s2);margin-bottom:var(--s3)">
          <div style="width:28px;height:28px;border-radius:50%;background:var(--bg-surface);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:0.7rem"><i class="fas fa-user" aria-hidden="true"></i></div>
          <div>
            <div style="font-weight:700;font-size:0.8rem;color:var(--text-primary)">You</div>
            <div style="font-size:0.7rem;color:var(--text-muted)">${escapeHTML(m.email)}</div>
          </div>
        </div>
        <div style="background:var(--bg-surface);border:1px solid var(--border-light);border-radius:var(--r-md);padding:var(--s4)">
          <p style="margin:0;font-size:0.87rem;line-height:1.6;color:var(--text-primary);white-space:pre-wrap">${escapeHTML(m.message)}</p>
        </div>

        ${replyBlock}
      </div>
    </div>`;
    })
    .join("");
}


