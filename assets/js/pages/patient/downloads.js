/**
 * HealthBridge — Patient Downloads Page
 * Loads and displays downloadable documents.
 */
"use strict";

document.addEventListener("DOMContentLoaded", async () => {
  requireAuth();
  
  const user = getUser();
  if (!user || user.role !== "patient") {
    window.location.href = getBasePath() + "pages/auth/login.html";
    return;
  }

  const container = document.getElementById("downloads-container");
  if (!container) return;

  const result = await apiFetch(
    getBasePath() + "api/patient/dashboard.php",
    {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({})
    },
    "Failed to load downloads."
  );

  container.innerHTML = "";

  if (result.ok && result.data?.success) {
    const downloads = result.data.data?.downloads;
    const allItems = [
      ...(downloads?.prescriptions || []).map(d => ({ ...d, icon: "fa-prescription" })),
      ...(downloads?.confirmations || []).map(d => ({ ...d, icon: "fa-calendar-check" })),
      ...(downloads?.visit_summaries || []).map(d => ({ ...d, icon: "fa-stethoscope" }))
    ];

    if (allItems.length === 0) {
      container.innerHTML = '<div class="empty-state"><div class="empty-icon"><i class="fas fa-download" aria-hidden="true"></i></div><h4>No Documents Available</h4><p>Visit summaries and reports will appear here after appointments.</p><a href="dashboard.html" class="btn btn-primary btn-sm">Back to Dashboard</a></div>';
      return;
    }

    allItems.forEach(item => {
      container.innerHTML += `
        <div class="dl-preview-item" style="margin-bottom:var(--s2)">
          <div class="dl-icon"><i class="fas ${item.icon || "fa-file"}" aria-hidden="true"></i></div>
          <div class="dl-content">
            <h4>${escapeHTML(item.label || "Document")}</h4>
            <p class="dl-meta">${escapeHTML(item.doctor || "")}${item.date ? " \u00b7 " + formatDate(item.date) : ""}</p>
          </div>
          <a href="${getBasePath()}${item.url}" class="btn btn-outline btn-sm dl-download" target="_blank"><i class="fas fa-download" aria-hidden="true"></i> Download</a>
        </div>`;
    });
  } else {
    container.innerHTML = '<div class="empty-state"><div class="empty-icon"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i></div><h4>Unable to Load Downloads</h4><p>Please try again later.</p><button class="btn btn-outline btn-sm" onclick="location.reload()">Try Again</button></div>';
  }
});