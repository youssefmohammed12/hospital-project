/**
 * HealthBridge — Patient Medical History Page
 * Loads and displays medical records for the logged-in patient.
 */
"use strict";

document.addEventListener("DOMContentLoaded", async () => {
  requireAuth();
  
  const user = getUser();
  if (!user || user.role !== "patient") {
    window.location.href = getBasePath() + "pages/auth/login.html";
    return;
  }

  // Use MedicalRecords module (loaded via HTML)
  const container = document.getElementById("medical-record-container");
  if (container && typeof MedicalRecords !== "undefined") {
    MedicalRecords.init(user.id, "patient", "medical-record-container");
  } else {
    // Fallback: load via dashboard API
    const result = await apiFetch(
      getBasePath() + "api/patient/dashboard.php",
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({})
      },
      "Failed to load medical history."
    );
    if (result.ok && result.data?.success) {
      const skeleton = document.getElementById("history-skeleton");
      if (skeleton) skeleton.style.display = "none";
      const timeline = result.data.data?.medical_timeline;
      if (timeline && timeline.length > 0 && container) {
        container.innerHTML = `<h3 style="margin-bottom:var(--s4)"><i class="fas fa-clock-rotate-left" aria-hidden="true"></i> Medical Timeline</h3><div class="med-timeline" id="med-timeline-full"></div>`;
        const timelineEl = document.getElementById("med-timeline-full");
        if (timelineEl) {
          timelineEl.innerHTML = timeline.map(event => {
            const hasDetails = event.metadata && Object.keys(event.metadata).length > 0;
            return `
              <div class="med-timeline-event">
                <div class="med-timeline-marker">
                  <div class="med-timeline-icon" style="color: ${event.color || 'var(--primary)'}; border-color: ${event.color || 'var(--border)'};">
                    <i class="fas ${event.icon || 'fa-circle'}" aria-hidden="true"></i>
                  </div>
                  <div class="med-timeline-date">${formatShortDate(event.date)}</div>
                </div>
                <div class="med-timeline-content">
                  <h4>${escapeHTML(event.title || '')}</h4>
                  <p>${escapeHTML(event.description || '')}</p>
                </div>
              </div>`;
          }).join("");
        }
      } else if (container) {
        container.innerHTML = '<div class="empty-state"><div class="empty-icon"><i class="fas fa-timeline" aria-hidden="true"></i></div><h4>No Medical History Yet</h4><p>Your medical timeline will populate as you visit doctors.</p><a href="dashboard.html" class="btn btn-primary btn-sm">Back to Dashboard</a></div>';
      }
    }
  }
});

function formatShortDate(dateStr) {
  if (!dateStr) return "";
  try {
    const d = new Date(dateStr);
    const now = new Date();
    const isToday = d.toDateString() === now.toDateString();
    if (isToday) return d.toLocaleTimeString("en-US", { hour: "numeric", minute: "2-digit", hour12: true });
    return d.toLocaleDateString("en-US", { month: "short", day: "numeric" });
  } catch { return dateStr; }
}