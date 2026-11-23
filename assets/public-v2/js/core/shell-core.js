/*
 * HealthBridge Workspace Shell Core — Public V2.
 *
 * The single implementation of the dashboard shell shared by the
 * patient and doctor workspaces: header, sidebar (desktop collapse
 * + mobile off-canvas), header bell, account menu, identity gate and
 * route reveal. patient-shell.js and doctor-shell.js carry no logic
 * of their own — they only call HealthBridgeShell.create() with the
 * per-workspace configuration (class prefix, role, route table,
 * labels, storage keys), so a shell fix lands once, here.
 *
 * Every rendered class/id is built from the configured prefix, which
 * is why the two dashboard CSS files keep matching unchanged.
 * Depends on the Public V2 shared site.js runtime.
 */
"use strict";

(() => {
  function createShell(config) {
    const p = config.prefix;
    const ROOT = typeof getBasePath === "function" ? getBasePath() : "../../";
    const currentPage = window.location.pathname.split("/").pop() || config.homePage;
    const sharedWordmark = '<span class="hb-wordmark">' +
      Array.from("HealthBridge").map((letter) =>
        '<span class="hb-wordmark__letter" data-letter="' + letter + '">' + letter + '</span>'
      ).join("") +
      '<span class="hb-wordmark__shimmer" aria-hidden="true">HealthBridge</span></span>';

    function sharedBrandMarkup(href, modifierClass = "") {
      return '<a class="logo ' + modifierClass + '" href="' + href + '" aria-label="' + config.brandLabel + '">' +
        '<div class="logo-icon"><svg class="auth-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 21V4h16v17M8 8h2m4 0h2M8 12h2m4 0h2M9 21v-4h6v4M12 1v5m-3-3h6"></path></svg></div>' +
        sharedWordmark +
        '</a>';
    }

    window.HealthBridgeBrand = Object.freeze({ markup: sharedBrandMarkup });

    const icons = {
      dashboard: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="8" height="8" rx="1.5"/><rect x="13" y="3" width="8" height="5" rx="1.5"/><rect x="13" y="10" width="8" height="11" rx="1.5"/><rect x="3" y="13" width="8" height="8" rx="1.5"/></svg>',
      appointments: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18M8 14h3M8 17h7"/></svg>',
      queue: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="8" r="3.2"/><path d="M3.5 20v-1a5.5 5.5 0 0 1 11 0v1"/><path d="M16 5.4a3.2 3.2 0 0 1 0 5.9M17.8 14.6a5.5 5.5 0 0 1 2.7 4.4v1"/></svg>',
      schedule: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/><circle cx="12" cy="15.5" r="2.6"/><path d="M12 14v1.5l1 .8"/></svg>',
      reviews: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m12 3.5 2.6 5.3 5.9.9-4.3 4.1 1 5.8-5.2-2.7-5.2 2.7 1-5.8L3.5 9.7l5.9-.9L12 3.5Z"/></svg>',
      history: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5M12 7v5l3 2"/></svg>',
      prescriptions: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 3h8a2 2 0 0 1 2 2v2H6V5a2 2 0 0 1 2-2Z"/><path d="M6 7h12v12a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V7Z"/><path d="M12 10v6M9 13h6"/></svg>',
      notifications: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg>',
      documents: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M13 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V10Z"/><path d="M13 3v7h7M8 14h8M8 17h6"/></svg>',
      profile: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="3.5"/><path d="M5 21v-1.5a7 7 0 0 1 14 0V21Z"/></svg>',
      settings: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="6.2"/><circle cx="12" cy="12" r="2.6"/><path d="M12 2.6v3M12 18.4v3M2.6 12h3M18.4 12h3M5.3 5.3l2.1 2.1M16.6 16.6l2.1 2.1M18.7 5.3l-2.1 2.1M7.4 16.6l-2.1 2.1"/></svg>',
      site: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg>',
      bell: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg>',
      markAll: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 7 17l-5-5"/><path d="m22 10-7.5 7.5L13 16"/></svg>',
      trash: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M10 11v6M14 11v6"/></svg>',
      dismiss: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>',
      chevron: '<svg class="' + p + '-profile-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>',
      menu: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>',
      collapse: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14.5 5.5 8 12l6.5 6.5"/></svg>',
    };

    function renderHeader(container) {
      container.innerHTML =
        '<header class="' + p + '-topbar">' +
          window.HealthBridgeBrand.markup(config.homePage, p + "-topbar__brand") +
          '<div class="' + p + '-topbar__content"><span class="' + p + '-workspace-kicker">' + config.kicker + '</span><div class="' + p + '-topbar__actions">' +
            '<div class="' + p + '-notification-menu"><button class="' + p + '-icon-button" id="' + p + '-notification-trigger" type="button" aria-label="Notifications" aria-haspopup="true" aria-expanded="false" aria-controls="' + p + '-notification-dropdown">' + icons.bell + '<span class="' + p + '-notification-badge" id="' + p + '-notification-badge" hidden>0</span></button>' +
              '<div class="' + p + '-notification-dropdown" id="' + p + '-notification-dropdown" aria-hidden="true" inert><div class="' + p + '-dropdown-heading"><span>Notifications</span><span class="' + p + '-dropdown-actions"><button type="button" class="' + p + '-dropdown-action" id="' + p + '-bell-mark-all" aria-label="Mark all as read" title="Mark all as read">' + icons.markAll + '</button><button type="button" class="' + p + '-dropdown-action" id="' + p + '-bell-dismiss-all" aria-label="Dismiss all" title="Dismiss all">' + icons.trash + '</button></span></div><p class="' + p + '-notification-empty">Your latest updates will appear here.</p><a class="' + p + '-dropdown-viewall" href="notifications.html">View all notifications</a></div></div>' +
            '<a class="' + p + '-icon-button" href="settings.html" aria-label="Settings" title="Settings">' + icons.settings + '</a>' +
            '<button class="' + p + '-icon-button theme-toggle" type="button" aria-label="Change appearance" title="Change appearance"></button>' +
            '<div class="' + p + '-profile-menu"><button class="' + p + '-profile-trigger" id="' + p + '-profile-trigger" type="button" aria-haspopup="true" aria-expanded="false" aria-controls="' + p + '-profile-dropdown"><span class="' + p + '-profile-avatar" id="' + p + '-profile-avatar" aria-hidden="true">' + config.fallbackInitials + '</span><span class="' + p + '-profile-name" id="' + p + '-profile-name">' + config.fallbackName + '</span>' + icons.chevron + '</button>' +
              '<div class="' + p + '-profile-dropdown" id="' + p + '-profile-dropdown" role="menu" aria-label="' + config.accountLabel + '" aria-hidden="true" inert>' +
                '<a href="profile.html" role="menuitem" tabindex="-1">' + icons.profile + '<span>Profile</span></a><a href="settings.html" role="menuitem" tabindex="-1">' + icons.settings + '<span>Settings</span></a><div class="' + p + '-profile-dropdown__divider" role="separator"></div><button type="button" class="is-danger" data-' + p + '-action="logout" role="menuitem" tabindex="-1"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 17l5-5-5-5M15 12H3"/><path d="M12 3h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6"/></svg><span>Logout</span></button>' +
              '</div></div>' +
            '<button class="' + p + '-sidebar-toggle" id="' + p + '-sidebar-toggle" type="button" aria-label="Open workspace navigation" aria-controls="' + p + '-sidebar" aria-expanded="false">' + icons.menu + '</button>' +
          '</div></div></header>';
    }

    function renderSidebar(container) {
      const sidebar = document.createElement("aside");
      sidebar.className = p + "-sidebar";
      sidebar.id = p + "-sidebar";
      sidebar.setAttribute("aria-label", config.sidebarLabel);
      const main = document.createElement("div");
      main.className = p + "-sidebar__main";
      const eyebrow = document.createElement("p");
      eyebrow.className = p + "-sidebar__eyebrow";
      eyebrow.textContent = config.eyebrow;
      const nav = document.createElement("nav");
      nav.className = p + "-sidebar-nav";
      nav.setAttribute("aria-label", config.navLabel);

      config.links.forEach(([href, label, icon]) => {
        const link = document.createElement("a");
        link.href = href;
        link.dataset.routeKey = href;
        link.dataset.tip = label;
        link.innerHTML = icons[icon] + "<span>" + label + "</span>";
        if (href === currentPage) {
          link.classList.add("is-active");
          link.setAttribute("aria-current", "page");
        }
        nav.append(link);
      });
      main.append(eyebrow, nav);

      const footer = document.createElement("nav");
      footer.className = p + "-sidebar__footer";
      footer.setAttribute("aria-label", "Workspace and site links");
      const sbToggle = document.createElement("button");
      sbToggle.type = "button";
      sbToggle.className = p + "-sb-toggle";
      sbToggle.id = p + "-sb-toggle";
      sbToggle.dataset.tip = "Collapse";
      sbToggle.innerHTML = icons.collapse + "<span>Collapse</span>";
      const settings = document.createElement("a");
      settings.href = "settings.html";
      settings.dataset.routeKey = "settings.html";
      settings.dataset.tip = "Settings";
      settings.innerHTML = icons.settings + "<span>Settings</span>";
      if (currentPage === "settings.html") {
        settings.classList.add("is-active");
        settings.setAttribute("aria-current", "page");
        sidebar.classList.add("is-footer-active");
      }
      const viewSite = document.createElement("a");
      viewSite.href = ROOT + "index.html";
      viewSite.dataset.tip = "View Site";
      viewSite.innerHTML = icons.site + "<span>View Site</span>";
      footer.append(sbToggle, settings, viewSite);
      sidebar.append(main, footer);

      const backdrop = document.createElement("button");
      backdrop.className = p + "-sidebar-backdrop";
      backdrop.type = "button";
      backdrop.tabIndex = -1;
      backdrop.setAttribute("aria-label", "Close workspace navigation");
      container.replaceChildren(sidebar, backdrop);
      return { sidebar, backdrop, sbToggle, navLinks: Array.from(nav.querySelectorAll("a")) };
    }

    function initials(name) {
      return String(name || config.fallbackName).trim().split(/\s+/).filter(Boolean).slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase()).join("") || config.fallbackInitials;
    }

    function updateIdentity(user) {
      const name = typeof user?.name === "string" && user.name.trim()
        ? user.name.trim().slice(0, 100) : config.fallbackName;
      const nameNode = document.getElementById(p + "-profile-name");
      const avatarNode = document.getElementById(p + "-profile-avatar");
      if (nameNode) nameNode.textContent = name;
      if (avatarNode) avatarNode.textContent = initials(name);
    }

    function redirectToLogin() {
      try { localStorage.removeItem("hb_user"); } catch {}
      window.location.assign(ROOT + "pages/auth/login.html");
    }

    async function resolveIdentity() {
      const cached = typeof getUser === "function" ? getUser() : null;
      if (cached && cached.role && cached.role !== config.role) {
        window.location.assign(typeof getDashboardUrl === "function"
          ? getDashboardUrl(cached.role) : ROOT + "pages/auth/login.html");
        return null;
      }
      if (cached?.role === config.role) updateIdentity(cached);

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
          if (cached?.role === config.role) return cached;
          redirectToLogin();
          return null;
        }

        if (serverUser.role !== config.role) {
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
        if (cached?.role === config.role) return cached;
        redirectToLogin();
        return null;
      }
    }

    function buildRouteSkeleton(main) {
      if (!main || main.querySelector("." + p + "-route-skeleton")) return null;
      main.setAttribute("aria-busy", "true");
      const skeleton = document.createElement("div");
      skeleton.className = p + "-route-skeleton";
      skeleton.setAttribute("aria-hidden", "true");
      skeleton.innerHTML =
        '<div class="' + p + '-route-skeleton__heading"></div>' +
        '<div class="' + p + '-route-skeleton__subheading"></div>' +
        '<div class="' + p + '-route-skeleton__grid"><span></span><span></span><span></span></div>' +
        '<div class="' + p + '-route-skeleton__panel"></div>';
      main.prepend(skeleton);
      return skeleton;
    }

    function revealRoute(main, skeleton) {
      if (!main) return;
      main.setAttribute("aria-busy", "false");
      main.classList.add(p + "-shell-ready");
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
        try { previousKey = sessionStorage.getItem(config.activeRouteKey); } catch {}
        const previous = navLinks.find((link) => link.dataset.routeKey === previousKey);
        // Prime at the previous tab's position with the transition off, so
        // the bar never slides down from the top of the menu on page load —
        // only the previous → current leg animates, smoothly.
        nav.classList.add("is-priming");
        if (previous && previous !== active) {
          nav.style.setProperty("--" + p + "-active-y", String(measure(previous)) + "px");
          void nav.offsetHeight;
          nav.classList.remove("is-priming");
          requestAnimationFrame(() =>
            nav.style.setProperty("--" + p + "-active-y", String(nextY) + "px"));
        } else {
          nav.style.setProperty("--" + p + "-active-y", String(nextY) + "px");
          void nav.offsetHeight;
          nav.classList.remove("is-priming");
        }
        try { sessionStorage.setItem(config.activeRouteKey, currentPage); } catch {}
      };
      moveIndicator();
      window.addEventListener("resize", moveIndicator, { passive: true });
    }

    /* Pop-up dismissals are view-only: dismissed notification ids live
       in localStorage under a per-area, per-user key, so a dismissed
       item leaves the pop-up but stays in the Notifications page with
       its read state untouched, and one account's dismissals never
       hide another's. The set is capped so it cannot grow unbounded. */
    function bellDismissKey() {
      const user = typeof getUser === "function" ? getUser() : null;
      return "hb_bell_dismissed:" + p + ":" + (user?.id ?? "unknown");
    }
    function loadBellDismissed() {
      try {
        const parsed = JSON.parse(localStorage.getItem(bellDismissKey()) || "[]");
        return new Set(Array.isArray(parsed)
          ? parsed.map(Number).filter(Number.isFinite) : []);
      } catch { return new Set(); }
    }
    function saveBellDismissed(set) {
      try {
        localStorage.setItem(bellDismissKey(), JSON.stringify([...set].slice(-200)));
      } catch {}
    }
    function syncBellEmpty(dropdown) {
      const empty = dropdown?.querySelector("." + p + "-notification-empty");
      if (!empty) return;
      const hasRows = Boolean(dropdown.querySelector("." + p + "-notification-row"));
      empty.hidden = hasRows;
      if (!hasRows) empty.textContent = "No new notifications";
    }

    /* The pop-up header's "Mark all as read" control marks the
       notifications read on the server and clears the badge in place;
       the items stay listed, only their unread styling goes. Opening
       the pop-up never marks anything by itself. bellUnread mirrors
       the count populateNotifications() last computed, so the POST only
       fires when there is something to clear. */
    let bellUnread = 0;
    let bellMarking = false;
    function markBellNotificationsRead() {
      if (bellMarking || bellUnread === 0) return;
      bellMarking = true;
      fetch(ROOT + "api/notifications/notifications.php", {
        method: "POST", credentials: "same-origin",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({ action: "mark_all_read" }),
      })
        .then((response) => response.json().catch(() => null))
        .then((payload) => {
          if (!payload || payload.success === false) { bellMarking = false; return; }
          bellUnread = 0;
          const badge = document.getElementById(p + "-notification-badge");
          if (badge) { badge.hidden = true; badge.textContent = "0"; }
          document.querySelectorAll("." + p + "-notification-item.is-unread")
            .forEach((row) => row.classList.remove("is-unread"));
        })
        .catch(() => { bellMarking = false; });
    }

    /* Fills the header bell: unread badge (pulses via CSS when it first
       appears) and the four latest notifications in the dropdown.
       Uses the same endpoint as the Notifications page, so they agree. */
    function populateNotifications() {
      const badge = document.getElementById(p + "-notification-badge");
      const dropdown = document.getElementById(p + "-notification-dropdown");
      if (!badge && !dropdown) return;
      fetch(ROOT + "api/notifications/notifications.php", {
        method: "GET", credentials: "same-origin", headers: { Accept: "application/json" },
      })
        .then((response) => response.json().catch(() => null))
        .then((payload) => {
          const items = Array.isArray(payload?.notifications) ? payload.notifications : [];
          const unread = items.filter((item) => Number(item.is_read) === 0).length;
          bellUnread = unread;
          if (badge) {
            badge.hidden = unread === 0;
            badge.textContent = unread > 99 ? "99+" : String(unread);
          }
          if (dropdown) {
            const dismissed = loadBellDismissed();
            const viewAll = dropdown.querySelector("." + p + "-dropdown-viewall");
            items.slice(0, 4).forEach((item) => {
              const id = Number(item.id);
              if (dismissed.has(id)) return;
              const row = document.createElement("div");
              row.className = p + "-notification-row";
              row.dataset.notificationId = String(id);
              const link = document.createElement("a");
              link.href = "notifications.html";
              link.className = p + "-notification-item" +
                (Number(item.is_read) === 0 ? " is-unread" : "");
              const title = document.createElement("strong");
              title.textContent = String(item.title || "Notification");
              const when = document.createElement("span");
              const date = new Date(String(item.created_at || "").replace(" ", "T"));
              when.textContent = Number.isNaN(date.getTime()) ? "" : date.toLocaleDateString(undefined, {
                month: "short", day: "numeric",
              });
              link.append(title, when);
              const dismiss = document.createElement("button");
              dismiss.type = "button";
              dismiss.className = p + "-notification-dismiss";
              dismiss.setAttribute("aria-label", "Dismiss notification");
              dismiss.title = "Dismiss";
              dismiss.innerHTML = icons.dismiss;
              dismiss.addEventListener("click", () => {
                const set = loadBellDismissed();
                set.add(id);
                saveBellDismissed(set);
                row.remove();
                syncBellEmpty(dropdown);
              });
              row.append(link, dismiss);
              dropdown.insertBefore(row, viewAll);
            });
            syncBellEmpty(dropdown);
          }
        })
        .catch(() => { /* the badge simply stays hidden */ });
    }

    function bindMenus() {
      const profileTrigger = document.getElementById(p + "-profile-trigger");
      const profileDropdown = document.getElementById(p + "-profile-dropdown");
      const noticeTrigger = document.getElementById(p + "-notification-trigger");
      const noticeDropdown = document.getElementById(p + "-notification-dropdown");

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
      };

      profileTrigger?.addEventListener("click", () => {
        const open = profileTrigger.getAttribute("aria-expanded") !== "true";
        setMenu(noticeTrigger, noticeDropdown, false);
        setMenu(profileTrigger, profileDropdown, open);
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
        setMenu(noticeTrigger, noticeDropdown, open);
      });

      document.getElementById(p + "-bell-mark-all")
        ?.addEventListener("click", () => markBellNotificationsRead());
      document.getElementById(p + "-bell-dismiss-all")
        ?.addEventListener("click", () => {
          if (!noticeDropdown) return;
          const set = loadBellDismissed();
          noticeDropdown.querySelectorAll("." + p + "-notification-row")
            .forEach((row) => {
              const id = Number(row.dataset.notificationId);
              if (Number.isFinite(id)) set.add(id);
              row.remove();
            });
          saveBellDismissed(set);
          syncBellEmpty(noticeDropdown);
        });

      document.addEventListener("pointerdown", (event) => {
        const target = event.target;
        if (target instanceof Node &&
            !profileDropdown?.contains(target) && !profileTrigger?.contains(target) &&
            !noticeDropdown?.contains(target) && !noticeTrigger?.contains(target)) closeMenus();
      });

      document.addEventListener("click", (event) => {
        const action = event.target instanceof Element
          ? event.target.closest("[data-" + p + "-action]") : null;
        if (action?.dataset[p + "Action"] === "logout") {
          event.preventDefault();
          if (typeof window.logoutUser === "function") window.logoutUser();
          else redirectToLogin();
        }
      });
      return closeMenus;
    }


    /* Desktop collapse: the sidebar folds to a 72px icon rail and the
       content area widens into the freed space. The control is a row at
       the top of the sidebar footer (rendered by renderSidebar): chevron
       + "Collapse" while expanded; in the rail only the centred chevron
       remains, turned to point right, with an "Expand" tooltip. The state
       persists in localStorage (the configured sidebar key); each page's
       head snippet applies it to <html> before first paint, and this
       binding re-applies it in case the snippet was blocked, so class
       and storage never disagree. On phone widths the row is hidden by
       CSS and the off-canvas mobile pattern (bindMobileSidebar) rules
       instead. */
    function bindSidebarCollapse(parts) {
      const btn = parts.sbToggle;
      if (!btn) return null;
      const label = btn.querySelector("span");
      const sync = () => {
        const collapsed = document.documentElement.classList.contains(p + "-sb-collapsed");
        btn.setAttribute("aria-expanded", String(!collapsed));
        btn.setAttribute("aria-label", collapsed ? "Expand sidebar" : "Collapse sidebar");
        btn.dataset.tip = collapsed ? "Expand" : "Collapse";
        if (label) label.textContent = collapsed ? "Expand" : "Collapse";
      };
      try {
        if (localStorage.getItem(config.sidebarKey) === "collapsed") {
          document.documentElement.classList.add(p + "-sb-collapsed");
        }
      } catch {}
      btn.addEventListener("click", () => {
        const collapsed = document.documentElement.classList.toggle(p + "-sb-collapsed");
        try { localStorage.setItem(config.sidebarKey, collapsed ? "collapsed" : "expanded"); } catch {}
        sync();
        // Sidebar width changed the layout the route indicator measures
        // against; let its resize listener re-measure.
        window.dispatchEvent(new Event("resize"));
      });
      sync();
      return btn;
    }

    function bindMobileSidebar(parts) {
      const toggle = document.getElementById(p + "-sidebar-toggle");
      const close = () => {
        document.body.classList.remove(p + "-sidebar-open");
        toggle?.setAttribute("aria-expanded", "false");
        toggle?.setAttribute("aria-label", "Open workspace navigation");
      };
      toggle?.addEventListener("click", () => {
        const open = !document.body.classList.contains(p + "-sidebar-open");
        document.body.classList.toggle(p + "-sidebar-open", open);
        toggle.setAttribute("aria-expanded", String(open));
        toggle.setAttribute("aria-label", open ? "Close workspace navigation" : "Open workspace navigation");
        if (open) parts.sidebar.querySelector("a.is-active")?.focus({ preventScroll: true });
      });
      parts.backdrop.addEventListener("click", close);
      parts.sidebar.querySelectorAll("a").forEach((link) => link.addEventListener("click", close));
      return close;
    }

    async function init() {
      /* Bell badge pulse gating: pulse once per AREA entry, not on every
         page load. Shared hb_bell_area session key with the public site
         and the other shell; .hb-bell-still suppresses this load's pulse
         on within-area navigation (see the dashboard CSS files). Set
         here, before populateNotifications() can unhide the badge. */
      try {
        if (sessionStorage.getItem("hb_bell_area") === p) {
          document.documentElement.classList.add("hb-bell-still");
        }
        sessionStorage.setItem("hb_bell_area", p);
      } catch { /* Storage unavailable: leave the pulse allowed. */ }
      document.body.classList.add(p + "-workspace");
      const headerContainer = document.getElementById("navbar-container");
      const sidebarContainer = document.getElementById("sidebar-container");
      const main = document.querySelector(".main-content");
      if (!headerContainer || !sidebarContainer || !main) return;

      const skeleton = buildRouteSkeleton(main);
      renderHeader(headerContainer);
      const sidebarParts = renderSidebar(sidebarContainer);
      bindSidebarCollapse(sidebarParts);
      setActiveIndicator(sidebarParts.navLinks);
      const closeMenus = bindMenus();
      const closeSidebar = bindMobileSidebar(sidebarParts);

      document.addEventListener("keydown", (event) => {
        if (event.key !== "Escape") return;
        closeMenus();
        closeSidebar();
        document.getElementById(p + "-profile-trigger")?.focus({ preventScroll: true });
      });

      const user = await resolveIdentity();
      if (!user) return;
      revealRoute(main, skeleton);
      document.dispatchEvent(new CustomEvent(p + ":shell-ready", {
        detail: { user: { id: user.id, role: user.role } },
      }));
      populateNotifications();
    }

    init();
  }

  window.HealthBridgeShell = Object.freeze({ create: createShell });
})();
