/*
 * HealthBridge Public V2 — standalone public-site runtime.
 *
 * This module owns the helpers and behavior shared by Public V2 pages.
 *
 * Public page controllers may use the small global helpers below:
 * getBasePath, getUser, getDashboardUrl, escapeHTML, safePositiveId,
 * safeCount, debounce and showToast.
 */
"use strict";

/* Apply the stored theme before the public stylesheets are painted. */
(function applyStoredThemeEarly() {
  try {
    const saved = localStorage.getItem("hb_theme");
    const prefersDark = window.matchMedia("(prefers-color-scheme: dark)").matches;
    const theme = saved === "light" || saved === "dark"
      ? saved
      : (prefersDark ? "dark" : "light");

    if (theme === "light") {
      document.documentElement.setAttribute("data-theme", "light");
    } else {
      document.documentElement.removeAttribute("data-theme");
    }
  } catch {
    document.documentElement.removeAttribute("data-theme");
  }
})();

/* Bell badge pulse gating: the pulse plays once per AREA entry, not on
 * every page load. The public site and both dashboards share the
 * hb_bell_area session key; when the stored area already matches this
 * one, .hb-bell-still on the root suppresses the pulse for this load
 * (see components.css). Runs at parse time, before the badge can be
 * unhidden by the notification fetch. */
(function gateBellPulse() {
  /* Workspace pages load this runtime too, but their own shells own
     the gate there — only claim the public area off-workspace. */
  if (/\/(?:patient|doctor|admin)\//.test(window.location.pathname) &&
      window.location.pathname.includes("/pages/")) {
    return;
  }
  try {
    if (sessionStorage.getItem("hb_bell_area") === "public") {
      document.documentElement.classList.add("hb-bell-still");
    }
    sessionStorage.setItem("hb_bell_area", "public");
  } catch {
    /* Storage unavailable: leave the pulse allowed. */
  }
})();

const PUBLIC_MOON_SVG = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>`;
const PUBLIC_SUN_SVG = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="5"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42m12.72 12.72 1.42 1.42M1 12h2m18 0h2M4.22 19.78l1.42-1.42m12.72-12.72 1.42-1.42"/></svg>`;
const PUBLIC_HAMBURGER_SVG = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="3" y1="6" x2="21" y2="6" class="ham-line ham-top"/><line x1="3" y1="12" x2="21" y2="12" class="ham-line ham-mid"/><line x1="3" y1="18" x2="21" y2="18" class="ham-line ham-bot"/></svg>`;
const PUBLIC_BELL_SVG = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg>`;
const PUBLIC_GEAR_SVG = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="6.2"/><circle cx="12" cy="12" r="2.6"/><path d="M12 2.6v3M12 18.4v3M2.6 12h3M18.4 12h3M5.3 5.3l2.1 2.1M16.6 16.6l2.1 2.1M18.7 5.3l-2.1 2.1M7.4 16.6l-2.1 2.1"/></svg>`;

function getBasePath() {
  const path = window.location.pathname;
  if (
    path.includes("/pages/admin/") ||
    path.includes("/pages/doctor/") ||
    path.includes("/pages/patient/") ||
    path.includes("/pages/auth/")
  ) {
    return "../../";
  }
  return "";
}

function getUser() {
  try {
    const raw = localStorage.getItem("hb_user");
    if (!raw) return null;
    const user = JSON.parse(raw);
    return user && typeof user === "object" && !Array.isArray(user)
      ? user
      : null;
  } catch {
    return null;
  }
}

function getDashboardUrl(role) {
  if (role === "admin") return getBasePath() + "pages/admin/admin.html";
  if (role === "doctor") return getBasePath() + "pages/doctor/doctor-dashboard.html";
  return getBasePath() + "pages/patient/dashboard.html";
}

function escapeHTML(value) {
  return String(value ?? "").replace(/[&<>"']/g, (character) => ({
    "&": "&amp;",
    "<": "&lt;",
    ">": "&gt;",
    '"': "&quot;",
    "'": "&#039;",
  })[character]);
}

function safePositiveId(value) {
  if (typeof value !== "number" && typeof value !== "string") return 0;
  const normalized = typeof value === "string" ? value.trim() : value;
  if (typeof normalized === "string" && !/^\d+$/.test(normalized)) return 0;
  const number = Number(normalized);
  return Number.isSafeInteger(number) && number > 0 ? number : 0;
}

function safeCount(value, fallback = 0) {
  if (typeof value !== "number" && typeof value !== "string") return fallback;
  const normalized = typeof value === "string" ? value.trim() : value;
  if (typeof normalized === "string" && !/^\d+$/.test(normalized)) return fallback;
  const number = Number(normalized);
  return Number.isSafeInteger(number) && number >= 0 ? number : fallback;
}

function debounce(callback, delay = 250) {
  let timer = 0;
  return (...args) => {
    window.clearTimeout(timer);
    timer = window.setTimeout(() => callback(...args), delay);
  };
}

function showToast(message, type = "info", duration = 3500) {
  const region = document.getElementById("site-toast-region");
  if (!region) return;

  const toast = document.createElement("div");
  toast.className = "site-toast";
  toast.dataset.type = ["success", "error", "info"].includes(type) ? type : "info";
  toast.setAttribute("role", type === "error" ? "alert" : "status");
  toast.textContent = String(message ?? "");
  region.appendChild(toast);

  requestAnimationFrame(() => {
    requestAnimationFrame(() => toast.classList.add("is-visible"));
  });

  window.setTimeout(() => {
    toast.classList.remove("is-visible");
    window.setTimeout(() => toast.remove(), 220);
  }, Math.max(1200, duration));
}

async function logoutUser() {
  try {
    localStorage.removeItem("hb_user");
    localStorage.removeItem("hb_remember");
  } catch {
    // Continue to terminate the server session if local storage is unavailable.
  }

  try {
    await fetch(getBasePath() + "api/auth/logout.php", {
      method: "POST",
      credentials: "same-origin",
    });
  } catch {
    // Local identity is already cleared when the network is unavailable.
  } finally {
    window.location.assign(getBasePath() + "pages/auth/login.html");
  }
}
window.logoutUser = logoutUser;

function getCurrentTheme() {
  return document.documentElement.getAttribute("data-theme") === "light"
    ? "light"
    : "dark";
}

function applyTheme(theme, syncPreference = false) {
  const nextTheme = theme === "light" ? "light" : "dark";
  if (nextTheme === "light") {
    document.documentElement.setAttribute("data-theme", "light");
  } else {
    document.documentElement.removeAttribute("data-theme");
  }

  try {
    localStorage.setItem("hb_theme", nextTheme);
  } catch {
    // Theme still applies for this page view when storage is unavailable.
  }

  document.querySelectorAll(".theme-toggle").forEach((button) => {
    button.innerHTML = nextTheme === "light" ? PUBLIC_MOON_SVG : PUBLIC_SUN_SVG;
    button.setAttribute(
      "aria-label",
      nextTheme === "light" ? "Switch to dark mode" : "Switch to light mode",
    );
    button.setAttribute("title", nextTheme === "light" ? "Dark mode" : "Light mode");
  });

  window.dispatchEvent(new CustomEvent("themechange", { detail: { theme: nextTheme } }));

  // Preserve the existing authenticated appearance preference contract.
  // This request occurs only after an explicit user toggle, never on page load.
  if (syncPreference && getUser()) {
    fetch(getBasePath() + "api/settings/update.php", {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ section: "appearance", theme: nextTheme }),
    }).catch(() => {});
  }
}


function bindPublicThemeToggle(button) {
  if (button.dataset.publicThemeBound === "true") return;
  button.addEventListener("click", () => {
    applyTheme(getCurrentTheme() === "dark" ? "light" : "dark", true);
  });
  button.dataset.publicThemeBound = "true";
}

function initPublicThemeToggles() {
  document.querySelectorAll(".theme-toggle").forEach(bindPublicThemeToggle);
  applyTheme(getCurrentTheme());
}

function closePublicMenu(navRow, toggle) {
  navRow.classList.remove("open");
  toggle.classList.remove("open");
  toggle.setAttribute("aria-expanded", "false");
  toggle.setAttribute("aria-label", "Open navigation menu");
}

function initPublicNavbar() {
  const navbar = document.getElementById("navbar");
  const container = navbar?.querySelector(".nav-container");
  const navRow = navbar?.querySelector(".nav-row");
  if (!navbar || !container || !navRow) return;

  let navToggle = container.querySelector(".nav-toggle");
  if (!navToggle) {
    navToggle = document.createElement("button");
    navToggle.type = "button";
    navToggle.className = "nav-toggle";
    navToggle.innerHTML = PUBLIC_HAMBURGER_SVG;
    container.appendChild(navToggle);
  }
  navToggle.setAttribute("aria-controls", navRow.id || "nav-menu");
  navToggle.setAttribute("aria-expanded", String(navRow.classList.contains("open")));
  navToggle.setAttribute(
    "aria-label",
    navRow.classList.contains("open") ? "Close navigation menu" : "Open navigation menu",
  );

  let themeToggle = container.querySelector(".theme-toggle");
  if (!themeToggle) {
    themeToggle = document.createElement("button");
    themeToggle.type = "button";
    themeToggle.className = "theme-toggle";
    container.insertBefore(themeToggle, navToggle);
  }
  bindPublicThemeToggle(themeToggle);
  applyTheme(getCurrentTheme());

  navToggle.addEventListener("click", () => {
    const willOpen = !navRow.classList.contains("open");
    if (willOpen) {
      navRow.classList.add("open");
      navToggle.classList.add("open");
      navToggle.setAttribute("aria-expanded", "true");
      navToggle.setAttribute("aria-label", "Close navigation menu");
    } else {
      closePublicMenu(navRow, navToggle);
    }
  });

  navRow.querySelectorAll("a").forEach((link) => {
    link.addEventListener("click", () => closePublicMenu(navRow, navToggle));
  });

  document.addEventListener("pointerdown", (event) => {
    if (!navRow.classList.contains("open")) return;
    const target = event.target;
    if (target instanceof Node && !container.contains(target)) {
      closePublicMenu(navRow, navToggle);
    }
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && navRow.classList.contains("open")) {
      closePublicMenu(navRow, navToggle);
      navToggle.focus();
    }
  });

  const authArea = document.getElementById("auth-links");
  const user = getUser();
  if (authArea && user) {
    const displayName = typeof user.name === "string" && user.name.trim()
      ? user.name.trim().slice(0, 80)
      : "Account";
    const dashboard = getDashboardUrl(user.role);

    authArea.replaceChildren();

    const name = document.createElement("span");
    name.className = "nav-user-name";
    name.textContent = displayName;

    const dashboardLink = document.createElement("a");
    dashboardLink.className = "nav-dashboard-link";
    dashboardLink.href = dashboard;
    dashboardLink.textContent = "Open workspace";

    const logoutButton = document.createElement("button");
    logoutButton.className = "btn btn-outline btn-sm";
    logoutButton.type = "button";
    logoutButton.textContent = "Log out";
    logoutButton.addEventListener("click", logoutUser);

    authArea.append(name, dashboardLink, logoutButton);
  }

  // Quick access for signed-in visitors: notifications + settings
  // icons beside the theme toggle, on every public page.
  if (user && !container.querySelector(".nav-quick-icons")) {
    const base = getBasePath();
    const bellHref = user.role === "patient"
      ? base + "pages/patient/notifications.html"
      : getDashboardUrl(user.role);
    const gearHref = user.role === "patient"
      ? base + "pages/patient/settings.html"
      : getDashboardUrl(user.role);

    const group = document.createElement("div");
    group.className = "nav-quick-icons";

    // The bell opens a small notifications panel (latest four), the same
    // pattern as the workspace header; "View all" goes to the full page.
    const bellMenu = document.createElement("div");
    bellMenu.className = "nav-quick-menu";

    const bell = document.createElement("button");
    bell.type = "button";
    bell.className = "nav-quick-bell";
    bell.setAttribute("aria-label", "Notifications");
    bell.setAttribute("aria-haspopup", "true");
    bell.setAttribute("aria-expanded", "false");
    bell.innerHTML = PUBLIC_BELL_SVG;
    const badge = document.createElement("span");
    badge.className = "nav-quick-badge";
    badge.hidden = true;
    bell.appendChild(badge);

    const panel = document.createElement("div");
    panel.className = "nav-quick-dropdown";
    panel.setAttribute("aria-hidden", "true");
    panel.inert = true;
    const heading = document.createElement("div");
    heading.className = "nav-quick-heading";
    heading.textContent = "Notifications";
    const list = document.createElement("div");
    list.className = "nav-quick-list";
    const empty = document.createElement("p");
    empty.className = "nav-quick-empty";
    empty.textContent = "Your latest updates will appear here.";
    list.appendChild(empty);
    const viewAll = document.createElement("a");
    viewAll.className = "nav-quick-viewall";
    viewAll.href = bellHref;
    viewAll.textContent = "View all notifications";
    panel.append(heading, list, viewAll);
    bellMenu.append(bell, panel);

    const setQuickPanel = (open) => {
      panel.classList.toggle("is-open", open);
      panel.setAttribute("aria-hidden", String(!open));
      panel.inert = !open;
      bell.setAttribute("aria-expanded", String(open));
    };
    bell.addEventListener("click", () => {
      setQuickPanel(bell.getAttribute("aria-expanded") !== "true");
    });
    document.addEventListener("pointerdown", (event) => {
      if (event.target instanceof Node && !bellMenu.contains(event.target)) {
        setQuickPanel(false);
      }
    });
    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape") setQuickPanel(false);
    });

    const gear = document.createElement("a");
    gear.href = gearHref;
    gear.setAttribute("aria-label", "Settings");
    gear.title = "Settings";
    gear.innerHTML = PUBLIC_GEAR_SVG;

    group.append(bellMenu, gear);
    container.insertBefore(group, themeToggle);

    const formatQuickDate = (value) => {
      const date = new Date(String(value || "").replace(" ", "T"));
      return Number.isNaN(date.getTime())
        ? ""
        : date.toLocaleDateString("en-US", { month: "short", day: "numeric" });
    };

    fetch(base + "api/notifications/notifications.php", {
      method: "GET",
      credentials: "same-origin",
      headers: { Accept: "application/json" },
    })
      .then((response) => response.json().catch(() => null))
      .then((payload) => {
        const items = Array.isArray(payload?.notifications) ? payload.notifications : [];
        const unread = items.filter((item) => Number(item.is_read) === 0).length;
        if (unread > 0) {
          badge.hidden = false;
          badge.textContent = unread > 99 ? "99+" : String(unread);
        }
        if (items.length) {
          list.replaceChildren();
          items.slice(0, 4).forEach((item) => {
            const row = document.createElement("a");
            row.href = bellHref;
            row.className = "nav-quick-item" +
              (Number(item.is_read) === 0 ? " is-unread" : "");
            const strong = document.createElement("strong");
            strong.textContent = item.title || "Notification";
            const time = document.createElement("time");
            time.textContent = formatQuickDate(item.created_at);
            row.append(strong, time);
            list.appendChild(row);
          });
        }
      })
      .catch(() => { /* badge stays hidden, panel keeps its empty note */ });
  }

  const updateScrolled = () => {
    navbar.classList.toggle("scrolled", window.scrollY > 20);
  };
  window.addEventListener("scroll", updateScrolled, { passive: true });
  updateScrolled();
}

function initPublicToastRegion() {
  if (document.getElementById("site-toast-region")) return;
  const region = document.createElement("div");
  region.id = "site-toast-region";
  region.className = "site-toast-region";
  region.setAttribute("aria-live", "polite");
  region.setAttribute("aria-relevant", "additions");
  document.body.appendChild(region);
}

/* Shared fallback data. The directory controller replaces this with API data. */
const DOCTORS_FALLBACK = [
  { id: 1, user_id: 1, name: "Dr. Ahmed Hassan", specialty: "Cardiology", department_id: 1, department_name: "Cardiology", rating: 4.9, exp: 12, available: 1, rating_count: 0 },
  { id: 2, user_id: 2, name: "Dr. Sarah Johnson", specialty: "Dermatology", department_id: 2, department_name: "Dermatology", rating: 4.8, exp: 8, available: 1, rating_count: 0 },
  { id: 3, user_id: 3, name: "Dr. Mohamed Ali", specialty: "Neurology", department_id: 3, department_name: "Neurology", rating: 4.7, exp: 15, available: 0, rating_count: 0 },
  { id: 4, user_id: 4, name: "Dr. Fatima Nour", specialty: "Pediatrics", department_id: 4, department_name: "Pediatrics", rating: 4.9, exp: 10, available: 1, rating_count: 0 },
  { id: 5, user_id: 5, name: "Dr. Karim Salah", specialty: "Orthopedics", department_id: 5, department_name: "Orthopedics", rating: 4.6, exp: 9, available: 1, rating_count: 0 },
  { id: 6, user_id: 6, name: "Dr. Layla Ibrahim", specialty: "Dentistry", department_id: 6, department_name: "Dentistry", rating: 4.8, exp: 6, available: 1, rating_count: 0 },
  { id: 7, user_id: 7, name: "Dr. Omar Khaled", specialty: "Ophthalmology", department_id: 7, department_name: "Ophthalmology", rating: 4.7, exp: 11, available: 0, rating_count: 0 },
  { id: 8, user_id: 8, name: "Dr. Nadia Rashid", specialty: "Gynecology", department_id: 8, department_name: "Gynecology", rating: 4.9, exp: 14, available: 1, rating_count: 0 },
];

/**
 * One observer and one animation vocabulary for all Public V2 page reveals.
 * Page controllers only add data-public-reveal="rise|left|right|card" markers.
 * Dynamic cards are picked up automatically; without IntersectionObserver or
 * when reduced motion is requested, content stays visible and no motion runs.
 */
function initPublicReveals() {
  const motionPreference = window.matchMedia?.("(prefers-reduced-motion: reduce)");
  if (motionPreference?.matches || typeof IntersectionObserver !== "function") return;

  const selector = "[data-public-reveal]";
  const observer = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
      if (!entry.isIntersecting) return;

      const element = entry.target;
      observer.unobserve(element);
      element.classList.remove("public-reveal-pending");
      element.classList.add("public-reveal-enter");
      element.dataset.publicRevealDone = "true";

      const finish = (event) => {
        if (event.target !== element || !event.animationName.startsWith("publicReveal")) return;
        element.classList.remove("public-reveal-enter");
        element.removeEventListener("animationend", finish);
      };
      element.addEventListener("animationend", finish);
    });
  }, {
    root: null,
    rootMargin: "0px 0px -96px 0px",
    threshold: 0,
  });

  const watch = (node) => {
    if (!(node instanceof Element)) return;
    const candidates = [];
    if (node.matches(selector)) candidates.push(node);
    candidates.push(...node.querySelectorAll(selector));

    candidates.forEach((element) => {
      if (element.dataset.publicRevealDone === "true") return;
      if (element.classList.contains("public-reveal-pending") ||
          element.classList.contains("public-reveal-enter")) return;
      element.classList.add("public-reveal-pending");
      observer.observe(element);
    });
  };

  document.querySelectorAll(selector).forEach(watch);

  const additionsObserver = new MutationObserver((records) => {
    records.forEach((record) => record.addedNodes.forEach(watch));
  });
  additionsObserver.observe(document.body, { childList: true, subtree: true });

  motionPreference?.addEventListener?.("change", (event) => {
    if (!event.matches) return;
    observer.disconnect();
    additionsObserver.disconnect();
    document.querySelectorAll(".public-reveal-pending, .public-reveal-enter").forEach((element) => {
      element.classList.remove("public-reveal-pending", "public-reveal-enter");
    });
  });
}

document.addEventListener("DOMContentLoaded", () => {
  initPublicToastRegion();
  initPublicThemeToggles();
  initPublicNavbar();
  initPublicReveals();
});
