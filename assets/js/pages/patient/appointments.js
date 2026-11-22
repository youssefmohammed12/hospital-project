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

    const requestedViewId = safePositiveId(viewId);
    if (requestedViewId) {
      setTimeout(() => {
        this.viewDetails(requestedViewId);
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

      const responseData = result.data && typeof result.data === "object" && !Array.isArray(result.data)
        ? result.data
        : {};
      const rawAppointments = Array.isArray(responseData.appointments) ? responseData.appointments : [];
      const appointments = rawAppointments
        .filter(appt => appt && typeof appt === "object" && !Array.isArray(appt))
        .map(appt => ({
          id: safePositiveId(appt.id),
          doctor_id: safePositiveId(appt.doctor_id),
          department_id: safePositiveId(appt.department_id),
          doctor: typeof appt.doctor === "string" ? appt.doctor.slice(0, 160) : "Doctor",
          doctor_specialty: typeof appt.doctor_specialty === "string" ? appt.doctor_specialty.slice(0, 160) : "",
          department: typeof appt.department === "string" ? appt.department.slice(0, 160) : "",
          date: typeof appt.date === "string" ? appt.date.slice(0, 40) : "",
          time: typeof appt.time === "string" ? appt.time.slice(0, 40) : "",
          time_range: typeof appt.time_range === "string" ? appt.time_range.slice(0, 80) : "",
          duration: safeCount(appt.duration, 30),
          status: ["Pending", "Confirmed", "Completed", "Cancelled", "Missed", "Rescheduled", "Reschedule Requested"].includes(appt.status)
            ? appt.status
            : "Scheduled",
          category: ["upcoming", "pending", "confirmed", "completed", "cancelled", "missed", "reschedule_requested"].includes(appt.category)
            ? appt.category
            : "scheduled",
          reschedule_status: ["pending", "suggested", "approved", "rejected"].includes(appt.reschedule_status)
            ? appt.reschedule_status
            : "",
          workflow_status: typeof appt.workflow_status === "string" ? appt.workflow_status.slice(0, 80) : "",
          pending_reschedule_date: typeof appt.pending_reschedule_date === "string" ? appt.pending_reschedule_date.slice(0, 40) : "",
          pending_reschedule_time: typeof appt.pending_reschedule_time === "string" ? appt.pending_reschedule_time.slice(0, 40) : "",
          reschedule_reason: typeof appt.reschedule_reason === "string" ? appt.reschedule_reason.slice(0, 1000) : "",
          notes: typeof appt.notes === "string" ? appt.notes.slice(0, 2000) : "",
          booked_at: typeof appt.booked_at === "string" ? appt.booked_at.slice(0, 40) : "",
          has_rating: appt.has_rating === true || appt.has_rating === 1 || appt.has_rating === "1",
          can_rate: appt.can_rate === true || appt.can_rate === 1 || appt.can_rate === "1",
        }));
      const rawPagination = responseData.pagination && typeof responseData.pagination === "object" && !Array.isArray(responseData.pagination)
        ? responseData.pagination
        : {};
      const totalPages = Math.max(1, Math.min(100000, safeCount(rawPagination.total_pages, 1)));
      const currentPage = Math.max(1, Math.min(totalPages, safeCount(rawPagination.current_page, 1)));
      this.currentPage = currentPage;
      this.data = {
        ...responseData,
        appointments,
        pagination: {
          current_page: currentPage,
          per_page: Math.max(1, Math.min(100, safeCount(rawPagination.per_page, this.perPage))),
          total: safeCount(rawPagination.total, appointments.length),
          total_pages: totalPages,
        },
      };
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

    const appointments = Array.isArray(this.data?.appointments) ? this.data.appointments : [];

    if (appointments.length === 0) {
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
    const apps = Array.isArray(this.data?.appointments) ? this.data.appointments : [];
    if (apps.length === 0) {
      container.classList.remove("is-visible");
      return;
    }

    const confirmed = apps.find((a) => a.category === "confirmed");
    const pending = apps.find((a) => a.category === "upcoming");
    const upcoming = confirmed || pending;

    if (!upcoming) {
      container.classList.remove("is-visible");
      return;
    }

    container.classList.add("is-visible");
    const d = typeof upcoming.date === "string" ? this.formatDate(upcoming.date) : "";
    const timeValue = typeof upcoming.time_range === "string" ? upcoming.time_range
      : typeof upcoming.time === "string" ? upcoming.time : "";
    const t = timeValue;
    const upcomingId = safePositiveId(upcoming.id);
    const doctorName = typeof upcoming.doctor === "string" ? upcoming.doctor : "Doctor";
    const departmentName = typeof upcoming.department === "string" ? upcoming.department : "";

    container.innerHTML = `
      <div class="featured-upcoming-card">
        <div class="featured-icon"><i class="fas fa-calendar-check" aria-hidden="true"></i></div>
        <div class="featured-body">
          <div class="featured-label">Next Scheduled Appointment</div>
          <div class="featured-title">${this.escapeHTML(doctorName)}</div>
          <div class="featured-meta">
            <span><i class="fas fa-calendar" aria-hidden="true"></i> ${this.escapeHTML(d)}</span>
            <span><i class="fas fa-clock" aria-hidden="true"></i> ${this.escapeHTML(t)}</span>
            <span><i class="fas fa-building" aria-hidden="true"></i> ${this.escapeHTML(departmentName)}</span>
          </div>
        </div>
        <div class="featured-actions">
          ${upcomingId
            ? `<button class="btn btn-outline btn-sm" data-appointments-action="view-details" data-appointment-id="${upcomingId}">View Details</button>
               <button class="btn btn-primary btn-sm" data-appointments-action="reschedule" data-appointment-id="${upcomingId}">Reschedule</button>`
            : '<span class="text-muted">Actions unavailable</span>'}
        </div>
      </div>
    `;
  },

  // ── Render Single Appointment Card (Phase 4 Redesign) ──────
  renderAppointmentCard(appt) {
    if (!appt || typeof appt !== "object" || Array.isArray(appt)) return "";
    const doctorName = typeof appt.doctor === "string" ? appt.doctor : "";
    const departmentName = typeof appt.department === "string" ? appt.department : "";
    const appointmentStatus = typeof appt.status === "string" ? appt.status : "";
    const category = typeof appt.category === "string" ? appt.category : "";
    const rescheduleStatus = typeof appt.reschedule_status === "string" ? appt.reschedule_status : "";
    const workflowStatus = typeof appt.workflow_status === "string" ? appt.workflow_status : "";
    // ── Status Resolution ──
    let badge = { label: "", tone: "neutral", icon: "fa-calendar" };

    if (rescheduleStatus === "pending") {
      badge = { label: "Reschedule Requested", tone: "reschedule-pending", icon: "fa-clock" };
    } else if (rescheduleStatus === "suggested") {
      badge = { label: "Reschedule Suggested", tone: "reschedule-suggested", icon: "fa-calendar-day" };
    } else if (rescheduleStatus === "approved") {
      badge = { label: "Reschedule Approved", tone: "reschedule-approved", icon: "fa-check-circle" };
    } else if (rescheduleStatus === "rejected") {
      badge = { label: "Reschedule Declined", tone: "reschedule-rejected", icon: "fa-times-circle" };
    } else if (appt.category === "completed" || appt.workflow_status === "Completed") {
      badge = { label: "Completed", tone: "completed", icon: "fa-circle-check" };
    } else if (category === "missed") {
      badge = { label: "Missed", tone: "missed", icon: "fa-user-slash" };
    } else if (appointmentStatus === "Cancelled" || category === "cancelled") {
      badge = { label: "Cancelled", tone: "cancelled", icon: "fa-ban" };
    } else if (appt.status === "Confirmed" || appt.category === "confirmed") {
      badge = { label: "Confirmed", tone: "confirmed", icon: "fa-check" };
    } else if (appt.status === "Pending" || appt.category === "upcoming" || appt.category === "pending") {
      badge = { label: "Pending Confirmation", tone: "pending", icon: "fa-hourglass-half" };
    } else {
      badge = { label: appointmentStatus || "Scheduled", tone: "neutral", icon: "fa-circle-info" };
    }

    const statusLabel = this.escapeHTML(badge.label);
    const appointmentId = safePositiveId(appt.id);

    const initials = this.escapeHTML(
      doctorName.trim()
        ? doctorName.trim().split(/\s+/).map(part => part[0]).join("").substring(0, 2).toUpperCase()
        : "DR"
    );

    const dateStr = this.formatDate(appt.date);
    const timeStr = typeof appt.time_range === "string" ? appt.time_range
      : typeof appt.time === "string" ? appt.time : "";
    const specialty = typeof appt.doctor_specialty === "string" ? appt.doctor_specialty : "";
    const durationMinutes = safeCount(appt.duration, 30);
    const duration = durationMinutes > 0 && durationMinutes <= 240
      ? `${durationMinutes} min`
      : "30 min";

    // Reschedule Information Banner
    let rescheduleBanner = "";
    if (rescheduleStatus === "pending" && typeof appt.pending_reschedule_date === "string" && appt.pending_reschedule_date) {
      const reqDate = this.escapeHTML(this.formatDate(appt.pending_reschedule_date));
      const reqTime = this.escapeHTML(appt.pending_reschedule_time || "");
      rescheduleBanner = `
        <div class="reschedule-banner reschedule-pending-banner">
          <i class="fas fa-clock" aria-hidden="true"></i>
          <div>
            <strong>Reschedule Request Pending:</strong> Requested ${reqDate}${reqTime ? " at " + reqTime : ""}
            ${appt.reschedule_reason ? `<span class="reschedule-reason-txt">Reason: ${this.escapeHTML(appt.reschedule_reason)}</span>` : ""}
          </div>
        </div>`;
    } else if (appt.reschedule_status === "suggested" && appt.pending_reschedule_date) {
      const sugDate = this.escapeHTML(this.formatDate(appt.pending_reschedule_date));
      const sugTime = this.escapeHTML(appt.pending_reschedule_time || "");
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
        <button class="btn btn-outline btn-sm" data-appointments-action="view-details" data-appointment-id="${appointmentId}">View</button>
        <button class="btn btn-success btn-sm" data-appointments-action="accept-suggestion" data-appointment-id="${appointmentId}"><i class="fas fa-check" aria-hidden="true"></i> Accept</button>
        <button class="btn btn-outline btn-sm btn-danger-text" data-appointments-action="decline-suggestion" data-appointment-id="${appointmentId}"><i class="fas fa-xmark" aria-hidden="true"></i> Decline</button>
      `;
    } else if (appt.reschedule_status === "pending") {
      actions = `
        <button class="btn btn-outline btn-sm" data-appointments-action="view-details" data-appointment-id="${appointmentId}">View</button>
        <button class="btn btn-outline btn-sm btn-danger-text" data-appointments-action="cancel-reschedule-request" data-appointment-id="${appointmentId}">Cancel Request</button>
      `;
    } else if (appt.category === "completed" || appt.workflow_status === "Completed") {
      const ratingAction = appt.has_rating
        ? `<button class="btn btn-primary btn-sm" data-appointments-action="view-rating" data-appointment-id="${appointmentId}"><i class="fas fa-star" aria-hidden="true"></i> View Rating</button>`
        : (appt.can_rate ? `<button class="btn btn-primary btn-sm" data-appointments-action="rate-doctor" data-appointment-id="${appointmentId}"><i class="fas fa-star" aria-hidden="true"></i> Rate Visit</button>` : "");
      actions = `
        <button class="btn btn-outline btn-sm" data-appointments-action="view-details" data-appointment-id="${appointmentId}">View Details</button>
        ${ratingAction}
      `;
    } else if (appt.category === "missed" || appt.status === "Cancelled" || appt.category === "cancelled") {
      actions = `
        <button class="btn btn-outline btn-sm" data-appointments-action="view-details" data-appointment-id="${appointmentId}">View Details</button>
      `;
    } else if (appt.status === "Pending" || appt.category === "upcoming" || appt.category === "pending") {
      actions = `
        <button class="btn btn-outline btn-sm" data-appointments-action="view-details" data-appointment-id="${appointmentId}">View</button>
        <button class="btn btn-outline btn-sm btn-danger-text" data-appointments-action="init-cancel" data-appointment-id="${appointmentId}">Cancel</button>
      `;
    } else if (appt.status === "Confirmed" || appt.category === "confirmed") {
      actions = `
        <button class="btn btn-outline btn-sm" data-appointments-action="view-details" data-appointment-id="${appointmentId}">View</button>
        <button class="btn btn-outline btn-sm" data-appointments-action="reschedule" data-appointment-id="${appointmentId}">Reschedule</button>
        <button class="btn btn-outline btn-sm btn-danger-text" data-appointments-action="init-cancel" data-appointment-id="${appointmentId}">Cancel</button>
      `;
    } else {
      actions = `
        <button class="btn btn-outline btn-sm" data-appointments-action="view-details" data-appointment-id="${appointmentId}">View Details</button>
      `;
    }

    let calendarBtn = "";
    if (appointmentId && (appt.category === "upcoming" || appt.category === "confirmed")) {
      calendarBtn = `<button class="card-action-icon" data-appointments-action="download-ics" data-appointment-id="${appointmentId}" title="Add to Calendar" aria-label="Add to calendar"><i class="fas fa-calendar-plus" aria-hidden="true"></i></button>`;
    }
    if (!appointmentId) actions = '<span class="text-muted">Actions unavailable</span>';

    return `
      <article class="appointment-card" data-id="${appointmentId}">
        <div class="appointment-card-left">
          <div class="doctor-avatar">${initials}</div>
        </div>
        <div class="appointment-card-center">
          <div class="appointment-card-header">
            <div class="appointment-card-title">
              <h3 class="doctor-name">${this.escapeHTML(doctorName)}</h3>
              <p class="doctor-specialty">${this.escapeHTML(specialty || departmentName)}</p>
            </div>
            <span class="appointment-status-badge appointment-status-badge--${badge.tone}">
              <i class="fas ${badge.icon}" aria-hidden="true"></i> ${statusLabel}
            </span>
          </div>
          <div class="department-name"><i class="fas fa-building" aria-hidden="true"></i> ${this.escapeHTML(departmentName)}</div>
          <div class="appointment-meta">
            <span><i class="fas fa-calendar" aria-hidden="true"></i> ${this.escapeHTML(dateStr)}</span>
            <span><i class="fas fa-clock" aria-hidden="true"></i> ${this.escapeHTML(timeStr)}</span>
            <span><i class="fas fa-hourglass-half" aria-hidden="true"></i> ${duration}</span>
          </div>
          ${typeof appt.notes === "string" && appt.notes ? `<div class="appointment-notes"><i class="fas fa-notes-medical" aria-hidden="true"></i> ${this.escapeHTML(appt.notes)}</div>` : ""}
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
    const safeId = safePositiveId(appointmentId);
    if (!safeId) return;
    const appt = this.data?.appointments?.find(a => safePositiveId(a.id) === safeId);
    if (!appt) return;

    const modalBody = document.getElementById("appointment-modal-body");
    if (!modalBody) return;

    const doctorName = typeof appt.doctor === "string" && appt.doctor.trim() ? appt.doctor : "Doctor";
    const departmentName = typeof appt.department === "string" ? appt.department : "";
    const dateValue = typeof appt.date === "string" ? appt.date : "";
    const timeValue = typeof appt.time_range === "string" && appt.time_range
      ? appt.time_range
      : typeof appt.time === "string" ? appt.time : "";
    const initials = this.escapeHTML(
      doctorName.trim()
        ? doctorName.trim().split(/\s+/).map(part => part[0]).join("").substring(0, 2).toUpperCase()
        : "DR"
    );

    const dateStr = this.formatDate(dateValue);
    const timeStr = timeValue;
    const status = typeof appt.status === "string" ? appt.status : "Scheduled";
    const statusTone = status === "Confirmed" ? "confirmed" : status === "Completed" ? "completed" : status === "Cancelled" ? "cancelled" : "pending";

    modalBody.innerHTML = `
      <div class="appointment-details">
        <div class="detail-header">
          <div class="detail-avatar">${initials}</div>
          <div class="detail-info">
            <h4>${this.escapeHTML(doctorName)}</h4>
            <p>${this.escapeHTML(departmentName)} • ${this.escapeHTML(typeof appt.doctor_specialty === "string" ? appt.doctor_specialty : "Specialist")}</p>
          </div>
          <span class="appt-status-pill appt-status-pill--${statusTone}">
            ${this.escapeHTML(status)}
          </span>
        </div>

        <div class="detail-section">
          <h5><i class="fas fa-calendar-day" aria-hidden="true"></i> Date &amp; Time</h5>
          <p>${this.escapeHTML(dateStr)} at ${this.escapeHTML(timeStr)}</p>
        </div>

        <div class="detail-section">
          <h5><i class="fas fa-hospital" aria-hidden="true"></i> Location &amp; Room</h5>
          <p>HealthBridge Main Hospital Building • ${this.escapeHTML(departmentName)} Department</p>
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
          <p>Appointment ID: #${safePositiveId(appt.id)} ${appt.booked_at ? `• Booked on ${this.formatDate(appt.booked_at)}` : ""}</p>
        </div>

        <div class="detail-actions">
          <button class="btn btn-outline" data-appointments-action="close-modal">Close</button>
          ${appt.status === "Confirmed" ? `<button class="btn btn-primary" data-appointments-action="reschedule-after-close" data-appointment-id="${safePositiveId(appt.id)}">Reschedule</button>` : ""}
        </div>
      </div>
    `;

    this.showModal();
  },

  // ── View Rating Modal (For already-rated appointments) ──────
  async viewRating(appointmentId) {
    const safeId = safePositiveId(appointmentId);
    if (!safeId) return;
    const appt = this.data?.appointments?.find(a => safePositiveId(a.id) === safeId);
    if (!appt) return;

    try {
      const response = await fetch(getBasePath() + `api/doctors/get-rating.php?appointmentId=${safeId}`, { credentials: "same-origin" });
      const result = await response.json();
      if (!result.success || !result.rating) {
        showToast("No rating found for this appointment.", "error");
        return;
      }
      const r = result.rating && typeof result.rating === "object" && !Array.isArray(result.rating)
        ? result.rating
        : null;
      const ratingStars = safeCount(r?.stars, 0);
      if (!r || ratingStars < 1 || ratingStars > 5) {
        showToast("The returned rating is invalid.", "error");
        return;
      }
      const ratingReview = typeof r.review === "string" ? r.review.slice(0, 500) : "";
      const ratingDate = typeof r.created_at === "string" ? r.created_at : "";
      const starsHtml = Array.from({ length: 5 }, (_, i) =>
        `<i class="fas fa-star rating-result-star ${i < ratingStars ? "is-filled" : "is-empty"}" aria-hidden="true"></i>`
      ).join("");

      const modalBody = document.getElementById("appointment-modal-body");
      if (!modalBody) return;

      const doctorName = typeof appt.doctor === "string" && appt.doctor.trim() ? appt.doctor : "Doctor";
      const departmentName = typeof appt.department === "string" ? appt.department : "";
      const initials = doctorName.trim().split(/\s+/).map(part => part[0]).join("").substring(0, 2).toUpperCase() || "DR";

      modalBody.innerHTML = `
        <div class="appointment-details">
          <div class="detail-header">
            <div class="detail-avatar">${initials}</div>
            <div class="detail-info">
              <h4>${this.escapeHTML(doctorName)}</h4>
              <p>${this.escapeHTML(departmentName)}</p>
            </div>
          </div>
          <div class="rating-result-summary">
            <div class="rating-result-stars">${starsHtml}</div>
            <h4 class="rating-result-score">${ratingStars} / 5 Stars</h4>
            ${ratingReview ? `<p class="rating-result-review">"${this.escapeHTML(ratingReview)}"</p>` : ""}
            <p class="rating-result-date">Submitted on ${this.formatDate(ratingDate)}</p>
          </div>
          <div class="detail-actions">
            <button class="btn btn-outline" data-appointments-action="close-modal">Close</button>
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
          `<button class="page-number ${i === pagination.current_page ? "active" : ""}" data-appointments-page="${i}">${i}</button>`
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

  // ── Delegated actions for static and dynamically rendered appointment controls ──
  handleDelegatedAction(event) {
    const target = event.target instanceof Element ? event.target : null;
    if (!target) return;

    const pageButton = target.closest("[data-appointments-page]");
    if (pageButton) {
      const page = Number(pageButton.dataset.appointmentsPage);
      if (Number.isSafeInteger(page) && page > 0) this.goToPage(page);
      return;
    }

    const button = target.closest("[data-appointments-action]");
    if (!button) return;

    const action = button.dataset.appointmentsAction;
    const appointmentId = safePositiveId(button.dataset.appointmentId);

    switch (action) {
      case "toggle-book":
        this.toggleBookModal();
        break;
      case "load":
        this.load();
        break;
      case "close-modal":
        this.closeModal();
        break;
      case "close-cancel-modal":
        this.closeCancelModal();
        break;
      case "confirm-cancel":
        this.confirmCancel();
        break;
      case "reschedule-after-close":
        if (appointmentId) {
          this.closeModal();
          this.reschedule(appointmentId);
        }
        break;
      case "view-details":
        if (appointmentId) this.viewDetails(appointmentId);
        break;
      case "reschedule":
        if (appointmentId) this.reschedule(appointmentId);
        break;
      case "accept-suggestion":
        if (appointmentId) this.acceptSuggestion(appointmentId);
        break;
      case "decline-suggestion":
        if (appointmentId) this.declineSuggestion(appointmentId);
        break;
      case "cancel-reschedule-request":
        if (appointmentId) this.cancelRescheduleRequest(appointmentId);
        break;
      case "view-rating":
        if (appointmentId) this.viewRating(appointmentId);
        break;
      case "rate-doctor":
        if (appointmentId) this.rateDoctor(appointmentId);
        break;
      case "init-cancel":
        if (appointmentId) this.initCancel(appointmentId);
        break;
      case "download-ics":
        if (appointmentId) this.downloadICS(appointmentId);
        break;
    }
  },

  // ── Event Listeners ────────────────────────────────────────
  attachEventListeners() {
    document.addEventListener("click", (event) => this.handleDelegatedAction(event));

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
    const safePage = safeCount(page, 0);
    const totalPages = Math.max(1, safeCount(this.data?.pagination?.total_pages, 1));
    if (!safePage || safePage > totalPages) return;
    this.currentPage = safePage;
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
      if (bookModal?.classList.contains("open")) {
        this.toggleBookModal();
      }
    }
  },

  // ── Appointment Workflow Actions ───────────────────────────
  async acceptSuggestion(appointmentId) {
    const safeId = safePositiveId(appointmentId);
    if (!safeId) return;
    if (!confirm("Accept the doctor's suggested time? The appointment date and time will be updated.")) return;
    try {
      const response = await fetch(getBasePath() + "api/appointments/accept-suggestion.php", {
        method: "POST", headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ appointment_id: safeId })
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
    const safeId = safePositiveId(appointmentId);
    if (!safeId) return;
    if (!confirm("Decline the doctor's suggested time? The appointment will remain pending the doctor's response.")) return;
    try {
      const response = await fetch(getBasePath() + "api/appointments/decline-suggestion.php", {
        method: "POST", headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ appointment_id: safeId })
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
    const safeId = safePositiveId(appointmentId);
    if (!safeId) return;
    if (!confirm("Are you sure you want to cancel the reschedule request? The appointment will remain at its original date and time.")) return;
    try {
      const response = await fetch(getBasePath() + "api/appointments/cancel-reschedule-request.php", {
        method: "POST", headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ appointment_id: safeId }),
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
    const safeId = safePositiveId(appointmentId);
    if (!safeId) return;
    const appt = this.data?.appointments?.find(a => safePositiveId(a.id) === safeId);
    if (!appt) return;
    if (typeof BookingWizard !== "undefined" && BookingWizard.openReschedule) {
      await BookingWizard.openReschedule(appt);
    } else {
      this.toggleBookModal();
    }
  },

  rateDoctor(appointmentId) {
    const safeId = safePositiveId(appointmentId);
    if (!safeId) return;
    const appt = this.data?.appointments?.find(a => safePositiveId(a.id) === safeId);
    if (!appt) return;
    this.openRatingModal(appointmentId, appt.doctor);
  },

  openRatingModal(appointmentId, doctorName) {
    const safeId = safePositiveId(appointmentId);
    if (!safeId) return;
    const safeDoctorName = typeof doctorName === "string" ? doctorName.slice(0, 160) : "the doctor";
    this.currentRatingAppointmentId = safeId;
    this.currentRatingStars = 0;

    const doctorNameEl = document.getElementById("rating-doctor-name");
    const doctorAvatarEl = document.getElementById("rating-doctor-avatar");
    const reviewText = document.getElementById("rating-review");
    const reviewCount = document.getElementById("review-count");
    const messageEl = document.getElementById("rating-message");
    const submitBtn = document.getElementById("rating-modal-submit");

    if (doctorNameEl) doctorNameEl.textContent = `Rate your visit with ${safeDoctorName || "the doctor"}`;
    if (doctorAvatarEl) {
      const initials = (safeDoctorName || "DR").trim().split(/\s+/).map(part => part[0]).join("").substring(0, 2).toUpperCase() || "DR";
      doctorAvatarEl.textContent = initials;
    }

    if (reviewText) reviewText.value = "";
    if (reviewCount) reviewCount.textContent = "0";
    if (messageEl) {
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
      s.classList.toggle("is-filled", i < count);
      s.classList.toggle("is-empty", i >= count);
    });
    const el = document.getElementById("rating-value");
    if (el) el.textContent = count === 0 ? "Select a rating" : `${count} star${count !== 1 ? "s" : ""}`;
  },

  async submitRating() {
    const appointmentId = safePositiveId(this.currentRatingAppointmentId);
    const stars = safeCount(this.currentRatingStars, 0);
    if (!appointmentId || stars < 1 || stars > 5) {
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
          appointment_id: appointmentId,
          stars,
          review: typeof reviewText === "string" ? reviewText.slice(0, 500) : "",
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
    const safeId = safePositiveId(appointmentId);
    if (!safeId) return;
    const appt = this.data?.appointments?.find(a => safePositiveId(a.id) === safeId);
    if (!appt) return;

    this.cancelingAppointmentId = safeId;

    const dateEl = document.getElementById("cancel-date");
    const timeEl = document.getElementById("cancel-time");
    const doctorNameEl = document.getElementById("cancel-doctor-name");
    const deptNameEl = document.getElementById("cancel-department-name");
    const initialsEl = document.getElementById("cancel-doctor-initials");

    if (dateEl) dateEl.textContent = this.formatDate(appt.date);
    if (timeEl) timeEl.textContent = appt.time_range || appt.time;
    if (doctorNameEl) doctorNameEl.textContent = typeof appt.doctor === "string" ? appt.doctor : "Doctor";
    if (deptNameEl) deptNameEl.textContent = typeof appt.department === "string" ? appt.department : "";
    if (initialsEl) {
      const doctorName = typeof appt.doctor === "string" && appt.doctor.trim() ? appt.doctor : "Doctor";
      const initials = doctorName.trim().split(/\s+/).map(part => part[0]).join("").substring(0, 2).toUpperCase() || "DR";
      initialsEl.textContent = initials;
    }

    this.showCancelModal();
  },

  async confirmCancel() {
    const appointmentId = safePositiveId(this.cancelingAppointmentId);
    if (!appointmentId) return;

    try {
      const response = await fetch("../../api/patient/cancel-appointment.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ id: appointmentId }),
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
      document.body.classList.remove("modal-open");
    }
  },

  downloadICS(appointmentId) {
    const safeId = safePositiveId(appointmentId);
    if (!safeId) return;
    const appt = this.data?.appointments?.find(a => safePositiveId(a.id) === safeId);
    if (!appt) return;

    const dateValue = typeof appt.date === "string" && /^\d{4}-\d{2}-\d{2}$/.test(appt.date)
      ? appt.date
      : "";
    const timeValue = typeof appt.time === "string" && /^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/.test(appt.time)
      ? appt.time
      : "";
    if (!dateValue || !timeValue) return;
    const [year, month, day] = dateValue.split("-").map(Number);
    const calendarDate = new Date(Date.UTC(year, month - 1, day));
    if (calendarDate.getUTCFullYear() !== year
      || calendarDate.getUTCMonth() !== month - 1
      || calendarDate.getUTCDate() !== day) return;
    const startDate = new Date(dateValue + "T" + timeValue);
    if (Number.isNaN(startDate.getTime())) return;
    const endDate = new Date(startDate.getTime() + 60 * 60 * 1000);

    const formatDateICS = (date) =>
      date.toISOString().replace(/[-:]/g, "").split(".")[0] + "Z";

    const icsText = value => typeof value === "string"
      ? value.replace(/[\r\n\\,;]/g, " ").slice(0, 250)
      : "";
    const icsContent = `
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//HealthBridge//Appointment//EN
BEGIN:VEVENT
UID:${safePositiveId(appt.id)}@healthbridge.com
DTSTAMP:${formatDateICS(new Date())}
DTSTART:${formatDateICS(startDate)}
DTEND:${formatDateICS(endDate)}
SUMMARY:HealthBridge Appointment with ${icsText(appt.doctor)}
DESCRIPTION:Department: ${icsText(appt.department)}
LOCATION:HealthBridge Medical Center
END:VEVENT
END:VCALENDAR
    `.trim();

    const blob = new Blob([icsContent], { type: "text/calendar;charset=utf-8" });
    const link = document.createElement("a");
    link.href = URL.createObjectURL(blob);
    link.download = `healthbridge-appointment-${safeId}.ics`;
    link.click();
    const objectUrl = link.href;
    setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
  },

  showModal() {
    const modal = document.getElementById("appointment-modal");
    if (modal) {
      modal.classList.add("open");
      document.body.classList.add("modal-open");
    }
  },

  closeModal() {
    const modal = document.getElementById("appointment-modal");
    if (modal) {
      modal.classList.remove("open");
      document.body.classList.remove("modal-open");
    }
  },

  showCancelModal() {
    const modal = document.getElementById("cancel-modal");
    if (modal) {
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
    if (el) el.classList.add("is-visible");
  },

  hideElement(id) {
    const el = document.getElementById(id);
    if (el) el.classList.remove("is-visible");
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
