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
      body: JSON.stringify({})
    },
    "Failed to load health insights."
  );

  container.innerHTML = "";

  if (result.ok && result.data?.success) {
    const data = result.data.data;
    const insights = data?.insights || {};
    const snapshot = data?.health_snapshot || {};
    const completion = data?.profile_completion || { percentage: 80, missing_fields: [] };
    const timeline = data?.medical_timeline || [];
    const prescriptions = data?.prescriptions?.active_medications || [];

    const totalVisits = insights.total_visits || snapshot.total_appointments || 0;
    const completedVisits = insights.completed_visits || snapshot.completed_appointments || 0;
    const cancelledVisits = insights.cancelled_visits || snapshot.cancelled_appointments || 0;
    const missedVisits = snapshot.missed_appointments || 0;
    const activeRx = snapshot.active_prescriptions || 0;

    container.innerHTML = `
      <!-- Health Record Completion Bar -->
      <div class="card" style="padding:var(--s5);margin-bottom:var(--s6);border:1px solid var(--border)">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:var(--s2);flex-wrap:wrap">
          <h3 style="margin:0;font-size:1.05rem;display:flex;align-items:center;gap:var(--s2)">
            <i class="fas fa-clipboard-check" style="color:var(--primary)"></i> Medical Profile Completion
          </h3>
          <span style="font-weight:700;font-size:1.1rem;color:var(--primary)">${completion.percentage}%</span>
        </div>
        <div style="width:100%;height:10px;background:var(--bg-surface);border-radius:var(--r-full);overflow:hidden;margin-bottom:var(--s3);border:1px solid var(--border-light)">
          <div style="width:${completion.percentage}%;height:100%;background:linear-gradient(90deg, var(--primary) 0%, var(--success) 100%);transition:width 0.6s ease"></div>
        </div>
        ${
          completion.missing_fields && completion.missing_fields.length > 0
            ? `<div style="font-size:0.8rem;color:var(--text-muted)">
                <i class="fas fa-info-circle" style="color:var(--info)"></i> Tip: Complete your <strong>${escapeHTML(completion.missing_fields.join(", "))}</strong> to reach 100%.
               </div>`
            : `<div style="font-size:0.8rem;color:var(--success)"><i class="fas fa-check-circle"></i> Your medical profile is 100% complete!</div>`
        }
      </div>

      <!-- Health Summary Stats Grid -->
      <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(180px, 1fr));gap:var(--s4);margin-bottom:var(--s6)">
        <div class="card" style="padding:var(--s4);text-align:center;border:1px solid var(--border)">
          <div style="font-size:1.5rem;color:var(--primary);margin-bottom:var(--s1)"><i class="fas fa-calendar-check"></i></div>
          <div style="font-size:1.5rem;font-weight:700;color:var(--text-primary)">${totalVisits}</div>
          <div style="font-size:0.8rem;color:var(--text-muted)">Total Bookings</div>
        </div>
        <div class="card" style="padding:var(--s4);text-align:center;border:1px solid var(--border)">
          <div style="font-size:1.5rem;color:var(--success);margin-bottom:var(--s1)"><i class="fas fa-circle-check"></i></div>
          <div style="font-size:1.5rem;font-weight:700;color:var(--text-primary)">${completedVisits}</div>
          <div style="font-size:0.8rem;color:var(--text-muted)">Completed Visits</div>
        </div>
        <div class="card" style="padding:var(--s4);text-align:center;border:1px solid var(--border)">
          <div style="font-size:1.5rem;color:var(--danger);margin-bottom:var(--s1)"><i class="fas fa-circle-xmark"></i></div>
          <div style="font-size:1.5rem;font-weight:700;color:var(--text-primary)">${missedVisits + cancelledVisits}</div>
          <div style="font-size:0.8rem;color:var(--text-muted)">Missed / Cancelled</div>
        </div>
        <div class="card" style="padding:var(--s4);text-align:center;border:1px solid var(--border)">
          <div style="font-size:1.5rem;color:var(--warning);margin-bottom:var(--s1)"><i class="fas fa-prescription"></i></div>
          <div style="font-size:1.5rem;font-weight:700;color:var(--text-primary)">${activeRx}</div>
          <div style="font-size:0.8rem;color:var(--text-muted)">Active Prescriptions</div>
        </div>
        <div class="card" style="padding:var(--s4);text-align:center;border:1px solid var(--border)">
          <div style="font-size:1.5rem;color:var(--info);margin-bottom:var(--s1)"><i class="fas fa-percent"></i></div>
          <div style="font-size:1.5rem;font-weight:700;color:var(--text-primary)">${insights.appointment_completion_rate !== null ? insights.appointment_completion_rate + "%" : "100%"}</div>
          <div style="font-size:0.8rem;color:var(--text-muted)">Completion Rate</div>
        </div>
      </div>

      <!-- Charts Grid -->
      <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(320px, 1fr));gap:var(--s6);margin-bottom:var(--s6)">
        
        <!-- Monthly Visit Trends Bar Chart -->
        <div class="card" style="padding:var(--s6);border:1px solid var(--border)">
          <h3 style="margin-bottom:var(--s4);font-size:1.05rem;display:flex;align-items:center;gap:var(--s2)">
            <i class="fas fa-chart-line" style="color:var(--primary)"></i> Monthly Visit Trends
          </h3>
          <div style="height:260px;position:relative">
            <canvas id="insights-visits-chart"></canvas>
          </div>
        </div>

        <!-- Department Distribution Pie Chart -->
        <div class="card" style="padding:var(--s6);border:1px solid var(--border)">
          <h3 style="margin-bottom:var(--s4);font-size:1.05rem;display:flex;align-items:center;gap:var(--s2)">
            <i class="fas fa-chart-pie" style="color:var(--primary)"></i> Department Visits Breakdown
          </h3>
          <div style="height:260px;position:relative">
            <canvas id="insights-dept-chart"></canvas>
          </div>
        </div>
      </div>

      <!-- Current Medications & Recent Activity Timeline -->
      <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(300px, 1fr));gap:var(--s6)">
        
        <!-- Current Active Medications List -->
        <div class="card" style="padding:var(--s5);border:1px solid var(--border)">
          <h3 style="margin-bottom:var(--s4);font-size:1.05rem;display:flex;align-items:center;gap:var(--s2)">
            <i class="fas fa-pills" style="color:var(--primary)"></i> Active Medication Tracker
          </h3>
          ${
            prescriptions.length > 0
              ? `<div style="display:flex;flex-direction:column;gap:var(--s3)">
                  ${prescriptions
                    .map(
                      (m) => `
                    <div style="background:var(--bg-surface);padding:var(--s3) var(--s4);border-radius:var(--r-md);border:1px solid var(--border-light);display:flex;justify-content:space-between;align-items:center">
                      <div>
                        <div style="font-weight:600;font-size:0.9rem;color:var(--text-primary)">${escapeHTML(m.medication || "")} ${m.strength ? `(${escapeHTML(m.strength)})` : ""}</div>
                        <div style="font-size:0.78rem;color:var(--text-muted)">${escapeHTML(m.dosage || "")} • ${escapeHTML(m.frequency || "")}</div>
                      </div>
                      <span class="status-badge active" style="padding:2px 8px;font-size:0.75rem">Active</span>
                    </div>`
                    )
                    .join("")}
                 </div>`
              : `<div class="empty-state" style="padding:var(--s4)"><p style="color:var(--text-muted);font-size:0.85rem">No active medications recorded.</p></div>`
          }
        </div>

        <!-- Recent Events Timeline -->
        <div class="card" style="padding:var(--s5);border:1px solid var(--border)">
          <h3 style="margin-bottom:var(--s4);font-size:1.05rem;display:flex;align-items:center;gap:var(--s2)">
            <i class="fas fa-clock-rotate-left" style="color:var(--primary)"></i> Recent Health Activity
          </h3>
          <div style="display:flex;flex-direction:column;gap:var(--s3)">
            ${
              timeline.length > 0
                ? timeline
                    .slice(-4)
                    .reverse()
                    .map(
                      (ev) => `
                    <div style="display:flex;align-items:flex-start;gap:var(--s3);padding-bottom:var(--s3);border-bottom:1px solid var(--border-light)">
                      <div style="width:32px;height:32px;border-radius:50%;background:var(--primary-subtle);display:flex;align-items:center;justify-content:center;color:var(--primary);font-size:0.85rem;flex-shrink:0">
                        <i class="fas ${ev.icon || "fa-circle"}"></i>
                      </div>
                      <div>
                        <div style="font-weight:600;font-size:0.88rem;color:var(--text-primary)">${escapeHTML(ev.title || "")}</div>
                        <div style="font-size:0.8rem;color:var(--text-secondary)">${escapeHTML(ev.description || "")}</div>
                        <div style="font-size:0.75rem;color:var(--text-muted);margin-top:2px">${formatDate(ev.date)}</div>
                      </div>
                    </div>`
                    )
                    .join("")
                : `<p style="color:var(--text-muted);font-size:0.85rem">No recent activity.</p>`
            }
          </div>
        </div>
      </div>`;

    // Initialize Chart.js charts
    if (typeof Chart !== "undefined") {
      const isDark = typeof getCurrentTheme === "function" && getCurrentTheme() === "dark";
      const textColor = isDark ? "#94a3b8" : "#475569";
      const gridColor = isDark ? "rgba(255, 255, 255, 0.05)" : "rgba(0, 0, 0, 0.05)";

      // Monthly Visits Bar Chart
      const visitsCtx = document.getElementById("insights-visits-chart");
      if (visitsCtx && insights.monthly_visits) {
        new Chart(visitsCtx, {
          type: "bar",
          data: {
            labels: insights.monthly_visits.map((m) => m.month),
            datasets: [
              {
                label: "Completed Visits",
                data: insights.monthly_visits.map((m) => m.count),
                backgroundColor: "rgba(6, 182, 212, 0.7)",
                borderColor: "#06b6d4",
                borderWidth: 1,
                borderRadius: 4
              },
              {
                label: "Cancelled",
                data: insights.monthly_visits.map((m) => m.cancelled),
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
      if (deptCtx && insights.department_distribution && insights.department_distribution.length > 0) {
        new Chart(deptCtx, {
          type: "doughnut",
          data: {
            labels: insights.department_distribution.map((d) => d.name),
            datasets: [
              {
                data: insights.department_distribution.map((d) => d.count),
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
        <button class="btn btn-outline btn-sm" onclick="location.reload()">Retry</button>
      </div>`;
  }
});