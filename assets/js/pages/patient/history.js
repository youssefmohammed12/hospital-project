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
      body: JSON.stringify({ sections: ["medical_timeline"] })
    },
    "Failed to load medical history."
  );

  container.innerHTML = "";

  if (result.ok && result.data?.success) {
    const sourceTimeline = result.data.data?.medical_timeline;
    const timeline = Array.isArray(sourceTimeline)
      ? sourceTimeline
          .filter((event) =>
            event &&
            typeof event === "object" &&
            !Array.isArray(event)
          )
          .map((event) => {
            const metadataSource = event.metadata;
            const metadataRecord = metadataSource &&
              typeof metadataSource === "object" &&
              !Array.isArray(metadataSource)
              ? metadataSource
              : null;
            const metadata = metadataRecord
              ? {
                  doctor: typeof metadataRecord.doctor === "string"
                    ? metadataRecord.doctor.slice(0, 160)
                    : "",
                  department: typeof metadataRecord.department === "string"
                    ? metadataRecord.department.slice(0, 160)
                    : "",
                  status: typeof metadataRecord.status === "string"
                    ? metadataRecord.status.slice(0, 100)
                    : "",
                  diagnosis: typeof metadataRecord.diagnosis === "string"
                    ? metadataRecord.diagnosis.slice(0, 500)
                    : "",
                }
              : null;
            const date = typeof event.date === "string"
              ? event.date.trim().slice(0, 40)
              : "";

            return {
              date: date && !Number.isNaN(Date.parse(date)) ? date : "",
              title: typeof event.title === "string" && event.title.trim()
                ? event.title.slice(0, 160)
                : "Event",
              description: typeof event.description === "string"
                ? event.description.slice(0, 1200)
                : "",
              icon: typeof event.icon === "string"
                ? event.icon.slice(0, 40)
                : "fa-circle",
              color: typeof event.color === "string"
                ? event.color.slice(0, 40)
                : "",
              metadata: metadata && Object.values(metadata).some(Boolean)
                ? metadata
                : null,
            };
          })
          // The service sorts oldest-first; retain the newest 200 events.
          .slice(-200)
      : [];

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
      <div class="medical-timeline">
        <div class="medical-timeline__spine" aria-hidden="true"></div>
        <div class="medical-timeline__events">
          ${sorted.map(ev => renderTimelineEvent(ev)).join("")}
        </div>
      </div>`;
  } else {
    container.innerHTML = `
      <div class="empty-state">
        <div class="empty-icon"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i></div>
        <h4>Unable to Load Medical History</h4>
        <p>Please try again later.</p>
        <button class="btn btn-outline btn-sm" data-page-action="retry">Retry</button>
      </div>`;
  }

  function renderTimelineEvent(ev) {
    const colorKey = typeof ev.color === "string"
      ? ev.color.trim().toLowerCase()
      : "";
    const colorVariant = ({
      "var(--primary)": "primary",
      "var(--primary-dark)": "primary-dark",
      "var(--danger)": "danger",
      "var(--success)": "success",
      "var(--warning)": "warning",
      "var(--text-muted)": "muted",
      "#facc15": "rating",
      "#8b5cf6": "suggestion",
    })[colorKey] || "primary";
    const icon = safeClassToken(ev.icon || "fa-circle", "fa-circle");
    const dateStr = escapeHTML(formatDate(ev.date));

    return `
      <div class="medical-timeline__event">
        <div class="medical-timeline__marker medical-timeline__marker--${colorVariant}" aria-hidden="true">
          <i class="fas ${icon}"></i>
        </div>

        <article class="card medical-timeline__card medical-timeline__card--${colorVariant}">
          <div class="medical-timeline__header">
            <h3 class="medical-timeline__title">${escapeHTML(ev.title || "Event")}</h3>
            <span class="medical-timeline__date">
              <i class="fas fa-calendar medical-timeline__date-icon" aria-hidden="true"></i> ${dateStr}
            </span>
          </div>
          <p class="medical-timeline__description">${escapeHTML(ev.description || "")}</p>

          ${
            ev.metadata
              ? `<div class="medical-timeline__metadata">
                  ${ev.metadata.doctor ? `<span class="badge medical-timeline__badge"><i class="fas fa-user-md" aria-hidden="true"></i> ${escapeHTML(ev.metadata.doctor)}</span>` : ""}
                  ${ev.metadata.department ? `<span class="badge medical-timeline__badge"><i class="fas fa-building" aria-hidden="true"></i> ${escapeHTML(ev.metadata.department)}</span>` : ""}
                  ${ev.metadata.status ? `<span class="badge medical-timeline__badge medical-timeline__badge--status">${escapeHTML(ev.metadata.status)}</span>` : ""}
                  ${ev.metadata.diagnosis ? `<span class="badge medical-timeline__badge medical-timeline__badge--diagnosis">Diagnosis: ${escapeHTML(ev.metadata.diagnosis)}</span>` : ""}
                </div>`
              : ""
          }
        </article>
      </div>`;
  }
});