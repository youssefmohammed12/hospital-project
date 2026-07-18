/**
 * HealthBridge — Doctors Page JavaScript (Phase 6.1)
 * Handles: API fetch, dynamic department chip filters, search, sort,
 *          animated card rendering, results count, availability badges,
 *          working days display, next-available slot, doctor detail modal,
 *          and booking integration.
 *
 * Phase 6.1: Department chips are now loaded dynamically from the
 * centralized departments API. Filtering uses department_id instead
 * of hardcoded specialty strings. specialty is preserved for backward-
 * compatible display only.
 *
 * Uses shared helpers from main.js:
 *   - escapeHTML(), formatDate(), formatTime(), showToast(), getUser()
 *   - filterData(), apiFetch()
 *
 * Used by: doctors.html
 */

"use strict";

document.addEventListener("DOMContentLoaded", () => {
  initDoctorsPage();
});

function initDoctorsPage() {
  const grid = document.getElementById("doctors-list");
  const countEl = document.getElementById("results-count");
  if (!grid) return;

  let allDoctors = [...DOCTORS_FALLBACK];
  let availabilityCache = {}; // doctor_id (user_id) -> availability data
  let departmentMap = {};     // department_id -> department_name

  const searchInput = document.getElementById("search-input");
  const sortFilter = document.getElementById("sort-filter");
  const chipsContainer = document.getElementById("specialty-chips-container");

  let activeSpecialty = "all";

  // Wire up the static "All Doctors" chip click handler
  const allChip = chipsContainer?.querySelector('.chip[data-specialty="all"]');
  if (allChip) {
    allChip.addEventListener("click", () => {
      setActiveChip(allChip);
      activeSpecialty = "all";
      applyFilters();
    });
  }

  // Pre-fill search from URL ?q=
  const initialQuery = new URLSearchParams(window.location.search).get("q");
  if (searchInput && initialQuery) searchInput.value = initialQuery;

  // Sync chip active state from URL
  if (initialQuery) {
    // Will be handled after chips are loaded
  }

  // ── Load departments and chips dynamically ─────────────
  async function loadDepartmentChips() {
    try {
      const res = await fetch((getBasePath() + "api/departments/get.php"), {
        credentials: "same-origin",
      });
      const result = await res.json();
      if (result.success && Array.isArray(result.departments)) {
        const departments = result.departments;
        // Build department map
        departments.forEach((dep) => {
          departmentMap[dep.id] = dep.name;
        });
        // Render chips dynamically
        departments.forEach((dep) => {
          const chip = document.createElement("button");
          chip.className = "chip";
          chip.dataset.specialty = dep.name;
          chip.dataset.departmentId = dep.id;
          chip.innerHTML = `<span class="chip-icon"><i class="fas fa-stethoscope" aria-hidden="true"></i></span>${escapeHTML(dep.name)}`;
          chip.addEventListener("click", () => {
            setActiveChip(chip);
            activeSpecialty = chip.dataset.specialty;
            applyFilters();
          });
          chipsContainer.appendChild(chip);
        });
        // Re-sync initial URL query after chips are loaded
        if (initialQuery) {
          chipsContainer.querySelectorAll(".chip").forEach((c) => {
            if (c.dataset.specialty.toLowerCase() === initialQuery.toLowerCase()) {
              setActiveChip(c);
              activeSpecialty = c.dataset.specialty;
            }
          });
        }
      }
    } catch (e) {
      console.warn("Could not load departments. Using fallback specialties.", e);
    }
  }

  // ── Load doctors + availability in sequence ──────────────
  loadDepartmentChips()
    .then(() => {
      return fetch((getBasePath() + "api/doctors/get.php"));
    })
    .then((r) => r.json())
    .then((result) => {
      const data = result.doctors || result;
      if (Array.isArray(data) && data.length > 0) {
        allDoctors = data;
        // Update departmentMap from returned doctors data if not already populated
        data.forEach((d) => {
          if (d.department_id && d.department_name && !departmentMap[d.department_id]) {
            departmentMap[d.department_id] = d.department_name;
          }
        });
      }
      return loadBatchAvailability();
    })
    .then(() => {
      applyFilters();
    })
    .catch((err) => {
      console.warn("Doctors load failed, using fallback data:", err);
      applyFilters();
    });

  // Initial render (will be updated when API returns)
  applyFilters();

  // Event listeners
  searchInput?.addEventListener("input", debounce(applyFilters, 200));
  sortFilter?.addEventListener("change", applyFilters);

  /* ── Batch Availability Load ────────────────────────────── */

  async function loadBatchAvailability() {
    try {
      const res = await fetch((getBasePath() + "api/doctors/get-public-availability.php"), {
        credentials: "same-origin",
      });
      const result = await res.json();
      if (result.success && result.availability) {
        availabilityCache = result.availability;
      }
    } catch {
      // Silently fail — cards will show without availability data
    }
  }

  /* ── Helpers ───────────────────────────────────────────── */

  function setActiveChip(target) {
    chipsContainer.querySelectorAll(".chip").forEach((c) => c.classList.remove("active"));
    target.classList.add("active");
  }

  function getAvailability(doctor) {
    // doctor.user_id is the users.id used by the schedule system
    const uid = doctor.user_id || doctor.id;
    return availabilityCache[uid] || null;
  }

  function getBadgeInfo(doctor) {
    const avail = getAvailability(doctor);
    const isAvailable = doctor.available == 1;

    if (!isAvailable) {
      return {
        badgeClass: "avail-badge badge-unavailable",
        badgeText: "Not Accepting Appointments",
        badgeIcon: "fa-circle-exclamation",
        badgeLabel: "Not accepting appointments",
        canBook: false,
        nextText: "",
        nextLabel: "",
      };
    }

    if (!avail) {
      return {
        badgeClass: "avail-badge badge-available",
        badgeText: "Available",
        badgeIcon: "fa-circle-check",
        badgeLabel: "Available for appointments",
        canBook: true,
        nextText: "",
        nextLabel: "",
      };
    }

    if (!avail.accepting_patients) {
      return {
        badgeClass: "avail-badge badge-unavailable",
        badgeText: "Not Accepting Appointments",
        badgeIcon: "fa-circle-exclamation",
        badgeLabel: "Not accepting appointments",
        canBook: false,
        nextText: "",
        nextLabel: "",
      };
    }

    if (avail.today_available) {
      return {
        badgeClass: "avail-badge badge-available",
        badgeText: "Available Today",
        badgeIcon: "fa-circle-check",
        badgeLabel: "Available today",
        canBook: true,
        nextText: avail.next_available
          ? `Next: ${avail.next_available.display}`
          : "",
        nextLabel: avail.next_available
          ? `Next available: ${avail.next_available.display}`
          : "",
      };
    }

    if (avail.next_available) {
      const dayLabel = avail.next_available.day_label;
      let badgeClass = "avail-badge badge-tomorrow";
      let badgeText = "Available Tomorrow";
      let badgeIcon = "fa-calendar-check";

      if (dayLabel !== "Tomorrow") {
        badgeClass = "avail-badge badge-later";
        badgeText = `Next ${dayLabel}`;
        badgeIcon = "fa-calendar-day";
      }

      return {
        badgeClass,
        badgeText,
        badgeIcon,
        badgeLabel: `Next available: ${avail.next_available.display}`,
        canBook: true,
        nextText: `Next: ${avail.next_available.display}`,
        nextLabel: `Next available: ${avail.next_available.display}`,
      };
    }

    return {
      badgeClass: "avail-badge badge-full",
      badgeText: "Currently Full",
      badgeIcon: "fa-ban",
      badgeLabel: "No available appointments in the next 30 days",
      canBook: false,
      nextText: "",
      nextLabel: "",
    };
  }

  function getWorkingDaysText(doctor) {
    const avail = getAvailability(doctor);
    if (!avail || !avail.working_days || avail.working_days.length === 0) {
      return "";
    }

    const days = avail.working_days;
    if (days.length >= 7) {
      return "Every day";
    }
    return days.join(" • ");
  }

  function applyFilters() {
    const query = (searchInput?.value || "").toLowerCase().trim();
    const sort = sortFilter?.value || "default";

    // Get the display name for the active specialty chip
    let activeDepartmentName = activeSpecialty;

    let filtered = allDoctors.filter((d) => {
      // Use department_name if available, fall back to specialty for backward compatibility
      const deptName = d.department_name || d.specialty || "";

      const matchSearch =
        (d.name || "").toLowerCase().includes(query) ||
        deptName.toLowerCase().includes(query) ||
        (d.specialty || "").toLowerCase().includes(query);

      // Filter by department name (the chip data-specialty matches department name)
      const matchSpecialty =
        activeSpecialty === "all" || deptName === activeSpecialty;

      return matchSearch && matchSpecialty;
    });

    // Sort
    if (sort === "rating-high")
      filtered.sort((a, b) => (b.rating || 0) - (a.rating || 0));
    if (sort === "rating-low")
      filtered.sort((a, b) => (a.rating || 0) - (b.rating || 0));
    if (sort === "exp") filtered.sort((a, b) => (b.exp || 0) - (a.exp || 0));
    if (sort === "alpha")
      filtered.sort((a, b) => (a.name || "").localeCompare(b.name || ""));
    if (sort === "available") {
      filtered.sort((a, b) => {
        const aAvail = getAvailability(a);
        const bAvail = getAvailability(b);
        const aScore = aAvail?.today_available
          ? 3
          : aAvail?.next_available
            ? 2
            : a.available == 1
              ? 1
              : 0;
        const bScore = bAvail?.today_available
          ? 3
          : bAvail?.next_available
            ? 2
            : b.available == 1
              ? 1
              : 0;
        return bScore - aScore;
      });
    }

    // Update results count
    if (countEl) {
      countEl.textContent =
        filtered.length === 1
          ? "1 doctor found"
          : `${filtered.length} doctors found`;
    }

    // Render cards
    if (filtered.length === 0) {
      grid.innerHTML = `
        <div class="empty-state">
          <div class="empty-icon"><i class="fas fa-user-doctor" aria-hidden="true"></i></div>
          <h3>No Doctors Found</h3>
          <p>Try adjusting your search or filter criteria.</p>
        </div>`;
      return;
    }

    grid.innerHTML = filtered
      .map((d, i) => {
        const rating = parseFloat(d.rating) || 0;
        const filledStars = Math.round(rating);
        const stars =
          Array(filledStars)
            .fill('<i class="fas fa-star" aria-hidden="true"></i>')
            .join("") +
          Array(5 - filledStars)
            .fill('<i class="far fa-star" aria-hidden="true"></i>')
            .join("");
        const doctorIcon =
          '<i class="fas fa-user-doctor" aria-hidden="true"></i>';
        const badge = getBadgeInfo(d);
        const workingDays = getWorkingDaysText(d);

        // Use department_name if available, fall back to specialty
        const displaySpecialty = d.department_name || d.specialty || "General";

        return `
      <div class="doctor-card" style="animation-delay:${i * 0.06}s"
           tabindex="0" role="button"
           aria-label="${escapeHTML(d.name)} — ${escapeHTML(displaySpecialty)} — ${escapeHTML(badge.badgeLabel)}"
           onclick="openDoctorDetailModal(${d.user_id || d.id}, '${escapeHTML(d.name)}')"
           onkeydown="if(event.key==='Enter'||event.key===' ') { event.preventDefault(); openDoctorDetailModal(${d.user_id || d.id}, '${escapeHTML(d.name)}'); }">
        <div class="doctor-card-header">
          <div class="doctor-avatar">${doctorIcon}</div>
          <div class="doctor-card-meta">
            <h3>${escapeHTML(d.name)}</h3>
            <span class="specialty-badge">${escapeHTML(displaySpecialty)}</span>
            <div class="rating-row">
              <span class="stars">${stars}</span>
              <span class="rating-num">${rating.toFixed(1)}</span>
            </div>
          </div>
        </div>

        <div class="doctor-stats">
          <div class="doctor-stat">
            <span class="doctor-stat-value">${d.exp || 0}</span>
            <span class="doctor-stat-label">Yrs Experience</span>
          </div>
          <div class="doctor-stat">
            <span class="doctor-stat-value">${rating.toFixed(1)} <i class="fas fa-star" aria-hidden="true"></i></span>
            <span class="doctor-stat-label">Patient Rating</span>
          </div>
          <div class="doctor-stat">
            <span class="doctor-stat-value">100+</span>
            <span class="doctor-stat-label">Patients</span>
          </div>
        </div>

        <div class="avail-row">
          <span class="${badge.badgeClass}" aria-label="${escapeHTML(badge.badgeLabel)}">
            <i class="fas ${badge.badgeIcon}" aria-hidden="true"></i>
            ${escapeHTML(badge.badgeText)}
          </span>
          ${badge.nextText ? `<span class="next-slot" aria-label="${escapeHTML(badge.nextLabel)}">${escapeHTML(badge.nextText)}</span>` : ""}
        </div>

        ${workingDays ? `<div class="working-days-row" aria-label="Working days: ${escapeHTML(workingDays)}"><i class="fas fa-calendar-week" aria-hidden="true"></i> ${escapeHTML(workingDays)}</div>` : ""}

        <button class="btn ${d.available == 1 ? "btn-primary" : "btn-outline"} book-btn"
                onclick="event.stopPropagation(); openDoctorDetailModal(${d.user_id || d.id}, '${escapeHTML(d.name)}')">
          ${d.available == 1 ? "Book Appointment" : "View Profile"}
        </button>
      </div>`;
      })
      .join("");
  }

  // ── Doctor Detail Modal ──────────────────────────────────

  window.openDoctorDetailModal = function (doctorUserId, doctorName) {
    const user = getUser();
    const doctor = allDoctors.find((d) => (d.user_id || d.id) === doctorUserId);
    if (!doctor) {
      showToast("Doctor information not available.", "error");
      return;
    }

    const avail = getAvailability(doctor);
    const badge = getBadgeInfo(doctor);
    const workingDays = getWorkingDaysText(doctor);
    const rating = parseFloat(doctor.rating) || 0;
    const filledStars = Math.round(rating);
    const stars =
      Array(filledStars)
        .fill('<i class="fas fa-star" aria-hidden="true"></i>')
        .join("") +
      Array(5 - filledStars)
        .fill('<i class="far fa-star" aria-hidden="true"></i>')
        .join("");

    // Use department_name if available, fall back to specialty
    const displaySpecialty = doctor.department_name || doctor.specialty || "General";

    // Get appointment duration from live schedule data (single source of truth)
    const duration = avail?.appointment_duration || 30;

    // Build modal
    let modal = document.getElementById("doctor-detail-modal");
    if (!modal) {
      modal = document.createElement("div");
      modal.id = "doctor-detail-modal";
      modal.className = "modal-overlay";
      modal.setAttribute("role", "dialog");
      modal.setAttribute("aria-modal", "true");
      modal.setAttribute("aria-label", "Doctor Details");
      document.body.appendChild(modal);
    }

    const nextSlotHtml = avail?.next_available
      ? `<div class="dd-next-card">
          <div class="dd-next-label">NEXT AVAILABLE</div>
          <div class="dd-next-row"><i class="fas fa-calendar-alt" aria-hidden="true"></i> ${escapeHTML(avail.next_available.day_label === "Today" || avail.next_available.day_label === "Tomorrow" ? avail.next_available.day_label : avail.next_available.display.split(" • ")[0])}</div>
          <div class="dd-next-row"><i class="fas fa-clock" aria-hidden="true"></i> ${escapeHTML(avail.next_available.display.includes("•") ? avail.next_available.display.split(" • ")[1] || avail.next_available.display : avail.next_available.display)}</div>
        </div>`
      : "";

    // Determine book button behavior based on user role and doctor's ability to accept
    const userRole = user?.role || "";
    let footerHtml = "";

    if (badge.canBook) {
      if (userRole === "patient") {
        footerHtml = `
          <div class="dd-footer">
            <button class="btn btn-primary btn-block dd-book-btn" onclick="bookFromDoctorModal(${doctorUserId}, '${escapeHTML(doctor.name)}', '${escapeHTML(displaySpecialty)}')">
              <i class="fas fa-calendar-days" aria-hidden="true"></i> Book Appointment
            </button>
          </div>`;
      } else if (!user || userRole === "guest") {
        footerHtml = `
          <div class="dd-footer">
            <button class="btn btn-primary btn-block dd-book-btn" onclick="bookFromDoctorModal(${doctorUserId}, '${escapeHTML(doctor.name)}', '${escapeHTML(displaySpecialty)}')">
              <i class="fas fa-calendar-days" aria-hidden="true"></i> Login to Book
            </button>
          </div>`;
      }
    } else if (userRole === "patient") {
      footerHtml = `
        <div class="dd-footer">
          <div class="dd-unavailable-msg">
            <i class="fas fa-info-circle" aria-hidden="true"></i>
            <p>Appointments are currently unavailable for this doctor.</p>
          </div>
        </div>`;
    }

    modal.innerHTML = `
      <div class="modal doctor-detail-modal">
        <div class="dd-header">
          <div class="dd-avatar"><i class="fas fa-user-doctor" aria-hidden="true"></i></div>
          <div class="dd-header-info">
            <h2>${escapeHTML(doctor.name)}</h2>
            <span class="specialty-badge">${escapeHTML(displaySpecialty)}</span>
            <div class="rating-row" style="margin-top:var(--s1)">
              <span class="stars">${stars}</span>
              <span class="rating-num">${rating.toFixed(1)}</span>
            </div>
          </div>
          <button class="btn btn-outline btn-sm dd-close" onclick="closeDoctorDetailModal()" aria-label="Close">&times;</button>
        </div>

        <div class="dd-scroll-content">
          <div class="dd-badge-row">
            <span class="${badge.badgeClass}" aria-label="${escapeHTML(badge.badgeLabel)}">
              <i class="fas ${badge.badgeIcon}" aria-hidden="true"></i>
              ${escapeHTML(badge.badgeText)}
            </span>
          </div>

          ${nextSlotHtml}

          <div class="dd-info-cards-grid">
            <div class="dd-info-card">
              <div class="dd-info-card-title">Experience</div>
              <div class="dd-info-card-value">${doctor.exp || 0} years</div>
            </div>
            <div class="dd-info-card">
              <div class="dd-info-card-title">Education</div>
              <div class="dd-info-card-value">Medical Degree<br/>Board Certified</div>
            </div>
            <div class="dd-info-card">
              <div class="dd-info-card-title">Languages</div>
              <div class="dd-info-card-value">English<br/>Arabic</div>
            </div>
            ${
              workingDays
                ? `<div class="dd-info-card">
              <div class="dd-info-card-title">Working Days</div>
              <div class="dd-info-card-value">${escapeHTML(workingDays)}</div>
            </div>`
                : ""
            }
            ${
              avail
                ? `<div class="dd-info-card">
              <div class="dd-info-card-title">Appointment Duration</div>
              <div class="dd-info-card-value">${duration} minutes</div>
            </div>`
                : ""
            }
          </div>

          <div class="dd-info-card dd-info-card-full">
            <div class="dd-info-card-title">Specializations</div>
            <div class="dd-info-card-value">${escapeHTML(displaySpecialty)} — specialized diagnostic and treatment services with ${doctor.exp || 0} years of clinical experience.</div>
          </div>

          <div class="dd-info-card dd-info-card-full">
            <div class="dd-info-card-title">Patient Reviews</div>
            <div class="dd-info-card-value">${rating.toFixed(1)} out of 5 stars based on patient ratings.</div>
          </div>
        </div>

        ${footerHtml}
      </div>
    `;

    modal.classList.add("open");
    document.body.classList.add("modal-open");

    // Focus trap: focus the close button
    const closeBtn = modal.querySelector(".dd-close");
    if (closeBtn) setTimeout(() => closeBtn.focus(), 100);

    // Close on Escape
    const escHandler = (e) => {
      if (e.key === "Escape") {
        closeDoctorDetailModal();
        document.removeEventListener("keydown", escHandler);
      }
    };
    document.addEventListener("keydown", escHandler);
  };

  window.closeDoctorDetailModal = function () {
    const modal = document.getElementById("doctor-detail-modal");
    if (modal) {
      modal.classList.remove("open");
      document.body.classList.remove("modal-open");
    }
  };

  window.bookFromDoctorModal = function (doctorUserId, doctorName, specialty) {
    closeDoctorDetailModal();

    const user = getUser();

    if (!user) {
      // Guest — redirect to login, return to doctors page
      window.location.href = `${getBasePath()}pages/auth/login.html?redirect=doctors.html?q=${encodeURIComponent(specialty)}`;
      return;
    }

    if (user.role !== "patient") {
      // Doctor or admin — block booking from public page
      showToast("Please use your dashboard to manage appointments.", "info");
      return;
    }

    // Patient — redirect to dashboard booking tab with doctor/department pre-selected
    window.location.href = `${getBasePath()}pages/patient/dashboard.html#book?doctor=${encodeURIComponent(doctorName)}&department=${encodeURIComponent(specialty)}`;
  };
}

