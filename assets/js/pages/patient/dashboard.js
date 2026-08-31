/**
 * HealthBridge — Patient Dashboard Controller v3
 *
 * Premium dashboard with skeleton → content transitions, per-widget
 * error isolation, DOM caching, and rich card renderers.
 *
 * Depends on shared helpers from main.js:
 *   apiFetch(), escapeHTML(), showToast(), formatDate(), getUser(),
 *   saveUser(), getBasePath()
 */

"use strict";

/* ============================================================
   DASHBOARD CONTROLLER
   ============================================================ */

const Dashboard = {
  /** @type {Object|null} Full API payload */
  data: null,
  /** @type {Object|null} Current user from localStorage */
  user: null,

  // ── DOM cache — populated once on init ──────────────────────
  _el: {},

  // ── Initialization ──────────────────────────────────────────

  /** Main entry — called on DOMContentLoaded */
  async init() {
    this.user = getUser();
    if (!this.user || this.user.role !== "patient") {
      window.location.href = getBasePath() + "pages/auth/login.html";
      return;
    }

    this._cacheElements();
    this._startClock();
    this._showSkeletons();

    await this._loadDashboard();
    this._attachListeners();
  },

  /** Cache frequently used DOM nodes */
  _cacheElements() {
    const ids = [
      "hero-skeleton", "hero-error", "hero-content",
      "hero-avatar-initials", "welcome-greeting", "hero-subtitle",
      "current-datetime", "patient-number", "member-since",
      "hospital-status", "hero-chip",
      "next-appt-hero", "next-appt-empty", "next-appt-title",
      "next-appt-countdown", "next-appt-avatar", "next-appt-doctor",
      "next-appt-dept", "next-appt-date", "next-appt-time",
      "view-appointment-btn",
      "primary-doctor-row", "primary-doc-avatar",
      "primary-doctor-name", "primary-doctor-specialty",
      "health-alerts-container", "health-alerts",
      "kpi-skeleton", "kpi-error", "kpi-content",
      "care-team-skeleton", "care-team-empty", "care-team-content", "care-team-list",
      "recent-activity-skeleton", "recent-activity-empty",
      "recent-activity-content", "dashboard-timeline-items",
      "latest-rx-skeleton", "latest-rx-empty", "latest-rx-content", "latest-rx-card",
      "recent-notif-skeleton", "recent-notif-empty",
      "recent-notif-content", "recent-notif-list",
      "recent-dl-skeleton", "recent-dl-empty",
      "recent-dl-content", "recent-dl-list",
      "insights-preview-skeleton", "insights-preview-empty",
      "insights-preview-content",
      "appointment-modal", "appointment-modal-body",
    ];
    ids.forEach(id => { this._el[id] = document.getElementById(id); });
  },

  /** Show/hide helper using cached elements */
  _show(id, displayType = "") {
    const el = this._el[id] || document.getElementById(id);
    if (el) el.style.display = displayType;
  },
  _hide(id) {
    const el = this._el[id] || document.getElementById(id);
    if (el) el.style.display = "none";
  },

  /** Show all skeleton placeholders */
  _showSkeletons() {
    const skeletons = [
      "care-team-skeleton", "recent-activity-skeleton",
      "latest-rx-skeleton", "recent-notif-skeleton",
      "recent-dl-skeleton", "insights-preview-skeleton",
    ];
    this._show("hero-skeleton");
    this._show("kpi-skeleton");
    skeletons.forEach(id => this._show(id));
  },

  /** Tick the datetime display every minute */
  _startClock() {
    const tick = () => {
      const el = this._el["current-datetime"];
      if (el) el.textContent = new Date().toLocaleDateString("en-US", {
        weekday: "long", year: "numeric", month: "long",
        day: "numeric", hour: "numeric", minute: "2-digit", hour12: true,
      });
    };
    tick();
    setInterval(tick, 60000);
  },

  // ── Data Loading ────────────────────────────────────────────

  async _loadDashboard() {
    const result = await apiFetch(
      getBasePath() + "api/patient/dashboard.php",
      { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({}) },
      "Failed to load dashboard data. Please try again.",
    );

    if (!result.ok || !result.data?.success) { this._showError(); return; }
    this.data = result.data.data;
    if (!this.data) { this._showError(); return; }

    // Render each widget individually; a failed widget won't block the others
    const widgets = [
      ["renderOverview",             () => this.renderOverview()],
      ["renderHealthAlerts",         () => this.renderHealthAlerts()],
      ["renderHealthSnapshot",       () => this.renderHealthSnapshot()],
      ["renderCareTeam",             () => this.renderCareTeam()],
      ["renderRecentActivity",       () => this.renderRecentActivity()],
      ["renderLatestPrescription",   () => this.renderLatestPrescription()],
      ["renderRecentNotifications",  () => this.renderRecentNotifications()],
      ["renderRecentDownloads",      () => this.renderRecentDownloads()],
      ["renderInsightsPreview",      () => this.renderInsightsPreview()],
    ];

    widgets.forEach(([name, fn]) => {
      try { fn(); }
      catch (err) {
        console.error(`Dashboard widget "${name}" threw:`, err);
        this._handleWidgetError(name);
      }
    });
  },

  _handleWidgetError(name) {
    const map = {
      renderCareTeam:             ["care-team-skeleton",         "care-team-empty"],
      renderRecentActivity:       ["recent-activity-skeleton",   "recent-activity-empty"],
      renderLatestPrescription:   ["latest-rx-skeleton",         "latest-rx-empty"],
      renderRecentNotifications:  ["recent-notif-skeleton",      "recent-notif-empty"],
      renderRecentDownloads:      ["recent-dl-skeleton",         "recent-dl-empty"],
      renderInsightsPreview:      ["insights-preview-skeleton",  "insights-preview-empty"],
    };
    if (map[name]) {
      this._hide(map[name][0]);
      this._show(map[name][1]);
    }
  },

  _showError() {
    this._hide("hero-skeleton"); this._hide("hero-content"); this._show("hero-error");
    this._hide("kpi-skeleton");  this._show("kpi-error");
    ["care-team","recent-activity","latest-rx","recent-notif","recent-dl","insights-preview"]
      .forEach(w => {
        this._hide(`${w}-skeleton`);
        this._show(`${w}-empty`);
      });
  },

  async refresh() {
    this._showSkeletons();
    await this._loadDashboard();
  },

  // ══════════════════════════════════════════════════════════
  //  1. HERO — Overview & Next Appointment
  // ══════════════════════════════════════════════════════════

  renderOverview() {
    const ov = this.data.overview;
    if (!ov) { this._show("hero-error"); this._hide("hero-skeleton"); return; }

    this._hide("hero-skeleton");
    this._hide("hero-error");
    this._show("hero-content");

    // Avatar initials
    const initials = this._getInitials(ov.first_name || "Patient");
    const avatarEl = this._el["hero-avatar-initials"];
    if (avatarEl) avatarEl.textContent = initials;

    // Greeting
    const greetEl = this._el["welcome-greeting"];
    if (greetEl) greetEl.textContent = `${ov.greeting || "Welcome"}, ${ov.first_name || "Patient"}`;

    // Subtitle — contextual based on next appointment
    const subtitleEl = this._el["hero-subtitle"];
    if (subtitleEl) {
      const next = ov.next_appointment;
      if (next) {
        const cd = next.countdown?.label || "";
        if (cd === "Today") {
          subtitleEl.textContent = `You have an appointment today at ${next.time_range || next.time} with ${next.doctor}`;
        } else if (cd === "Tomorrow") {
          subtitleEl.textContent = `Your next appointment is tomorrow at ${next.time_range || next.time} with ${next.doctor}`;
        } else {
          subtitleEl.textContent = `Your next appointment is on ${this._fmtDate(next.date)} with ${next.doctor}`;
        }
      } else {
        subtitleEl.textContent = "No upcoming appointments — book your next visit below.";
      }
    }

    // Meta chips
    const pn = this._el["patient-number"]; if (pn) pn.textContent = ov.patient_number || "—";
    const ms = this._el["member-since"];   if (ms) ms.textContent = ov.member_since ? this._fmtDate(ov.member_since) : "—";
    const hs = this._el["hospital-status"];
    if (hs) {
      const status = ov.hospital?.status || "Unknown";
      hs.textContent = status;
      hs.className = "chip-value " + (status.toLowerCase() === "open" ? "chip-value--open" : "chip-value--closed");
    }

    // Primary doctor
    const pd = ov.primary_doctor;
    const pdRow = this._el["primary-doctor-row"];
    if (pd?.name && pdRow) {
      pdRow.style.display = "";
      const pda = this._el["primary-doc-avatar"];
      if (pda) pda.textContent = this._getInitials(pd.name);
      const pdn = this._el["primary-doctor-name"];
      if (pdn) pdn.textContent = pd.name;
      const pds = this._el["primary-doctor-specialty"];
      if (pds) pds.textContent = pd.specialty || "General Practice";
    } else if (pdRow) {
      pdRow.style.display = "none";
    }

    this._renderNextAppointment(ov.next_appointment);
  },

  _renderNextAppointment(appt) {
    const heroEl  = this._el["next-appt-hero"];
    const emptyEl = this._el["next-appt-empty"];
    const viewBtn = this._el["view-appointment-btn"];

    if (!appt) {
      if (heroEl)  heroEl.style.display  = "none";
      if (emptyEl) emptyEl.style.display = "";
      if (viewBtn) viewBtn.style.display = "none";
      return;
    }

    if (heroEl)  heroEl.style.display  = "";
    if (emptyEl) emptyEl.style.display = "none";

    const isPending = appt.reschedule_status === "pending";

    // Avatar initials
    const av = this._el["next-appt-avatar"];
    if (av) av.textContent = this._getInitials(appt.doctor || "DR");

    // Doctor & dept
    const docEl  = this._el["next-appt-doctor"]; if (docEl) docEl.textContent  = appt.doctor || "—";
    const deptEl = this._el["next-appt-dept"];
    if (deptEl) {
      const dept = appt.department;
      deptEl.textContent = typeof dept === "object" ? dept?.name || "General" : (dept || "General");
    }

    // Date & time (show pending if applicable)
    const dateEl = this._el["next-appt-date"];
    if (dateEl) {
      dateEl.textContent = isPending && appt.pending_reschedule_date
        ? this._fmtDate(appt.pending_reschedule_date) + " (requested)"
        : this._fmtDate(appt.date);
    }
    const timeEl = this._el["next-appt-time"];
    if (timeEl) {
      timeEl.textContent = isPending && appt.pending_reschedule_time
        ? (appt.pending_reschedule_time || "") + " (requested)"
        : (appt.time_range || appt.time || "");
    }

    // Countdown badge
    const cdEl = this._el["next-appt-countdown"];
    if (cdEl) cdEl.textContent = isPending ? "Reschedule Pending ⏳" : (appt.countdown?.label || "");

    // Title
    const titleEl = this._el["next-appt-title"];
    if (titleEl) {
      if (isPending) titleEl.innerHTML = '<i class="fas fa-clock" aria-hidden="true"></i> Reschedule Pending';
      else titleEl.innerHTML = '<i class="fas fa-calendar-days" aria-hidden="true"></i> Next Appointment';
    }

    if (viewBtn) {
      viewBtn.style.display = "";
      viewBtn.onclick = (e) => {
        e.preventDefault();
        window.location.href = `appointments.html?view=${appt.id}`;
      };
    }
  },

  // ══════════════════════════════════════════════════════════
  //  2. HEALTH ALERTS
  // ══════════════════════════════════════════════════════════

  renderHealthAlerts() {
    const alerts = this.data.health_alerts?.alerts || [];
    const containerEl = this._el["health-alerts-container"];
    const alertsEl    = this._el["health-alerts"];

    if (!containerEl || !alertsEl) return;
    containerEl.style.display = "";

    if (!alerts.length) {
      alertsEl.innerHTML = `
        <div class="health-alert-card health-alert-success">
          <div class="alert-card-left">
            <div class="alert-card-icon"><i class="fas fa-circle-check" aria-hidden="true"></i></div>
          </div>
          <div class="alert-card-center">
            <h4 class="alert-card-title">All Clear</h4>
            <p class="alert-card-desc">No outstanding health alerts. You're up to date.</p>
          </div>
        </div>`;
      return;
    }

    alertsEl.innerHTML = alerts.map(alert => {
      const missing = alert.missing_fields?.length
        ? `<div class="alert-missing">
             <span class="missing-label">Missing</span>
             ${alert.missing_fields.map(f => `<span class="missing-badge">${this._escHTML(f)}</span>`).join("")}
           </div>`
        : "";
      let rawUrl = alert.action?.url || "#";
      if (rawUrl.startsWith("#")) rawUrl = rawUrl.substring(1) + ".html";
      const action = alert.action
        ? `<a href="${this._escHTML(rawUrl)}" class="btn btn-primary btn-sm alert-card-action">${this._escHTML(alert.action.label)}</a>`
        : "";
      return `
        <div class="health-alert-card health-alert-${this._escHTML(alert.severity || "warning")}">
          <div class="alert-card-left">
            <div class="alert-card-icon"><i class="fas ${this._escHTML(alert.icon || "fa-exclamation")}" aria-hidden="true"></i></div>
          </div>
          <div class="alert-card-center">
            <h4 class="alert-card-title">${this._escHTML(alert.title)}</h4>
            <p class="alert-card-desc">${this._escHTML(alert.message)}</p>
            ${missing}
          </div>
          ${action ? `<div class="alert-card-right">${action}</div>` : ""}
        </div>`;
    }).join("");
  },

  // ══════════════════════════════════════════════════════════
  //  3. HEALTH SNAPSHOT — KPI Cards
  // ══════════════════════════════════════════════════════════

  renderHealthSnapshot() {
    const snap = this.data.health_snapshot;
    if (!snap) { this._show("kpi-error"); this._hide("kpi-skeleton"); return; }

    this._hide("kpi-skeleton");
    this._hide("kpi-error");

    const contentEl = this._el["kpi-content"];
    if (!contentEl) return;
    contentEl.style.display = "grid";

    const kpis = [
      {
        label: "Upcoming",
        value: snap.upcoming_appointments ?? 0,
        icon: "fa-calendar-days",
        color: "--success",
        desc: "Scheduled visits",
        href: "appointments.html",
      },
      {
        label: "Total Visits",
        value: snap.completed_appointments ?? 0,
        icon: "fa-circle-check",
        color: "--primary",
        desc: "Completed appointments",
        href: "history.html",
      },
      {
        label: "Prescriptions",
        value: snap.active_prescriptions ?? 0,
        icon: "fa-prescription-bottle-medical",
        color: "--warning",
        desc: "Active prescriptions",
        href: "prescriptions.html",
      },
      {
        label: "Doctors Seen",
        value: snap.doctors_seen ?? 0,
        icon: "fa-user-doctor",
        color: "--info",
        desc: "Unique providers",
        href: "../../doctors.html",
      },
      {
        label: "Unread",
        value: snap.unread_notifications ?? 0,
        icon: "fa-bell",
        color: "--danger",
        desc: "New notifications",
        href: "notifications.html",
      },
      {
        label: "Profile",
        value: `${snap.profile_completion ?? 0}%`,
        icon: "fa-user-pen",
        color: "--success",
        desc: "Profile completeness",
        href: "profile.html",
      },
    ];

    contentEl.innerHTML = kpis.map(k => `
      <a href="${k.href}" class="kpi-card" role="listitem" aria-label="${k.label}: ${k.value}">
        <div class="kpi-icon-wrap" style="--kpi-color:var(${k.color})">
          <i class="fas ${k.icon}" aria-hidden="true"></i>
        </div>
        <div class="kpi-body">
          <div class="kpi-value">${k.value}</div>
          <div class="kpi-label">${k.label}</div>
          <div class="kpi-desc">${k.desc}</div>
        </div>
      </a>`).join("");
  },

  // ══════════════════════════════════════════════════════════
  //  4. CARE TEAM
  // ══════════════════════════════════════════════════════════

  renderCareTeam() {
    const doctors = (this.data.favorite_doctors || []).slice(0, 3);
    this._hide("care-team-skeleton");

    if (!doctors.length) { this._show("care-team-empty"); return; }

    this._show("care-team-content");
    this._hide("care-team-empty");

    const listEl = this._el["care-team-list"];
    if (!listEl) return;

    listEl.innerHTML = doctors.map(doc => {
      const rating    = doc.rating ? parseFloat(doc.rating).toFixed(1) : "N/A";
      const specialty = doc.specialty || doc.department || "General Practice";
      const dept      = doc.department && doc.specialty && doc.department !== doc.specialty ? doc.department : "";
      const badges    = [
        doc.is_primary    && "Primary Doctor",
        doc.last_visit_date && "Recently Visited",
      ].filter(Boolean);

      return `
        <div class="care-team-card" role="listitem">
          <div class="care-team-main">
            <div class="care-team-avatar" aria-hidden="true">${this._escHTML(this._getInitials(doc.name))}</div>
            <div class="care-team-info">
              <div class="care-team-title-row">
                <h4>${this._escHTML(doc.name)}</h4>
                <span class="doctor-rating-badge" aria-label="Rating ${rating}">
                  <i class="fas fa-star" aria-hidden="true"></i> ${this._escHTML(String(rating))}
                </span>
              </div>
              <p class="care-team-specialty">${this._escHTML(specialty)}</p>
              ${dept ? `<p class="care-team-dept">${this._escHTML(dept)}</p>` : ""}
              ${badges.length ? `<div class="care-team-badges">${badges.map(b => `<span class="doctor-badge">${b}</span>`).join("")}</div>` : ""}
            </div>
          </div>
          <div class="care-team-actions">
            <a href="../../doctors.html" class="btn btn-outline btn-sm">View Profile</a>
            <a href="appointments.html" class="btn btn-primary btn-sm">Book</a>
          </div>
        </div>`;
    }).join("");
  },

  // ══════════════════════════════════════════════════════════
  //  5. RECENT ACTIVITY — Timeline
  // ══════════════════════════════════════════════════════════

  renderRecentActivity() {
    const raw = Array.isArray(this.data.recent_activity) ? this.data.recent_activity
      : Array.isArray(this.data.medical_timeline)        ? this.data.medical_timeline : [];

    this._hide("recent-activity-skeleton");

    const events = raw.filter(Boolean)
      .sort((a, b) => {
        const ta = Date.parse(`${a.date||a.created_at||""} ${a.time||""}`) || 0;
        const tb = Date.parse(`${b.date||b.created_at||""} ${b.time||""}`) || 0;
        return tb - ta;
      }).slice(0, 3);

    if (!events.length) { this._show("recent-activity-empty"); return; }

    this._show("recent-activity-content");
    this._hide("recent-activity-empty");

    const listEl = this._el["dashboard-timeline-items"];
    if (!listEl) return;

    listEl.innerHTML = events.map(ev => {
      const title    = ev.title || ev.doctor || "Activity";
      const desc     = ev.description || ev.department || "";
      const dateStr  = ev.date ? this._fmtDate(ev.date) : "";
      const timeStr  = ev.time || ev.appointment_time_range || "";
      const color    = ev.color || "var(--primary)";
      const icon     = ev.icon  || "fa-clock";
      const meta     = [dateStr, timeStr].filter(Boolean).join(" • ");
      return `
        <div class="activity-item" role="listitem">
          <div class="activity-line-dot" style="--dot-color:${color}" aria-hidden="true"></div>
          <div class="activity-avatar" style="background:${color}1a;color:${color}">
            <i class="fas ${icon}" aria-hidden="true"></i>
          </div>
          <div class="activity-content">
            <h4>${this._escHTML(title)}</h4>
            ${desc ? `<p>${this._escHTML(desc)}</p>` : ""}
            ${meta ? `<div class="activity-meta"><i class="fas fa-calendar" aria-hidden="true"></i> ${this._escHTML(meta)}</div>` : ""}
          </div>
        </div>`;
    }).join("");
  },

  // ══════════════════════════════════════════════════════════
  //  6. LATEST PRESCRIPTION
  // ══════════════════════════════════════════════════════════

  renderLatestPrescription() {
    const rx = this.data.latest_prescription;
    this._hide("latest-rx-skeleton");

    if (!rx) { this._show("latest-rx-empty"); return; }

    this._show("latest-rx-content");
    const cardEl = this._el["latest-rx-card"];
    if (!cardEl) return;

    const items = rx.items || [];
    const statusCls = (rx.status || "active").toLowerCase();

    cardEl.innerHTML = `
      <div class="rx-preview-card">
        <div class="rx-card-top">
          <div class="rx-card-identity">
            <div class="rx-icon"><i class="fas fa-prescription-bottle-medical" aria-hidden="true"></i></div>
            <div>
              <div class="rx-card-label">Issued by</div>
              <h4>${this._escHTML(rx.doctor_name || "Provider")}</h4>
            </div>
          </div>
          <span class="rx-status-badge rx-status-${statusCls}">${this._escHTML(rx.status || "Active")}</span>
        </div>
        <div class="rx-card-meta">
          <div class="rx-meta-item">
            <span class="rx-meta-label">Date Issued</span>
            <strong>${typeof formatDate === "function" ? formatDate(rx.created_at) : rx.created_at || "—"}</strong>
          </div>
          <div class="rx-meta-item">
            <span class="rx-meta-label">Medications</span>
            <strong>${items.length}</strong>
          </div>
        </div>
        ${rx.notes ? `<p class="rx-notes">${this._escHTML(rx.notes)}</p>` : ""}
        <div class="rx-card-actions">
          <a href="prescriptions.html" class="btn btn-outline btn-sm">View All Prescriptions</a>
        </div>
      </div>`;
  },

  // ══════════════════════════════════════════════════════════
  //  7. RECENT NOTIFICATIONS
  // ══════════════════════════════════════════════════════════

  renderRecentNotifications() {
    const grouped = this.data.recent_notifications?.grouped;
    const rawList = grouped
      ? Object.values(grouped).flat()
      : (Array.isArray(this.data.recent_notifications) ? this.data.recent_notifications : []);

    const sorted = rawList.slice()
      .sort((a, b) => {
        const ar = a.is_read ? 1 : 0, br = b.is_read ? 1 : 0;
        if (ar !== br) return ar - br;
        return (Date.parse(b.created_at || "") || 0) - (Date.parse(a.created_at || "") || 0);
      }).slice(0, 3);

    this._hide("recent-notif-skeleton");

    if (!sorted.length) { this._show("recent-notif-empty"); return; }

    this._show("recent-notif-content");
    const listEl = this._el["recent-notif-list"];
    if (!listEl) return;

    const iconForType = t => {
      if (/appointment/i.test(t)) return "fa-calendar-check";
      if (/prescription|medical|lab/i.test(t)) return "fa-prescription";
      if (/record|note|rating/i.test(t)) return "fa-notes-medical";
      if (/password|account|security/i.test(t)) return "fa-shield-alt";
      return "fa-bell";
    };

    listEl.innerHTML = sorted.map(n => `
      <div class="notif-preview-item${n.is_read ? "" : " notif-unread"}" role="listitem">
        <div class="notif-preview-icon"><i class="fas ${iconForType(n.type || n.title || "")}" aria-hidden="true"></i></div>
        <div class="notif-preview-body">
          <div class="notif-preview-header">
            <span class="notif-preview-title">${this._escHTML(n.title || "Notification")}</span>
            <span class="notif-preview-time">${this._escHTML(n.time_ago || "")}</span>
          </div>
          <p class="notif-preview-msg">${this._escHTML(n.message || "")}</p>
        </div>
        ${!n.is_read ? '<span class="notif-unread-dot" aria-label="Unread"></span>' : ""}
      </div>`).join("");
  },

  // ══════════════════════════════════════════════════════════
  //  8. RECENT DOWNLOADS
  // ══════════════════════════════════════════════════════════

  renderRecentDownloads() {
    const dl = this.data.recent_downloads;
    this._hide("recent-dl-skeleton");

    const all = [...(dl?.prescriptions || []), ...(dl?.visit_summaries || [])]
      .sort((a, b) => (Date.parse(b.date || b.created_at || "") || 0) - (Date.parse(a.date || a.created_at || "") || 0))
      .slice(0, 3);

    if (!all.length) { this._show("recent-dl-empty"); return; }

    this._show("recent-dl-content");
    const listEl = this._el["recent-dl-list"];
    if (!listEl) return;

    const iconFor = (label = "") => {
      if (/prescription/i.test(label)) return "fa-prescription";
      if (/visit|summary/i.test(label)) return "fa-notes-medical";
      if (/lab/i.test(label)) return "fa-vial";
      if (/referral/i.test(label)) return "fa-handshake-angle";
      if (/invoice|bill/i.test(label)) return "fa-receipt";
      return "fa-file-lines";
    };

    listEl.innerHTML = all.map(item => {
      const icon    = iconFor(item.label || item.type || "");
      const date    = typeof formatDate === "function" ? formatDate(item.date || item.created_at) : (item.date || "—");
      const typeTxt = item.type ? ` • ${this._escHTML(item.type)}` : "";
      const rawUrl  = item.url || "#";
      const fullUrl = (rawUrl.startsWith("http") || rawUrl.startsWith("/") || rawUrl === "#")
        ? rawUrl
        : getBasePath() + rawUrl;

      return `
        <div class="dl-preview-item" role="listitem">
          <div class="dl-preview-icon"><i class="fas ${icon}" aria-hidden="true"></i></div>
          <div class="dl-preview-info">
            <div class="dl-preview-name">${this._escHTML(item.label || "Document")}</div>
            <div class="dl-preview-meta">${date}${typeTxt}</div>
          </div>
          <a href="${this._escHTML(fullUrl)}" class="dl-preview-btn" target="_blank"
             rel="noopener noreferrer" aria-label="Download ${this._escHTML(item.label || "document")}" title="Download / Print Document">
            <i class="fas fa-download" aria-hidden="true"></i>
          </a>
        </div>`;
    }).join("");
  },

  // ══════════════════════════════════════════════════════════
  //  9. HEALTH INSIGHTS PREVIEW
  // ══════════════════════════════════════════════════════════

  renderInsightsPreview() {
    const ins = this.data.insights_preview;
    this._hide("insights-preview-skeleton");

    if (!ins?.monthly_visits?.length) { this._show("insights-preview-empty"); return; }

    this._show("insights-preview-content");
    const contentEl = this._el["insights-preview-content"];
    if (!contentEl) return;

    const monthly       = ins.monthly_visits;
    const currentMonth  = new Date().toISOString().substring(0, 7);
    const thisMonth     = monthly.find(m => m.month?.startsWith(currentMonth))?.count || 0;
    const lastMonthData = monthly[monthly.length - 2];
    const lastMonth     = lastMonthData?.count ?? null;

    const trendIcon  = lastMonth === null ? "fa-minus"
      : thisMonth > lastMonth ? "fa-arrow-trend-up"
      : thisMonth < lastMonth ? "fa-arrow-trend-down"
      : "fa-minus";
    const trendColor = lastMonth === null ? "var(--text-muted)"
      : thisMonth > lastMonth ? "var(--success)"
      : thisMonth < lastMonth ? "var(--danger)"
      : "var(--text-muted)";
    const trendText  = lastMonth === null ? "No prior data"
      : thisMonth > lastMonth ? "Higher than last month"
      : thisMonth < lastMonth ? "Lower than last month"
      : "Same as last month";

    contentEl.innerHTML = `
      <div class="insights-mini">
        <div class="insights-mini-stat">
          <div class="insights-stat-value">${this._escHTML(String(thisMonth))}</div>
          <div class="insights-stat-label">Visits This Month</div>
        </div>
        <div class="insights-mini-trend" style="color:${trendColor}">
          <i class="fas ${trendIcon}" aria-hidden="true"></i>
          <span>${trendText}</span>
        </div>
        <div class="insights-mini-detail">
          <span>Last month: <strong>${lastMonth !== null ? lastMonth : "—"}</strong></span>
          <span>Data points: <strong>${monthly.length}</strong></span>
        </div>
        <a href="insights.html" class="btn btn-outline btn-sm insights-view-btn">View Full Insights</a>
      </div>`;
  },

  // ══════════════════════════════════════════════════════════
  //  APPOINTMENT DETAILS MODAL (from dashboard)
  // ══════════════════════════════════════════════════════════

  viewAppointmentDetails(id) {
    const appt =
      this.data?.appointments?.upcoming?.find(a => a.id === id) ||
      this.data?.appointments?.completed?.find(a => a.id === id);
    if (!appt) return;

    const bodyEl = this._el["appointment-modal-body"];
    if (!bodyEl) return;

    const initials = this._getInitials(appt.doctor || "DR");
    const statusBg  = this._statusColor(appt.status);

    bodyEl.innerHTML = `
      <div class="appt-details">
        <div class="appt-detail-header">
          <div class="appt-detail-avatar">${initials}</div>
          <div class="appt-detail-meta">
            <h4>${this._escHTML(appt.doctor || "Doctor")}</h4>
            <p>${this._escHTML(appt.department || "")}</p>
          </div>
          <span class="appt-status-pill" style="background:${statusBg}1a;color:${statusBg};border:1px solid ${statusBg}33">
            ${this._escHTML(appt.status || "—")}
          </span>
        </div>
        <div class="appt-detail-body">
          <div class="appt-detail-row">
            <span class="appt-detail-label">Date &amp; Time</span>
            <span class="appt-detail-value">${this._fmtDate(appt.date)} at ${appt.time_range || appt.time || "—"}</span>
          </div>
          <div class="appt-detail-row">
            <span class="appt-detail-label">Appointment ID</span>
            <span class="appt-detail-value">#${this._escHTML(String(appt.id))}</span>
          </div>
          ${appt.notes ? `
          <div class="appt-detail-row appt-notes-row">
            <span class="appt-detail-label">Notes</span>
            <span class="appt-detail-value">${this._escHTML(appt.notes)}</span>
          </div>` : ""}
        </div>
        <div class="appt-detail-actions">
          <button class="btn btn-outline" onclick="Dashboard.closeApptModal()">Close</button>
          <button class="btn btn-primary" onclick="Dashboard.closeApptModal();window.location.href='appointments.html'">
            View All Appointments
          </button>
        </div>
      </div>`;

    const modal = this._el["appointment-modal"];
    if (modal) {
      modal.style.display = "flex";
      document.body.classList.add("modal-open");
      modal.classList.add("open");
    }
  },

  closeApptModal() {
    const modal = this._el["appointment-modal"];
    if (modal) {
      modal.style.display = "none";
      modal.classList.remove("open");
      document.body.classList.remove("modal-open");
    }
  },

  // ── Navigation helpers ──────────────────────────────────────

  openBookModal() {
    window.location.href = "appointments.html";
  },

  // ── Event listeners ─────────────────────────────────────────

  _attachListeners() {
    document.addEventListener("keydown", e => {
      if (e.key === "Escape") this.closeApptModal();
    });
    // Click-outside modal
    const modal = this._el["appointment-modal"];
    if (modal) {
      modal.addEventListener("click", e => {
        if (e.target === modal) this.closeApptModal();
      });
    }
  },

  // ── Utility helpers ─────────────────────────────────────────

  _escHTML(str) {
    if (str === null || str === undefined) return "";
    const d = document.createElement("div");
    d.textContent = String(str);
    return d.innerHTML;
  },

  _getInitials(name) {
    if (!name) return "??";
    return name.split(" ").map(n => n[0] || "").join("").substring(0, 2).toUpperCase();
  },

  _fmtDate(d) {
    if (!d) return "—";
    try {
      return new Date(d).toLocaleDateString("en-US", {
        month: "short", day: "numeric", year: "numeric",
      });
    } catch { return String(d); }
  },

  _statusColor(status = "") {
    const s = status.toLowerCase();
    if (s === "confirmed")  return "var(--primary)";
    if (s === "completed")  return "var(--success)";
    if (s === "cancelled")  return "var(--danger)";
    if (s === "missed")     return "var(--text-muted)";
    return "var(--warning)";
  },
};

// ── Bootstrap ───────────────────────────────────────────────

if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", () => Dashboard.init());
} else {
  Dashboard.init();
}
