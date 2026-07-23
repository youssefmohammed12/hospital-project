/**
 * HealthBridge — Patient Dashboard JavaScript
 *
 * Lightweight dashboard controller for the compact single-screen dashboard.
 * Renders preview widgets with skeleton → content transitions.
 *
 * Uses shared helpers from main.js:
 *   apiFetch(), escapeHTML(), showToast(), formatDate(), formatApptTime()
 *   getUser(), saveUser(), getBasePath()
 */

"use strict";

/* ============================================================
   DASHBOARD APP — Compact Dashboard Controller
   ============================================================ */

const Dashboard = {
  /** @type {Object|null} Dashboard data from API */
  data: null,

  /** @type {Object|null} Current user */
  user: null,

  /** @type {Chart|null} Mini chart instance */
  chart: null,

  // ── Initialization ──────────────────────────────────────

  /** Main entry point — called on DOMContentLoaded */
  async init() {
    this.user = getUser();
    if (!this.user) {
      window.location.href = getBasePath() + "pages/auth/login.html";
      return;
    }

    if (this.user.role !== "patient") {
      window.location.href = getBasePath() + "pages/auth/login.html";
      return;
    }

    this.updateClock();
    setInterval(() => this.updateClock(), 60000);

    this.showSkeletons();
    await this.loadDashboard();
    this.attachEventListeners();
  },

  /** Show skeleton loaders for all widgets */
  showSkeletons() {
    this.show("hero-skeleton");
    this.show("kpi-skeleton");
    this.show("care-team-skeleton");
    this.show("recent-activity-skeleton");
    this.show("latest-rx-skeleton");
    this.show("recent-notif-skeleton");
    this.show("recent-dl-skeleton");
    this.show("insights-preview-skeleton");
  },

  /** Update the current time display */
  updateClock() {
    const el = document.getElementById("current-datetime");
    if (el) {
      el.textContent = new Date().toLocaleDateString("en-US", {
        weekday: "long",
        year: "numeric",
        month: "long",
        day: "numeric",
        hour: "numeric",
        minute: "2-digit",
        hour12: true,
      });
    }
  },

  // ── Data Loading ────────────────────────────────────────

  async loadDashboard() {
    const result = await apiFetch(
      getBasePath() + "api/patient/dashboard.php",
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({}),
      },
      "Failed to load dashboard data. Please try again.",
    );

    if (!result.ok || !result.data?.success) {
      this.showError();
      return;
    }

    this.data = result.data.data;
    if (!this.data) {
      this.showError();
      return;
    }

    //console.log("Dashboard payload", this.data);

    // Try each renderer individually so one failure can't break the rest
    this.safeRender("renderOverview", () => this.renderOverview());
    this.safeRender("renderHealthAlerts", () => this.renderHealthAlerts());
    this.safeRender("renderHealthSnapshot", () => this.renderHealthSnapshot());
    this.safeRender("renderCareTeam", () => this.renderCareTeam());
    this.safeRender("renderRecentActivity", () => this.renderRecentActivity());
    this.safeRender("renderLatestPrescription", () =>
      this.renderLatestPrescription(),
    );
    this.safeRender("renderRecentNotifications", () =>
      this.renderRecentNotifications(),
    );
    this.safeRender("renderRecentDownloads", () =>
      this.renderRecentDownloads(),
    );
    this.safeRender("renderInsightsPreview", () =>
      this.renderInsightsPreview(),
    );
  },

  /** Safe wrapper: catches per-widget errors so one broken widget doesn't block the rest */
  safeRender(name, fn) {
    try {
      fn();
    } catch (e) {
      console.error(`Dashboard widget "${name}" failed:`, e);
      // Ensure skeleton is hidden and empty state shown for this widget
      const skeletonMap = {
        renderCareTeam: "care-team-skeleton",
        renderRecentActivity: "recent-activity-skeleton",
        renderRecentNotifications: "recent-notif-skeleton",
        renderLatestPrescription: "latest-rx-skeleton",
        renderRecentDownloads: "recent-dl-skeleton",
        renderInsightsPreview: "insights-preview-skeleton",
      };
      const emptyMap = {
        renderCareTeam: "care-team-empty",
        renderRecentActivity: "recent-activity-empty",
        renderRecentNotifications: "recent-notif-empty",
        renderLatestPrescription: "latest-rx-empty",
        renderRecentDownloads: "recent-dl-empty",
        renderInsightsPreview: "insights-preview-empty",
      };
      if (skeletonMap[name]) this.hide(skeletonMap[name]);
      if (emptyMap[name]) this.show(emptyMap[name]);
    }
  },

  /** Show error state for dashboard */
  showError() {
    this.show("hero-error");
    this.hide("hero-skeleton");
    this.hide("hero-content");
    this.hide("kpi-skeleton");
    this.show("kpi-error");
    this.hide("care-team-skeleton");
    this.show("care-team-empty");
    this.hide("recent-activity-skeleton");
    this.show("recent-activity-empty");
    this.hide("latest-rx-skeleton");
    this.show("latest-rx-empty");
    this.hide("recent-notif-skeleton");
    this.show("recent-notif-empty");
    this.hide("recent-dl-skeleton");
    this.show("recent-dl-empty");
    this.hide("insights-preview-skeleton");
    this.show("insights-preview-empty");
  },

  /** renderAll is no longer directly called — renderers are invoked individually by safeRender */
  renderAll() {
    // legacy stub — each renderer is called individually from loadDashboard
  },

  // ══════════════════════════════════════════════════════════
  //  1. OVERVIEW — Welcome Hero
  // ══════════════════════════════════════════════════════════

  renderOverview() {
    const overview = this.data.overview;
    if (!overview) {
      this.show("hero-error");
      this.hide("hero-skeleton");
      return;
    }

    this.hide("hero-skeleton");
    this.hide("hero-error");
    this.show("hero-content");

    // Greeting
    const greetingEl = document.getElementById("welcome-greeting");
    if (greetingEl)
      greetingEl.textContent = `${overview.greeting}, ${overview.first_name || "Patient"}`;

    // Subtitle — contextual message based on next appointment
    const subtitleEl = document.getElementById("hero-subtitle");
    if (subtitleEl) {
      const next = overview.next_appointment;
      const unread = overview.unread_notifications || 0;
      const completion = this.data.profile_completion?.percentage || 0;

      if (next) {
        const countdown = next.countdown?.label || "";
        if (countdown === "Today") {
          subtitleEl.textContent = `👋 You have an appointment today at ${next.time_range || next.time} with ${next.doctor}`;
        } else if (countdown === "Tomorrow") {
          subtitleEl.textContent = `👋 Your next appointment is tomorrow at ${next.time_range || next.time} with ${next.doctor}`;
        } else {
          subtitleEl.textContent = `👋 Your next appointment is on ${this.formatDate(next.date)} with ${next.doctor}`;
        }
      } else {
        subtitleEl.textContent = `👋 You have no upcoming appointments. Book one in seconds.`;
      }
    }

    // Patient number
    const patientNumberEl = document.getElementById("patient-number");
    if (patientNumberEl)
      patientNumberEl.textContent = overview.patient_number || "-";

    // Member since
    const memberSinceEl = document.getElementById("member-since");
    if (memberSinceEl)
      memberSinceEl.textContent = overview.member_since
        ? formatDate(overview.member_since)
        : "-";

    // Hospital status
    const hospitalStatusEl = document.getElementById("hospital-status");
    if (hospitalStatusEl)
      hospitalStatusEl.textContent = `Hospital ${overview.hospital?.status || "Unknown"}`;

    // Primary doctor
    const primaryDoc = overview.primary_doctor;
    const primaryRow = document.getElementById("primary-doctor-row");
    const primaryNameEl = document.getElementById("primary-doctor-name");
    const primarySpecialtyEl = document.getElementById(
      "primary-doctor-specialty",
    );
    if (primaryDoc && primaryDoc.name) {
      if (primaryRow) primaryRow.style.display = "";
      if (primaryNameEl) primaryNameEl.textContent = primaryDoc.name;
      if (primarySpecialtyEl && primaryDoc.specialty)
        primarySpecialtyEl.textContent = primaryDoc.specialty;
    } else if (primaryRow) {
      primaryRow.style.display = "none";
    }

    this.renderNextAppointment(overview.next_appointment);
  },

  renderNextAppointment(appt) {
    const hero = document.getElementById("next-appt-hero");
    const empty = document.getElementById("next-appt-empty");
    const viewBtn = document.getElementById("view-appointment-btn");

    if (!appt) {
      if (hero) hero.style.display = "none";
      if (empty) empty.style.display = "block";
      if (viewBtn) viewBtn.style.display = "none";
      return;
    }

    if (hero) hero.style.display = "block";
    if (empty) empty.style.display = "none";
    if (viewBtn) {
      viewBtn.style.display = "inline-block";
      viewBtn.onclick = (e) => {
        e.preventDefault();
        window.location.href = `appointments.html?view=${appt.id}`;
      };
    }

    const dateEl = document.getElementById("next-appt-date");
    if (dateEl) {
      dateEl.textContent = formatDate(appt.date);
    }

    const timeEl = document.getElementById("next-appt-time");
    if (timeEl) {
      timeEl.textContent = appt.time_range || appt.time;
    }

    const doctorEl = document.getElementById("next-appt-doctor");
    if (doctorEl) doctorEl.textContent = appt.doctor || "Unknown Doctor";

    const deptEl = document.getElementById("next-appt-dept");
    if (deptEl) {
      const deptValue = appt.department;
      if (typeof deptValue === "object" && deptValue !== null) {
        deptEl.textContent =
          deptValue.name || deptValue.department_name || "General";
      } else {
        deptEl.textContent = deptValue || "General";
      }
    }
  },

  // ══════════════════════════════════════════════════════════
  //  WIDGET: MY CARE TEAM
  // ══════════════════════════════════════════════════════════

  renderCareTeam() {
    const doctors = (this.data.favorite_doctors || []).slice(0, 3);
    //console.log("Care Team", doctors);

    this.hide("care-team-skeleton");

    if (!doctors.length) {
      this.show("care-team-empty");
      return;
    }

    this.show("care-team-content");
    this.hide("care-team-empty");

    const list = document.getElementById("care-team-list");
    if (!list) return;

    list.innerHTML = doctors
      .map((doctor) => {
        const rating = doctor.rating ? `${doctor.rating}` : "N/A";
        const primaryBadges = [];
        if (doctor.is_primary) {
          primaryBadges.push("Primary Doctor");
        }
        if (doctor.last_visit_date) {
          primaryBadges.push("Recently Visited");
        }

        const specialty =
          doctor.specialty || doctor.department || "General Practice";
        const extraDept =
          doctor.department &&
          doctor.specialty &&
          doctor.department !== doctor.specialty
            ? doctor.department
            : "";

        return `
      <div class="care-team-card">
        <div class="care-team-main">
          <div class="care-team-avatar">${this.escapeHTML(this.getInitials(doctor.name))}</div>
          <div class="care-team-info">
            <div class="care-team-title-row">
              <h4>${this.escapeHTML(doctor.name)}</h4>
              <span class="doctor-rating-badge"><i class="fas fa-star" aria-hidden="true"></i> ${this.escapeHTML(rating)}</span>
            </div>
            <p class="care-team-specialty">${this.escapeHTML(specialty)}</p>
            ${extraDept ? `<p class="care-team-dept">${this.escapeHTML(extraDept)}</p>` : ""}
            ${primaryBadges.length ? `<div class="care-team-badges">${primaryBadges.map((b) => `<span class="doctor-badge">${this.escapeHTML(b)}</span>`).join("")}</div>` : ""}
          </div>
        </div>
        <div class="care-team-actions">
          <button class="btn btn-outline btn-sm" onclick="window.location.href='../../doctors.html'">View Profile</button>
          <button class="btn btn-primary btn-sm" onclick="window.location.href='appointments.html'">Book Appointment</button>
        </div>
      </div>`;
      })
      .join("");
  },

  // ══════════════════════════════════════════════════════════
  //  WIDGET: HEALTH ALERTS
  // ══════════════════════════════════════════════════════════

  renderHealthAlerts() {
    const alerts = this.data.health_alerts?.alerts || [];
    const container = document.getElementById("health-alerts-container");
    const alertsEl = document.getElementById("health-alerts");

    if (!alerts.length) {
      if (container) container.style.display = "block";
      if (alertsEl) {
        alertsEl.innerHTML = `
          <div class="health-alerts-empty">
            <div class="empty-icon"><i class="fas fa-check-circle" aria-hidden="true"></i></div>
            <h4>No Health Alerts</h4>
            <p>Everything looks good today.</p>
          </div>`;
      }
      return;
    }

    if (container) container.style.display = "block";
    if (!alertsEl) return;

    alertsEl.innerHTML = alerts
      .map((alert) => {
        let missingBadges = "";
        if (alert.missing_fields && alert.missing_fields.length) {
          missingBadges = `
          <div class="alert-missing">
            <span class="missing-label">Missing</span>
            ${alert.missing_fields.map((field) => `<span class="missing-badge">${this.escapeHTML(field)}</span>`).join("")}
          </div>`;
        }
        return `
        <div class="health-alert-card health-alert-${alert.severity}">
          <div class="alert-card-left">
            <div class="alert-card-icon"><i class="fas ${alert.icon}" aria-hidden="true"></i></div>
          </div>
          <div class="alert-card-center">
            <h4 class="alert-card-title">${this.escapeHTML(alert.title)}</h4>
            <p class="alert-card-desc">${this.escapeHTML(alert.message)}</p>
            ${missingBadges}
          </div>
          <div class="alert-card-right">
            ${alert.action ? `<a href="${alert.action.url}" class="btn btn-primary btn-sm alert-card-action">${this.escapeHTML(alert.action.label)}</a>` : ""}
          </div>
        </div>`;
      })
      .join("");
  },

  // ══════════════════════════════════════════════════════════
  //  WIDGET: HEALTH SNAPSHOT (KPI Cards)
  // ══════════════════════════════════════════════════════════

  renderHealthSnapshot() {
    const snapshot = this.data.health_snapshot;
    if (!snapshot) {
      this.show("kpi-error");
      this.hide("kpi-skeleton");
      return;
    }

    this.hide("kpi-skeleton");
    this.hide("kpi-error");
    this.show("kpi-content");

    const kpiContent = document.getElementById("kpi-content");
    if (!kpiContent) return;

    const kpis = [
      {
        label: "Upcoming",
        value: snapshot.upcoming_appointments,
        icon: "fa-calendar-days",
        color: "var(--success)",
      },
      {
        label: "Visits",
        value: snapshot.completed_appointments,
        icon: "fa-circle-check",
        color: "var(--primary-dark)",
      },
      {
        label: "Prescriptions",
        value: snapshot.active_prescriptions,
        icon: "fa-prescription",
        color: "var(--warning)",
      },
      {
        label: "Doctors",
        value: snapshot.doctors_seen,
        icon: "fa-user-doctor",
        color: "var(--info)",
      },
      {
        label: "Notifications",
        value: snapshot.unread_notifications,
        icon: "fa-bell",
        color: "var(--danger)",
      },
      {
        label: "Profile",
        value: `${snapshot.profile_completion}%`,
        icon: "fa-user-pen",
        color: "var(--success)",
      },
    ];

    kpiContent.innerHTML = kpis
      .map(
        (kpi) => `
      <div class="kpi-card-compact">
        <div class="kpi-icon" style="color: ${kpi.color};"><i class="fas ${kpi.icon}" aria-hidden="true"></i></div>
        <div class="kpi-content">
          <div class="kpi-value">${kpi.value}</div>
          <div class="kpi-label">${kpi.label}</div>
        </div>
      </div>
    `,
      )
      .join("");
  },

  // ══════════════════════════════════════════════════════════
  //  WIDGET: RECENT ACTIVITY (3 items)
  // ══════════════════════════════════════════════════════════

  renderRecentActivity() {
    const recentActivity =
      Array.isArray(this.data.recent_activity) &&
      this.data.recent_activity.length
        ? this.data.recent_activity
        : Array.isArray(this.data.medical_timeline)
          ? this.data.medical_timeline
          : [];

    this.hide("recent-activity-skeleton");

    const widgetContainer = document.getElementById("dashboard-timeline-items");
    if (!widgetContainer) return;

    const timelineEvents = recentActivity.slice().filter(Boolean);
    const normalizedEvents = timelineEvents
      .sort((a, b) => {
        const aTime =
          Date.parse(`${a.date || a.created_at || ""} ${a.time || ""}`) || 0;
        const bTime =
          Date.parse(`${b.date || b.created_at || ""} ${b.time || ""}`) || 0;
        return bTime - aTime;
      })
      .slice(0, 3);

    if (!normalizedEvents.length) {
      this.show("recent-activity-empty");
      this.hide("recent-activity-content");
      return;
    }

    this.show("recent-activity-content");
    this.hide("recent-activity-empty");

    const markup = normalizedEvents
      .map((event) => {
        const title = event.title || event.doctor || "Activity";
        const desc = event.description || event.department || "";
        const dateStr = event.date ? this.formatDate(event.date) : "";
        const iconColor = event.color || "var(--primary)";
        const icon = event.icon || "fa-clock";
        const timeLabel = event.time || event.appointment_time_range || "";
        const meta = timeLabel
          ? `${dateStr}${dateStr && timeLabel ? " • " : ""}${timeLabel}`
          : dateStr;

        return `
        <div class="activity-item" style="cursor:default">
          <div class="activity-avatar" style="background:${iconColor}20;color:${iconColor}"><i class="fas ${icon}" aria-hidden="true"></i></div>
          <div class="activity-content">
            <h4>${this.escapeHTML(title)}</h4>
            <p>${this.escapeHTML(desc)}</p>
            <div class="activity-meta">
              <span><i class="fas fa-calendar" aria-hidden="true"></i> ${this.escapeHTML(meta)}</span>
            </div>
          </div>
        </div>`;
      })
      .join("");

    widgetContainer.innerHTML = markup;
  },

  // ══════════════════════════════════════════════════════════
  //  WIDGET: LATEST PRESCRIPTION (1 item)
  // ══════════════════════════════════════════════════════════

  renderLatestPrescription() {
    const rx = this.data.latest_prescription;
    //console.log("Prescription", rx);

    this.hide("latest-rx-skeleton");

    if (!rx) {
      this.show("latest-rx-empty");
      return;
    }

    this.show("latest-rx-content");

    const card = document.getElementById("latest-rx-card");
    if (!card) return;

    const items = rx.items || [];
    const medicationCount = items.length;
    const statusClass = (rx.status || "active").toLowerCase();

    card.innerHTML = `
      <div class="rx-preview-card">
        <div class="rx-card-top">
          <div class="rx-card-title">
            <div class="rx-icon"><i class="fas fa-prescription-bottle-medical" aria-hidden="true"></i></div>
            <div>
              <div class="rx-card-label">Prescription</div>
              <h4>${this.escapeHTML(rx.doctor_name || "Provider")}</h4>
            </div>
          </div>
          <span class="rx-status-badge rx-status-${statusClass}">${this.escapeHTML(rx.status || "Active")}</span>
        </div>
        <div class="rx-card-meta">
          <div>
            <span>Issued</span>
            <strong>${formatDate(rx.created_at)}</strong>
          </div>
          <div>
            <span>Medications</span>
            <strong>${medicationCount}</strong>
          </div>
        </div>
        <div class="rx-description">${this.escapeHTML(rx.notes || "Your latest prescription summary is shown here.")}</div>
        <div class="rx-actions">
          <button class="btn btn-outline btn-sm" onclick="window.location.href='prescriptions.html'">View Prescription</button>
        </div>
      </div>`;
  },

  // ══════════════════════════════════════════════════════════
  //  WIDGET: RECENT NOTIFICATIONS (3 items)
  // ══════════════════════════════════════════════════════════

  renderRecentNotifications() {
    const notifGroups = this.data.recent_notifications?.grouped;
    const notifications = [];

    if (notifGroups) {
      Object.values(notifGroups).forEach((group) => {
        if (Array.isArray(group)) {
          notifications.push(...group);
        }
      });
    } else if (Array.isArray(this.data.recent_notifications)) {
      notifications.push(...this.data.recent_notifications);
    }

    const sortedNotifications = notifications
      .slice()
      .sort((a, b) => {
        const aRead = a.is_read ? 1 : 0;
        const bRead = b.is_read ? 1 : 0;
        if (aRead !== bRead) return aRead - bRead;
        const aTime = Date.parse(a.created_at || a.date || "") || 0;
        const bTime = Date.parse(b.created_at || b.date || "") || 0;
        return bTime - aTime;
      })
      .slice(0, 3);

    this.hide("recent-notif-skeleton");

    if (!sortedNotifications.length) {
      this.show("recent-notif-empty");
      return;
    }

    this.show("recent-notif-content");

    const list = document.getElementById("recent-notif-list");
    if (!list) return;

    const getNotifIcon = (type = "") => {
      if (/appointment/i.test(type)) return "fa-calendar-check";
      if (/prescription|medical|visit|lab/i.test(type))
        return "fa-prescription";
      if (/record|update|note|rating/i.test(type)) return "fa-notes-medical";
      if (/password|account|security/i.test(type)) return "fa-shield-alt";
      return "fa-bell";
    };

    list.innerHTML = sortedNotifications
      .map((notif) => {
        const icon = getNotifIcon(notif.type || notif.title || "");
        return `
      <div class="notif-preview-item ${notif.is_read ? "" : "notif-unread"}">
        <div class="notif-icon"><i class="fas ${icon}" aria-hidden="true"></i></div>
        <div class="notif-body">
          <div class="notif-header">
            <h4>${this.escapeHTML(notif.title)}</h4>
            <span class="notif-time">${this.escapeHTML(notif.time_ago || "")}</span>
          </div>
          <p class="notif-message">${this.escapeHTML(notif.message)}</p>
        </div>
      </div>`;
      })
      .join("");
  },

  // ══════════════════════════════════════════════════════════
  //  WIDGET: RECENT DOWNLOADS (3 items)
  // ══════════════════════════════════════════════════════════

  renderRecentDownloads() {
    const downloads = this.data.recent_downloads;
    //console.log("Downloads", downloads);

    this.hide("recent-dl-skeleton");

    const allDownloads = [
      ...(downloads?.prescriptions || []),
      ...(downloads?.visit_summaries || []),
    ];

    const sortedDownloads = allDownloads
      .slice()
      .sort((a, b) => {
        const aTime = Date.parse(a.date || a.created_at || "") || 0;
        const bTime = Date.parse(b.date || b.created_at || "") || 0;
        return bTime - aTime;
      })
      .slice(0, 3);

    if (!sortedDownloads.length) {
      this.show("recent-dl-empty");
      return;
    }

    this.show("recent-dl-content");

    const list = document.getElementById("recent-dl-list");
    if (!list) return;

    const getDownloadIcon = (label = "") => {
      if (/prescription/i.test(label)) return "fa-prescription";
      if (/visit|summary/i.test(label)) return "fa-notes-medical";
      if (/lab/i.test(label)) return "fa-vial";
      if (/referral/i.test(label)) return "fa-handshake-angle";
      if (/invoice|bill/i.test(label)) return "fa-receipt";
      return "fa-file-lines";
    };

    list.innerHTML = sortedDownloads
      .map((dl) => {
        const icon = getDownloadIcon(dl.label || dl.type || dl.url || "");
        const metaLabel = dl.type ? `${this.escapeHTML(dl.type)}` : "";
        const dateLabel = formatDate(dl.date || dl.created_at);
        return `
      <div class="dl-preview-item">
        <div class="dl-icon"><i class="fas ${icon}" aria-hidden="true"></i></div>
        <div class="dl-content">
          <h4>${this.escapeHTML(dl.label)}</h4>
          <p class="dl-meta">${dateLabel}${metaLabel ? " • " + metaLabel : ""}</p>
        </div>
        <a href="${dl.url}" class="dl-download" target="_blank"><span>Download</span><i class="fas fa-download" aria-hidden="true"></i></a>
      </div>`;
      })
      .join("");
  },

  // ══════════════════════════════════════════════════════════
  //  WIDGET: HEALTH INSIGHTS PREVIEW
  // ══════════════════════════════════════════════════════════

  renderInsightsPreview() {
    const insights = this.data.insights_preview;
    //console.log("Insights", insights);

    this.hide("insights-preview-skeleton");

    if (!insights || !insights.monthly_visits?.length) {
      this.show("insights-preview-empty");
      return;
    }

    this.show("insights-preview-content");

    const monthlyVisits = insights.monthly_visits;
    const currentMonth = new Date().toISOString().substring(0, 7);
    const thisMonthData = monthlyVisits.find(
      (m) => m.month && m.month.startsWith(currentMonth),
    );
    const visitsThisMonth = thisMonthData ? thisMonthData.count : 0;

    const lastMonthData = monthlyVisits[monthlyVisits.length - 2];
    const lastMonthCount = lastMonthData ? lastMonthData.count : null;
    const trendText = lastMonthData
      ? visitsThisMonth > lastMonthCount
        ? "↑ Higher than last month"
        : visitsThisMonth < lastMonthCount
          ? "↓ Lower than last month"
          : "↔ Stable compared to last month"
      : "No prior month data";

    const content = document.getElementById("insights-preview-content");
    if (!content) return;

    content.innerHTML = `
      <div class="insights-mini-summary">
        <div class="insights-summary-header">
          <div>
            <div class="insights-label">Visits This Month</div>
            <div class="insights-value">${this.escapeHTML(String(visitsThisMonth))}</div>
          </div>
          <div class="insights-trend-badge">${this.escapeHTML(trendText)}</div>
        </div>
        <div class="insights-summary-footer">
          <div>
            <span>Last month</span>
            <strong>${lastMonthCount !== null ? this.escapeHTML(String(lastMonthCount)) : "—"}</strong>
          </div>
          <div>
            <span>Data points</span>
            <strong>${monthlyVisits.length}</strong>
          </div>
        </div>
      </div>`;
  },

  // ── Helper Methods ────────────────────────────────────────

  /** Show element by ID (string only) */
  show(id) {
    const el = document.getElementById(id);
    if (el) {
      if (el.classList.contains("kpi-grid-compact")) {
        el.style.display = "grid";
      } else {
        el.style.display = "";
      }
    }
  },

  /** Hide element by ID (string only) */
  hide(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = "none";
  },

  /** Escape HTML to prevent XSS */
  escapeHTML(str) {
    if (!str) return "";
    const div = document.createElement("div");
    div.textContent = str;
    return div.innerHTML;
  },

  /** Get initials from name */
  getInitials(name) {
    if (!name) return "??";
    return name
      .split(" ")
      .map((n) => n[0])
      .join("")
      .substring(0, 2)
      .toUpperCase();
  },

  /** Refresh dashboard data */
  async refresh() {
    this.showSkeletons();
    await this.loadDashboard();
  },

  // ══════════════════════════════════════════════════════════
  //  BOOKING REDIRECT
  // ══════════════════════════════════════════════════════════

  openBookModal() {
    window.location.href = "appointments.html";
  },

  // ══════════════════════════════════════════════════════════
  //  APPOINTMENT DETAILS MODAL
  // ══════════════════════════════════════════════════════════

  viewAppointmentDetails(appointmentId) {
    const appt =
      this.data?.appointments?.upcoming?.find((a) => a.id === appointmentId) ||
      this.data?.appointments?.completed?.find((a) => a.id === appointmentId);
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
        <div class="detail-header" style="display:flex;align-items:center;gap:var(--s4);margin-bottom:var(--s5)">
          <div class="detail-avatar" style="width:56px;height:56px;border-radius:50%;background:var(--primary-subtle);display:flex;align-items:center;justify-content:center;font-size:1.3rem;color:var(--primary);font-weight:700">${initials}</div>
          <div class="detail-info">
            <h4 style="margin:0">${this.escapeHTML(appt.doctor)}</h4>
            <p style="margin:0;color:var(--text-secondary);font-size:0.85rem">${this.escapeHTML(appt.department)}</p>
          </div>
        </div>
        <div class="detail-section" style="margin-bottom:var(--s4)">
          <h5 style="font-size:0.85rem;color:var(--text-muted);margin-bottom:var(--s1);text-transform:uppercase;letter-spacing:0.5px">Date & Time</h5>
          <p style="margin:0">${this.formatDate(appt.date)} at ${appt.time_range || appt.time}</p>
        </div>
        ${
          appt.notes
            ? `
          <div class="detail-section" style="margin-bottom:var(--s4)">
            <h5 style="font-size:0.85rem;color:var(--text-muted);margin-bottom:var(--s1);text-transform:uppercase;letter-spacing:0.5px">Notes</h5>
            <p style="margin:0">${this.escapeHTML(appt.notes)}</p>
          </div>`
            : ""
        }
        <div class="detail-section" style="margin-bottom:var(--s4)">
          <h5 style="font-size:0.85rem;color:var(--text-muted);margin-bottom:var(--s1);text-transform:uppercase;letter-spacing:0.5px">Status</h5>
          <p style="margin:0"><span class="badge" style="background:${appt.status === "Confirmed" ? "var(--success)" : appt.status === "Cancelled" ? "var(--danger)" : "var(--warning)"}20;color:${appt.status === "Confirmed" ? "var(--success)" : appt.status === "Cancelled" ? "var(--danger)" : "var(--warning)"};padding:2px 10px;border-radius:var(--r-full);font-size:0.8rem;font-weight:600">${appt.status}</span></p>
        </div>
        <div class="detail-section" style="margin-bottom:var(--s4)">
          <h5 style="font-size:0.85rem;color:var(--text-muted);margin-bottom:var(--s1);text-transform:uppercase;letter-spacing:0.5px">Appointment ID</h5>
          <p style="margin:0">#${appt.id}</p>
        </div>
        <div class="detail-actions" style="display:flex;gap:var(--s3);margin-top:var(--s5);flex-wrap:wrap">
          <button class="btn btn-outline" onclick="Dashboard.closeApptModal()">Close</button>
          <button class="btn btn-primary" onclick="Dashboard.closeApptModal();window.location.href='appointments.html'">Book Another</button>
        </div>
      </div>`;

    const modal = document.getElementById("appointment-modal");
    if (modal) {
      modal.style.display = "flex";
      document.body.classList.add("modal-open");
    }
  },

  closeApptModal() {
    const modal = document.getElementById("appointment-modal");
    if (modal) {
      modal.style.display = "none";
      document.body.classList.remove("modal-open");
    }
  },

  /** Attach event listeners */
  attachEventListeners() {
    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape") {
        this.closeApptModal();
      }
    });
  },

  formatDate(dateStr) {
    if (!dateStr) return "-";
    try {
      return new Date(dateStr).toLocaleDateString("en-US", {
        month: "short",
        day: "numeric",
        year: "numeric",
      });
    } catch {
      return dateStr;
    }
  },
};

// Initialize when DOM is ready
if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", () => Dashboard.init());
} else {
  Dashboard.init();
}
