/**
 * HealthBridge — Patient Notifications Page
 * Loads and displays notifications from the API.
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
  if (!container) return;

  // Use NotificationService if available
  if (typeof NotificationService !== "undefined") {
    NotificationService.init();
    // Try loading from the patient notifications API
    const result = await apiFetch(getBasePath() + "api/notifications/get-patient.php", {}, "Failed to load notifications");
    if (result.ok && result.data?.success) {
      container.innerHTML = "";
      const notifs = result.data.notifications || [];
      if (notifs.length === 0) {
        container.innerHTML = '<div class="empty-state"><div class="empty-icon"><i class="fas fa-bell-slash" aria-hidden="true"></i></div><h4>No Notifications</h4><p>You\'re all caught up!</p><a href="dashboard.html" class="btn btn-primary btn-sm">Back to Dashboard</a></div>';
        return;
      }
      notifs.forEach(n => {
        container.innerHTML += `
          <div class="notif-item${n.is_read ? "" : " unread"}" style="margin-bottom:var(--s2)">
            <div class="notif-icon"><i class="fas ${getNotifIcon(n.type)}" aria-hidden="true"></i></div>
            <div class="notif-content">
              <div class="notif-title">${escapeHTML(n.title)}</div>
              <div class="notif-message">${escapeHTML(n.message)}</div>
            </div>
            <div class="notif-time">${escapeHTML(n.time_ago || n.created_at || "")}</div>
          </div>`;
      });
      return;
    }
  }

  // Fallback: use dashboard API
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
    const notifData = result.data.data?.notifications;
    const allNotifs = [
      ...(notifData?.grouped?.today || []),
      ...(notifData?.grouped?.yesterday || []),
      ...(notifData?.grouped?.this_week || []),
      ...(notifData?.grouped?.earlier || [])
    ];

    if (allNotifs.length === 0) {
      container.innerHTML = '<div class="empty-state"><div class="empty-icon"><i class="fas fa-bell-slash" aria-hidden="true"></i></div><h4>No Notifications</h4><p>You\'re all caught up!</p><a href="dashboard.html" class="btn btn-primary btn-sm">Back to Dashboard</a></div>';
      return;
    }

    const groups = { today: "Today", yesterday: "Yesterday", this_week: "This Week", earlier: "Earlier" };
    Object.entries(groups).forEach(([key, label]) => {
      const items = notifData?.grouped?.[key] || [];
      if (items.length === 0) return;
      container.innerHTML += `<h3 style="margin:var(--s4) 0 var(--s2);font-size:0.85rem;color:var(--text-muted)">${label}</h3>`;
      items.forEach(n => {
        container.innerHTML += `
          <div class="notif-item${n.is_read ? "" : " unread"}" style="margin-bottom:var(--s2)">
            <div class="notif-icon"><i class="fas ${getNotifIcon(n.type)}" aria-hidden="true"></i></div>
            <div class="notif-content">
              <div class="notif-title">${escapeHTML(n.title)}</div>
              <div class="notif-message">${escapeHTML(n.message)}</div>
            </div>
            <div class="notif-time">${escapeHTML(n.time_ago || "")}</div>
          </div>`;
      });
    });
  } else {
    container.innerHTML = '<div class="empty-state"><div class="empty-icon"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i></div><h4>Unable to Load Notifications</h4><p>Please try again later.</p><button class="btn btn-outline btn-sm" onclick="location.reload()">Try Again</button></div>';
  }
});

function getNotifIcon(type) {
  if (!type) return "fa-bell";
  if (type.includes("appointment")) return "fa-calendar-check";
  if (type.includes("prescription")) return "fa-prescription";
  if (type.includes("rating") || type.includes("review")) return "fa-star";
  if (type.includes("support") || type.includes("message")) return "fa-comment";
  if (type.includes("password") || type.includes("profile") || type.includes("account")) return "fa-user";
  if (type.includes("medical") || type.includes("visit")) return "fa-notes-medical";
  return "fa-bell";
}