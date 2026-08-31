/**
 * HealthBridge — Patient Appointments Page Controller v4
 * Manages the appointments page with search, filters, pagination,
 * card rendering, modals (Details, Cancel, Rating), and actions.
 */

const AppointmentsPage = {
  data: null,
  currentPage: 1,
  perPage: 10,
  currentFilter: "all",
  searchQuery: "",
  cancelingAppointmentId: null,
  currentRatingAppointmentId: null,
  currentRatingStars: 0,

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
      setTimeout(() => {
        this.viewDetails(parseInt(viewId));
      }, 150);
    }

    // Handle deep link from doctors page: ?book=1&doctor=X&department=Y
    if (bookParam === "1" && doctorParam) {
      setTimeout(() => {
        if (typeof BookingWizard !== "undefined") {
          BookingWizard.open();
          if (departmentParam) {
            const deptInput = document.getElementById("appt-department");
            if (deptInput) {
              const trySetDept = () => {
                const options = [...deptInput.options];
                const match = options.find(o => o.value === departmentParam || o.textContent.trim() === departmentParam);
                if (match) {
                  deptInput.value = match.value;
                  deptInput.dispatchEvent(new Event("change", { bubbles: true }));
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

      const response = await fetch(`../../api/patient/appointments.php?${params}`);
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

    const appointments = this.data?.appointments;

    if (!appointments || appointments.length === 0) {
      this.showEmpty();
      this.hideElement("pagination-container");
      this.renderFeaturedUpcoming();
      return;
    }

    this.hideElement("appointments-empty");
    this.showElement("appointments-grid");

    const grid = document.getElementById("appointments-grid");
    if (!grid) return;

    grid.innerHTML = appointments
      .map((appt) => this.renderAppointmentCard(appt))
      .join("");

    this.renderFeaturedUpcoming();
  },

  // ── Featured Next Appointment Panel ────────────────────────
  renderFeaturedUpcoming() {
    const container = document.getElementById("featured-upcoming-container");
    if (!container) return;
    const apps = this.data?.appointments;
    if (!apps || apps.length === 0) {
      container.style.display = "none";
      return;
    }

    const confirmed = apps.find((a) => a.category === "confirmed");
    const pending = apps.find((a) => a.category === "upcoming");
    const upcoming = confirmed || pending;

    if (!upcoming) {
      container.style.display = "none";
      return;
    }

    container.style.display = "block";
    const d = upcoming.date ? this.formatDate(upcoming.date) : "";
    const t = upcoming.time_range || upcoming.time || "";

    container.innerHTML = `
      <div class="featured-upcoming-card">
        <div class="featured-icon"><i class="fas fa-calendar-check" aria-hidden="true"></i></div>
        <div class="featured-body">
          <div class="featured-label">Next Scheduled Appointment</div>
          <div class="featured-title">${this.escapeHTML(upcoming.doctor)}</div>
          <div class="featured-meta">
            <span><i class="fas fa-calendar" aria-hidden="true"></i> ${this.escapeHTML(d)}</span>
            <span><i class="fas fa-clock" aria-hidden="true"></i> ${this.escapeHTML(t)}</span>
            <span><i class="fas fa-building" aria-hidden="true"></i> ${this.escapeHTML(upcoming.department)}</span>
          </div>
        </div>
        <div class="featured-actions">
          <button class="btn btn-outline btn-sm" onclick="AppointmentsPage.viewDetails(${upcoming.id})">View Details</button>
          <button class="btn btn-primary btn-sm" onclick="AppointmentsPage.reschedule(${upcoming.id})">Reschedule</button>
        </div>
      </div>
    `;
  },

  // ── Render Single Appointment Card (Phase 4 Redesign) ──────
  renderAppointmentCard(appt) {
    // ── Status Resolution ──
    let badge = { label: "", color: "var(--primary)", icon: "fa-calendar" };

    if (appt.reschedule_status === "pending") {
      badge = { label: "Reschedule Requested", color: "#f59e0b", icon: "fa-clock" };
    } else if (appt.reschedule_status === "suggested") {
      badge = { label: "Reschedule Suggested", color: "#8b5cf6", icon: "fa-calendar-day" };
    } else if (appt.reschedule_status === "approved") {
      badge = { label: "Reschedule Approved", color: "#22c55e", icon: "fa-check-circle" };
    } else if (appt.reschedule_status === "rejected") {
      badge = { label: "Reschedule Declined", color: "#ef4444", icon: "fa-times-circle" };
    } else if (appt.category === "completed" || appt.workflow_status === "Completed") {
      badge = { label: "Completed", color: "#22c55e", icon: "fa-circle-check" };
    } else if (appt.category === "missed") {
      badge = { label: "Missed", color: "#6b7280", icon: "fa-user-slash" };
    } else if (appt.status === "Cancelled" || appt.category === "cancelled") {
      badge = { label: "Cancelled", color: "#ef4444", icon: "fa-ban" };
    } else if (appt.status === "Confirmed" || appt.category === "confirmed") {
      badge = { label: "Confirmed", color: "#3b82f6", icon: "fa-check" };
    } else if (appt.status === "Pending" || appt.category === "upcoming" || appt.category === "pending") {
      badge = { label: "Pending Confirmation", color: "#f59e0b", icon: "fa-hourglass-half" };
    } else {
      badge = { label: appt.status || "Scheduled", color: "var(--text-muted)", icon: "fa-circle-info" };
    }

    const statusColor = badge.color;
    const statusLabel = badge.label;

    const initials = appt.doctor
      ? appt.doctor.split(" ").map((n) => n[0]).join("").substring(0, 2).toUpperCase()
      : "DR";

    const dateStr = this.formatDate(appt.date);
    const timeStr = appt.time_range || appt.time;
    const specialty = appt.doctor_specialty || "";
    const duration = appt.duration ? `${appt.duration} min` : "30 min";

    // Reschedule Information Banner
    let rescheduleBanner = "";
    if (appt.reschedule_status === "pending" && appt.pending_reschedule_date) {
      const reqDate = this.formatDate(appt.pending_reschedule_date);
      const reqTime = appt.pending_reschedule_time || "";
      rescheduleBanner = `
        <div class="reschedule-banner reschedule-pending-banner">
          <i class="fas fa-clock" aria-hidden="true"></i>
          <div>
            <strong>Reschedule Request Pending:</strong> Requested ${reqDate}${reqTime ? " at " + reqTime : ""}
            ${appt.reschedule_reason ? `<span class="reschedule-reason-txt">Reason: ${this.escapeHTML(appt.reschedule_reason)}</span>` : ""}
          </div>
        </div>`;
    } else if (appt.reschedule_status === "suggested" && appt.pending_reschedule_date) {
      const sugDate = this.formatDate(appt.pending_reschedule_date);
      const sugTime = appt.pending_reschedule_time || "";
      rescheduleBanner = `
        <div class="reschedule-banner reschedule-suggested-banner">
          <i class="fas fa-user-doctor" aria-hidden="true"></i>
          <div>
            <strong>Doctor Suggested New Time:</strong> ${sugDate}${sugTime ? " at " + sugTime : ""}
          </div>
        </div>`;
    }

    // Actions derivation
    let actions = "";
    if (appt.reschedule_status === "suggested") {
      actions = `
        <button class="btn btn-outline btn-sm" onclick="AppointmentsPage.viewDetails(${appt.id})">View</button>
        <button class="btn btn-success btn-sm" onclick="AppointmentsPage.acceptSuggestion(${appt.id})"><i class="fas fa-check" aria-hidden="true"></i> Accept</button>
        <button class="btn btn-outline btn-sm btn-danger-text" onclick="AppointmentsPage.declineSuggestion(${appt.id})"><i class="fas fa-xmark" aria-hidden="true"></i> Decline</button>
      `;
    } else if (appt.reschedule_status === "pending") {
      actions = `
        <button class="btn btn-outline btn-sm" onclick="AppointmentsPage.viewDetails(${appt.id})">View</button>
        <button class="btn btn-outline btn-sm btn-danger-text" onclick="AppointmentsPage.cancelRescheduleRequest(${appt.id})">Cancel Request</button>
      `;
    } else if (appt.category === "completed" || appt.workflow_status === "Completed") {
      const ratingAction = appt.has_rating
        ? `<button class="btn btn-primary btn-sm" onclick="AppointmentsPage.viewRating(${appt.id})"><i class="fas fa-star" aria-hidden="true"></i> View Rating</button>`
        : (appt.can_rate ? `<button class="btn btn-primary btn-sm" onclick="AppointmentsPage.rateDoctor(${appt.id})"><i class="fas fa-star" aria-hidden="true"></i> Rate Visit</button>` : "");
      actions = `
        <button class="btn btn-outline btn-sm" onclick="AppointmentsPage.viewDetails(${appt.id})">View Details</button>
        ${ratingAction}
      `;
    } else if (appt.category === "missed" || appt.status === "Cancelled" || appt.category === "cancelled") {
      actions = `
        <button class="btn btn-outline btn-sm" onclick="AppointmentsPage.viewDetails(${appt.id})">View Details</button>
      `;
    } else if (appt.status === "Pending" || appt.category === "upcoming" || appt.category === "pending") {
      actions = `
        <button class="btn btn-outline btn-sm" onclick="AppointmentsPage.viewDetails(${appt.id})">View</button>
        <button class="btn btn-outline btn-sm btn-danger-text" onclick="AppointmentsPage.initCancel(${appt.id})">Cancel</button>
      `;
    } else if (appt.status === "Confirmed" || appt.category === "confirmed") {
      actions = `
        <button class="btn btn-outline btn-sm" onclick="AppointmentsPage.viewDetails(${appt.id})">View</button>
        <button class="btn btn-outline btn-sm" onclick="AppointmentsPage.reschedule(${appt.id})">Reschedule</button>
        <button class="btn btn-outline btn-sm btn-danger-text" onclick="AppointmentsPage.initCancel(${appt.id})">Cancel</button>
      `;
    } else {
      actions = `
        <button class="btn btn-outline btn-sm" onclick="AppointmentsPage.viewDetails(${appt.id})">View Details</button>
      `;
    }

    let calendarBtn = "";
    if (appt.category === "upcoming" || appt.category === "confirmed") {
      calendarBtn = `<button class="card-action-icon" onclick="AppointmentsPage.downloadICS(${appt.id})" title="Add to Calendar" aria-label="Add to calendar"><i class="fas fa-calendar-plus" aria-hidden="true"></i></button>`;
    }

    return `
      <article class="appointment-card" data-id="${appt.id}">
        <div class="appointment-card-left">
          <div class="doctor-avatar">${initials}</div>
        </div>
        <div class="appointment-card-center">
          <div class="appointment-card-header">
            <div class="appointment-card-title">
              <h3 class="doctor-name">${this.escapeHTML(appt.doctor)}</h3>
              <p class="doctor-specialty">${this.escapeHTML(specialty || appt.department || "")}</p>
            </div>
            <span class="appointment-status-badge" style="background: ${statusColor}18; color: ${statusColor}; border: 1px solid ${statusColor}33;">
              <i class="fas ${badge.icon}" aria-hidden="true"></i> ${statusLabel}
            </span>
          </div>
          <div class="department-name"><i class="fas fa-building" aria-hidden="true"></i> ${this.escapeHTML(appt.department)}</div>
          <div class="appointment-meta">
            <span><i class="fas fa-calendar" aria-hidden="true"></i> ${dateStr}</span>
            <span><i class="fas fa-clock" aria-hidden="true"></i> ${timeStr}</span>
            <span><i class="fas fa-hourglass-half" aria-hidden="true"></i> ${duration}</span>
          </div>
          ${appt.notes ? `<div class="appointment-notes"><i class="fas fa-notes-medical" aria-hidden="true"></i> ${this.escapeHTML(appt.notes)}</div>` : ""}
          ${rescheduleBanner}
        </div>
        <div class="appointment-card-right">
          <div class="appointment-actions">
            ${actions}
          </div>
          <div class="card-secondary-actions">
            ${calendarBtn}
          </div>
        </div>
      </article>
    `;
  },

  // ── Appointment Details Modal ──────────────────────────────
  viewDetails(appointmentId) {
    const appt = this.data?.appointments?.find((a) => a.id === appointmentId);
    if (!appt) return;

    const modalBody = document.getElementById("appointment-modal-body");
    if (!modalBody) return;

    const initials = appt.doctor
      ? appt.doctor.split(" ").map((n) => n[0]).join("").substring(0, 2).toUpperCase()
      : "DR";

    const dateStr = this.formatDate(appt.date);
    const timeStr = appt.time_range || appt.time;
    const statusColor = appt.status === "Confirmed" ? "var(--primary)" : appt.status === "Completed" ? "var(--success)" : appt.status === "Cancelled" ? "var(--danger)" : "var(--warning)";

    modalBody.innerHTML = `
      <div class="appointment-details">
        <div class="detail-header">
          <div class="detail-avatar">${initials}</div>
          <div class="detail-info">
            <h4>${this.escapeHTML(appt.doctor)}</h4>
            <p>${this.escapeHTML(appt.department)} • ${this.escapeHTML(appt.doctor_specialty || "Specialist")}</p>
          </div>
          <span class="appt-status-pill" style="background:${statusColor}18;color:${statusColor};border:1px solid ${statusColor}33">
            ${this.escapeHTML(appt.status)}
          </span>
        </div>

        <div class="detail-section">
          <h5><i class="fas fa-calendar-day" aria-hidden="true"></i> Date &amp; Time</h5>
          <p>${dateStr} at ${timeStr}</p>
        </div>

        <div class="detail-section">
          <h5><i class="fas fa-hospital" aria-hidden="true"></i> Location &amp; Room</h5>
          <p>HealthBridge Main Hospital Building • ${this.escapeHTML(appt.department)} Department</p>
        </div>

        ${appt.notes ? `
        <div class="detail-section">
          <h5><i class="fas fa-clipboard-list" aria-hidden="true"></i> Medical Notes / Symptoms</h5>
          <p>${this.escapeHTML(appt.notes)}</p>
        </div>` : ""}

        ${appt.reschedule_reason ? `
        <div class="detail-section">
          <h5><i class="fas fa-clock-rotate-left" aria-hidden="true"></i> Reschedule Reason</h5>
          <p>${this.escapeHTML(appt.reschedule_reason)}</p>
        </div>` : ""}

        <div class="detail-section">
          <h5><i class="fas fa-hashtag" aria-hidden="true"></i> Reference Info</h5>
          <p>Appointment ID: #${appt.id} ${appt.booked_at ? `• Booked on ${this.formatDate(appt.booked_at)}` : ""}</p>
        </div>

        <div class="detail-actions">
          <button class="btn btn-outline" onclick="AppointmentsPage.closeModal()">Close</button>
          ${appt.status === "Confirmed" ? `<button class="btn btn-primary" onclick="AppointmentsPage.closeModal();AppointmentsPage.reschedule(${appt.id})">Reschedule</button>` : ""}
        </div>
      </div>
    `;

    this.showModal();
  },

  // ── View Rating Modal (For already-rated appointments) ──────
  async viewRating(appointmentId) {
    const appt = this.data.appointments.find((a) => a.id === appointmentId);
    if (!appt) return;

    try {
      const response = await fetch(getBasePath() + `api/doctors/get-rating.php?appointmentId=${appointmentId}`, { credentials: "same-origin" });
      const result = await response.json();
      if (!result.success || !result.rating) {
        showToast("No rating found for this appointment.", "error");
        return;
      }
      const r = result.rating;
      const starsHtml = Array.from({ length: 5 }, (_, i) =>
        `<i class="fas fa-star" style="color:${i < r.stars ? "#f59e0b" : "var(--border-light)"};font-size:1.4rem"></i>`
      ).join("");

      const modalBody = document.getElementById("appointment-modal-body");
      if (!modalBody) return;

      const initials = (appt.doctor || "DR").split(" ").map(n => n[0]).join("").substring(0, 2).toUpperCase();

      modalBody.innerHTML = `
        <div class="appointment-details">
          <div class="detail-header">
            <div class="detail-avatar">${initials}</div>
            <div class="detail-info">
              <h4>${this.escapeHTML(appt.doctor)}</h4>
              <p>${this.escapeHTML(appt.department)}</p>
            </div>
          </div>
          <div style="text-align:center;padding:var(--s6) 0">
            <div style="display:flex;gap:var(--s2);justify-content:center;margin-bottom:var(--s3)">${starsHtml}</div>
            <h4 style="font-size:1.3rem;font-weight:700;margin-bottom:var(--s2)">${r.stars} / 5 Stars</h4>
            ${r.review ? `<p style="color:var(--text-secondary);font-style:italic;padding:var(--s4);background:var(--bg-subtle);border-radius:var(--r-md);border:1px solid var(--border-light)">"${this.escapeHTML(r.review)}"</p>` : ""}
            <p style="font-size:0.8rem;color:var(--text-muted);margin-top:var(--s3)">Submitted on ${this.formatDate(r.created_at)}</p>
          </div>
          <div class="detail-actions">
            <button class="btn btn-outline" onclick="AppointmentsPage.closeModal()">Close</button>
          </div>
        </div>
      `;
      this.showModal();
    } catch (error) {
      console.error("View rating error:", error);
      showToast("Failed to load rating.", "error");
    }
  },

  // ── Render Pagination ─────────────────────────────────────
  renderPagination() {
    const pagination = this.data?.pagination;
    if (!pagination) return;

    const totalPages = pagination.total_pages;

    if (totalPages <= 1) {
      this.hideElement("pagination-container");
      return;
    }

    this.showElement("pagination-container");

    const prevBtn = document.getElementById("prev-page");
    const nextBtn = document.getElementById("next-page");
    const pageNumbers = document.getElementById("page-numbers");

    if (prevBtn) prevBtn.disabled = pagination.current_page <= 1;
    if (nextBtn) nextBtn.disabled = pagination.current_page >= totalPages;

    if (pageNumbers) {
      let pages = [];
      const maxVisible = 5;
      let startPage = Math.max(1, pagination.current_page - Math.floor(maxVisible / 2));
      let endPage = Math.min(totalPages, startPage + maxVisible - 1);

      if (endPage - startPage < maxVisible - 1) {
        startPage = Math.max(1, endPage - maxVisible + 1);
      }

      for (let i = startPage; i <= endPage; i++) {
        pages.push(
          `<button class="page-number ${i === pagination.current_page ? "active" : ""}" onclick="AppointmentsPage.goToPage(${i})">${i}</button>`
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
      chip.setAttribute("aria-selected", "false");
      if (chip.dataset.filter === this.currentFilter) {
        chip.classList.add("active");
        chip.setAttribute("aria-selected", "true");
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
        const btn = e.target.closest(".filter-chip");
        if (btn) {
          this.currentFilter = btn.dataset.filter;
          this.currentPage = 1;
          this.load();
        }
      });
    }

    // Pagination buttons
    const prevBtn = document.getElementById("prev-page");
    const nextBtn = document.getElementById("next-page");
    if (prevBtn) prevBtn.addEventListener("click", () => this.goToPage(this.currentPage - 1));
    if (nextBtn) nextBtn.addEventListener("click", () => this.goToPage(this.currentPage + 1));

    // Backdrop click handlers for modals
    document.addEventListener("mousedown", (e) => {
      const cancelModal = document.getElementById("cancel-modal");
      if (cancelModal && cancelModal.classList.contains("open")) {
        const dialog = cancelModal.querySelector(".modal");
        if (dialog && !dialog.contains(e.target) && cancelModal.contains(e.target)) {
          AppointmentsPage.closeCancelModal();
          return;
        }
      }
      const apptModal = document.getElementById("appointment-modal");
      if (apptModal && apptModal.classList.contains("open")) {
        const dialog = apptModal.querySelector(".modal");
        if (dialog && !dialog.contains(e.target) && apptModal.contains(e.target)) {
          AppointmentsPage.closeModal();
          return;
        }
      }
    });

    document.addEventListener("keydown", (e) => this.handleEscapeKey(e));

    // Rating modal event listeners
    const ratingOverlay = document.getElementById("rating-modal-overlay");
    const ratingCloseBtn = document.querySelector("[data-rating-close]");
    const ratingCancelBtn = document.querySelector("[data-rating-cancel]");
    const ratingSubmitBtn = document.getElementById("rating-modal-submit");
    const ratingReviewText = document.getElementById("rating-review");
    const ratingReviewCount = document.getElementById("review-count");
    const starIcons = document.querySelectorAll(".star-icon");

    if (ratingCloseBtn) ratingCloseBtn.addEventListener("click", () => this.closeRatingModal());
    if (ratingCancelBtn) ratingCancelBtn.addEventListener("click", () => this.closeRatingModal());
    if (ratingOverlay) {
      ratingOverlay.addEventListener("click", (e) => {
        if (e.target === ratingOverlay) this.closeRatingModal();
      });
    }

    starIcons.forEach((star) => {
      star.addEventListener("mouseover", () =>
        this.highlightStars(parseInt(star.getAttribute("data-rating")))
      );
      star.addEventListener("mouseout", () =>
        this.highlightStars(this.currentRatingStars || 0)
      );
      star.addEventListener("click", () => {
        this.currentRatingStars = parseInt(star.getAttribute("data-rating"));
        this.highlightStars(this.currentRatingStars);
        if (ratingSubmitBtn) ratingSubmitBtn.disabled = this.currentRatingStars === 0;
      });
    });

    if (ratingReviewText && ratingReviewCount) {
      ratingReviewText.addEventListener("input", () => {
        ratingReviewCount.textContent = ratingReviewText.value.length;
      });
    }

    if (ratingSubmitBtn) {
      ratingSubmitBtn.addEventListener("click", () => this.submitRating());
    }
  },

  initBookingWizard() {
    if (typeof BookingWizard !== "undefined" && BookingWizard.init) {
      BookingWizard.init({
        onSuccess: () => this.load(),
      });
    }
  },

  goToPage(page) {
    this.currentPage = page;
    this.load();
  },

  toggleBookModal() {
    if (typeof BookingWizard !== "undefined") {
      BookingWizard.toggleModal();
    }
  },

  handleEscapeKey(e) {
    if (e.key === "Escape") {
      this.closeModal();
      if (this.currentRatingAppointmentId !== null || this.currentRatingStars !== 0) {
        this.closeRatingModal();
      }
      const bookModal = document.getElementById("book-appointment-modal");
      if (bookModal && bookModal.style.display === "flex") {
        this.toggleBookModal();
      }
    }
  },

  // ── Appointment Workflow Actions ───────────────────────────
  async acceptSuggestion(appointmentId) {
    if (!confirm("Accept the doctor's suggested time? The appointment date and time will be updated.")) return;
    try {
      const response = await fetch(getBasePath() + "api/appointments/accept-suggestion.php", {
        method: "POST", headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ appointment_id: appointmentId })
      });
      const result = await response.json();
      if (!result.success) throw new Error(result.message || "Failed to accept suggestion");
      showToast("Suggested time accepted. Appointment updated.", "success");
      await this.load();
    } catch (error) {
      console.error("Accept suggestion error:", error);
      showToast(error.message || "Failed to accept suggestion.", "error");
    }
  },

  async declineSuggestion(appointmentId) {
    if (!confirm("Decline the doctor's suggested time? The appointment will remain pending the doctor's response.")) return;
    try {
      const response = await fetch(getBasePath() + "api/appointments/decline-suggestion.php", {
        method: "POST", headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ appointment_id: appointmentId })
      });
      const result = await response.json();
      if (!result.success) throw new Error(result.message || "Failed to decline suggestion");
      showToast("Suggested time declined.", "success");
      await this.load();
    } catch (error) {
      console.error("Decline suggestion error:", error);
      showToast(error.message || "Failed to decline suggestion.", "error");
    }
  },

  async cancelRescheduleRequest(appointmentId) {
    if (!confirm("Are you sure you want to cancel the reschedule request? The appointment will remain at its original date and time.")) return;
    try {
      const response = await fetch(getBasePath() + "api/appointments/cancel-reschedule-request.php", {
        method: "POST", headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ appointment_id: appointmentId }),
      });
      const result = await response.json();
      if (!result.success) throw new Error(result.message || "Failed to cancel reschedule request");
      showToast("Reschedule request cancelled.", "success");
      await this.load();
    } catch (error) {
      console.error("Cancel reschedule error:", error);
      showToast(error.message || "Failed to cancel reschedule request.", "error");
    }
  },

  async reschedule(appointmentId) {
    const appt = this.data.appointments.find((a) => a.id === appointmentId);
    if (!appt) return;
    if (typeof BookingWizard !== "undefined" && BookingWizard.openReschedule) {
      await BookingWizard.openReschedule(appt);
    } else {
      this.toggleBookModal();
    }
  },

  rateDoctor(appointmentId) {
    const appt = this.data.appointments.find((a) => a.id === appointmentId);
    if (!appt) return;
    this.openRatingModal(appointmentId, appt.doctor);
  },

  openRatingModal(appointmentId, doctorName) {
    this.currentRatingAppointmentId = appointmentId;
    this.currentRatingStars = 0;

    const doctorNameEl = document.getElementById("rating-doctor-name");
    const doctorAvatarEl = document.getElementById("rating-doctor-avatar");
    const reviewText = document.getElementById("rating-review");
    const reviewCount = document.getElementById("review-count");
    const messageEl = document.getElementById("rating-message");
    const submitBtn = document.getElementById("rating-modal-submit");

    if (doctorNameEl) doctorNameEl.textContent = `Rate your visit with ${doctorName || "the doctor"}`;
    if (doctorAvatarEl) {
      const initials = (doctorName || "DR").split(" ").map(n => n[0]).join("").substring(0, 2).toUpperCase();
      doctorAvatarEl.textContent = initials;
    }

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
    this.highlightStars(0);

    const overlay = document.getElementById("rating-modal-overlay");
    if (overlay) {
      overlay.classList.add("open");
      document.body.classList.add("modal-open");
    }
  },

  closeRatingModal() {
    this.currentRatingAppointmentId = null;
    this.currentRatingStars = 0;
    const overlay = document.getElementById("rating-modal-overlay");
    if (overlay) {
      overlay.classList.remove("open");
      document.body.classList.remove("modal-open");
    }
  },

  highlightStars(count) {
    document.querySelectorAll(".star-icon").forEach((s, i) => {
      s.style.color = i < count ? "#f59e0b" : "var(--border-light)";
    });
    const el = document.getElementById("rating-value");
    if (el) el.textContent = count === 0 ? "Select a rating" : `${count} star${count !== 1 ? "s" : ""}`;
  },

  async submitRating() {
    if (!this.currentRatingAppointmentId || this.currentRatingStars === 0) {
      showToast("Please select a rating.", "error");
      return;
    }
    const reviewText = document.getElementById("rating-review")?.value || "";
    const submitBtn = document.getElementById("rating-modal-submit");
    const originalText = submitBtn?.textContent || "Submit Rating";
    setLoading(submitBtn, true, "Submitting...");

    const result = await apiFetch(
      getBasePath() + "api/doctors/submit-rating.php",
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          appointment_id: this.currentRatingAppointmentId,
          stars: this.currentRatingStars,
          review: reviewText,
        }),
      },
      "Could not submit rating."
    );

    setLoading(submitBtn, false, originalText);
    if (result.data?.success) {
      showToast("Thank you! Your rating has been submitted.", "success");
      this.closeRatingModal();
      await this.load();
    } else {
      showToast(result.data?.message || "Failed to submit rating.", "error");
    }
  },

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

      showToast("Appointment cancelled successfully.", "info");
      this.closeCancelModal();
      this.currentPage = 1;
      await this.load();
    } catch (error) {
      console.error("Cancel appointment error:", error);
      showToast("Failed to cancel appointment. Please try again.", "error");
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

  downloadICS(appointmentId) {
    const appt = this.data.appointments.find((a) => a.id === appointmentId);
    if (!appt) return;

    const startDate = new Date(appt.date + "T" + (appt.time || "09:00:00"));
    const endDate = new Date(startDate.getTime() + 60 * 60 * 1000);

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
SUMMARY:HealthBridge Appointment with ${appt.doctor}
DESCRIPTION:Department: ${appt.department}
LOCATION:HealthBridge Medical Center
END:VEVENT
END:VCALENDAR
    `.trim();

    const blob = new Blob([icsContent], { type: "text/calendar;charset=utf-8" });
    const link = document.createElement("a");
    link.href = URL.createObjectURL(blob);
    link.download = `healthbridge-appointment-${appt.id}.ics`;
    link.click();
  },

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
    }
  },

  showSkeleton() { this.showElement("appointments-skeleton"); },
  hideSkeleton() { this.hideElement("appointments-skeleton"); },
  showEmpty() { this.showElement("appointments-empty"); },
  showError() { this.showElement("appointments-error"); },

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

  formatDate(dateStr) {
    if (!dateStr) return "-";
    try {
      const date = new Date(dateStr);
      return date.toLocaleDateString("en-US", {
        month: "short",
        day: "numeric",
        year: "numeric",
      });
    } catch { return String(dateStr); }
  },

  escapeHTML(str) {
    if (!str) return "";
    const div = document.createElement("div");
    div.textContent = str;
    return div.innerHTML;
  },
};

document.addEventListener("DOMContentLoaded", () => {
  AppointmentsPage.init();
});
