/*
 * HealthBridge Patient Workspace — Public V2 shell controller.
 * Owns authenticated patient navigation and shell state.
 * Depends only on the Public V2 shared site.js runtime.
 */
"use strict";

(() => {
  const ROOT = typeof getBasePath === "function" ? getBasePath() : "../../";
  const currentPage = window.location.pathname.split("/").pop() || "dashboard.html";
  const sharedWordmark = '<span class="hb-wordmark">' +
    Array.from("HealthBridge").map((letter) =>
      '<span class="hb-wordmark__letter" data-letter="' + letter + '">' + letter + '</span>'
    ).join("") +
    '<span class="hb-wordmark__shimmer" aria-hidden="true">HealthBridge</span></span>';

  function sharedBrandMarkup(href, modifierClass = "") {
    return '<a class="logo ' + modifierClass + '" href="' + href + '" aria-label="HealthBridge patient workspace home">' +
      '<div class="logo-icon"><svg class="auth-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 21V4h16v17M8 8h2m4 0h2M8 12h2m4 0h2M9 21v-4h6v4M12 1v5m-3-3h6"></path></svg></div>' +
      sharedWordmark +
      '</a>';
  }

  window.HealthBridgeBrand = Object.freeze({ markup: sharedBrandMarkup });

  const icons = {
    dashboard: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="8" height="8" rx="1.5"/><rect x="13" y="3" width="8" height="5" rx="1.5"/><rect x="13" y="10" width="8" height="11" rx="1.5"/><rect x="3" y="13" width="8" height="8" rx="1.5"/></svg>',
    appointments: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18M8 14h3M8 17h7"/></svg>',
    history: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5M12 7v5l3 2"/></svg>',
    prescriptions: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 3h8a2 2 0 0 1 2 2v2H6V5a2 2 0 0 1 2-2Z"/><path d="M6 7h12v12a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V7Z"/><path d="M12 10v6M9 13h6"/></svg>',
    notifications: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg>',
    documents: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M13 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V10Z"/><path d="M13 3v7h7M8 14h8M8 17h6"/></svg>',
    profile: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="3.5"/><path d="M5 21v-1.5a7 7 0 0 1 14 0V21Z"/></svg>',
    settings: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="6.2"/><circle cx="12" cy="12" r="2.6"/><path d="M12 2.6v3M12 18.4v3M2.6 12h3M18.4 12h3M5.3 5.3l2.1 2.1M16.6 16.6l2.1 2.1M18.7 5.3l-2.1 2.1M7.4 16.6l-2.1 2.1"/></svg>',
    site: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg>',
    bell: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg>',
    chevron: '<svg class="patient-profile-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>',
    menu: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>',
  };

  function renderHeader(container) {
    container.innerHTML =
      '<header class="patient-topbar">' +
        window.HealthBridgeBrand.markup("dashboard.html", "patient-topbar__brand") +
        '<div class="patient-topbar__content"><span class="patient-workspace-kicker">PATIENT WORKSPACE</span><div class="patient-topbar__actions">' +
          '<div class="patient-notification-menu"><button class="patient-icon-button" id="patient-notification-trigger" type="button" aria-label="Notifications" aria-haspopup="true" aria-expanded="false" aria-controls="patient-notification-dropdown">' + icons.bell + '<span class="patient-notification-badge" id="patient-notification-badge" hidden>0</span></button>' +
            '<div class="patient-notification-dropdown" id="patient-notification-dropdown" aria-hidden="true" inert><div class="patient-dropdown-heading">Notifications</div><p class="patient-notification-empty">Your latest updates will appear here.</p><a href="notifications.html">View all notifications</a></div></div>' +
          '<div class="patient-quick-menu"><button class="patient-icon-button" id="patient-quick-trigger" type="button" aria-label="Quick settings" aria-haspopup="true" aria-expanded="false" aria-controls="patient-quick-dropdown">' + icons.settings + '</button>' +
            '<div class="patient-quick-dropdown" id="patient-quick-dropdown" aria-hidden="true" inert><div class="patient-dropdown-heading">Quick settings</div><p class="patient-quick-label">Appearance</p><div class="patient-quick-themes"><button type="button" data-quick-theme="light">Light</button><button type="button" data-quick-theme="dark">Dark</button><button type="button" data-quick-theme="system">System</button></div><p class="patient-quick-saved" id="patient-quick-saved" role="status"></p><div class="patient-quick-links"><a href="notifications.html">' + icons.bell + '<span>Notifications</span></a><a href="settings.html">' + icons.settings + '<span>All settings</span></a></div></div></div>' +
          '<button class="patient-icon-button theme-toggle" type="button" aria-label="Change appearance" title="Change appearance"></button>' +
          '<div class="patient-profile-menu"><button class="patient-profile-trigger" id="patient-profile-trigger" type="button" aria-haspopup="true" aria-expanded="false" aria-controls="patient-profile-dropdown"><span class="patient-profile-avatar" id="patient-profile-avatar" aria-hidden="true">PT</span><span class="patient-profile-name" id="patient-profile-name">Patient</span>' + icons.chevron + '</button>' +
            '<div class="patient-profile-dropdown" id="patient-profile-dropdown" role="menu" aria-label="Patient account" aria-hidden="true" inert>' +
              '<a href="profile.html" role="menuitem" tabindex="-1">' + icons.profile + '<span>Profile</span></a><a href="settings.html" role="menuitem" tabindex="-1">' + icons.settings + '<span>Settings</span></a><div class="patient-profile-dropdown__divider" role="separator"></div><button type="button" class="is-danger" data-patient-action="logout" role="menuitem" tabindex="-1"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 17l5-5-5-5M15 12H3"/><path d="M12 3h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6"/></svg><span>Logout</span></button>' +
            '</div></div>' +
          '<button class="patient-sidebar-toggle" id="patient-sidebar-toggle" type="button" aria-label="Open workspace navigation" aria-controls="patient-sidebar" aria-expanded="false">' + icons.menu + '</button>' +
        '</div></div></header>';
  }

  const mainLinks = [
    ["dashboard.html", "Dashboard", "dashboard"],
    ["appointments.html", "Appointments", "appointments"],
    ["history.html", "Medical History", "history"],
    ["prescriptions.html", "Prescriptions", "prescriptions"],
    ["notifications.html", "Notifications", "notifications"],
    ["downloads.html", "Document Center", "documents"],
    ["profile.html", "Profile", "profile"],
    // Health Insights retired in Phase 5: profile completeness moved to the
    // Dashboard home and the medication tracker to Prescriptions.
  ];

  function renderSidebar(container) {
    const sidebar = document.createElement("aside");
    sidebar.className = "patient-sidebar";
    sidebar.id = "patient-sidebar";
    sidebar.setAttribute("aria-label", "Patient workspace navigation");
    const main = document.createElement("div");
    main.className = "patient-sidebar__main";
    const eyebrow = document.createElement("p");
    eyebrow.className = "patient-sidebar__eyebrow";
    eyebrow.textContent = "YOUR HEALTH";
    const nav = document.createElement("nav");
    nav.className = "patient-sidebar-nav";
    nav.setAttribute("aria-label", "Patient pages");

    mainLinks.forEach(([href, label, icon]) => {
      const link = document.createElement("a");
      link.href = href;
      link.dataset.routeKey = href;
      link.innerHTML = icons[icon] + "<span>" + label + "</span>";
      if (href === currentPage) {
        link.classList.add("is-active");
        link.setAttribute("aria-current", "page");
      }
      nav.append(link);
    });
    main.append(eyebrow, nav);

    const footer = document.createElement("nav");
    footer.className = "patient-sidebar__footer";
    footer.setAttribute("aria-label", "Workspace and site links");
    const settings = document.createElement("a");
    settings.href = "settings.html";
    settings.dataset.routeKey = "settings.html";
    settings.innerHTML = icons.settings + "<span>Settings</span>";
    if (currentPage === "settings.html") {
      settings.classList.add("is-active");
      settings.setAttribute("aria-current", "page");
      sidebar.classList.add("is-footer-active");
    }
    const viewSite = document.createElement("a");
    viewSite.href = ROOT + "index.html";
    viewSite.innerHTML = icons.site + "<span>View Site</span>";
    footer.append(settings, viewSite);
    sidebar.append(main, footer);

    const backdrop = document.createElement("button");
    backdrop.className = "patient-sidebar-backdrop";
    backdrop.type = "button";
    backdrop.tabIndex = -1;
    backdrop.setAttribute("aria-label", "Close workspace navigation");
    container.replaceChildren(sidebar, backdrop);
    return { sidebar, backdrop, navLinks: Array.from(nav.querySelectorAll("a")) };
  }

  function initials(name) {
    return String(name || "Patient").trim().split(/\s+/).filter(Boolean).slice(0, 2)
      .map((part) => part.charAt(0).toUpperCase()).join("") || "PT";
  }

  function updateIdentity(user) {
    const name = typeof user?.name === "string" && user.name.trim()
      ? user.name.trim().slice(0, 100) : "Patient";
    const nameNode = document.getElementById("patient-profile-name");
    const avatarNode = document.getElementById("patient-profile-avatar");
    if (nameNode) nameNode.textContent = name;
    if (avatarNode) avatarNode.textContent = initials(name);
  }

  function redirectToLogin() {
    try { localStorage.removeItem("hb_user"); } catch {}
    window.location.assign(ROOT + "pages/auth/login.html");
  }

  async function resolveIdentity() {
    const cached = typeof getUser === "function" ? getUser() : null;
    if (cached && cached.role && cached.role !== "patient") {
      window.location.assign(typeof getDashboardUrl === "function"
        ? getDashboardUrl(cached.role) : ROOT + "pages/auth/login.html");
      return null;
    }
    if (cached?.role === "patient") updateIdentity(cached);

    try {
      const response = await fetch(ROOT + "api/auth/current_user.php", {
        method: "GET", credentials: "same-origin", headers: { Accept: "application/json" },
      });
      const payload = await response.json().catch(() => null);
      const serverUser = payload?.success && payload.user && typeof payload.user === "object"
        ? payload.user : null;

      if (!response.ok || !serverUser) {
        if (response.status === 401 || response.status === 403) {
          redirectToLogin();
          return null;
        }
        if (cached?.role === "patient") return cached;
        redirectToLogin();
        return null;
      }

      if (serverUser.role !== "patient") {
        try { localStorage.setItem("hb_user", JSON.stringify(serverUser)); } catch {}
        window.location.assign(typeof getDashboardUrl === "function"
          ? getDashboardUrl(serverUser.role) : ROOT + "pages/auth/login.html");
        return null;
      }

      try {
        localStorage.setItem("hb_user", JSON.stringify({
          id: serverUser.id, name: serverUser.name, email: serverUser.email, role: serverUser.role,
        }));
      } catch {}
      updateIdentity(serverUser);
      return serverUser;
    } catch {
      if (cached?.role === "patient") return cached;
      redirectToLogin();
      return null;
    }
  }

  function buildRouteSkeleton(main) {
    if (!main || main.querySelector(".patient-route-skeleton")) return null;
    main.setAttribute("aria-busy", "true");
    const skeleton = document.createElement("div");
    skeleton.className = "patient-route-skeleton";
    skeleton.setAttribute("aria-hidden", "true");
    skeleton.innerHTML =
      '<div class="patient-route-skeleton__heading"></div>' +
      '<div class="patient-route-skeleton__subheading"></div>' +
      '<div class="patient-route-skeleton__grid"><span></span><span></span><span></span></div>' +
      '<div class="patient-route-skeleton__panel"></div>';
    main.prepend(skeleton);
    return skeleton;
  }

  function revealRoute(main, skeleton) {
    if (!main) return;
    main.setAttribute("aria-busy", "false");
    main.classList.add("patient-shell-ready");
    if (!skeleton) return;
    requestAnimationFrame(() => {
      skeleton.classList.add("is-leaving");
      window.setTimeout(() => skeleton.remove(), 210);
    });
  }

  function setActiveIndicator(navLinks) {
    const active = navLinks.find((link) => link.dataset.routeKey === currentPage);
    if (!active) return;
    const nav = active.parentElement;
    const measure = (link) =>
      link.getBoundingClientRect().top - nav.getBoundingClientRect().top + nav.scrollTop;
    const moveIndicator = () => {
      const nextY = measure(active);
      let previousKey = null;
      try { previousKey = sessionStorage.getItem("hb_patient_active_route"); } catch {}
      const previous = navLinks.find((link) => link.dataset.routeKey === previousKey);
      // Prime at the previous tab's position with the transition off, so
      // the bar never slides down from the top of the menu on page load —
      // only the previous → current leg animates, smoothly.
      nav.classList.add("is-priming");
      if (previous && previous !== active) {
        nav.style.setProperty("--patient-active-y", String(measure(previous)) + "px");
        void nav.offsetHeight;
        nav.classList.remove("is-priming");
        requestAnimationFrame(() =>
          nav.style.setProperty("--patient-active-y", String(nextY) + "px"));
      } else {
        nav.style.setProperty("--patient-active-y", String(nextY) + "px");
        void nav.offsetHeight;
        nav.classList.remove("is-priming");
      }
      try { sessionStorage.setItem("hb_patient_active_route", currentPage); } catch {}
    };
    moveIndicator();
    window.addEventListener("resize", moveIndicator, { passive: true });
  }

  /* Fills the header bell: unread badge (pulses via CSS when it first
     appears) and the four latest notifications in the dropdown.
     Uses the same endpoint as the Notifications page, so they agree. */
  function populateNotifications() {
    const badge = document.getElementById("patient-notification-badge");
    const dropdown = document.getElementById("patient-notification-dropdown");
    if (!badge && !dropdown) return;
    fetch(ROOT + "api/notifications/notifications.php", {
      method: "GET", credentials: "same-origin", headers: { Accept: "application/json" },
    })
      .then((response) => response.json().catch(() => null))
      .then((payload) => {
        const items = Array.isArray(payload?.notifications) ? payload.notifications : [];
        const unread = items.filter((item) => Number(item.is_read) === 0).length;
        if (badge) {
          badge.hidden = unread === 0;
          badge.textContent = unread > 99 ? "99+" : String(unread);
        }
        if (dropdown && items.length) {
          dropdown.querySelector(".patient-notification-empty")?.remove();
          const viewAll = dropdown.querySelector("a");
          items.slice(0, 4).forEach((item) => {
            const row = document.createElement("a");
            row.href = "notifications.html";
            row.className = "patient-notification-item" +
              (Number(item.is_read) === 0 ? " is-unread" : "");
            const title = document.createElement("strong");
            title.textContent = String(item.title || "Notification");
            const when = document.createElement("span");
            const date = new Date(String(item.created_at || "").replace(" ", "T"));
            when.textContent = Number.isNaN(date.getTime()) ? "" : date.toLocaleDateString(undefined, {
              month: "short", day: "numeric",
            });
            row.append(title, when);
            dropdown.insertBefore(row, viewAll);
          });
        }
      })
      .catch(() => { /* the badge simply stays hidden */ });
  }

  function bindMenus() {
    const profileTrigger = document.getElementById("patient-profile-trigger");
    const profileDropdown = document.getElementById("patient-profile-dropdown");
    const noticeTrigger = document.getElementById("patient-notification-trigger");
    const noticeDropdown = document.getElementById("patient-notification-dropdown");
    const quickTrigger = document.getElementById("patient-quick-trigger");
    const quickDropdown = document.getElementById("patient-quick-dropdown");

    const setMenu = (trigger, menu, open) => {
      if (!trigger || !menu) return;
      menu.classList.toggle("is-open", open);
      menu.setAttribute("aria-hidden", String(!open));
      menu.inert = !open;
      trigger.setAttribute("aria-expanded", String(open));
    };
    const closeMenus = () => {
      setMenu(profileTrigger, profileDropdown, false);
      setMenu(noticeTrigger, noticeDropdown, false);
      setMenu(quickTrigger, quickDropdown, false);
    };

    profileTrigger?.addEventListener("click", () => {
      const open = profileTrigger.getAttribute("aria-expanded") !== "true";
      setMenu(noticeTrigger, noticeDropdown, false);
      setMenu(quickTrigger, quickDropdown, false);
      setMenu(profileTrigger, profileDropdown, open);
    });

    quickTrigger?.addEventListener("click", () => {
      const open = quickTrigger.getAttribute("aria-expanded") !== "true";
      setMenu(profileTrigger, profileDropdown, false);
      setMenu(noticeTrigger, noticeDropdown, false);
      setMenu(quickTrigger, quickDropdown, open);
    });

    profileTrigger?.addEventListener("keydown", (event) => {
      if (!["ArrowDown", "ArrowUp", "Enter", " "].includes(event.key)) return;
      if (profileTrigger.getAttribute("aria-expanded") === "true") return;
      event.preventDefault();
      setMenu(noticeTrigger, noticeDropdown, false);
      setMenu(profileTrigger, profileDropdown, true);
      const items = Array.from(profileDropdown?.querySelectorAll("[role='menuitem']") || []);
      const target = event.key === "ArrowUp" ? items[items.length - 1] : items[0];
      target?.focus({ preventScroll: true });
    });

    profileDropdown?.addEventListener("keydown", (event) => {
      const items = Array.from(profileDropdown.querySelectorAll("[role='menuitem']"));
      if (!items.length) return;
      if (event.key === "Tab") {
        setMenu(profileTrigger, profileDropdown, false);
        return;
      }
      const currentIndex = items.indexOf(document.activeElement);
      let nextIndex = null;
      if (event.key === "ArrowDown") nextIndex = (currentIndex + 1 + items.length) % items.length;
      if (event.key === "ArrowUp") nextIndex = (currentIndex - 1 + items.length) % items.length;
      if (event.key === "Home") nextIndex = 0;
      if (event.key === "End") nextIndex = items.length - 1;
      if (nextIndex !== null) {
        event.preventDefault();
        items[nextIndex].focus();
      }
    });
    noticeTrigger?.addEventListener("click", () => {
      const open = noticeTrigger.getAttribute("aria-expanded") !== "true";
      setMenu(profileTrigger, profileDropdown, false);
      setMenu(quickTrigger, quickDropdown, false);
      setMenu(noticeTrigger, noticeDropdown, open);
    });

    document.addEventListener("pointerdown", (event) => {
      const target = event.target;
      if (target instanceof Node &&
          !profileDropdown?.contains(target) && !profileTrigger?.contains(target) &&
          !noticeDropdown?.contains(target) && !noticeTrigger?.contains(target) &&
          !quickDropdown?.contains(target) && !quickTrigger?.contains(target)) closeMenus();
    });

    document.addEventListener("click", (event) => {
      const action = event.target instanceof Element
        ? event.target.closest("[data-patient-action]") : null;
      if (action?.dataset.patientAction === "logout") {
        event.preventDefault();
        if (typeof window.logoutUser === "function") window.logoutUser();
        else redirectToLogin();
      }
    });
    return closeMenus;
  }

  /* Quick-settings panel (from the header gear): appearance only, plus
     shortcuts. The full Settings page remains the single place for
     everything else — this panel deliberately holds no forms. */
  function bindQuickSettings() {
    const buttons = Array.from(document.querySelectorAll("[data-quick-theme]"));
    if (!buttons.length) return;
    const saved = document.getElementById("patient-quick-saved");
    let savedTimer = null;
    const mark = (value) => buttons.forEach((button) =>
      button.classList.toggle("is-selected", button.dataset.quickTheme === value));
    const flash = () => {
      if (!saved) return;
      saved.textContent = "Appearance saved ✓";
      saved.classList.add("is-visible");
      window.clearTimeout(savedTimer);
      savedTimer = window.setTimeout(() => saved.classList.remove("is-visible"), 2200);
    };

    fetch(ROOT + "api/settings/get.php", {
      credentials: "same-origin",
      headers: { Accept: "application/json" },
    })
      .then((response) => response.json().catch(() => null))
      .then((payload) => {
        const theme = payload?.preferences?.theme;
        if (theme === "light" || theme === "dark" || theme === "system") {
          mark(theme);
          return;
        }
        throw new Error("no saved preference");
      })
      .catch(() => {
        const local = localStorage.getItem("hb_theme");
        mark(local === "light" || local === "dark" ? local : "system");
      });

    buttons.forEach((button) => {
      button.addEventListener("click", async () => {
        const value = button.dataset.quickTheme;
        if (typeof applyTheme === "function") {
          if (value === "system") {
            const osDark = window.matchMedia("(prefers-color-scheme: dark)").matches;
            applyTheme(osDark ? "dark" : "light");
            localStorage.removeItem("hb_theme");
          } else {
            applyTheme(value);
          }
        }
        mark(value);
        try {
          const response = await fetch(ROOT + "api/settings/update.php", {
            method: "POST",
            credentials: "same-origin",
            headers: { "Content-Type": "application/json", Accept: "application/json" },
            body: JSON.stringify({ section: "appearance", theme: value }),
          });
          const payload = await response.json().catch(() => null);
          if (response.ok && payload?.success) flash();
        } catch { /* the theme is still applied locally */ }
      });
    });
  }

  function bindMobileSidebar(parts) {
    const toggle = document.getElementById("patient-sidebar-toggle");
    const close = () => {
      document.body.classList.remove("patient-sidebar-open");
      toggle?.setAttribute("aria-expanded", "false");
      toggle?.setAttribute("aria-label", "Open workspace navigation");
    };
    toggle?.addEventListener("click", () => {
      const open = !document.body.classList.contains("patient-sidebar-open");
      document.body.classList.toggle("patient-sidebar-open", open);
      toggle.setAttribute("aria-expanded", String(open));
      toggle.setAttribute("aria-label", open ? "Close workspace navigation" : "Open workspace navigation");
      if (open) parts.sidebar.querySelector("a.is-active")?.focus({ preventScroll: true });
    });
    parts.backdrop.addEventListener("click", close);
    parts.sidebar.querySelectorAll("a").forEach((link) => link.addEventListener("click", close));
    return close;
  }

  async function init() {
    document.body.classList.add("patient-workspace");
    const headerContainer = document.getElementById("navbar-container");
    const sidebarContainer = document.getElementById("sidebar-container");
    const main = document.querySelector(".main-content");
    if (!headerContainer || !sidebarContainer || !main) return;

    const skeleton = buildRouteSkeleton(main);
    renderHeader(headerContainer);
    const sidebarParts = renderSidebar(sidebarContainer);
    setActiveIndicator(sidebarParts.navLinks);
    const closeMenus = bindMenus();
    bindQuickSettings();
    const closeSidebar = bindMobileSidebar(sidebarParts);

    document.addEventListener("keydown", (event) => {
      if (event.key !== "Escape") return;
      closeMenus();
      closeSidebar();
      document.getElementById("patient-profile-trigger")?.focus({ preventScroll: true });
    });

    const user = await resolveIdentity();
    if (!user) return;
    revealRoute(main, skeleton);
    document.dispatchEvent(new CustomEvent("patient:shell-ready", {
      detail: { user: { id: user.id, role: user.role } },
    }));
    populateNotifications();
  }

  init();
})();