/*
 * HealthBridge Admin Overview — Public V2 page script.
 *
 * Phase 0 landing behaviour (kept): the admin's saved default-tab
 * preference (settings → admin_default_tab, one of the values the
 * settings endpoint accepts) is honoured once per session — the first
 * landing on the Overview in a session forwards to the preferred
 * section, and later explicit visits to the Overview stay put.
 *
 * Phase 1: the analytics overview. One payload —
 * api/admin/dashboard-analytics.php — drives everything on the page:
 * the nine KPIs as stat tiles with context lines computed from the
 * same payload, weekly + monthly appointment bars (hand-built, no
 * chart library), the status distribution with the completion rate,
 * patient demographics, the three top-five lists, the department
 * table, recent activity, and the system-status strip. Every number
 * rendered is either a payload field or a sum/share computed from
 * payload fields — nothing is hardcoded.
 * Depends on the Public V2 shared site.js runtime.
 */
"use strict";

/* ---------- Phase 0: default-tab landing ---------- */

(() => {
  const TAB_PAGES = {
    overview: "admin-dashboard.html",
    doctors: "doctors.html",
    patients: "patients.html",
    appointments: "appointments.html",
    "support-messages": "support-messages.html",
  };
  const FLAG = "hb_admin_landing_done";

  document.addEventListener("admin:shell-ready", async () => {
    let already;
    try { already = sessionStorage.getItem(FLAG); } catch { already = "1"; }
    if (already) return;
    try { sessionStorage.setItem(FLAG, "1"); } catch { /* storage off: redirect at most once per load */ }

    try {
      const ROOT = typeof getBasePath === "function" ? getBasePath() : "../../";
      const response = await fetch(ROOT + "api/settings/get.php", {
        method: "GET", credentials: "same-origin", headers: { Accept: "application/json" },
      });
      const payload = await response.json().catch(() => null);
      const tab = payload?.success ? payload.preferences?.admin_default_tab : null;
      const target = typeof tab === "string" ? TAB_PAGES[tab] : null;
      if (target && target !== "admin-dashboard.html") {
        window.location.assign(target);
      }
    } catch { /* preference unreadable: stay on the Overview */ }
  });
})();

/* ---------- Phase 1: analytics overview ---------- */

(() => {
  const ROOT = typeof getBasePath === "function" ? getBasePath() : "../../";
  const $ = (id) => document.getElementById(id);
  const esc = window.HBHelpers.esc;
  const num = (v) => { const n = Number(v); return Number.isFinite(n) ? n : 0; };
  const initialsOf = (name) => String(name || "").trim().split(/\s+/).filter(Boolean)
    .slice(0, 2).map((part) => part.charAt(0).toUpperCase()).join("") || "—";

  function fmtHour(value) {
    const m = String(value || "").match(/^(\d{1,2}):(\d{2})/);
    if (!m) return String(value || "");
    let h = Number(m[1]);
    const suffix = h >= 12 ? "PM" : "AM";
    h = h % 12 === 0 ? 12 : h % 12;
    return h + ":" + m[2] + " " + suffix;
  }

  function countUp(el, target, decimals = 0) {
    if (!el) return;
    const from = Number(el.dataset.count ?? 0);
    el.dataset.count = String(target);
    const reduced = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    if (reduced || from === target) { el.textContent = target.toFixed(decimals); return; }
    const start = performance.now();
    const duration = 800;
    const tick = (now) => {
      const p = Math.min(1, (now - start) / duration);
      const eased = 1 - Math.pow(1 - p, 3);
      el.textContent = (from + (target - from) * eased).toFixed(decimals);
      if (p < 1) requestAnimationFrame(tick);
    };
    requestAnimationFrame(tick);
  }

  async function fetchJson(url) {
    const response = await fetch(ROOT + url, {
      credentials: "same-origin", headers: { Accept: "application/json" },
      // Freeze round (Item 5): a hung request must never leave the page on
      // its loading skeleton forever — fail into the error state instead.
      signal: AbortSignal.timeout(12000),
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload?.success) throw new Error(url);
    return payload;
  }

  /* ---------- Intro + system state ---------- */

  function renderIntro(data) {
    const status = data.system_status || {};
    const now = new Date(String(status.current_time || "").replace(" ", "T"));
    const valid = !Number.isNaN(now.getTime());

    const dateNode = $("ov-date");
    if (dateNode && valid) {
      dateNode.textContent = "Admin workspace · " + now.toLocaleDateString("en-US", {
        weekday: "long", month: "long", day: "numeric", year: "numeric",
      });
    }
    const greeting = $("ov-greeting");
    if (greeting && valid) {
      const hour = now.getHours();
      const dayPart = hour < 12 ? "Good morning" : hour < 18 ? "Good afternoon" : "Good evening";
      const user = typeof getUser === "function" ? getUser() : null;
      const name = (user && user.name) || "Admin";
      greeting.innerHTML = dayPart + ", <em>" + esc(name) + "</em>";
    }

    const pill = $("ov-open");
    const pillText = $("ov-open-text");
    if (pill && pillText) {
      const open = status.is_open === true;
      pill.classList.toggle("is-closed", !open);
      pillText.textContent = open
        ? "Open now · closes " + fmtHour(status.close_time)
        : "Closed · opens " + fmtHour(status.open_time);
      pill.hidden = false;
    }
    return valid ? now : null;
  }

  /* ---------- KPI tiles ---------- */

  function setCtx(id, text) {
    const el = $(id);
    if (el) el.textContent = text;
  }

  function renderKpis(data, now) {
    const kpi = data.kpi || {};
    const statusRows = data.appointment_analytics?.status_distribution || [];
    const totalAppts = statusRows.reduce((sum, r) => sum + num(r.count), 0);
    const reschedule = statusRows.find((r) => r.status === "Reschedule Requested");
    const depts = Array.isArray(data.department_analytics) ? data.department_analytics : [];
    const activeDepts = depts.filter((d) => String(d.status).toLowerCase() === "active").length;
    const topRated = (data.doctor_analytics?.highest_rated || [])[0];

    countUp($("ov-stat-patients"), num(kpi.total_patients));
    setCtx("ov-stat-patients-ctx", num(kpi.new_patients_this_month) + " new this month");

    countUp($("ov-stat-doctors"), num(kpi.total_doctors));
    setCtx("ov-stat-doctors-ctx", num(kpi.active_doctors) + " of " + num(kpi.total_doctors) + " currently active");

    countUp($("ov-stat-today"), num(kpi.today_appointments));
    setCtx("ov-stat-today-ctx", now
      ? now.toLocaleDateString("en-US", { weekday: "long", month: "long", day: "numeric" })
      : "scheduled for today");

    countUp($("ov-stat-pending"), num(kpi.pending_appointments));
    setCtx("ov-stat-pending-ctx", reschedule
      ? "plus " + num(reschedule.count) + " reschedule requests"
      : "waiting for a decision");

    countUp($("ov-stat-confirmed"), num(kpi.confirmed_appointments));
    const rate = num(data.appointment_analytics?.completion_rate);
    setCtx("ov-stat-confirmed-ctx", totalAppts
      ? "of " + totalAppts + " booked · " + rate.toFixed(1) + "% completed"
      : "bookings confirmed");

    countUp($("ov-stat-departments"), num(kpi.total_departments));
    setCtx("ov-stat-departments-ctx", depts.length
      ? (activeDepts === depts.length
        ? "All " + depts.length + " active"
        : activeDepts + " of " + depts.length + " active")
      : "across the hospital");

    const rating = num(kpi.avg_rating);
    countUp($("ov-stat-rating"), rating, 1);
    const ratingCtx = $("ov-stat-rating-ctx");
    if (ratingCtx) {
      const stars = Math.min(5, Math.max(0, Math.round(rating)));
      const starText = "★★★★★".slice(0, stars) + "☆☆☆☆☆".slice(0, 5 - stars);
      ratingCtx.innerHTML = '<span class="admin-stat__stars" aria-hidden="true">' + starText + "</span> " +
        (topRated ? "Top score " + Number(topRated.rating).toFixed(1) : "average across doctors");
    }

    countUp($("ov-stat-new"), num(kpi.new_patients_this_month));
    setCtx("ov-stat-new-ctx", now
      ? "registered in " + now.toLocaleDateString("en-US", { month: "long", year: "numeric" })
      : "registered this month");
  }

  /* ---------- Bar charts ---------- */

  // Bar heights are set in measured pixels, never in %. The
  // previous construction (bar height in % of a flex-grown track,
  // plus a scaleY grow animation that starts at 0) made every
  // bar's visibility depend on an animation advancing: in a
  // hidden or restored tab the grow animation stays frozen at
  // its first frame (scaleY(0)) and the chart renders counts and
  // labels with no bars at all (owner report, 23 Nov 2026 —
  // measured: track at full height, computed bar heights correct,
  // painted height 0). Pixel heights measured from the track
  // after layout can collapse in neither way.
  function layoutBars(chart) {
    const max = Number(chart.dataset.max) || 1;
    chart.querySelectorAll(".admin-chart__day").forEach((cell) => {
      const track = cell.querySelector(".admin-chart__track");
      const bar = cell.querySelector(".admin-chart__bar");
      if (!track || !bar) return;
      const count = Number(bar.dataset.count) || 0;
      bar.style.height = count === 0 ? "0px" :
        Math.max(4, Math.round((track.clientHeight * count) / max)) + "px";
    });
  }

  function layoutAllCharts() {
    document.querySelectorAll(".admin-chart").forEach(layoutBars);
  }

  function barChart(host, bars) {
    // bars: [{count, dn, dt, now}] — each chart is normalised to
    // its OWN series (owner fix, 23 Nov 2026): the tallest value
    // fills the track, every other bar is exactly its share of
    // that max, a nonzero value keeps a 4px stub so it stays
    // visible, and zero renders no bar at all.
    const max = Math.max(1, ...bars.map((b) => b.count));
    const chart = document.createElement("div");
    chart.className = "admin-chart";
    chart.dataset.max = String(max);
    chart.setAttribute("role", "img");
    chart.setAttribute("aria-label", "Bar chart: " +
      bars.map((b) => b.dn + ": " + b.count).join(", ") + ".");
    bars.forEach((b) => {
      const cell = document.createElement("div");
      cell.className = "admin-chart__day" + (b.now ? " is-now" : "");
      cell.innerHTML = '<span class="admin-chart__count">' + b.count + "</span>" +
        '<div class="admin-chart__track"><div class="admin-chart__bar" data-count="' + b.count +
        '" style="height:0px"></div></div>';
      chart.appendChild(cell);
    });
    const labels = document.createElement("div");
    labels.className = "admin-chart__labels";
    bars.forEach((b) => {
      const label = document.createElement("div");
      if (b.now) label.className = "is-now";
      label.innerHTML = '<span class="admin-chart__dn">' + esc(b.dn) + "</span>" +
        '<span class="admin-chart__dt">' + esc(b.dt) + "</span>";
      labels.appendChild(label);
    });
    host.replaceChildren(chart, labels);
    layoutBars(chart);
  }

  function renderWeekly(data, now) {
    const host = $("ov-week");
    if (!host) return;
    const rows = Array.isArray(data.appointment_analytics?.weekly) ? data.appointment_analytics.weekly : [];
    if (!rows.length) {
      host.innerHTML = '<p class="admin-empty">No appointments in the last seven days.</p>';
      return;
    }
    const todayDow = now ? now.getDay() : -1; // 0 = Sunday
    const bars = rows.map((r) => {
      const dayNum = num(r.day_num); // 1 = Sunday … 7 = Saturday
      let dateLabel = "";
      if (now && dayNum >= 1) {
        const d = new Date(now.getFullYear(), now.getMonth(), now.getDate() - ((todayDow - (dayNum - 1) + 7) % 7));
        dateLabel = String(d.getDate());
      }
      return {
        count: num(r.count),
        dn: String(r.day || "").slice(0, 3),
        dt: dateLabel,
        now: dayNum >= 1 && (dayNum - 1) === todayDow,
      };
    });
    barChart(host, bars);
    const note = $("ov-week-note");
    if (note) {
      const total = bars.reduce((sum, b) => sum + b.count, 0);
      note.textContent = "Last 7 days · " + total + (total === 1 ? " appointment" : " appointments");
    }
  }

  function renderMonthly(data, now) {
    const host = $("ov-month");
    if (!host) return;
    const rows = Array.isArray(data.appointment_analytics?.monthly) ? data.appointment_analytics.monthly : [];
    if (!rows.length) {
      host.innerHTML = '<p class="admin-empty">No appointment history to chart yet.</p>';
      return;
    }
    const nowKey = now
      ? now.getFullYear() + "-" + String(now.getMonth() + 1).padStart(2, "0")
      : "";
    const bars = rows.map((r) => {
      const key = String(r.month || "");
      const d = new Date(key + "-01T00:00:00");
      const valid = !Number.isNaN(d.getTime());
      return {
        count: num(r.count),
        dn: valid ? d.toLocaleDateString("en-US", { month: "short" }) : key,
        dt: valid ? "’" + String(d.getFullYear()).slice(2) : "",
        now: key === nowKey,
      };
    });
    barChart(host, bars);
    const note = $("ov-month-note");
    if (note && rows.length) {
      const fmtMonth = (key) => {
        const d = new Date(String(key) + "-01T00:00:00");
        return Number.isNaN(d.getTime()) ? String(key)
          : d.toLocaleDateString("en-US", { month: "short", year: "numeric" });
      };
      const total = bars.reduce((sum, b) => sum + b.count, 0);
      note.textContent = fmtMonth(rows[0].month) + " – " + fmtMonth(rows[rows.length - 1].month) +
        " · " + total + " appointments";
    }
  }

  /* ---------- Status distribution ---------- */

  const STATUS_CLASS = {
    "Confirmed": "is-confirmed",
    "Pending": "is-pending",
    "Reschedule Requested": "is-reschedule",
    "Cancelled": "is-cancelled",
  };

  function renderStatus(data) {
    const host = $("ov-status");
    if (!host) return;
    const rows = Array.isArray(data.appointment_analytics?.status_distribution)
      ? data.appointment_analytics.status_distribution : [];
    const total = rows.reduce((sum, r) => sum + num(r.count), 0);
    if (!rows.length || !total) {
      host.innerHTML = '<p class="admin-empty">No appointments on record yet.</p>';
      return;
    }
    const wrap = document.createElement("div");
    wrap.className = "admin-dist";
    rows.forEach((r, i) => {
      const count = num(r.count);
      const share = Math.round((count / total) * 100);
      const row = document.createElement("div");
      row.className = "admin-dist__row";
      const cls = STATUS_CLASS[r.status] || "";
      row.innerHTML =
        '<div class="admin-dist__meta"><span class="admin-dist__name">' + esc(r.status) + "</span>" +
        '<span class="admin-dist__count">' + count + " · " + share + "%</span></div>" +
        '<div class="admin-dist__track"><div class="admin-dist__bar ' + cls + '" style="width:' +
          ((count / total) * 100).toFixed(1) + "%;animation-delay:" + (i * 25) + 'ms"></div></div>';
      if (!cls) row.querySelector(".admin-dist__bar").style.background = "#8aa79d";
      wrap.appendChild(row);
    });
    const rate = num(data.appointment_analytics?.completion_rate);
    const foot = document.createElement("p");
    foot.className = "admin-dist__foot";
    foot.innerHTML = "<strong>" + rate.toFixed(1) + "%</strong> of appointments that reached " +
      "their date were completed — the completion rate across all " + total + " bookings.";
    host.replaceChildren(wrap, foot);
  }

  /* ---------- Demographics ---------- */

  function renderDemographics(data) {
    const host = $("ov-demo");
    if (!host) return;
    const pa = data.patient_analytics || {};
    const genders = Array.isArray(pa.gender_distribution) ? pa.gender_distribution : [];
    const ages = Array.isArray(pa.age_distribution) ? pa.age_distribution : [];
    if (!genders.length && !ages.length) {
      host.innerHTML = '<p class="admin-empty">No patient demographics on record yet.</p>';
      return;
    }
    host.replaceChildren();

    if (genders.length) {
      const total = genders.reduce((sum, g) => sum + num(g.count), 0) || 1;
      const split = document.createElement("div");
      split.className = "admin-demo__split";
      split.setAttribute("role", "img");
      split.setAttribute("aria-label", "Gender split: " +
        genders.map((g) => g.gender + " " + g.count).join(", ") + ".");
      const legend = document.createElement("ul");
      legend.className = "admin-demo__legend";
      genders.forEach((g, i) => {
        const key = String(g.gender || "").toLowerCase();
        const cls = key === "female" ? "is-female" : key === "male" ? "is-male" : "is-other";
        const seg = document.createElement("div");
        seg.className = "admin-demo__seg " + cls;
        seg.style.width = ((num(g.count) / total) * 100).toFixed(1) + "%";
        seg.style.animationDelay = (i * 25) + "ms";
        split.appendChild(seg);
        const li = document.createElement("li");
        li.innerHTML = '<span class="admin-demo__dot ' + cls + '" aria-hidden="true"></span>' +
          "<strong>" + esc(g.gender) + "</strong><span>" + num(g.count) + " · " +
          Math.round((num(g.count) / total) * 100) + "%</span>";
        legend.appendChild(li);
      });
      host.append(split, legend);
    }

    if (ages.length) {
      const max = Math.max(1, ...ages.map((a) => num(a.count)));
      const wrap = document.createElement("div");
      wrap.className = "admin-demo__ages";
      ages.forEach((a, i) => {
        const row = document.createElement("div");
        row.className = "admin-demo__age";
        row.innerHTML = "<span>" + esc(a.age_range) + "</span>" +
          '<div class="admin-demo__track"><div class="admin-demo__bar" style="width:' +
            ((num(a.count) / max) * 100).toFixed(1) + "%;animation-delay:" + (i * 25) + 'ms"></div></div>' +
          "<strong>" + num(a.count) + "</strong>";
        wrap.appendChild(row);
      });
      host.appendChild(wrap);
    }
  }

  /* ---------- Top-five lists ---------- */

  function topRow({ href, name, sub, value, valueSub, stars }) {
    const row = document.createElement(href ? "a" : "div");
    row.className = "admin-top__row";
    if (href) row.href = href;
    row.innerHTML =
      '<span class="admin-avatar" aria-hidden="true">' + esc(initialsOf(name)) + "</span>" +
      '<span class="admin-top__who"><strong>' + esc(name) + "</strong><span>" + esc(sub) + "</span></span>" +
      '<span class="admin-top__value"><strong>' + esc(value) + "</strong>" +
      (stars ? '<span class="admin-top__stars" aria-hidden="true">' + stars + "</span>" : "") +
      (valueSub ? "<span>" + esc(valueSub) + "</span>" : "") + "</span>";
    return row;
  }

  function renderTopLists(data) {
    const da = data.doctor_analytics || {};
    const busiest = Array.isArray(da.busiest_doctors) ? da.busiest_doctors : [];
    const rated = Array.isArray(da.highest_rated) ? da.highest_rated : [];
    const active = Array.isArray(data.patient_analytics?.most_active) ? data.patient_analytics.most_active : [];

    const busiestHost = $("ov-busiest");
    if (busiestHost) {
      if (!busiest.length) {
        busiestHost.innerHTML = '<p class="admin-empty">No doctor appointments on record yet.</p>';
      } else {
        busiestHost.replaceChildren(...busiest.map((d) => topRow({
          href: "doctor-profile.html?id=" + encodeURIComponent(d.id ?? ""),
          name: d.name || "Doctor",
          sub: d.specialty || "",
          value: String(num(d.appointment_count)),
          valueSub: num(d.appointment_count) === 1 ? "appointment" : "appointments",
        })));
      }
    }

    const ratedHost = $("ov-rated");
    if (ratedHost) {
      if (!rated.length) {
        ratedHost.innerHTML = '<p class="admin-empty">No rated doctors yet.</p>';
      } else {
        ratedHost.replaceChildren(...rated.map((d) => {
          const rating = num(d.rating);
          const stars = Math.min(5, Math.max(0, Math.round(rating)));
          return topRow({
            href: "doctor-profile.html?id=" + encodeURIComponent(d.id ?? ""),
            name: d.name || "Doctor",
            sub: (d.specialty || "") + " · " + num(d.review_count) +
              (num(d.review_count) === 1 ? " review" : " reviews") +
              " · " + num(d.total_appointments) + " appointments",
            value: rating.toFixed(1),
            stars: "★★★★★".slice(0, stars) + "☆☆☆☆☆".slice(0, 5 - stars),
          });
        }));
      }
    }

    const activeHost = $("ov-active");
    if (activeHost) {
      if (!active.length) {
        activeHost.innerHTML = '<p class="admin-empty">No patient appointments on record yet.</p>';
      } else {
        activeHost.replaceChildren(...active.map((p) => topRow({
          name: p.name || "Patient",
          sub: p.email || "",
          value: String(num(p.appointment_count)),
          valueSub: num(p.appointment_count) === 1 ? "appointment" : "appointments",
        })));
      }
    }
  }

  /* ---------- Departments ---------- */

  function renderDepartments(data) {
    const host = $("ov-dept");
    if (!host) return;
    const rows = Array.isArray(data.department_analytics) ? data.department_analytics : [];
    if (!rows.length) {
      host.innerHTML = '<p class="admin-empty">No departments on record yet.</p>';
      return;
    }
    const wrap = document.createElement("div");
    wrap.className = "admin-table-wrap";
    const table = document.createElement("table");
    table.className = "admin-table";
    table.innerHTML = "<thead><tr><th>Department</th><th>Status</th><th>Doctors</th>" +
      "<th>Appointments</th><th>Patients</th></tr></thead>";
    const body = document.createElement("tbody");
    rows.forEach((d) => {
      const isActive = String(d.status).toLowerCase() === "active";
      const tr = document.createElement("tr");
      tr.innerHTML = "<td>" + esc(d.name) + "</td>" +
        '<td><span class="admin-pill ' + (isActive ? "is-active" : "is-inactive") + '">' +
          esc(isActive ? "Active" : (d.status || "Inactive")) + "</span></td>" +
        "<td>" + num(d.doctor_count) + "</td>" +
        "<td>" + num(d.appointment_count) + "</td>" +
        "<td>" + num(d.patient_count) + "</td>";
      body.appendChild(tr);
    });
    table.appendChild(body);
    wrap.appendChild(table);
    host.replaceChildren(wrap);
  }

  /* ---------- Recent activity ---------- */

  function renderActivity(data) {
    const host = $("ov-activity");
    if (!host) return;
    const rows = Array.isArray(data.recent_activity) ? data.recent_activity : [];
    if (!rows.length) {
      host.innerHTML = '<p class="admin-empty">No activity recorded yet — new bookings and changes will appear here.</p>';
      return;
    }
    const wrap = document.createElement("div");
    wrap.className = "admin-activity";
    rows.forEach((r) => {
      const row = document.createElement("div");
      row.className = "admin-activity__row";
      const meta = [r.actor_name, r.actor_role, r.time_ago].filter(Boolean).join(" · ");
      row.innerHTML = '<p class="admin-activity__desc">' + esc(r.description || "") + "</p>" +
        (meta ? '<p class="admin-activity__meta">' + esc(meta) + "</p>" : "");
      wrap.appendChild(row);
    });
    host.replaceChildren(wrap);
  }

  /* ---------- System status strip ---------- */

  function sysItem(label, value, extra) {
    return '<div class="admin-sys__item' + (extra ? " " + extra : "") + '">' +
      '<span class="admin-sys__label">' + esc(label) + '</span>' +
      '<span class="admin-sys__value">' + esc(value) + "</span></div>";
  }

  function renderSystem(data) {
    const host = $("ov-sys");
    if (!host) return;
    const s = data.system_status || {};
    const da = data.doctor_analytics || {};
    const avail = num(da.available_today);
    const unavail = num(da.unavailable_today);
    const open = s.is_open === true;
    host.innerHTML =
      '<div class="admin-sys__item"><span class="admin-open-pill__dot' + (open ? "" : " is-closed") +
        '" aria-hidden="true"></span><span class="admin-sys__value">' +
        (open ? "Open now" : "Closed") + "</span>" +
        '<span class="admin-sys__label">' + fmtHour(s.open_time) + " – " + fmtHour(s.close_time) + "</span></div>" +
      sysItem("Hospital", s.hospital_name || "HealthBridge Hospital") +
      sysItem("Doctors today", avail + " of " + (avail + unavail) + " available") +
      sysItem("Unread notifications", num(s.unread_count) + " across all accounts") +
      sysItem("Server time", s.current_time_formatted || "", "admin-sys__item--wide");
  }

  /* ---------- Page load / refresh ---------- */

  async function loadAll() {
    const refreshBtn = $("ov-refresh");
    if (refreshBtn) {
      refreshBtn.disabled = true;
      refreshBtn.classList.add("is-spinning");
      window.setTimeout(() => refreshBtn.classList.remove("is-spinning"), 850);
    }
    try {
      const data = await fetchJson("api/admin/dashboard-analytics.php");
      const now = renderIntro(data);
      renderKpis(data, now);
      renderWeekly(data, now);
      renderMonthly(data, now);
      renderStatus(data);
      renderDemographics(data);
      renderTopLists(data);
      renderDepartments(data);
      renderActivity(data);
      renderSystem(data);
    } catch {
      const error = $("ov-error");
      if (error) error.hidden = false;
      ["ov-week", "ov-month", "ov-status", "ov-demo", "ov-busiest", "ov-rated",
        "ov-active", "ov-dept", "ov-activity"].forEach((id) => {
        const host = $(id);
        if (host) host.innerHTML = '<p class="admin-empty">This section could not be loaded.</p>';
      });
      const sys = $("ov-sys");
      if (sys) sys.innerHTML = '<p class="admin-empty">System status could not be loaded.</p>';
    } finally {
      if (refreshBtn) refreshBtn.disabled = false;
      // Freeze round (Item 5): on the FIRST load only, swap the skeleton
      // for the real bento with a short opacity fade. Refresh clicks keep
      // the content in place.
      if (!loadAll.firstDone) {
        loadAll.firstDone = true;
        const skeleton = $("ov-skeleton");
        if (skeleton) skeleton.remove();
        const bento = $("ov-bento");
        if (bento) {
          bento.hidden = false;
          bento.classList.add("hb-fade-in");
        }
        const main = $("admin-main");
        if (main) main.setAttribute("aria-busy", "false");
      }
    }
  }

  function start() {
    const refreshBtn = $("ov-refresh");
    if (refreshBtn) refreshBtn.addEventListener("click", loadAll);

    // Entrance: staggered fade + rise runs once under .is-entering;
    // the class (and its will-change) is removed when the last tile
    // lands so hover transforms are never overridden afterwards.
    const overview = document.querySelector(".admin-overview");
    if (overview) {
      overview.classList.add("is-entering");
      window.setTimeout(() => overview.classList.remove("is-entering"), 1100);
    }
    // Idle animations pause while the tab is hidden.
    document.addEventListener("visibilitychange", () => {
      document.documentElement.classList.toggle("hb-anim-paused", document.hidden);
    });
    // Bar heights are pixel-measured (see layoutBars): re-measure
    // when the track height can change — a viewport resize (the
    // ≤560px breakpoint shortens the chart) or a late font load
    // (the count label's height is what sets the track's).
    let resizeTick = false;
    window.addEventListener("resize", () => {
      if (resizeTick) return;
      resizeTick = true;
      requestAnimationFrame(() => { resizeTick = false; layoutAllCharts(); });
    });
    if (document.fonts && document.fonts.ready) {
      document.fonts.ready.then(layoutAllCharts);
    }

    loadAll();
  }

  document.addEventListener("admin:shell-ready", start);
})();
