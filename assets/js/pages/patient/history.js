/**
 * HealthBridge — Patient Medical History Page Controller
 *
 * Renders the hospital-grade medical timeline incorporating:
 * - Patient registration
 * - Appointment bookings
 * - Confirmed / Completed visits & doctor notes
 * - Prescriptions issued
 * - Reschedule workflow requests / approvals / declines
 * - Ratings submitted
 * - Profile updates
 */
"use strict";

document.addEventListener("DOMContentLoaded", async () => {
  requireAuth();

  const user = getUser();
  if (!user || user.role !== "patient") {
    window.location.href = getBasePath() + "pages/auth/login.html";
    return;
  }

  const container = document.getElementById("medical-record-container");
  if (!container) return;

  const result = await apiFetch(
    getBasePath() + "api/patient/dashboard.php",
    {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({})
    },
    "Failed to load medical history."
  );

  container.innerHTML = "";

  if (result.ok && result.data?.success) {
    const timeline = result.data.data?.medical_timeline || [];

    if (timeline.length === 0) {
      container.innerHTML = `
        <div class="empty-state">
          <div class="empty-icon"><i class="fas fa-timeline" aria-hidden="true"></i></div>
          <h4>No Medical Events Yet</h4>
          <p>Your medical timeline will automatically populate as you book and complete appointments.</p>
          <a href="dashboard.html" class="btn btn-primary btn-sm">Back to Dashboard</a>
        </div>`;
      return;
    }

    // Sort timeline descending (most recent first)
    const sorted = timeline.slice().reverse();

    container.innerHTML = `
      <div style="position:relative;padding-left:var(--s6);margin-top:var(--s4)">
        
        <!-- Timeline vertical spine line -->
        <div style="position:absolute;left:18px;top:0;bottom:0;width:3px;background:var(--border-light);border-radius:2px"></div>

        <div style="display:flex;flex-direction:column;gap:var(--s6)">
          ${sorted.map(ev => renderTimelineEvent(ev)).join("")}
        </div>

      </div>`;
  } else {
    container.innerHTML = `
      <div class="empty-state">
        <div class="empty-icon"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i></div>
        <h4>Unable to Load Medical History</h4>
        <p>Please try again later.</p>
        <button class="btn btn-outline btn-sm" onclick="location.reload()">Retry</button>
      </div>`;
  }

  function renderTimelineEvent(ev) {
    const color = ev.color || "var(--primary)";
    const icon = ev.icon || "fa-circle";
    const dateStr = formatDate(ev.date);

    return `
      <div style="position:relative;display:flex;gap:var(--s4);align-items:flex-start">
        
        <!-- Icon Marker -->
        <div style="position:absolute;left:-44px;top:2px;width:36px;height:36px;border-radius:50%;background:var(--bg-card);border:2px solid ${color};display:flex;align-items:center;justify-content:center;color:${color};font-size:0.95rem;box-shadow:var(--shadow-sm);z-index:2">
          <i class="fas ${icon}"></i>
        </div>

        <!-- Event Content Card -->
        <div class="card" style="flex:1;padding:var(--s5);border:1px solid var(--border);border-left:4px solid ${color}">
          <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:var(--s2);margin-bottom:var(--s2)">
            <h3 style="margin:0;font-size:1.05rem;color:var(--text-primary)">${escapeHTML(ev.title || "Event")}</h3>
            <span style="font-size:0.78rem;color:var(--text-muted);font-weight:600">
              <i class="fas fa-calendar" style="margin-right:4px"></i> ${dateStr}
            </span>
          </div>
          <p style="margin:0 0 var(--s3) 0;font-size:0.88rem;color:var(--text-secondary)">${escapeHTML(ev.description || "")}</p>
          
          ${
            ev.metadata
              ? `<div style="display:flex;gap:var(--s2);flex-wrap:wrap;margin-top:var(--s2)">
                  ${ev.metadata.doctor ? `<span class="badge" style="background:var(--bg-surface);color:var(--text-secondary);font-size:0.75rem"><i class="fas fa-user-md"></i> ${escapeHTML(ev.metadata.doctor)}</span>` : ""}
                  ${ev.metadata.department ? `<span class="badge" style="background:var(--bg-surface);color:var(--text-secondary);font-size:0.75rem"><i class="fas fa-building"></i> ${escapeHTML(ev.metadata.department)}</span>` : ""}
                  ${ev.metadata.status ? `<span class="badge" style="background:var(--primary-subtle);color:var(--primary);font-size:0.75rem">${escapeHTML(ev.metadata.status)}</span>` : ""}
                  ${ev.metadata.diagnosis ? `<span class="badge" style="background:rgba(34, 197, 94, 0.1);color:var(--success);font-size:0.75rem">Diagnosis: ${escapeHTML(ev.metadata.diagnosis)}</span>` : ""}
                 </div>`
              : ""
          }
        </div>

      </div>`;
  }
});