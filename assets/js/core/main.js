/**
 * Dynamic base path prefix detection to support reorganized subfolders
 */
function getBasePath() {
  const path = window.location.pathname;
  if (
    path.indexOf('/pages/admin/') !== -1 ||
    path.indexOf('/pages/doctor/') !== -1 ||
    path.indexOf('/pages/patient/') !== -1 ||
    path.indexOf('/pages/auth/') !== -1
  ) {
    return '../../';
  }
  return '';
}
/**
 * HealthBridge — Main Shared JavaScript
 * Contains: Toast notifications, auth utilities, theme system, navbar,
 *           scroll animations, back-to-top, password toggle, tab navigation,
 *           and SHARED HELPERS used by all dashboard pages.
 *
 * Used by: ALL pages
 */

"use strict";

/* ============================================================
   TOAST NOTIFICATION SYSTEM
   Slide-in alerts fixed at bottom-right. Auto-dismiss after delay.
   Usage: showToast("Message", "success" | "error" | "info", durationMs)
   ============================================================ */

function showToast(message, type = "info", duration = 3500) {
  const container = document.getElementById("toast-container");
  if (!container) {
    alert(message); // Fallback if toast container doesn't exist
    return;
  }

  const icons = {
    success: '<i class="fas fa-circle-check" aria-hidden="true"></i>',
    error: '<i class="fas fa-triangle-exclamation" aria-hidden="true"></i>',
    info: '<i class="fas fa-circle-info" aria-hidden="true"></i>',
  };

  const toast = document.createElement("div");
  toast.className = `toast toast-${type}`;
  toast.innerHTML = `
    <span class="toast-icon">${icons[type] || icons.info}</span>
    <span>${escapeHTML(message)}</span>
  `;

  container.appendChild(toast);

  // Trigger CSS animation (double rAF ensures the DOM is painted first)
  requestAnimationFrame(() => {
    requestAnimationFrame(() => toast.classList.add("show"));
  });

  // Auto-dismiss
  setTimeout(() => {
    toast.classList.remove("show");
    setTimeout(() => toast.remove(), 400);
  }, duration);
}

/* ============================================================
   AUTHENTICATION UTILITIES
   ============================================================ */

/** Save user object to localStorage */
function saveUser(user) {
  localStorage.setItem("hb_user", JSON.stringify(user));
}

/** Get user from localStorage, or null if not logged in / corrupt */
function getUser() {
  try {
    const data = localStorage.getItem("hb_user");
    return data ? JSON.parse(data) : null;
  } catch {
    return null;
  }
}

/** Remove localStorage user and call server logout */
function logoutUser() {
  localStorage.removeItem("hb_user");
  fetch((getBasePath() + "api/auth/logout.php"), { credentials: "same-origin" }).catch(() => {});
  window.location.href = getBasePath() + "pages/auth/login.html";
}

/** Redirect to login if no localStorage user */
function requireAuth() {
  if (!getUser()) {
    window.location.href = getBasePath() + "pages/auth/login.html";
    throw new Error("Authentication required");
  }
}

/** Sync with server session and update localStorage */
async function getSessionUser() {
  try {
    const res = await fetch((getBasePath() + "api/auth/current_user.php"), {
      credentials: "same-origin",
    });
    const result = await res.json();
    if (!res.ok || !result.success) {
      localStorage.removeItem("hb_user");
      return null;
    }
    saveUser(result.user);
    return result.user;
  } catch {
    return null;
  }
}

/** Verify server-side role, redirect if mismatch */
async function requireServerRole(role, redirectUrl = null) {
  if (!redirectUrl) redirectUrl = getBasePath() + "pages/auth/login.html";
  try {
    const user = await getSessionUser();
    if (!user || user.role !== role) {
      localStorage.removeItem("hb_user");
      showToast(
        `${role.charAt(0).toUpperCase() + role.slice(1)} login required.`,
        "error",
      );
      setTimeout(() => {
        window.location.href = redirectUrl;
      }, 1200);
      return null;
    }
    return user;
  } catch {
    showToast("Session check failed. Please log in again.", "error");
    setTimeout(() => {
      window.location.href = redirectUrl;
    }, 1200);
    return null;
  }
}

/** Get the correct dashboard URL for a user role */
function getDashboardUrl(role) {
  if (role === "admin") return getBasePath() + "pages/admin/admin.html";
  if (role === "doctor") return getBasePath() + "pages/doctor/doctor-dashboard.html";
  return getBasePath() + "pages/patient/dashboard.html";
}

/* ============================================================
   THEME SYSTEM (Dark / Light)
   Theme is applied before DOMContentLoaded via IIFE to avoid flash.
   ============================================================ */

const MOON_SVG = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
  stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
  <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
</svg>`;

const SUN_SVG = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
  stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
  <circle cx="12" cy="12" r="5"/>
  <line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/>
  <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/>
  <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
  <line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/>
  <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/>
  <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
</svg>`;

/** Apply theme early to prevent flash of wrong theme */
(function applyThemeEarly() {
  const saved = localStorage.getItem("hb_theme");
  const prefersDark = window.matchMedia("(prefers-color-scheme: dark)").matches;
  const theme = saved || (prefersDark ? "dark" : "light");
  if (theme === "light") {
    document.documentElement.setAttribute("data-theme", "light");
  } else {
    document.documentElement.removeAttribute("data-theme");
  }
})();

function getCurrentTheme() {
  return document.documentElement.getAttribute("data-theme") === "light"
    ? "light"
    : "dark";
}

function applyTheme(theme) {
  if (theme === "light") {
    document.documentElement.setAttribute("data-theme", "light");
  } else {
    document.documentElement.removeAttribute("data-theme");
  }
  localStorage.setItem("hb_theme", theme);

  // Update all toggle buttons on the page
  document.querySelectorAll(".theme-toggle").forEach((btn) => {
    btn.innerHTML = theme === "light" ? MOON_SVG : SUN_SVG;
    btn.setAttribute(
      "aria-label",
      theme === "light" ? "Switch to dark mode" : "Switch to light mode",
    );
    btn.setAttribute("title", theme === "light" ? "Dark mode" : "Light mode");
  });

  // Notify dynamic components (e.g., Leaflet maps) about theme change
  window.dispatchEvent(new CustomEvent("themechange", { detail: { theme } }));
}

function toggleTheme() {
  applyTheme(getCurrentTheme() === "dark" ? "light" : "dark");
}

function initTheme() {
  applyTheme(getCurrentTheme()); // Sync icon state
}

/* ============================================================
   NAVBAR
   Initializes mobile toggle, theme button, and auth state.
   ============================================================ */

/* ── Animated hamburger SVG ──────────────────────────────── */
const HAMBURGER_SVG = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
  <line x1="3" y1="6" x2="21" y2="6" class="ham-line ham-top"/>
  <line x1="3" y1="12" x2="21" y2="12" class="ham-line ham-mid"/>
  <line x1="3" y1="18" x2="21" y2="18" class="ham-line ham-bot"/>
</svg>`;

function initNavbar() {
  const navbar = document.getElementById("navbar");
  if (!navbar) return;

  const hasNavRow = !!navbar.querySelector(".nav-row");

  // Mobile hamburger toggle — only for navbars with a .nav-row
  if (hasNavRow) {
    let toggle = document.querySelector(".nav-toggle");
    if (!toggle) {
      const container = navbar.querySelector(".nav-container");
      if (container) {
        toggle = document.createElement("button");
        toggle.className = "nav-toggle";
        toggle.setAttribute("aria-label", "Toggle navigation menu");
        toggle.innerHTML = HAMBURGER_SVG;
        toggle.addEventListener("click", () => {
          const navRow = document.querySelector(".nav-row");
          navRow?.classList.toggle("open");
          toggle?.classList.toggle("open");
        });
        container.appendChild(toggle);
      }
    }

    // Close mobile menu when a nav link is clicked
    document
      .querySelectorAll(".nav-links a, .nav-actions a, .nav-actions button")
      .forEach((link) => {
        link.addEventListener("click", () => {
          document.querySelector(".nav-row")?.classList.remove("open");
          document.querySelector(".nav-toggle")?.classList.remove("open");
        });
      });
  }

  // Theme toggle button — injected into all navbars
  const navContainer = navbar.querySelector(".nav-container");
  if (navContainer && !navContainer.querySelector(".theme-toggle")) {
    const themeBtn = document.createElement("button");
    themeBtn.className = "theme-toggle";
    themeBtn.onclick = toggleTheme;
    const isDark = getCurrentTheme() === "dark";
    themeBtn.innerHTML = isDark ? SUN_SVG : MOON_SVG;
    themeBtn.setAttribute(
      "aria-label",
      isDark ? "Switch to light mode" : "Switch to dark mode",
    );
    themeBtn.setAttribute("title", isDark ? "Light mode" : "Dark mode");
    // If there's a nav-toggle (main pages), insert before it
    // Otherwise, inject into .nav-actions (simple navbars e.g. login page)
    const navToggle = navContainer.querySelector(".nav-toggle");
    if (navToggle) {
      navContainer.insertBefore(themeBtn, navToggle);
    } else {
      const navActions = navContainer.querySelector(".nav-actions");
      if (navActions) {
        navActions.insertBefore(themeBtn, navActions.firstChild);
      }
    }
  }

  // Scroll effect: add .scrolled class when scrolled past threshold
  function handleNavScroll() {
    const scrollY =
      window.scrollY || document.querySelector(".main-content")?.scrollTop || 0;
    navbar.classList.toggle("scrolled", scrollY > 20);
  }

  window.addEventListener("scroll", handleNavScroll, { passive: true });
  const mainContent = document.querySelector(".main-content");
  if (mainContent) {
    mainContent.addEventListener("scroll", handleNavScroll, { passive: true });
  }
  // Initial check
  handleNavScroll();

  // Auth state: only for navbars with #auth-links
  const authArea = document.getElementById("auth-links");
  if (!authArea) return;

  const user = getUser();
  if (user) {
    authArea.innerHTML = `
      <button class="nav-icon-btn" onclick="openSettings()" title="Settings" aria-label="Settings">
        <i class="fas fa-cog"></i>
      </button>
      <button class="nav-icon-btn" id="notif-bell-btn" title="Notifications" aria-label="Notifications">
        <i class="fas fa-bell"></i>
        <span class="notification-badge" id="notif-badge" style="display:none">0</span>
      </button>
      <span class="nav-user-name">${escapeHTML(user.name)}</span>
      <a href="${getDashboardUrl(user.role)}" class="nav-dashboard-link" title="Dashboard" aria-label="Open dashboard">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 10.8 12 3l9 7.8v9.7a.5.5 0 0 1-.5.5h-5.2a.5.5 0 0 1-.5-.5v-5.4H9.2v5.4a.5.5 0 0 1-.5.5H3.5a.5.5 0 0 1-.5-.5v-9.7Z"></path></svg>
        <span>Dashboard</span>
      </a>
      <button class="btn btn-outline btn-sm" onclick="logoutUser()">Logout</button>
    `;
  } else {
    authArea.innerHTML = `
      <a href="${getBasePath()}pages/auth/login.html" class="btn btn-outline btn-sm">Login</a>
      <a href="${getBasePath()}pages/auth/login.html#signup" class="btn btn-primary btn-sm">Sign Up</a>
    `;
  }
}

/* ============================================================
   UTILITY FUNCTIONS
   ============================================================ */

/** Escape HTML special characters to prevent XSS */
function escapeHTML(value) {
  return String(value ?? "").replace(
    /[&<>"']/g,
    (char) =>
      ({
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#039;",
      })[char],
  );
}

/** Debounce function calls — useful for search input handlers */
function debounce(fn, delay = 300) {
  let timer;
  return (...args) => {
    clearTimeout(timer);
    timer = setTimeout(() => fn.apply(this, args), delay);
  };
}

/** Format a date string for display */
function formatDate(dateStr) {
  if (!dateStr) return "-";
  try {
    return new Date(dateStr).toLocaleDateString(undefined, {
      year: "numeric",
      month: "short",
      day: "numeric",
    });
  } catch {
    return dateStr;
  }
}

/**
 * Format an appointment time for display.
 * Uses appointment_time_range if available (e.g. "09:00 AM - 09:30 AM"),
 * otherwise falls back to formatting the single time value.
 *
 * @param {object} appointment - Appointment object with time and/or appointment_time_range
 * @returns {string} Formatted time string
 */
function formatApptTime(appointment) {
  if (!appointment) return "-";
  if (appointment.appointment_time_range) {
    return appointment.appointment_time_range;
  }
  return formatTime(appointment.time || "");
}

/**
 * Format a time string for consistent display with AM/PM.
 * Handles both "09:00" (24-hour) and "09:00 AM" (12-hour) formats.
 * Returns formatted time like "9:00 AM", "2:30 PM".
 *
 * @param {string} timeStr - The time string to format
 * @returns {string} Formatted time with AM/PM
 */
function formatTime(timeStr) {
  if (!timeStr) return "-";

  // If already in 12-hour format with AM/PM, normalize spacing
  if (/^\d{1,2}:\d{2}\s*(AM|PM)$/i.test(timeStr.trim())) {
    const match = timeStr.trim().match(/^(\d{1,2}:\d{2})\s*(AM|PM)$/i);
    if (match) {
      const [_, time, ampm] = match;
      const parts = time.split(":");
      const hour = parseInt(parts[0], 10);
      const min = parts[1];
      const displayHour = hour % 12 || 12;
      return `${displayHour}:${min} ${ampm.toUpperCase()}`;
    }
  }

  // Handle 24-hour format (e.g., "09:00", "14:30")
  if (/^([01]\d|2[0-3]):([0-5]\d)$/.test(timeStr)) {
    const parts = timeStr.split(":");
    const hour = parseInt(parts[0], 10);
    const min = parts[1];
    const ampm = hour >= 12 ? "PM" : "AM";
    const displayHour = hour % 12 || 12;
    return `${displayHour}:${min} ${ampm}`;
  }

  // Fallback: return as-is
  return timeStr;
}

/* ============================================================
   SHARED DATA HELPERS
   Used by dashboard.js, doctor-dashboard.js, admin.js
   ============================================================ */

/**
 * Generic filter function for array of objects.
 * Checks if any of the specified fields contain the query string.
 *
 * @param {Array} items — Array of objects to filter
 * @param {string} query — Search string (case-insensitive)
 * @param {string[]} fields — Object property names to search
 * @returns {Array} Filtered items
 */
function filterData(items, query, fields) {
  const q = query.toLowerCase().trim();
  if (!q) return items;
  return items.filter((item) =>
    fields.some((f) => (item[f] || "").toLowerCase().includes(q)),
  );
}

/**
 * Generic table renderer.
 * Renders items as table rows using a custom row generator function.
 *
 * @param {string} tbodyId — ID of the tbody element
 * @param {Array} items — Array of data objects
 * @param {Function} rowFn — Function(item) => HTML string for a row
 * @param {string} emptyMsg — Message when no items (default: "No items found.")
 * @param {number} colSpan — Number of columns for empty row
 */
function renderTable(
  tbodyId,
  items,
  rowFn,
  emptyMsg = "No items found.",
  colSpan = 6,
) {
  const tbody = document.getElementById(tbodyId);
  if (!tbody) return;

  if (!items.length) {
    tbody.innerHTML = `<tr><td colspan="${colSpan}" class="text-center" style="padding:var(--s8);color:var(--text-muted)">${emptyMsg}</td></tr>`;
    return;
  }

  tbody.innerHTML = items.map(rowFn).join("");
}

/**
 * Wrapper for fetch() with consistent error handling.
 * Automatically shows toast on network errors.
 *
 * @param {string} url — API endpoint URL
 * @param {object} options — fetch options
 * @param {string} errorMsg — User-facing error message
 * @returns {Promise<object>} Parsed JSON response
 */
async function apiFetch(
  url,
  options = {},
  errorMsg = "Service unavailable. Please ensure XAMPP is running.",
) {
  try {
    const res = await fetch(url, { credentials: "same-origin", ...options });
    const data = await res.json();
    return { ok: res.ok, status: res.status, data };
  } catch {
    showToast(errorMsg, "error");
    return {
      ok: false,
      status: 0,
      data: { success: false, message: errorMsg },
    };
  }
}

/* ============================================================
   INITIALIZATION
   ============================================================ */

/** Create toast container if it doesn't exist */
function initToastContainer() {
  if (!document.getElementById("toast-container")) {
    const container = document.createElement("div");
    container.id = "toast-container";
    document.body.appendChild(container);
  }
}

/* ============================================================
   SCROLL-TRIGGERED ANIMATIONS
   Works on ALL pages (index, about, doctors, etc.)
   Elements with .fade-in or .fade-in-stagger get .animated when in view.
   ============================================================ */

function initScrollAnimations() {
  const observer = new IntersectionObserver(
    (entries) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        const el = entry.target;
        el.classList.add("animated");
        // Stagger: assign increasing delay to each child
        if (el.classList.contains("fade-in-stagger")) {
          [...el.children].forEach((child, i) => {
            child.style.animationDelay = `${i * 0.13}s`;
          });
        }
        observer.unobserve(el);
      });
    },
    { threshold: 0.12 },
  );

  document.querySelectorAll(".fade-in, .fade-in-stagger").forEach((el) => {
    observer.observe(el);
  });
}

/* ============================================================
   BACK TO TOP BUTTON
   ============================================================ */

function initBackToTop() {
  if (document.getElementById("back-to-top-btn")) return; // Avoid duplicates

  const btn = document.createElement("button");
  btn.id = "back-to-top-btn";
  btn.className = "back-to-top-btn";
  btn.setAttribute("aria-label", "Back to top");
  btn.setAttribute("title", "Back to top");
  btn.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="18 15 12 9 6 15"></polyline></svg>`;
  document.body.appendChild(btn);

  const THRESHOLD = 300;

  btn.addEventListener("click", () => {
    const panel = document.querySelector(".main-content");
    if (panel) {
      panel.scrollTo({ top: 0, behavior: "smooth" });
    } else {
      window.scrollTo({ top: 0, behavior: "smooth" });
    }
  });

  function onScroll(scrollTop) {
    btn.classList.toggle("show", scrollTop > THRESHOLD);
  }

  // Listen on .main-content (dashboard pages) and window (public pages)
  const panel = document.querySelector(".main-content");
  if (panel) {
    panel.addEventListener("scroll", () => onScroll(panel.scrollTop), {
      passive: true,
    });
  }
  window.addEventListener("scroll", () => onScroll(window.scrollY), {
    passive: true,
  });
}

/* ============================================================
   PASSWORD VISIBILITY TOGGLE
   ============================================================ */

const EYE_OPEN_SVG = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
  stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
  <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
</svg>`;

const EYE_CLOSED_SVG = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
  stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
  <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/>
  <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/>
  <line x1="1" y1="1" x2="23" y2="23"/>
</svg>`;

function togglePasswordVisibility(btn) {
  const wrapper = btn.closest(".pw-field");
  if (!wrapper) return;
  const input = wrapper.querySelector("input");
  if (!input) return;

  const isHidden = input.type === "password";
  input.type = isHidden ? "text" : "password";
  btn.innerHTML = isHidden ? EYE_CLOSED_SVG : EYE_OPEN_SVG;
  btn.setAttribute("aria-label", isHidden ? "Hide password" : "Show password");
  btn.setAttribute("title", isHidden ? "Hide password" : "Show password");
}

/* ============================================================
   GENERIC TAB NAVIGATION SYSTEM
   Used by dashboard.html, doctor-dashboard.html, admin.html
   ============================================================ */

/**
 * Initialize sidebar tab navigation with localStorage persistence.
 * @param {string} storageKey — LocalStorage key for active tab
 */
function initTabNavigation(storageKey) {
  const navLinks = document.querySelectorAll(".sidebar-nav a[href^='#']");
  const sections = document.querySelectorAll(".main-content section");
  if (!navLinks.length) return;

  navLinks.forEach((link) => {
    link.addEventListener("click", (e) => {
      e.preventDefault();
      const targetId = link.getAttribute("href").substring(1);

      // Update active nav link
      navLinks.forEach((item) => item.classList.remove("active"));
      link.classList.add("active");

      // Show target section, hide others
      sections.forEach((section) => {
        section.classList.toggle("active", section.id === targetId);
      });

      // Persist tab state
      if (storageKey) localStorage.setItem(storageKey, targetId);
    });
  });

  // Click on stat cards to jump to their linked tab
  document.querySelectorAll(".stat-card[data-tab]").forEach((card) => {
    card.addEventListener("click", () => {
      document
        .querySelector(
          `.sidebar-nav a[href="#${card.getAttribute("data-tab")}"]`,
        )
        ?.click();
    });
  });

  // Restore active tab (URL hash > localStorage > first tab)
  const hash = window.location.hash.substring(1);
  const savedTab =
    hash ||
    (storageKey ? localStorage.getItem(storageKey) : null) ||
    "overview";
  const activeLink = document.querySelector(
    `.sidebar-nav a[href="#${savedTab}"]`,
  );
  if (activeLink) {
    activeLink.click();
  } else if (navLinks.length > 0) {
    navLinks[0].click();
  }

  // Handle hash changes
  window.addEventListener("hashchange", () => {
    const link = document.querySelector(
      `.sidebar-nav a[href="${window.location.hash}"]`,
    );
    if (link) link.click();
  });
}

/* ============================================================
   UNIFIED MODAL SYSTEM
   Consistent behavior for all modals:
   - Close on overlay click (outside modal)
   - Close on Escape key
   - Prevent body scroll while open
   Usage: just add the modal-overlay class + modal class structure
   ============================================================ */

function initModalSystem() {
  // Close modal when clicking on overlay background (not modal content)
  document.addEventListener("click", (e) => {
    const overlay = e.target.closest(".modal-overlay.open");
    if (!overlay) return;
    // Only close if the click is directly on the overlay, not on the modal or its children
    if (e.target === overlay) {
      overlay.classList.remove("open");
      document.body.classList.remove("modal-open");
      // Dispatch a custom event so modals can clean up
      overlay.dispatchEvent(new CustomEvent("modalclose"));
    }
  });

  // Close modal on Escape key
  document.addEventListener("keydown", (e) => {
    if (e.key !== "Escape") return;
    // Find the top-most open modal overlay
    const overlays = document.querySelectorAll(".modal-overlay.open");
    if (overlays.length === 0) return;
    const topmost = overlays[overlays.length - 1];
    topmost.classList.remove("open");
    document.body.classList.remove("modal-open");
    topmost.dispatchEvent(new CustomEvent("modalclose"));
  });

  // Watch for modal open/close to toggle body scroll
  const observer = new MutationObserver(() => {
    const hasOpen = document.querySelector(".modal-overlay.open");
    document.body.classList.toggle("modal-open", !!hasOpen);
  });

  // Observe the entire document for class changes on modal overlays
  document.querySelectorAll(".modal-overlay").forEach((el) => {
    observer.observe(el, { attributes: true, attributeFilter: ["class"] });
  });

  // Also observe new modal overlays added to the DOM
  const containerObserver = new MutationObserver((mutations) => {
    mutations.forEach((mutation) => {
      mutation.addedNodes.forEach((node) => {
        if (node.nodeType === 1 && node.classList?.contains("modal-overlay")) {
          observer.observe(node, {
            attributes: true,
            attributeFilter: ["class"],
          });
        }
      });
    });
  });
  containerObserver.observe(document.body, { childList: true, subtree: false });
}

/* ============================================================
   BUTTON LOADING STATE
   ============================================================ */

function setLoading(btn, isLoading, text = "Loading...") {
  if (!btn) return;
  btn.disabled = isLoading;
  btn.dataset.originalText = btn.dataset.originalText || btn.textContent;
  btn.textContent = isLoading ? text : btn.dataset.originalText;
}

/* ============================================================
   SHARED FALLBACK DATA
   Used when API is unavailable (e.g., XAMPP not running)
   ============================================================ */

const DOCTORS_FALLBACK = [
  {
    id: 1,
    name: "Dr. Ahmed Hassan",
    specialty: "Cardiology",
    department_id: 1,
    department_name: "Cardiology",
    rating: 4.9,
    exp: 12,
    available: 1,
    user_id: 1,
    icon: "fa-user-doctor",
  },
  {
    id: 2,
    name: "Dr. Sarah Johnson",
    specialty: "Dermatology",
    department_id: 2,
    department_name: "Dermatology",
    rating: 4.8,
    exp: 8,
    available: 1,
    user_id: 2,
    icon: "fa-user-doctor",
  },
  {
    id: 3,
    name: "Dr. Mohamed Ali",
    specialty: "Neurology",
    department_id: 3,
    department_name: "Neurology",
    rating: 4.7,
    exp: 15,
    available: 0,
    user_id: 3,
    icon: "fa-user-doctor",
  },
  {
    id: 4,
    name: "Dr. Fatima Nour",
    specialty: "Pediatrics",
    department_id: 4,
    department_name: "Pediatrics",
    rating: 4.9,
    exp: 10,
    available: 1,
    user_id: 4,
    icon: "fa-user-doctor",
  },
  {
    id: 5,
    name: "Dr. Karim Salah",
    specialty: "Orthopedics",
    department_id: 5,
    department_name: "Orthopedics",
    rating: 4.6,
    exp: 9,
    available: 1,
    user_id: 5,
    icon: "fa-user-doctor",
  },
  {
    id: 6,
    name: "Dr. Layla Ibrahim",
    specialty: "Dentistry",
    department_id: 6,
    department_name: "Dentistry",
    rating: 4.8,
    exp: 6,
    available: 1,
    user_id: 6,
    icon: "fa-user-doctor",
  },
  {
    id: 7,
    name: "Dr. Omar Khaled",
    specialty: "Ophthalmology",
    department_id: 7,
    department_name: "Ophthalmology",
    rating: 4.7,
    exp: 11,
    available: 0,
    user_id: 7,
    icon: "fa-user-doctor",
  },
  {
    id: 8,
    name: "Dr. Nadia Rashid",
    specialty: "Gynecology",
    department_id: 8,
    department_name: "Gynecology",
    rating: 4.9,
    exp: 14,
    available: 1,
    user_id: 8,
    icon: "fa-user-doctor",
  },
];

/* ============================================================
   DOM READY — Initialize everything
   ============================================================ */

document.addEventListener("DOMContentLoaded", () => {
  initTheme();
  initToastContainer();
  initNavbar();
  initBackToTop();
  initScrollAnimations();
  initModalSystem();

  // Load settings.js and notifications.js dynamically if user is logged in.
  // This ensures the Settings gear and Notification bell work on ALL pages.
  if (getUser()) {
    // Prevent duplicate notification initialization
    if (!window._hbNotificationsInitialized) {
      // Load settings.js if not already loaded
      if (typeof openSettings === "undefined") {
        const script1 = document.createElement("script");
        script1.src = getBasePath() + "assets/js/pages/settings.js";
        script1.onload = () => {
          // Now load notifications.js
          if (typeof NotificationService === "undefined") {
            const script2 = document.createElement("script");
            script2.src = getBasePath() + "assets/js/modules/notifications.js";
            script2.onload = () => {
              window._hbNotificationsInitialized = true;
              NotificationService.init();
              NotificationService.initDropdown();
            };
            document.body.appendChild(script2);
          } else {
            window._hbNotificationsInitialized = true;
            NotificationService.init();
            NotificationService.initDropdown();
          }
        };
        document.body.appendChild(script1);
      } else {
        // Both already loaded (dashboard pages)
        if (typeof NotificationService !== "undefined") {
          window._hbNotificationsInitialized = true;
          NotificationService.init();
          NotificationService.initDropdown();
        }
      }
    }
  }
});



