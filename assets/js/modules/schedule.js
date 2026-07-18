/**
 * HealthBridge — Schedule Manager
 * Rebuilt from scratch with a premium two-column workspace.
 * Uses: apiFetch(), showToast() from main.js
 */

"use strict";

const ScheduleManager = (() => {
  // ── Constants ──────────────────────────────────────────────

  const DAY_NAMES = {
    1: "Monday",
    2: "Tuesday",
    3: "Wednesday",
    4: "Thursday",
    5: "Friday",
    6: "Saturday",
    7: "Sunday",
  };

  const DAY_NAMES_SHORT = {
    1: "Mon",
    2: "Tue",
    3: "Wed",
    4: "Thu",
    5: "Fri",
    6: "Sat",
    7: "Sun",
  };

  const VALID_DURATIONS = [15, 20, 30, 45, 60];

  function generateTimeOptions(openTime, closeTime) {
    const options = [];
    const openParts = (openTime || "08:00").split(":").map(Number);
    const closeParts = (closeTime || "22:00").split(":").map(Number);
    const openMin = openParts[0] * 60 + openParts[1];
    const closeMin = closeParts[0] * 60 + closeParts[1];

    for (let m = openMin; m <= closeMin; m += 30) {
      const h = Math.floor(m / 60);
      const min = m % 60;
      const value = `${String(h).padStart(2, "0")}:${String(min).padStart(2, "0")}`;
      const ampm = h >= 12 ? "PM" : "AM";
      const displayHour = h % 12 || 12;
      const label = `${displayHour}:${String(min).padStart(2, "0")} ${ampm}`;
      options.push({ value, label });
    }
    return options;
  }

  let TIME_OPTIONS = generateTimeOptions("08:00", "22:00"); // fallback

  const API = {
    GET: (getBasePath() + "api/schedule/get.php"),
    UPDATE: (getBasePath() + "api/schedule/update.php"),
    RESET: (getBasePath() + "api/schedule/reset.php"),
  };

  // ── State ──────────────────────────────────────────────────

  let currentRole = ""; // 'doctor' or 'admin'
  let currentDoctorId = 0; // The doctor being managed
  let scheduleData = null; // Cached schedule
  let isDirty = false;

  // ── Public: Initialize ─────────────────────────────────────

  function init(containerId, role, doctorId) {
    currentRole = role;
    currentDoctorId = doctorId;
    isDirty = false;

    const container = document.getElementById(containerId);
    if (!container) {
      console.error("Schedule container not found:", containerId);
      return;
    }

    // Render new UI layout skeleton
    container.innerHTML = buildScheduleHTML(role);

    // Event listener to monitor changes and set dirty state
    container.addEventListener("change", (e) => {
      const isTargetInput =
        e.target.classList.contains("schedule-time-input") ||
        e.target.classList.contains("schedule-day-working-toggle") ||
        e.target.id === "schedule-duration" ||
        e.target.id === "schedule-max-appts" ||
        e.target.id === "schedule-break-start" ||
        e.target.id === "schedule-break-end" ||
        e.target.id === "schedule-availability";

      if (isTargetInput) {
        onSettingChange();
      }
    });

    // Load schedule data
    loadSchedule();
  }

  function loadForDoctor(doctorId) {
    currentDoctorId = doctorId;
    isDirty = false;
    loadSchedule();
  }

  // ── API Calls ──────────────────────────────────────────────

  async function loadSchedule() {
    await loadHospitalHours();

    const result = await apiFetch(
      API.GET,
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        credentials: "same-origin",
        body: JSON.stringify({ doctor_id: currentDoctorId }),
      },
      "Failed to load schedule.",
    );

    if (!result.ok || !result.data?.success) {
      showToast(result.data?.message || "Failed to load schedule.", "error");
      return;
    }

    scheduleData = result.data.schedule;
    renderSchedule();
  }

  async function loadHospitalHours() {
    try {
      const res = await fetch((getBasePath() + "api/settings/get-hospital.php"), {
        credentials: "same-origin",
      });
      const result = await res.json();
      if (result.success && result.settings) {
        const s = result.settings;
        const newOptions = generateTimeOptions(
          s.appointment_open_time || "08:00",
          s.appointment_close_time || "22:00",
        );
        TIME_OPTIONS = newOptions;
        rebuildTimeDropdowns();

        const openTime = s.appointment_open_time || "08:00";
        const closeTime = s.appointment_close_time || "22:00";
        const openFormatted = formatTimeLocal(openTime);
        const closeFormatted = formatTimeLocal(closeTime);

        // Update both notice areas
        const hoursEl = document.getElementById("schedule-hospital-hours");
        if (hoursEl)
          hoursEl.textContent = `${openFormatted} – ${closeFormatted}`;

        const stickyHoursEl = document.getElementById(
          "schedule-hospital-hours-sticky",
        );
        if (stickyHoursEl)
          stickyHoursEl.textContent = `${openFormatted} – ${closeFormatted}`;

        const dashboardHoursEl = document.getElementById(
          "summary-hospital-hours-val",
        );
        if (dashboardHoursEl)
          dashboardHoursEl.textContent = `${openFormatted} – ${closeFormatted}`;
      }
    } catch (e) {
      console.warn("Failed to load hospital operating hours:", e);
    }
  }

  async function saveSchedule() {
    const weekly = collectWeeklyData();
    const settings = collectSettingsData();

    const weeklyError = validateWeekly(weekly);
    if (weeklyError) {
      showToast(weeklyError, "error");
      return;
    }

    const settingsError = validateSettings(settings);
    if (settingsError) {
      showToast(settingsError, "error");
      return;
    }

    const breakError = validateBreakWithinHours(weekly, settings);
    if (breakError) {
      showToast(breakError, "error");
      return;
    }

    const payload = {
      doctor_id: currentDoctorId,
      weekly: weekly,
      settings: settings,
    };

    const saveBtn = document.getElementById("schedule-save-btn");
    const originalText = saveBtn?.innerHTML || "Save Changes";
    setLoading(saveBtn, true, "Saving...");

    const result = await apiFetch(
      API.UPDATE,
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        credentials: "same-origin",
        body: JSON.stringify(payload),
      },
      "Failed to save schedule.",
    );

    setLoading(saveBtn, false, originalText);

    if (result.data?.success) {
      showToast("Schedule saved successfully!", "success");
      scheduleData = result.data.schedule;
      isDirty = false;
      renderSchedule();
    } else {
      showToast(result.data?.message || "Failed to save schedule.", "error");
    }
  }

  async function resetSchedule() {
    if (
      !confirm(
        "Reset this doctor's schedule to defaults? This cannot be undone.",
      )
    ) {
      return;
    }

    const result = await apiFetch(
      API.RESET,
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        credentials: "same-origin",
        body: JSON.stringify({ doctor_id: currentDoctorId }),
      },
      "Failed to reset schedule.",
    );

    if (result.data?.success) {
      showToast(
        result.data.message || "Schedule reset successfully!",
        "success",
      );
      isDirty = false;
      await loadSchedule();
    } else {
      showToast(result.data?.message || "Failed to reset schedule.", "error");
    }
  }

  // ── Render HTML Layout ──────────────────────────────────────

  function buildScheduleHTML(role) {
    const isAdmin = role === "admin";
    return `
      <div class="schedule-workspace-wrapper">
        <!-- Step 4 — Schedule Summary Dashboard -->
        <div class="schedule-dashboard-summary">
          <div class="summary-widget">
            <div class="lbl">Availability</div>
            <div class="val" id="summary-availability-status"><span class="badge badge-success">Available</span></div>
          </div>
          <div class="summary-widget">
            <div class="lbl">Working Days</div>
            <div class="val" id="summary-working-days">0 Days</div>
          </div>
          <div class="summary-widget">
            <div class="lbl">Weekly Hours</div>
            <div class="val" id="summary-weekly-hours">0 Hrs</div>
          </div>
          <div class="summary-widget">
            <div class="lbl">Appt Duration</div>
            <div class="val" id="summary-duration">30 Mins</div>
          </div>
          <div class="summary-widget">
            <div class="lbl">Hospital Operating Hours</div>
            <div class="val" id="summary-hospital-hours-val">08:00 AM – 10:00 PM</div>
          </div>
        </div>

        <!-- Two-column workspace layout -->
        <div class="schedule-workspace-layout">
          <!-- LEFT COLUMN (70%): Expandable cards vertical list -->
          <div class="schedule-workspace-left" id="schedule-days-grid">
            ${buildDayPillsHTML()}
          </div>

          <!-- RIGHT COLUMN (30%): Sticky settings panel -->
          <div class="schedule-workspace-right">
            <div class="schedule-settings-sticky">
              <div class="sticky-panel-header">
                <i class="fas fa-sliders" aria-hidden="true"></i>
                <span>Configuration Panel</span>
              </div>
              
              <div class="sticky-panel-body">
                <!-- Unsaved changes banner -->
                <div class="schedule-unsaved-banner" id="schedule-unsaved-banner" style="display: none;">
                  <i class="fas fa-triangle-exclamation"></i> Unsaved changes detected
                </div>

                <!-- Hospital hours display -->
                <div class="sticky-hours-card">
                  <div class="hours-card-title">Hospital Hours</div>
                  <div class="hours-card-value" id="schedule-hospital-hours-sticky">08:00 AM – 10:00 PM</div>
                  <div class="hours-card-hint">Schedule must fall within these hours</div>
                </div>

                <!-- Schedule statistics -->
                <div class="sticky-stats-section">
                  <div class="stats-section-title">Workspace Statistics</div>
                  <div class="stats-grid-compact">
                    <div class="stat-box-compact">
                      <div class="val" id="stats-working-days">0 Days</div>
                      <div class="lbl">Working Days</div>
                    </div>
                    <div class="stat-box-compact">
                      <div class="val" id="stats-weekly-hours">0 Hrs</div>
                      <div class="lbl">Weekly Hours</div>
                    </div>
                    <div class="stat-box-compact">
                      <div class="val" id="stats-total-slots">0</div>
                      <div class="lbl">Est. Slots</div>
                    </div>
                  </div>
                </div>

                <!-- Settings form fields -->
                <div class="sticky-form-section">
                  <div class="form-group">
                    <label for="schedule-duration">Appointment Duration</label>
                    <select id="schedule-duration" class="form-select">
                      ${VALID_DURATIONS.map((d) => `<option value="${d}">${d} minutes</option>`).join("")}
                    </select>
                  </div>

                  <div class="form-group">
                    <label for="schedule-max-appts">Max Appointments / Day</label>
                    <input type="number" id="schedule-max-appts" class="form-input" min="1" max="100" value="25" />
                  </div>

                  <div class="form-row-break">
                    <div class="form-group">
                      <label for="schedule-break-start">Break Start</label>
                      <input type="time" id="schedule-break-start" class="form-input" />
                    </div>
                    <div class="form-group">
                      <label for="schedule-break-end">Break End</label>
                      <input type="time" id="schedule-break-end" class="form-input" />
                    </div>
                  </div>

                  <div class="form-group">
                    <label>Availability Status</label>
                    <div class="switch-container" style="margin-top: 4px">
                      <label class="switch">
                        <input type="checkbox" id="schedule-availability" />
                        <span class="slider"></span>
                      </label>
                      <span id="schedule-avail-label" style="font-size: 0.8rem; font-weight:600;">Available for bookings</span>
                    </div>
                  </div>
                </div>

                <!-- Actions -->
                <div class="sticky-actions-section">
                  <button id="schedule-save-btn" class="btn btn-primary btn-block" onclick="ScheduleManager.saveSchedule()">
                    <i class="fas fa-floppy-disk" aria-hidden="true"></i> Save Changes
                  </button>
                  <div class="sticky-secondary-actions">
                    ${
                      isAdmin
                        ? `
                      <button class="btn btn-outline btn-sm" onclick="ScheduleManager.resetSchedule()">
                        <i class="fas fa-rotate-left" aria-hidden="true"></i> Reset
                      </button>
                    `
                        : ""
                    }
                    <button class="btn btn-outline btn-sm" onclick="ScheduleManager.refresh()">
                      <i class="fas fa-rotate" aria-hidden="true"></i> Refresh
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    `;
  }

  function buildDayPillsHTML() {
    const timeOpts = TIME_OPTIONS.map(
      (t) => `<option value="${t.value}">${t.label}</option>`,
    ).join("");
    let html = "";

    for (let d = 1; d <= 7; d++) {
      const dayFull = DAY_NAMES[d];
      html += `
        <!-- Expandable day card -->
        <div class="schedule-day-card schedule-day-pill" data-day="${d}">
          <div class="schedule-day-card-header" onclick="ScheduleManager.toggleDayExpansion(${d})">
            <div class="schedule-day-card-info">
              <span class="schedule-day-card-name">${dayFull}</span>
              <span class="schedule-day-card-summary" id="schedule-day-summary-${d}">OFF DUTY</span>
            </div>
            <div class="schedule-day-card-actions">
              <span class="schedule-day-status-badge" id="schedule-pill-status-${d}">Off Duty</span>
              <i class="fas fa-chevron-down schedule-day-chevron" id="schedule-day-chevron-${d}"></i>
            </div>
          </div>
          
          <div class="schedule-day-card-body" id="schedule-pill-times-${d}" style="display: none;">
            <div class="schedule-day-editor-grid">
              
              <!-- Toggle Working Status -->
              <div class="schedule-day-toggle-container">
                <label class="schedule-field-label">Working on this day?</label>
                <div class="switch-container">
                  <label class="switch">
                    <input type="checkbox" class="schedule-day-working-toggle" data-day="${d}" onchange="ScheduleManager.onDayToggle(${d})" />
                    <span class="slider"></span>
                  </label>
                </div>
              </div>

              <!-- Time selection select inputs -->
              <div class="schedule-day-times-inputs">
                <div class="form-group-time">
                  <label class="schedule-field-label">Start Time</label>
                  <select class="form-select schedule-time-input schedule-start-time" data-day="${d}">
                    ${timeOpts}
                  </select>
                </div>
                <div class="form-group-time">
                  <label class="schedule-field-label">End Time</label>
                  <select class="form-select schedule-time-input schedule-end-time" data-day="${d}">
                    ${timeOpts}
                  </select>
                </div>
              </div>

              <!-- Estimated slot previews and warnings -->
              <div class="schedule-day-preview-box">
                <div class="preview-item">
                  <span class="preview-label">Shift Details:</span>
                  <span class="preview-value" id="schedule-day-preview-text-${d}">OFF</span>
                </div>
                <div class="preview-item">
                  <span class="preview-label">Total Hours:</span>
                  <span class="preview-value" id="schedule-day-hours-val-${d}">0 Hours</span>
                </div>
                <div class="preview-item">
                  <span class="preview-label">Estimated Slots:</span>
                  <span class="preview-value" id="schedule-day-slots-val-${d}">0 Slots</span>
                </div>
              </div>

            </div>
          </div>
        </div>
      `;
    }
    return html;
  }

  function rebuildTimeDropdowns() {
    const timeOptsHtml = TIME_OPTIONS.map(
      (t) => `<option value="${t.value}">${t.label}</option>`,
    ).join("");
    document.querySelectorAll(".schedule-time-input").forEach((select) => {
      const currentValue = select.value;
      select.innerHTML = timeOptsHtml;
      if ([...select.options].some((o) => o.value === currentValue)) {
        select.value = currentValue;
      } else {
        select.selectedIndex = 0;
      }
    });
  }

  // ── Render Data and populate values ─────────────────────────

  function renderSchedule() {
    if (!scheduleData) return;

    const settings = scheduleData.settings;
    const weekly = scheduleData.weekly;

    // Populate settings fields
    const durationEl = document.getElementById("schedule-duration");
    if (durationEl && settings.appointment_duration) {
      durationEl.value = settings.appointment_duration;
    }

    const maxApptsEl = document.getElementById("schedule-max-appts");
    if (maxApptsEl && settings.max_appointments_per_day) {
      maxApptsEl.value = settings.max_appointments_per_day;
    }

    const breakStartEl = document.getElementById("schedule-break-start");
    if (breakStartEl) {
      breakStartEl.value = settings.break_start || "";
    }

    const breakEndEl = document.getElementById("schedule-break-end");
    if (breakEndEl) {
      breakEndEl.value = settings.break_end || "";
    }

    const availToggle = document.getElementById("schedule-availability");
    if (availToggle) {
      availToggle.checked = parseInt(settings.is_available) === 1;
      onAvailabilityToggle();
    }

    // Populate weekly days list
    if (weekly && Array.isArray(weekly)) {
      weekly.forEach((day) => {
        const d = parseInt(day.day_of_week);
        const card = document.querySelector(
          `.schedule-day-card[data-day="${d}"]`,
        );
        const startInput = document.querySelector(
          `.schedule-start-time[data-day="${d}"]`,
        );
        const endInput = document.querySelector(
          `.schedule-end-time[data-day="${d}"]`,
        );
        const statusEl = document.getElementById(`schedule-pill-status-${d}`);
        const timesEl = document.getElementById(`schedule-pill-times-${d}`);
        const toggleInput = document.querySelector(
          `.schedule-day-working-toggle[data-day="${d}"]`,
        );

        const isWorking = parseInt(day.is_working) === 1;

        if (startInput) startInput.value = day.start_time || "09:00";
        if (endInput) endInput.value = day.end_time || "17:00";
        if (toggleInput) toggleInput.checked = isWorking;

        if (card) {
          card.classList.toggle("day-off", !isWorking);
        }

        if (statusEl) {
          statusEl.textContent = isWorking ? "Working" : "Off Duty";
          statusEl.classList.toggle("active", isWorking);
        }

        if (timesEl) {
          timesEl.style.display = card?.classList.contains("expanded")
            ? "block"
            : "none";
        }

        if (startInput) startInput.disabled = !isWorking;
        if (endInput) endInput.disabled = !isWorking;
      });
    }

    updateUnsavedChangesIndicator(false);
    updateWorkspaceStats();
  }

  // ── Event Handlers ─────────────────────────────────────────

  function onDayToggle(day) {
    const card = document.querySelector(
      `.schedule-day-card[data-day="${day}"]`,
    );
    const toggleInput = document.querySelector(
      `.schedule-day-working-toggle[data-day="${day}"]`,
    );
    const startInput = document.querySelector(
      `.schedule-start-time[data-day="${day}"]`,
    );
    const endInput = document.querySelector(
      `.schedule-end-time[data-day="${day}"]`,
    );
    const statusEl = document.getElementById(`schedule-pill-status-${day}`);

    const isWorking = toggleInput?.checked || false;

    if (card) {
      card.classList.toggle("day-off", !isWorking);
    }

    if (statusEl) {
      statusEl.textContent = isWorking ? "Working" : "Off Duty";
      statusEl.classList.toggle("active", isWorking);
    }

    if (startInput) startInput.disabled = !isWorking;
    if (endInput) endInput.disabled = !isWorking;

    onSettingChange();
  }

  function onAvailabilityToggle() {
    const toggle = document.getElementById("schedule-availability");
    const label = document.getElementById("schedule-avail-label");
    const dashboardStatusEl = document.getElementById(
      "summary-availability-status",
    );

    if (!toggle) return;

    const isAvail = toggle.checked;
    if (label) {
      label.textContent = isAvail
        ? "Available for bookings"
        : "Not available for bookings";
      label.style.color = isAvail ? "var(--primary)" : "var(--danger)";
    }

    if (dashboardStatusEl) {
      dashboardStatusEl.innerHTML = isAvail
        ? `<span class="badge badge-success">Available</span>`
        : `<span class="badge badge-danger">Unavailable</span>`;
    }
  }

  function onSettingChange() {
    isDirty = true;
    updateUnsavedChangesIndicator(true);
    updateWorkspaceStats();
  }

  function updateUnsavedChangesIndicator(dirty) {
    const indicator = document.getElementById("schedule-unsaved-banner");

    if (indicator) {
      indicator.style.display = dirty ? "block" : "none";
    }
  }

  // ── Expand/Collapse ─────────────────────────────────────────

  function toggleDayExpansion(dayNum) {
    const card = document.querySelector(
      `.schedule-day-card[data-day="${dayNum}"]`,
    );
    if (!card) return;

    const isExpanded = card.classList.contains("expanded");
    const timesEl = document.getElementById(`schedule-pill-times-${dayNum}`);

    // If mobile view, close other open cards to follow accordion design
    if (window.innerWidth <= 768) {
      document.querySelectorAll(".schedule-day-card").forEach((c) => {
        if (parseInt(c.getAttribute("data-day")) !== dayNum) {
          c.classList.remove("expanded");
          const cTimes = document.getElementById(
            `schedule-pill-times-${c.getAttribute("data-day")}`,
          );
          if (cTimes) cTimes.style.display = "none";
        }
      });
    }

    if (isExpanded) {
      card.classList.remove("expanded");
      if (timesEl) timesEl.style.display = "none";
    } else {
      card.classList.add("expanded");
      if (timesEl) timesEl.style.display = "block";
    }
  }

  // ── Dynamic Workspace Statistics ────────────────────────────

  function updateWorkspaceStats() {
    let workingDaysCount = 0;
    let totalWeeklyHours = 0;
    let totalWeeklySlots = 0;

    const duration = parseInt(
      document.getElementById("schedule-duration")?.value || "30",
    );
    const maxApptsPerDay = parseInt(
      document.getElementById("schedule-max-appts")?.value || "25",
    );
    const breakStart =
      document.getElementById("schedule-break-start")?.value || "";
    const breakEnd = document.getElementById("schedule-break-end")?.value || "";

    let breakMinutes = 0;
    if (breakStart && breakEnd) {
      const bsParts = breakStart.split(":").map(Number);
      const beParts = breakEnd.split(":").map(Number);
      breakMinutes = Math.max(
        0,
        beParts[0] * 60 + beParts[1] - (bsParts[0] * 60 + bsParts[1]),
      );
    }

    for (let d = 1; d <= 7; d++) {
      const toggle = document.querySelector(
        `.schedule-day-working-toggle[data-day="${d}"]`,
      );
      const isWorking = toggle?.checked || false;

      const summaryEl = document.getElementById(`schedule-day-summary-${d}`);
      const hoursPreviewVal = document.getElementById(
        `schedule-day-hours-val-${d}`,
      );
      const slotsPreviewVal = document.getElementById(
        `schedule-day-slots-val-${d}`,
      );
      const previewTextEl = document.getElementById(
        `schedule-day-preview-text-${d}`,
      );

      if (isWorking) {
        workingDaysCount++;
        const startVal =
          document.querySelector(`.schedule-start-time[data-day="${d}"]`)
            ?.value || "09:00";
        const endVal =
          document.querySelector(`.schedule-end-time[data-day="${d}"]`)
            ?.value || "17:00";

        const startParts = startVal.split(":").map(Number);
        const endParts = endVal.split(":").map(Number);

        const startMin = startParts[0] * 60 + startParts[1];
        const endMin = endParts[0] * 60 + endParts[1];
        let dayMinutes = Math.max(0, endMin - startMin);

        // Check if break falls inside the working shift on this day
        let appliesBreak = false;
        if (breakMinutes > 0) {
          const bsParts = breakStart.split(":").map(Number);
          const beParts = breakEnd.split(":").map(Number);
          const bsMin = bsParts[0] * 60 + bsParts[1];
          const beMin = beParts[0] * 60 + beParts[1];

          if (bsMin >= startMin && beMin <= endMin) {
            appliesBreak = true;
            dayMinutes = Math.max(0, dayMinutes - breakMinutes);
          }
        }

        const dayHours = dayMinutes / 60;
        totalWeeklyHours += dayHours;

        // Estimate slots count
        const estSlots = Math.min(
          maxApptsPerDay,
          Math.floor(dayMinutes / duration),
        );
        totalWeeklySlots += estSlots;

        // Update day previews
        const startFormatted = formatTimeLocal(startVal);
        const endFormatted = formatTimeLocal(endVal);
        const breakInfo = appliesBreak
          ? ` (Break: ${formatTimeLocal(breakStart)} – ${formatTimeLocal(breakEnd)})`
          : "";

        if (summaryEl)
          summaryEl.textContent = `${startFormatted} – ${endFormatted}${breakInfo}`;
        if (previewTextEl)
          previewTextEl.textContent = `${startFormatted} – ${endFormatted}`;
        if (hoursPreviewVal)
          hoursPreviewVal.textContent = `${dayHours.toFixed(1)} Hours`;
        if (slotsPreviewVal) slotsPreviewVal.textContent = `${estSlots} Slots`;
      } else {
        if (summaryEl) summaryEl.textContent = "OFF DUTY";
        if (previewTextEl) previewTextEl.textContent = "OFF DUTY";
        if (hoursPreviewVal) hoursPreviewVal.textContent = "0 Hours";
        if (slotsPreviewVal) slotsPreviewVal.textContent = "0 Slots";
      }
    }

    // Update sticky panel stats
    const statsDays = document.getElementById("stats-working-days");
    if (statsDays) statsDays.textContent = `${workingDaysCount} Days`;

    const statsHours = document.getElementById("stats-weekly-hours");
    if (statsHours)
      statsHours.textContent = `${totalWeeklyHours.toFixed(1)} Hrs`;

    const statsSlots = document.getElementById("stats-total-slots");
    if (statsSlots) statsSlots.textContent = totalWeeklySlots;

    // Update dashboard widgets
    const summaryDays = document.getElementById("summary-working-days");
    if (summaryDays) summaryDays.textContent = `${workingDaysCount} Days`;

    const summaryHours = document.getElementById("summary-weekly-hours");
    if (summaryHours)
      summaryHours.textContent = `${totalWeeklyHours.toFixed(1)} Hrs`;

    const summaryDuration = document.getElementById("summary-duration");
    if (summaryDuration) summaryDuration.textContent = `${duration} Mins`;
  }

  // ── Data Collection ────────────────────────────────────────

  function collectWeeklyData() {
    const days = [];
    for (let d = 1; d <= 7; d++) {
      const toggle = document.querySelector(
        `.schedule-day-working-toggle[data-day="${d}"]`,
      );
      const startInput = document.querySelector(
        `.schedule-start-time[data-day="${d}"]`,
      );
      const endInput = document.querySelector(
        `.schedule-end-time[data-day="${d}"]`,
      );

      const isWorking = toggle?.checked ? 1 : 0;

      days.push({
        day_of_week: d,
        start_time: isWorking ? startInput?.value || "09:00" : "09:00",
        end_time: isWorking ? endInput?.value || "17:00" : "17:00",
        is_working: isWorking,
      });
    }
    return days;
  }

  function collectSettingsData() {
    const durationEl = document.getElementById("schedule-duration");
    const maxApptsEl = document.getElementById("schedule-max-appts");
    const breakStartEl = document.getElementById("schedule-break-start");
    const breakEndEl = document.getElementById("schedule-break-end");
    const availToggle = document.getElementById("schedule-availability");

    const breakStart = breakStartEl?.value?.trim() || "";
    const breakEnd = breakEndEl?.value?.trim() || "";

    return {
      appointment_duration: parseInt(durationEl?.value || "30"),
      max_appointments_per_day: parseInt(maxApptsEl?.value || "25"),
      break_start: breakStart || null,
      break_end: breakEnd || null,
      is_available: availToggle?.checked ? 1 : 0,
    };
  }

  // ── Client-side Validation ─────────────────────────────────

  function validateWeekly(weekly) {
    if (!weekly || weekly.length === 0) {
      return "Weekly schedule data is required.";
    }

    const seenDays = [];
    let hasWorkingDay = false;

    for (const day of weekly) {
      const d = day.day_of_week;
      if (d < 1 || d > 7) {
        return "Invalid day of week.";
      }
      if (seenDays.includes(d)) {
        return DAY_NAMES[d] + ": Duplicate day entry.";
      }
      seenDays.push(d);

      if (day.is_working === 1) {
        hasWorkingDay = true;

        if (!day.start_time || !day.end_time) {
          return DAY_NAMES[d] + ": Start and end times are required.";
        }

        if (day.start_time >= day.end_time) {
          return DAY_NAMES[d] + ": Start time must be before end time.";
        }
      }
    }

    if (!hasWorkingDay) {
      return "At least one working day must be selected.";
    }

    return null;
  }

  function validateSettings(settings) {
    if (!settings) return null;

    if (settings.appointment_duration) {
      const dur = parseInt(settings.appointment_duration);
      if (!VALID_DURATIONS.includes(dur)) {
        return (
          "Appointment duration must be one of: " +
          VALID_DURATIONS.join(", ") +
          " minutes."
        );
      }
    }

    if (settings.max_appointments_per_day) {
      const max = parseInt(settings.max_appointments_per_day);
      if (max < 1 || max > 100) {
        return "Maximum appointments per day must be between 1 and 100.";
      }
    }

    if (settings.break_start && settings.break_end) {
      if (settings.break_start >= settings.break_end) {
        return "Break start time must be before break end time.";
      }
    } else if (settings.break_start || settings.break_end) {
      return "Both break start and end times are required when configuring a break.";
    }

    return null;
  }

  function validateBreakWithinHours(weekly, settings) {
    if (!settings.break_start || !settings.break_end) return null;

    for (const day of weekly) {
      if (day.is_working !== 1) continue;

      if (
        settings.break_start < day.start_time ||
        settings.break_end > day.end_time
      ) {
        const dayName = DAY_NAMES[day.day_of_week] || "Unknown";
        return `Break (${settings.break_start} - ${settings.break_end}) must be within working hours on ${dayName} (${day.start_time} - ${day.end_time}).`;
      }
    }

    return null;
  }

  // ── Local Helpers ──────────────────────────────────────────

  function formatTimeLocal(timeStr) {
    if (!timeStr) return "Closed";
    const parts = timeStr.split(":");
    if (parts.length < 2) return timeStr;
    const h = parseInt(parts[0]);
    const m = parseInt(parts[1]);
    const ampm = h >= 12 ? "PM" : "AM";
    const displayHour = h % 12 || 12;
    return `${displayHour}:${String(m).padStart(2, "0")} ${ampm}`;
  }

  function refresh() {
    loadSchedule();
  }

  // ── Public API ─────────────────────────────────────────────

  return {
    init,
    loadForDoctor,
    saveSchedule,
    resetSchedule,
    refresh,
    onDayToggle,
    onAvailabilityToggle,
    onSettingChange,
    toggleDayExpansion,
  };
})();

window.ScheduleManager = ScheduleManager;

