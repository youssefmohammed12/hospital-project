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
  let _unreadCount = 0;
  let _currentPage = 1;
  let _hasMore = false;
  let _isLoading = false;
  let _onUnreadChange = null; // Callback for badge updates

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

  // ── Public API ───────────────────────────────────────────

  /**
   * Initialize the notification system.
   * Call once on page load after the user is authenticated.
   *
   * @param {Function} [onUnreadChange] — Optional callback(count) for badge updates
   */
  function init(onUnreadChange) {
    _onUnreadChange = onUnreadChange || null;

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
    if (_isLoading) return null;
    _isLoading = true;

    try {
      const result = await apiFetch(
        `${getBasePath()}api/notifications/notifications.php?page=${page || 1}`,
        {},
        "Failed to load notifications.",
      );

      if (result.data?.success) {
        _unreadCount = result.data.unread_count || 0;
        _hasMore = result.data.has_more || false;
        _currentPage = page || 1;
        updateBadge(_unreadCount);
        if (_onUnreadChange) _onUnreadChange(_unreadCount);
        return result.data;
      }
      return null;
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
    const result = await apiFetch((getBasePath() + "api/notifications/notifications.php"), {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action: "mark_read", id }),
    });

    if (result.data?.success) {
      _unreadCount = result.data.unread_count || 0;
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
    const result = await apiFetch((getBasePath() + "api/notifications/notifications.php"), {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action: "delete", id }),
    });

    if (result.data?.success) {
      _unreadCount = result.data.unread_count || 0;
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
        _unreadCount = result.data.unread_count || 0;
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
      badge.style.display = "";
    } else {
      badge.style.display = "none";
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
      <div class="notif-footer" id="notif-footer" style="display:none">
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

    if (!notifications || notifications.length === 0) {
      if (!append) {
        list.innerHTML = `
          <div class="notif-empty">
            <i class="fas fa-bell-slash"></i>
            <p>No notifications yet</p>
            <span>You're all caught up!</span>
          </div>
        `;
      }
      document.getElementById("notif-footer").style.display = "none";
      return;
    }

    notifications.forEach((notif) => {
      const item = document.createElement("div");
      item.className = `notif-item${notif.is_read ? "" : " unread"}`;
      item.dataset.id = notif.id;
      item.dataset.type = notif.type || "";
      item.dataset.refType = notif.ref_type || "";
      item.dataset.refId = notif.ref_id || "";

      const icon = TYPE_ICONS[notif.type] || DEFAULT_ICON;

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
          ${notif.is_read ? "" : '<button class="notif-mark-read" title="Mark as read"><i class="fas fa-check"></i></button>'}
          <button class="notif-delete" title="Delete"><i class="fas fa-times"></i></button>
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
          markAsRead(notif.id).then(() => {
            item.classList.remove("unread");
            markBtn.remove();
          });
        });
      }

      // Delete button
      item.querySelector(".notif-delete").addEventListener("click", (e) => {
        e.stopPropagation();
        deleteNotification(notif.id).then(() => {
          item.style.animation = "notifSlideOut 0.25s ease forwards";
          setTimeout(() => item.remove(), 250);
          // If list is now empty, show empty state
          if (list.children.length === 0) {
            renderNotifications([]);
          }
        });
      });

      list.appendChild(item);
    });

    // Show/hide load more
    const footer = document.getElementById("notif-footer");
    if (footer) {
      footer.style.display = _hasMore ? "" : "none";
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

    const type = notif.type || "";
    const refType = notif.ref_type || "";
    const refId = notif.ref_id;

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
   * Navigate to a sidebar tab if on a dashboard page.
   * Falls back to dashboard URL if not on a dashboard.
   */
  function navigateToTab(tabId) {
    const link = document.querySelector(`.sidebar-nav a[href="#${tabId}"]`);
    if (link) {
      link.click();
    } else {
      // Not on a dashboard page — redirect to the appropriate dashboard
      const user = getUser();
      if (user) {
        window.location.href = getDashboardUrl(user.role);
      }
    }
  }

  /**
   * Convert a datetime string to a relative time string.
   * Examples: "Just now", "2 min ago", "1 hour ago", "Yesterday", "3 days ago"
   */
  function getRelativeTime(dateStr) {
    if (!dateStr) return "";
    const now = new Date();
    const date = new Date(dateStr);
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
    const bellBtn = document.getElementById("notif-bell-btn");
    if (!bellBtn) return;

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
    });

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
    });

    // Close on Escape
    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape") closeDropdown();
    });
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


