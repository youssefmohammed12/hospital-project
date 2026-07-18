/**
 * HealthBridge — Admin Analytics Dashboard Module
 *
 * Professional dashboard with Chart.js visualizations, KPI cards,
 * activity timeline, quick actions, and system status widgets.
 *
 * Features:
 *   - Single API call for all dashboard data
 *   - Theme-aware charts (auto-update on theme change)
 *   - Skeleton loaders during data fetch
 *   - Empty and error states for every widget
 *   - Chart export (PNG) and CSV download
 *   - Circular progress for completion rate
 *   - Relative timestamps for activity feed
 *   - Responsive layout
 *   - ARIA labels and keyboard navigation
 *
 * Dependencies: Chart.js (loaded from CDN), main.js helpers
 */

"use strict";

// ─── Module State ──────────────────────────────────────────

const AdminDashboard = {
  data: null,
  charts: {},
  theme: getCurrentTheme(),
  initialized: false,
  basePath: getBasePath(),
};

// ─── Color Palette (matches CSS variables) ─────────────────

const DASHBOARD_COLORS = {
  primary: "#22d3ee",
  success: "#86efac",
  warning: "#facc15",
  danger: "#fca5a5",
  secondary: "#7fb3d3",
  info: "#7dd3fc",
  purple: "#a78bfa",
  orange: "#fb923c",
  text: "#e0f7fa",
  muted: "#4a7fb5",
  border: "rgba(34, 211, 238, 0.1)",
};

const DASHBOARD_COLORS_LIGHT = {
  primary: "#0891b2",
  success: "#16a34a",
  warning: "#b45309",
  danger: "#b91c1c",
  secondary: "#334e68",
  info: "#0e7490",
  purple: "#7c3aed",
  orange: "#ea580c",
  text: "#0f172a",
  muted: "#64748b",
  border: "rgba(8, 145, 178, 0.18)",
};

function getColors() {
  return getCurrentTheme() === "light"
    ? DASHBOARD_COLORS_LIGHT
    : DASHBOARD_COLORS;
}

function getGridColor() {
  return getCurrentTheme() === "light"
    ? "rgba(8, 145, 178, 0.08)"
    : "rgba(34, 211, 238, 0.06)";
}

// ─── Initialization ────────────────────────────────────────

/**
 * Initialize the admin dashboard.
 * Called from admin.js after auth check.
 */
async function initAdminDashboard() {
  if (AdminDashboard.initialized) return;
  AdminDashboard.initialized = true;

  // Load Chart.js from CDN if not already loaded
  if (typeof Chart === "undefined") {
    await loadChartJS();
  }

  // Register theme change listener for chart recreation
  window.addEventListener("themechange", (e) => {
    AdminDashboard.theme = e.detail.theme;
    if (AdminDashboard.data) {
      recreateAllCharts();
    }
  });

  // Load dashboard data
  await loadDashboardData();
}

/**
 * Dynamically load Chart.js from CDN.
 */
function loadChartJS() {
  return new Promise((resolve, reject) => {
    if (typeof Chart !== "undefined") {
      resolve();
      return;
    }
    const script = document.createElement("script");
    script.src =
      "https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js";
    script.crossOrigin = "anonymous";
    script.onload = () => {
      // Register the Chart.js plugin for chart export
      resolve();
    };
    script.onerror = () => {
      console.error("Failed to load Chart.js");
      reject(new Error("Chart.js load failed"));
    };
    document.head.appendChild(script);
  });
}

// ─── Data Loading ──────────────────────────────────────────

async function loadDashboardData() {
  showSkeletons();

  const result = await apiFetch(
    AdminDashboard.basePath + "api/admin/dashboard-analytics.php",
    {},
    "Failed to load dashboard analytics. Please ensure XAMPP is running.",
  );

  if (!result.ok || !result.data?.success) {
    showErrorState(result.data?.message || "Failed to load dashboard data.");
    return;
  }

  AdminDashboard.data = result.data;
  renderDashboard();
}

// ─── Rendering ─────────────────────────────────────────────

function renderDashboard() {
  const data = AdminDashboard.data;
  if (!data) return;

  hideSkeletons();
  renderGreeting(data);
  renderKPIs(data.kpi);
  renderAppointmentCharts(data.appointment_analytics);
  renderPatientCharts(data.patient_analytics);
  renderDoctorAnalytics(data.doctor_analytics);
  renderDepartmentAnalytics(data.department_analytics);
  renderActivityTimeline(data.recent_activity);
  renderQuickActions();
  renderSystemStatus(data.system_status);
}

// ─── Greeting ──────────────────────────────────────────────

function renderGreeting(data) {
  const container = document.getElementById("dashboard-greeting");
  if (!container) return;

  const kpi = data.kpi;
  const status = data.system_status;
  const hour = new Date().getHours();
  const greeting =
    hour < 12 ? "Good Morning" : hour < 18 ? "Good Afternoon" : "Good Evening";

  const openStatus = status.is_open
    ? '<span class="greeting-success">Open</span>'
    : '<span class="greeting-danger">Closed</span>';

  const unavailableCount = data.doctor_analytics?.unavailable_today ?? 0;
  const unavailableText =
    unavailableCount > 0
      ? `<span class="greeting-warning">${unavailableCount} doctors unavailable</span>`
      : "all doctors available";

  container.innerHTML = `
    <h2><i class="fas fa-sparkles" aria-hidden="true"></i> ${greeting}, Admin</h2>
    <p>
      Today there are <span class="greeting-highlight">${kpi.today_appointments} appointments</span>,
      <span class="greeting-warning">${kpi.pending_appointments} pending approvals</span>,
      ${unavailableText}, and the hospital is ${openStatus}.
    </p>
  `;
}

// ─── KPI Cards ─────────────────────────────────────────────

function renderKPIs(kpi) {
  if (!kpi) return;

  const cards = [
    {
      id: "kpi-patients",
      icon: "fa-users",
      value: kpi.total_patients,
      label: "Total Patients",
      color: "kpi-primary",
    },
    {
      id: "kpi-doctors",
      icon: "fa-user-doctor",
      value: kpi.total_doctors,
      label: "Total Doctors",
      color: "kpi-success",
    },
    {
      id: "kpi-departments",
      icon: "fa-building",
      value: kpi.total_departments,
      label: "Active Departments",
      color: "kpi-info",
    },
    {
      id: "kpi-today",
      icon: "fa-calendar-day",
      value: kpi.today_appointments,
      label: "Today's Appointments",
      color: "kpi-primary",
    },
    {
      id: "kpi-pending",
      icon: "fa-hourglass-half",
      value: kpi.pending_appointments,
      label: "Pending Appointments",
      color: "kpi-warning",
    },
    {
      id: "kpi-confirmed",
      icon: "fa-circle-check",
      value: kpi.confirmed_appointments,
      label: "Confirmed Appointments",
      color: "kpi-success",
    },
    {
      id: "kpi-active-doctors",
      icon: "fa-heart-pulse",
      value: kpi.active_doctors,
      label: "Active Doctors",
      color: "kpi-danger",
    },
    {
      id: "kpi-rating",
      icon: "fa-star",
      value: kpi.avg_rating,
      label: "Avg Doctor Rating",
      color: "kpi-warning",
    },
  ];

  cards.forEach((card) => {
    const el = document.getElementById(card.id);
    if (!el) return;

    const trendHtml =
      card.id === "kpi-patients" && kpi.new_patients_this_month > 0
        ? `<div class="kpi-trend"><i class="fas fa-user-plus" aria-hidden="true"></i> ${kpi.new_patients_this_month} new this month</div>`
        : "";

    el.innerHTML = `
      <div class="kpi-icon-wrapper"><i class="fas ${card.icon}" aria-hidden="true"></i></div>
      <div class="kpi-content">
        <div class="kpi-value">${escapeHTML(String(card.value))}</div>
        <div class="kpi-label">${escapeHTML(card.label)}</div>
        ${trendHtml}
      </div>
    `;
    el.className = `kpi-card ${card.color}`;
  });
}

// ─── Appointment Charts ────────────────────────────────────

function renderAppointmentCharts(analytics) {
  if (!analytics) return;

  renderWeeklyChart(analytics.weekly);
  renderMonthlyChart(analytics.monthly);
  renderStatusChart(analytics.status_distribution);
  renderCompletionRate(
    analytics.completion_rate,
    analytics.status_distribution,
  );
}

function renderWeeklyChart(weeklyData) {
  const canvas = document.getElementById("chart-weekly");
  if (!canvas) return;

  const ctx = canvas.getContext("2d");
  const colors = getColors();
  const gridColor = getGridColor();

  const labels = weeklyData?.length
    ? weeklyData.map((d) => d.day?.substring(0, 3) || "N/A")
    : ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"];
  const values = weeklyData?.length
    ? weeklyData.map((d) => parseInt(d.count) || 0)
    : [0, 0, 0, 0, 0, 0, 0];

  destroyChart("weekly");
  AdminDashboard.charts.weekly = new Chart(ctx, {
    type: "bar",
    data: {
      labels: labels,
      datasets: [
        {
          label: "Appointments",
          data: values,
          backgroundColor: colors.primary + "40",
          borderColor: colors.primary,
          borderWidth: 2,
          borderRadius: 4,
          hoverBackgroundColor: colors.primary + "80",
        },
      ],
    },
    options: getBarChartOptions("Weekly Appointments", colors, gridColor),
    plugins: [chartExportPlugin("weekly")],
  });
}

function renderMonthlyChart(monthlyData) {
  const canvas = document.getElementById("chart-monthly");
  if (!canvas) return;

  const ctx = canvas.getContext("2d");
  const colors = getColors();
  const gridColor = getGridColor();

  const labels = monthlyData?.length
    ? monthlyData.map((d) => d.month || "N/A")
    : [];
  const values = monthlyData?.length
    ? monthlyData.map((d) => parseInt(d.count) || 0)
    : [];

  destroyChart("monthly");
  AdminDashboard.charts.monthly = new Chart(ctx, {
    type: "line",
    data: {
      labels: labels,
      datasets: [
        {
          label: "Appointments",
          data: values,
          borderColor: colors.success,
          backgroundColor: colors.success + "15",
          borderWidth: 2.5,
          fill: true,
          tension: 0.35,
          pointBackgroundColor: colors.success,
          pointBorderColor: colors.success,
          pointRadius: 4,
          pointHoverRadius: 6,
        },
      ],
    },
    options: getLineChartOptions("Monthly Appointments", colors, gridColor),
    plugins: [chartExportPlugin("monthly")],
  });
}

function renderStatusChart(statusData) {
  const canvas = document.getElementById("chart-status");
  if (!canvas) return;

  const ctx = canvas.getContext("2d");
  const colors = getColors();

  const hasData = statusData?.length;
  const labels = hasData
    ? statusData.map((d) => d.status || "Unknown")
    : ["No Data"];
  const values = hasData ? statusData.map((d) => parseInt(d.count) || 0) : [1];
  const bgColors = hasData
    ? statusData.map((d) => {
        const s = (d.status || "").toLowerCase();
        if (s === "confirmed") return colors.success;
        if (s === "pending") return colors.warning;
        if (s === "cancelled") return colors.danger;
        return colors.secondary;
      })
    : [colors.border];

  destroyChart("status");
  AdminDashboard.charts.status = new Chart(ctx, {
    type: "doughnut",
    data: {
      labels: labels,
      datasets: [
        {
          data: values,
          backgroundColor: bgColors,
          borderColor: getCurrentTheme() === "light" ? "#fff" : "#111e33",
          borderWidth: 2,
          hoverOffset: 6,
        },
      ],
    },
    options: getDoughnutChartOptions("Appointment Status", colors),
    plugins: [chartExportPlugin("status")],
  });
}

function renderCompletionRate(rate, statusData) {
  const container = document.getElementById("completion-rate-content");
  if (!container) return;

  const completionRate = rate || 0;
  const circumference = 2 * Math.PI * 54; // r=54
  const offset = circumference - (completionRate / 100) * circumference;

  // Count confirmed and completed from status distribution
  let confirmed = 0;
  let completed = 0;
  if (statusData?.length) {
    statusData.forEach((d) => {
      const s = (d.status || "").toLowerCase();
      if (s === "confirmed") confirmed = parseInt(d.count) || 0;
    });
  }
  completed = Math.round((completionRate / 100) * confirmed);

  container.innerHTML = `
    <div class="completion-rate-container">
      <div class="circular-progress" role="img" aria-label="Appointment completion rate: ${completionRate}%">
        <svg viewBox="0 0 120 120" aria-hidden="true">
          <circle class="bg-circle" cx="60" cy="60" r="54" />
          <circle class="progress-circle" cx="60" cy="60" r="54"
            stroke-dasharray="${circumference}"
            stroke-dashoffset="${offset}" />
        </svg>
        <div class="progress-text">
          <span class="progress-value">${completionRate}%</span>
          <span class="progress-label">Completed</span>
        </div>
      </div>
      <div class="completion-stats">
        <div class="completion-stat">
          <span class="stat-dot completed" aria-hidden="true"></span>
          <span>Completed Visits</span>
          <span class="stat-value">${completed}</span>
        </div>
        <div class="completion-stat">
          <span class="stat-dot pending" aria-hidden="true"></span>
          <span>Total Confirmed</span>
          <span class="stat-value">${confirmed}</span>
        </div>
      </div>
    </div>
  `;
}

// ─── Patient Charts ────────────────────────────────────────

function renderPatientCharts(analytics) {
  if (!analytics) return;

  renderGenderChart(analytics.gender_distribution);
  renderAgeChart(analytics.age_distribution);
  renderActivePatients(analytics.most_active);
}

function renderGenderChart(genderData) {
  const canvas = document.getElementById("chart-gender");
  if (!canvas) return;

  const ctx = canvas.getContext("2d");
  const colors = getColors();

  const hasData = genderData?.length;
  const labels = hasData
    ? genderData.map((d) => d.gender || "Unknown")
    : ["No Data"];
  const values = hasData ? genderData.map((d) => parseInt(d.count) || 0) : [1];
  const bgColors = hasData
    ? genderData.map((d, i) => {
        const g = (d.gender || "").toLowerCase();
        if (g === "male") return colors.primary;
        if (g === "female") return colors.danger;
        if (g === "not specified") return colors.muted;
        return [colors.success, colors.warning, colors.info, colors.purple][
          i % 4
        ];
      })
    : [colors.border];

  destroyChart("gender");
  AdminDashboard.charts.gender = new Chart(ctx, {
    type: "pie",
    data: {
      labels: labels,
      datasets: [
        {
          data: values,
          backgroundColor: bgColors,
          borderColor: getCurrentTheme() === "light" ? "#fff" : "#111e33",
          borderWidth: 2,
          hoverOffset: 6,
        },
      ],
    },
    options: getPieChartOptions("Gender Distribution", colors),
    plugins: [chartExportPlugin("gender")],
  });
}

function renderAgeChart(ageData) {
  const canvas = document.getElementById("chart-age");
  if (!canvas) return;

  const ctx = canvas.getContext("2d");
  const colors = getColors();
  const gridColor = getGridColor();

  const hasData = ageData?.length;
  const labels = hasData
    ? ageData.map((d) => d.age_range || "Unknown")
    : ["No Data"];
  const values = hasData ? ageData.map((d) => parseInt(d.count) || 0) : [0];

  destroyChart("age");
  AdminDashboard.charts.age = new Chart(ctx, {
    type: "bar",
    data: {
      labels: labels,
      datasets: [
        {
          label: "Patients",
          data: values,
          backgroundColor: colors.info + "40",
          borderColor: colors.info,
          borderWidth: 2,
          borderRadius: 4,
          hoverBackgroundColor: colors.info + "80",
        },
      ],
    },
    options: getBarChartOptions("Age Distribution", colors, gridColor),
    plugins: [chartExportPlugin("age")],
  });
}

function renderActivePatients(activeData) {
  const container = document.getElementById("active-patients-content");
  if (!container) return;

  if (!activeData?.length) {
    container.innerHTML = `
      <div class="dashboard-empty">
        <i class="fas fa-users" aria-hidden="true"></i>
        <h4>No Active Patients</h4>
        <p>Patient appointment data will appear here once patients start booking.</p>
      </div>
    `;
    return;
  }

  container.innerHTML = `
    <div class="dept-table-wrap">
      <table role="table" aria-label="Most active patients">
        <thead>
          <tr>
            <th>Patient</th>
            <th>Email</th>
            <th>Appointments</th>
          </tr>
        </thead>
        <tbody>
          ${activeData
            .map(
              (p) => `
            <tr>
              <td class="dept-name-cell">${escapeHTML(p.name || "—")}</td>
              <td>${escapeHTML(p.email || "—")}</td>
              <td><strong>${parseInt(p.appointment_count) || 0}</strong></td>
            </tr>
          `,
            )
            .join("")}
        </tbody>
      </table>
    </div>
  `;
}

// ─── Doctor Analytics ──────────────────────────────────────

function renderDoctorAnalytics(analytics) {
  if (!analytics) return;

  renderHighestRatedChart(analytics.highest_rated);
  renderBusiestDoctors(analytics.busiest_doctors);
  renderDoctorAvailability(
    analytics.available_today,
    analytics.unavailable_today,
  );
}

function renderHighestRatedChart(ratedData) {
  const canvas = document.getElementById("chart-highest-rated");
  if (!canvas) return;

  const ctx = canvas.getContext("2d");
  const colors = getColors();
  const gridColor = getGridColor();

  const hasData = ratedData?.length;
  const labels = hasData
    ? ratedData.map((d) => d.name?.split(" ").slice(0, 2).join(" ") || "N/A")
    : ["No Data"];
  const values = hasData
    ? ratedData.map((d) => parseFloat(d.rating) || 0)
    : [0];
  const bgColors = hasData
    ? values.map((v) =>
        v >= 4.8 ? colors.success : v >= 4.5 ? colors.primary : colors.warning,
      )
    : [colors.border];

  destroyChart("highestRated");
  AdminDashboard.charts.highestRated = new Chart(ctx, {
    type: "bar",
    data: {
      labels: labels,
      datasets: [
        {
          label: "Rating",
          data: values,
          backgroundColor: bgColors.map((c) => c + "60"),
          borderColor: bgColors,
          borderWidth: 2,
          borderRadius: 4,
        },
      ],
    },
    options: {
      indexAxis: "y",
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: getCurrentTheme() === "light" ? "#fff" : "#111e33",
          titleColor: getCurrentTheme() === "light" ? "#0f172a" : "#e0f7fa",
          bodyColor: getCurrentTheme() === "light" ? "#334e68" : "#7fb3d3",
          borderColor:
            getCurrentTheme() === "light"
              ? "rgba(8, 145, 178, 0.2)"
              : "rgba(34, 211, 238, 0.2)",
          borderWidth: 1,
          padding: 10,
          cornerRadius: 8,
          callbacks: {
            label: (ctx) => `Rating: ${ctx.parsed.x} / 5.0`,
          },
        },
      },
      scales: {
        x: {
          min: 0,
          max: 5,
          grid: { color: gridColor },
          ticks: { color: colors.muted, font: { size: 11 } },
        },
        y: {
          grid: { display: false },
          ticks: { color: colors.muted, font: { size: 10 } },
        },
      },
    },
    plugins: [chartExportPlugin("highestRated")],
  });
}

function renderBusiestDoctors(busyData) {
  const container = document.getElementById("busiest-doctors-content");
  if (!container) return;

  if (!busyData?.length) {
    container.innerHTML = `
      <div class="dashboard-empty">
        <i class="fas fa-user-doctor" aria-hidden="true"></i>
        <h4>No Doctor Activity</h4>
        <p>Appointment data will appear here once doctors start seeing patients.</p>
      </div>
    `;
    return;
  }

  container.innerHTML = `
    <div class="dept-table-wrap">
      <table role="table" aria-label="Busiest doctors">
        <thead>
          <tr>
            <th>Doctor</th>
            <th>Specialty</th>
            <th>Appointments</th>
          </tr>
        </thead>
        <tbody>
          ${busyData
            .map(
              (d) => `
            <tr>
              <td class="dept-name-cell">${escapeHTML(d.name || "—")}</td>
              <td>${escapeHTML(d.specialty || "—")}</td>
              <td><strong>${parseInt(d.appointment_count) || 0}</strong></td>
            </tr>
          `,
            )
            .join("")}
        </tbody>
      </table>
    </div>
  `;
}

function renderDoctorAvailability(available, unavailable) {
  const container = document.getElementById("doctor-availability-content");
  if (!container) return;

  container.innerHTML = `
    <div class="status-widgets">
      <div class="status-widget">
        <div class="status-widget-icon icon-open"><i class="fas fa-check-circle" aria-hidden="true"></i></div>
        <div class="status-widget-content">
          <p class="status-widget-value">${available}</p>
          <p class="status-widget-label">Available Today</p>
        </div>
      </div>
      <div class="status-widget">
        <div class="status-widget-icon icon-closed"><i class="fas fa-circle-xmark" aria-hidden="true"></i></div>
        <div class="status-widget-content">
          <p class="status-widget-value">${unavailable}</p>
          <p class="status-widget-label">Unavailable Today</p>
        </div>
      </div>
    </div>
  `;
}

// ─── Department Analytics ──────────────────────────────────

function renderDepartmentAnalytics(deptData) {
  if (!deptData) return;

  renderDeptChart(deptData);
  renderDeptTable(deptData);
}

function renderDeptChart(deptData) {
  const canvas = document.getElementById("chart-departments");
  if (!canvas) return;

  const ctx = canvas.getContext("2d");
  const colors = getColors();
  const gridColor = getGridColor();

  const hasData = deptData?.length;
  const labels = hasData
    ? deptData.map((d) => d.name?.substring(0, 10) || "N/A")
    : ["No Data"];
  const doctors = hasData
    ? deptData.map((d) => parseInt(d.doctor_count) || 0)
    : [0];
  const patients = hasData
    ? deptData.map((d) => parseInt(d.patient_count) || 0)
    : [0];
  const appointments = hasData
    ? deptData.map((d) => parseInt(d.appointment_count) || 0)
    : [0];

  destroyChart("departments");
  AdminDashboard.charts.departments = new Chart(ctx, {
    type: "bar",
    data: {
      labels: labels,
      datasets: [
        {
          label: "Doctors",
          data: doctors,
          backgroundColor: colors.primary + "60",
          borderColor: colors.primary,
          borderWidth: 1,
          borderRadius: 2,
        },
        {
          label: "Patients",
          data: patients,
          backgroundColor: colors.success + "60",
          borderColor: colors.success,
          borderWidth: 1,
          borderRadius: 2,
        },
        {
          label: "Appointments",
          data: appointments,
          backgroundColor: colors.warning + "60",
          borderColor: colors.warning,
          borderWidth: 1,
          borderRadius: 2,
        },
      ],
    },
    options: getStackedBarChartOptions(
      "Department Overview",
      colors,
      gridColor,
    ),
    plugins: [chartExportPlugin("departments")],
  });
}

function renderDeptTable(deptData) {
  const container = document.getElementById("dept-table-content");
  if (!container) return;

  if (!deptData?.length) {
    container.innerHTML = `
      <div class="dashboard-empty">
        <i class="fas fa-building" aria-hidden="true"></i>
        <h4>No Departments</h4>
        <p>Department data will appear here once departments are created.</p>
      </div>
    `;
    return;
  }

  container.innerHTML = `
    <div class="dept-table-wrap">
      <table role="table" aria-label="Department analytics">
        <thead>
          <tr>
            <th>Department</th>
            <th>Status</th>
            <th>Doctors</th>
            <th>Patients</th>
            <th>Appointments</th>
          </tr>
        </thead>
        <tbody>
          ${deptData
            .map(
              (d) => `
            <tr>
              <td class="dept-name-cell">${escapeHTML(d.name || "—")}</td>
              <td><span class="status ${d.status === "active" ? "status-active" : "status-disabled"}">${escapeHTML(d.status || "—")}</span></td>
              <td><strong>${parseInt(d.doctor_count) || 0}</strong></td>
              <td><strong>${parseInt(d.patient_count) || 0}</strong></td>
              <td><strong>${parseInt(d.appointment_count) || 0}</strong></td>
            </tr>
          `,
            )
            .join("")}
        </tbody>
      </table>
    </div>
  `;
}

// ─── Activity Timeline ─────────────────────────────────────

function renderActivityTimeline(activities) {
  const container = document.getElementById("activity-timeline-content");
  if (!container) return;

  if (!activities?.length) {
    container.innerHTML = `
      <div class="dashboard-empty">
        <i class="fas fa-clock-rotate-left" aria-hidden="true"></i>
        <h4>No Recent Activity</h4>
        <p>System activity will appear here as actions are performed.</p>
      </div>
    `;
    return;
  }

  container.innerHTML = `
    <div class="activity-timeline" role="feed" aria-label="Recent system activity">
      ${activities.map((a) => renderActivityItem(a)).join("")}
    </div>
  `;
}

function renderActivityItem(a) {
  const action = (a.action || "").toLowerCase();
  const entityType = (a.entity_type || "").toLowerCase();

  // Determine icon class based on action
  let iconClass = "icon-default";
  let icon = "fa-circle-info";
  if (action.includes("create") || action === "add") {
    iconClass = "icon-create";
    icon = "fa-plus-circle";
  } else if (
    action.includes("update") ||
    action.includes("edit") ||
    action.includes("save")
  ) {
    iconClass = "icon-update";
    icon = "fa-pen-to-square";
  } else if (action.includes("delete") || action.includes("remove")) {
    iconClass = "icon-delete";
    icon = "fa-trash-can";
  } else if (action.includes("reassign") || action.includes("transfer")) {
    iconClass = "icon-action";
    icon = "fa-right-left";
  } else if (action.includes("activate") || action.includes("approve")) {
    iconClass = "icon-create";
    icon = "fa-toggle-on";
  } else if (action.includes("deactivate") || action.includes("decline")) {
    iconClass = "icon-delete";
    icon = "fa-toggle-off";
  }

  // Badge class
  let badgeClass = "badge-default";
  if (iconClass === "icon-create") badgeClass = "badge-create";
  else if (iconClass === "icon-update") badgeClass = "badge-update";
  else if (iconClass === "icon-delete") badgeClass = "badge-delete";
  else if (iconClass === "icon-action") badgeClass = "badge-reassign";

  // Entity icon
  let entityIcon = "fa-cube";
  if (entityType === "doctor") entityIcon = "fa-user-doctor";
  else if (entityType === "patient") entityIcon = "fa-user";
  else if (entityType === "department") entityIcon = "fa-building";
  else if (entityType === "appointment") entityIcon = "fa-calendar-days";
  else if (entityType === "hospital_settings") entityIcon = "fa-sliders";
  else if (entityType === "prescription") entityIcon = "fa-prescription";

  const description = a.description || `${action} ${entityType}`;

  return `
    <div class="activity-item" role="article" aria-label="Activity: ${escapeHTML(action)} ${escapeHTML(entityType)}">
      <div class="activity-icon-wrapper ${iconClass}" aria-hidden="true">
        <i class="fas ${icon}"></i>
      </div>
      <div class="activity-body">
        <div class="activity-header">
          <span class="activity-actor">${escapeHTML(a.actor_name || "System")}</span>
          <span class="activity-action-badge ${badgeClass}">${escapeHTML(action)}</span>
        </div>
        <p class="activity-description">${escapeHTML(description)}</p>
        <div class="activity-meta">
          <span class="activity-time"><i class="far fa-clock" aria-hidden="true"></i> ${escapeHTML(a.time_ago || "")}</span>
          <span class="activity-entity-icon"><i class="fas ${entityIcon}" aria-hidden="true"></i> ${escapeHTML(entityType)}</span>
        </div>
      </div>
    </div>
  `;
}

// ─── Quick Actions ─────────────────────────────────────────

function renderQuickActions() {
  const container = document.getElementById("quick-actions-content");
  if (!container) return;

  const actions = [
    {
      icon: "fa-user-doctor",
      label: "Add Doctor",
      desc: "Register a new doctor",
      onclick: "toggleAddDoctorModal()",
    },
    {
      icon: "fa-user-plus",
      label: "Add Patient",
      desc: "Create a new patient",
      onclick: "toggleAddPatientModal()",
    },
    {
      icon: "fa-building",
      label: "Add Department",
      desc: "Create a department",
      onclick: "toggleAddDepartmentModal()",
    },
    {
      icon: "fa-clock-rotate-left",
      label: "Audit Log",
      desc: "View system activity",
      onclick:
        "document.querySelector('.sidebar-nav a[href=\\'#audit-log\\']')?.click()",
    },
    {
      icon: "fa-sliders",
      label: "Settings",
      desc: "Hospital configuration",
      onclick:
        "document.querySelector('.sidebar-nav a[href=\\'#system-settings\\']')?.click()",
    },
    {
      icon: "fa-arrows-rotate",
      label: "Refresh",
      desc: "Reload dashboard data",
      onclick: "refreshAdminDashboard()",
    },
  ];

  container.innerHTML = `
    <div class="quick-actions-grid">
      ${actions
        .map(
          (a) => `
        <div class="quick-action-card" onclick="${a.onclick}" role="button" tabindex="0"
             onkeydown="if(event.key==='Enter'||event.key===' '){${a.onclick};event.preventDefault();}"
             aria-label="${escapeHTML(a.label)}">
          <div class="quick-action-icon"><i class="fas ${a.icon}" aria-hidden="true"></i></div>
          <p class="quick-action-label">${escapeHTML(a.label)}</p>
          <p class="quick-action-desc">${escapeHTML(a.desc)}</p>
        </div>
      `,
        )
        .join("")}
    </div>
  `;
}

// ─── System Status ─────────────────────────────────────────

function renderSystemStatus(status) {
  if (!status) return;

  const container = document.getElementById("system-status-content");
  if (!container) return;

  const openIcon = status.is_open ? "icon-open" : "icon-closed";
  const openIconFa = status.is_open ? "fa-door-open" : "fa-door-closed";
  const openLabel = status.is_open ? "Open" : "Closed";

  container.innerHTML = `
    <div class="status-widgets">
      <div class="status-widget">
        <div class="status-widget-icon ${openIcon}"><i class="fas ${openIconFa}" aria-hidden="true"></i></div>
        <div class="status-widget-content">
          <p class="status-widget-value">${openLabel}</p>
          <p class="status-widget-label">Hospital Status</p>
        </div>
      </div>
      <div class="status-widget">
        <div class="status-widget-icon icon-info"><i class="fas fa-clock" aria-hidden="true"></i></div>
        <div class="status-widget-content">
          <p class="status-widget-value" id="dashboard-current-time">${escapeHTML(status.current_time_formatted || "")}</p>
          <p class="status-widget-label">Current Date & Time</p>
        </div>
      </div>
      <div class="status-widget">
        <div class="status-widget-icon icon-info"><i class="fas fa-user-doctor" aria-hidden="true"></i></div>
        <div class="status-widget-content">
          <p class="status-widget-value">${parseInt(status.unread_count) || 0}</p>
          <p class="status-widget-label">Unread Notifications</p>
        </div>
      </div>
      <div class="status-widget">
        <div class="status-widget-icon icon-info"><i class="fas fa-building" aria-hidden="true"></i></div>
        <div class="status-widget-content">
          <p class="status-widget-value">${escapeHTML(status.hospital_name || "HealthBridge")}</p>
          <p class="status-widget-label">Hospital</p>
        </div>
      </div>
    </div>
  `;

  // Update clock every 30 seconds
  if (window._dashboardClockInterval)
    clearInterval(window._dashboardClockInterval);
  window._dashboardClockInterval = setInterval(() => {
    const timeEl = document.getElementById("dashboard-current-time");
    if (timeEl) {
      timeEl.textContent = new Date().toLocaleString("en-US", {
        weekday: "long",
        year: "numeric",
        month: "long",
        day: "numeric",
        hour: "numeric",
        minute: "2-digit",
        hour12: true,
      });
    }
  }, 30000);
}

// ─── Chart Options ─────────────────────────────────────────

function getBarChartOptions(title, colors, gridColor) {
  return {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { display: false },
      tooltip: getTooltipConfig(),
    },
    scales: {
      x: {
        grid: { color: gridColor },
        ticks: { color: colors.muted, font: { size: 11 } },
      },
      y: {
        beginAtZero: true,
        grid: { color: gridColor },
        ticks: { color: colors.muted, font: { size: 11 }, stepSize: 1 },
      },
    },
  };
}

function getLineChartOptions(title, colors, gridColor) {
  return {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { display: false },
      tooltip: getTooltipConfig(),
    },
    scales: {
      x: {
        grid: { display: false },
        ticks: { color: colors.muted, font: { size: 11 } },
      },
      y: {
        beginAtZero: true,
        grid: { color: gridColor },
        ticks: { color: colors.muted, font: { size: 11 }, stepSize: 1 },
      },
    },
  };
}

function getDoughnutChartOptions(title, colors) {
  return {
    responsive: true,
    maintainAspectRatio: false,
    cutout: "60%",
    plugins: {
      legend: {
        position: "bottom",
        labels: {
          color: colors.text,
          font: { size: 11 },
          padding: 12,
          usePointStyle: true,
          pointStyle: "circle",
        },
      },
      tooltip: getTooltipConfig(),
    },
  };
}

function getPieChartOptions(title, colors) {
  return {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: {
        position: "bottom",
        labels: {
          color: colors.text,
          font: { size: 11 },
          padding: 12,
          usePointStyle: true,
          pointStyle: "circle",
        },
      },
      tooltip: getTooltipConfig(),
    },
  };
}

function getStackedBarChartOptions(title, colors, gridColor) {
  return {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: {
        position: "top",
        labels: {
          color: colors.text,
          font: { size: 10 },
          padding: 10,
          usePointStyle: true,
          pointStyle: "circle",
        },
      },
      tooltip: getTooltipConfig(),
    },
    scales: {
      x: {
        stacked: true,
        grid: { color: gridColor },
        ticks: { color: colors.muted, font: { size: 10 } },
      },
      y: {
        stacked: true,
        beginAtZero: true,
        grid: { color: gridColor },
        ticks: { color: colors.muted, font: { size: 10 } },
      },
    },
  };
}

function getTooltipConfig() {
  const isLight = getCurrentTheme() === "light";
  return {
    backgroundColor: isLight ? "#fff" : "#111e33",
    titleColor: isLight ? "#0f172a" : "#e0f7fa",
    bodyColor: isLight ? "#334e68" : "#7fb3d3",
    borderColor: isLight ? "rgba(8, 145, 178, 0.2)" : "rgba(34, 211, 238, 0.2)",
    borderWidth: 1,
    padding: 10,
    cornerRadius: 8,
    bodyFont: { size: 12 },
    titleFont: { size: 12, weight: "600" },
  };
}

// ─── Chart Export Plugin ───────────────────────────────────

function chartExportPlugin(chartId) {
  return {
    id: "chartExport_" + chartId,
    afterDraw(chart) {
      // Store reference for export
      chart.__exportId = chartId;
    },
  };
}

/**
 * Export a chart as PNG image.
 */
function exportChartPNG(chartId) {
  const chart = AdminDashboard.charts[chartId];
  if (!chart) {
    showToast("Chart not available for export.", "error");
    return;
  }

  const link = document.createElement("a");
  link.download = `healthbridge-${chartId}-chart.png`;
  link.href = chart.toBase64Image("image/png", 1);
  link.click();
  showToast("Chart exported as PNG.", "success");
}

/**
 * Export chart data as CSV.
 */
function exportChartCSV(chartId) {
  const chart = AdminDashboard.charts[chartId];
  if (!chart) {
    showToast("Chart data not available for export.", "error");
    return;
  }

  const labels = chart.data.labels;
  const datasets = chart.data.datasets;

  let csv = "Label";
  datasets.forEach((ds) => {
    csv += "," + escapeCSV(ds.label || "Value");
  });
  csv += "\n";

  labels.forEach((label, i) => {
    csv += escapeCSV(label);
    datasets.forEach((ds) => {
      csv += "," + (ds.data[i] ?? "");
    });
    csv += "\n";
  });

  const blob = new Blob([csv], { type: "text/csv;charset=utf-8;" });
  const link = document.createElement("a");
  link.href = URL.createObjectURL(blob);
  link.download = `healthbridge-${chartId}-data.csv`;
  link.click();
  URL.revokeObjectURL(link.href);
  showToast("Chart data exported as CSV.", "success");
}

function escapeCSV(value) {
  const str = String(value ?? "");
  if (str.includes(",") || str.includes('"') || str.includes("\n")) {
    return '"' + str.replace(/"/g, '""') + '"';
  }
  return str;
}

// ─── Chart Management ──────────────────────────────────────

function destroyChart(key) {
  if (AdminDashboard.charts[key]) {
    AdminDashboard.charts[key].destroy();
    delete AdminDashboard.charts[key];
  }
}

function recreateAllCharts() {
  const data = AdminDashboard.data;
  if (!data) return;

  // Destroy all existing charts
  Object.keys(AdminDashboard.charts).forEach((key) => destroyChart(key));

  // Re-render all chart sections
  renderAppointmentCharts(data.appointment_analytics);
  renderPatientCharts(data.patient_analytics);
  renderDoctorAnalytics(data.doctor_analytics);
  renderDepartmentAnalytics(data.department_analytics);
}

// ─── Skeleton Loaders ──────────────────────────────────────

function showSkeletons() {
  // Show skeleton containers, hide real content
  document
    .querySelectorAll(".skeleton-container")
    .forEach((el) => (el.style.display = ""));
  document
    .querySelectorAll(".dashboard-content-container")
    .forEach((el) => (el.style.display = "none"));
}

function hideSkeletons() {
  document
    .querySelectorAll(".skeleton-container")
    .forEach((el) => (el.style.display = "none"));
  document
    .querySelectorAll(".dashboard-content-container")
    .forEach((el) => (el.style.display = ""));
}

// ─── Error State ───────────────────────────────────────────

function showErrorState(message) {
  hideSkeletons();

  const errorEl = document.getElementById("dashboard-error");
  if (errorEl) {
    errorEl.style.display = "";
    errorEl.querySelector("p").textContent =
      message || "An unexpected error occurred.";
  }
}

// ─── Refresh ───────────────────────────────────────────────

async function refreshAdminDashboard() {
  showToast("Refreshing dashboard...", "info");
  AdminDashboard.initialized = false;
  Object.keys(AdminDashboard.charts).forEach((key) => destroyChart(key));
  AdminDashboard.charts = {};
  AdminDashboard.data = null;
  await initAdminDashboard();
  showToast("Dashboard refreshed.", "success");
}

// ─── Expose Globally ───────────────────────────────────────

window.initAdminDashboard = initAdminDashboard;
window.refreshAdminDashboard = refreshAdminDashboard;
window.exportChartPNG = exportChartPNG;
window.exportChartCSV = exportChartCSV;
