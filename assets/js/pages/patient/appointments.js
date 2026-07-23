/**
 * HealthBridge — Patient Appointments Page Controller
 * Manages the appointments page with search, filters, pagination, and actions.
 */

const AppointmentsPage = {
  data: null,
  currentPage: 1,
  perPage: 10,
  currentFilter: "all",
  searchQuery: "",
  cancelingAppointmentId: null,

  // ── Initialization ────────────────────────────────────────
  async init() {
    await this.load();
    this.attachEventListeners();
    this.initBookingWizard();
    // Check for URL parameters
    const urlParams = new URLSearchParams(window.location.search);
    const viewId = urlParams.get("view");
    const bookParam = urlParams.get("book");
    const doctorParam = urlParams.get("doctor");
    const departmentParam = urlParams.get("department");

    if (viewId) {
      // Wait for data to load, then open details modal
      setTimeout(() => {
        this.viewDetails(parseInt(viewId));
      }, 100);
    }

    // Handle deep link from doctors page: ?book=1&doctor=X&department=Y
    if (bookParam === "1" && doctorParam) {
      setTimeout(() => {
        if (typeof BookingWizard !== "undefined") {
          BookingWizard.open();
          // Pre-select department if provided
          if (departmentParam) {
            const deptInput = document.getElementById("appt-department");
            if (deptInput) {
              // Wait for departments to load, then set value
              const trySetDept = () => {
                const options = [...deptInput.options];
                const match = options.find(o => o.value === departmentParam || o.textContent.trim() === departmentParam);
                if (match) {
                  deptInput.value = match.value;
                  deptInput.dispatchEvent(new Event("change", { bubbles: true }));
                  // Now pre-select doctor
                  setTimeout(() => {
                    const docInput = document.getElementById("appt-doctor");
                    if (docInput) {
                      const docOptions = [...docInput.options];
                      const docMatch = docOptions.find(o => o.textContent.trim() === doctorParam);
                      if (docMatch) {
                        docInput.value = docMatch.value;
                        docInput.dispatchEvent(new Event("change", { bubbles: true }));
                      }
                    }
                  }, 300);
                } else {
                  // Retry after departments load
                  setTimeout(trySetDept, 500);
                }
              };
              setTimeout(trySetDept, 500);
            }
          }
        }
      }, 200);
    }
  },

  // ── Load Appointments ─────────────────────────────────────
  async load() {
    this.showSkeleton();
    this.hideElement("appointments-grid");
    this.hideElement("appointments-empty");
    this.hideElement("appointments-error");
    this.hideElement("pagination-container");

    try {
      const params = new URLSearchParams({
        page: this.currentPage,
        limit: this.perPage,
        status: this.currentFilter,
        search: this.searchQuery,
      });

      const response = await fetch(
        `../../api/patient/appointments.php?${params}`,
      );
      const result = await response.json();

      if (!result.success) {
        throw new Error(result.message || "Failed to load appointments");
      }

      this.data = result.data;
      this.renderAppointments();
      this.renderPagination();
      this.updateFilterChips();
    } catch (error) {
      console.error("Load appointments error:", error);
      this.showError();
    }
  },

  // ── Render Appointments ────────────────────────────────────
  renderAppointments() {
    this.hideSkeleton();
    this.hideElement("appointments-error");

    const appointments = this.data.appointments;

    if (!appointments || appointments.length === 0) {
      this.showEmpty();
      this.hideElement("pagination-container");
      return;
    }

    this.hideElement("appointments-empty");
    this.showElement("appointments-grid");

    const grid = document.getElementById("appointments-grid");
    if (!grid) return;

    grid.innerHTML = appointments
      .map((appt) => this.renderAppointmentCard(appt))
      .join("");
  },

  // ── Render Single Appointment Card ─────────────────────────
  renderAppointmentCard(appt) {
    const statusColors = {
      upcoming: "var(--success)",
      completed: "var(--primary)",
      cancelled: "var(--danger)",
      missed: "var(--warning)",
      pending: "var(--warning)",
      reschedule_requested: "var(--warning)"
    };

    const statusLabels = {
      upcoming: "Confirmed",
      completed: "Completed",
      cancelled: "Cancelled",
      missed: "Missed",
      pending: "Pending",
      reschedule_requested: "Reschedule Requested"
    };

    // Override display for pending appointments (not yet approved by doctor)
    let displayStatus = statusLabels[appt.category] || appt.status;
    let displayColor = statusColors[appt.category] || "var(--text-muted)";
    if (appt.status === "Pending" || appt.category === "pending") {
      displayStatus = "Pending Doctor Confirmation";
      displayColor = "var(--warning)";
    }
    
    // Handle reschedule requested status
    if (appt.status === "Reschedule Requested" || appt.reschedule_status === "pending") {
      displayStatus = "🟡 Reschedule Requested";
      displayColor = "#f59e0b";
    }

    // Use computed display values that respect Pending status
    const statusColor = displayColor;
    const statusLabel = displayStatus;

    const initials = appt.doctor
      ? appt.doctor
          .split(" ")
          .map((n) => n[0])
          .join("")
          .substring(0, 2)
          .toUpperCase()
      : "DR";

    const dateStr = this.formatDate(appt.date);
    const timeStr = appt.time_range || appt.time;

    // Action buttons based on status
    let actions = "";
    if (appt.category === "upcoming") {
      actions = `
        <button class="btn btn-outline btn-sm" onclick="AppointmentsPage.viewDetails(${appt.id})">View Details</button>
        <button class="btn btn-outline btn-sm" onclick="AppointmentsPage.reschedule(${appt.id})">Reschedule</button>
        <button class="btn btn-outline btn-sm" style="color: var(--danger); border-color: rgba(252,165,165,0.3);" onclick="AppointmentsPage.initCancel(${appt.id})">Cancel</button>
      `;
    } else if (appt.category === "completed") {
      actions = `
        <button class="btn btn-outline btn-sm" onclick="AppointmentsPage.viewDetails(${appt.id})">View Details</button>
        <button class="btn btn-primary btn-sm" onclick="AppointmentsPage.reschedule(${appt.id})">Book Again</button>
        ${appt.can_rate ? `<button class="btn btn-primary btn-sm" onclick="AppointmentsPage.rateDoctor(${appt.id})">Rate Doctor</button>` : ""}
      `;
    } else if (appt.category === "cancelled") {
      actions = `
        <button class="btn btn-outline btn-sm" onclick="AppointmentsPage.viewDetails(${appt.id})">View Details</button>
        <button class="btn btn-primary btn-sm" onclick="AppointmentsPage.reschedule(${appt.id})">Book Again</button>
      `;
    } else {
      actions = `
        <button class="btn btn-outline btn-sm" onclick="AppointmentsPage.viewDetails(${appt.id})">View Details</button>
      `;
    }

    // Calendar button for upcoming appointments
    let calendarBtn = "";
    if (appt.category === "upcoming") {
      calendarBtn = `<button class="card-action-icon" onclick="AppointmentsPage.downloadICS(${appt.id})" title="Add to Calendar"><i class="fas fa-calendar-plus" aria-hidden="true"></i></button>`;
    }

    return `
      <div class="appointment-card" data-id="${appt.id}">
        <div class="appointment-card-left">
          <div class="doctor-avatar">${initials}</div>
          <div class="appointment-status-badge" style="background: ${statusColor}20; color: ${statusColor};">
            ${statusLabel}
          </div>
        </div>
        <div class="appointment-card-center">
          <h4 class="doctor-name">${this.escapeHTML(appt.doctor)}</h4>
          <p class="department-name">${this.escapeHTML(appt.department)}</p>
          <div class="appointment-meta">
            <span><i class="fas fa-calendar" aria-hidden="true"></i> ${dateStr}</span>
            <span><i class="fas fa-clock" aria-hidden="true"></i> ${timeStr}</span>
          </div>
          ${appt.notes ? `<p class="appointment-notes">${this.escapeHTML(appt.notes)}</p>` : ""}
        </div>
        <div class="appointment-card-right">
          <div class="appointment-actions">
            ${actions}
          </div>
          <div class="card-secondary-actions">
            ${calendarBtn}
          </div>
        </div>
      </div>
    `;
  },

  // ── Render Pagination ─────────────────────────────────────
  renderPagination() {
    const pagination = this.data.pagination;
    const totalPages = pagination.total_pages;

    if (totalPages <= 1) {
      this.hideElement("pagination-container");
      return;
    }

    this.showElement("pagination-container");

    const prevBtn = document.getElementById("prev-page");
    const nextBtn = document.getElementById("next-page");
    const pageNumbers = document.getElementById("page-numbers");

    if (prevBtn) {
      prevBtn.disabled = pagination.current_page <= 1;
    }

    if (nextBtn) {
      nextBtn.disabled = pagination.current_page >= totalPages;
    }

    if (pageNumbers) {
      let pages = [];
      const maxVisible = 5;
      let startPage = Math.max(
        1,
        pagination.current_page - Math.floor(maxVisible / 2),
      );
      let endPage = Math.min(totalPages, startPage + maxVisible - 1);

      if (endPage - startPage < maxVisible - 1) {
        startPage = Math.max(1, endPage - maxVisible + 1);
      }

      for (let i = startPage; i <= endPage; i++) {
        pages.push(
          `<button class="page-number ${i === pagination.current_page ? "active" : ""}" onclick="AppointmentsPage.goToPage(${i})">${i}</button>`,
        );
      }

      pageNumbers.innerHTML = pages.join("");
    }
  },

  // ── Update Filter Chips ────────────────────────────────────
  updateFilterChips() {
    const chips = document.querySelectorAll(".filter-chip");
    chips.forEach((chip) => {
      chip.classList.remove("active");
      if (chip.dataset.filter === this.currentFilter) {
        chip.classList.add("active");
      }
    });
  },

  // ── Event Listeners ────────────────────────────────────────
  attachEventListeners() {
    // Search input with debounce
    const searchInput = document.getElementById("appointments-search");
    if (searchInput) {
      let debounceTimer;
      searchInput.addEventListener("input", (e) => {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => {
          this.searchQuery = e.target.value.trim();
          this.currentPage = 1;
          this.load();
        }, 300);
      });
    }

    // Filter chips
    const filterChips = document.getElementById("filter-chips");
    if (filterChips) {
      filterChips.addEventListener("click", (e) => {
        if (e.target.classList.contains("filter-chip")) {
          this.currentFilter = e.target.dataset.filter;
          this.currentPage = 1;
          this.load();
        }
      });
    }

    // Pagination buttons
    const prevBtn = document.getElementById("prev-page");
    const nextBtn = document.getElementById("next-page");
    if (prevBtn)
      prevBtn.addEventListener("click", () =>
        this.goToPage(this.currentPage - 1),
      );
    if (nextBtn)
      nextBtn.addEventListener("click", () =>
        this.goToPage(this.currentPage + 1),
      );

    // Close modals when clicking outside the dialog
    document.addEventListener("mousedown", function(e) {
      // Cancel modal
      const modal = document.getElementById("cancel-modal");
      if (modal && modal.classList.contains("open")) {
        const dialog = modal.querySelector(".modal");
        if (dialog && !dialog.contains(e.target) && modal.contains(e.target)) {
          AppointmentsPage.closeCancelModal();
          return;
        }
      }
      // Appointment details modal
      const apptModal = document.getElementById("appointment-modal");
      if (apptModal && apptModal.classList.contains("open")) {
        const dialog = apptModal.querySelector(".modal");
        if (dialog && !dialog.contains(e.target) && apptModal.contains(e.target)) {
          AppointmentsPage.closeModal();
          return;
        }
      }
    });

    // Escape key handler for modals
    document.addEventListener("keydown", (e) => this.handleEscapeKey(e));
  },

  initBookingWizard() {
    if (typeof BookingWizard !== "undefined" && BookingWizard.init) {
      BookingWizard.init({
        onSuccess: () => this.load(),
      });
    }
  },

  // ── Navigation ─────────────────────────────────────────────
  goToPage(page) {
    this.currentPage = page;
    this.load();
  },

  // ── Appointment Actions ─────────────────────────────────────
  viewDetails(appointmentId) {
    const appt = this.data.appointments.find((a) => a.id === appointmentId);
    if (!appt) return;

    const modalBody = document.getElementById("appointment-modal-body");
    if (!modalBody) return;

    const initials = appt.doctor
      ? appt.doctor
          .split(" ")
          .map((n) => n[0])
          .join("")
          .substring(0, 2)
          .toUpperCase()
      : "DR";

    modalBody.innerHTML = `
      <div class="appointment-details">
        <div class="detail-header">
          <div class="detail-avatar">${initials}</div>
          <div class="detail-info">
            <h4>${this.escapeHTML(appt.doctor)}</h4>
            <p>${this.escapeHTML(appt.department)}</p>
          </div>
        </div>
        <div class="detail-section">
          <h5>Date & Time</h5>
          <p>${this.formatDate(appt.date)} at ${appt.time_range || appt.time}</p>
        </div>
        ${
          appt.notes
            ? `
          <div class="detail-section">
            <h5>Notes</h5>
            <p>${this.escapeHTML(appt.notes)}</p>
          </div>
        `
            : ""
        }
        <div class="detail-section">
          <h5>Status</h5>
          <p>${appt.status}</p>
        </div>
        <div class="detail-section">
          <h5>Booked On</h5>
          <p>${this.formatDate(appt.booked_at)}</p>
        </div>
        ${
          appt.category === "upcoming"
            ? `
          <div class="detail-actions">
            <button class="btn btn-outline" onclick="AppointmentsPage.closeModal()">Close</button>
            <button class="btn btn-primary" onclick="AppointmentsPage.closeModal();AppointmentsPage.reschedule(${appt.id})">Book Again</button>
          </div>
        `
            : ""
        }
      </div>
    `;

    const modal = document.getElementById("appointment-modal");
    if (modal) {
      modal.style.display = "flex";
      modal.classList.add("open");
      document.body.classList.add("modal-open");
    }
  },

  toggleBookModal() {
    if (typeof BookingWizard !== "undefined") {
      BookingWizard.toggleModal();
    }
  },

  // Close modal on escape key
  handleEscapeKey(e) {
    if (e.key === "Escape") {
      this.closeModal();
      const bookModal = document.getElementById("book-appointment-modal");
      if (bookModal && bookModal.style.display === "flex") {
        this.toggleBookModal();
      }
    }
  },


  async reschedule(appointmentId) {
    const appt = this.data.appointments.find((a) => a.id === appointmentId);
    if (!appt) return;

    // Use BookingWizard.openReschedule which locks department/doctor
    // and allows only date/time/reason changes
    if (typeof BookingWizard !== "undefined" && BookingWizard.openReschedule) {
      await BookingWizard.openReschedule(appt);
    } else {
      // Fallback: just open the modal
      this.toggleBookModal();
    }
  },

  rateDoctor(appointmentId) {
    const appt = this.data.appointments.find((a) => a.id === appointmentId);
    if (!appt) return;

    // Open the public doctors page and trigger the doctor detail modal for that doctor
    // The doctors page will show the doctor card; user can click to rate from there
    showToast("Opening doctor profile to leave a rating...", "info");
    window.location.href = `../../doctors.html?q=${encodeURIComponent(appt.doctor)}`;
  },

  // ── Cancel Appointment ─────────────────────────────────────
  initCancel(appointmentId) {
    const appt = this.data.appointments.find((a) => a.id === appointmentId);
    if (!appt) return;

    this.cancelingAppointmentId = appointmentId;

    const dateEl = document.getElementById("cancel-date");
    const timeEl = document.getElementById("cancel-time");
    const doctorNameEl = document.getElementById("cancel-doctor-name");
    const deptNameEl = document.getElementById("cancel-department-name");
    const initialsEl = document.getElementById("cancel-doctor-initials");

    if (dateEl) dateEl.textContent = this.formatDate(appt.date);
    if (timeEl) timeEl.textContent = appt.time_range || appt.time;
    if (doctorNameEl) doctorNameEl.textContent = appt.doctor || "Doctor";
    if (deptNameEl) deptNameEl.textContent = appt.department || "";
    if (initialsEl) {
      const initials = appt.doctor
        ? appt.doctor.split(" ").map(n => n[0]).join("").substring(0, 2).toUpperCase()
        : "DR";
      initialsEl.textContent = initials;
    }

    this.showCancelModal();
  },

  async confirmCancel() {
    if (!this.cancelingAppointmentId) return;

    try {
      const response = await fetch("../../api/patient/cancel-appointment.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ id: this.cancelingAppointmentId }),
      });

      const result = await response.json();

      if (!result.success) {
        throw new Error(result.message || "Failed to cancel appointment");
      }

      this.closeCancelModal();
      this.currentPage = 1;
      await this.load();
    } catch (error) {
      console.error("Cancel appointment error:", error);
      alert("Failed to cancel appointment. Please try again.");
    }
  },

  closeCancelModal() {
    this.cancelingAppointmentId = null;
    const modal = document.getElementById("cancel-modal");
    if (modal) {
      modal.classList.remove("open");
      modal.style.display = "none";
      document.body.classList.remove("modal-open");
    }
  },

  // ── Calendar Integration ────────────────────────────────────
  downloadICS(appointmentId) {
    const appt = this.data.appointments.find((a) => a.id === appointmentId);
    if (!appt) return;

    const startDate = new Date(appt.date + "T" + appt.time);
    const endDate = new Date(startDate.getTime() + 60 * 60 * 1000); // 1 hour duration

    const formatDateICS = (date) =>
      date.toISOString().replace(/[-:]/g, "").split(".")[0] + "Z";

    const icsContent = `
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//HealthBridge//Appointment//EN
BEGIN:VEVENT
UID:${appt.id}@healthbridge.com
DTSTAMP:${formatDateICS(new Date())}
DTSTART:${formatDateICS(startDate)}
DTEND:${formatDateICS(endDate)}
SUMMARY:Appointment with ${appt.doctor}
DESCRIPTION:Appointment at ${appt.department}
LOCATION:HealthBridge Hospital
END:VEVENT
END:VCALENDAR
    `.trim();

    const blob = new Blob([icsContent], {
      type: "text/calendar;charset=utf-8",
    });
    const link = document.createElement("a");
    link.href = URL.createObjectURL(blob);
    link.download = `appointment-${appt.id}.ics`;
    link.click();
  },

  // ── Modal Management ────────────────────────────────────────
  showModal() {
    const modal = document.getElementById("appointment-modal");
    if (modal) {
      modal.style.display = "flex";
      modal.classList.add("open");
      document.body.classList.add("modal-open");
    }
  },

  closeModal() {
    const modal = document.getElementById("appointment-modal");
    if (modal) {
      modal.classList.remove("open");
      modal.style.display = "none";
      document.body.classList.remove("modal-open");
    }
  },

  showCancelModal() {
    const modal = document.getElementById("cancel-modal");
    if (modal) {
      modal.style.display = "flex";
      modal.classList.add("open");
      document.body.classList.add("modal-open");
      // Backdrop close is handled via document mousedown listener
      // (attached once in attachEventListeners, since modal-overlay has pointer-events:none)
    }
  },

  // ── UI Helpers ──────────────────────────────────────────────
  showSkeleton() {
    this.showElement("appointments-skeleton");
  },

  hideSkeleton() {
    this.hideElement("appointments-skeleton");
  },

  showEmpty() {
    this.showElement("appointments-empty");
  },

  showError() {
    this.showElement("appointments-error");
  },

  showElement(id) {
    const el = document.getElementById(id);
    if (el) {
      if (el.classList.contains("appointments-grid")) {
        el.style.display = "grid";
      } else {
        el.style.display = "block";
      }
    }
  },

  hideElement(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = "none";
  },

  // ── Utility Functions ───────────────────────────────────────
  formatDate(dateStr) {
    if (!dateStr) return "-";
    const date = new Date(dateStr);
    return date.toLocaleDateString("en-US", {
      month: "short",
      day: "numeric",
      year: "numeric",
    });
  },

  escapeHTML(str) {
    if (!str) return "";
    const div = document.createElement("div");
    div.textContent = str;
    return div.innerHTML;
  },
};

// ── Initialize on DOM Ready ─────────────────────────────────
document.addEventListener("DOMContentLoaded", () => {
  AppointmentsPage.init();
});
