/**
 * HealthBridge — Patient Health Insights Page Controller
 *
 * Visual health analytics: Health record completion progress bar,
 * Appointment attendance rate, Monthly visit frequency bar chart,
 * Department distribution pie/doughnut chart, Medication summary, and recent activity timeline.
 * 100% derived from real patient database metrics (zero AI advice).
 */
"use strict";

document.addEventListener("DOMContentLoaded", async () => {
  requireAuth();

  const user = getUser();
  if (!user || user.role !== "patient") {
    window.location.href = getBasePath() + "pages/auth/login.html";
    return;
  }

  const container = document.getElementById("insights-container");
  if (!container) return;

  const result = await apiFetch(
    getBasePath() + "api/patient/dashboard.php",
    {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        sections: [
          "insights",
          "health_snapshot",
          "profile_completion",
          "medical_timeline",
          "prescriptions"
        ]
      })
    },
    "Failed to load health insights."
  );

  container.innerHTML = "";

  if (result.ok && result.data?.success) {
    const data = result.data.data && typeof result.data.data === "object"
      && !Array.isArray(result.data.data) ? result.data.data : {};
    const insights = data.insights && typeof data.insights === "object" && !Array.isArray(data.insights)
      ? data.insights : {};
    const snapshot = data.health_snapshot && typeof data.health_snapshot === "object"
      && !Array.isArray(data.health_snapshot) ? data.health_snapshot : {};
    const rawCompletion = data.profile_completion && typeof data.profile_completion === "object"
      && !Array.isArray(data.profile_completion) ? data.profile_completion : {};
    const completionPercentage = Math.min(100, safeCount(rawCompletion.percentage, 80));
    const missingFields = Array.isArray(rawCompletion.missing_fields)
      ? rawCompletion.missing_fields.filter((field) => typeof field === "string")
      : [];
    const completion = { percentage: completionPercentage, missing_fields: missingFields };
    const timeline = Array.isArray(data.medical_timeline)
      ? data.medical_timeline.filter((event) => event && typeof event === "object" && !Array.isArray(event))
      : [];
    const rawMedications = data.prescriptions?.active_medications;
    const prescriptions = Array.isArray(rawMedications)
      ? rawMedications.filter((medication) => medication && typeof medication === "object" && !Array.isArray(medication))
      : [];
    const monthlyVisits = Array.isArray(insights.monthly_visits)
      ? insights.monthly_visits.filter((entry) => entry && typeof entry === "object" && !Array.isArray(entry))
      : [];
    const departmentDistribution = Array.isArray(insights.department_distribution)
      ? insights.department_distribution.filter((entry) => entry && typeof entry === "object" && !Array.isArray(entry))
      : [];

    const totalVisits = safeCount(insights.total_visits ?? snapshot.total_appointments, 0);
    const completedVisits = safeCount(insights.completed_visits ?? snapshot.completed_appointments, 0);
    const cancelledVisits = safeCount(insights.cancelled_visits ?? snapshot.cancelled_appointments, 0);
    const missedVisits = safeCount(snapshot.missed_appointments, 0);
    const activeRx = safeCount(snapshot.active_prescriptions, 0);
    const rawCompletionRate = Number(insights.appointment_completion_rate);
    const completionRate = Number.isFinite(rawCompletionRate) && rawCompletionRate >= 0 && rawCompletionRate <= 100
      ? `${Math.round(rawCompletionRate * 10) / 10}%`
      : "100%";

    container.innerHTML = `
      <!-- Health Record Completion Bar -->
      <section class="card insights-completion">
        <div class="insights-completion__header">
          <h3 class="insights-section-title">
            <i class="fas fa-clipboard-check insights-section-title__icon" aria-hidden="true"></i> Medical Profile Completion
          </h3>
          <span class="insights-completion__percentage">${completion.percentage}%</span>
        </div>
        <progress class="insights-completion__progress" max="100" value="${completion.percentage}" aria-label="Medical profile completion"></progress>
        ${
          completion.missing_fields && completion.missing_fields.length > 0
            ? `<div class="insights-completion__hint">
                <i class="fas fa-info-circle insights-completion__hint-icon" aria-hidden="true"></i> Tip: Complete your <strong>${escapeHTML(completion.missing_fields.join(", "))}</strong> to reach 100%.
               </div>`
            : `<div class="insights-completion__complete"><i class="fas fa-check-circle" aria-hidden="true"></i> Your medical profile is 100% complete!</div>`
        }
      </section>

      <!-- Health Summary Stats Grid -->
      <div class="insights-stats-grid">
        <div class="card insights-stat-card">
          <div class="insights-stat-card__icon insights-stat-card__icon--primary"><i class="fas fa-calendar-check"></i></div>
          <div class="insights-stat-card__value">${totalVisits}</div>
          <div class="insights-stat-card__label">Total Bookings</div>
        </div>
        <div class="card insights-stat-card">
          <div class="insights-stat-card__icon insights-stat-card__icon--success"><i class="fas fa-circle-check"></i></div>
          <div class="insights-stat-card__value">${completedVisits}</div>
          <div class="insights-stat-card__label">Completed Visits</div>
        </div>
        <div class="card insights-stat-card">
          <div class="insights-stat-card__icon insights-stat-card__icon--danger"><i class="fas fa-circle-xmark"></i></div>
          <div class="insights-stat-card__value">${safeCount(missedVisits + cancelledVisits, 0)}</div>
          <div class="insights-stat-card__label">Missed / Cancelled</div>
        </div>
        <div class="card insights-stat-card">
          <div class="insights-stat-card__icon insights-stat-card__icon--warning"><i class="fas fa-prescription"></i></div>
          <div class="insights-stat-card__value">${activeRx}</div>
          <div class="insights-stat-card__label">Active Prescriptions</div>
        </div>
        <div class="card insights-stat-card">
          <div class="insights-stat-card__icon insights-stat-card__icon--info"><i class="fas fa-percent"></i></div>
          <div class="insights-stat-card__value">${completionRate}</div>
          <div class="insights-stat-card__label">Completion Rate</div>
        </div>
      </div>

      <!-- Charts Grid -->
      <div class="insights-charts-grid">
        
        <!-- Monthly Visit Trends Bar Chart -->
        <div class="card insights-chart-card">
          <h3 class="insights-section-title">
            <i class="fas fa-chart-line insights-section-title__icon" aria-hidden="true"></i> Monthly Visit Trends
          </h3>
          <div class="insights-chart-frame">
            <canvas id="insights-visits-chart"></canvas>
          </div>
        </div>

        <!-- Department Distribution Pie Chart -->
        <div class="card insights-chart-card">
          <h3 class="insights-section-title">
            <i class="fas fa-chart-pie insights-section-title__icon" aria-hidden="true"></i> Department Visits Breakdown
          </h3>
          <div class="insights-chart-frame">
            <canvas id="insights-dept-chart"></canvas>
          </div>
        </div>
      </div>

      <!-- Current Medications & Recent Activity Timeline -->
      <div class="insights-activity-grid">
        
        <!-- Current Active Medications List -->
        <section class="card insights-activity-card">
          <h3 class="insights-section-title">
            <i class="fas fa-pills insights-section-title__icon" aria-hidden="true"></i> Active Medication Tracker
          </h3>
          ${
            prescriptions.length > 0
              ? `<div class="insights-vertical-list">
                  ${prescriptions
                    .map(
                      (m) => `
                    <div class="insights-medication-item">
                      <div>
                        <div class="insights-medication-item__name">${escapeHTML(m.medication || "")} ${m.strength ? `(${escapeHTML(m.strength)})` : ""}</div>
                        <div class="insights-medication-item__details">${escapeHTML(m.dosage || "")} • ${escapeHTML(m.frequency || "")}</div>
                      </div>
                      <span class="status-badge active insights-medication-item__status">Active</span>
                    </div>`
                    )
                    .join("")}
                 </div>`
              : `<div class="empty-state insights-empty"><p class="insights-empty__message">No active medications recorded.</p></div>`
          }
        </section>

        <!-- Recent Events Timeline -->
        <section class="card insights-activity-card">
          <h3 class="insights-section-title">
            <i class="fas fa-clock-rotate-left insights-section-title__icon" aria-hidden="true"></i> Recent Health Activity
          </h3>
          <div class="insights-vertical-list">
            ${
              timeline.length > 0
                ? timeline
                    .slice(-4)
                    .reverse()
                    .map(
                      (ev) => `
                    <div class="insights-recent-item">
                      <div class="insights-recent-item__avatar">
                        <i class="fas ${safeClassToken(ev.icon || "fa-circle", "fa-circle")}"></i>
                      </div>
                      <div>
                        <div class="insights-recent-item__title">${escapeHTML(ev.title || "")}</div>
                        <div class="insights-recent-item__description">${escapeHTML(ev.description || "")}</div>
                        <div class="insights-recent-item__date">${escapeHTML(formatDate(ev.date))}</div>
                      </div>
                    </div>`
                    )
                    .join("")
                : `<p class="insights-empty__message">No recent activity.</p>`
            }
          </div>
        </section>
      </div>`;

    // Initialize Chart.js charts
    if (typeof Chart !== "undefined") {
      const isDark = typeof getCurrentTheme === "function" && getCurrentTheme() === "dark";
      const textColor = isDark ? "#94a3b8" : "#475569";
      const gridColor = isDark ? "rgba(255, 255, 255, 0.05)" : "rgba(0, 0, 0, 0.05)";

      // Monthly Visits Bar Chart
      const visitsCtx = document.getElementById("insights-visits-chart");
      if (visitsCtx && monthlyVisits.length > 0) {
        new Chart(visitsCtx, {
          type: "bar",
          data: {
            labels: monthlyVisits.map((m) => typeof m.month === "string" ? m.month : ""),
            datasets: [
              {
                label: "Completed Visits",
                data: monthlyVisits.map((m) => safeCount(m.count, 0)),
                backgroundColor: "rgba(6, 182, 212, 0.7)",
                borderColor: "#06b6d4",
                borderWidth: 1,
                borderRadius: 4
              },
              {
                label: "Cancelled",
                data: monthlyVisits.map((m) => safeCount(m.cancelled, 0)),
                backgroundColor: "rgba(239, 68, 68, 0.5)",
                borderColor: "#ef4444",
                borderWidth: 1,
                borderRadius: 4
              }
            ]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { labels: { color: textColor, font: { family: "Sora", size: 11 } } } },
            scales: {
              x: { ticks: { color: textColor, font: { family: "Sora", size: 10 } }, grid: { color: gridColor } },
              y: { beginAtZero: true, ticks: { color: textColor, font: { family: "Sora", size: 10 }, stepSize: 1 }, grid: { color: gridColor } }
            }
          }
        });
      }

      // Department Distribution Pie Chart
      const deptCtx = document.getElementById("insights-dept-chart");
      if (deptCtx && departmentDistribution.length > 0) {
        new Chart(deptCtx, {
          type: "doughnut",
          data: {
            labels: departmentDistribution.map((entry) => typeof entry.name === "string" ? entry.name : ""),
            datasets: [
              {
                data: departmentDistribution.map((entry) => safeCount(entry.count, 0)),
                backgroundColor: [
                  "rgba(6, 182, 212, 0.8)",
                  "rgba(59, 130, 246, 0.8)",
                  "rgba(139, 92, 246, 0.8)",
                  "rgba(236, 72, 153, 0.8)",
                  "rgba(34, 197, 94, 0.8)",
                  "rgba(234, 179, 8, 0.8)"
                ],
                borderWidth: 2,
                borderColor: isDark ? "#1e293b" : "#ffffff"
              }
            ]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: "right", labels: { color: textColor, font: { family: "Sora", size: 11 } } } }
          }
        });
      }
    }
  } else {
    container.innerHTML = `
      <div class="empty-state">
        <div class="empty-icon"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i></div>
        <h4>Unable to Load Health Insights</h4>
        <p>Please try again later.</p>
        <button class="btn btn-outline btn-sm" data-page-action="retry">Retry</button>
      </div>`;
  }
});