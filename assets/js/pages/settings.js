/**
 * HealthBridge — Settings Modal JavaScript
 * Handles: profile update, password change, appearance, notifications,
 *          doctor settings, admin preferences, and account deletion.
 *
 * Uses shared helpers from main.js:
 *   - apiFetch(), showToast(), setLoading(), escapeHTML(), formatDate()
 *   - getCurrentTheme(), applyTheme()
 *
 * Used by: dashboard.html, doctor-dashboard.html, admin.html
 */

"use strict";

let settingsInitialized = false;
let currentWeeklySchedule = null;
bindSettingsDelegatedActions();

/**
 * Shared Settings actions use delegated listeners so both the standalone
 * Settings page and dynamically injected Settings modal work without
 * executable event attributes.
 */
function bindSettingsDelegatedActions() {
  if (window._hbSettingsDelegatedActionsBound) return;

  document.addEventListener("click", (event) => {
    const target = event.target instanceof Element ? event.target : null;
    if (!target) return;

    const action = target.closest("[data-settings-action]");
    if (action) {
      event.preventDefault();
      switch (action.dataset.settingsAction) {
        case "open-delete":
          openDeleteModal();
          break;
        case "close-delete":
          closeDeleteModal();
          break;
        case "close-settings":
          closeSettings(event);
          break;
      }
      return;
    }

  });

  window._hbSettingsDelegatedActionsBound = true;
}

/* ============================================================
   OPEN / CLOSE SETTINGS MODAL
   ============================================================ */

function openSettings() {
  const modal = document.getElementById("settings-modal");
  if (!modal) {
    // First time — build and inject the modal
    buildSettingsModal();
  }
  document.getElementById("settings-modal")?.classList.add("open");
  document.body.classList.add("settings-modal-open");

  // Load data if not yet loaded
  if (!settingsInitialized) {
    initSettings();
  }
}

function closeSettings(e) {
  // If called from an event, stop propagation and prevent default
  if (e) {
    e.stopPropagation();
    if (e.preventDefault) e.preventDefault();
  }
  const modal = document.getElementById("settings-modal");
  if (modal) modal.classList.remove("open");
  document.body.classList.remove("settings-modal-open");
}

/* ============================================================
   BUILD MODAL HTML
   ============================================================ */

function buildSettingsModal() {
  // Ensure settings.css is loaded so the modal renders with full styles on any page
  if (!document.querySelector('link[href*="settings.css"]')) {
    const link = document.createElement("link");
    link.rel = "stylesheet";
    link.href = getBasePath() + "assets/css/pages/settings.css?v=4";
    document.head.appendChild(link);
  }

  const modal = document.createElement("div");
  modal.className = "modal-overlay settings-modal-overlay settings-modal-overlay--top-aligned";
  modal.id = "settings-modal";

  modal.innerHTML = `
    <div class="settings-modal-content">
      <!-- Header -->
      <div class="settings-modal-header">
        <h2><i class="fas fa-gear" aria-hidden="true"></i> Settings</h2>
        <button class="btn btn-outline btn-sm" data-settings-action="close-settings" aria-label="Close settings">&times;</button>
      </div>

      <!-- Tabs -->
      <div class="settings-tabs" id="settings-tabs">
        <button class="settings-tab active" data-section="account"><i class="fas fa-user" aria-hidden="true"></i> Account</button>
        <button class="settings-tab" data-section="password"><i class="fas fa-lock" aria-hidden="true"></i> Password</button>
        <button class="settings-tab" data-section="appearance"><i class="fas fa-palette" aria-hidden="true"></i> Appearance</button>
        <button class="settings-tab" data-section="notifications"><i class="fas fa-bell" aria-hidden="true"></i> Notifications</button>
        <button class="settings-tab" data-section="doctor" id="settings-tab-doctor" hidden><i class="fas fa-stethoscope" aria-hidden="true"></i> Practice</button>
        <button class="settings-tab" data-section="admin" id="settings-tab-admin" hidden><i class="fas fa-user-tie" aria-hidden="true"></i> Admin</button>
      </div>

      <!-- Panels -->
      <div class="settings-panels">

        <!-- ACCOUNT -->
        <div class="settings-panel active" id="panel-account">
          <div class="settings-card">
            <div class="settings-card-header">
              <span class="settings-card-icon"><i class="fas fa-clipboard-list" aria-hidden="true"></i></span>
              <div><h3>Profile Information</h3><p>Update your name, email, and contact details.</p></div>
            </div>
            <form id="settings-profile-form">
              <div class="settings-form-row">
                <div class="form-group">
                  <label for="settings-name">Full Name</label>
                  <input type="text" id="settings-name" placeholder="Your full name" required minlength="2" maxlength="100" />
                </div>
                <div class="form-group">
                  <label for="settings-email">Email Address</label>
                  <input type="email" id="settings-email" placeholder="you@example.com" required />
                </div>
              </div>
              <div class="settings-form-row">
                <div class="form-group">
                  <label for="settings-phone">Phone Number</label>
                  <input type="text" id="settings-phone" placeholder="+20 123 456 7890" maxlength="20" />
                </div>
                <div class="form-group">
                  <label>Account Type</label>
                  <input type="text" id="settings-role" disabled class="settings-readonly-field" />
                </div>
              </div>
              <div class="settings-form-row">
                <div class="form-group">
                  <label>Member Since</label>
                  <input type="text" id="settings-created" disabled class="settings-readonly-field" />
                </div>
                <div class="form-group">
                  <label>Account Status</label>
                  <input type="text" id="settings-status" disabled class="settings-readonly-field" />
                </div>
              </div>
              <div class="settings-form-actions">
                <button type="submit" class="btn btn-primary">Save Changes</button>
              </div>
            </form>
          </div>

          <div class="settings-card settings-card-danger">
            <div class="settings-card-header">
              <span class="settings-card-icon settings-danger-icon"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i></span>
              <div><h3>Account Deactivation</h3><p>Disable sign-in while preserving your clinical and appointment history.</p></div>
            </div>
            <p class="settings-danger-note">
              Sign-in will be disabled and appointments that have not started will be cancelled. Your clinical, appointment, and audit history will be retained.
            </p>
            <button class="btn btn-outline" data-settings-action="open-delete">Deactivate My Account</button>
          </div>
        </div>

        <!-- PASSWORD -->
        <div class="settings-panel" id="panel-password">
          <div class="settings-card">
            <div class="settings-card-header">
              <span class="settings-card-icon"><i class="fas fa-key" aria-hidden="true"></i></span>
              <div><h3>Password</h3><p>Choose a strong password you haven't used before.</p></div>
            </div>
            <form id="settings-password-form">
              <div class="form-group">
                <label for="settings-current-pw">Current Password</label>
                <div class="pw-field">
                  <input type="password" id="settings-current-pw" placeholder="Enter current password" required minlength="6" />
                  <button type="button" class="pw-toggle" data-password-toggle="true" aria-label="Show password" title="Show password">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                  </button>
                </div>
              </div>
              <div class="settings-form-row">
                <div class="form-group">
                  <label for="settings-new-pw">New Password</label>
                  <div class="pw-field">
                    <input type="password" id="settings-new-pw" placeholder="Min. 6 characters" required minlength="6" />
                    <button type="button" class="pw-toggle" data-password-toggle="true" aria-label="Show password" title="Show password">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                  </div>
                  <div class="pw-strength-wrap settings-password-strength">
                    <div class="pw-strength-bar"><div class="pw-strength-fill" id="pw-strength-fill"></div></div>
                    <span class="pw-strength-label" id="pw-strength-label"></span>
                  </div>
                </div>
                <div class="form-group">
                  <label for="settings-confirm-pw">Confirm New Password</label>
                  <div class="pw-field">
                    <input type="password" id="settings-confirm-pw" placeholder="Re-enter new password" required minlength="6" />
                    <button type="button" class="pw-toggle" data-password-toggle="true" aria-label="Show password" title="Show password">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                  </div>
                </div>
              </div>
              <div class="settings-form-actions">
                <button type="submit" class="btn btn-primary">Update Password</button>
              </div>
            </form>
          </div>
        </div>

        <!-- APPEARANCE -->
        <div class="settings-panel" id="panel-appearance">
          <div class="settings-card">
            <div class="settings-card-header">
              <span class="settings-card-icon"><i class="fas fa-circle-half-stroke" aria-hidden="true"></i></span>
              <div><h3>Theme</h3><p>Choose your preferred color scheme.</p></div>
            </div>
            <div class="theme-options" id="theme-options">
              <label class="theme-option" data-theme="light">
                <input type="radio" name="settings-theme" value="light" />
                <span class="theme-option-icon"><i class="fas fa-sun" aria-hidden="true"></i></span>
                <span class="theme-option-label">Light</span>
              </label>
              <label class="theme-option" data-theme="dark">
                <input type="radio" name="settings-theme" value="dark" />
                <span class="theme-option-icon"><i class="fas fa-moon" aria-hidden="true"></i></span>
                <span class="theme-option-label">Dark</span>
              </label>
              <label class="theme-option" data-theme="system">
                <input type="radio" name="settings-theme" value="system" />
                <span class="theme-option-icon"><i class="fas fa-desktop" aria-hidden="true"></i></span>
                <span class="theme-option-label">System</span>
              </label>
            </div>
          </div>
        </div>

        <!-- NOTIFICATIONS -->
        <div class="settings-panel" id="panel-notifications">
          <div class="settings-card">
            <div class="settings-card-header">
              <span class="settings-card-icon"><i class="fas fa-bell" aria-hidden="true"></i></span>
              <div><h3>Notifications</h3><p>Toggle notification types on or off.</p></div>
            </div>
            <form id="settings-notif-form">
              ${buildToggleRow("notif_appointment", '<i class="fas fa-calendar-days" aria-hidden="true"></i>', "Appointment Reminders", "Get notified about upcoming appointments")}
              ${buildToggleRow("notif_ratings", '<i class="fas fa-star" aria-hidden="true"></i>', "Rating Notifications", "Get notified when you receive a new rating")}
              ${buildToggleRow("notif_messages", '<i class="fas fa-comments" aria-hidden="true"></i>', "New Messages", "Get notified when you receive a support reply")}
              ${buildToggleRow("notif_announcements", '<i class="fas fa-bullhorn" aria-hidden="true"></i>', "System Announcements", "Important updates about HealthBridge")}
              ${buildToggleRow("notif_email", '<i class="fas fa-envelope" aria-hidden="true"></i>', "Email Notifications", "Receive notifications via email")}
              <div class="settings-form-actions">
                <button type="submit" class="btn btn-primary">Save Notification Settings</button>
              </div>
            </form>
          </div>
        </div>

        <!-- DOCTOR -->
        <div class="settings-panel" id="panel-doctor">
          <div class="settings-card">
            <div class="settings-card-header">
              <span class="settings-card-icon"><i class="fas fa-stethoscope" aria-hidden="true"></i></span>
              <div><h3>Practice Preferences</h3><p>Configure your practice details.</p></div>
            </div>
            <form id="settings-doctor-form">
              ${buildToggleRow("accept_new_patients", '<i class="fas fa-circle-check" aria-hidden="true"></i>', "Accepting New Patients", "Allow new patients to book appointments with you")}
              ${buildToggleRow("profile_visible", '<i class="fas fa-eye" aria-hidden="true"></i>', "Profile Visible", "Show your profile on the public doctors page")}
              <div class="settings-form-row">
                <div class="form-group">
                  <label for="settings-consult-duration">Consultation Duration (minutes)</label>
                  <input type="number" id="settings-consult-duration" min="15" max="120" step="5" value="30" />
                </div>
                <div class="form-group">
                  <label>&nbsp;</label>
                  <p class="settings-help-text">Choose how long each appointment should take.</p>
                </div>
              </div>
              <div id="settings-global-hours">
                <div class="settings-form-row">
                  <div class="form-group">
                    <label for="settings-work-start">Working Hours Start</label>
                    <input type="time" id="settings-work-start" value="09:00" />
                  </div>
                  <div class="form-group">
                    <label for="settings-work-end">Working Hours End</label>
                    <input type="time" id="settings-work-end" value="17:00" />
                  </div>
                </div>
                <p class="settings-help-text">Set your typical working hours for patient scheduling.</p>
              </div>
              <div id="settings-weekly-schedule" class="weekly-schedule-editor" hidden>
                <div class="weekly-schedule-heading">
                  <h4>Weekly working hours</h4>
                  <p class="settings-help-text">Set your working days and hours individually. Times must fit within hospital operating hours.</p>
                </div>
                <div id="settings-weekly-days" class="weekly-schedule-days"></div>
              </div>
              <div class="settings-form-actions">
                <button type="submit" class="btn btn-primary">Save Practice Settings</button>
              </div>
            </form>
          </div>
        </div>

        <!-- ADMIN -->
        <div class="settings-panel" id="panel-admin">
          <div class="settings-card">
            <div class="settings-card-header">
              <span class="settings-card-icon"><i class="fas fa-gear" aria-hidden="true"></i></span>
              <div><h3>Dashboard Preferences</h3><p>Configure your admin dashboard defaults.</p></div>
            </div>
            <form id="settings-admin-form">
              <div class="form-group">
                <label for="settings-admin-tab">Default Dashboard Tab</label>
                <select id="settings-admin-tab">
                  <option value="overview">Overview</option>
                  <option value="doctors">Doctors</option>
                  <option value="patients">Patients</option>
                  <option value="appointments">Appointments</option>
                  <option value="support-messages">Support Messages</option>
                </select>
              </div>
              <div class="settings-form-actions">
                <button type="submit" class="btn btn-primary">Save Admin Preferences</button>
              </div>
            </form>
          </div>
        </div>

      </div>
    </div>

    <!-- Deactivate Account Modal (nested - NOT a modal-overlay to prevent double overlay stacking) -->
    <div id="settings-delete-modal" class="modal-overlay settings-modal-overlay settings-modal-overlay--top-aligned" hidden>
      <div class="modal settings-delete-modal-content">
        <div class="settings-delete-modal-header">
          <h3 class="settings-delete-modal-title"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i> Deactivate Account</h3>
          <button class="close-btn" data-settings-action="close-delete">&times;</button>
        </div>
        <p class="settings-delete-modal-description">
          This disables sign-in and cancels appointments that have not started. Your medical, appointment, and audit history will be retained. Active clinical visits must be completed before a doctor's account can be deactivated.
        </p>
        <form id="delete-account-form">
          <div class="form-group">
            <label for="delete-confirm-pw">Enter your password to confirm</label>
            <div class="pw-field">
              <input type="password" id="delete-confirm-pw" placeholder="Your password" required />
              <button type="button" class="pw-toggle" data-password-toggle="true" aria-label="Show password" title="Show password">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              </button>
            </div>
          </div>
          <button type="submit" class="btn btn-danger btn-block">Deactivate My Account</button>
        </form>
      </div>
    </div>
  `;

  // Close on backdrop click
  modal.addEventListener("click", (e) => {
    if (e.target === modal) closeSettings();
  });

  document.body.appendChild(modal);
}

/** Helper: build a toggle row HTML string */
function buildToggleRow(name, icon, label, desc) {
  return `
    <div class="settings-toggle-row">
      <div class="settings-toggle-info">
        <span class="settings-toggle-icon">${icon}</span>
        <div>
          <span class="settings-toggle-label">${label}</span>
          <span class="settings-toggle-desc">${desc}</span>
        </div>
      </div>
      <label class="toggle-switch">
        <input type="checkbox" name="${name}" checked />
        <span class="toggle-slider"></span>
      </label>
    </div>`;
}

/* ============================================================
   INIT SETTINGS
   ============================================================ */

async function initSettings() {
  if (settingsInitialized) return;

  const user = getUser();
  if (!user) return;

  await loadSettingsData();
  initSettingsTabs();
  initProfileForm();
  initPasswordForm();
  initAppearance();
  initNotificationForm();
  initDoctorForm();
  initAdminForm();
  initDeleteAccountForm();
  initPasswordStrength();

  settingsInitialized = true;
}

/* ============================================================
   LOAD SETTINGS DATA
   ============================================================ */

async function loadSettingsData() {
  const result = await apiFetch(
    (getBasePath() + "api/settings/get.php"),
    {},
    "Failed to load settings.",
  );
  if (!result.ok || !result.data?.success) {
    showToast(result.data?.message || "Failed to load settings.", "error");
    return;
  }

  const profile = result.data.profile;
  const prefs = result.data.preferences;
  if (!profile) return;

  // Populate profile form
  setVal("settings-name", profile.name || "");
  setVal("settings-email", profile.email || "");
  setVal("settings-phone", profile.phone || "");
  setVal("settings-role", capitalize(profile.role));
  setVal("settings-created", formatDate(profile.created_at) || "-");
  setVal("settings-status", profile.is_active ? "Active" : "Disabled");

  // Populate appearance
  const savedTheme = prefs?.theme || "system";
  document.querySelectorAll("#theme-options .theme-option").forEach((opt) => {
    const isSelected = opt.dataset.theme === savedTheme;
    opt.classList.toggle("selected", isSelected);
    const radio = opt.querySelector("input");
    if (radio) radio.checked = isSelected;
  });

  // If user explicitly saved light or dark in DB, respect it
  if (savedTheme === "light" || savedTheme === "dark") {
    applyTheme(savedTheme);
  } else {
    // If system and no local preference exists yet, initialize from system
    if (!localStorage.getItem("hb_theme")) {
      const prefersDark = window.matchMedia("(prefers-color-scheme: dark)").matches;
      applyTheme(prefersDark ? "dark" : "light");
    }
  }

  // Populate notifications
  if (prefs) {
    setChecked("notif_appointment", prefs.notif_appointment == 1);
    setChecked("notif_ratings", prefs.notif_ratings == 1);
    setChecked("notif_messages", prefs.notif_messages == 1);
    setChecked("notif_announcements", prefs.notif_announcements == 1);
    setChecked("notif_email", prefs.notif_email == 1);
  }

  // Show/hide role-specific tabs
  const tabDoctor = document.getElementById("settings-tab-doctor");
  const panelDoctor = document.getElementById("panel-doctor");
  const tabAdmin = document.getElementById("settings-tab-admin");
  const panelAdmin = document.getElementById("panel-admin");

  if (profile.role === "doctor" && prefs) {
    if (tabDoctor) tabDoctor.hidden = false;
    if (panelDoctor) panelDoctor.hidden = false;
    setChecked("accept_new_patients", prefs.accept_new_patients == 1);
    setChecked("profile_visible", prefs.profile_visible == 1);
    setVal("settings-consult-duration", prefs.consultation_duration || 30);

    const weeklySchedule = result.data.weekly_schedule;
    currentWeeklySchedule =
      Array.isArray(weeklySchedule) && weeklySchedule.length === 7
        ? weeklySchedule
        : null;
    const weeklyEditor = document.getElementById("settings-weekly-schedule");
    const globalHours = document.getElementById("settings-global-hours");
    if (Array.isArray(weeklySchedule) && weeklySchedule.length === 7) {
      if (weeklyEditor) weeklyEditor.hidden = false;
      if (globalHours) globalHours.hidden = true;
      renderWeeklySchedule(weeklySchedule);
    } else {
      if (weeklyEditor) weeklyEditor.hidden = true;
      if (globalHours) globalHours.hidden = false;
      setVal("settings-work-start", prefs.working_hours_start || "09:00");
      setVal("settings-work-end", prefs.working_hours_end || "17:00");
    }
  } else {
    if (tabDoctor) tabDoctor.hidden = true;
    if (panelDoctor) panelDoctor.hidden = true;
  }

  if (profile.role === "admin" && prefs) {
    if (tabAdmin) tabAdmin.hidden = false;
    if (panelAdmin) panelAdmin.hidden = false;
    setVal("settings-admin-tab", prefs.admin_default_tab || "overview");
  } else {
    if (tabAdmin) tabAdmin.hidden = true;
    if (panelAdmin) panelAdmin.hidden = true;
  }
}

/* ============================================================
   HELPERS
   ============================================================ */

function setVal(id, val) {
  const el = document.getElementById(id);
  if (el) el.value = val;
}

function setChecked(name, checked) {
  const el = document.querySelector(`#settings-modal [name="${name}"], [name="${name}"]`);
  if (el) el.checked = checked;
}

function renderWeeklySchedule(schedule) {
  const root = document.getElementById("settings-weekly-days");
  if (!root) return;

  const dayNames = {
    1: "Monday",
    2: "Tuesday",
    3: "Wednesday",
    4: "Thursday",
    5: "Friday",
    6: "Saturday",
    7: "Sunday",
  };
  const byDay = new Map(
    schedule.map((day) => [Number(day.day_of_week), day]),
  );
  root.replaceChildren();

  for (let dayNumber = 1; dayNumber <= 7; dayNumber += 1) {
    const saved = byDay.get(dayNumber) || {};
    const row = document.createElement("div");
    row.className = "weekly-schedule-row";

    const dayTitle = document.createElement("strong");
    dayTitle.className = "weekly-schedule-day";
    dayTitle.textContent = dayNames[dayNumber];

    const workingLabel = document.createElement("label");
    workingLabel.className = "weekly-schedule-working";
    const workingInput = document.createElement("input");
    workingInput.type = "checkbox";
    workingInput.checked = Number(saved.is_working) === 1;
    workingInput.dataset.weeklyWorking = String(dayNumber);
    const workingText = document.createElement("span");
    workingText.textContent = "Working";
    workingLabel.append(workingInput, workingText);

    const timeFields = document.createElement("div");
    timeFields.className = "weekly-schedule-times";

    const startLabel = document.createElement("label");
    startLabel.textContent = "Start";
    const startInput = document.createElement("input");
    startInput.type = "time";
    startInput.value = saved.start_time || "09:00";
    startInput.dataset.weeklyStart = String(dayNumber);
    startLabel.append(startInput);

    const endLabel = document.createElement("label");
    endLabel.textContent = "End";
    const endInput = document.createElement("input");
    endInput.type = "time";
    endInput.value = saved.end_time || "17:00";
    endInput.dataset.weeklyEnd = String(dayNumber);
    endLabel.append(endInput);

    const updateDisabledState = () => {
      startInput.disabled = !workingInput.checked;
      endInput.disabled = !workingInput.checked;
    };
    workingInput.addEventListener("change", updateDisabledState);
    updateDisabledState();

    timeFields.append(startLabel, endLabel);
    row.append(dayTitle, workingLabel, timeFields);
    root.append(row);
  }
}

function capitalize(str) {
  return str ? str.charAt(0).toUpperCase() + str.slice(1) : "";
}

/* ============================================================
   TAB NAVIGATION
   ============================================================ */

function initSettingsTabs() {
  const tabs = document.querySelectorAll("#settings-tabs .settings-tab");
  const modalPanels = document.querySelectorAll(".settings-panels .settings-panel");
  const pageSections = document.querySelectorAll("main.main-content section[id]");
  if (!tabs.length) return;

  tabs.forEach((tab) => {
    tab.addEventListener("click", () => {
      const section = tab.dataset.section;
      tabs.forEach((t) => t.classList.remove("active"));
      tab.classList.add("active");

      // Modal mode panels
      if (modalPanels.length) {
        modalPanels.forEach((p) => p.classList.remove("active"));
        const panel = document.getElementById(`panel-${section}`);
        if (panel) panel.classList.add("active");
      }

      // Standalone page sections
      if (pageSections.length) {
        pageSections.forEach((s) => {
          if (s.id === section) {
            s.hidden = false;
            s.classList.add("active");
          } else {
            s.hidden = true;
            s.classList.remove("active");
          }
        });
      }
    });
  });

  // Activate first visible tab
  const firstVisible = Array.from(tabs).find((t) => !t.hidden);
  if (firstVisible) firstVisible.click();
}

/* ============================================================
   PROFILE FORM
   ============================================================ */

function initProfileForm() {
  const form = document.getElementById("settings-profile-form");
  if (!form) return;
  // Remove any existing listener to prevent duplicates
  const newForm = form.cloneNode(true);
  form.parentNode.replaceChild(newForm, form);

  newForm.addEventListener("submit", async (e) => {
    e.preventDefault();
    const name = document.getElementById("settings-name")?.value.trim() || "";
    const email = document.getElementById("settings-email")?.value.trim() || "";
    const phone = document.getElementById("settings-phone")?.value.trim() || "";

    if (!name || !email) {
      showToast("Name and email are required.", "error");
      return;
    }

    const submitBtn = newForm.querySelector('[type="submit"]');
    setLoading(submitBtn, true, "Saving...");

    const result = await apiFetch(
      (getBasePath() + "api/settings/update.php"),
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ section: "profile", name, email, phone }),
      },
      "Failed to update profile.",
    );

    if (result.data?.success) {
      showToast(result.data.message, "success");
      const user = getUser();
      if (user) {
        user.name = result.data.name || name;
        user.email = result.data.email || email;
        saveUser(user);
      }
      await loadSettingsData();
    } else {
      showToast(result.data?.message || "Failed to update profile.", "error");
    }
    setLoading(submitBtn, false, "Save Changes");
  });
}

/* ============================================================
   PASSWORD FORM
   ============================================================ */

function initPasswordForm() {
  const form = document.getElementById("settings-password-form");
  if (!form) return;
  const newForm = form.cloneNode(true);
  form.parentNode.replaceChild(newForm, form);

  newForm.addEventListener("submit", async (e) => {
    e.preventDefault();
    const current = document.getElementById("settings-current-pw")?.value || "";
    const newPass = document.getElementById("settings-new-pw")?.value || "";
    const confirm = document.getElementById("settings-confirm-pw")?.value || "";

    if (!current || !newPass || !confirm) {
      showToast("All password fields are required.", "error");
      return;
    }
    const passwordErrors = [];
    if (newPass.length < 8) passwordErrors.push("at least 8 characters");
    if (!/[A-Z]/.test(newPass)) passwordErrors.push("an uppercase letter");
    if (!/[a-z]/.test(newPass)) passwordErrors.push("a lowercase letter");
    if (!/[0-9]/.test(newPass)) passwordErrors.push("a number");
    if (!/[^A-Za-z0-9]/.test(newPass)) passwordErrors.push("a special character");
    if (passwordErrors.length > 0) {
      showToast("New password must include " + passwordErrors.join(", ") + ".", "error");
      return;
    }
    if (newPass !== confirm) {
      showToast("New passwords do not match.", "error");
      return;
    }

    const submitBtn = newForm.querySelector('[type="submit"]');
    setLoading(submitBtn, true, "Updating...");

    const result = await apiFetch(
      (getBasePath() + "api/settings/update.php"),
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          section: "password",
          current_password: current,
          new_password: newPass,
          confirm_password: confirm,
        }),
      },
      "Failed to update password.",
    );

    if (result.data?.success) {
      showToast(result.data.message, "success");
      newForm.reset();
      const fill = document.getElementById("pw-strength-fill");
      const label = document.getElementById("pw-strength-label");
      if (fill) fill.className = "pw-strength-fill pw-strength-fill--level-0";
      if (label) {
        label.className = "pw-strength-label pw-strength-label--level-0";
        label.textContent = "";
      }
    } else {
      showToast(result.data?.message || "Failed to update password.", "error");
    }
    setLoading(submitBtn, false, "Update Password");
  });
}

/* ============================================================
   PASSWORD STRENGTH
   ============================================================ */

function initPasswordStrength() {
  const pwInput = document.getElementById("settings-new-pw");
  if (!pwInput) return;

  pwInput.addEventListener("input", () => {
    const val = pwInput.value;
    const fill = document.getElementById("pw-strength-fill");
    const label = document.getElementById("pw-strength-label");
    if (!fill || !label) return;

    let score = 0;
    if (val.length >= 8) score += 1;
    if (val.length >= 10) score += 1;
    if (/[A-Z]/.test(val)) score += 1;
    if (/[a-z]/.test(val)) score += 1;
    if (/[0-9]/.test(val)) score += 1;
    if (/[^A-Za-z0-9]/.test(val)) score += 1;

    const levels = [
      { pct: 0, color: "transparent", text: "" },
      { pct: 20, color: "var(--danger)", text: "Weak" },
      { pct: 40, color: "#f87171", text: "Fair" },
      { pct: 60, color: "var(--warning)", text: "Good" },
      { pct: 80, color: "#86efac", text: "Strong" },
      { pct: 100, color: "var(--success)", text: "Very Strong" },
    ];

    const levelIndex = Math.min(score, levels.length - 1);
    const level = levels[levelIndex];
    fill.className = `pw-strength-fill pw-strength-fill--level-${levelIndex}`;
    label.className = `pw-strength-label pw-strength-label--level-${levelIndex}`;
    label.textContent = level.text;
  });
}

/* ============================================================
   APPEARANCE (Theme)
   ============================================================ */

function initAppearance() {
  const options = document.querySelectorAll("#theme-options .theme-option");
  if (!options.length) return;

  options.forEach((opt) => {
    // Remove any old listener by cloning
    const newOpt = opt.cloneNode(true);
    opt.parentNode.replaceChild(newOpt, opt);

    newOpt.addEventListener("click", async (e) => {
      e.stopPropagation();
      const theme = newOpt.dataset.theme;
      if (!theme) return;

      // Guard: skip if already selected
      if (newOpt.classList.contains("selected")) return;

      // Update UI
      options.forEach((o) => {
        const actual = document.querySelector(
          `#theme-options .theme-option[data-theme="${o.dataset.theme}"]`,
        );
        if (actual) actual.classList.remove("selected");
      });
      newOpt.classList.add("selected");
      const radio = newOpt.querySelector("input");
      if (radio) radio.checked = true;

      // Apply theme immediately
      if (theme === "system") {
        const prefersDark = window.matchMedia(
          "(prefers-color-scheme: dark)",
        ).matches;
        applyTheme(prefersDark ? "dark" : "light");
      } else {
        applyTheme(theme);
      }

      // Save to server (one API call = one toast)
      const result = await apiFetch(
        (getBasePath() + "api/settings/update.php"),
        {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ section: "appearance", theme }),
        },
        "Failed to save theme.",
      );

      if (result.data?.success) {
        showToast(result.data.message, "success");
      } else {
        showToast(result.data?.message || "Failed to save theme.", "error");
      }
    });
  });
}

/* ============================================================
   NOTIFICATIONS FORM
   ============================================================ */

function initNotificationForm() {
  const form = document.getElementById("settings-notif-form");
  if (!form) return;
  const newForm = form.cloneNode(true);
  form.parentNode.replaceChild(newForm, form);

  newForm.addEventListener("submit", async (e) => {
    e.preventDefault();
    const data = { section: "notifications" };
    [
      "notif_appointment",
      "notif_ratings",
      "notif_messages",
      "notif_announcements",
      "notif_email",
    ].forEach((f) => {
      const el = newForm.querySelector(`[name="${f}"]`);
      data[f] = el ? (el.checked ? 1 : 0) : 0;
    });

    const submitBtn = newForm.querySelector('[type="submit"]');
    setLoading(submitBtn, true, "Saving...");

    const result = await apiFetch(
      (getBasePath() + "api/settings/update.php"),
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(data),
      },
      "Failed to save notification settings.",
    );

    if (result.data?.success) showToast(result.data.message, "success");
    else
      showToast(
        result.data?.message || "Failed to save notification settings.",
        "error",
      );

    setLoading(submitBtn, false, "Save Notification Settings");
  });
}

/* ============================================================
   DOCTOR FORM
   ============================================================ */

function initDoctorForm() {
  const form = document.getElementById("settings-doctor-form");
  if (!form) return;
  const newForm = form.cloneNode(true);
  form.parentNode.replaceChild(newForm, form);
  if (currentWeeklySchedule) {
    renderWeeklySchedule(currentWeeklySchedule);
  }

  newForm.addEventListener("submit", async (e) => {
    e.preventDefault();
    const data = {
      section: "doctor",
      accept_new_patients: newForm.querySelector('[name="accept_new_patients"]')
        ?.checked
        ? 1
        : 0,
      profile_visible: newForm.querySelector('[name="profile_visible"]')
        ?.checked
        ? 1
        : 0,
      consultation_duration: parseInt(
        document.getElementById("settings-consult-duration")?.value || "30",
      ),
    };

    const weeklyEditor = newForm.querySelector("#settings-weekly-schedule");
    if (weeklyEditor && !weeklyEditor.hidden) {
      data.weekly_schedule = Array.from(
        weeklyEditor.querySelectorAll(".weekly-schedule-row"),
      ).map((row) => {
        const working = row.querySelector("[data-weekly-working]");
        const start = row.querySelector("[data-weekly-start]");
        const end = row.querySelector("[data-weekly-end]");
        return {
          day_of_week: Number(working?.dataset.weeklyWorking),
          start_time: start?.value || "09:00",
          end_time: end?.value || "17:00",
          is_working: working?.checked ? 1 : 0,
        };
      });
    } else {
      data.working_hours_start =
        document.getElementById("settings-work-start")?.value || "09:00";
      data.working_hours_end =
        document.getElementById("settings-work-end")?.value || "17:00";
    }

    const submitBtn = newForm.querySelector('[type="submit"]');
    setLoading(submitBtn, true, "Saving...");

    const result = await apiFetch(
      (getBasePath() + "api/settings/update.php"),
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(data),
      },
      "Failed to save practice settings.",
    );

    if (result.data?.success) showToast(result.data.message, "success");
    else
      showToast(
        result.data?.message || "Failed to save practice settings.",
        "error",
      );

    setLoading(submitBtn, false, "Save Practice Settings");
  });
}

/* ============================================================
   ADMIN FORM
   ============================================================ */

function initAdminForm() {
  const form = document.getElementById("settings-admin-form");
  if (!form) return;
  const newForm = form.cloneNode(true);
  form.parentNode.replaceChild(newForm, form);

  newForm.addEventListener("submit", async (e) => {
    e.preventDefault();
    const defaultTab =
      document.getElementById("settings-admin-tab")?.value || "overview";

    const submitBtn = newForm.querySelector('[type="submit"]');
    setLoading(submitBtn, true, "Saving...");

    const result = await apiFetch(
      (getBasePath() + "api/settings/update.php"),
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          section: "admin",
          admin_default_tab: defaultTab,
        }),
      },
      "Failed to save admin preferences.",
    );

    if (result.data?.success) showToast(result.data.message, "success");
    else
      showToast(
        result.data?.message || "Failed to save admin preferences.",
        "error",
      );

    setLoading(submitBtn, false, "Save Admin Preferences");
  });
}

/* ============================================================
   DEACTIVATE ACCOUNT
   ============================================================ */

function openDeleteModal() {
  const modal = document.getElementById("settings-delete-modal")
    || document.getElementById("delete-modal");
  if (modal) modal.classList.add("open");
}

function closeDeleteModal() {
  const modal = document.getElementById("settings-delete-modal")
    || document.getElementById("delete-modal");
  if (modal) modal.classList.remove("open");
  document.getElementById("delete-account-form")?.reset();
}

function initDeleteAccountForm() {
  const form = document.getElementById("delete-account-form");
  if (!form) return;
  const newForm = form.cloneNode(true);
  form.parentNode.replaceChild(newForm, form);

  newForm.addEventListener("submit", async (e) => {
    e.preventDefault();
    const password = document.getElementById("delete-confirm-pw")?.value || "";
    if (!password) {
      showToast("Please enter your password to confirm.", "error");
      return;
    }
    if (
      !confirm(
        "Deactivate your account? Sign-in will be disabled and not-started appointments will be cancelled. Medical and audit history will be retained.",
      )
    )
      return;

    const submitBtn = newForm.querySelector('[type="submit"]');
    setLoading(submitBtn, true, "Deactivating...");

    const result = await apiFetch(
      (getBasePath() + "api/settings/update.php"),
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          section: "delete_account",
          confirm_password: password,
        }),
      },
      "Failed to deactivate account",
    );

    if (result.data?.success && result.data.message === "account_deactivated") {
      showToast("Your account has been deactivated. Redirecting...", "info");
      setTimeout(() => {
        localStorage.removeItem("hb_user");
        window.location.href = getBasePath() + "index.html";
      }, 1500);
    } else {
      showToast(result.data?.message || "Failed to deactivate account.", "error");
    }
    setLoading(submitBtn, false, "Deactivate My Account");
  });
}

// Expose globally
window.openSettings = openSettings;
window.closeSettings = closeSettings;
window.openDeleteModal = openDeleteModal;
window.closeDeleteModal = closeDeleteModal;

// Auto-initialize when loaded on standalone settings page.
// Works whether DOMContentLoaded has already fired (script at body bottom) or not.
function _tryAutoInitSettings() {
  if (document.getElementById("settings-profile-form")) {
    initSettings();
  }
}
if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", _tryAutoInitSettings);
} else {
  _tryAutoInitSettings();
}

