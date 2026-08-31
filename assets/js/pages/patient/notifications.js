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
        <div class="skeleton skeleton-card" style="height: 60px; margin-bottom: var(--s3);"></div>
        <div class="skeleton skeleton-card" style="height: 60px; margin-bottom: var(--s3);"></div>
        <div class="skeleton skeleton-card" style="height: 60px;"></div>
      </div>`;

    const result = await apiFetch(
      getBasePath() + "api/patient/dashboard.php",
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({})
      },
      "Failed to load notifications."
    );

    container.innerHTML = "";

    if (result.ok && result.data?.success) {
      const notifData = result.data.data?.notifications || {};
      const grouped = notifData.grouped || {};
      const unreadCount = notifData.unread_count || 0;

      if (markAllBtn) {
        markAllBtn.style.display = unreadCount > 0 ? "inline-flex" : "none";
        markAllBtn.onclick = async () => {
          await apiFetch(getBasePath() + "api/notifications/notifications.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ action: "mark_all_read" })
          });
          showToast("All notifications marked as read", "success");
          loadNotifications();
        };
      }

      const groupLabels = {
        today: "Today",
        yesterday: "Yesterday",
        this_week: "This Week",
        earlier: "Earlier"
      };

      let totalCount = 0;

      Object.entries(groupLabels).forEach(([key, label]) => {
        const items = grouped[key] || [];
        if (items.length === 0) return;
        totalCount += items.length;

        const groupEl = document.createElement("div");
        groupEl.style.marginBottom = "var(--s5)";
        groupEl.innerHTML = `
          <h3 style="margin:var(--s4) 0 var(--s3) 0;font-size:0.85rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.5px">
            ${escapeHTML(label)} (${items.length})
          </h3>
          <div class="notif-items-wrap" style="display:flex;flex-direction:column;gap:var(--s2)"></div>`;

        const itemsWrap = groupEl.querySelector(".notif-items-wrap");
        items.forEach(n => {
          const itemEl = document.createElement("div");
          itemEl.className = `card notif-item ${!n.is_read ? "unread" : ""}`;
          itemEl.style.cssText = `padding:var(--s4);display:flex;align-items:flex-start;gap:var(--s4);cursor:pointer;border:1px solid ${!n.is_read ? "var(--primary-subtle)" : "var(--border-light)"};background:${!n.is_read ? "var(--bg-surface)" : "var(--bg-card)"}`;
          
          const icon = getNotifIcon(n.type || n.title || "");
          itemEl.innerHTML = `
            <div style="width:40px;height:40px;border-radius:50%;background:${!n.is_read ? "var(--primary-subtle)" : "var(--bg-surface)"};display:flex;align-items:center;justify-content:center;color:${!n.is_read ? "var(--primary)" : "var(--text-muted)"};font-size:1.1rem;flex-shrink:0">
              <i class="fas ${icon}"></i>
            </div>
            <div style="flex:1">
              <div style="display:flex;justify-content:space-between;align-items:center;gap:var(--s2)">
                <h4 style="margin:0;font-size:0.95rem;font-weight:${!n.is_read ? "700" : "600"};color:var(--text-primary)">
                  ${!n.is_read ? `<span class="unread-dot" style="display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--primary);margin-right:var(--s2)"></span>` : ""}
                  ${escapeHTML(n.title)}
                </h4>
                <span style="font-size:0.75rem;color:var(--text-muted)">${escapeHTML(n.time_ago || "")}</span>
              </div>
              <p style="margin:var(--s1) 0 0 0;font-size:0.85rem;color:var(--text-secondary)">${escapeHTML(n.message)}</p>
            </div>`;

          itemEl.addEventListener("click", async () => {
            if (!n.is_read) {
              n.is_read = 1;
              itemEl.classList.remove("unread");
              itemEl.style.background = "var(--bg-card)";
              itemEl.style.borderColor = "var(--border-light)";
              const dot = itemEl.querySelector(".unread-dot");
              if (dot) dot.remove();

              apiFetch(getBasePath() + "api/notifications/notifications.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ action: "mark_read", id: n.id })
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
          <button class="btn btn-outline btn-sm" onclick="location.reload()">Retry</button>
        </div>`;
    }
  }

  function renderNotifItem(n) {
    const isUnread = !n.is_read;
    const icon = getNotifIcon(n.type || n.title || "");

    return `
      <div class="card notif-item ${isUnread ? "unread" : ""}" style="padding:var(--s4);display:flex;align-items:flex-start;gap:var(--s4);border:1px solid ${isUnread ? "var(--primary-subtle)" : "var(--border-light)"};background:${isUnread ? "var(--bg-surface)" : "var(--bg-card)"}">
        <div style="width:40px;height:40px;border-radius:50%;background:${isUnread ? "var(--primary-subtle)" : "var(--bg-surface)"};display:flex;align-items:center;justify-content:center;color:${isUnread ? "var(--primary)" : "var(--text-muted)"};font-size:1.1rem;flex-shrink:0">
          <i class="fas ${icon}"></i>
        </div>
        <div style="flex:1">
          <div style="display:flex;justify-content:space-between;align-items:center;gap:var(--s2)">
            <h4 style="margin:0;font-size:0.95rem;font-weight:${isUnread ? "700" : "600"};color:var(--text-primary)">
              ${isUnread ? `<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--primary);margin-right:var(--s2)"></span>` : ""}
              ${escapeHTML(n.title)}
            </h4>
            <span style="font-size:0.75rem;color:var(--text-muted)">${escapeHTML(n.time_ago || "")}</span>
          </div>
          <p style="margin:var(--s1) 0 0 0;font-size:0.85rem;color:var(--text-secondary)">${escapeHTML(n.message)}</p>
        </div>
      </div>`;
  }

  function getNotifIcon(type) {
    if (!type) return "fa-bell";
    const t = type.toLowerCase();
    if (t.includes("appointment")) return "fa-calendar-check";
    if (t.includes("prescription")) return "fa-prescription-bottle-medical";
    if (t.includes("rating") || t.includes("review")) return "fa-star";
    if (t.includes("security") || t.includes("password")) return "fa-shield-alt";
    if (t.includes("medical") || t.includes("visit")) return "fa-stethoscope";
    return "fa-bell";
  }
});