/**
 * HealthBridge — Patient Prescriptions Page
 * Loads prescriptions data from the dashboard API and renders them.
 */
"use strict";

document.addEventListener("DOMContentLoaded", async () => {
  requireAuth();
  
  const user = getUser();
  if (!user || user.role !== "patient") {
    window.location.href = getBasePath() + "pages/auth/login.html";
    return;
  }

  const container = document.getElementById("prescriptions-container");
  if (!container) return;

  // Try to use the Prescriptions module first
  if (typeof Prescriptions !== "undefined") {
    const result = await apiFetch(getBasePath() + "api/prescriptions/get.php", {}, "Failed to load prescriptions");
    if (result.ok && result.data?.success) {
      const prescriptions = result.data.prescriptions || [];
      container.innerHTML = "";
      Prescriptions.renderList(prescriptions, container.id, "patient");
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
    "Failed to load prescriptions."
  );

  container.innerHTML = ""; // Clear skeleton

  if (result.ok && result.data?.success) {
    const rxData = result.data.data?.prescriptions;
    const prescriptions = rxData?.prescriptions || [];

    if (prescriptions.length === 0) {
      container.innerHTML = `
        <div class="empty-state">
          <div class="empty-icon"><i class="fas fa-prescription" aria-hidden="true"></i></div>
          <h4>No Prescriptions Yet</h4>
          <p>When your doctor prescribes medication, it will appear here.</p>
          <a href="dashboard.html" class="btn btn-primary btn-sm">Back to Dashboard</a>
        </div>`;
      return;
    }

    prescriptions.forEach(rx => {
      const items = rx.items || [];
      container.innerHTML += `
        <div class="prescription-card" style="margin-bottom:var(--s4)">
          <div class="prescription-card-header">
            <div class="rx-info">
              <h4>${escapeHTML(rx.doctor_name || "Doctor")}</h4>
              <div class="rx-meta">${formatDate(rx.created_at)} · ${escapeHTML(rx.department || "")}</div>
            </div>
            <span class="status-badge ${rx.status === "Active" ? "active" : rx.status === "Completed" ? "completed" : "cancelled"}">${escapeHTML(rx.status || "")}</span>
          </div>
          <div class="prescription-card-body">
            <div class="rx-medication-list">
              ${items.map(item => `
                <div class="rx-med-item">
                  <span class="rx-med-name">${escapeHTML(item.medication_name || "")} ${escapeHTML(item.strength || "")}</span>
                  <span class="rx-med-dosage">${escapeHTML(item.dosage || "")} · ${escapeHTML(item.frequency || "")}</span>
                </div>
              `).join("")}
            </div>
          </div>
          <div class="prescription-card-footer">
            <button class="btn btn-outline btn-sm" onclick="window.open('${getBasePath()}api/prescriptions/print.php?id=${rx.id}', '_blank')"><i class="fas fa-print" aria-hidden="true"></i> Print</button>
            <button class="btn btn-outline btn-sm" onclick="window.open('${getBasePath()}api/prescriptions/print.php?id=${rx.id}', '_blank')"><i class="fas fa-download" aria-hidden="true"></i> PDF</button>
          </div>
        </div>`;
    });
  } else {
    container.innerHTML = `
      <div class="empty-state">
        <div class="empty-icon"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i></div>
        <h4>Unable to Load Prescriptions</h4>
        <p>Please try again later.</p>
        <button class="btn btn-outline btn-sm" onclick="location.reload()">Try Again</button>
      </div>`;
  }
});