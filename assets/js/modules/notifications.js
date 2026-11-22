/**
 * HealthBridge — Notification System
 *
 * Frontend service for fetching, displaying, and managing notifications.
 * Designed with a clean public API so the internal polling can be replaced
 * with SSE/WebSocket later without changing any calling code.
 *
 * Usage:
 *   NotificationService.init();          // Start on page load
 *   NotificationService.refresh();       // Force refresh
 *   NotificationService.markAsRead(id);  // Mark single as read
 *   NotificationService.markAllAsRead(); // Mark all as read
 *   NotificationService.delete(id);      // Delete single
 *   NotificationService.deleteAll();     // Delete all
 *   NotificationService.destroy();       // Cleanup
 *
 * Dependencies: main.js (apiFetch, showToast, getUser, escapeHTML)
 */

"use strict";

const NotificationService = (() => {
  // ── Private State ────────────────────────────────────────
  let _pollInterval = null;
  let _serviceInitialized = false;
  let _unreadCount = 0;
  let _currentPage = 1;
  let _hasMore = false;
  let _isLoading = false;
  let _onUnreadChange = null; // Callback for badge updates
  let _dropdownAbortController = null;

  // ── Font Awesome Icons by Notification Type ──────────────
  const TYPE_ICONS = {
    appointment_confirmed: "fa-check-circle",
    appointment_declined: "fa-times-circle",
    appointment_request: "fa-calendar-plus",
    appointment_cancelled: "fa-calendar-times",
    appointment_time_changed: "fa-clock",
    rating_received: "fa-star",
    review_received: "fa-comment",
    support_reply: "fa-reply",
    password_changed: "fa-lock",
    profile_updated: "fa-user-edit",
    account_status_changed: "fa-user-shield",
    new_patient_registered: "fa-user-plus",
    new_doctor_registered: "fa-user-md",
    new_support_ticket: "fa-ticket-alt",
    medical_record_updated: "fa-notes-medical",
    visit_note_added: "fa-file-medical",
    prescription_issued: "fa-prescription",
    prescription_updated: "fa-prescription",
    prescription_completed: "fa-check-circle",
    prescription_cancelled: "fa-ban",
  };

  const DEFAULT_ICON = "fa-bell";
  const MAX_NOTIFICATIONS_PER_PAGE = 50;

  function normalizeNotification(value) {
    if (!value || typeof value !== "object" || Array.isArray(value)) return null;
    const notificationId = safePositiveId(value.id);
    const type = typeof value.type === "string" ? value.type.slice(0, 80) : "";
    const title = typeof value.title === "string" && value.title.trim()
      ? value.title.slice(0, 200)
      : "Notification";
    return {
      id: notificationId,
      type,
      title,
      message: typeof value.message === "string" ? value.message.slice(0, 1000) : "",
      created_at: typeof value.created_at === "string" ? value.created_at.slice(0, 40) : "—",
      is_read: value.is_read === true || value.is_read === 1 || value.is_read === "1",
      ref_type: typeof value.ref_type === "string" ? value.ref_type.slice(0, 40) : "",
      ref_id: safePositiveId(value.ref_id) || 0,
    };
  }

  // ── Public API ───────────────────────────────────────────

  /**
   * Initialize the notification system.
   * Call once on page load after the user is authenticated.
   *
   * @param {Function} [onUnreadChange] — Optional callback(count) for badge updates
   */
  function init(onUnreadChange) {
    if (typeof onUnreadChange === "function") {
      _onUnreadChange = onUnreadChange;
    } else if (!_serviceInitialized) {
      _onUnreadChange = null;
    }

    // Several page entry points can request initialization. Update the callback
    // above, but create only one initial fetch, timer and visibility listener.
    if (_serviceInitialized) return;
    _serviceInitialized = true;

    // Load initial count
    refreshUnreadCount();

    // Passive polling every 60 seconds (only if tab is visible)
    if (_pollInterval) clearInterval(_pollInterval);
    _pollInterval = setInterval(() => {
      if (!document.hidden) refreshUnreadCount();
    }, 60000);

    // Refresh when tab becomes visible again (only once)
    document.removeEventListener("visibilitychange", _onVisibilityChange);
    document.addEventListener("visibilitychange", _onVisibilityChange);
  }

  function _onVisibilityChange() {
    if (!document.hidden) refreshUnreadCount();
  }

  /**
   * Force a full refresh of notifications and unread count.
   * Called when the dropdown is opened or after an action.
   */
  function refresh() {
    _currentPage = 1;
    _hasMore = false;
    refreshUnreadCount();
  }

  /**
   * Fetch a page of notifications.
   *
   * @param {number} [page=1] — Page number (1-based)
   * @returns {Promise<object>} { notifications, unread_count, has_more, total }
   */
  async function getNotifications(page) {
    _isLoading = true;
    const requestedPage = Math.max(1, Math.min(10000, safeCount(page, 1)));

    try {
      const result = await apiFetch(
        `${getBasePath()}api/notifications/notifications.php?page=${requestedPage}`,
        {},
        "Failed to load notifications.",
      );

      if (result && result.data && result.data.success) {
        _unreadCount = safeCount(result.data.unread_count, 0);
        _hasMore = result.data.has_more === true;
        _currentPage = Math.max(1, Math.min(10000, safeCount(page, 1)));
        updateBadge(_unreadCount);
        if (_onUnreadChange) _onUnreadChange(_unreadCount);
        return result.data;
      }
      return { notifications: [], unread_count: 0, has_more: false, total: 0 };
    } catch (e) {
      return { notifications: [], unread_count: 0, has_more: false, total: 0 };
    } finally {
      _isLoading = false;
    }
  }

  /**
   * Mark a single notification as read.
   * @param {number} id
   * @returns {Promise<number>} Updated unread count
   */
  async function markAsRead(id) {
    const notificationId = safePositiveId(id);
    if (!notificationId) return _unreadCount;

    const result = await apiFetch((getBasePath() + "api/notifications/notifications.php"), {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action: "mark_read", id: notificationId }),
    });

    if (result.data?.success) {
      _unreadCount = safeCount(result.data.unread_count, 0);
      updateBadge(_unreadCount);
      if (_onUnreadChange) _onUnreadChange(_unreadCount);
    }
    return _unreadCount;
  }

  /**
   * Mark all notifications as read.
   * @returns {Promise<number>} Updated unread count (0)
   */
  async function markAllAsRead() {
    const result = await apiFetch((getBasePath() + "api/notifications/notifications.php"), {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action: "mark_all_read" }),
    });

    if (result.data?.success) {
      _unreadCount = 0;
      updateBadge(0);
      if (_onUnreadChange) _onUnreadChange(0);
    }
    return 0;
  }

  /**
   * Delete a single notification.
   * @param {number} id
   * @returns {Promise<number>} Updated unread count
   */
  async function deleteNotification(id) {
    const notificationId = safePositiveId(id);
    if (!notificationId) return _unreadCount;

    const result = await apiFetch((getBasePath() + "api/notifications/notifications.php"), {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action: "delete", id: notificationId }),
    });

    if (result.data?.success) {
      _unreadCount = safeCount(result.data.unread_count, 0);
      updateBadge(_unreadCount);
      if (_onUnreadChange) _onUnreadChange(_unreadCount);
    }
    return _unreadCount;
  }

  /**
   * Delete all notifications.
   * @returns {Promise<number>} Updated unread count (0)
   */
  async function deleteAll() {
    const result = await apiFetch((getBasePath() + "api/notifications/notifications.php"), {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action: "delete_all" }),
    });

    if (result.data?.success) {
      _unreadCount = 0;
      updateBadge(0);
      if (_onUnreadChange) _onUnreadChange(0);
    }
    return 0;
  }

  /**
   * Cleanup: stop polling, remove listeners.
   */
  function destroy() {
    if (_pollInterval) {
      clearInterval(_pollInterval);
      _pollInterval = null;
    }

    document.removeEventListener("visibilitychange", _onVisibilityChange);
    _serviceInitialized = false;

    if (_dropdownAbortController) {
      _dropdownAbortController.abort();
      _dropdownAbortController = null;
    }

    // Remove the dropdown so its item-level listeners and detached state are released.
    const dropdown = document.getElementById("notif-dropdown");
    if (dropdown) dropdown.remove();

    _onUnreadChange = null;
  }

  // ── Private Helpers ──────────────────────────────────────

  /** Refresh just the unread count (lightweight). */
  async function refreshUnreadCount() {
    try {
      const result = await apiFetch(
        getBasePath() + "api/notifications/notifications.php?page=1&count_only=1",
        {},
        "",
      );
      if (result.data?.success) {
        _unreadCount = safeCount(result.data.unread_count, 0);
        updateBadge(_unreadCount);
        if (_onUnreadChange) _onUnreadChange(_unreadCount);
      }
    } catch {
      // Silently fail — not critical
    }
  }

  /** Update the badge element in the navbar. */
  function updateBadge(count) {
    const badge = document.getElementById("notif-badge");
    if (!badge) return;
    if (count > 0) {
      badge.textContent = count > 99 ? "99+" : count;
      badge.hidden = false;
    } else {
      badge.hidden = true;
    }
  }

  // ── Dropdown Rendering ───────────────────────────────────

  /**
   * Build the notification dropdown HTML and inject it into the DOM.
   * Called once by initNotificationDropdown().
   */
  function buildDropdown() {
    // Remove existing if any
    const existing = document.getElementById("notif-dropdown");
    if (existing) existing.remove();

    const dropdown = document.createElement("div");
    dropdown.id = "notif-dropdown";
    dropdown.className = "notification-dropdown";
    dropdown.innerHTML = `
      <div class="notif-header">
        <h3>Notifications</h3>
        <div class="notif-header-actions">
          <button class="notif-action-btn" id="notif-mark-all-read" title="Mark all as read">
            <i class="fas fa-check-double"></i>
          </button>
          <button class="notif-action-btn" id="notif-delete-all" title="Delete all">
            <i class="fas fa-trash-alt"></i>
          </button>
        </div>
      </div>
      <div class="notif-list" id="notif-list">
        <div class="notif-loading"><i class="fas fa-spinner fa-spin"></i> Loading...</div>
      </div>
      <div class="notif-footer" id="notif-footer" hidden>
        <button class="notif-load-more" id="notif-load-more">Load More</button>
      </div>
    `;

    document.body.appendChild(dropdown);
    return dropdown;
  }

  /**
   * Render notifications into the dropdown list.
   * @param {Array} notifications
   * @param {boolean} append — If true, append to existing list instead of replacing
   */
  function renderNotifications(notifications, append) {
    const list = document.getElementById("notif-list");
    if (!list) return;

    if (!append) {
      list.innerHTML = "";
    }

    const safeNotifications = Array.isArray(notifications)
      ? notifications.map(normalizeNotification).filter(Boolean).slice(0, MAX_NOTIFICATIONS_PER_PAGE)
      : [];

    if (safeNotifications.length === 0) {
      if (!append) {
        list.innerHTML = `
          <div class="notif-empty">
            <i class="fas fa-bell-slash"></i>
            <p>No notifications yet</p>
            <span>You're all caught up!</span>
          </div>
        `;
      }
      const footer = document.getElementById("notif-footer");
      if (footer) footer.hidden = true;
      return;
    }

    safeNotifications.forEach((notif) => {
      const notificationId = safePositiveId(notif.id);
      const notificationType = typeof notif.type === "string" ? notif.type : "";
      const item = document.createElement("div");
      item.className = `notif-item${notif.is_read === true ? "" : " unread"}`;
      item.dataset.id = notificationId ? String(notificationId) : "";
      item.dataset.type = notificationType;
      item.dataset.refType = typeof notif.ref_type === "string" ? notif.ref_type : "";
      item.dataset.refId = typeof notif.ref_id === "string" || typeof notif.ref_id === "number"
        ? String(notif.ref_id)
        : "";

      const icon = Object.prototype.hasOwnProperty.call(TYPE_ICONS, notificationType)
        ? TYPE_ICONS[notificationType]
        : DEFAULT_ICON;

      item.innerHTML = `
        <div class="notif-item-icon">
          <i class="fas ${icon}"></i>
        </div>
        <div class="notif-item-content">
          <div class="notif-item-title">${escapeHTML(notif.title)}</div>
          <div class="notif-item-message">${escapeHTML(notif.message)}</div>
          <div class="notif-item-time">${getRelativeTime(notif.created_at)}</div>
        </div>
        <div class="notif-item-actions">
          ${!notif.is_read && notificationId ? '<button class="notif-mark-read" title="Mark as read"><i class="fas fa-check"></i></button>' : ""}
          ${notificationId ? '<button class="notif-delete" title="Delete"><i class="fas fa-times"></i></button>' : ""}
        </div>
      `;

      // Click on the notification body navigates
      item
        .querySelector(".notif-item-content")
        .addEventListener("click", () => {
          handleNotificationClick(notif);
        });

      // Mark as read button
      const markBtn = item.querySelector(".notif-mark-read");
      if (markBtn) {
        markBtn.addEventListener("click", (e) => {
          e.stopPropagation();
          markAsRead(notificationId).then(() => {
            item.classList.remove("unread");
            markBtn.remove();
          });
        });
      }

      // Delete action exists only for a strictly validated notification ID.
      const deleteBtn = item.querySelector(".notif-delete");
      if (deleteBtn) {
        deleteBtn.addEventListener("click", (e) => {
          e.stopPropagation();
          deleteNotification(notificationId).then(() => {
            item.classList.add("is-deleting");
            setTimeout(() => item.remove(), 250);
            // If list is now empty, show empty state
            if (list.children.length === 0) {
              renderNotifications([]);
            }
          });
        });
      }

      list.appendChild(item);
    });

    // Show/hide load more
    const footer = document.getElementById("notif-footer");
    if (footer) {
      footer.hidden = !_hasMore;
    }
  }

  /**
   * Handle clicking a notification — navigate to the relevant page/tab.
   */
  function handleNotificationClick(notif) {
    // Mark as read first
    if (!notif.is_read) {
      markAsRead(notif.id);
    }

    const type = typeof notif.type === "string" ? notif.type : "";
    const refType = typeof notif.ref_type === "string" ? notif.ref_type : "";

    // Determine navigation based on type + ref
    switch (type) {
      case "appointment_confirmed":
      case "appointment_declined":
      case "appointment_cancelled":
      case "appointment_time_changed":
        // Navigate to patient's booking history
        navigateToTab("history");
        break;

      case "appointment_request":
        // Navigate to doctor's appointments tab
        navigateToTab("appointments");
        break;

      case "rating_received":
      case "review_received":
        // Navigate to doctor's reviews tab
        navigateToTab("reviews");
        break;

      case "support_reply":
        // Navigate to support messages section
        navigateToTab("messages");
        break;

      case "password_changed":
      case "profile_updated":
        // Open Settings modal
        if (typeof openSettings === "function") {
          closeDropdown();
          openSettings();
        }
        break;

      case "account_status_changed":
        // Just refresh the page
        location.reload();
        break;

      case "new_patient_registered":
        navigateToTab("patients");
        break;

      case "new_doctor_registered":
        navigateToTab("doctors");
        break;

      case "new_support_ticket":
        navigateToTab("support-messages");
        break;

      case "medical_record_updated":
      case "visit_note_added":
        // Navigate to patient's medical record tab
        navigateToTab("medical-record");
        break;

      case "prescription_issued":
      case "prescription_updated":
      case "prescription_completed":
      case "prescription_cancelled":
        // Navigate to prescriptions tab
        navigateToTab("prescriptions");
        break;

      default:
        // Generic: try to navigate by ref_type
        if (refType === "appointment") {
          navigateToTab("history");
        }
        break;
    }

    closeDropdown();
  }

  /**
   * Navigate to a tab/page from notifications.
   */
  function navigateToTab(tabId) {
    if (!tabId) return;
    if (tabId.startsWith("#")) tabId = tabId.substring(1);

    const user = getUser();
    const role = user ? user.role : "patient";

    const pageMap = {
      history: role === "patient" ? "pages/patient/history.html" : "pages/doctor/doctor-dashboard.html#history",
      appointments: role === "patient" ? "pages/patient/appointments.html" : "pages/doctor/doctor-dashboard.html#appointments",
      prescriptions: role === "patient" ? "pages/patient/prescriptions.html" : "pages/doctor/doctor-dashboard.html#prescriptions",
      notifications: role === "patient" ? "pages/patient/notifications.html" : "pages/doctor/doctor-dashboard.html#notifications",
      profile: role === "patient" ? "pages/patient/profile.html" : "pages/doctor/doctor-dashboard.html#profile",
      settings: role === "patient" ? "pages/patient/settings.html" : "pages/doctor/doctor-dashboard.html#settings",
      reviews: "pages/doctor/doctor-dashboard.html#reviews",
      queue: "pages/doctor/doctor-dashboard.html#queue",
      patients: "pages/admin/admin.html#patients",
      doctors: "pages/admin/admin.html#doctors"
    };

    const relativePath = pageMap[tabId];
    if (relativePath) {
      const targetUrl = getBasePath() + relativePath;
      const currentPath = window.location.pathname;
      const targetPageName = relativePath.split("/").pop().split("#")[0];

      if (!currentPath.endsWith(targetPageName)) {
        window.location.href = targetUrl;
      }
      return;
    }

    const safeTabId = String(tabId).trim();
    if (!/^[a-z0-9_-]{1,40}$/i.test(safeTabId)) return;

    const link = Array.from(document.querySelectorAll(".sidebar-nav a")).find((anchor) => {
      const dataPage = anchor.getAttribute("data-page") || "";
      const href = anchor.getAttribute("href") || "";
      return dataPage === safeTabId || href.includes(safeTabId);
    });
    if (link) link.click();
  }

  /**
   * Convert a datetime string to a relative time string.
   * Examples: "Just now", "2 min ago", "1 hour ago", "Yesterday", "3 days ago"
   */
  function getRelativeTime(dateStr) {
    if (typeof dateStr !== "string" || !dateStr) return "";
    const now = new Date();
    const date = new Date(dateStr);
    if (Number.isNaN(date.getTime())) return "—";
    const diffMs = now - date;
    const diffSec = Math.floor(diffMs / 1000);
    const diffMin = Math.floor(diffSec / 60);
    const diffHour = Math.floor(diffMin / 60);
    const diffDay = Math.floor(diffHour / 24);

    if (diffSec < 10) return "Just now";
    if (diffSec < 60) return `${diffSec}s ago`;
    if (diffMin < 60) return `${diffMin}m ago`;
    if (diffHour < 24) return `${diffHour}h ago`;
    if (diffDay === 1) return "Yesterday";
    if (diffDay < 7) return `${diffDay}d ago`;
    if (diffDay < 30) return `${Math.floor(diffDay / 7)}w ago`;

    // Fallback to formatted date
    return date.toLocaleDateString(undefined, {
      month: "short",
      day: "numeric",
    });
  }

  /** Close the notification dropdown. */
  function closeDropdown() {
    const dropdown = document.getElementById("notif-dropdown");
    if (dropdown) dropdown.classList.remove("open");
  }

  // ── Dropdown Toggle ──────────────────────────────────────

  /**
   * Initialize the notification bell button and dropdown.
   * Called from initNavbar() in main.js.
   */
  function initDropdown() {
    // main.js and individual dashboards can both request initialization.
    // Bind once per active lifecycle so a click can never toggle twice.
    if (_dropdownAbortController) return;

    const bellBtn = document.getElementById("notif-bell-btn");
    if (!bellBtn) return;

    _dropdownAbortController = new AbortController();
    const signal = _dropdownAbortController.signal;

    // Build dropdown on first click
    let dropdownBuilt = false;

    bellBtn.addEventListener("click", (e) => {
      e.stopPropagation();
      e.preventDefault();

      if (!dropdownBuilt) {
        buildDropdown();
        dropdownBuilt = true;
      }

      const dropdown = document.getElementById("notif-dropdown");
      if (!dropdown) return;

      const isOpen = dropdown.classList.contains("open");
      if (isOpen) {
        dropdown.classList.remove("open");
        return;
      }

      // Open and load notifications
      dropdown.classList.add("open");
      loadNotifications(1);
    }, { signal });

    // Close dropdown when clicking outside
    document.addEventListener("click", (e) => {
      const dropdown = document.getElementById("notif-dropdown");
      if (!dropdown) return;
      if (
        !dropdown.contains(e.target) &&
        e.target !== bellBtn &&
        !bellBtn.contains(e.target)
      ) {
        dropdown.classList.remove("open");
      }
    }, { signal });

    // Close on Escape
    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape") closeDropdown();
    }, { signal });
  }

  /**
   * Load a page of notifications into the dropdown.
   * @param {number} page
   */
  async function loadNotifications(page) {
    const list = document.getElementById("notif-list");
    if (!list) return;

    const data = await getNotifications(page);
    if (!data) return;

    const append = page > 1;
    renderNotifications(data.notifications || [], append);

    // Bind load more button
    const loadMoreBtn = document.getElementById("notif-load-more");
    if (loadMoreBtn) {
      const newBtn = loadMoreBtn.cloneNode(true);
      loadMoreBtn.parentNode.replaceChild(newBtn, loadMoreBtn);
      newBtn.addEventListener("click", () => {
        loadNotifications(_currentPage + 1);
      });
    }

    // Bind mark all read
    const markAllBtn = document.getElementById("notif-mark-all-read");
    if (markAllBtn) {
      const newMarkAll = markAllBtn.cloneNode(true);
      markAllBtn.parentNode.replaceChild(newMarkAll, markAllBtn);
      newMarkAll.addEventListener("click", () => {
        markAllAsRead().then(() => {
          document.querySelectorAll(".notif-item.unread").forEach((el) => {
            el.classList.remove("unread");
            const markBtn = el.querySelector(".notif-mark-read");
            if (markBtn) markBtn.remove();
          });
        });
      });
    }

    // Bind delete all
    const deleteAllBtn = document.getElementById("notif-delete-all");
    if (deleteAllBtn) {
      const newDeleteAll = deleteAllBtn.cloneNode(true);
      deleteAllBtn.parentNode.replaceChild(newDeleteAll, deleteAllBtn);
      newDeleteAll.addEventListener("click", () => {
        if (confirm("Delete all notifications?")) {
          deleteAll().then(() => {
            renderNotifications([]);
          });
        }
      });
    }
  }

  // ── Expose Public API ────────────────────────────────────
  return {
    init,
    refresh,
    getNotifications,
    markAsRead,
    markAllAsRead,
    delete: deleteNotification,
    deleteAll,
    destroy,
    initDropdown,
    getRelativeTime,
  };
})();

// Expose on window object for global module availability
window.NotificationService = NotificationService;


