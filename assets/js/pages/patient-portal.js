/**
 * HealthBridge — Patient Portal JavaScript
 *
 * Single controller for the professional Patient Portal.
 * Loads ALL dashboard data with ONE API request.
 * Renders every section with skeleton → content transitions.
 *
 * Uses shared helpers from main.js:
 *   apiFetch(), escapeHTML(), showToast(), formatDate(), formatApptTime()
 *   getUser(), saveUser(), getBasePath()
 *
 * Dependencies: Chart.js (loaded via CDN in dashboard.html)
 *
 * Used by: pages/patient/dashboard.html
 */

"use strict";

/* ============================================================
   PORTAL APP — Main Controller
   ============================================================ */

const PortalApp = {
  /** @type {Object|null} Full dashboard data from API */
  data: null,

  /** @type {Object|null} Current user */
  user: null,

  /** @type {Chart|null} Monthly visits chart instance */
  chart: null,

  /** @type {number} Search debounce timer */
  searchTimer: null,

  /** @type {string} Current active doctor tab */
  currentDocTab: 'most_visited',

  /** @type {string} Current notification filter */
  currentNotifFilter: 'all',

  /** @type {string} Current active section */
  currentSection: 'overview',

  /** @type {number} Items shown per load more */
  loadMoreCount: 5,

  /** @type {Object} Track items shown for each section */
  itemsShown: {
    notifications: 5,
    timeline: 5
  },

  // ── Initialization ──────────────────────────────────────

  /** Main entry point — called on DOMContentLoaded */
  async init() {
    this.user = getUser();
    if (!this.user) return;

    // Verify role
    if (this.user.role !== 'patient') {
      window.location.href = getBasePath() + 'pages/auth/login.html';
      return;
    }

    // Populate sidebar
    this.populateSidebar();
    // Update clock every minute
    this.updateClock();
    setInterval(() => this.updateClock(), 60000);

    // Show skeleton loaders BEFORE API call
    this.showSkeletons();

    // Load dashboard data
    await this.loadDashboard();

    // Attach event listeners
    this.attachEventListeners();

    // Initialize section navigation
    this.initSectionNavigation();
  },

  /** Show skeleton loaders for dashboard overview only */
  showSkeletons() {
    this.showElement('hero-skeleton');
    this.showElement('kpi-skeleton');
    this.showElement('recent-activity-skeleton');
    this.showElement('latest-rx-skeleton');
    this.showElement('recent-notif-skeleton');
    this.showElement('fav-docs-skeleton');
    this.showElement('recent-dl-skeleton');
    this.showElement('insights-preview-skeleton');
  },

  /** Populate sidebar user info */
  populateSidebar() {
    const nameEl = document.getElementById('sidebar-name');
    const emailEl = document.getElementById('sidebar-email');
    const avatar = document.querySelector('.sidebar-avatar');

    if (nameEl) nameEl.textContent = this.user.name || 'Patient';
    if (emailEl) emailEl.textContent = this.user.email || '';

    if (avatar && this.user.name) {
      const initials = this.user.name
        .split(' ')
        .map(n => n[0])
        .join('')
        .substring(0, 2)
        .toUpperCase();
      avatar.textContent = initials || '??';
    }
  },

  /** Update the current time display */
  updateClock() {
    const el = document.getElementById('current-datetime');
    if (el) {
      el.textContent = new Date().toLocaleDateString('en-US', {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        hour12: true
      });
    }
  },

  // ── Data Loading ────────────────────────────────────────

  /** Load ALL dashboard data with ONE API request */
  async loadDashboard() {
    const result = await apiFetch(
      getBasePath() + 'api/patient/dashboard.php',
      {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({})
      },
      'Failed to load dashboard data. Please try again.'
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

    this.renderAll();
  },

  /** Show error state for dashboard overview */
  showError() {
    this.showElement('hero-error');
    this.hideElement('hero-skeleton');
    this.hideElement('hero-content');
    this.hideElement('kpi-skeleton');
    this.showElement('kpi-error');
    this.hideElement('recent-activity-skeleton');
    this.showElement('recent-activity-empty');
    this.hideElement('latest-rx-skeleton');
    this.showElement('latest-rx-empty');
    this.hideElement('recent-notif-skeleton');
    this.showElement('recent-notif-empty');
    this.hideElement('fav-docs-skeleton');
    this.showElement('fav-docs-empty');
    this.hideElement('recent-dl-skeleton');
    this.showElement('recent-dl-empty');
    this.hideElement('insights-preview-skeleton');
    this.showElement('insights-preview-empty');
  },

  /** Render ALL sections */
  renderAll() {
    this.renderOverview();
    this.renderHealthAlerts();
    this.renderHealthSnapshot();
    this.renderRecentActivity();
    this.renderLatestPrescription();
    this.renderRecentNotifications();
    this.renderFavoriteDoctorsPreview();
    this.renderRecentDownloads();
    this.renderInsightsPreview();
    // Full sections are rendered on demand when navigating to them
  },

  // ══════════════════════════════════════════════════════════
  //  SECTION 1: OVERVIEW — Welcome Hero
  // ══════════════════════════════════════════════════════════

  renderOverview() {
    const overview = this.data.overview;
    if (!overview) {
      this.hideElement('hero-skeleton');
      this.showElement('hero-error');
      return;
    }

    // Greeting
    const greetingEl = document.getElementById('welcome-greeting');
    if (greetingEl) {
      greetingEl.textContent = `${escapeHTML(overview.greeting || 'Welcome')}, ${escapeHTML(overview.first_name || 'Patient')}`;
    }

    // Patient number
    this.setText('patient-number', overview.patient_number || '-');

    // Hospital status
    const statusEl = document.getElementById('hospital-status');
    if (statusEl && overview.hospital) {
      statusEl.textContent = overview.hospital.status || 'Unknown';
      statusEl.className = 'meta-value' + (overview.hospital.is_open ? ' hospital-open' : ' hospital-closed');
    }

    // Member since
    this.setText('member-since', overview.member_since ? formatDate(overview.member_since) : '-');

    // Unread badge
    const badge = document.getElementById('unread-badge');
    const countEl = document.getElementById('unread-count');
    if (overview.unread_notifications > 0) {
      if (countEl) countEl.textContent = overview.unread_notifications;
      if (badge) badge.style.display = 'flex';
    } else {
      if (badge) badge.style.display = 'none';
    }

    // Next appointment
    const nextAppt = overview.next_appointment;
    const nextApptEmpty = document.getElementById('next-appt-empty');
    const nextApptActions = document.getElementById('next-appt-actions');

    if (nextAppt) {
      this.hideElement('next-appt-empty');
      if (nextApptActions) nextApptActions.style.display = 'flex';

      // Avatar
      const avatarEl = document.getElementById('next-appt-avatar');
      if (avatarEl && nextAppt.doctor) {
        const initials = nextAppt.doctor
          .replace('Dr. ', '')
          .split(' ')
          .map(n => n[0])
          .join('')
          .substring(0, 2)
          .toUpperCase();
        avatarEl.textContent = initials || 'DR';
      }

      this.setText('next-appt-dept', nextAppt.department || '');
      this.setText('next-appt-doctor', nextAppt.doctor || '');

      // Format time
      const timeDisplay = nextAppt.time_range || nextAppt.time || '';
      let dateTimeStr = '';
      if (nextAppt.date) {
        try {
          const d = new Date(nextAppt.date + 'T12:00:00');
          dateTimeStr = d.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' }) + ' · ' + timeDisplay;
        } catch (e) {
          dateTimeStr = nextAppt.date + ' · ' + timeDisplay;
        }
      }
      this.setText('next-appt-time', dateTimeStr);

      // Countdown
      const countdownEl = document.getElementById('next-appt-countdown');
      if (countdownEl && nextAppt.countdown) {
        countdownEl.textContent = nextAppt.countdown.label || '-';
      } else if (countdownEl) {
        countdownEl.textContent = '-';
      }
    } else {
      if (nextApptEmpty) nextApptEmpty.style.display = 'block';
      if (nextApptActions) nextApptActions.style.display = 'none';
    }

    // Primary doctor
    const primaryDoc = overview.primary_doctor;
    if (primaryDoc) {
      const docAvatar = document.getElementById('primary-doc-avatar');
      if (docAvatar && primaryDoc.name) {
        const initials = primaryDoc.name
          .replace('Dr. ', '')
          .split(' ')
          .map(n => n[0])
          .join('')
          .substring(0, 2)
          .toUpperCase();
        docAvatar.textContent = initials || 'DR';
      }
      this.setText('primary-doc-name', primaryDoc.name || 'Not assigned');
      this.setText('primary-doc-specialty', primaryDoc.specialty || '');

      if (primaryDoc.rating) {
        const ratingEl = document.getElementById('primary-doc-rating');
        if (ratingEl) {
          ratingEl.style.display = 'flex';
          const valEl = document.getElementById('primary-doc-rating-value');
          if (valEl) valEl.textContent = primaryDoc.rating;
        }
      }
    }

    // Show content, hide skeleton
    this.hideElement('hero-skeleton');
    this.showElement('hero-content');
  },

  // ══════════════════════════════════════════════════════════
  //  HEALTH ALERTS
  // ══════════════════════════════════════════════════════════

  renderHealthAlerts() {
    const alerts = this.data.health_alerts;
    if (!alerts || !alerts.alerts || alerts.alerts.length === 0) {
      this.hideElement('health-alerts-container');
      return;
    }

    const container = document.getElementById('health-alerts');
    if (!container) return;

    container.innerHTML = alerts.alerts.map(alert => `
      <div class="health-alert-item alert-${escapeHTML(alert.severity || 'info')}" role="alert">
        <div class="health-alert-icon"><i class="fas ${escapeHTML(alert.icon || 'fa-circle-info')}" aria-hidden="true"></i></div>
        <div class="health-alert-body">
          <div class="alert-title">${escapeHTML(alert.title)}</div>
          <div class="alert-message">${escapeHTML(alert.message)}</div>
        </div>
        ${alert.action ? `<button class="health-alert-action" onclick="PortalApp.navigateTo('${escapeHTML(alert.action.url)}')">${escapeHTML(alert.action.label)}</button>` : ''}
      </div>
    `).join('');

    this.showElement('health-alerts-container');
  },

  // ══════════════════════════════════════════════════════════
  //  SECTION 2: HEALTH SNAPSHOT — KPI Cards
  // ══════════════════════════════════════════════════════════

  renderHealthSnapshot() {
    const snapshot = this.data.health_snapshot;
    if (!snapshot) {
      this.hideElement('kpi-skeleton');
      this.showElement('kpi-error');
      return;
    }

    const cards = [
      { icon: 'fa-calendar-days', value: snapshot.upcoming_appointments, label: 'Upcoming Appointments', trend: 'up', trendText: 'Pending' },
      { icon: 'fa-check-circle', value: snapshot.completed_appointments, label: 'Completed Visits', trend: 'neutral', trendText: 'All time' },
      { icon: 'fa-prescription', value: snapshot.active_prescriptions, label: 'Active Prescriptions', trend: snapshot.active_prescriptions > 0 ? 'up' : 'neutral', trendText: snapshot.active_prescriptions > 0 ? `${snapshot.total_prescriptions} total` : 'None active' },
      { icon: 'fa-notes-medical', value: snapshot.has_medical_record ? 1 : 0, label: 'Medical Record', trend: 'neutral', trendText: snapshot.has_medical_record ? 'Complete' : 'Not set up' },
      { icon: 'fa-user-doctor', value: snapshot.doctors_seen, label: 'Doctors Seen', trend: 'neutral', trendText: 'Unique doctors' },
      { icon: 'fa-bell', value: snapshot.unread_notifications, label: 'Unread Notifications', trend: snapshot.unread_notifications > 0 ? 'up' : 'neutral', trendText: `${snapshot.total_notifications} total` },
      { icon: 'fa-percent', value: snapshot.profile_completion, label: 'Profile Complete', trend: snapshot.profile_completion >= 80 ? 'up' : snapshot.profile_completion >= 50 ? 'neutral' : 'down', trendText: `${snapshot.profile_completion}% completed` },
      { icon: 'fa-clock', value: snapshot.last_visit || '-', label: 'Last Visit', trend: 'neutral', trendText: snapshot.last_visit || 'No visits yet' },
    ];

    const container = document.getElementById('kpi-content');
    if (!container) return;

    container.innerHTML = cards.map(card => `
      <div class="kpi-card">
        <div class="kpi-icon-wrapper"><i class="fas ${escapeHTML(card.icon)}" aria-hidden="true"></i></div>
        <div class="kpi-value" data-target="${escapeHTML(String(card.value))}">${escapeHTML(String(card.value).replace(/^0$/, '—'))}</div>
        <div class="kpi-label">${escapeHTML(card.label)}</div>
        <div class="kpi-trend ${escapeHTML(card.trend)}">${escapeHTML(card.trendText)}</div>
      </div>
    `).join('');

    this.hideElement('kpi-skeleton');
    this.showElement('kpi-content');
  },

  // ══════════════════════════════════════════════════════════
  //  SECTION 3: APPOINTMENT TIMELINE
  // ══════════════════════════════════════════════════════════

  renderAppointments() {
    const appts = this.data.appointments;
    if (!appts) {
      this.hideElement('appt-skeleton');
      this.showElement('appt-error');
      return;
    }

    const groups = [
      { key: 'upcoming', label: 'Upcoming', dotClass: 'upcoming', items: appts.upcoming || [] },
      { key: 'completed', label: 'Completed', dotClass: 'completed', items: appts.completed || [] },
      { key: 'cancelled', label: 'Cancelled', dotClass: 'cancelled', items: appts.cancelled || [] },
      { key: 'missed', label: 'Missed', dotClass: 'missed', items: appts.missed || [] },
    ];

    const container = document.getElementById('appt-timeline-groups');
    if (!container) return;

    const hasAny = groups.some(g => g.items.length > 0);
    if (!hasAny) {
      this.hideElement('appt-skeleton');
      this.hideElement('appt-content');
      this.showElement('appt-empty');
      return;
    }

    container.innerHTML = groups.filter(g => g.items.length > 0).map(group => {
      const itemsHtml = group.items.map(a => `
        <div class="timeline-item" data-appt-id="${escapeHTML(String(a.id))}">
          <div class="timeline-dot dot-${escapeHTML(group.dotClass)}"></div>
          <div class="timeline-item-top">
            <div class="timeline-item-info">
              <h4>${escapeHTML(a.doctor || 'Doctor')}</h4>
              <div class="timeline-dept">${escapeHTML(a.department || '')}</div>
              <div class="timeline-datetime">
                <i class="fas fa-calendar" aria-hidden="true"></i>
                ${escapeHTML(a.date || '')} · ${escapeHTML(a.time_range || a.time || '')}
              </div>
              ${a.notes ? `<div style="font-size:0.75rem;color:var(--text-muted);margin-top:var(--s1)">${escapeHTML(a.notes)}</div>` : ''}
            </div>
            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:var(--s2);">
              <span class="status-badge ${escapeHTML(group.dotClass)}">${escapeHTML(group.label)}</span>
              <div class="timeline-item-actions">
                ${a.has_prescription ? `<button class="btn btn-outline btn-sm" onclick="PortalApp.viewPrescription(${a.prescription_id})">Prescription</button>` : ''}
                ${a.can_rate ? `<button class="btn btn-outline btn-sm" onclick="PortalApp.openRating(${a.id}, '${escapeHTML(a.doctor)}')">Rate</button>` : ''}
              </div>
            </div>
          </div>
        </div>
      `).join('');

      return `
        <div class="timeline-group">
          <div class="timeline-group-header">
            <div class="group-dot ${escapeHTML(group.dotClass)}"></div>
            <h3>${escapeHTML(group.label)}</h3>
            <span class="group-count">${group.items.length}</span>
          </div>
          <div class="timeline-items">${itemsHtml}</div>
        </div>
      `;
    }).join('');

    this.hideElement('appt-skeleton');
    this.showElement('appt-content');
  },

  // ══════════════════════════════════════════════════════════
  //  DASHBOARD PREVIEW: RECENT ACTIVITY
  // ══════════════════════════════════════════════════════════

  renderRecentActivity() {
    const events = this.data.medical_timeline;
    if (!events || events.length === 0) {
      this.hideElement('recent-activity-skeleton');
      this.showElement('recent-activity-empty');
      return;
    }

    // Show initial batch
    this.renderActivityBatch(events, this.itemsShown.timeline);
  },

  /** Render a batch of activity events */
  renderActivityBatch(events, count) {
    const container = document.getElementById('recent-activity-timeline');
    const loadMoreBtn = document.getElementById('load-more-activity');
    if (!container) return;

    const toShow = events.slice(0, count);
    const hasMore = events.length > count;

    container.innerHTML = toShow.map((event, idx) => {
      const hasDetails = event.metadata && Object.keys(event.metadata).length > 0;
      return `
        <div class="med-timeline-event" data-index="${idx}">
          <div class="med-timeline-marker">
            <div class="med-timeline-icon" style="color: ${event.color || 'var(--primary)'}; border-color: ${event.color || 'var(--border)'};">
              <i class="fas ${escapeHTML(event.icon || 'fa-circle')}" aria-hidden="true"></i>
            </div>
            <div class="med-timeline-date">${event.date ? formatShortDate(event.date) : ''}</div>
          </div>
          <div class="med-timeline-content" ${hasDetails ? `onclick="PortalApp.toggleTimelineDetail(this)" tabindex="0" role="button" aria-expanded="false"` : ''}>
            <h4>${escapeHTML(event.title || '')}</h4>
            <p>${escapeHTML(event.description || '')}</p>
            ${hasDetails ? `
              <button class="expand-toggle" aria-label="Expand details">
                <i class="fas fa-chevron-down" aria-hidden="true"></i> Show details
              </button>
              <div class="med-timeline-details">
                ${event.metadata ? Object.entries(event.metadata)
                  .filter(([_, v]) => v !== null && v !== undefined && v !== false)
                  .map(([k, v]) => `<div style="font-size:0.75rem;color:var(--text-secondary);margin-bottom:2px"><strong>${escapeHTML(k.replace(/_/g, ' '))}:</strong> ${escapeHTML(String(v))}</div>`).join('')
                  : ''}
              </div>
            ` : ''}
          </div>
        </div>
      `;
    }).join('');

    // Show/hide load more button
    if (loadMoreBtn) {
      loadMoreBtn.style.display = hasMore ? 'block' : 'none';
    }

    this.hideElement('recent-activity-skeleton');
    this.showElement('recent-activity-content');
  },

  /** Load more activity events */
  loadMoreActivity() {
    const events = this.data.medical_timeline;
    if (!events) return;

    this.itemsShown.timeline += this.loadMoreCount;
    this.renderActivityBatch(events, this.itemsShown.timeline);
  },

  // ══════════════════════════════════════════════════════════
  //  SECTION 4: MEDICAL TIMELINE (Full)
  // ══════════════════════════════════════════════════════════

  renderMedicalTimeline() {
    const events = this.data.medical_timeline;
    if (!events || events.length === 0) {
      this.hideElement('timeline-skeleton');
      this.hideElement('timeline-content');
      this.showElement('timeline-empty');
      return;
    }

    const countEl = document.getElementById('timeline-count');
    if (countEl) countEl.textContent = events.length;

    const container = document.getElementById('med-timeline');
    if (!container) return;

    container.innerHTML = events.map((event, idx) => {
      const hasDetails = event.metadata && Object.keys(event.metadata).length > 0;
      return `
        <div class="med-timeline-event" data-index="${idx}">
          <div class="med-timeline-marker">
            <div class="med-timeline-icon" style="color: ${event.color || 'var(--primary)'}; border-color: ${event.color || 'var(--border)'};">
              <i class="fas ${escapeHTML(event.icon || 'fa-circle')}" aria-hidden="true"></i>
            </div>
            <div class="med-timeline-date">${event.date ? formatShortDate(event.date) : ''}</div>
          </div>
          <div class="med-timeline-content" ${hasDetails ? `onclick="PortalApp.toggleTimelineDetail(this)" tabindex="0" role="button" aria-expanded="false"` : ''}>
            <h4>${escapeHTML(event.title || '')}</h4>
            <p>${escapeHTML(event.description || '')}</p>
            ${hasDetails ? `
              <button class="expand-toggle" aria-label="Expand details">
                <i class="fas fa-chevron-down" aria-hidden="true"></i> Show details
              </button>
              <div class="med-timeline-details">
                ${event.metadata ? Object.entries(event.metadata)
                  .filter(([_, v]) => v !== null && v !== undefined && v !== false)
                  .map(([k, v]) => `<div style="font-size:0.75rem;color:var(--text-secondary);margin-bottom:2px"><strong>${escapeHTML(k.replace(/_/g, ' '))}:</strong> ${escapeHTML(String(v))}</div>`).join('')
                  : ''}
              </div>
            ` : ''}
          </div>
        </div>
      `;
    }).join('');

    this.hideElement('timeline-skeleton');
    this.showElement('timeline-content');
  },

  /** Toggle expandable timeline detail */
  toggleTimelineDetail(el) {
    const details = el.querySelector('.med-timeline-details');
    const toggle = el.querySelector('.expand-toggle');
    if (!details) return;

    const isOpen = details.classList.contains('open');
    details.classList.toggle('open');
    if (toggle) {
      toggle.innerHTML = isOpen
        ? '<i class="fas fa-chevron-down" aria-hidden="true"></i> Show details'
        : '<i class="fas fa-chevron-up" aria-hidden="true"></i> Hide details';
    }
    el.setAttribute('aria-expanded', !isOpen);
  },

  // ══════════════════════════════════════════════════════════
  //  DASHBOARD PREVIEW: LATEST PRESCRIPTION
  // ══════════════════════════════════════════════════════════

  renderLatestPrescription() {
    const rxData = this.data.prescriptions;
    if (!rxData || !rxData.prescriptions || rxData.prescriptions.length === 0) {
      this.hideElement('latest-rx-skeleton');
      this.showElement('latest-rx-empty');
      return;
    }

    const latestRx = rxData.prescriptions[0]; // Get most recent
    const container = document.getElementById('latest-rx-card');
    if (!container) return;

    container.innerHTML = `
      <div class="prescription-card">
        <div class="prescription-card-header">
          <div class="rx-info">
            <h4>${escapeHTML(latestRx.doctor_name || 'Doctor')}</h4>
            <div class="rx-meta">${latestRx.created_at ? formatDate(latestRx.created_at) : ''} · ${escapeHTML(latestRx.department || '')}</div>
          </div>
          <span class="status-badge ${escapeHTML(latestRx.status === 'Active' ? 'active' : latestRx.status === 'Completed' ? 'completed' : 'cancelled')}">${escapeHTML(latestRx.status || '')}</span>
        </div>
        <div class="prescription-card-body">
          <div class="rx-medication-list">
            ${(latestRx.items || []).map(item => `
              <div class="rx-med-item">
                <span class="rx-med-name">${escapeHTML(item.medication_name || '')} ${escapeHTML(item.strength || '')}</span>
                <span class="rx-med-dosage">${escapeHTML(item.dosage || '')} · ${escapeHTML(item.frequency || '')}</span>
              </div>
            `).join('')}
          </div>
        </div>
        <div class="prescription-card-footer">
          <button class="btn btn-outline btn-sm" onclick="PortalApp.downloadPrescription(${latestRx.id})"><i class="fas fa-download" aria-hidden="true"></i> PDF</button>
          <button class="btn btn-outline btn-sm" onclick="window.open('${getBasePath()}api/prescriptions/print.php?id=${latestRx.id}', '_blank')"><i class="fas fa-print" aria-hidden="true"></i> Print</button>
          <button class="btn btn-outline btn-sm" onclick="PortalApp.viewPrescription(${latestRx.id})"><i class="fas fa-eye" aria-hidden="true"></i> View</button>
        </div>
      </div>
    `;

    this.hideElement('latest-rx-skeleton');
    this.showElement('latest-rx-content');
  },

  // ══════════════════════════════════════════════════════════
  //  SECTION 5: PRESCRIPTIONS (Full)
  // ══════════════════════════════════════════════════════════

  renderPrescriptions() {
    const rxData = this.data.prescriptions;
    if (!rxData) {
      this.hideElement('rx-skeleton');
      this.showElement('rx-error');
      return;
    }

    const prescriptions = rxData.prescriptions || [];
    const countEl = document.getElementById('rx-count');
    if (countEl) countEl.textContent = prescriptions.length;

    if (prescriptions.length === 0) {
      this.hideElement('rx-skeleton');
      this.hideElement('rx-content');
      this.showElement('rx-empty');
      return;
    }

    // Medication tracker
    const activeMeds = rxData.active_medications || [];
    const trackerContainer = document.getElementById('med-tracker-container');
    const medTracker = document.getElementById('med-tracker');
    const activeCount = document.getElementById('active-meds-count');

    if (activeMeds.length > 0 && medTracker && trackerContainer) {
      if (activeCount) activeCount.textContent = activeMeds.length;
      medTracker.innerHTML = activeMeds.map(med => `
        <div class="med-tracker-item" role="button" tabindex="0" onclick="this.querySelector('.med-tracker-check').classList.toggle('taken')">
          <div class="med-tracker-check"><i class="fas fa-check" aria-hidden="true"></i></div>
          <div class="med-tracker-info">
            <h5>${escapeHTML(med.medication || med.medication_name || '')}</h5>
            <p>${escapeHTML(med.frequency || '')} · ${escapeHTML(med.strength || '')}</p>
          </div>
        </div>
      `).join('');
      trackerContainer.style.display = 'block';
    } else if (trackerContainer) {
      trackerContainer.style.display = 'none';
    }

    // Prescription cards
    const container = document.getElementById('rx-cards');
    if (!container) return;

    container.innerHTML = prescriptions.map(rx => `
      <div class="prescription-card">
        <div class="prescription-card-header">
          <div class="rx-info">
            <h4>${escapeHTML(rx.doctor_name || 'Doctor')}</h4>
            <div class="rx-meta">${rx.created_at ? formatDate(rx.created_at) : ''} · ${escapeHTML(rx.department || '')}</div>
          </div>
          <span class="status-badge ${escapeHTML(rx.status === 'Active' ? 'active' : rx.status === 'Completed' ? 'completed' : 'cancelled')}">${escapeHTML(rx.status || '')}</span>
        </div>
        <div class="prescription-card-body">
          <div class="rx-medication-list">
            ${(rx.items || []).map(item => `
              <div class="rx-med-item">
                <span class="rx-med-name">${escapeHTML(item.medication_name || '')} ${escapeHTML(item.strength || '')}</span>
                <span class="rx-med-dosage">${escapeHTML(item.dosage || '')} · ${escapeHTML(item.frequency || '')} · ${escapeHTML(item.duration || '')}</span>
              </div>
            `).join('')}
            ${rx.notes ? `<div style="font-size:0.75rem;color:var(--text-muted);margin-top:var(--s2);padding-top:var(--s2);border-top:1px solid var(--border-light)">${escapeHTML(rx.notes)}</div>` : ''}
          </div>
        </div>
        <div class="prescription-card-footer">
          <button class="btn btn-outline btn-sm" onclick="PortalApp.downloadPrescription(${rx.id})"><i class="fas fa-download" aria-hidden="true"></i> PDF</button>
          <button class="btn btn-outline btn-sm" onclick="window.open('${getBasePath()}api/prescriptions/print.php?id=${rx.id}', '_blank')"><i class="fas fa-print" aria-hidden="true"></i> Print</button>
          <button class="btn btn-outline btn-sm" onclick="PortalApp.viewPrescription(${rx.id})"><i class="fas fa-eye" aria-hidden="true"></i> View</button>
        </div>
      </div>
    `).join('');

    this.hideElement('rx-skeleton');
    this.showElement('rx-content');
  },

  // ══════════════════════════════════════════════════════════
  //  SECTION 6: MEDICAL PROFILE
  // ══════════════════════════════════════════════════════════

  renderProfile() {
    const profile = this.data.profile;
    if (!profile) {
      this.hideElement('profile-skeleton');
      this.showElement('profile-error');
      return;
    }

    // Profile completion
    const completion = this.data.profile_completion;
    if (completion) {
      this.renderProfileCompletion(completion);
    }

    // Personal Information
    const personalFields = [
      { label: 'Patient Number', value: profile.patient_number },
      { label: 'Full Name', value: profile.name },
      { label: 'Date of Birth', value: profile.date_of_birth || 'Not set' },
      { label: 'Age', value: profile.age !== null ? profile.age + ' years' : '—' },
      { label: 'Gender', value: profile.gender || 'Not set' },
      { label: 'Phone', value: profile.phone || 'Not set' },
      { label: 'Email', value: profile.email },
      { label: 'National ID', value: profile.national_id || 'Not set' },
      { label: 'Governorate', value: (profile.location?.governorate) || 'Not set' },
      { label: 'City', value: (profile.location?.city) || 'Not set' },
      { label: 'Address', value: (profile.location?.address) || 'Not set' },
    ];

    this.renderProfileGrid('profile-personal', personalFields);

    // Medical Information
    const medicalFields = [
      { label: 'Blood Type', value: profile.blood_type || 'Not set' },
      { label: 'Height', value: profile.height_cm ? profile.height_cm + ' cm' : 'Not set' },
      { label: 'Weight', value: profile.weight_kg ? profile.weight_kg + ' kg' : 'Not set' },
      { label: 'BMI', value: profile.bmi !== null ? profile.bmi : '—' },
      { label: 'Allergies', value: profile.allergies || 'None recorded' },
      { label: 'Chronic Diseases', value: profile.chronic_diseases || 'None recorded' },
      { label: 'Current Medications', value: profile.current_medications || 'None recorded' },
    ];

    this.renderProfileGrid('profile-medical', medicalFields);

    // Emergency Contact
    const emergencyFields = [
      { label: 'Contact Name', value: profile.emergency_contact?.name || 'Not set' },
      { label: 'Relationship', value: profile.emergency_contact?.relationship || 'Not set' },
      { label: 'Phone', value: profile.emergency_contact?.phone || 'Not set' },
    ];

    this.renderProfileGrid('profile-emergency', emergencyFields);

    // Insurance
    const insuranceFields = [
      { label: 'Insurance Provider', value: profile.insurance?.provider || 'Not set' },
      { label: 'Policy Number', value: profile.insurance?.number || 'Not set' },
    ];

    this.renderProfileGrid('profile-insurance', insuranceFields);

    this.hideElement('profile-skeleton');
    this.showElement('profile-content');
  },

  /** Render a profile grid section */
  renderProfileGrid(elementId, fields) {
    const el = document.getElementById(elementId);
    if (!el) return;

    el.innerHTML = fields.map(f => `
      <div class="profile-field">
        <span class="field-label">${escapeHTML(f.label)}</span>
        <span class="field-value${f.value === 'Not set' || f.value === 'None recorded' || f.value === '—' ? ' missing' : ''}">${escapeHTML(String(f.value))}</span>
      </div>
    `).join('');
  },

  /** Render profile completion circular widget */
  renderProfileCompletion(completion) {
    const container = document.getElementById('profile-completion');
    if (!container) return;

    const pct = completion.percentage || 0;
    const circumference = 2 * Math.PI * 47; // r=47
    const offset = circumference - (pct / 100) * circumference;

    const missingItems = (completion.missing_fields || []).slice(0, 5);

    container.innerHTML = `
      <div class="completion-ring">
        <svg viewBox="0 0 100 100">
          <circle class="ring-bg" cx="50" cy="50" r="47" />
          <circle class="ring-fill" cx="50" cy="50" r="47"
            stroke-dasharray="${circumference}"
            stroke-dashoffset="${offset}" />
        </svg>
        <span class="ring-label">${pct}%</span>
      </div>
      <div class="completion-details">
        <h4>Profile Completion</h4>
        <div class="completion-missing">
          ${(completion.completed_fields || []).slice(0, 3).map(f => `
            <div class="completion-missing-item complete">
              <i class="fas fa-check-circle" aria-hidden="true"></i>
              <span>${escapeHTML(f.label)}</span>
            </div>
          `).join('')}
          ${missingItems.map(f => `
            <div class="completion-missing-item">
              <i class="fas fa-exclamation-circle" aria-hidden="true"></i>
              <span>Missing: ${escapeHTML(f.label)}</span>
            </div>
          `).join('')}
        </div>
        <button class="btn btn-primary btn-sm" onclick="editProfile()" style="margin-top: var(--s3);">
          Complete Profile <i class="fas fa-arrow-right" aria-hidden="true"></i>
        </button>
      </div>
    `;

    this.showElement('profile-completion-container');
  },

  // ══════════════════════════════════════════════════════════
  //  DASHBOARD PREVIEW: FAVORITE DOCTORS
  // ══════════════════════════════════════════════════════════

  renderFavoriteDoctorsPreview() {
    const favs = this.data.favorites;
    if (!favs) {
      this.hideElement('fav-docs-skeleton');
      this.showElement('fav-docs-empty');
      return;
    }

    // Get 3 doctors: most visited, top rated, recently visited
    const previewDocs = [
      ...(favs.most_visited || []).slice(0, 1),
      ...(favs.top_rated || []).slice(0, 1),
      ...(favs.recently_visited || []).slice(0, 1)
    ].filter((doc, index, self) => {
      // Remove duplicates by doctor_id
      return index === self.findIndex(d => d.doctor_id === doc.doctor_id);
    }).slice(0, 3);

    if (previewDocs.length === 0) {
      this.hideElement('fav-docs-skeleton');
      this.showElement('fav-docs-empty');
      return;
    }

    const container = document.getElementById('fav-docs-list');
    if (!container) return;

    container.innerHTML = previewDocs.map(doc => `
      <div class="fav-doc-card">
        <div class="fav-doc-avatar">${doc.name ? doc.name.replace('Dr. ', '').split(' ').map(n => n[0]).join('').substring(0, 2).toUpperCase() : 'DR'}</div>
        <div class="fav-doc-info">
          <h5>${escapeHTML(doc.name || 'Doctor')}</h5>
          <p>${escapeHTML(doc.specialty || '')}</p>
          <div class="fav-doc-rating">
            <i class="fas fa-star" aria-hidden="true"></i>
            <span>${escapeHTML(String(doc.rating || '—'))}</span>
          </div>
        </div>
        <button class="btn btn-primary btn-sm fav-doc-book-btn" onclick="PortalApp.bookWithDoctor(${doc.doctor_id || 0}, '${escapeHTML(doc.name || '')}')">
          Book
        </button>
      </div>
    `).join('');

    this.hideElement('fav-docs-skeleton');
    this.showElement('fav-docs-content');
  },

  // ══════════════════════════════════════════════════════════
  //  DASHBOARD PREVIEW: RECENT DOWNLOADS
  // ══════════════════════════════════════════════════════════

  renderRecentDownloads() {
    const downloads = this.data.downloads;
    if (!downloads) {
      this.hideElement('recent-dl-skeleton');
      this.showElement('recent-dl-empty');
      return;
    }

    const allItems = [
      ...(downloads.prescriptions || []).map(d => ({ ...d, section: 'prescriptions', icon: 'fa-prescription' })),
      ...(downloads.confirmations || []).map(d => ({ ...d, section: 'confirmations', icon: 'fa-calendar-check' })),
      ...(downloads.visit_summaries || []).map(d => ({ ...d, section: 'visit_summaries', icon: 'fa-stethoscope' })),
    ].slice(0, 3);

    if (allItems.length === 0) {
      this.hideElement('recent-dl-skeleton');
      this.showElement('recent-dl-empty');
      return;
    }

    const container = document.getElementById('recent-dl-list');
    if (!container) return;

    container.innerHTML = allItems.map(item => `
      <div class="dl-item-card">
        <div class="dl-item-icon"><i class="fas ${escapeHTML(item.icon || 'fa-file')}" aria-hidden="true"></i></div>
        <div class="dl-item-info">
          <h5>${escapeHTML(item.label || 'Document')}</h5>
          <p>${item.date ? formatDate(item.date) : ''}</p>
        </div>
        <button class="btn btn-outline btn-sm dl-item-download" onclick="window.open('${getBasePath()}${escapeHTML(item.url || '#')}', '_blank')">
          <i class="fas fa-download" aria-hidden="true"></i>
        </button>
      </div>
    `).join('');

    this.hideElement('recent-dl-skeleton');
    this.showElement('recent-dl-content');
  },

  // ══════════════════════════════════════════════════════════
  //  DASHBOARD PREVIEW: HEALTH INSIGHTS
  // ══════════════════════════════════════════════════════════

  renderInsightsPreview() {
    const insights = this.data.insights;
    if (!insights || !insights.total_visits) {
      this.hideElement('insights-preview-skeleton');
      this.showElement('insights-preview-empty');
      return;
    }

    // Render mini chart
    if (insights.monthly_visits && insights.monthly_visits.length > 0) {
      this.renderMiniChart(insights.monthly_visits);
    }

    // Render key stats
    const statsContainer = document.getElementById('insights-preview-stats');
    if (!statsContainer) return;

    const keyStats = [
      { label: 'Total Visits', value: insights.total_visits || 0 },
      { label: 'This Month', value: insights.visits_this_month || 0 }
    ];

    statsContainer.innerHTML = keyStats.map(stat => `
      <div class="insights-preview-stat">
        <div class="insights-preview-stat-value">${escapeHTML(String(stat.value))}</div>
        <div class="insights-preview-stat-label">${escapeHTML(stat.label)}</div>
      </div>
    `).join('');

    this.hideElement('insights-preview-skeleton');
    this.showElement('insights-preview-content');
  },

  /** Render mini chart for insights widget */
  renderMiniChart(monthlyData) {
    const canvas = document.getElementById('insights-mini-chart');
    if (!canvas) return;

    // Destroy existing chart
    if (this.miniChart) {
      this.miniChart.destroy();
      this.miniChart = null;
    }

    const isDark = getCurrentTheme() === 'dark';
    const textColor = isDark ? '#7fb3d3' : '#4a6b8a';
    const gridColor = isDark ? 'rgba(34, 211, 238, 0.08)' : 'rgba(0, 0, 0, 0.06)';

    const labels = monthlyData.map(m => m.month || '');
    const counts = monthlyData.map(m => m.count || 0);

    this.miniChart = new Chart(canvas, {
      type: 'line',
      data: {
        labels: labels,
        datasets: [
          {
            label: 'Visits',
            data: counts,
            borderColor: isDark ? '#22d3ee' : '#06b6d4',
            backgroundColor: isDark ? 'rgba(34, 211, 238, 0.1)' : 'rgba(6, 182, 212, 0.1)',
            borderWidth: 2,
            fill: true,
            tension: 0.4,
            pointRadius: 3,
            pointBackgroundColor: isDark ? '#22d3ee' : '#06b6d4'
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false }
        },
        scales: {
          x: {
            ticks: { display: false },
            grid: { display: false }
          },
          y: {
            beginAtZero: true,
            ticks: { display: false },
            grid: { color: gridColor }
          }
        }
      }
    });
  },

  // ══════════════════════════════════════════════════════════
  //  DASHBOARD PREVIEW: RECENT NOTIFICATIONS
  // ══════════════════════════════════════════════════════════

  renderRecentNotifications() {
    const notifData = this.data.notifications;
    if (!notifData || !notifData.total || notifData.total === 0) {
      this.hideElement('recent-notif-skeleton');
      this.showElement('recent-notif-empty');
      return;
    }

    // Get all notifications
    const allNotifs = [
      ...(notifData.grouped?.today || []),
      ...(notifData.grouped?.yesterday || []),
      ...(notifData.grouped?.this_week || []),
      ...(notifData.grouped?.earlier || [])
    ];
    
    // Sort: unread first, then by date
    const sortedNotifs = allNotifs.sort((a, b) => {
      if (a.is_read !== b.is_read) return a.is_read ? 1 : -1;
      return 0; // Keep API order
    });

    // Show initial batch
    this.renderNotificationsBatch(sortedNotifs, this.itemsShown.notifications);
  },

  /** Render a batch of notifications */
  renderNotificationsBatch(allNotifs, count) {
    const container = document.getElementById('recent-notif-list');
    const loadMoreBtn = document.getElementById('load-more-notif');
    if (!container) return;

    const toShow = allNotifs.slice(0, count);
    const hasMore = allNotifs.length > count;

    container.innerHTML = toShow.map(n => `
      <div class="notif-item${n.is_read ? '' : ' unread'}" data-notif-id="${n.id}" role="listitem">
        <div class="notif-icon"><i class="fas ${escapeHTML(this.getNotifIcon(n.type))}" aria-hidden="true"></i></div>
        <div class="notif-content">
          <div class="notif-title">${escapeHTML(n.title)}</div>
          <div class="notif-message">${escapeHTML(n.message)}</div>
        </div>
        <div class="notif-time">${escapeHTML(n.time_ago || '')}</div>
      </div>
    `).join('');

    // Show/hide load more button
    if (loadMoreBtn) {
      loadMoreBtn.style.display = hasMore ? 'block' : 'none';
    }

    this.hideElement('recent-notif-skeleton');
    this.showElement('recent-notif-content');
  },

  /** Load more notifications */
  loadMoreNotifications() {
    const notifData = this.data.notifications;
    if (!notifData) return;

    const allNotifs = [
      ...(notifData.grouped?.today || []),
      ...(notifData.grouped?.yesterday || []),
      ...(notifData.grouped?.this_week || []),
      ...(notifData.grouped?.earlier || [])
    ].sort((a, b) => {
      if (a.is_read !== b.is_read) return a.is_read ? 1 : -1;
      return 0;
    });

    this.itemsShown.notifications += this.loadMoreCount;
    this.renderNotificationsBatch(allNotifs, this.itemsShown.notifications);
  },

  // ══════════════════════════════════════════════════════════
  //  SECTION 7: NOTIFICATIONS (Full)
  // ══════════════════════════════════════════════════════════

  renderNotifications() {
    const notifData = this.data.notifications;
    if (!notifData) {
      this.hideElement('notif-skeleton');
      this.showElement('notif-error');
      return;
    }

    const countEl = document.getElementById('notif-count');
    if (countEl) countEl.textContent = notifData.total || 0;

    if (!notifData.total || notifData.total === 0) {
      this.hideElement('notif-skeleton');
      this.hideElement('notif-content');
      this.showElement('notif-empty');
      return;
    }

    // Show filters
    this.showElement('notif-filters');

    // Mark all read button
    const markBtn = document.getElementById('mark-all-read-btn');
    if (markBtn && notifData.unread_count > 0) {
      markBtn.style.display = 'flex';
    } else if (markBtn) {
      markBtn.style.display = 'none';
    }

    // Render grouped notifications
    const container = document.getElementById('notif-groups');
    if (!container) return;

    const groupLabels = {
      today: 'Today',
      yesterday: 'Yesterday',
      this_week: 'This Week',
      earlier: 'Earlier'
    };

    const grouped = notifData.grouped || {};
    const groupsWithItems = Object.entries(groupLabels)
      .filter(([key]) => grouped[key] && grouped[key].length > 0);

    if (groupsWithItems.length === 0) {
      container.innerHTML = '';
      this.hideElement('notif-skeleton');
      this.showElement('notif-content');
      return;
    }

    container.innerHTML = groupsWithItems.map(([key, label]) => {
      const items = grouped[key] || [];
      return `
        <div class="notif-group" data-group="${escapeHTML(key)}">
          <div class="notif-group-label">${escapeHTML(label)}</div>
          ${items.map(n => `
            <div class="notif-item${n.is_read ? '' : ' unread'}" data-notif-id="${n.id}" role="listitem">
              <div class="notif-icon"><i class="fas ${escapeHTML(this.getNotifIcon(n.type))}" aria-hidden="true"></i></div>
              <div class="notif-content">
                <div class="notif-title">${escapeHTML(n.title)}</div>
                <div class="notif-message">${escapeHTML(n.message)}</div>
              </div>
              <div class="notif-time">${escapeHTML(n.time_ago || '')}</div>
              <div class="notif-actions">
                ${!n.is_read ? `<button onclick="PortalApp.markNotifRead(${n.id})" title="Mark as read" aria-label="Mark as read"><i class="fas fa-check" aria-hidden="true"></i></button>` : ''}
                <button onclick="PortalApp.deleteNotif(${n.id})" title="Delete" aria-label="Delete"><i class="fas fa-trash" aria-hidden="true"></i></button>
              </div>
            </div>
          `).join('')}
        </div>
      `;
    }).join('');

    this.hideElement('notif-skeleton');
    this.showElement('notif-content');
  },

  /** Get Font Awesome icon for notification type */
  getNotifIcon(type) {
    if (!type) return 'fa-bell';
    if (type.includes('appointment')) return 'fa-calendar-check';
    if (type.includes('prescription')) return 'fa-prescription';
    if (type.includes('rating') || type.includes('review')) return 'fa-star';
    if (type.includes('support') || type.includes('message') || type.includes('ticket')) return 'fa-comment';
    if (type.includes('password') || type.includes('profile') || type.includes('account')) return 'fa-user';
    if (type.includes('medical') || type.includes('visit')) return 'fa-notes-medical';
    return 'fa-bell';
  },

  /** Mark a single notification as read */
  async markNotifRead(notifId) {
    const item = document.querySelector(`.notif-item[data-notif-id="${notifId}"]`);
    if (item) {
      item.classList.remove('unread');
      item.querySelector('.notif-actions button:first-child')?.remove();
    }

    await apiFetch(
      getBasePath() + 'api/notifications/notifications.php',
      {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'mark_read', id: notifId })
      },
      ''
    );
  },

  /** Delete a notification */
  async deleteNotif(notifId) {
    const item = document.querySelector(`.notif-item[data-notif-id="${notifId}"]`);
    if (item) item.remove();

    await apiFetch(
      getBasePath() + 'api/notifications/notifications.php',
      {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'delete', id: notifId })
      },
      ''
    );
  },

  /** Mark all notifications as read */
  async markAllNotifRead() {
    document.querySelectorAll('.notif-item.unread').forEach(el => {
      el.classList.remove('unread');
      const btn = el.querySelector('.notif-actions button:first-child');
      if (btn) btn.remove();
    });

    const btn = document.getElementById('mark-all-read-btn');
    if (btn) btn.style.display = 'none';

    await apiFetch(
      getBasePath() + 'api/notifications/notifications.php',
      {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'mark_all_read' })
      },
      ''
    );
  },

  // ══════════════════════════════════════════════════════════
  //  SECTION 8: FAVORITE DOCTORS
  // ══════════════════════════════════════════════════════════

  renderFavorites() {
    const favs = this.data.favorites;
    if (!favs) {
      this.hideElement('doc-skeleton');
      this.showElement('doc-error');
      return;
    }

    const categories = [
      { key: 'most_visited', label: 'Most Visited', items: favs.most_visited || [] },
      { key: 'top_rated', label: 'Top Rated', items: favs.top_rated || [] },
      { key: 'recently_visited', label: 'Recently Visited', items: favs.recently_visited || [] },
      { key: 'recommended', label: 'Recommended', items: favs.recommended || [] },
    ];

    const hasAny = categories.some(c => c.items.length > 0);
    if (!hasAny) {
      this.hideElement('doc-skeleton');
      this.hideElement('doc-content');
      this.showElement('doc-empty');
      return;
    }

    // Show tabs
    this.showElement('doc-tabs');

    // Render active tab
    this.renderDoctorTab(this.currentDocTab);
  },

  /** Render a specific doctor tab */
  renderDoctorTab(tabKey) {
    const favs = this.data.favorites;
    if (!favs) return;

    const tabMap = {
      most_visited: { items: favs.most_visited || [], label: 'Most Visited' },
      top_rated: { items: favs.top_rated || [], label: 'Top Rated' },
      recently_visited: { items: favs.recently_visited || [], label: 'Recently Visited' },
      recommended: { items: favs.recommended || [], label: 'Recommended' },
    };

    const tab = tabMap[tabKey];
    if (!tab || tab.items.length === 0) {
      this.hideElement('doc-skeleton');
      this.showElement('doc-empty');
      return;
    }

    const container = document.getElementById('doc-cards');
    if (!container) return;

    container.innerHTML = tab.items.map(doc => `
      <div class="doctor-card">
        <div class="doctor-card-avatar">
          ${doc.name ? doc.name.replace('Dr. ', '').split(' ').map(n => n[0]).join('').substring(0, 2).toUpperCase() : 'DR'}
        </div>
        <h4>${escapeHTML(doc.name || 'Doctor')}</h4>
        <div class="doctor-specialty">${escapeHTML(doc.specialty || '')}</div>
        <div class="doctor-meta">
          <span><i class="fas fa-star" aria-hidden="true"></i> ${escapeHTML(String(doc.rating || '—'))}</span>
          <span>${doc.available ? '<i class="fas fa-check-circle" style="color: var(--success);" aria-hidden="true"></i> Available' : '<i class="fas fa-times-circle" style="color: var(--danger);" aria-hidden="true"></i> Unavailable'}</span>
        </div>
        <button class="btn btn-primary btn-sm" onclick="PortalApp.bookWithDoctor(${doc.doctor_id || 0}, '${escapeHTML(doc.name || '')}')">
          <i class="fas fa-calendar-plus" aria-hidden="true"></i> Book Appointment
        </button>
      </div>
    `).join('');

    this.hideElement('doc-skeleton');
    this.showElement('doc-content');
  },

  // ══════════════════════════════════════════════════════════
  //  SECTION 9: HEALTH INSIGHTS
  // ══════════════════════════════════════════════════════════

  renderInsights() {
    const insights = this.data.insights;
    if (!insights || !insights.total_visits) {
      this.hideElement('insights-skeleton');
      this.hideElement('insights-stats');
      this.hideElement('insights-chart-container');
      this.showElement('insights-empty');
      return;
    }

    const stats = [
      { icon: 'fa-building', value: insights.most_visited_department?.name || '—', label: 'Favorite Department' },
      { icon: 'fa-percent', value: insights.appointment_attendance_rate !== null ? insights.appointment_attendance_rate + '%' : '—', label: 'Attendance Rate' },
      { icon: 'fa-check-circle', value: insights.appointment_completion_rate !== null ? insights.appointment_completion_rate + '%' : '—', label: 'Completion Rate' },
      { icon: 'fa-star', value: insights.average_doctor_rating_given !== null ? insights.average_doctor_rating_given + ' / 5' : '—', label: 'Avg Rating Given' },
      { icon: 'fa-user-doctor', value: insights.unique_doctors_visited || 0, label: 'Doctors Visited' },
      { icon: 'fa-prescription', value: insights.prescriptions_this_year || 0, label: 'Prescriptions This Year' },
      { icon: 'fa-calendar', value: insights.visits_this_month || 0, label: 'Visits This Month' },
      { icon: 'fa-clock', value: insights.average_interval_days ? insights.average_interval_days + ' days' : '—', label: 'Avg Visit Interval' },
    ];

    const container = document.getElementById('insight-cards');
    if (!container) return;

    container.innerHTML = stats.map(s => `
      <div class="insight-stat">
        <div class="stat-icon"><i class="fas ${escapeHTML(s.icon)}" aria-hidden="true"></i></div>
        <div class="stat-value">${escapeHTML(String(s.value))}</div>
        <div class="stat-label">${escapeHTML(s.label)}</div>
      </div>
    `).join('');

    this.hideElement('insights-skeleton');
    this.showElement('insights-stats');

    // Render chart if data available
    if (insights.monthly_visits && insights.monthly_visits.length > 0) {
      this.renderChart(insights.monthly_visits);
    } else {
      this.hideElement('insights-chart-container');
    }
  },

  // ══════════════════════════════════════════════════════════
  //  CHARTS
  // ══════════════════════════════════════════════════════════

  /** Render monthly visits chart */
  renderChart(monthlyData) {
    const canvas = document.getElementById('visits-chart');
    if (!canvas) return;

    const container = document.getElementById('insights-chart-container');
    if (container) container.style.display = 'block';

    // Destroy existing chart
    if (this.chart) {
      this.chart.destroy();
      this.chart = null;
    }

    const isDark = getCurrentTheme() === 'dark';
    const textColor = isDark ? '#7fb3d3' : '#4a6b8a';
    const gridColor = isDark ? 'rgba(34, 211, 238, 0.08)' : 'rgba(0, 0, 0, 0.06)';

    const labels = monthlyData.map(m => m.month || '');
    const counts = monthlyData.map(m => m.count || 0);
    const cancelled = monthlyData.map(m => m.cancelled || 0);

    this.chart = new Chart(canvas, {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [
          {
            label: 'Visits',
            data: counts,
            backgroundColor: isDark ? 'rgba(34, 211, 238, 0.6)' : 'rgba(6, 182, 212, 0.6)',
            borderColor: isDark ? '#22d3ee' : '#06b6d4',
            borderWidth: 1,
            borderRadius: 4,
          },
          {
            label: 'Cancelled',
            data: cancelled,
            backgroundColor: isDark ? 'rgba(252, 165, 165, 0.4)' : 'rgba(239, 68, 68, 0.4)',
            borderColor: isDark ? '#fca5a5' : '#ef4444',
            borderWidth: 1,
            borderRadius: 4,
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            labels: { color: textColor, font: { family: 'Sora' } }
          }
        },
        scales: {
          x: {
            ticks: { color: textColor, font: { family: 'Sora', size: 11 } },
            grid: { color: gridColor }
          },
          y: {
            beginAtZero: true,
            ticks: { color: textColor, font: { family: 'Sora', size: 11 }, stepSize: 1 },
            grid: { color: gridColor }
          }
        }
      }
    });
  },

  // ══════════════════════════════════════════════════════════
  //  SECTION 10: DOWNLOAD CENTER
  // ══════════════════════════════════════════════════════════

  renderDownloads() {
    const downloads = this.data.downloads;
    if (!downloads) {
      this.hideElement('dl-skeleton');
      this.showElement('dl-error');
      return;
    }

    const allItems = [
      ...(downloads.prescriptions || []).map(d => ({ ...d, section: 'prescriptions' })),
      ...(downloads.confirmations || []).map(d => ({ ...d, section: 'confirmations' })),
      ...(downloads.visit_summaries || []).map(d => ({ ...d, section: 'visit_summaries' })),
    ];

    if (allItems.length === 0) {
      this.hideElement('dl-skeleton');
      this.hideElement('dl-content');
      this.showElement('dl-empty');
      return;
    }

    const container = document.getElementById('dl-items');
    if (!container) return;

    const iconMap = {
      prescriptions: 'fa-prescription',
      confirmations: 'fa-calendar-check',
      visit_summaries: 'fa-stethoscope',
    };

    container.innerHTML = allItems.map(item => `
      <div class="download-card">
        <div class="download-icon"><i class="fas ${escapeHTML(iconMap[item.section] || 'fa-file')}" aria-hidden="true"></i></div>
        <div class="download-info">
          <h4>${escapeHTML(item.label || 'Document')}</h4>
          <p>${escapeHTML(item.doctor || '')} · ${item.date ? formatDate(item.date) : ''}</p>
        </div>
        <button class="download-btn" onclick="window.open('${getBasePath()}${escapeHTML(item.url || '#')}', '_blank')">
          <i class="fas fa-download" aria-hidden="true"></i> Download
        </button>
      </div>
    `).join('');

    this.hideElement('dl-skeleton');
    this.showElement('dl-content');
  },

  // ══════════════════════════════════════════════════════════
  //  SECTION 11: QUICK ACTIONS
  // ══════════════════════════════════════════════════════════

  renderQuickActions() {
    // All wired via onclick in HTML — no dynamic rendering needed
  },

  // ══════════════════════════════════════════════════════════
  //  SEARCH
  // ══════════════════════════════════════════════════════════

  /** Initialize search with debounced backend calls */
  initSearch() {
    const input = document.getElementById('portal-search');
    const results = document.getElementById('search-results');
    if (!input || !results) return;

    input.addEventListener('input', () => {
      clearTimeout(this.searchTimer);
      const query = input.value.trim();

      if (query.length < 2) {
        results.classList.remove('open');
        results.innerHTML = '';
        return;
      }

      this.searchTimer = setTimeout(() => {
        this.performSearch(query);
      }, 300);
    });

    // Close on blur (with delay for click)
    input.addEventListener('blur', () => {
      setTimeout(() => results.classList.remove('open'), 200);
    });

    // Open on focus if has results
    input.addEventListener('focus', () => {
      if (results.children.length > 0) {
        results.classList.add('open');
      }
    });
  },

  /** Perform search via API */
  async performSearch(query) {
    const results = document.getElementById('search-results');
    if (!results) return;

    const result = await apiFetch(
      getBasePath() + 'api/patient/dashboard.php',
      {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ search: query })
      },
      ''
    );

    if (!result.ok || !result.data?.success) {
      results.innerHTML = '<div class="search-result-group" style="text-align:center;color:var(--text-muted);padding:var(--s4)">Search unavailable</div>';
      results.classList.add('open');
      return;
    }

    const searchData = result.data.data?.search || result.data.search;
    if (!searchData || !searchData.total) {
      results.innerHTML = '<div class="search-result-group" style="text-align:center;color:var(--text-muted);padding:var(--s4)">No results found</div>';
      results.classList.add('open');
      return;
    }

    let html = '';

    if (searchData.appointments?.length > 0) {
      html += `<div class="search-result-group"><h5>Appointments (${searchData.appointments.length})</h5>`;
      html += searchData.appointments.slice(0, 3).map(a => `
        <div class="search-result-item" onclick="PortalApp.navigateTo('#appointments')">
          <i class="fas fa-calendar" style="color:var(--primary);width:16px;" aria-hidden="true"></i>
          <span>${escapeHTML(a.doctor || '')} · ${escapeHTML(a.date || '')}</span>
        </div>
      `).join('');
      html += '</div>';
    }

    if (searchData.doctors?.length > 0) {
      html += `<div class="search-result-group"><h5>Doctors (${searchData.doctors.length})</h5>`;
      html += searchData.doctors.slice(0, 3).map(d => `
        <div class="search-result-item" onclick="PortalApp.navigateTo('#doctors')">
          <i class="fas fa-user-doctor" style="color:var(--primary);width:16px;" aria-hidden="true"></i>
          <span>${escapeHTML(d.doctor_name || '')} · ${escapeHTML(d.specialty || '')}</span>
        </div>
      `).join('');
      html += '</div>';
    }

    if (searchData.prescriptions?.length > 0) {
      html += `<div class="search-result-group"><h5>Prescriptions (${searchData.prescriptions.length})</h5>`;
      html += searchData.prescriptions.slice(0, 3).map(r => `
        <div class="search-result-item" onclick="PortalApp.navigateTo('#prescriptions')">
          <i class="fas fa-prescription" style="color:var(--primary);width:16px;" aria-hidden="true"></i>
          <span>#${r.id} · ${escapeHTML(r.doctor_name || '')}</span>
        </div>
      `).join('');
      html += '</div>';
    }

    if (searchData.history?.length > 0) {
      html += `<div class="search-result-group"><h5>Medical History (${searchData.history.length})</h5>`;
      html += searchData.history.slice(0, 3).map(h => `
        <div class="search-result-item" onclick="PortalApp.navigateTo('#timeline')">
          <i class="fas fa-notes-medical" style="color:var(--primary);width:16px;" aria-hidden="true"></i>
          <span>${escapeHTML(h.diagnosis || h.doctor || '')}</span>
        </div>
      `).join('');
      html += '</div>';
    }

    results.innerHTML = html || '<div class="search-result-group" style="text-align:center;color:var(--text-muted);padding:var(--s4)">No results found</div>';
    results.classList.add('open');
  },

  // ══════════════════════════════════════════════════════════
  //  EVENT LISTENERS
  // ══════════════════════════════════════════════════════════

  /** Attach all event listeners */
  attachEventListeners() {
    // Notification filters
    document.querySelectorAll('.notif-filter-btn[data-filter]').forEach(btn => {
      btn.addEventListener('click', (e) => {
        document.querySelectorAll('.notif-filter-btn[data-filter]').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        this.currentNotifFilter = btn.dataset.filter;
        this.filterNotifications();
      });
    });

    // Doctor tabs
    document.querySelectorAll('.notif-filter-btn[data-doctab]').forEach(btn => {
      btn.addEventListener('click', (e) => {
        document.querySelectorAll('.notif-filter-btn[data-doctab]').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        this.currentDocTab = btn.dataset.doctab;
        this.renderDoctorTab(this.currentDocTab);
      });
    });

    // Mark all read
    document.getElementById('mark-all-read-btn')?.addEventListener('click', () => {
      this.markAllNotifRead();
    });

    // Search
    this.initSearch();

    // Theme change — recreate chart
    window.addEventListener('themechange', (e) => {
      if (this.data?.insights?.monthly_visits) {
        this.renderChart(this.data.insights.monthly_visits);
      }
    });

    // Support message form
    const msgForm = document.getElementById('dashboard-message-form');
    if (msgForm) {
      msgForm.addEventListener('submit', this.handleSupportMessage.bind(this));
    }
  },

  /** Filter notifications by type */
  filterNotifications() {
    const filter = this.currentNotifFilter;
    if (filter === 'all') {
      document.querySelectorAll('.notif-group').forEach(g => g.style.display = 'block');
      return;
    }

    // Use the by_type data from the API
    const typeData = this.data.notifications?.by_type || {};
    const filteredIds = new Set((typeData[filter] || []).map(n => n.id));

    document.querySelectorAll('.notif-item').forEach(item => {
      const id = parseInt(item.dataset.notifId);
      item.style.display = filteredIds.has(id) ? '' : 'none';
    });

    // Hide empty groups
    document.querySelectorAll('.notif-group').forEach(group => {
      const visible = group.querySelectorAll('.notif-item[style*="display: none"]').length === 0;
      group.style.display = visible ? 'block' : 'none';
    });
  },

  /** Send support message */
  async handleSupportMessage(e) {
    e.preventDefault();
    const form = e.target;
    const submitBtn = form.querySelector('[type="submit"]');
    const subject = document.getElementById('dashboard-msg-subject')?.value.trim();
    const message = document.getElementById('dashboard-msg-body')?.value.trim();
    const phone = document.getElementById('dashboard-msg-phone')?.value.trim();
    const dept = document.getElementById('dashboard-msg-dept')?.value || 'General Inquiry';

    if (!subject || !message) {
      showToast('Subject and message are required.', 'error');
      return;
    }

    setLoading(submitBtn, true, 'Sending...');

    const result = await apiFetch(
      getBasePath() + 'api/settings/contact.php',
      {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          name: this.user?.name || 'Patient',
          email: this.user?.email || '',
          phone: phone || undefined,
          department: dept,
          subject,
          message
        })
      },
      'Failed to send message.'
    );

    if (result.ok && (result.data?.success || result.data?.id)) {
      showToast('Message sent to Support successfully!', 'success');
      form.reset();
    } else {
      showToast(result.data?.message || 'Failed to send message.', 'error');
    }

    setLoading(submitBtn, false, 'Send to Support');
  },

  // ══════════════════════════════════════════════════════════
  //  NAVIGATION HELPERS
  // ══════════════════════════════════════════════════════════

  /** Initialize section navigation */
  initSectionNavigation() {
    // Handle sidebar navigation clicks
    document.querySelectorAll('.sidebar-nav a[data-section]').forEach(link => {
      link.addEventListener('click', (e) => {
        e.preventDefault();
        const section = link.dataset.section;
        this.navigateToSection(section);
      });
    });

    // Handle initial hash
    const hash = window.location.hash.replace('#', '');
    if (hash && hash !== 'overview') {
      this.navigateToSection(hash);
    }
  },

  /** Navigate to a section */
  navigateToSection(sectionId) {
    if (!sectionId) return;

    // Update sidebar active state
    document.querySelectorAll('.sidebar-nav a').forEach(a => a.classList.remove('active'));
    const activeLink = document.querySelector(`.sidebar-nav a[data-section="${sectionId}"]`);
    if (activeLink) activeLink.classList.add('active');

    // Hide all sections
    document.querySelectorAll('.portal-section').forEach(section => {
      section.style.display = 'none';
    });

    // Show target section
    const targetSection = document.getElementById(sectionId);
    if (targetSection) {
      targetSection.style.display = 'block';
      this.currentSection = sectionId;

      // Render full section data if needed
      this.renderFullSection(sectionId);

      // Update URL hash
      window.location.hash = sectionId;
    }
  },

  /** Render full section data when navigating to it */
  renderFullSection(sectionId) {
    switch (sectionId) {
      case 'appointments':
        this.showElement('appt-skeleton');
        this.renderAppointments();
        break;
      case 'medical-history':
        this.showElement('timeline-skeleton');
        this.renderMedicalTimeline();
        break;
      case 'prescriptions':
        this.showElement('rx-skeleton');
        this.renderPrescriptions();
        break;
      case 'notifications':
        this.showElement('notif-skeleton');
        this.renderNotifications();
        break;
      case 'doctors':
        this.showElement('doc-skeleton');
        this.renderFavorites();
        break;
      case 'downloads':
        this.showElement('dl-skeleton');
        this.renderDownloads();
        break;
      case 'profile':
        this.showElement('profile-skeleton');
        this.renderProfile();
        break;
      case 'insights':
        this.showElement('insights-skeleton');
        this.renderInsights();
        break;
      case 'overview':
        // Already rendered on load
        break;
    }
  },

  /** Navigate to a sidebar section (legacy support) */
  navigateTo(target) {
    if (typeof target === 'string' && target.startsWith('#')) {
      const sectionId = target.replace('#', '');
      // Map old IDs to new section IDs
      const sectionMap = {
        'timeline': 'medical-history',
        'book': 'book'
      };
      this.navigateToSection(sectionMap[sectionId] || sectionId);
      return;
    }
    if (typeof target === 'string' && target !== '') {
      window.location.href = target;
    }
  },

  /** Navigate to book appointment tab */
  navigateToBook() {
    const link = document.querySelector('.sidebar-nav a[href="#book"]');
    if (link) link.click();
    else window.location.href = getBasePath() + 'pages/patient/dashboard.html#book';
  },

  /** Book with a specific doctor */
  bookWithDoctor(doctorId, doctorName) {
    this.navigateToBook();
    showToast(`Booking with ${doctorName}...`, 'info');
  },

  /** View a prescription */
  viewPrescription(rxId) {
    const link = document.querySelector('.sidebar-nav a[href="#prescriptions"]');
    if (link) link.click();

    if (rxId) {
      showToast(`Viewing Prescription #${rxId}`, 'info');
    }
  },

  /** Download a prescription PDF */
  downloadPrescription(rxId) {
    window.open(getBasePath() + `api/prescriptions/print.php?id=${rxId}`, '_blank');
  },

  /** Open rating modal for an appointment */
  openRating(appointmentId, doctorName) {
    if (typeof openRatingModal === 'function') {
      openRatingModal(appointmentId, doctorName);
    } else {
      showToast('Rating modal is being initialized...', 'info');
    }
  },

  // ══════════════════════════════════════════════════════════
  //  UTILITY HELPERS
  // ══════════════════════════════════════════════════════════

  /** Set innerText of an element by ID */
  setText(id, text) {
    const el = document.getElementById(id);
    if (el) el.textContent = text;
  },

  /** Show an element by ID */
  showElement(id) {
    const el = (typeof id === 'string') ? document.getElementById(id) : id;
    if (el) el.style.display = '';
  },

  /** Hide an element by ID */
  hideElement(id) {
    const el = (typeof id === 'string') ? document.getElementById(id) : id;
    if (el) el.style.display = 'none';
  }
};

// ══════════════════════════════════════════════════════════
//  UTILITY FUNCTIONS
// ══════════════════════════════════════════════════════════

/** Format a date string to short format (e.g., "Jan 15" or "9:30 AM" for today) */
function formatShortDate(dateStr) {
  if (!dateStr) return '';
  try {
    const d = new Date(dateStr);
    const now = new Date();
    const isToday = d.toDateString() === now.toDateString();
    if (isToday) {
      return d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
    }
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
  } catch (e) {
    return dateStr;
  }
}

// ══════════════════════════════════════════════════════════
//  GLOBAL FUNCTIONS (called from HTML onclick attributes)
// ══════════════════════════════════════════════════════════

/** Refresh portal data */
function refreshPortal() {
  showToast('Refreshing dashboard...', 'info');
  PortalApp.loadDashboard();
}

/** Navigate to book appointment */
function navigateToBook() {
  PortalApp.navigateToBook();
}

/** Navigate to a section */
function navigateToSection(sectionId) {
  PortalApp.navigateTo('#' + sectionId);
}

/** View appointment details */
function viewAppointment() {
  PortalApp.navigateTo('#appointments');
}

/** Edit profile */
function editProfile() {
  if (typeof openSettings === 'function') {
    openSettings();
  } else {
    showToast('Profile settings will open here.', 'info');
  }
}

/** Close messages modal */
function closeMessagesModal() {
  const overlay = document.getElementById('messages-modal-overlay');
  if (overlay) overlay.classList.remove('open');
}

/** Close doctor profile modal */
function closeDoctorProfile() {
  const overlay = document.getElementById('doctor-profile-overlay');
  if (overlay) overlay.classList.remove('open');
}

// ══════════════════════════════════════════════════════════
//  INITIALIZATION
// ══════════════════════════════════════════════════════════

document.addEventListener('DOMContentLoaded', () => {
  // Initialize tab navigation (reuse existing system)
  if (typeof initTabNavigation === 'function') {
    initTabNavigation('hb_patient_active_tab');
  }

  // Initialize portal
  PortalApp.init();
});