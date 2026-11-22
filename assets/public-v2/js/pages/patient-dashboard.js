/**
 * HealthBridge Public V2 — Patient overview controller.
 * Renders only the approved Phase 1 overview: next valid appointment,
 * actionable updates, a compact account snapshot, quick actions and one
 * recent-activity feed. The shared patient shell owns authentication and
 * global navigation. The existing patient dashboard API remains unchanged.
 */
"use strict";

(() => {
  const ROUTES = new Set([
    "appointments.html",
    "profile.html",
    "prescriptions.html",
    "notifications.html",
    "history.html",
    "downloads.html",
  ]);

  const ICONS = {
    appointment: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"></rect><path d="M16 3v4M8 3v4M3 10h18M12 13v5M9.5 15.5h5"></path></svg>',
    alert: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m10.3 3.9-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.7-3.1l-8-14a2 2 0 0 0-3.4 0Z"></path><path d="M12 9v4M12 17h.01"></path></svg>',
    profile: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="3.5"></circle><path d="M5 21v-1.5a7 7 0 0 1 14 0V21Z"></path></svg>',
    prescription: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 3h8a2 2 0 0 1 2 2v2H6V5a2 2 0 0 1 2-2Z"></path><path d="M6 7h12v12a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V7Z"></path><path d="M12 10v6M9 13h6"></path></svg>',
    bell: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"></path></svg>',
    history: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12a8 8 0 1 0 2.4-5.7L4 8"></path><path d="M4 3v5h5M12 7v5l3 2"></path></svg>',
    note: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M13 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V10Z"></path><path d="M13 3v7h7M8 14h8M8 17h6"></path></svg>',
    check: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 4 4L19 6"></path></svg>',
  };

  const $ = (id) => document.getElementById(id);
  const escapeHTML = (value) => String(value ?? "").replace(/[&<>"']/g, (character) => ({
    "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;",
  })[character]);

  const safeCount = (value, fallback = 0) => {
    const number = typeof value === "number" ? value
      : typeof value === "string" && /^\d+$/.test(value.trim()) ? Number(value.trim())
      : Number.NaN;
    return Number.isSafeInteger(number) && number >= 0 ? number : fallback;
  };

  const parseDateParts = (value) => {
    const match = String(value ?? "").trim().match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (!match) return null;
    const year = Number(match[1]);
    const month = Number(match[2]);
    const day = Number(match[3]);
    const date = new Date(year, month - 1, day);
    if (date.getFullYear() !== year || date.getMonth() !== month - 1 || date.getDate() !== day) return null;
    return { year, month, day, date };
  };

  const parseTimeParts = (value) => {
    const match = String(value ?? "").trim().match(/(?:^|[^\d])(\d{1,2}):(\d{2})(?::\d{2})?\s*(AM|PM)?/i);
    if (!match) return null;
    let hour = Number(match[1]);
    const minute = Number(match[2]);
    const meridiem = (match[3] || "").toUpperCase();
    if (minute > 59 || hour > 23) return null;
    if (meridiem) {
      if (hour < 1 || hour > 12) return null;
      if (meridiem === "AM" && hour === 12) hour = 0;
      if (meridiem === "PM" && hour !== 12) hour += 12;
    }
    return { hour, minute };
  };

  const localDateKey = (date) => [
    date.getFullYear(),
    String(date.getMonth() + 1).padStart(2, "0"),
    String(date.getDate()).padStart(2, "0"),
  ].join("-");

  const formatDateParts = (parts, options = {}) => parts.date.toLocaleDateString(undefined, {
    weekday: options.weekday ? "short" : undefined,
    year: "numeric",
    month: "short",
    day: "numeric",
  });

  const formatTime = (value) => {
    const parsed = parseTimeParts(value);
    if (!parsed) return String(value ?? "").trim();
    const date = new Date(2000, 0, 1, parsed.hour, parsed.minute);
    return date.toLocaleTimeString(undefined, { hour: "numeric", minute: "2-digit" });
  };

  const getAppointmentDate = (appointment) => {
    const parts = parseDateParts(appointment?.date || appointment?.appointment_date);
    if (!parts) return null;
    const rawTime = appointment?.time || appointment?.time_range
      || appointment?.appointment_time_range || "";
    const time = parseTimeParts(rawTime);
    const todayKey = localDateKey(new Date());
    const dateKey = localDateKey(parts.date);

    // A same-day appointment without a valid time cannot safely be called upcoming.
    if (dateKey === todayKey && !time) return null;

    const scheduledAt = time
      ? new Date(parts.year, parts.month - 1, parts.day, time.hour, time.minute, 0, 0)
      : new Date(parts.year, parts.month - 1, parts.day, 12, 0, 0, 0);

    if (scheduledAt.getTime() <= Date.now()) return null;
    return { ...parts, dateKey, time, scheduledAt };
  };

  const getSafeAppointmentRoute = (route) => {
    const candidate = String(route ?? "").trim().replace(/^\.\//, "");
    return ROUTES.has(candidate) ? candidate : "";
  };

  const getAppointmentCandidates = (data) => {
    const overviewAppointment = data?.overview?.next_appointment;
    const upcoming = Array.isArray(data?.appointments?.upcoming) ? data.appointments.upcoming : [];
    const rescheduled = Array.isArray(data?.appointments?.rescheduled) ? data.appointments.rescheduled : [];
    const source = [...upcoming, ...rescheduled, ...(overviewAppointment ? [overviewAppointment] : [])];
    const seen = new Set();

    return source.flatMap((appointment) => {
      if (!appointment || typeof appointment !== "object" || Array.isArray(appointment)) return [];
      const status = String(appointment.status ?? "").trim().toLowerCase();
      if (["cancelled", "canceled", "completed", "missed"].includes(status)) return [];

      const date = getAppointmentDate(appointment);
      if (!date) return [];

      const key = appointment.id
        ? "id:" + String(appointment.id)
        : [date.dateKey, date.time?.hour ?? "", date.time?.minute ?? "", appointment.doctor ?? ""].join("|");
      if (seen.has(key)) return [];
      seen.add(key);
      return [{ appointment, date }];
    }).sort((a, b) => a.date.scheduledAt - b.date.scheduledAt);
  };

  const getAlertIcon = (type) => {
    if (/appointment/i.test(type)) return ICONS.appointment;
    if (/prescription/i.test(type)) return ICONS.prescription;
    if (/profile|insurance|emergency/i.test(type)) return ICONS.profile;
    if (/notification/i.test(type)) return ICONS.bell;
    return ICONS.alert;
  };

  const getActivityIcon = (type) => {
    if (/appointment|booking|reschedule/i.test(type)) return ICONS.appointment;
    if (/prescription/i.test(type)) return ICONS.prescription;
    if (/profile|registration/i.test(type)) return ICONS.profile;
    if (/record|note|medical|visit/i.test(type)) return ICONS.note;
    return ICONS.check;
  };

  const Dashboard = {
    data: null,
    elements: {},

    cacheElements() {
      [
        "dashboard-main", "dashboard-loading", "dashboard-content", "dashboard-error",
        "welcome-greeting", "hero-subtitle", "dashboard-current-date",
        "hospital-status", "hospital-status-label", "hospital-status-hours",
        "next-appointment-card", "next-appointment-empty", "next-appointment-status",
        "next-appointment-doctor", "next-appointment-department", "next-appointment-date",
        "next-appointment-time", "next-appointment-change",
        "health-alerts-section", "health-alerts-list",
        "snapshot-completed", "snapshot-prescriptions", "snapshot-profile",
        "recent-activity-list", "recent-activity-empty",
      ].forEach((id) => { this.elements[id] = $(id); });
    },

    showLoading() {
      this.elements["dashboard-loading"].hidden = false;
      this.elements["dashboard-content"].hidden = true;
      this.elements["dashboard-error"].hidden = true;
      this.elements["dashboard-main"].setAttribute("aria-busy", "true");
    },

    showError() {
      this.elements["dashboard-loading"].hidden = true;
      this.elements["dashboard-content"].hidden = true;
      this.elements["dashboard-error"].hidden = false;
      this.elements["dashboard-main"].setAttribute("aria-busy", "false");
    },

    showContent() {
      this.elements["dashboard-loading"].hidden = true;
      this.elements["dashboard-error"].hidden = true;
      this.elements["dashboard-content"].hidden = false;
      this.elements["dashboard-main"].setAttribute("aria-busy", "false");
    },

    async init() {
      this.cacheElements();
      this.showLoading();
      await this.load();
      document.addEventListener("click", (event) => {
        const action = event.target instanceof Element
          ? event.target.closest("[data-dashboard-action]")
          : null;
        if (action?.dataset.dashboardAction === "refresh") {
          event.preventDefault();
          this.refresh();
        }
      });
    },

    async refresh() {
      this.showLoading();
      await this.load();
    },

    async load() {
      const response = await this.fetchDashboard();
      if (!response.ok || !response.data?.success || !response.data?.data) {
        this.showError();
        return;
      }

      this.data = response.data.data;
      this.render();
      this.showContent();
    },

    async fetchDashboard() {
      try {
        const response = await fetch(getBasePath() + "api/patient/dashboard.php", {
          method: "POST",
          credentials: "same-origin",
          headers: { "Content-Type": "application/json", Accept: "application/json" },
          body: JSON.stringify({}),
        });
        const data = await response.json().catch(() => null);
        return { ok: response.ok, status: response.status, data };
      } catch {
        if (typeof showToast === "function") {
          showToast("Could not reach your dashboard. Please try again.", "error");
        }
        return { ok: false, status: 0, data: null };
      }
    },

    render() {
      this.renderGreeting();
      this.renderHospitalStatus();
      this.renderNextAppointment();
      this.renderAlerts();
      this.renderSnapshot();
      this.renderActivity();
    },

    renderGreeting() {
      const overview = this.data?.overview || {};
      const firstName = typeof overview.first_name === "string" && overview.first_name.trim()
        ? overview.first_name.trim()
        : "there";
      const greeting = typeof overview.greeting === "string" && overview.greeting.trim()
        ? overview.greeting.trim()
        : "Welcome back";

      this.elements["welcome-greeting"].textContent = greeting + ", " + firstName;
      this.elements["hero-subtitle"].textContent = "Here’s what’s coming up in your care.";
      const now = new Date();
      this.elements["dashboard-current-date"].textContent = now.toLocaleDateString(undefined, {
        weekday: "long", year: "numeric", month: "long", day: "numeric",
      });
      this.elements["dashboard-current-date"].dateTime = [
        now.getFullYear(),
        String(now.getMonth() + 1).padStart(2, "0"),
        String(now.getDate()).padStart(2, "0"),
      ].join("-");
    },

    renderHospitalStatus() {
      const hospital = this.data?.overview?.hospital || {};
      const status = String(hospital.status ?? "").trim().toLowerCase();
      const isOpen = typeof hospital.is_open === "boolean" ? hospital.is_open
        : (hospital.is_open === 1 || hospital.is_open === "1" || status === "open");
      const known = typeof hospital.is_open === "boolean"
        || hospital.is_open === 1
        || hospital.is_open === 0
        || hospital.is_open === "1"
        || hospital.is_open === "0"
        || status === "open"
        || status === "closed";

      if (!known) {
        this.elements["hospital-status"].hidden = true;
        return;
      }

      this.elements["hospital-status"].hidden = false;
      this.elements["hospital-status"].classList.toggle("is-open", isOpen);
      this.elements["hospital-status"].classList.toggle("is-closed", !isOpen);
      const closedReason = String(hospital.closed_reason ?? "").trim().toLowerCase();
      const dayName = typeof hospital.day_name === "string" ? hospital.day_name.trim() : "";
      this.elements["hospital-status-label"].textContent = isOpen
        ? "Appointment booking is open now"
        : closedReason === "weekend"
          ? "Appointment booking is closed today" + (dayName ? " · " + dayName : "")
          : closedReason === "outside_hours"
            ? "Appointment booking is closed outside booking hours"
            : "Appointment booking is currently closed";

      const openAt = formatTime(hospital.open_at || hospital.open_time || "");
      const closeAt = formatTime(hospital.close_at || hospital.close_time || "");
      const operatingDays = String(hospital.operating_days || "").trim().replace("Monday-Friday", "Mon–Fri");
      this.elements["hospital-status-hours"].textContent = openAt && closeAt
        ? "Booking hours · " + (operatingDays ? operatingDays + " · " : "") + openAt + "–" + closeAt
        : "Current booking availability";
    },

    renderNextAppointment() {
      const candidates = getAppointmentCandidates(this.data);
      const selected = candidates[0] || null;

      if (!selected) {
        this.elements["next-appointment-card"].hidden = true;
        this.elements["next-appointment-empty"].hidden = false;
        this.elements["hero-subtitle"].textContent = "You have no upcoming appointments. You can book a visit whenever you’re ready.";
        return;
      }

      const appointment = selected.appointment;
      const date = selected.date;
      const status = String(appointment.status ?? "").trim().toLowerCase();
      const changePending = String(appointment.reschedule_status ?? "").trim().toLowerCase() === "pending"
        || status === "reschedule requested";
      const isAwaitingConfirmation = status === "pending";

      this.elements["next-appointment-card"].hidden = false;
      this.elements["next-appointment-empty"].hidden = true;
      this.elements["next-appointment-status"].textContent = changePending
        ? "Change request pending"
        : isAwaitingConfirmation ? "Awaiting confirmation" : "Upcoming";
      this.elements["next-appointment-status"].className = "appointment-state-badge"
        + (changePending ? " is-pending" : isAwaitingConfirmation ? " is-awaiting" : "");

      this.elements["next-appointment-doctor"].textContent =
        typeof appointment.doctor === "string" && appointment.doctor.trim()
          ? appointment.doctor.trim()
          : "Your care provider";

      const department = typeof appointment.department === "string"
        ? appointment.department
        : typeof appointment.department?.name === "string" ? appointment.department.name : "";
      this.elements["next-appointment-department"].textContent = department || "Department not listed";

      this.elements["next-appointment-date"].textContent = formatDateParts(date, { weekday: true });
      const rawTimeRange = String(appointment.time_range || appointment.appointment_time_range || "").trim();
      const rawTime = String(appointment.time || "").trim();
      const timeLabel = rawTimeRange || (rawTime ? formatTime(rawTime) : "Time to be confirmed");
      this.elements["next-appointment-time"].textContent = timeLabel;

      this.elements["next-appointment-change"].hidden = !changePending;
      this.elements["next-appointment-change"].textContent = changePending
        ? "Your requested appointment change is waiting for a response. The time shown is your currently scheduled visit."
        : "";
    },

    renderAlerts() {
      const raw = Array.isArray(this.data?.health_alerts?.alerts)
        ? this.data.health_alerts.alerts
        : [];
      const next = getAppointmentCandidates(this.data)[0] || null;
      const today = localDateKey(new Date());
      const filtered = raw.filter((alert) => {
        if (!alert || typeof alert !== "object" || Array.isArray(alert)) return false;
        const type = String(alert.type ?? "").toLowerCase();
        if (type === "hospital_closed") return false;
        if (type === "appointment_today" && (!next || next.date.dateKey !== today)) return false;
        return true;
      }).slice(0, 3);

      if (!filtered.length) {
        this.elements["health-alerts-section"].hidden = true;
        this.elements["health-alerts-list"].replaceChildren();
        return;
      }

      this.elements["health-alerts-section"].hidden = false;
      this.elements["health-alerts-list"].innerHTML = filtered.map((alert) => {
        const type = String(alert.type ?? "");
        const severity = ["danger", "warning", "info", "success"].includes(String(alert.severity ?? "").toLowerCase())
          ? String(alert.severity).toLowerCase()
          : "info";
        const route = getSafeAppointmentRoute(alert.action?.url);
        const action = route && typeof alert.action?.label === "string"
          ? '<a class="dashboard-alert__action" href="' + escapeHTML(route) + '">' + escapeHTML(alert.action.label) + ' <span aria-hidden="true">→</span></a>'
          : "";

        return '<article class="dashboard-alert is-' + severity + '">'
          + '<span class="dashboard-alert__icon">' + getAlertIcon(type) + '</span>'
          + '<div class="dashboard-alert__copy"><h3>' + escapeHTML(alert.title || "Update") + '</h3>'
          + '<p>' + escapeHTML(alert.message || "") + '</p></div>'
          + action
          + '</article>';
      }).join("");
    },

    renderSnapshot() {
      const snapshot = this.data?.health_snapshot || {};
      this.elements["snapshot-completed"].textContent = String(safeCount(snapshot.completed_appointments));
      this.elements["snapshot-prescriptions"].textContent = String(safeCount(snapshot.active_prescriptions));
      this.elements["snapshot-profile"].textContent = Math.min(100, safeCount(snapshot.profile_completion)) + "%";
    },

    renderActivity() {
      const raw = Array.isArray(this.data?.recent_activity)
        ? this.data.recent_activity
        : Array.isArray(this.data?.medical_timeline) ? this.data.medical_timeline : [];

      const events = raw.filter((event) => event && typeof event === "object" && !Array.isArray(event))
        .sort((a, b) => {
          const left = Date.parse(String(a.date ?? a.created_at ?? "")) || 0;
          const right = Date.parse(String(b.date ?? b.created_at ?? "")) || 0;
          return right - left;
        }).slice(0, 3);

      if (!events.length) {
        this.elements["recent-activity-list"].replaceChildren();
        this.elements["recent-activity-empty"].hidden = false;
        return;
      }

      this.elements["recent-activity-empty"].hidden = true;
      this.elements["recent-activity-list"].innerHTML = events.map((event) => {
        const title = typeof event.title === "string" && event.title.trim()
          ? event.title.trim()
          : "Care activity";
        const description = typeof event.description === "string" ? event.description.trim() : "";
        const rawDate = String(event.date ?? event.created_at ?? "").trim();
        const parts = parseDateParts(rawDate);
        const dateTime = parts
          ? localDateKey(parts.date)
          : "";
        const dateLabel = parts ? formatDateParts(parts, { weekday: true }) : "";

        return '<li class="recent-activity-item">'
          + '<span class="recent-activity-item__icon">' + getActivityIcon(event.type) + '</span>'
          + '<div class="recent-activity-item__copy"><h3>' + escapeHTML(title) + '</h3>'
          + (description ? '<p>' + escapeHTML(description) + '</p>' : "")
          + '</div>'
          + (dateLabel ? '<time class="recent-activity-item__date" datetime="' + escapeHTML(dateTime) + '">' + escapeHTML(dateLabel) + '</time>' : "")
          + '</li>';
      }).join("");
    },
  };

  const start = (event) => {
    if (event?.detail?.user?.role !== "patient") return;
    if (Dashboard.elements["dashboard-main"]) return;
    const user = typeof getUser === "function" ? getUser() : null;
    if (!user || user.role !== "patient") return;
    Dashboard.init();
  };

  document.addEventListener("patient:shell-ready", start);
})();
