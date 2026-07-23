/**
 * HealthBridge — Patient Insights Page
 * Loads and displays health insights with charts.
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
      body: JSON.stringify({})
    },
    "Failed to load insights."
  );

  container.innerHTML = "";

  if (result.ok && result.data?.success) {
    const insights = result.data.data?.insights;
    if (!insights || !insights.total_visits) {
      container.innerHTML = '<div class="empty-state"><div class="empty-icon"><i class="fas fa-chart-bar" aria-hidden="true"></i></div><h4>No Data Yet</h4><p>Visit a doctor to see your health insights.</p><a href="dashboard.html" class="btn btn-primary btn-sm">Back to Dashboard</a></div>';
      return;
    }

    // Stats cards
    const stats = [
      { icon: "fa-building", value: insights.most_visited_department?.name || "—", label: "Favorite Department" },
      { icon: "fa-percent", value: insights.appointment_attendance_rate !== null ? insights.appointment_attendance_rate + "%" : "—", label: "Attendance Rate" },
      { icon: "fa-check-circle", value: insights.appointment_completion_rate !== null ? insights.appointment_completion_rate + "%" : "—", label: "Completion Rate" },
      { icon: "fa-star", value: insights.average_doctor_rating_given !== null ? insights.average_doctor_rating_given + " / 5" : "—", label: "Avg Rating Given" },
      { icon: "fa-user-doctor", value: insights.unique_doctors_visited || 0, label: "Doctors Visited" },
      { icon: "fa-prescription", value: insights.prescriptions_this_year || 0, label: "Prescriptions This Year" },
      { icon: "fa-calendar", value: insights.visits_this_month || 0, label: "Visits This Month" },
      { icon: "fa-clock", value: insights.average_interval_days ? insights.average_interval_days + " days" : "—", label: "Avg Visit Interval" },
    ];

    container.innerHTML = `
      <div class="insights-stats-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:var(--s4);margin-bottom:var(--s6)">
        ${stats.map(s => `
          <div class="insight-stat" style="background:var(--bg-card);border:1px solid var(--border-light);border-radius:var(--r-lg);padding:var(--s5);text-align:center">
            <div class="stat-icon" style="font-size:1.5rem;color:var(--primary);margin-bottom:var(--s2)"><i class="fas ${s.icon}" aria-hidden="true"></i></div>
            <div class="stat-value" style="font-size:1.5rem;font-weight:700">${escapeHTML(String(s.value))}</div>
            <div class="stat-label" style="font-size:0.8rem;color:var(--text-muted)">${escapeHTML(s.label)}</div>
          </div>
        `).join("")}
      </div>
      <div id="chart-container" style="background:var(--bg-card);border:1px solid var(--border-light);border-radius:var(--r-lg);padding:var(--s6)">
        <h3 style="margin-bottom:var(--s4)"><i class="fas fa-chart-line" aria-hidden="true"></i> Monthly Visits</h3>
        <canvas id="visits-chart" style="height:300px"></canvas>
      </div>`;

    // Render chart
    if (insights.monthly_visits && insights.monthly_visits.length > 0 && typeof Chart !== "undefined") {
      const isDark = getCurrentTheme() === "dark";
      const textColor = isDark ? "#7fb3d3" : "#4a6b8a";
      const gridColor = isDark ? "rgba(34, 211, 238, 0.08)" : "rgba(0, 0, 0, 0.06)";

      new Chart(document.getElementById("visits-chart"), {
        type: "bar",
        data: {
          labels: insights.monthly_visits.map(m => m.month),
          datasets: [
            { label: "Visits", data: insights.monthly_visits.map(m => m.count), backgroundColor: isDark ? "rgba(34, 211, 238, 0.6)" : "rgba(6, 182, 212, 0.6)", borderColor: isDark ? "#22d3ee" : "#06b6d4", borderWidth: 1, borderRadius: 4 },
            { label: "Cancelled", data: insights.monthly_visits.map(m => m.cancelled), backgroundColor: isDark ? "rgba(252, 165, 165, 0.4)" : "rgba(239, 68, 68, 0.4)", borderColor: isDark ? "#fca5a5" : "#ef4444", borderWidth: 1, borderRadius: 4 }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { labels: { color: textColor, font: { family: "Sora" } } } },
          scales: {
            x: { ticks: { color: textColor, font: { family: "Sora", size: 11 } }, grid: { color: gridColor } },
            y: { beginAtZero: true, ticks: { color: textColor, font: { family: "Sora", size: 11 }, stepSize: 1 }, grid: { color: gridColor } }
          }
        }
      });
    }
  } else {
    container.innerHTML = '<div class="empty-state"><div class="empty-icon"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i></div><h4>Unable to Load Insights</h4><p>Please try again later.</p><button class="btn btn-outline btn-sm" onclick="location.reload()">Try Again</button></div>';
  }
});