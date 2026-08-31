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
  document.body.style.overflow = "hidden";

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
  document.body.style.overflow = "";
}

/* ============================================================
   BUILD MODAL HTML
   ============================================================ */

function buildSettingsModal() {
  // Ensure settings.css is loaded so the modal renders with full styles on any page
  if (!document.querySelector('link[href*="settings.css"]')) {
    const link = document.createElement("link");
    link.rel = "stylesheet";
    link.href = getBasePath() + "assets/css/pages/settings.css?v=3";
    document.head.appendChild(link);
  }

  const modal = document.createElement("div");
  modal.className = "modal-overlay";
  modal.id = "settings-modal";
  // Use individual properties so modal-overlay background, opacity, pointer-events
  // from the CSS class are NOT overridden by cssText assignment.
  modal.style.zIndex = "3000";
  modal.style.alignItems = "flex-start";
  modal.style.paddingTop = "60px";
  modal.style.overflowY = "auto";

  modal.innerHTML = `
    <div class="settings-modal-content">
      <!-- Header -->
      <div class="settings-modal-header">
        <h2><i class="fas fa-gear" aria-hidden="true"></i> Settings</h2>
        <button class="btn btn-outline btn-sm" onclick="closeSettings()" aria-label="Close settings">&times;</button>
      </div>

      <!-- Tabs -->
      <div class="settings-tabs" id="settings-tabs">
        <button class="settings-tab active" data-section="account"><i class="fas fa-user" aria-hidden="true"></i> Account</button>
        <button class="settings-tab" data-section="password"><i class="fas fa-lock" aria-hidden="true"></i> Password</button>
        <button class="settings-tab" data-section="appearance"><i class="fas fa-palette" aria-hidden="true"></i> Appearance</button>
        <button class="settings-tab" data-section="notifications"><i class="fas fa-bell" aria-hidden="true"></i> Notifications</button>
        <button class="settings-tab" data-section="doctor" id="settings-tab-doctor" style="display:none"><i class="fas fa-stethoscope" aria-hidden="true"></i> Practice</button>
        <button class="settings-tab" data-section="admin" id="settings-tab-admin" style="display:none"><i class="fas fa-user-tie" aria-hidden="true"></i> Admin</button>
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
                  <input type="text" id="settings-role" disabled style="opacity:0.7;cursor:not-allowed" />
                </div>
              </div>
              <div class="settings-form-row">
                <div class="form-group">
                  <label>Member Since</label>
                  <input type="text" id="settings-created" disabled style="opacity:0.7;cursor:not-allowed" />
                </div>
                <div class="form-group">
                  <label>Account Status</label>
                  <input type="text" id="settings-status" disabled style="opacity:0.7;cursor:not-allowed" />
                </div>
              </div>
              <div class="settings-form-actions">
                <button type="submit" class="btn btn-primary">Save Changes</button>
              </div>
            </form>
          </div>

          <div class="settings-card settings-card-danger">
            <div class="settings-card-header">
              <span class="settings-card-icon" style="color:var(--danger)"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i></span>
              <div><h3>Danger Zone</h3><p>Permanently delete your account and all associated data.</p></div>
            </div>
            <p style="font-size:0.85rem;color:var(--text-muted);margin-bottom:var(--s4)">
              This action cannot be undone. All your appointments, ratings, and messages will be permanently removed.
            </p>
            <button class="btn btn-danger" onclick="openDeleteModal()">Delete My Account</button>
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
                  <button type="button" class="pw-toggle" onclick="togglePasswordVisibility(this)" aria-label="Show password" title="Show password">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                  </button>
                </div>
              </div>
              <div class="settings-form-row">
                <div class="form-group">
                  <label for="settings-new-pw">New Password</label>
                  <div class="pw-field">
                    <input type="password" id="settings-new-pw" placeholder="Min. 6 characters" required minlength="6" />
                    <button type="button" class="pw-toggle" onclick="togglePasswordVisibility(this)" aria-label="Show password" title="Show password">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                  </div>
                  <div class="pw-strength-wrap" style="margin-top:var(--s1)">
                    <div class="pw-strength-bar"><div class="pw-strength-fill" id="pw-strength-fill"></div></div>
                    <span class="pw-strength-label" id="pw-strength-label"></span>
                  </div>
                </div>
                <div class="form-group">
                  <label for="settings-confirm-pw">Confirm New Password</label>
                  <div class="pw-field">
                    <input type="password" id="settings-confirm-pw" placeholder="Re-enter new password" required minlength="6" />
                    <button type="button" class="pw-toggle" onclick="togglePasswordVisibility(this)" aria-label="Show password" title="Show password">
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
                  <label for="settings-work-start">Working Hours Start</label>
                  <input type="time" id="settings-work-start" value="09:00" />
                </div>
              </div>
              <div class="settings-form-row">
                <div class="form-group">
                  <label for="settings-work-end">Working Hours End</label>
                  <input type="time" id="settings-work-end" value="17:00" />
                </div>
                <div class="form-group">
                  <label>&nbsp;</label>
                  <p style="font-size:0.8rem;color:var(--text-muted);padding-top:var(--s2)">Set your typical working hours for patient scheduling.</p>
                </div>
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

    <!-- Delete Account Modal (nested - NOT a modal-overlay to prevent double overlay stacking) -->
    <div id="settings-delete-modal" style="position:fixed;inset:0;z-index:3100;display:flex;align-items:center;justify-content:center;padding:var(--s6);opacity:0;pointer-events:none;transition:opacity var(--t);background:rgba(0,0,0,0.5)">
      <div class="modal" style="max-width:440px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:var(--s6)">
          <h3 style="margin:0;color:var(--danger)"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i> Delete Account</h3>
          <button class="close-btn" onclick="closeDeleteModal()">&times;</button>
        </div>
        <p style="font-size:0.9rem;color:var(--text-secondary);margin-bottom:var(--s6);line-height:1.6">
          This will permanently delete your account and all associated data including appointments, ratings, and messages. <strong>This cannot be undone.</strong>
        </p>
        <form id="delete-account-form">
          <div class="form-group">
            <label for="delete-confirm-pw">Enter your password to confirm</label>
            <div class="pw-field">
              <input type="password" id="delete-confirm-pw" placeholder="Your password" required />
              <button type="button" class="pw-toggle" onclick="togglePasswordVisibility(this)" aria-label="Show password" title="Show password">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              </button>
            </div>
          </div>
          <button type="submit" class="btn btn-danger btn-block">Permanently Delete My Account</button>
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
    if (tabDoctor) tabDoctor.style.display = "";
    if (panelDoctor) panelDoctor.style.display = "";
    setChecked("accept_new_patients", prefs.accept_new_patients == 1);
    setChecked("profile_visible", prefs.profile_visible == 1);
    setVal("settings-consult-duration", prefs.consultation_duration || 30);
    setVal("settings-work-start", prefs.working_hours_start || "09:00");
    setVal("settings-work-end", prefs.working_hours_end || "17:00");
  } else {
    if (tabDoctor) tabDoctor.style.display = "none";
    if (panelDoctor) panelDoctor.style.display = "none";
  }

  if (profile.role === "admin" && prefs) {
    if (tabAdmin) tabAdmin.style.display = "";
    if (panelAdmin) panelAdmin.style.display = "";
    setVal("settings-admin-tab", prefs.admin_default_tab || "overview");
  } else {
    if (tabAdmin) tabAdmin.style.display = "none";
    if (panelAdmin) panelAdmin.style.display = "none";
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
            s.style.display = "block";
            s.classList.add("active");
          } else {
            s.style.display = "none";
            s.classList.remove("active");
          }
        });
      }
    });
  });

  // Activate first visible tab
  const firstVisible = Array.from(tabs).find((t) => t.style.display !== "none");
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
    if (newPass.length < 6) {
      showToast("New password must be at least 6 characters.", "error");
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
      if (fill) fill.style.width = "0%";
      if (label) label.textContent = "";
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
    if (val.length >= 6) score += 1;
    if (val.length >= 10) score += 1;
    if (/[A-Z]/.test(val)) score += 1;
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

    const level = levels[Math.min(score, levels.length - 1)];
    fill.style.width = level.pct + "%";
    fill.style.background = level.color;
    label.textContent = level.text;
    label.style.color = level.color;
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
      working_hours_start:
        document.getElementById("settings-work-start")?.value || "09:00",
      working_hours_end:
        document.getElementById("settings-work-end")?.value || "17:00",
    };

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
   DELETE ACCOUNT
   ============================================================ */

function openDeleteModal() {
  const modal = document.getElementById("settings-delete-modal");
  if (modal) modal.classList.add("open");
}

function closeDeleteModal() {
  const modal = document.getElementById("settings-delete-modal");
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
        "Are you absolutely sure? This will permanently delete your account and all associated data.",
      )
    )
      return;

    const submitBtn = newForm.querySelector('[type="submit"]');
    setLoading(submitBtn, true, "Deleting...");

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
      "Failed to delete account.",
    );

    if (result.data?.success && result.data.message === "account_deleted") {
      showToast("Your account has been deleted. Redirecting...", "info");
      setTimeout(() => {
        localStorage.removeItem("hb_user");
        window.location.href = getBasePath() + "index.html";
      }, 1500);
    } else {
      showToast(result.data?.message || "Failed to delete account.", "error");
    }
    setLoading(submitBtn, false, "Permanently Delete My Account");
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

