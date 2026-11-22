/**
 * HealthBridge — Patient Notifications Page Controller
 *
 * Gmail-style notifications rendering: grouped by Today, Yesterday, This Week, Earlier.
 * Category icons, unread badges, mark single as read, mark all as read.
 */
"use strict";

document.addEventListener("DOMContentLoaded", async () => {
  requireAuth();

  const user = getUser();
  if (!user || user.role !== "patient") {
    window.location.href = getBasePath() + "pages/auth/login.html";
    return;
  }

  const container = document.getElementById("notifications-container");
  const markAllBtn = document.getElementById("mark-all-read-btn");
  if (!container) return;

  await loadNotifications();

  async function loadNotifications() {
    container.innerHTML = `
      <div class="skeleton-loader">
        <div class="skeleton skeleton-card patient-notifications-skeleton"></div>
        <div class="skeleton skeleton-card patient-notifications-skeleton"></div>
        <div class="skeleton skeleton-card patient-notifications-skeleton"></div>
      </div>`;

    const result = await apiFetch(
      getBasePath() + "api/patient/dashboard.php",
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ sections: ["notifications"] })
      },
      "Failed to load notifications."
    );

    container.innerHTML = "";

    if (result.ok && result.data?.success) {
      const rawNotifData = result.data.data?.notifications;
      const notifData = rawNotifData && typeof rawNotifData === "object" && !Array.isArray(rawNotifData)
        ? rawNotifData
        : {};
      const rawGrouped = notifData.grouped;
      const grouped = rawGrouped && typeof rawGrouped === "object" && !Array.isArray(rawGrouped)
        ? rawGrouped
        : {};
      const unreadCount = safeCount(notifData.unread_count, 0);

      if (markAllBtn) {
        markAllBtn.hidden = unreadCount <= 0;

        // Preserve the former onclick replacement semantics across repeated loads:
        // bind once, rather than stacking another handler on every refresh.
        if (markAllBtn.dataset.markAllReadBound !== "true") {
          markAllBtn.addEventListener("click", async () => {
            await apiFetch(getBasePath() + "api/notifications/notifications.php", {
              method: "POST",
              headers: { "Content-Type": "application/json" },
              body: JSON.stringify({ action: "mark_all_read" })
            });
            showToast("All notifications marked as read", "success");
            loadNotifications();
          });
          markAllBtn.dataset.markAllReadBound = "true";
        }
      }

      const groupLabels = {
        today: "Today",
        yesterday: "Yesterday",
        this_week: "This Week",
        earlier: "Earlier"
      };

      let totalCount = 0;
      const maxDisplayedNotifications = 20;

      Object.entries(groupLabels).forEach(([key, label]) => {
        const rawItems = grouped[key];
        const remaining = Math.max(0, maxDisplayedNotifications - totalCount);
        if (remaining === 0) return;

        const items = (Array.isArray(rawItems) ? rawItems : [])
          .filter(notification =>
            notification && typeof notification === "object" && !Array.isArray(notification)
          )
          .slice(0, remaining)
          .map(notification => {
            const title = typeof notification.title === "string"
              ? notification.title.slice(0, 200)
              : "Notification";
            return {
              id: safePositiveId(notification.id),
              title,
              message: typeof notification.message === "string" ? notification.message.slice(0, 1000) : "",
              time_ago: typeof notification.time_ago === "string" ? notification.time_ago.slice(0, 80) : "",
              type: typeof notification.type === "string" ? notification.type.slice(0, 80) : title.slice(0, 80),
              is_read: notification.is_read === true || notification.is_read === 1 || notification.is_read === "1",
            };
          });

        if (items.length === 0) return;
        totalCount += items.length;

        const groupEl = document.createElement("div");
        groupEl.className = "patient-notification-group";
        groupEl.innerHTML = `
          <h3 class="patient-notification-group__title">
            ${escapeHTML(label)} (${items.length})
          </h3>
          <div class="notif-items-wrap patient-notification-items"></div>`;

        const itemsWrap = groupEl.querySelector(".notif-items-wrap");
        items.forEach(n => {
          const itemEl = document.createElement("div");
          itemEl.className = `card notif-item patient-notification-item ${!n.is_read ? "unread is-unread" : "read is-read"}`;
          
          const iconSource = typeof n.type === "string"
            ? n.type
            : typeof n.title === "string" ? n.title : "";
          const icon = safeClassToken(getNotifIcon(iconSource), "fa-bell");
          itemEl.innerHTML = `
            <div class="patient-notification-item__icon ${!n.is_read ? "is-unread" : "is-read"}">
              <i class="fas ${icon}" aria-hidden="true"></i>
            </div>
            <div class="patient-notification-item__content">
              <div class="patient-notification-item__header">
                <h4 class="patient-notification-item__title ${!n.is_read ? "is-unread" : "is-read"}">
                  ${!n.is_read ? `<span class="unread-dot patient-notification-item__unread-dot" aria-hidden="true"></span>` : ""}
                  <span class="notif-title-text">${escapeHTML(n.title)}</span>
                </h4>
                <span class="patient-notification-item__time">${escapeHTML(n.time_ago || "")}</span>
              </div>
              <p class="patient-notification-item__message">${escapeHTML(n.message)}</p>
            </div>`;

          itemEl.addEventListener("click", async () => {
            const notificationId = safePositiveId(n.id);
            if (!notificationId) return;

            if (!n.is_read) {
              n.is_read = 1;
              itemEl.classList.remove("unread", "is-unread");
              itemEl.classList.add("read", "is-read");
              const icon = itemEl.querySelector(".patient-notification-item__icon");
              if (icon) {
                icon.classList.remove("is-unread");
                icon.classList.add("is-read");
              }
              const title = itemEl.querySelector(".patient-notification-item__title");
              if (title) {
                title.classList.remove("is-unread");
                title.classList.add("is-read");
              }
              const dot = itemEl.querySelector(".unread-dot");
              if (dot) dot.remove();

              apiFetch(getBasePath() + "api/notifications/notifications.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ action: "mark_read", id: notificationId })
              }).then(res => {
                if (window.NotificationService && typeof window.NotificationService.refresh === "function") {
                  window.NotificationService.refresh();
                }
              });
            }
          });

          itemsWrap.appendChild(itemEl);
        });

        container.appendChild(groupEl);
      });

      if (totalCount === 0) {
        container.innerHTML = `
          <div class="empty-state">
            <div class="empty-icon"><i class="fas fa-bell-slash" aria-hidden="true"></i></div>
            <h4>No Notifications</h4>
            <p>You're all caught up! Important updates about appointments and medical records will appear here.</p>
            <a href="dashboard.html" class="btn btn-primary btn-sm">Back to Dashboard</a>
          </div>`;
      }
    } else {
      container.innerHTML = `
        <div class="empty-state">
          <div class="empty-icon"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i></div>
          <h4>Unable to Load Notifications</h4>
          <p>Please try again later.</p>
          <button class="btn btn-outline btn-sm" data-page-action="retry">Retry</button>
        </div>`;
    }
  }

  function renderNotifItem(n) {
    const isUnread = !n.is_read;
    const iconSource = typeof n?.type === "string"
      ? n.type
      : typeof n?.title === "string" ? n.title : "";
    const icon = safeClassToken(getNotifIcon(iconSource), "fa-bell");

    return `
      <div class="card notif-item patient-notification-item ${isUnread ? "unread is-unread" : "read is-read"}">
        <div class="patient-notification-item__icon ${isUnread ? "is-unread" : "is-read"}">
          <i class="fas ${icon}" aria-hidden="true"></i>
        </div>
        <div class="patient-notification-item__content">
          <div class="patient-notification-item__header">
            <h4 class="patient-notification-item__title ${isUnread ? "is-unread" : "is-read"}">
              ${isUnread ? `<span class="unread-dot patient-notification-item__unread-dot" aria-hidden="true"></span>` : ""}
              ${escapeHTML(n.title)}
            </h4>
            <span class="patient-notification-item__time">${escapeHTML(n.time_ago || "")}</span>
          </div>
          <p class="patient-notification-item__message">${escapeHTML(n.message)}</p>
        </div>
      </div>`;
  }

  function getNotifIcon(type) {
    if (typeof type !== "string" || !type) return "fa-bell";
    const t = type.toLowerCase();
    if (t.includes("appointment")) return "fa-calendar-check";
    if (t.includes("prescription")) return "fa-prescription-bottle-medical";
    if (t.includes("rating") || t.includes("review")) return "fa-star";
    if (t.includes("security") || t.includes("password")) return "fa-shield-alt";
    if (t.includes("medical") || t.includes("visit")) return "fa-stethoscope";
    return "fa-bell";
  }
});