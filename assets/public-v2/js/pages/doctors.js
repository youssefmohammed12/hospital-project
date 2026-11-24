/**
 * HealthBridge — Doctors Directory controller.
 * Owns directory API reads/normalization, department chips, search/sort,
 * availability presentation, semantic doctor cards, profile dialog and
 * the existing role-aware booking navigation.
 *
 * Department identity is based on validated department_id. department_name
 * is the preferred display label; legacy specialty remains a display fallback.
 *
 * Loads after the standalone Public V2 runtime at ../core/site.js.
 * No legacy dashboard/shared stylesheet or JavaScript is required.
 *
 * Used by: doctors.html
 */

"use strict";

const SAMPLE_DIRECTORY_RATINGS = [
  4.8, 4.3, 4.6, 4.2, 4.9, 4.1, 4.3, 4.8, 5.0, 4.6,
  4.1, 4.8, 4.2, 4.9, 3.8, 4.1, 4.3, 4.6, 4.8, 4.9,
  5.0, 4.2, 3.8, 4.3, 4.6, 4.9, 4.1, 4.2, 5.0, 3.8,
];

const SAMPLE_DIRECTORY_WORKING_DAYS = [
  ["Mon", "Tue", "Wed", "Thu", "Fri"],
  ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat"],
  ["Sun", "Mon", "Tue", "Wed", "Thu"],
  ["Sat", "Sun", "Mon", "Tue", "Wed"],
  ["Mon", "Wed", "Fri"],
  ["Tue", "Thu", "Sat"],
  ["Sun", "Tue", "Thu", "Sat"],
  ["Mon", "Tue", "Thu", "Fri", "Sat"],
];

const SAMPLE_DIRECTORY_SLOT_PRESETS = [
  { daysAhead: 1, time: "9:30 AM" },
  { daysAhead: 2, time: "10:00 AM" },
  { daysAhead: 3, time: "11:15 AM" },
  { daysAhead: 4, time: "12:30 PM" },
  { daysAhead: 5, time: "1:00 PM" },
  { daysAhead: 6, time: "2:45 PM" },
  { daysAhead: 7, time: "4:00 PM" },
  { daysAhead: 2, time: "9:00 AM" },
  { daysAhead: 4, time: "10:45 AM" },
];

const DOCTORS_PUBLIC_REVEAL_TARGETS = [
  ".doctors-highlights__grid > *",
  ".doctors-section-heading",
  ".directory-panel",
  ".directory-results-heading",
  ".directory-disclaimer",
  ".doctors-process-card",
  ".doctors-cta__inner",
].join(", ");

document.querySelectorAll(DOCTORS_PUBLIC_REVEAL_TARGETS).forEach((element) => {
  element.dataset.publicReveal = "rise";
});

document.addEventListener("DOMContentLoaded", () => {
  initDoctorsPage();
});

function initDoctorsPage() {
  const grid = document.getElementById("doctors-list");
  const countEl = document.getElementById("results-count");
  if (!grid) return;

  let allDoctors = [...DOCTORS_FALLBACK];
  let availabilityCache = Object.create(null); // doctor_id (user_id) -> normalized availability data
  let departmentMap = Object.create(null);     // department_id -> department_name
  let lastProfileTrigger = null;
  let dialogBackgroundStates = [];

  const searchInput = document.getElementById("search-input");
  const sortFilter = document.getElementById("sort-filter");
  const chipsContainer = document.getElementById("specialty-chips-container");
  let activeSpecialty = "all";

  function initDoctorsMotion() {
    if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) return;
    const hero = document.querySelector(".doctors-hero");
    if (hero && window.scrollY < 32) hero.classList.add("doctors-hero--enter");
  }

  initDoctorsMotion();

  // Wire up the static "All Doctors" chip click handler
  const allChip = chipsContainer?.querySelector('.chip[data-specialty="all"]');
  if (allChip) {
    allChip.addEventListener("click", () => {
      setActiveChip(allChip);
      activeSpecialty = "all";
      applyFilters();
    });
  }

  // Hero quick-jump pills: scroll to the directory, then activate the
  // matching department chip through its own click handler, so chip
  // state, filtering and result counts stay owned by one mechanism.
  document.querySelectorAll(".doctors-hero__jump-pill").forEach((pill) => {
    pill.addEventListener("click", () => {
      const target = pill.dataset.jumpSpecialty;
      const directory = document.getElementById("doctor-directory");
      if (directory) {
        const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
        directory.scrollIntoView({ behavior: reduceMotion ? "auto" : "smooth", block: "start" });
      }
      // Department chips render after the departments fetch resolves;
      // wait briefly for the target chip, then click it.
      let attempts = 0;
      const activateChip = () => {
        const chip = chipsContainer?.querySelector('.chip[data-specialty="' + target + '"]');
        if (chip) {
          chip.click();
          return;
        }
        attempts += 1;
        if (attempts < 30) window.setTimeout(activateChip, 100);
      };
      activateChip();
    });
  });

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
        signal: AbortSignal.timeout(12000),
      });
      const result = await res.json();
      if (result.success && Array.isArray(result.departments)) {
        const departments = result.departments
          .filter((department) =>
            department &&
            typeof department === "object" &&
            !Array.isArray(department) &&
            safePositiveId(department.id) > 0 &&
            typeof department.name === "string" &&
            department.name.trim() !== ""
          )
          .map((department) => ({
            id: safePositiveId(department.id),
            name: department.name.trim().slice(0, 100),
          }));

        // Build a prototype-safe map using validated numeric department IDs.
        departments.forEach((dep) => {
          departmentMap[String(dep.id)] = dep.name;
        });

        // Render chips dynamically when the expected page container exists.
        departments.forEach((dep) => {
          if (!chipsContainer) return;
          const chip = document.createElement("button");
          chip.className = "chip";
          chip.dataset.specialty = dep.name;
          chip.dataset.departmentId = String(dep.id);
          chip.type = "button";
          chip.setAttribute("aria-pressed", "false");
          chip.innerHTML = '<span class="chip-icon" aria-hidden="true">' + departmentIconSvg(dep.name) + '</span>' + escapeHTML(dep.name);
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
      return fetch((getBasePath() + "api/doctors/get.php"), {
        signal: AbortSignal.timeout(12000),
      });
    })
    .then((r) => r.json())
    .then((result) => {
      const data = Array.isArray(result?.doctors)
        ? result.doctors
        : Array.isArray(result)
          ? result
          : [];
      const normalizedDoctors = data
        .filter((doctor) =>
          doctor &&
          typeof doctor === "object" &&
          !Array.isArray(doctor) &&
          safePositiveId(doctor.user_id || doctor.id) > 0 &&
          typeof doctor.name === "string" &&
          doctor.name.trim() !== ""
        )
        .map((doctor) => {
          const doctorId = safePositiveId(doctor.user_id || doctor.id);
          const specialty = typeof doctor.specialty === "string"
            ? doctor.specialty.trim().slice(0, 100)
            : "";
          const departmentName = typeof doctor.department_name === "string" &&
              doctor.department_name.trim() !== ""
            ? doctor.department_name.trim().slice(0, 100)
            : departmentMap[String(safePositiveId(doctor.department_id))] || specialty;
          const rawRating = doctor.rating;
          const ratingText = typeof rawRating === "string" ? rawRating.trim() : "";
          const hasScalarRating = typeof rawRating === "number" ||
            (typeof rawRating === "string" &&
              ratingText.length > 0 &&
              ratingText.length <= 12 &&
              /^\d+(?:\.\d+)?$/.test(ratingText));
          const ratingValue = typeof rawRating === "number"
            ? rawRating
            : hasScalarRating
              ? Number(ratingText)
              : 0;
          const hasValidRating = hasScalarRating &&
            Number.isFinite(ratingValue) &&
            ratingValue >= 0 &&
            ratingValue <= 5;
          return {
            id: safePositiveId(doctor.id) || doctorId,
            user_id: doctorId,
            name: doctor.name.trim().slice(0, 120),
            specialty,
            department_name: departmentName,
            department_id: safePositiveId(doctor.department_id),
            available: doctor.available === true || doctor.available === 1 || doctor.available === "1" ? 1 : 0,
            rating: hasValidRating ? ratingValue : 0,
            rating_count: hasValidRating ? safeCount(doctor.rating_count, 0) : 0,
            exp: safeCount(doctor.exp, 0),
          };
        });

      if (normalizedDoctors.length > 0) {
        allDoctors = normalizedDoctors;
        // Update departmentMap only from validated numeric IDs and string labels.
        normalizedDoctors.forEach((doctor) => {
          const departmentId = safePositiveId(doctor.department_id);
          const departmentName = doctor.department_name;
          if (departmentId && departmentName && !departmentMap[String(departmentId)]) {
            departmentMap[String(departmentId)] = departmentName;
          }
        });
      }
      return loadBatchAvailability();
    })
    .then(() => {
      applyDirectorySampleVariants();
      applyFilters();
    })
    .catch((err) => {
      console.warn("Doctors load failed, using fallback data:", err);
      applyDirectorySampleVariants();
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
      if (
        result.success &&
        result.availability &&
        typeof result.availability === "object" &&
        !Array.isArray(result.availability)
      ) {
        const normalizedAvailability = Object.create(null);
        Object.entries(result.availability).forEach(([rawDoctorId, value]) => {
          const doctorId = safePositiveId(rawDoctorId);
          if (
            !doctorId ||
            !value ||
            typeof value !== "object" ||
            Array.isArray(value)
          ) {
            return;
          }

          const next = value.next_available &&
              typeof value.next_available === "object" &&
              !Array.isArray(value.next_available) &&
              typeof value.next_available.display === "string" &&
              value.next_available.display.trim() !== ""
            ? {
                day_label: typeof value.next_available.day_label === "string"
                  ? value.next_available.day_label.trim().slice(0, 30)
                  : "Later",
                display: value.next_available.display.trim().slice(0, 80),
              }
            : null;

          const workingDays = Array.isArray(value.working_days)
            ? value.working_days
                .filter((day) => typeof day === "string" && day.trim() !== "")
                .map((day) => day.trim().slice(0, 12))
                .slice(0, 7)
            : [];

          normalizedAvailability[String(doctorId)] = {
            accepting_patients: value.accepting_patients === true ||
              value.accepting_patients === 1 || value.accepting_patients === "1",
            today_available: value.today_available === true ||
              value.today_available === 1 || value.today_available === "1",
            next_available: next,
            working_days: workingDays,
            appointment_duration: safeCount(value.appointment_duration, 30),
          };
        });
        availabilityCache = normalizedAvailability;
      }
    } catch {
      // Silently fail — cards will show without availability data
    }
  }

  /**
   * Adds believable display variation to the public synthetic directory only.
   * Booking eligibility and API responses are not changed; the normalized
   * client-side summary is shared by the card and profile dialog.
   */
  function applyDirectorySampleVariants() {
    const ratedDoctors = allDoctors
      .filter((doctor) => getSafeRatingCount(doctor) > 0)
      .sort((a, b) => safePositiveId(a.user_id || a.id) - safePositiveId(b.user_id || b.id));

    ratedDoctors.forEach((doctor, index) => {
      doctor.rating = SAMPLE_DIRECTORY_RATINGS[index % SAMPLE_DIRECTORY_RATINGS.length];
    });

    const availabilityIds = Object.keys(availabilityCache)
      .map((id) => safePositiveId(id))
      .filter(Boolean)
      .sort((a, b) => a - b);

    availabilityIds.forEach((doctorId, index) => {
      const availability = availabilityCache[String(doctorId)];
      if (!availability) return;
      availability.working_days =
        SAMPLE_DIRECTORY_WORKING_DAYS[index % SAMPLE_DIRECTORY_WORKING_DAYS.length].slice();
    });

    const scheduledDoctorIds = availabilityIds.filter((doctorId) =>
      Boolean(availabilityCache[String(doctorId)]?.next_available)
    );

    scheduledDoctorIds.forEach((doctorId, index) => {
      const availability = availabilityCache[String(doctorId)];
      const preset = SAMPLE_DIRECTORY_SLOT_PRESETS[index % SAMPLE_DIRECTORY_SLOT_PRESETS.length];
      const date = new Date();
      date.setHours(12, 0, 0, 0);
      date.setDate(date.getDate() + preset.daysAhead);

      const weekday = new Intl.DateTimeFormat("en-US", { weekday: "short" }).format(date);
      const dateLabel = preset.daysAhead === 1
        ? "Tomorrow"
        : new Intl.DateTimeFormat("en-US", {
            weekday: "short",
            month: "short",
            day: "numeric",
          }).format(date);

      availability.next_available = {
        ...availability.next_available,
        day_label: preset.daysAhead === 1 ? "Tomorrow" : weekday,
        display: dateLabel + " • " + preset.time,
      };
    });
  }

  /* ── Helpers ───────────────────────────────────────────── */

  function setActiveChip(target) {
    chipsContainer.querySelectorAll('.chip').forEach((chip) => {
      chip.classList.remove('active');
      chip.setAttribute('aria-pressed', 'false');
    });
    target.classList.add('active');
    target.setAttribute('aria-pressed', 'true');
  }

  function setDialogBackgroundInert(shouldInert) {
    if (shouldInert) {
      // Do not overwrite the original states if the dialog is opened again
      // while it is already active.
      if (dialogBackgroundStates.length > 0) return;

      dialogBackgroundStates = Array.from(document.body.children)
        .filter((element) => element.id !== "doctor-detail-modal")
        .map((element) => ({
          element,
          wasInert: element.inert,
          ariaHidden: element.getAttribute("aria-hidden"),
        }));

      dialogBackgroundStates.forEach(({ element }) => {
        element.inert = true;
        element.setAttribute("aria-hidden", "true");
      });
      return;
    }

    const statesToRestore = dialogBackgroundStates;
    dialogBackgroundStates = [];

    statesToRestore.forEach(({ element, wasInert, ariaHidden }) => {
      if (!element.isConnected) return;
      element.inert = wasInert;
      if (ariaHidden === null) element.removeAttribute("aria-hidden");
      else element.setAttribute("aria-hidden", ariaHidden);
    });
  }

  function iconSvg(name) {
    const paths = {
      stethoscope: '<path d="M6 4v5a4 4 0 0 0 8 0V4M4 4h4M12 4h4M10 13v2a4 4 0 0 0 8 0v-1"/><circle cx="18" cy="12" r="1.5"/>',
      calendar: '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/>',
      clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
      info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>'
    };
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (paths[name] || paths.info) + '</svg>';
  }

  // Department icons for the filter chips — the SAME path set the booking
  // wizard uses for its department cards (DEPARTMENT_ICON_PATHS in
  // patient-appointments.js; keep the two copies in sync). Keyed by
  // normalized department name; unmapped departments get the neutral
  // medical-cross fallback.
  const DEPARTMENT_ICON_PATHS = {
    cardiology: '<path d="M12 20.2C6.9 16.5 3.6 13.2 3.6 9.7 3.6 7.1 5.6 5.1 8.1 5.1c1.6 0 3.1.9 3.9 2.2.8-1.3 2.3-2.2 3.9-2.2 2.5 0 4.5 2 4.5 4.6 0 3.5-3.3 6.8-8.4 10.5Z"/><path d="M6.3 12h2.9l1.4-2.4 2.1 4 1.4-1.6h3.6"/>',
    dentistry: '<path d="M12 6c-1.4-1.2-3.2-1.8-5-1.4C4.8 4.9 3.7 6.7 4 9c.2 2.2 1.3 3.3 1.8 5.1.4 1.6.6 4.2 1.2 5.7.3.8 1.5.8 2-.1.6-1.3.9-3.3 3-3.3s2.4 2 3 3.3c.5.9 1.7.9 2 .1.6-1.5.8-4.1 1.2-5.7.5-1.8 1.6-2.9 1.8-5.1.3-2.3-.8-4.1-3-4.4-1.8-.4-3.6.2-5 1.4Z"/>',
    dermatology: '<path d="M12 3.4 20.3 7.7 12 11.6 3.7 7.7 12 3.4Z"/><path d="m4.2 12.4 7.8 4.3 7.8-4.3"/><path d="m4.2 15.9 7.8 4.3 7.8-4.3"/>',
    "general practice": '<path d="M6 4v5a4 4 0 0 0 8 0V4M4 4h4M12 4h4M10 13v2a4 4 0 0 0 8 0v-1"/><circle cx="18" cy="12" r="1.5"/>',
    gynecology: '<circle cx="12" cy="8.3" r="4.9"/><path d="M12 13.2v7.3M8.7 17.4h6.6"/>',
    neurology: '<path d="M12 5.7C10.5 4.5 8.6 4.6 7.5 5.7 6.1 6.3 5.3 7.7 5.7 9.3 4.6 10.3 4.3 12 5.1 13.4 4.9 15.1 6 16.7 7.6 17.1 8.3 18.7 10 19.4 12 18.9Z"/><path d="M12 5.7c1.5-1.2 3.4-1.1 4.5 0 1.4.6 2.2 2 1.8 3.6 1.1 1 1.4 2.7.6 4.1.2 1.7-.9 3.3-2.5 3.7-.7 1.6-2.4 2.3-4.4 1.8V5.7Z"/>',
    ophthalmology: '<path d="M2.8 12c2-3.9 5.3-6.3 9.2-6.3S19 8.1 21.2 12c-2 3.9-5.3 6.3-9.2 6.3S4.8 15.9 2.8 12Z"/><circle cx="12" cy="12" r="3.1"/>',
    orthopedics: '<path d="M10.35 15.91 15.91 10.35A2.1 2.1 0 1 1 16.27 7.87L16.13 7.73A2.1 2.1 0 1 1 13.65 8.09L8.09 13.65A2.1 2.1 0 1 1 7.87 16.27L7.73 16.13A2.1 2.1 0 1 1 10.35 15.91Z"/>',
    pediatrics: '<circle cx="12" cy="13" r="6.4"/><path d="M12 6.6c-.2-1.7.9-2.9 2.5-3"/><path d="M9.4 12.6h.01M14.6 12.6h.01"/><path d="M9.7 15.6a3.2 3.2 0 0 0 4.6 0"/>',
  };
  const DEPARTMENT_ICON_FALLBACK = '<circle cx="12" cy="12" r="8.6"/><path d="M12 8.8v6.4M8.8 12h6.4"/>';

  function departmentIconSvg(name) {
    const key = String(name || '').trim().toLowerCase();
    const inner = DEPARTMENT_ICON_PATHS[key] || DEPARTMENT_ICON_FALLBACK;
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + inner + '</svg>';
  }

  function getDoctorInitials(name) {
    const parts = String(name || '').trim().split(/\s+/).filter(Boolean);
    if (!parts.length) return 'HB';
    return parts.slice(0, 2).map((part) => Array.from(part)[0] || '').join('').toUpperCase();
  }

    function getAvailability(doctor) {
    // doctor.user_id is the users.id used by the schedule system.
    const uid = safePositiveId(doctor?.user_id || doctor?.id);
    return uid ? availabilityCache[String(uid)] || null : null;
  }

  function getSafeRatingCount(doctor) {
    return safeCount(doctor.rating_count, 0);
  }

  function getSafeRating(doctor) {
    if (getSafeRatingCount(doctor) === 0) return 0;
    const value = doctor?.rating;
    return typeof value === "number" && Number.isFinite(value) && value >= 0 && value <= 5
      ? value
      : 0;
  }

  function getSafeExperience(doctor) {
    return safeCount(doctor.exp, 0);
  }

  function getAppointmentDuration(doctor) {
    const availability = getAvailability(doctor);
    return Math.min(240, Math.max(1, safeCount(availability?.appointment_duration, 30)));
  }

  function getSpecialtyTone(specialty) {
    const department = String(specialty || "").trim().toLowerCase();
    const tones = {
      cardiology: "rose",
      dentistry: "sage",
      dermatology: "sand",
      "general practice": "mint",
      gynecology: "rose",
      neurology: "blue",
      ophthalmology: "plum",
      orthopedics: "sand",
      pediatrics: "mint",
    };
    return tones[department] || "sage";
  }

  function createStarMarkup(rating) {
    const nearestHalf = Math.round(rating * 2) / 2;
    const wholeStars = Math.floor(nearestHalf);
    const hasHalfStar = nearestHalf - wholeStars >= 0.5;
    const emptyStars = 5 - wholeStars - (hasHalfStar ? 1 : 0);

    return '<span class="star-icon star-icon--full" aria-hidden="true">★</span>'.repeat(wholeStars) +
      (hasHalfStar ? '<span class="star-icon star-icon--half" aria-hidden="true">★</span>' : "") +
      '<span class="star-icon star-icon--empty" aria-hidden="true">★</span>'.repeat(emptyStars);
  }

  function getRatingMarkup(doctor) {
    const ratingCount = getSafeRatingCount(doctor);
    if (ratingCount === 0) return '<span class="rating-num">No ratings yet</span>';

    const rating = getSafeRating(doctor);
    return '<span class="stars" role="img" aria-label="' + rating.toFixed(1) + ' out of 5 stars">' +
      createStarMarkup(rating) + '</span><span class="rating-num">' +
      rating.toFixed(1) + ' · ' + ratingCount + ' rating' + (ratingCount === 1 ? '' : 's') +
      '</span>';
  }

  function getBadgeInfo(doctor) {
    const avail = getAvailability(doctor);
    const isAvailable = doctor.available == 1;
    if (!isAvailable || (avail && !avail.accepting_patients)) {
      return { badgeClass: 'avail-badge badge-unavailable', badgeText: 'Not accepting appointments', badgeLabel: 'Not accepting appointments', canBook: false, nextText: '', nextLabel: '' };
    }
    if (!avail) {
      return { badgeClass: 'avail-badge badge-available', badgeText: 'Available', badgeLabel: 'Available for appointments', canBook: true, nextText: '', nextLabel: '' };
    }
    if (avail.today_available) {
      return { badgeClass: 'avail-badge badge-available', badgeText: 'Available today', badgeLabel: 'Available today', canBook: true, nextText: avail.next_available ? 'Next: ' + avail.next_available.display : '', nextLabel: avail.next_available ? 'Next available: ' + avail.next_available.display : '' };
    }
    if (avail.next_available) {
      const isTomorrow = avail.next_available.day_label === 'Tomorrow';
      return { badgeClass: isTomorrow ? 'avail-badge badge-tomorrow' : 'avail-badge badge-later', badgeText: isTomorrow ? 'Available tomorrow' : 'Next ' + avail.next_available.day_label, badgeLabel: 'Next available: ' + avail.next_available.display, canBook: true, nextText: 'Next: ' + avail.next_available.display, nextLabel: 'Next available: ' + avail.next_available.display };
    }
    return { badgeClass: 'avail-badge badge-full', badgeText: 'Currently full', badgeLabel: 'No available appointments in the next 30 days', canBook: false, nextText: '', nextLabel: '' };
  }

    function getWorkingDaysText(doctor) {
    const avail = getAvailability(doctor);
    if (!avail || !Array.isArray(avail.working_days) || avail.working_days.length === 0) {
      return "";
    }

    const days = avail.working_days;
    if (days.length >= 7) {
      return "Every day";
    }
    return days.join(" • ");
  }

  function applyFilters() {
    const query = (searchInput?.value || '').toLowerCase().trim();
    const sort = sortFilter?.value || 'default';
    const filtered = allDoctors.filter((doctor) => {
      const departmentName = doctor.department_name || doctor.specialty || '';
      const matchesSearch = (doctor.name || '').toLowerCase().includes(query) || departmentName.toLowerCase().includes(query) || (doctor.specialty || '').toLowerCase().includes(query);
      const matchesDepartment = activeSpecialty === 'all' || departmentName === activeSpecialty;
      return matchesSearch && matchesDepartment;
    });
    if (sort === 'rating-high') filtered.sort((a, b) => getSafeRating(b) - getSafeRating(a));
    if (sort === 'rating-low') filtered.sort((a, b) => getSafeRating(a) - getSafeRating(b));
    if (sort === 'exp') filtered.sort((a, b) => getSafeExperience(b) - getSafeExperience(a));
    if (sort === 'alpha') filtered.sort((a, b) => (a.name || '').localeCompare(b.name || ''));
    if (sort === 'available') {
      filtered.sort((a, b) => {
        const aAvail = getAvailability(a);
        const bAvail = getAvailability(b);
        const aScore = aAvail?.today_available ? 3 : aAvail?.next_available ? 2 : a.available == 1 ? 1 : 0;
        const bScore = bAvail?.today_available ? 3 : bAvail?.next_available ? 2 : b.available == 1 ? 1 : 0;
        return bScore - aScore;
      });
    }
    if (countEl) countEl.textContent = filtered.length === 1 ? '1 sample profile found' : filtered.length + ' sample profiles found';
    grid.setAttribute('aria-busy', 'false');
    // Freeze round (Item 5): the first render replaces the skeleton cards —
    // fade the real directory in once.
    if (!grid.dataset.faded) {
      grid.dataset.faded = '1';
      grid.classList.add('hb-fade-in');
    }
    if (filtered.length === 0) {
      grid.innerHTML = '<div class="empty-state"><div class="empty-icon" aria-hidden="true">⌕</div><h3>No matching profiles</h3><p>Try a different name or department, or return to all departments to explore the full sample directory.</p></div>';
      return;
    }
    grid.innerHTML = filtered.map((doctor) => {
      const experience = getSafeExperience(doctor);
      const badge = getBadgeInfo(doctor);
      const workingDays = getWorkingDaysText(doctor);
      const displaySpecialty = doctor.department_name || doctor.specialty || 'General';
      const doctorId = safePositiveId(doctor.user_id || doctor.id);
      if (!doctorId) return '';
      const ratingsHtml = getRatingMarkup(doctor);
      const duration = getAppointmentDuration(doctor);
      const specialtyTone = getSpecialtyTone(displaySpecialty);
      const availabilityText = badge.nextText || (badge.canBook
        ? 'Next: Schedule not listed'
        : 'Not currently accepting appointments');
      const availabilityHtml = '<span class="next-slot" aria-label="' + escapeHTML(availabilityText) + '">' +
        escapeHTML(availabilityText) + '</span>';
      const workingDaysHtml = workingDays
        ? '<div class="working-days-row" aria-label="Working days: ' + escapeHTML(workingDays) + '">' +
          iconSvg('calendar') + '<span>' + escapeHTML(workingDays) + '</span></div>'
        : '';
      return '<article class="doctor-card" data-public-reveal="rise" data-doctor-id="' + doctorId + '">' +
        '<div class="doctor-card-header"><div class="doctor-avatar" data-specialty-tone="' + specialtyTone + '" aria-hidden="true">' +
        escapeHTML(getDoctorInitials(doctor.name)) + '</div>' +
        '<div class="doctor-card-meta"><h3>' + escapeHTML(doctor.name) + '</h3><span class="specialty-badge">' +
        escapeHTML(displaySpecialty) + '</span><div class="rating-row">' + ratingsHtml + '</div></div></div>' +
        '<div class="doctor-stats"><div class="doctor-stat"><span class="doctor-stat-value">' + experience +
        '</span><span class="doctor-stat-label">Years experience</span></div>' +
        '<div class="doctor-stat"><span class="doctor-stat-value">' + duration +
        ' min</span><span class="doctor-stat-label">Appointment duration</span></div></div>' +
        '<div class="avail-row">' + availabilityHtml + '</div>' + workingDaysHtml +
        '<button class="btn btn-outline book-btn" type="button" aria-label="View profile for ' +
        escapeHTML(doctor.name) + '" data-doctor-directory-action="open-profile" data-doctor-id="' +
        doctorId + '">View profile</button></article>';
    }).join('');
  }

    // ── Doctor Detail Modal ──────────────────────────────────

  window.openDoctorDetailModal = function (doctorUserId, doctorName) {
    doctorUserId = safePositiveId(doctorUserId);
    if (!doctorUserId) { showToast('Doctor information not available.', 'error'); return; }
    const user = getUser();
    const doctor = allDoctors.find((item) => safePositiveId(item.user_id || item.id) === doctorUserId);
    if (!doctor) { showToast('Doctor information not available.', 'error'); return; }
    const availability = getAvailability(doctor);
    const badge = getBadgeInfo(doctor);
    const workingDays = getWorkingDaysText(doctor);
    const ratingCount = getSafeRatingCount(doctor);
    const hasPatientRatings = ratingCount > 0;
    const rating = getSafeRating(doctor);
    const experience = getSafeExperience(doctor);
    const displaySpecialty = doctor.department_name || doctor.specialty || 'General';
    const duration = getAppointmentDuration(doctor);
    let modal = document.getElementById('doctor-detail-modal');
    if (!modal) {
      modal = document.createElement('div');
      modal.id = 'doctor-detail-modal';
      modal.className = 'modal-overlay';
      modal.setAttribute("role", "dialog");
      modal.setAttribute("aria-modal", "true");
      modal.addEventListener("click", (event) => {
        // Clicking the backdrop dismisses the dialog; clicks inside the
        // profile panel continue to behave normally.
        if (event.target === modal) window.closeDoctorDetailModal();
      });
      document.body.appendChild(modal);
    }
    modal.setAttribute('aria-labelledby', 'doctor-detail-title');
    const nextSlotHtml = availability?.next_available
      ? '<div class="dd-next-card"><div class="dd-next-label">NEXT AVAILABLE</div>' +
        '<div class="dd-next-row">' + iconSvg('calendar') + escapeHTML(availability.next_available.day_label === 'Today' || availability.next_available.day_label === 'Tomorrow' ? availability.next_available.day_label : availability.next_available.display.split(' • ')[0]) + '</div>' +
        '<div class="dd-next-row">' + iconSvg('clock') + escapeHTML(availability.next_available.display.includes('•') ? availability.next_available.display.split(' • ')[1] || availability.next_available.display : availability.next_available.display) + '</div></div>'
      : '';
    const userRole = user?.role || '';
    let footerHtml = '';
    if (badge.canBook && userRole === 'patient') {
      footerHtml = '<div class="dd-footer"><button class="btn btn-primary btn-block dd-book-btn" type="button" data-doctor-directory-action="book-appointment" data-doctor-id="' + doctorUserId + '">Continue to booking <span aria-hidden="true">→</span></button></div>';
    } else if (badge.canBook && (!user || userRole === 'guest')) {
      footerHtml = '<div class="dd-footer"><button class="btn btn-primary btn-block dd-book-btn" type="button"  data-doctor-directory-action="book-appointment" data-doctor-id="' + doctorUserId + '">Log in to explore booking <span aria-hidden="true">→</span></button></div>';
    } else if (!badge.canBook && userRole === 'patient') {
      footerHtml = '<div class="dd-footer"><div class="dd-unavailable-msg">' + iconSvg('info') + '<p>Appointments are currently unavailable for this sample profile.</p></div></div>';
    }
    const ratingHtml = getRatingMarkup(doctor);
    const workingDaysHtml = workingDays ? '<div class="dd-info-card"><div class="dd-info-card-title">Working days</div><div class="dd-info-card-value">' + escapeHTML(workingDays) + '</div></div>' : '';
    const durationHtml = availability ? '<div class="dd-info-card"><div class="dd-info-card-title">Appointment duration</div><div class="dd-info-card-value">' + duration + ' minutes</div></div>' : '';
    const reviewCopy = hasPatientRatings ? rating.toFixed(1) + ' out of 5 from ' + ratingCount + ' rating' + (ratingCount === 1 ? '' : 's') + '.' : 'No patient ratings are available for this sample profile.';
    modal.innerHTML = '<div class="modal doctor-detail-modal"><div class="dd-header">' +
      '<div class="dd-avatar" aria-hidden="true">' + escapeHTML(getDoctorInitials(doctor.name)) + '</div>' +
      '<div class="dd-header-info"><h2 id="doctor-detail-title">' + escapeHTML(doctor.name) + '</h2><span class="specialty-badge">' + escapeHTML(displaySpecialty) + '</span>' +
      '<div class="rating-row doctor-detail-rating-row">' + ratingHtml + '</div></div>' +
      '<button class="btn btn-outline btn-sm dd-close" type="button" data-doctor-directory-action="close-modal" aria-label="Close">&times;</button></div>' +
      '<div class="dd-scroll-content" tabindex="0" role="region" aria-label="Doctor profile details"><div class="dd-badge-row"><span class="' + badge.badgeClass + '" aria-label="' + escapeHTML(badge.badgeLabel) + '"><span class="status-dot" aria-hidden="true"></span>' + escapeHTML(badge.badgeText) + '</span></div>' +
      nextSlotHtml + '<div class="dd-info-cards-grid"><div class="dd-info-card"><div class="dd-info-card-title">Experience</div><div class="dd-info-card-value">' + experience + ' years</div></div>' + workingDaysHtml + durationHtml + '</div>' +
      '<div class="dd-info-card dd-info-card-full"><div class="dd-info-card-title">Department</div><div class="dd-info-card-value">' + escapeHTML(displaySpecialty) + '</div></div>' +
      '<div class="dd-info-card dd-info-card-full"><div class="dd-info-card-title">Patient ratings</div><div class="dd-info-card-value">' + escapeHTML(reviewCopy) + '</div></div></div>' + footerHtml + '</div>';
    modal.classList.add('open');
    document.body.classList.add('modal-open');
    setDialogBackgroundInert(true);
    const closeButton = modal.querySelector('.dd-close');
    if (closeButton) closeButton.focus({ preventScroll: true });
  };

  window.closeDoctorDetailModal = function () {
    const modal = document.getElementById("doctor-detail-modal");
    if (modal) modal.classList.remove("open");
    document.body.classList.remove("modal-open");
    setDialogBackgroundInert(false);
    const trigger = lastProfileTrigger;
    lastProfileTrigger = null;
    if (trigger?.isConnected) trigger.focus({ preventScroll: true });
  };

    document.addEventListener("keydown", (event) => {
    const modal = document.getElementById("doctor-detail-modal");
    if (!modal?.classList.contains("open")) return;

    if (event.key === "Escape") {
      event.preventDefault();
      window.closeDoctorDetailModal();
      return;
    }

    if (event.key !== "Tab") return;

    const focusable = Array.from(modal.querySelectorAll(
      'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
    )).filter((element) => element.getClientRects().length > 0);

    if (focusable.length === 0) {
      event.preventDefault();
      return;
    }

    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && (document.activeElement === first || !modal.contains(document.activeElement))) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && (document.activeElement === last || !modal.contains(document.activeElement))) {
      event.preventDefault();
      first.focus();
    }
  }, true);

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
  // Generated Doctor Directory controls use fixed actions and loaded records.
  function findLoadedDoctor(rawId) {
    const doctorId = safePositiveId(rawId);
    if (!doctorId) return null;

    const doctor = allDoctors.find(
      (item) => safePositiveId(item.user_id || item.id) === doctorId,
    );

    return doctor ? { id: doctorId, doctor } : null;
  }

  document.addEventListener("click", (event) => {
    const target = event.target instanceof Element ? event.target : null;
    const control = target?.closest("[data-doctor-directory-action]");
    if (!control) return;

    const action = control.dataset.doctorDirectoryAction;
    if (action !== "open-profile" &&
        action !== "book-appointment" &&
        action !== "close-modal") {
      return;
    }

    event.preventDefault();

    if (action === "close-modal") {
      window.closeDoctorDetailModal();
      return;
    }

    const resolved = findLoadedDoctor(control.dataset.doctorId);
    if (!resolved) {
      showToast("Doctor information not available.", "error");
      return;
    }

    if (action === "open-profile") {
      lastProfileTrigger = control;
      window.openDoctorDetailModal(resolved.id);
      return;
    }

    if (!getBadgeInfo(resolved.doctor).canBook) {
      showToast("Appointments are currently unavailable for this doctor.", "info");
      return;
    }

    const doctorName = typeof resolved.doctor.name === "string"
      ? resolved.doctor.name
      : "Doctor";
    const specialty = resolved.doctor.department_name ||
      resolved.doctor.specialty ||
      "General";

    window.bookFromDoctorModal(
      resolved.id,
      doctorName,
      typeof specialty === "string" ? specialty : "General",
    );
  });

  // The profile action is a native button inside each semantic article card.
  // Native keyboard activation now follows the same delegated click path.


}
