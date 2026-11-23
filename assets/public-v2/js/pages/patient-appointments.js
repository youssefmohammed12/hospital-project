/*
 * HealthBridge Public V2 — Patient Appointments
 * Owns the patient appointment list and its existing booking, reschedule,
 * cancellation, suggestion and rating workflows. Backend routes/payloads stay unchanged.
 */
"use strict";

(() => {
  const ROOT = typeof getBasePath === "function" ? getBasePath() : "../../";
  const API = {
    list: ROOT + "api/patient/appointments.php",
    doctors: ROOT + "api/doctors/get.php",
    slots: ROOT + "api/appointments/get-available-slots.php",
    book: ROOT + "api/appointments/book.php",
    reschedule: ROOT + "api/appointments/request-reschedule.php",
    cancel: ROOT + "api/patient/cancel-appointment.php",
    acceptSuggestion: ROOT + "api/appointments/accept-suggestion.php",
    declineSuggestion: ROOT + "api/appointments/decline-suggestion.php",
    cancelReschedule: ROOT + "api/appointments/cancel-reschedule-request.php",
    rating: ROOT + "api/doctors/get-rating.php",
    submitRating: ROOT + "api/doctors/submit-rating.php",
  };

  const state = {
    initialized: false,
    user: null,
    page: 1,
    limit: 10,
    filter: "all",
    search: "",
    appointments: [],
    pagination: { current_page: 1, per_page: 10, total: 0, total_pages: 1 },
    doctors: null,
    loadToken: 0,
    searchTimer: 0,
    booking: null,
    rating: null,
    dialogClose: null,
    returnFocus: null,
    busy: false,
    wizardTransitioning: false,
    wizardTransitionTimer: 0,
    wizardHeightTimer: 0,
    wizardRenderedStep: 0,
  };

  const $ = (id) => document.getElementById(id);
  const positiveId = (value) => {
    if (typeof value !== "number" && typeof value !== "string") return 0;
    if (typeof value === "string" && !/^\d+$/.test(value.trim())) return 0;
    const number = Number(value);
    return Number.isSafeInteger(number) && number > 0 ? number : 0;
  };
  const count = (value, fallback = 0) => {
    if (typeof value !== "number" && typeof value !== "string") return fallback;
    if (typeof value === "string" && !/^\d+$/.test(value.trim())) return fallback;
    const number = Number(value);
    return Number.isSafeInteger(number) && number >= 0 ? number : fallback;
  };
  const text = (value, fallback = "") => typeof value === "string" ? value : fallback;
  const record = (value) => value !== null && typeof value === "object" && !Array.isArray(value);
  const esc = (value) => String(value ?? "").replace(/[&<>"']/g, (char) => ({
    "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;",
  })[char]);
  const localDateValue = () => {
    const now = new Date();
    return [now.getFullYear(), String(now.getMonth() + 1).padStart(2, "0"), String(now.getDate()).padStart(2, "0")].join("-");
  };
  const validDate = (value) => {
    if (typeof value !== "string" || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return false;
    const [year, month, day] = value.split("-").map(Number);
    const date = new Date(year, month - 1, day);
    return date.getFullYear() === year && date.getMonth() === month - 1 && date.getDate() === day;
  };
  const validTime = (value) => typeof value === "string" && /^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/.test(value);
  const formatDate = (value) => {
    if (typeof value !== "string" || !validDate(value)) return value ? esc(value) : "—";
    const [year, month, day] = value.split("-").map(Number);
    return new Intl.DateTimeFormat("en", { month: "short", day: "numeric", year: "numeric" })
      .format(new Date(year, month - 1, day));
  };
  const formatTime = (value) => {
    if (typeof value !== "string") return "Time to be confirmed";
    const match = value.match(/^(\d{2}):(\d{2})(?::\d{2})?$/);
    if (!match) return value;
    const hour = Number(match[1]);
    const minute = Number(match[2]);
    const suffix = hour >= 12 ? "PM" : "AM";
    return String(hour % 12 || 12) + ":" + String(minute).padStart(2, "0") + " " + suffix;
  };
  const appointmentTime = (appt) => {
    // Always format from the start time + duration. The stored time_range
    // strings differ per row ("15:00 - 15:30" vs "2:00 PM – 2:30 PM"), which
    // made the same list render in mixed formats.
    const start = text(appt?.time).trim();
    const match = start.match(/^(\d{2}):(\d{2})(?::\d{2})?$/);
    if (!match) return formatTime(start);
    const total = Number(match[1]) * 60 + Number(match[2]) + (appt?.duration || 30);
    const end = String(Math.floor(total / 60) % 24).padStart(2, "0") + ":" + String(total % 60).padStart(2, "0");
    return formatTime(start) + " – " + formatTime(end);
  };
  const showToast = (message, type = "info") => {
    if (typeof window.showToast === "function") window.showToast(message, type);
    else {
      // Fallback mirrors site.js showToast exactly — the site-wide
      // pop-up treatment (same markup, classes and timing).
      const region = $("site-toast-region");
      if (!region) return;
      const toast = document.createElement("div");
      toast.className = "site-toast";
      toast.dataset.type = ["success", "error", "info"].includes(type) ? type : "info";
      toast.setAttribute("role", type === "error" ? "alert" : "status");
      toast.textContent = message;
      region.append(toast);
      requestAnimationFrame(() => {
        requestAnimationFrame(() => toast.classList.add("is-visible"));
      });
      window.setTimeout(() => {
        toast.classList.remove("is-visible");
        window.setTimeout(() => toast.remove(), 220);
      }, 4200);
    }
  };

  async function apiRequest(url, options = {}) {
    const response = await fetch(url, {
      credentials: "same-origin",
      headers: options.body ? { "Content-Type": "application/json", ...(options.headers || {}) } : (options.headers || {}),
      ...options,
    });
    let payload;
    try {
      payload = await response.json();
    } catch {
      throw new Error("The service returned an unreadable response. Please try again.");
    }
    if (!response.ok || !record(payload) || payload.success !== true) {
      const message = record(payload) && typeof payload.message === "string"
        ? payload.message
        : "The request could not be completed. Please try again.";
      throw new Error(message);
    }
    return payload;
  }

  function normalizeAppointment(value) {
    if (!record(value)) return null;
    const statusOptions = ["Pending", "Confirmed", "Completed", "Cancelled", "Missed", "Rescheduled", "Reschedule Requested"];
    const categoryOptions = ["upcoming", "confirmed", "completed", "cancelled", "missed", "reschedule_requested"];
    const rescheduleOptions = ["pending", "suggested", "approved", "rejected"];
    return {
      id: positiveId(value.id),
      doctor_id: positiveId(value.doctor_id),
      department_id: positiveId(value.department_id),
      doctor: text(value.doctor, "Doctor").slice(0, 160),
      doctor_specialty: text(value.doctor_specialty).slice(0, 160),
      department: text(value.department).slice(0, 160),
      date: text(value.date).slice(0, 40),
      time: text(value.time).slice(0, 40),
      time_range: text(value.time_range).slice(0, 80),
      duration: count(value.duration ?? value.duration_minutes, 30),
      status: statusOptions.includes(value.status) ? value.status : "Scheduled",
      category: categoryOptions.includes(value.category) ? value.category : "upcoming",
      reschedule_status: rescheduleOptions.includes(value.reschedule_status) ? value.reschedule_status : "",
      workflow_status: text(value.workflow_status).slice(0, 80),
      pending_reschedule_date: text(value.pending_reschedule_date).slice(0, 40),
      pending_reschedule_time: text(value.pending_reschedule_time).slice(0, 40),
      reschedule_reason: text(value.reschedule_reason).slice(0, 500),
      notes: text(value.notes).slice(0, 2000),
      booked_at: text(value.booked_at).slice(0, 40),
      has_rating: value.has_rating === true || value.has_rating === 1 || value.has_rating === "1",
      can_rate: value.can_rate === true || value.can_rate === 1 || value.can_rate === "1",
    };
  }

  function setViewState(view) {
    $("appointments-loading").hidden = view !== "loading";
    $("appointments-error").hidden = view !== "error";
    $("appointments-empty").hidden = view !== "empty";
    $("appointments-grid").hidden = view !== "list";
    $("appointments-pagination").hidden = view !== "list" || state.pagination.total_pages <= 1;
    $("appointments-grid").setAttribute("aria-busy", view === "loading" ? "true" : "false");
  }

  function setError(message) {
    $("appointments-error-message").textContent = message || "Please check your connection and try again.";
    $("appointments-next").hidden = true;
    setViewState("error");
  }

  async function loadAppointments() {
    const token = ++state.loadToken;
    setViewState("loading");
    $("appointments-next").hidden = true;
    const params = new URLSearchParams({
      page: String(state.page),
      limit: String(state.limit),
      status: state.filter,
      search: state.search,
    });
    try {
      const result = await apiRequest(API.list + "?" + params.toString());
      if (token !== state.loadToken) return;
      const data = record(result.data) ? result.data : result;
      const list = Array.isArray(data.appointments) ? data.appointments : [];
      state.appointments = list.map(normalizeAppointment).filter((appt) => appt && appt.id);
      const raw = record(data.pagination) ? data.pagination : {};
      const totalPages = Math.max(1, Math.min(100000, count(raw.total_pages, 1)));
      state.pagination = {
        current_page: Math.min(totalPages, Math.max(1, count(raw.current_page, state.page))),
        per_page: Math.max(1, Math.min(50, count(raw.per_page, state.limit))),
        total: count(raw.total, state.appointments.length),
        total_pages: totalPages,
      };
      state.page = state.pagination.current_page;
      renderPage();
      setViewState(state.appointments.length ? "list" : "empty");
    } catch (error) {
      if (token !== state.loadToken) return;
      setError(error.message);
      console.error("Patient appointments load failed:", error);
    }
  }

  function isFutureAppointment(appt) {
    if (!appt || !["upcoming", "confirmed", "reschedule_requested"].includes(appt.category)) return false;
    if (!validDate(appt.date) || !validTime(appt.time)) return false;
    const [year, month, day] = appt.date.split("-").map(Number);
    const [hour, minute, second = 0] = appt.time.split(":").map(Number);
    const startsAt = new Date(year, month - 1, day, hour, minute, second);
    return Number.isFinite(startsAt.getTime()) && startsAt.getTime() > Date.now();
  }

  function statusBadge(appt) {
    if (appt.reschedule_status === "pending") return ["Reschedule requested", "request"];
    if (appt.reschedule_status === "suggested") return ["New time suggested", "suggested"];
    if (appt.reschedule_status === "approved") return ["Reschedule approved", "approved"];
    if (appt.reschedule_status === "rejected") return ["Reschedule declined", "declined"];
    if (appt.category === "completed" || appt.workflow_status === "Completed") return ["Completed", "complete"];
    if (appt.category === "missed") return ["Missed", "missed"];
    if (appt.category === "cancelled" || appt.status === "Cancelled") return ["Cancelled", "cancelled"];
    if (appt.category === "confirmed" || appt.status === "Confirmed") return ["Confirmed", "confirmed"];
    if (appt.status === "Pending") return ["Pending confirmation", "pending"];
    return [appt.status || "Scheduled", "neutral"];
  }

  function appointmentActions(appt) {
    const id = appt.id;
    const button = (action, label, extra = "") =>
      '<button type="button" class="pa-action-button ' + extra + '" data-appointments-action="' + action + '" data-appointment-id="' + id + '">' + label + "</button>";
    if (appt.reschedule_status === "suggested") {
      return button("view", "View details") + button("accept-suggestion", "Accept new time", "is-primary") +
        button("decline-suggestion", "Decline", "is-quiet-danger");
    }
    if (appt.reschedule_status === "pending") {
      return button("view", "View details") + button("cancel-reschedule", "Cancel request", "is-quiet-danger");
    }
    if (appt.category === "completed" || appt.workflow_status === "Completed") {
      const rating = appt.has_rating
        ? button("view-rating", "View rating")
        : (appt.can_rate ? button("rate", "Rate visit", "is-primary") : "");
      return button("view", "View details") + rating;
    }
    if (appt.category === "cancelled" || appt.category === "missed" || appt.status === "Cancelled" || appt.status === "Missed") {
      return button("view", "View details");
    }
    if (appt.category === "confirmed" || appt.status === "Confirmed") {
      return button("view", "View details") + button("reschedule", "Request a change") +
        button("cancel", "Cancel", "is-quiet-danger");
    }
    if (appt.category === "upcoming" || appt.status === "Pending") {
      return button("view", "View details") + button("cancel", "Cancel", "is-quiet-danger");
    }
    return button("view", "View details");
  }

  function renderFeaturedAppointment() {
    const container = $("appointments-next");
    const candidates = state.appointments.filter((appt) =>
      ["upcoming", "confirmed", "reschedule_requested"].includes(appt.category) &&
      validDate(appt.date) && appt.date >= localDateValue()
    ).sort((a, b) => {
      const first = a.date + " " + (validTime(a.time) ? a.time : "23:59");
      const second = b.date + " " + (validTime(b.time) ? b.time : "23:59");
      return first.localeCompare(second);
    });
    const next = candidates[0];
    if (!next) {
      container.hidden = true;
      container.innerHTML = "";
      return;
    }
    const [label] = statusBadge(next);
    container.hidden = false;
    container.innerHTML =
      '<div class="pa-next-card"><div class="pa-next-mark" aria-hidden="true">↗</div><div class="pa-next-copy">' +
        '<p class="pa-eyebrow">UP NEXT</p><h2>' + esc(next.doctor) + '</h2><p>' + esc(next.doctor_specialty || next.department) + '</p>' +
        '<div class="pa-next-meta"><span>' + esc(formatDate(next.date)) + '</span><span>' + esc(appointmentTime(next)) + '</span><span>' + esc(label) + '</span></div>' +
      '</div><button type="button" class="pa-secondary-button" data-appointments-action="view" data-appointment-id="' + next.id + '">View appointment</button></div>';
  }

  function renderCard(appt, index = 0) {
    const [label, tone] = statusBadge(appt);
    const initials = appt.doctor.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part.charAt(0)).join("").toUpperCase() || "DR";
    const rescheduleBanner = appt.reschedule_status === "pending" && appt.pending_reschedule_date
      ? '<div class="pa-request-note"><strong>Waiting for the doctor</strong><span>Your requested time: ' +
        esc(formatDate(appt.pending_reschedule_date)) + (appt.pending_reschedule_time ? " · " + esc(formatTime(appt.pending_reschedule_time)) : "") +
        '</span></div>'
      : appt.reschedule_status === "suggested" && appt.pending_reschedule_date
        ? '<div class="pa-request-note pa-request-note--suggested"><strong>The doctor suggested another time</strong><span>' +
          esc(formatDate(appt.pending_reschedule_date)) + (appt.pending_reschedule_time ? " · " + esc(formatTime(appt.pending_reschedule_time)) : "") +
          '</span></div>'
        : "";
    const addToCalendar = isFutureAppointment(appt)
      ? '<button type="button" class="pa-calendar-button" data-appointments-action="calendar" data-appointment-id="' + appt.id + '">Add to calendar</button>'
      : "";
    return '<article class="pa-appointment-card" style="--pa-i:' + Math.min(index, 8) + '" data-appointment-id="' + appt.id + '">' +
      '<div class="pa-card-accent" aria-hidden="true"></div>' +
      '<div class="pa-card-main">' +
        '<div class="pa-card-topline"><span class="pa-reference">REF · #' + appt.id + '</span><span class="pa-status" data-status="' + tone + '">' + esc(label) + '</span></div>' +
        '<div class="pa-doctor-row"><div class="pa-doctor-avatar" aria-hidden="true">' + esc(initials) + '</div><div class="pa-doctor-copy"><h3>' + esc(appt.doctor) + '</h3><p>' + esc(appt.doctor_specialty || appt.department || "Specialist") + '</p></div></div>' +
        '<div class="pa-card-meta"><span><small>DATE</small><strong>' + esc(formatDate(appt.date)) + '</strong></span>' +
          '<span><small>TIME</small><strong>' + esc(appointmentTime(appt)) + '</strong></span>' +
          '<span><small>DEPARTMENT</small><strong>' + esc(appt.department || "—") + '</strong></span></div>' +
        rescheduleBanner +
        '<div class="pa-card-actions">' + appointmentActions(appt) + addToCalendar + '</div>' +
      '</div></article>';
  }

  function renderPage() {
    const total = state.pagination.total;
    $("appointments-total").textContent = String(total);
    $("appointments-results-count").textContent = total
      ? "Showing " + Math.min((state.page - 1) * state.limit + 1, total) + "–" +
        Math.min(state.page * state.limit, total) + " of " + total
      : "No results";
    $("appointments-results-title").textContent = state.filter === "all"
      ? "All appointments"
      : document.querySelector('[data-filter="' + state.filter + '"]')?.textContent.trim() || "Filtered appointments";
    document.querySelectorAll("[data-filter]").forEach((button) => {
      const active = button.dataset.filter === state.filter;
      button.classList.toggle("is-active", active);
      button.setAttribute("aria-pressed", String(active));
    });
    if (state.appointments.length) {
      $("appointments-grid").innerHTML = state.appointments.map(renderCard).join("");
      renderFeaturedAppointment();
      $("appointments-empty-title").textContent = "No matching appointments";
      $("appointments-empty-copy").textContent = state.search
        ? "Try a different doctor, department or appointment reference."
        : "There are no appointments in this status yet.";
    } else {
      $("appointments-grid").innerHTML = "";
      $("appointments-next").hidden = true;
      $("appointments-empty-title").textContent = state.filter === "all" && !state.search
        ? "No appointments yet" : "No matching appointments";
      $("appointments-empty-copy").textContent = state.search
        ? "Try a different doctor, department or appointment reference."
        : state.filter === "all"
          ? "When you book a visit, its details and status will appear here."
          : "There are no appointments in this status yet.";
    }
    const pagination = state.pagination;
    $("appointments-pagination").hidden = pagination.total_pages <= 1;
    $("appointments-page-label").textContent = "Page " + pagination.current_page + " of " + pagination.total_pages;
    const previous = document.querySelector('[data-page="previous"]');
    const next = document.querySelector('[data-page="next"]');
    if (previous) previous.disabled = pagination.current_page <= 1;
    if (next) next.disabled = pagination.current_page >= pagination.total_pages;
    const search = $("appointments-search");
    if (search && search.value !== state.search) search.value = state.search;
  }

  function ensureDialogRoot() {
    const root = $("appointments-dialog-root");
    if (root.parentElement !== document.body) document.body.append(root);
    if (root.dataset.bound === "true") return root;
    root.dataset.bound = "true";
    root.addEventListener("click", handleDialogClick);
    root.addEventListener("change", handleDialogChange);
    root.addEventListener("input", handleDialogInput);
    root.addEventListener("click", (event) => {
      if (event.target === root) closeDialog();
    });
    return root;
  }

  function openDialog(title, body, options = {}) {
    const root = ensureDialogRoot();
    const dialogClass = options.wizard === true ? "pa-dialog pa-dialog--wizard" : "pa-dialog";
    if (!root.querySelector(".pa-dialog")) state.returnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    state.dialogClose = typeof options.onClose === "function" ? options.onClose : null;
    root.innerHTML =
      '<div class="pa-modal-backdrop" role="presentation">' +
        '<section class="' + dialogClass + '" role="dialog" aria-modal="true" aria-labelledby="pa-dialog-title" aria-describedby="pa-dialog-description" tabindex="-1">' +
          '<header class="pa-dialog-header"><div><p class="pa-eyebrow">' + esc(options.eyebrow || "HEALTHBRIDGE") + '</p><h2 id="pa-dialog-title" tabindex="-1">' + esc(title) + '</h2>' +
          '<p id="pa-dialog-description">' + esc(options.description || "") + '</p></div>' +
          '<button type="button" class="pa-dialog-close" data-dialog-close>Close</button></header>' +
          '<div class="pa-dialog-body">' + body + '</div>' +
        '</section></div>';
    root.hidden = false;
    document.body.classList.add("modal-open");
    const focusTarget = options.focusSelector ? root.querySelector(options.focusSelector) : null;
    (focusTarget || root.querySelector("#pa-dialog-title"))?.focus({ preventScroll: true });
  }

  function openBookingWizardShell() {
    const root = ensureDialogRoot();
    if (root.querySelector(".pa-dialog--wizard")) return root;
    if (!root.querySelector(".pa-dialog")) {
      state.returnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    }
    state.dialogClose = null;
    state.wizardRenderedStep = 0;
    state.wizardTransitioning = false;

    const brandMarkup = window.HealthBridgeBrand?.markup
      ? window.HealthBridgeBrand.markup("dashboard.html", "pa-wizard-brand")
      : '<a class="logo pa-wizard-brand" href="dashboard.html"><span class="home-brand-mark" aria-hidden="true"></span><span>HealthBridge</span></a>';

    root.innerHTML =
      '<div class="pa-modal-backdrop" role="presentation">' +
        '<section class="pa-dialog pa-dialog--wizard" role="dialog" aria-modal="true" aria-labelledby="pa-dialog-title" aria-describedby="pa-dialog-description" tabindex="-1">' +
          '<header class="pa-dialog-header pa-wizard-header">' +
            '<div class="pa-wizard-header-main">' + brandMarkup +
              '<div class="pa-wizard-title-copy">' +
                '<p class="pa-eyebrow" id="pa-dialog-eyebrow">APPOINTMENT BOOKING</p>' +
                '<h2 id="pa-dialog-title" tabindex="-1">Book an appointment</h2>' +
                '<p id="pa-dialog-description">Find the right specialist and request a convenient visit time.</p>' +
              '</div>' +
            '</div>' +
            '<button type="button" class="pa-dialog-close" data-dialog-close>Close</button>' +
          '</header>' +
          '<div class="pa-wizard-progress-wrap" id="pa-wizard-progress-wrap">' + bookingProgressMarkup() + '</div>' +
          '<div class="pa-dialog-body" id="pa-wizard-body"><div class="pa-wizard-stage" id="pa-wizard-stage"></div></div>' +
          '<footer class="pa-dialog-actions pa-booking-footer" id="pa-booking-footer"></footer>' +
        '</section>' +
      '</div>';

    root.hidden = false;
    document.body.classList.add("modal-open");
    root.querySelector("#pa-dialog-title")?.focus({ preventScroll: true });
    return root;
  }

  function wizardPrefersReducedMotion() {
    return Boolean(window.matchMedia?.("(prefers-reduced-motion: reduce)").matches);
  }

  function focusWizardTarget(view, selector = "") {
    const target = selector ? view?.querySelector(selector) : null;
    const fallback = view?.querySelector(".pa-booking-step > h3, .pa-booking-loading");
    if (fallback && fallback.matches("h3")) fallback.setAttribute("tabindex", "-1");
    (target || fallback)?.focus?.({ preventScroll: true });
  }

  function renderWizardStage(html, options = {}) {
    const root = $("appointments-dialog-root");
    const panel = root?.querySelector(".pa-dialog--wizard");
    const stage = $("pa-wizard-stage");
    if (!panel || !stage) return null;

    const previous = stage.querySelector(".pa-step-view:not(.is-leaving)");
    const next = document.createElement("div");
    next.className = "pa-step-view";
    next.innerHTML = html;

    const animate = options.animate === true && Boolean(previous) && !wizardPrefersReducedMotion();
    if (!animate) {
      if (state.wizardTransitionTimer) window.clearTimeout(state.wizardTransitionTimer);
      state.wizardTransitionTimer = 0;
      state.wizardTransitioning = false;
      stage.replaceChildren(next);
      panel.style.height = "";
      panel.style.removeProperty("transition");
      if (options.focus) focusWizardTarget(next, options.focusSelector || "");
      return next;
    }

    const oldHeight = panel.getBoundingClientRect().height;
    panel.style.height = oldHeight + "px";
    previous.classList.add("is-leaving");
    previous.setAttribute("aria-hidden", "true");
    previous.inert = true;
    stage.append(next);
    next.classList.add("is-entering");

    panel.style.height = "auto";
    const targetHeight = panel.getBoundingClientRect().height;
    panel.style.height = oldHeight + "px";
    panel.offsetHeight;
    state.wizardTransitioning = true;
    panel.classList.add("is-transitioning");
    $("pa-booking-footer")?.querySelectorAll("button").forEach((button) => { button.disabled = true; });

    window.requestAnimationFrame(() => {
      panel.style.height = targetHeight + "px";
      next.classList.add("is-visible");
      if (options.focus) focusWizardTarget(next, options.focusSelector || "");
    });

    if (state.wizardTransitionTimer) window.clearTimeout(state.wizardTransitionTimer);
    state.wizardTransitionTimer = window.setTimeout(() => {
      previous.remove();
      next.classList.remove("is-entering", "is-visible");
      panel.style.height = "";
      panel.style.removeProperty("transition");
      panel.classList.remove("is-transitioning");
      const footer = $("pa-booking-footer");
      if (footer && state.booking) footer.innerHTML = bookingFooterContents();
      state.wizardTransitioning = false;
      state.wizardTransitionTimer = 0;
    }, 250);
    return next;
  }

  function updateWizardStage(html, options = {}) {
    const stage = $("pa-wizard-stage");
    const panel = $("appointments-dialog-root")?.querySelector(".pa-dialog--wizard");
    if (!stage || !panel) return null;
    const current = stage.querySelector(".pa-step-view:not(.is-leaving)");
    if (!current) return renderWizardStage(html, options);

    const oldHeight = panel.getBoundingClientRect().height;
    current.innerHTML = html;
    if (!wizardPrefersReducedMotion() && options.animateHeight === true) {
      const targetHeight = panel.getBoundingClientRect().height;
      if (Math.abs(targetHeight - oldHeight) > 1) {
        panel.style.height = oldHeight + "px";
        panel.offsetHeight;
        panel.style.height = targetHeight + "px";
        if (state.wizardHeightTimer) window.clearTimeout(state.wizardHeightTimer);
        state.wizardHeightTimer = window.setTimeout(() => {
          panel.style.height = "";
          panel.style.removeProperty("transition");
          state.wizardHeightTimer = 0;
        }, 250);
      }
    } else {
      panel.style.height = "";
      panel.style.removeProperty("transition");
    }
    if (options.focus) focusWizardTarget(current, options.focusSelector || "");
    return current;
  }

  function closeDialog() {
    const root = $("appointments-dialog-root");
    if (!root || root.hidden) return;
    root.hidden = true;
    root.replaceChildren();
    document.body.classList.remove("modal-open");
    const closeCallback = state.dialogClose;
    state.dialogClose = null;
    if (state.wizardTransitionTimer) window.clearTimeout(state.wizardTransitionTimer);
    if (state.wizardHeightTimer) window.clearTimeout(state.wizardHeightTimer);
    state.wizardTransitionTimer = 0;
    state.wizardHeightTimer = 0;
    state.wizardTransitioning = false;
    state.wizardRenderedStep = 0;
    state.booking = null;
    state.rating = null;
    state.busy = false;
    if (typeof closeCallback === "function") closeCallback();
    if (state.returnFocus?.isConnected) state.returnFocus.focus({ preventScroll: true });
    state.returnFocus = null;
  }

  function focusableDialogElements() {
    return Array.from($("appointments-dialog-root")?.querySelectorAll(
      'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])'
    ) || []).filter((node) =>
      !node.hidden &&
      !node.closest("[inert]") &&
      !node.closest('[aria-hidden="true"]')
    );
  }

  function bindDocumentKeys(event) {
    const root = $("appointments-dialog-root");
    if (!root || root.hidden) return;
    if (event.key === "Escape") {
      event.preventDefault();
      if (!state.busy) closeDialog();
      return;
    }
    if (event.key === "Tab") {
      const items = focusableDialogElements();
      if (!items.length) return;
      const first = items[0];
      const last = items[items.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    }
  }

  function showConfirmation(title, message, confirmLabel, callback, danger = false) {
    const body = '<div class="pa-confirm-body"><span class="pa-confirm-mark" aria-hidden="true">!</span><p>' + esc(message) + '</p></div>' +
      '<div class="pa-dialog-actions"><button type="button" class="pa-secondary-button" data-dialog-close>Keep appointment</button>' +
      '<button type="button" class="' + (danger ? "pa-danger-button" : "pa-primary-button") + '" data-dialog-action="confirm">' + esc(confirmLabel) + '</button></div>';
    state.confirmCallback = callback;
    openDialog(title, body, { eyebrow: "PLEASE CONFIRM", description: "Review this action before continuing." });
  }

  async function runConfirmedAction() {
    if (state.busy || typeof state.confirmCallback !== "function") return;
    state.busy = true;
    const callback = state.confirmCallback;
    state.confirmCallback = null;
    const button = document.querySelector('[data-dialog-action="confirm"]');
    if (button) button.disabled = true;
    try {
      await callback();
      closeDialog();
    } catch (error) {
      state.busy = false;
      if (button) button.disabled = false;
      showToast(error.message || "The action could not be completed.", "error");
    }
  }

  async function requestAppointmentsAction(url, payload, successMessage) {
    await apiRequest(url, {
      method: "POST",
      body: JSON.stringify(payload),
    });
    closeDialog();
    showToast(successMessage, "success");
    state.page = 1;
    await loadAppointments();
  }

  function openDetails(appt) {
    const [label, tone] = statusBadge(appt);
    const pending = appt.pending_reschedule_date
      ? '<div class="pa-detail-callout"><strong>' + (appt.reschedule_status === "suggested" ? "Doctor’s proposed time" : "Requested time") + '</strong><span>' +
        esc(formatDate(appt.pending_reschedule_date)) + (appt.pending_reschedule_time ? " · " + esc(formatTime(appt.pending_reschedule_time)) : "") + '</span>' +
        (appt.reschedule_reason ? '<p>' + esc(appt.reschedule_reason) + '</p>' : "") + '</div>'
      : "";
    const notes = appt.notes ? '<div class="pa-detail-block"><span>Notes shared when booking</span><p>' + esc(appt.notes) + '</p></div>' : "";
    const booked = appt.booked_at && validDate(appt.booked_at.slice(0, 10))
      ? formatDate(appt.booked_at.slice(0, 10)) : "—";
    const calendarAction = isFutureAppointment(appt)
      ? '<button type="button" class="pa-secondary-button" data-appointments-action="calendar" data-appointment-id="' + appt.id + '">Add to calendar</button>'
      : "";
    const body =
      '<div class="pa-detail-summary"><span class="pa-status" data-status="' + tone + '">' + esc(label) + '</span><h3>' + esc(appt.doctor) + '</h3><p>' + esc(appt.doctor_specialty || appt.department || "Specialist") + '</p></div>' +
      '<div class="pa-detail-grid"><div class="pa-detail-block"><span>Appointment date</span><strong>' + esc(formatDate(appt.date)) + '</strong></div>' +
      '<div class="pa-detail-block"><span>Time</span><strong>' + esc(appointmentTime(appt)) + '</strong></div>' +
      '<div class="pa-detail-block"><span>Department</span><strong>' + esc(appt.department || "—") + '</strong></div>' +
      '<div class="pa-detail-block"><span>Reference</span><strong>#' + appt.id + '</strong></div>' +
      '<div class="pa-detail-block"><span>Booked</span><strong>' + esc(booked) + '</strong></div></div>' + '<div class="pa-dialog-actions"><button type="button" class="pa-secondary-button" data-dialog-close>Close</button>' + calendarAction + '</div>';
    openDialog("Appointment details", body, { eyebrow: "VISIT INFORMATION", description: "Your current appointment information and any outstanding change request." });
  }

  function normalizeDoctor(value) {
    if (!record(value)) return null;
    const id = positiveId(value.user_id ?? value.id);
    if (!id) return null;
    return {
      id,
      name: text(value.name, "Doctor").slice(0, 160),
      specialty: text(value.specialty).slice(0, 160),
      department: text(value.department_name || value.specialty, "General").slice(0, 160),
      available: value.available === true || value.available === 1 || value.available === "1",
      rating: Number.isFinite(Number(value.rating)) ? Math.min(5, Math.max(0, Number(value.rating))) : 0,
      ratingCount: count(value.rating_count, 0),
      experience: count(value.exp, 0),
    };
  }

  async function loadDoctors() {
    if (Array.isArray(state.doctors)) return state.doctors;
    const result = await apiRequest(API.doctors);
    const list = Array.isArray(result.doctors) ? result.doctors : [];
    state.doctors = list.map(normalizeDoctor).filter(Boolean);
    return state.doctors;
  }

  function departmentNames(doctors) {
    return [...new Set(doctors.map((doctor) => doctor.department).filter((name) => name.trim()))]
      .sort((a, b) => a.localeCompare(b));
  }

  async function openBooking(initial = {}) {
    const booking = state.booking = {
      mode: initial.appointment ? "reschedule" : "book",
      appointment: initial.appointment || null,
      step: initial.appointment ? 3 : 1,
      department: initial.appointment ? text(initial.appointment.department) : "",
      doctorId: initial.appointment ? positiveId(initial.appointment.doctor_id) : 0,
      date: "",
      time: "",
      slots: [],
      slotMessage: "Select a date to check availability.",
      slotsLoading: false,
      patientName: text(state.user?.name, ""),
      notes: "",
      reason: "",
    };

    openBookingWizardShell();
    updateBookingWizardHeading();
    $("pa-wizard-progress-wrap").hidden = true;
    $("pa-booking-footer").hidden = true;
    renderWizardStage(
      '<div class="pa-booking-loading" role="status"><span class="pa-spinner" aria-hidden="true"></span><p>Preparing the booking options…</p></div>'
    );

    try {
      const doctors = await loadDoctors();
      if (state.booking !== booking) return;
      if (!doctors.length) {
        renderBookingMessage("No doctors are currently listed for booking. Please try again later.");
        return;
      }
      if (initial.appointment) {
        const appt = initial.appointment;
        const doctor = doctors.find((item) => item.id === positiveId(appt.doctor_id))
          || doctors.find((item) => item.name === appt.doctor && item.department === appt.department);
        if (!doctor) {
          renderBookingMessage("The doctor linked to this appointment is no longer available in the directory. Please contact the hospital to request a change.");
          return;
        }
        booking.doctorId = doctor.id;
        booking.department = doctor.department;
      } else {
        const params = new URLSearchParams(window.location.search);
        if (params.get("book") === "1") {
          booking.department = text(params.get("department"));
          const name = text(params.get("doctor"));
          const match = doctors.find((doctor) => doctor.name === name &&
            (!booking.department || doctor.department === booking.department));
          if (match) booking.doctorId = match.id;
          if (booking.doctorId) booking.step = 3;
          else if (booking.department) booking.step = 2;
        }
      }
      renderBookingDialog({ focus: true });
    } catch (error) {
      if (state.booking !== booking) return;
      renderBookingMessage(error.message || "We could not load doctors. Please close this window and try again.");
    }
  }

  function updateBookingWizardHeading(message = false) {
    const reschedule = state.booking?.mode === "reschedule";
    const title = reschedule ? "Request a new appointment time" : "Book an appointment";
    const heading = $("pa-dialog-title");
    const eyebrow = $("pa-dialog-eyebrow");
    const description = $("pa-dialog-description");
    if (heading) heading.textContent = title;
    if (eyebrow) eyebrow.textContent = message ? "BOOKING UNAVAILABLE" : (reschedule ? "RESCHEDULE REQUEST" : "APPOINTMENT BOOKING");
    if (description) description.textContent = message
      ? "We could not prepare the next step."
      : (reschedule
        ? "Choose a different available time for your existing appointment."
        : "Find the right specialist and request a convenient visit time.");
  }

  function renderBookingMessage(message) {
    if (!$("appointments-dialog-root")?.querySelector(".pa-dialog--wizard")) openBookingWizardShell();
    updateBookingWizardHeading(true);
    $("pa-wizard-progress-wrap").hidden = true;
    $("pa-booking-footer").hidden = true;
    renderWizardStage(
      '<div class="pa-state-card pa-dialog-state"><span class="pa-state-symbol" aria-hidden="true">!</span><h3>Booking options unavailable</h3><p>' +
      esc(message) + '</p><div class="pa-dialog-actions"><button type="button" class="pa-secondary-button" data-dialog-close>Close</button></div></div>',
      { focus: false }
    );
    $("appointments-dialog-root").querySelector(".pa-dialog-state [data-dialog-close]")?.focus({ preventScroll: true });
  }

  function bookingProgressMarkup() {
    const labels = ["Department", "Doctor", "Date & time", "Review"];
    return '<ol class="pa-stepper" id="pa-booking-progress" aria-label="Booking progress" data-completed-steps="0">' +
      labels.map((label, index) =>
        '<li data-progress-step="' + (index + 1) + '">' +
          '<span class="pa-stepper-circle">' + (index + 1) + '</span><small>' + label + '</small>' +
        '</li>'
      ).join("") +
      '</ol>';
  }

  function syncBookingProgress() {
    const booking = state.booking;
    const progress = $("pa-booking-progress");
    if (!booking || !progress) return;
    const step = booking.step;
    const reschedule = booking.mode === "reschedule";
    progress.dataset.currentStep = String(step);
    progress.dataset.completedSteps = String(Math.max(0, Math.min(3, step - 1)));
    progress.querySelectorAll("[data-progress-step]").forEach((item) => {
      const number = count(item.dataset.progressStep, 0);
      const current = number === step;
      const complete = number < step;
      const locked = reschedule && number < 3;
      item.classList.toggle("is-current", current);
      item.classList.toggle("is-complete", complete);
      item.classList.toggle("is-locked", locked);
      if (current) item.setAttribute("aria-current", "step");
      else item.removeAttribute("aria-current");
      const circle = item.querySelector(".pa-stepper-circle");
      if (circle) circle.textContent = complete ? "✓" : String(number);
    });
  }

  function bookingFooterContents() {
    const booking = state.booking;
    const reschedule = booking.mode === "reschedule";
    let back = "";
    if (booking.step > (reschedule ? 3 : 1)) {
      back = '<button type="button" class="pa-secondary-button" data-booking-action="back">Back</button>';
    }
    let next = "";
    if (booking.step < 4) {
      const enabled = booking.step === 1 ? Boolean(booking.department)
        : booking.step === 2 ? Boolean(booking.doctorId)
          : Boolean(booking.date && booking.time && booking.slots.some((slot) => slot.time === booking.time && slot.available) && !booking.slotsLoading);
      next = '<button type="button" class="pa-primary-button" data-booking-action="next" ' + (enabled ? "" : "disabled") + '>Continue <span aria-hidden="true">→</span></button>';
    } else {
      next = '<button type="button" class="pa-primary-button" data-booking-action="submit" ' + (state.busy ? "disabled" : "") + '>' +
        (reschedule ? "Send reschedule request" : "Confirm appointment") + '</button>';
    }
    return back + next;
  }

  function renderBookingStep() {
    const booking = state.booking;
    const doctors = Array.isArray(state.doctors) ? state.doctors : [];
    const departments = departmentNames(doctors);
    const reschedule = booking.mode === "reschedule";
    if (booking.step === 1) {
      return '<section class="pa-booking-step"><p class="pa-eyebrow">STEP 01 · DEPARTMENT</p><h3>What kind of care do you need?</h3><p class="pa-step-copy">Choose a department to see the specialists who work there.</p>' +
        '<div class="pa-choice-grid pa-department-grid">' + departments.map((name) =>
          '<button type="button" class="pa-choice-card ' + (booking.department === name ? "is-selected" : "") + '" data-department="' + esc(name) + '" aria-pressed="' + String(booking.department === name) + '">' +
            '<span class="pa-choice-symbol" aria-hidden="true">＋</span><strong>' + esc(name) + '</strong><small>Explore specialists</small></button>'
        ).join("") + '</div></section>';
    }
    if (booking.step === 2) {
      const filtered = doctors.filter((doctor) => doctor.department === booking.department);
      return '<section class="pa-booking-step"><p class="pa-eyebrow">STEP 02 · SPECIALIST</p><h3>Choose your doctor</h3><p class="pa-step-copy">Select a specialist in ' + esc(booking.department) + '.</p>' +
        '<div class="pa-doctor-choice-list">' + filtered.map((doctor) =>
          '<button type="button" class="pa-doctor-choice ' + (booking.doctorId === doctor.id ? "is-selected" : "") + '"' +
          (doctor.available ? "" : " disabled") + ' data-doctor-id="' + doctor.id + '" aria-pressed="' + String(booking.doctorId === doctor.id) + '">' +
            '<span class="pa-doctor-avatar" aria-hidden="true">' + esc(doctor.name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join("").toUpperCase()) + '</span>' +
            '<span class="pa-doctor-choice-copy"><strong>' + esc(doctor.name) + '</strong><small>' + esc(doctor.specialty || doctor.department) + '</small></span>' +
            '<span class="pa-doctor-availability" data-available="' + String(doctor.available) + '">' + (doctor.available ? "Available" : "Unavailable") + '</span></button>'
        ).join("") + '</div>' +
        (filtered.every((doctor) => !doctor.available) ? '<p class="pa-inline-note">No doctors in this department are currently accepting bookings.</p>' : "") +
        '</section>';
    }
    if (booking.step === 3) {
      const doctor = doctors.find((item) => item.id === booking.doctorId);
      if (reschedule) {
        return '<section class="pa-booking-step"><p class="pa-eyebrow">STEP 03 · NEW TIME</p><h3>Choose a new date and time</h3><p class="pa-step-copy">Your doctor and department stay the same. Your current appointment remains unchanged until the doctor responds.</p>' +
          '<div class="pa-locked-doctor"><span class="pa-doctor-avatar" aria-hidden="true">' +
          esc((doctor?.name || booking.appointment.doctor || "DR").split(/\s+/).slice(0, 2).map((part) => part[0]).join("").toUpperCase()) +
          '</span><div><strong>' + esc(doctor?.name || booking.appointment.doctor) + '</strong><small>' + esc(booking.department) + '</small></div><span>Fixed</span></div>' +
          dateAndSlotsMarkup(booking) + '</section>';
      }
      return '<section class="pa-booking-step"><p class="pa-eyebrow">STEP 03 · DATE & TIME</p><h3>Find a time that works</h3><p class="pa-step-copy">Available slots are checked against the doctor’s current schedule.</p>' +
        '<div class="pa-selected-doctor"><strong>' + esc(doctor?.name || "Selected doctor") + '</strong><span>' + esc(booking.department) + '</span></div>' +
        dateAndSlotsMarkup(booking) + '</section>';
    }
    const doctor = doctors.find((item) => item.id === booking.doctorId);
    const selectedSlot = booking.slots.find((slot) => slot.time === booking.time);
    return '<section class="pa-booking-step pa-booking-step--review"><p class="pa-eyebrow">STEP 04 · REVIEW</p><h3>' + (reschedule ? "Review your time request" : "Review your appointment") + '</h3>' +
      '<p class="pa-step-copy">' + (reschedule ? "The doctor will review your request. The existing appointment remains in place until it is approved." : "Check the details before sending your booking request.") + '</p>' +
      '<div class="pa-review-card"><div><span>Department</span><strong>' + esc(booking.department) + '</strong></div>' +
      '<div><span>Doctor</span><strong>' + esc(doctor?.name || booking.appointment?.doctor || "—") + '</strong></div>' +
      '<div><span>Date</span><strong>' + esc(formatDate(booking.date)) + '</strong></div>' +
      '<div><span>Time</span><strong>' + esc(selectedSlot?.label || formatTime(booking.time)) + '</strong></div></div>' +
      (reschedule
        ? '<label class="pa-field"><span>Reason for changing the time <small>Optional</small></span><textarea id="booking-reason" maxlength="500" rows="3" placeholder="Add a short note for your doctor…">' + esc(booking.reason) + '</textarea></label>'
        : '<label class="pa-field"><span>Patient name</span><input id="booking-patient-name" type="text" maxlength="160" required value="' + esc(booking.patientName) + '" autocomplete="name"></label>' +
          '<label class="pa-field"><span>Notes for the doctor <small>Optional</small></span><textarea id="booking-notes" maxlength="2000" rows="3" placeholder="Share anything you would like the doctor to know…">' + esc(booking.notes) + '</textarea></label>') +
      '</section>';
  }

  function dateAndSlotsMarkup(booking) {
    const slotContent = booking.slotsLoading
      ? '<div class="pa-slot-message" role="status"><span class="pa-spinner" aria-hidden="true"></span> Checking available times…</div>'
      : booking.slots.length
        ? '<div class="pa-slot-grid">' + booking.slots.map((slot) =>
          '<button type="button" class="pa-slot-button ' + (booking.time === slot.time ? "is-selected" : "") +
          '" data-slot-time="' + esc(slot.time) + '" aria-pressed="' + String(booking.time === slot.time && slot.available) + '"' + (slot.available ? "" : " disabled aria-disabled=\"true\"") + '><span>' + esc(slot.label) + '</span>' + (slot.available ? "" : '<small>' + esc(slot.stateLabel || "Taken") + '</small>') + '</button>'
        ).join("") + '</div>'
        : '<p class="pa-slot-message" role="status">' + esc(booking.slotMessage || "Choose a date to check availability.") + '</p>';
    return '<label class="pa-field pa-date-field"><span>Choose a date</span><input id="booking-date" type="date" min="' + localDateValue() + '" value="' + esc(booking.date) + '"></label>' +
      '<div class="pa-slot-area' + (booking.slotsLoading || booking.slots.length ? "" : " pa-slot-area--empty") + '"><div class="pa-slot-heading"><strong>Available times</strong><span>Times are shown in your local time</span></div>' +
      '<div id="booking-slots" aria-live="polite">' + slotContent + '</div></div>';
  }

  function renderBookingDialog(options = {}) {
    if (!state.booking) return;
    if (!$("appointments-dialog-root")?.querySelector(".pa-dialog--wizard")) openBookingWizardShell();
    updateBookingWizardHeading();
    const step = state.booking.step;
    const footer = $("pa-booking-footer");
    const progressWrap = $("pa-wizard-progress-wrap");
    if (progressWrap) progressWrap.hidden = false;
    if (footer) {
      footer.hidden = false;
      footer.innerHTML = bookingFooterContents();
    }
    syncBookingProgress();

    const html = renderBookingStep();
    const changedStep = state.wizardRenderedStep !== step;
    const focusSelector = options.focusSelector || (step === 3 ? "#booking-date" : "");
    if (!$("pa-wizard-stage")?.querySelector(".pa-step-view")) {
      renderWizardStage(html, { focus: options.focus === true, focusSelector });
    } else if (changedStep && options.transition === true) {
      renderWizardStage(html, { animate: true, focus: options.focus !== false, focusSelector });
    } else {
      updateWizardStage(html, {
        animateHeight: !changedStep,
        focus: options.focus === true,
        focusSelector,
      });
    }
    state.wizardRenderedStep = step;
  }

  async function loadAvailableSlots() {
    const booking = state.booking;
    if (!booking || !booking.doctorId || !validDate(booking.date) || booking.date < localDateValue()) return;
    booking.slots = [];
    booking.time = "";
    booking.slotMessage = "Checking the selected date…";
    booking.slotsLoading = true;
    renderBookingDialog();
    const requestToken = (booking.slotRequestToken || 0) + 1;
    booking.slotRequestToken = requestToken;
    try {
      const result = await apiRequest(API.slots, {
        method: "POST",
        body: JSON.stringify({ doctor_id: booking.doctorId, date: booking.date }),
      });
      if (state.booking !== booking || booking.slotRequestToken !== requestToken) return;
      const sourceSlots = Array.isArray(result.slots) ? result.slots : [];
      booking.slots = sourceSlots.filter((slot) => record(slot) && validTime(slot.time))
        .map((slot) => {
          const status = text(slot.status).trim().toLowerCase();
          const explicitlyUnavailable = slot.available === false || slot.available === 0 || slot.available === "0"
            || slot.is_available === false || slot.is_available === 0 || slot.is_available === "0"
            || ["taken", "booked", "occupied", "unavailable"].includes(status);
          const available = !explicitlyUnavailable;
          return {
            time: slot.time,
            label: text(slot.label, formatTime(slot.time)).slice(0, 80),
            available,
            stateLabel: available ? "" : text(slot.state_label, "Taken").slice(0, 30),
          };
        });
      booking.slotMessage = booking.slots.length
        ? "Choose one of the available time slots."
        : text(result.message, "No appointment slots are available on this date.");
    } catch (error) {
      if (state.booking !== booking || booking.slotRequestToken !== requestToken) return;
      booking.slotMessage = error.message || "Unable to load available time slots.";
    } finally {
      if (state.booking === booking && booking.slotRequestToken === requestToken) {
        booking.slotsLoading = false;
        renderBookingDialog();
        $("booking-date")?.focus({ preventScroll: true });
      }
    }
  }

  async function submitBooking() {
    const booking = state.booking;
    if (!booking || state.busy) return;
    const doctor = state.doctors?.find((item) => item.id === booking.doctorId);
    const selectedSlot = booking.slots.find((slot) => slot.time === booking.time && slot.available);
    if (!doctor || !booking.department || !validDate(booking.date) || booking.date < localDateValue() || !validTime(booking.time) || !selectedSlot || booking.slotsLoading) {
      showToast("Choose a valid doctor, future date and available time slot.", "error");
      return;
    }
    if (booking.mode === "book") {
      booking.patientName = text($("booking-patient-name")?.value).trim();
      booking.notes = text($("booking-notes")?.value).trim().slice(0, 2000);
      if (!booking.patientName) {
        $("booking-patient-name")?.focus();
        showToast("Enter the patient name to continue.", "error");
        return;
      }
    } else {
      booking.reason = text($("booking-reason")?.value).trim().slice(0, 500);
    }
    state.busy = true;
    const button = document.querySelector('[data-booking-action="submit"]');
    if (button) {
      button.disabled = true;
      button.textContent = booking.mode === "reschedule" ? "Sending request…" : "Submitting booking…";
    }
    try {
      let endpoint;
      let payload;
      if (booking.mode === "reschedule") {
        endpoint = API.reschedule;
        payload = {
          appointment_id: positiveId(booking.appointment?.id),
          new_date: booking.date,
          new_time: booking.time,
          reason: booking.reason,
        };
      } else {
        endpoint = API.book;
        payload = {
          department: booking.department,
          doctor: doctor.name,
          doctor_id: doctor.id,
          date: booking.date,
          time: booking.time,
          patientName: booking.patientName.slice(0, 160),
          notes: booking.notes,
        };
      }
      const result = await apiRequest(endpoint, { method: "POST", body: JSON.stringify(payload) });
      const reference = positiveId(result.appointment_id);
      closeDialog();
      showToast(booking.mode === "reschedule"
        ? "Your reschedule request has been sent to the doctor."
        : "Your appointment booking request was submitted.", "success");
      if (reference) {
        window.history.replaceState({}, "", window.location.pathname);
      }
      state.page = 1;
      await loadAppointments();
    } catch (error) {
      state.busy = false;
      showToast(error.message || "Your request could not be submitted.", "error");
      if (button?.isConnected) button.disabled = false;
    }
  }

  function openRating(appt) {
    state.rating = { appointmentId: appt.id, doctor: appt.doctor, stars: 0, review: "" };
    const body = '<div class="pa-rating-body"><p>How was your visit with <strong>' + esc(appt.doctor) + '</strong>?</p>' +
      '<fieldset class="pa-rating-fieldset"><legend>Your rating</legend><div class="pa-rating-stars">' +
      [1, 2, 3, 4, 5].map((star) => '<button type="button" data-rating-star="' + star + '" aria-label="' + star + (star === 1 ? " star" : " stars") + '" aria-pressed="false">★</button>').join("") +
      '</div><span id="pa-rating-label" aria-live="polite">Select a rating</span></fieldset>' +
      '<label class="pa-field"><span>Review <small>Optional</small></span><textarea id="pa-rating-review" maxlength="500" rows="4" placeholder="Share a few words about your experience…"></textarea></label>' +
      '<div class="pa-dialog-actions"><button type="button" class="pa-secondary-button" data-dialog-close>Cancel</button><button type="button" class="pa-primary-button" data-rating-action="submit" disabled>Submit rating</button></div></div>';
    openDialog("Rate your visit", body, { eyebrow: "PATIENT FEEDBACK", description: "Your feedback can help other patients make informed choices." });
  }

  async function submitRating() {
    if (!state.rating || state.busy || state.rating.stars < 1 || state.rating.stars > 5) return;
    state.rating.review = text($("pa-rating-review")?.value).trim().slice(0, 500);
    state.busy = true;
    const button = document.querySelector('[data-rating-action="submit"]');
    if (button) {
      button.disabled = true;
      button.textContent = "Submitting…";
    }
    try {
      await apiRequest(API.submitRating, {
        method: "POST",
        body: JSON.stringify({
          appointment_id: state.rating.appointmentId,
          stars: state.rating.stars,
          review: state.rating.review,
        }),
      });
      closeDialog();
      showToast("Thank you for sharing your feedback.", "success");
      await loadAppointments();
    } catch (error) {
      state.busy = false;
      if (button?.isConnected) button.disabled = false;
      showToast(error.message || "Your rating could not be submitted.", "error");
    }
  }

  async function viewRating(appt) {
    try {
      const result = await apiRequest(API.rating + "?appointmentId=" + encodeURIComponent(appt.id));
      const rating = record(result.rating) ? result.rating : null;
      if (!rating) {
        showToast("No rating was found for this appointment.", "info");
        return;
      }
      const stars = count(rating.stars, 0);
      if (stars < 1 || stars > 5) throw new Error("The saved rating could not be displayed.");
      const starMarkup = Array.from({ length: 5 }, (_, index) =>
        '<span aria-hidden="true" class="' + (index < stars ? "is-filled" : "") + '">★</span>'
      ).join("");
      const review = text(rating.review).slice(0, 500);
      const date = text(rating.created_at);
      const body = '<div class="pa-saved-rating"><div class="pa-saved-stars" aria-label="' + stars + ' out of 5 stars">' + starMarkup + '</div>' +
        '<h3>' + stars + ' out of 5</h3>' + (review ? '<blockquote>“' + esc(review) + '”</blockquote>' : '<p>No written review was added.</p>') +
        '<small>' + (date ? "Submitted " + esc(date.slice(0, 10)) : "") + '</small></div>' +
        '<div class="pa-dialog-actions"><button type="button" class="pa-secondary-button" data-dialog-close>Close</button></div>';
      openDialog("Your visit rating", body, { eyebrow: "PATIENT FEEDBACK", description: "Your previously submitted feedback." });
    } catch (error) {
      showToast(error.message || "Unable to load the saved rating.", "error");
    }
  }

  function downloadCalendar(appt) {
    if (!validDate(appt.date) || !validTime(appt.time)) {
      showToast("The appointment time is not available in a calendar-ready format.", "error");
      return;
    }
    const [year, month, day] = appt.date.split("-").map(Number);
    const [hour, minute, second = 0] = appt.time.split(":").map(Number);
    const start = new Date(year, month - 1, day, hour, minute, second);
    if (Number.isNaN(start.getTime())) return;
    const end = new Date(start.getTime() + Math.max(15, Math.min(240, appt.duration || 30)) * 60000);
    const icsDate = (date) => date.toISOString().replace(/[-:]/g, "").replace(/\.\d{3}Z$/, "Z");
    const clean = (value) => text(value).replace(/[\\\r\n,;]/g, " ").slice(0, 240);
    const contents = [
      "BEGIN:VCALENDAR", "VERSION:2.0", "PRODID:-//HealthBridge//Patient Appointment//EN",
      "BEGIN:VEVENT", "UID:" + appt.id + "@healthbridge",
      "DTSTAMP:" + icsDate(new Date()), "DTSTART:" + icsDate(start), "DTEND:" + icsDate(end),
      "SUMMARY:HealthBridge visit with " + clean(appt.doctor),
      "DESCRIPTION:Department: " + clean(appt.department),
      "LOCATION:HealthBridge Medical Center", "END:VEVENT", "END:VCALENDAR",
    ].join("\r\n");
    const blob = new Blob([contents], { type: "text/calendar;charset=utf-8" });
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.href = url;
    link.download = "healthbridge-appointment-" + appt.id + ".ics";
    document.body.append(link);
    link.click();
    link.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
  }

  function findAppointment(id) {
    const safe = positiveId(id);
    return safe ? state.appointments.find((appt) => appt.id === safe) || null : null;
  }

  function handleAppointmentAction(action, id) {
    const appt = findAppointment(id);
    if (action === "book") {
      openBooking();
      return;
    }
    if (action === "reload") {
      loadAppointments();
      return;
    }
    if (!appt) return;
    if (action === "view") openDetails(appt);
    else if (action === "reschedule") openBooking({ appointment: appt });
    else if (action === "rate") openRating(appt);
    else if (action === "view-rating") viewRating(appt);
    else if (action === "calendar") downloadCalendar(appt);
    else if (action === "cancel") {
      showConfirmation(
        "Cancel this appointment?",
        "You are cancelling the appointment with " + appt.doctor + " on " + formatDate(appt.date) + " at " + appointmentTime(appt) + ". This action cannot be undone.",
        "Yes, cancel appointment",
        () => requestAppointmentsAction(API.cancel, { id: appt.id }, "Your appointment was cancelled."),
        true
      );
    } else if (action === "accept-suggestion") {
      showConfirmation(
        "Accept the suggested time?",
        "The doctor’s suggested date and time will replace the currently scheduled slot.",
        "Accept suggested time",
        () => requestAppointmentsAction(API.acceptSuggestion, { appointment_id: appt.id }, "The suggested appointment time was accepted.")
      );
    } else if (action === "decline-suggestion") {
      showConfirmation(
        "Decline the suggested time?",
        "The suggested time will be declined. The appointment will remain subject to the doctor’s response.",
        "Decline suggestion",
        () => requestAppointmentsAction(API.declineSuggestion, { appointment_id: appt.id }, "The suggested time was declined."),
        true
      );
    } else if (action === "cancel-reschedule") {
      showConfirmation(
        "Cancel your reschedule request?",
        "The appointment will remain at its originally scheduled date and time.",
        "Cancel request",
        () => requestAppointmentsAction(API.cancelReschedule, { appointment_id: appt.id }, "Your reschedule request was cancelled."),
        true
      );
    }
  }

  function handleDialogClick(event) {
    const target = event.target instanceof Element ? event.target : null;
    if (!target) return;
    const close = target.closest("[data-dialog-close]");
    if (close) {
      if (!state.busy) closeDialog();
      return;
    }
    const confirm = target.closest('[data-dialog-action="confirm"]');
    if (confirm) {
      runConfirmedAction();
      return;
    }
    const bookingAction = target.closest("[data-booking-action]");
    const department = target.closest("[data-department]");
    const doctor = target.closest("[data-doctor-id]");
    const slot = target.closest("[data-slot-time]");
    if (state.wizardTransitioning && (bookingAction || department || doctor || slot)) return;
    if (bookingAction) {
      const action = bookingAction.dataset.bookingAction;
      if (action === "next" && state.booking) {
        state.booking.step = Math.min(4, state.booking.step + 1);
        renderBookingDialog({ transition: true, focus: true });
      } else if (action === "back" && state.booking) {
        state.booking.step = Math.max(state.booking.mode === "reschedule" ? 3 : 1, state.booking.step - 1);
        renderBookingDialog({ transition: true, focus: true });
      } else if (action === "submit") {
        submitBooking();
      }
      return;
    }
    if (department && state.booking?.mode === "book") {
      state.booking.department = text(department.dataset.department);
      state.booking.doctorId = 0;
      state.booking.date = "";
      state.booking.time = "";
      state.booking.slots = [];
      state.booking.step = 2;
      renderBookingDialog({ transition: true, focus: true });
      return;
    }
    if (doctor && state.booking?.mode === "book" && !doctor.disabled) {
      state.booking.doctorId = positiveId(doctor.dataset.doctorId);
      state.booking.date = "";
      state.booking.time = "";
      state.booking.slots = [];
      state.booking.step = 3;
      renderBookingDialog({ transition: true, focus: true });
      return;
    }
    if (slot && state.booking) {
      const selected = state.booking.slots.find((item) => item.time === slot.dataset.slotTime);
      if (!selected || !selected.available) return;
      state.booking.time = validTime(slot.dataset.slotTime) ? slot.dataset.slotTime : "";
      renderBookingDialog({ focus: true, focusSelector: '[data-slot-time="' + slot.dataset.slotTime + '"]' });
      return;
    }
    const star = target.closest("[data-rating-star]");
    if (star && state.rating) {
      const selected = count(star.dataset.ratingStar, 0);
      if (selected < 1 || selected > 5) return;
      state.rating.stars = selected;
      $("appointments-dialog-root").querySelectorAll("[data-rating-star]").forEach((button) => {
        const active = count(button.dataset.ratingStar, 0) <= selected;
        button.classList.toggle("is-selected", active);
        button.setAttribute("aria-pressed", String(count(button.dataset.ratingStar, 0) === selected));
      });
      $("pa-rating-label").textContent = selected + " out of 5 stars";
      const submit = document.querySelector('[data-rating-action="submit"]');
      if (submit) submit.disabled = false;
      return;
    }
    const ratingSubmit = target.closest('[data-rating-action="submit"]');
    if (ratingSubmit) submitRating();
  }

  async function handleDialogChange(event) {
    const target = event.target;
    if (!(target instanceof HTMLElement) || !state.booking) return;
    if (target.id === "booking-date") {
      const date = text(target.value);
      if (!validDate(date) || date < localDateValue()) {
        state.booking.date = "";
        state.booking.time = "";
        state.booking.slots = [];
        state.booking.slotMessage = "Choose a valid date today or later.";
        renderBookingDialog();
        $("booking-date")?.focus({ preventScroll: true });
        return;
      }
      state.booking.date = date;
      state.booking.time = "";
      await loadAvailableSlots();
    }
  }

  function handleDialogInput(event) {
    const target = event.target;
    if (!(target instanceof HTMLElement)) return;
    if (target.id === "booking-patient-name" && state.booking) state.booking.patientName = text(target.value);
    if (target.id === "booking-notes" && state.booking) state.booking.notes = text(target.value);
    if (target.id === "booking-reason" && state.booking) state.booking.reason = text(target.value);
    if (target.id === "pa-rating-review" && state.rating) state.rating.review = text(target.value);
  }

  function bindPageEvents() {
    document.addEventListener("click", (event) => {
      const target = event.target instanceof Element ? event.target : null;
      if (!target) return;
      const searchToggle = target.closest("#appointments-search-toggle");
      if (searchToggle) {
        const panel = $("appointments-search-panel");
        const input = $("appointments-search");
        const opening = searchToggle.getAttribute("aria-expanded") !== "true";
        if (panel) panel.hidden = !opening;
        searchToggle.setAttribute("aria-expanded", String(opening));
        if (opening) window.requestAnimationFrame(() => input?.focus({ preventScroll: true }));
        else searchToggle.focus({ preventScroll: true });
        return;
      }
      const filter = target.closest("[data-filter]");
      if (filter && filter.closest("#appointments-filters")) {
        state.filter = filter.dataset.filter;
        state.page = 1;
        loadAppointments();
        return;
      }
      const page = target.closest("[data-page]");
      if (page) {
        const nextPage = state.page + (page.dataset.page === "next" ? 1 : -1);
        if (nextPage >= 1 && nextPage <= state.pagination.total_pages) {
          state.page = nextPage;
          loadAppointments();
        }
        return;
      }
      const action = target.closest("[data-appointments-action]");
      if (action) handleAppointmentAction(action.dataset.appointmentsAction, action.dataset.appointmentId);
    });
    $("appointments-search")?.addEventListener("input", (event) => {
      window.clearTimeout(state.searchTimer);
      const value = text(event.target.value).trim().slice(0, 100);
      state.searchTimer = window.setTimeout(() => {
        state.search = value;
        state.page = 1;
        loadAppointments();
      }, 300);
    });
    document.addEventListener("keydown", bindDocumentKeys);
  }

  async function init() {
    if (state.initialized) return;
    state.initialized = true;
    const root = ensureDialogRoot();
    bindPageEvents();
    state.user = typeof getUser === "function" ? getUser() : null;
    if (!state.user?.name && $("patient-profile-name")) state.user = { ...(state.user || {}), name: $("patient-profile-name").textContent };
    await loadAppointments();
    const params = new URLSearchParams(window.location.search);
    if (params.get("view")) {
      const wanted = positiveId(params.get("view"));
      const match = state.appointments.find((appt) => appt.id === wanted);
      if (match) openDetails(match);
    } else if (params.get("book") === "1") {
      openBooking();
    }
  }

  document.addEventListener("patient:shell-ready", (event) => {
    state.user = record(event.detail?.user) ? event.detail.user : (typeof getUser === "function" ? getUser() : null);
    init();
  }, { once: true });
})();
