/**
 * HealthBridge — Home Page JavaScript
 * Handles: hero search redirect, stat counter animation, dynamic department cards.
 * Scroll animations are handled globally by main.js (initScrollAnimations).
 *
 * Phase 6.1: Clinical Departments section now loads dynamically from the
 * centralized departments API. Only active departments are shown.
 *
 * Used by: index.html
 */

"use strict";

document.addEventListener("DOMContentLoaded", () => {
  // Hero search — Enter key redirects to doctors page with query
  const heroSearch = document.getElementById("hero-search");
  if (heroSearch) {
    heroSearch.addEventListener("keydown", (e) => {
      if (e.key === "Enter") {
        const query = encodeURIComponent(heroSearch.value.trim());
        window.location.href = query ? `doctors.html?q=${query}` : getBasePath() + "doctors.html";
      }
    });
  }

  // Stat counter animation — counts up when scrolled into view
  const statCards = document.querySelectorAll(".stat-card h4");
  const countObserver = new IntersectionObserver(
    (entries) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) {
          animateCounter(entry.target);
          countObserver.unobserve(entry.target);
        }
      });
    },
    { threshold: 0.5 },
  );

  statCards.forEach((card) => countObserver.observe(card));

  // ── Load department cards dynamically ──────────────
  loadDepartmentCards();

  // ── Load hospital working hours dynamically ────────
  loadHospitalWorkingHours();
});

/**
 * Load active departments from the API and render them as clickable cards
 * in the Clinical Departments section of the home page.
 */
async function loadDepartmentCards() {
  const grid = document.getElementById("home-departments-grid");
  if (!grid) return;

  try {
    const res = await fetch((getBasePath() + "api/departments/get.php"), {
      credentials: "same-origin",
    });
    const result = await res.json();

    if (result.success && Array.isArray(result.departments)) {
      const departments = result.departments;

      if (departments.length === 0) {
        grid.innerHTML = '<p style="color:var(--text-muted);text-align:center;grid-column:1/-1">No departments available at this time.</p>';
        return;
      }

      // Map department names to icon classes
      const iconMap = {
        "cardiology": "fa-heart-pulse",
        "neurology": "fa-brain",
        "pediatrics": "fa-baby",
        "orthopedics": "fa-bone",
        "ophthalmology": "fa-eye",
        "dentistry": "fa-tooth",
        "dermatology": "fa-microscope",
        "gynecology": "fa-stethoscope",
        "general": "fa-stethoscope",
        "emergency": "fa-truck-medical",
        "radiology": "fa-x-ray",
        "pathology": "fa-vial",
        "psychiatry": "fa-comments",
        "surgery": "fa-scalpel",
        "oncology": "fa-ribbon",
        "urology": "fa-kidneys",
        "gastroenterology": "fa-stomach",
        "endocrinology": "fa-droplet",
        "pulmonology": "fa-lungs",
        "nephrology": "fa-filter",
        "hematology": "fa-droplet",
        "rheumatology": "fa-bone",
        "infectious": "fa-virus",
        "allergy": "fa-allergies",
        "nutrition": "fa-apple-alt",
        "physical therapy": "fa-person-walking",
        "occupational therapy": "fa-hands",
        "speech therapy": "fa-language",
      };

      // Get the base icon for a department name
      function getIcon(name) {
        const key = (name || "").toLowerCase().trim();
        return iconMap[key] || "fa-stethoscope";
      }

      grid.innerHTML = departments
        .map((dep) => {
          const icon = getIcon(dep.name);
          const query = encodeURIComponent(dep.name);
          return `
            <div class="dept-card" onclick="window.location.href = 'doctors.html?q=${query}'" style="cursor:pointer">
              <span class="dept-icon"><i class="fas ${icon}" aria-hidden="true"></i></span>
              <h4>${escapeHTML(dep.name)}</h4>
              <span>${escapeHTML(dep.description || "Specialist Care")}</span>
            </div>`;
        })
        .join("");
    }
  } catch (e) {
    console.warn("Could not load department cards:", e);
    // Keep the grid empty — it will show nothing if API fails
  }
}

/**
 * Load hospital working hours from the centralized settings API
 * and display them in a formatted 12-hour string.
 * Reuses the existing ${getBasePath()}api/settings/get-hospital.php endpoint.
 */
async function loadHospitalWorkingHours() {
  const hoursText = document.getElementById("hospital-hours-text");
  if (!hoursText) return;

  try {
    const res = await fetch((getBasePath() + "api/settings/get-hospital.php"), {
      credentials: "same-origin",
    });
    const result = await res.json();

    if (result.success && result.settings) {
      const openTime = result.settings.appointment_open_time || "08:00";
      const closeTime = result.settings.appointment_close_time || "22:00";

      // Use formatTime() from main.js to convert 24h to 12h format
      const openFormatted = formatTime(openTime);
      const closeFormatted = formatTime(closeTime);

      hoursText.textContent = `${openFormatted} – ${closeFormatted}`;
    } else {
      hoursText.textContent = "Hours unavailable";
    }
  } catch (e) {
    console.warn("Could not load hospital working hours:", e);
    hoursText.textContent = "Hours unavailable";
  }
}

/**
 * Animate a number counter from 0 to target value.
 * Supports decimal numbers and suffixes (e.g., "4.8", "120+").
 */
function animateCounter(element) {
  const text = element.textContent || "";
  const match = text.match(/([\d.]+)/);
  if (!match) return;

  const target = parseFloat(match[1]);
  const suffix = text.replace(match[1], "");
  const duration = 1200;
  const start = performance.now();
  const isDecimal = target % 1 !== 0;

  function update(now) {
    const elapsed = now - start;
    const progress = Math.min(elapsed / duration, 1);
    const eased = 1 - Math.pow(1 - progress, 3); // Ease-out cubic
    const current = target * eased;

    element.textContent = isDecimal
      ? current.toFixed(1) + suffix
      : Math.round(current) + suffix;

    if (progress < 1) requestAnimationFrame(update);
  }

  requestAnimationFrame(update);
}



