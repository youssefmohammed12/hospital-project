/**
 * HealthBridge Public V2 — Doctor overview controller (Phase 1, PORT).
 * Greeting with a working availability toggle; a bento grid holding
 * today's schedule as a timeline (Up next folded into the same tile),
 * an attention tile of unfinished EMR notes with a Write note action
 * per row (badge carries the pending count), four KPI mini-tiles with
 * computed context lines (waiting, confirmed, completed, rating), the
 * current week as a bar chart, and the latest patient review. All six
 * headline numbers stay visible: today's count sits in the schedule
 * tile's header badge, the EMR-notes count in the attention badge.
 * Data: api/appointments/get-doctor.php, api/doctors/get-doctor-ratings.php,
 * api/doctors/get-availability.php, api/doctors/toggle-availability.php.
 * Every number on the page is computed from those responses — nothing
 * is hardcoded.
 */
"use strict";

(() => {
  const ROOT = typeof getBasePath === "function" ? getBasePath() : "../../";
  const $ = (id) => document.getElementById(id);
  const esc = window.HBHelpers.esc;

  const pad = (n) => String(n).padStart(2, "0");
  const dateStr = (d) => d.getFullYear() + "-" + pad(d.getMonth() + 1) + "-" + pad(d.getDate());
  const todayStr = () => dateStr(new Date());
  const fmtDay = (ds) => {
    const d = new Date(String(ds || "") + "T00:00:00");
    if (Number.isNaN(d.getTime())) return "";
    if (ds === todayStr()) return "Today";
    const tmr = new Date();
    tmr.setDate(tmr.getDate() + 1);
    if (ds === dateStr(tmr)) return "Tomorrow";
    return d.toLocaleDateString("en-US", { weekday: "short", month: "short", day: "numeric" });
  };
  const fmtTime = window.HBHelpers.fmtTime;
  const fmtRange = (appt) => {
    const parts = String(appt.appointment_time_range || "").split("-").map((s) => s.trim());
    if (parts.length === 2 && parts[0]) return fmtTime(parts[0]) + " – " + fmtTime(parts[1]);
    return fmtTime(appt.time);
  };
  const workspaceUrl = (appt) =>
    "doctor-workspace.html?patient_id=" + encodeURIComponent(appt.patient_id ?? appt.user_id ?? "") +
    "&appointment_id=" + encodeURIComponent(appt.id ?? "");
  const byDateTime = (a, b) => (String(a.date) + String(a.time)).localeCompare(String(b.date) + String(b.time));
  const initialsOf = (name) => String(name || "").trim().split(/\s+/).filter(Boolean)
    .slice(0, 2).map((part) => part.charAt(0).toUpperCase()).join("") || "—";

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

  async function fetchJson(url, options) {
    const response = await fetch(ROOT + url, Object.assign({
      credentials: "same-origin", headers: { Accept: "application/json" },
    }, options || {}));
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload?.success) throw new Error(url);
    return payload;
  }

  /* ---------- KPI row ---------- */

  function renderStats(appointments) {
    const active = appointments.filter((a) => a.status !== "Cancelled");
    const waiting = active.filter((a) => a.visit_workflow?.status === "Waiting");
    const confirmedCount = appointments.filter((a) => a.status === "Confirmed").length;
    const completed = active.filter((a) => a.visit_workflow?.status === "Completed");
    countUp($("ov-stat-waiting"), waiting.length);
    countUp($("ov-stat-confirmed"), confirmedCount);
    countUp($("ov-stat-completed"), completed.length);

    // Context lines — all computed from the same payload, nothing invented.
    const waitingCtx = $("ov-stat-waiting-ctx");
    if (waitingCtx) {
      const sorted = [...waiting].sort(byDateTime);
      const next = sorted.find((a) => a.date >= todayStr()) || sorted[0];
      waitingCtx.innerHTML = next
        ? 'Next up: <span class="doctor-stat__nm">' + esc(next.patient_name || "Patient") +
          "</span> · " + esc(fmtDay(next.date))
        : "No one is waiting right now";
    }
    const confirmedCtx = $("ov-stat-confirmed-ctx");
    if (confirmedCtx) {
      const total = appointments.length;
      confirmedCtx.textContent = "of " + total + (total === 1 ? " total appointment" : " total appointments");
    }
    const completedCtx = $("ov-stat-completed-ctx");
    if (completedCtx) {
      const recent = [...completed].sort(byDateTime).pop();
      completedCtx.innerHTML = recent
        ? 'Most recent: <span class="doctor-stat__nm">' + esc(recent.patient_name || "Patient") +
          "</span> · " + esc(fmtDay(recent.date))
        : "No completed visits yet";
    }
  }

  function renderRating(ratings) {
    const value = $("ov-stat-rating");
    const ctx = $("ov-stat-rating-ctx");
    if (!ratings.length) {
      if (value) value.textContent = "—";
      if (ctx) ctx.textContent = "No reviews yet";
      return;
    }
    const avg = ratings.reduce((sum, r) => sum + (Number(r.stars) || 0), 0) / ratings.length;
    countUp(value, avg, 1);
    if (ctx) {
      const stars = Math.min(5, Math.max(0, Math.round(avg)));
      const starText = "★★★★★".slice(0, stars) + "☆☆☆☆☆".slice(0, 5 - stars);
      ctx.innerHTML = '<span class="doctor-stat__stars" aria-hidden="true">' + starText + "</span> from " +
        ratings.length + (ratings.length === 1 ? " review" : " reviews");
    }
  }

  /* ---------- Schedule timelines ---------- */

  function pillFor(appt) {
    if (appt.status === "Pending") return { text: "Pending", cls: " is-pending" };
    const wf = appt.visit_workflow?.status || "";
    if (wf === "Waiting") return { text: "Waiting", cls: " is-waiting" };
    if (wf === "In Progress") return { text: "In progress", cls: " is-progress" };
    if (wf === "Completed" || appt.status === "Completed") return { text: "Completed", cls: " is-done" };
    return { text: appt.status || "Confirmed", cls: "" };
  }

  function timelineRow(appt, showDay) {
    const pill = pillFor(appt);
    const name = appt.patient_name || "Patient";
    const row = document.createElement("div");
    row.className = "doctor-tl-row";
    row.innerHTML =
      '<div class="doctor-tl-time">' + esc(fmtRange(appt)) +
        (showDay ? '<span class="doctor-tl-date">' + esc(fmtDay(appt.date)) + "</span>" : "") + "</div>" +
      '<div class="doctor-tl-rail"><span class="doctor-tl-dot is-filled"></span></div>' +
      '<div class="doctor-tl-main"><div class="doctor-appt">' +
        '<span class="doctor-avatar" aria-hidden="true">' + esc(initialsOf(name)) + "</span>" +
        '<span class="doctor-appt__who"><strong>' + esc(name) + "</strong><span>" +
          esc(appt.department || "") + "</span></span>" +
        '<span class="doctor-pill' + pill.cls + '">' + esc(pill.text) + "</span>" +
        '<a class="doctor-consult-btn" href="' + esc(workspaceUrl(appt)) + '">Consult</a>' +
      "</div></div>";
    return row;
  }

  function renderSchedule(appointments) {
    const todayHost = $("ov-today");
    const nextHost = $("ov-upnext");
    const today = todayStr();

    const todays = appointments
      .filter((a) => a.date === today && a.status !== "Cancelled")
      .sort(byDateTime);
    const todayBadge = $("ov-today-count");
    if (todayBadge) {
      todayBadge.textContent = todays.length + (todays.length === 1 ? " appt today" : " appts today");
    }
    if (todayHost) {
      if (!todays.length) {
        todayHost.innerHTML =
          '<div class="doctor-tl-row doctor-tl-row--empty">' +
            '<div class="doctor-tl-time"></div>' +
            '<div class="doctor-tl-rail"><span class="doctor-tl-dot"></span></div>' +
            '<div class="doctor-tl-main">' +
              '<p class="doctor-empty-title">No appointments scheduled for today.</p>' +
              '<p class="doctor-empty-sub">Enjoy the breathing room — new bookings will appear here.</p>' +
            "</div></div>";
      } else {
        todayHost.replaceChildren(...todays.map((a) => timelineRow(a, false)));
      }
    }

    const upcoming = appointments
      .filter((a) => a.date > today && (a.status === "Confirmed" || a.status === "Pending"))
      .sort(byDateTime)
      .slice(0, 5);
    if (nextHost) {
      if (!upcoming.length) {
        nextHost.innerHTML =
          '<div class="doctor-tl-row"><div class="doctor-tl-time"></div>' +
          '<div class="doctor-tl-rail"></div>' +
          '<div class="doctor-tl-main"><p class="doctor-empty">Nothing booked beyond today yet.</p></div></div>';
      } else {
        nextHost.replaceChildren(...upcoming.map((a) => timelineRow(a, true)));
      }
    }
  }

  /* ---------- Needs your attention ---------- */

  function attentionRow(appt) {
    const name = appt.patient_name || "Patient";
    const row = document.createElement("div");
    row.className = "doctor-att-row";
    row.innerHTML =
      '<span class="doctor-avatar" aria-hidden="true">' + esc(initialsOf(name)) + "</span>" +
      '<span class="doctor-att-row__info"><strong>' + esc(name) + "</strong><span>" +
        "Visit note still to write — " + esc(fmtDay(appt.date)) + ", " + esc(fmtTime(appt.time)) +
        "</span></span>" +
      '<a class="doctor-btn-teal" href="' + esc(workspaceUrl(appt)) + '">Write note</a>';
    return row;
  }

  function moreRow(count, href) {
    const p = document.createElement("p");
    p.className = "doctor-attention-more";
    p.innerHTML = '<a href="' + esc(href) + '">and ' + count + " more</a>";
    return p;
  }

  function renderAttention(appointments) {
    const host = $("ov-attention");
    const notes = appointments
      .filter((a) => a.status === "Confirmed" && !a.has_visit_note)
      .sort(byDateTime);
    const badge = $("ov-attention-count");
    if (badge) {
      badge.textContent = notes.length + (notes.length === 1 ? " note" : " notes");
      badge.hidden = notes.length === 0;
    }
    if (!host) return;
    if (!notes.length) {
      host.innerHTML = '<p class="doctor-empty">All visit notes are written — nothing waiting on you.</p>';
      return;
    }
    const CAP = 5;
    const nodes = notes.slice(0, CAP).map((a) => attentionRow(a));
    if (notes.length > CAP) nodes.push(moreRow(notes.length - CAP, "queue.html"));
    host.replaceChildren(...nodes);
  }

  /* ---------- Your week ---------- */

  function renderWeek(appointments) {
    const host = $("ov-week");
    if (!host) return;
    const now = new Date();
    const monday = new Date(now.getFullYear(), now.getMonth(), now.getDate() - ((now.getDay() + 6) % 7));
    const counts = new Map();
    appointments.forEach((a) => {
      if (a.status === "Cancelled") return;
      counts.set(a.date, (counts.get(a.date) || 0) + 1);
    });
    const dayNames = ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"];
    const days = [];
    let total = 0;
    let max = 0;
    for (let i = 0; i < 7; i += 1) {
      const d = new Date(monday.getFullYear(), monday.getMonth(), monday.getDate() + i);
      const ds = dateStr(d);
      const count = counts.get(ds) || 0;
      total += count;
      if (count > max) max = count;
      days.push({ name: dayNames[i], date: d.getDate(), ds, count, isToday: ds === todayStr() });
    }

    const note = $("ov-week-note");
    if (note) {
      const monthOf = (ds) => new Date(ds + "T00:00:00")
        .toLocaleDateString("en-US", { month: "short" });
      const first = days[0];
      const last = days[6];
      const range = first.name + " " + first.date +
        (monthOf(first.ds) !== monthOf(last.ds) ? " " + monthOf(first.ds) : "") +
        " – " + last.name + " " + last.date + " " + monthOf(last.ds);
      note.textContent = range + " · " + total +
        (total === 1 ? " appointment" : " appointments") + " this week";
    }

    const chart = document.createElement("div");
    chart.className = "doctor-chart";
    chart.setAttribute("role", "img");
    chart.setAttribute("aria-label", "Bar chart of appointments per day this week: " +
      days.map((d) => d.name + " " + d.date + ": " + d.count).join(", ") + ".");
    days.forEach((d) => {
      const cell = document.createElement("div");
      cell.className = "doctor-chart__day" + (d.isToday ? " is-today" : "");
      const pct = d.count === 0 ? 0 : Math.max(16, Math.round((d.count / max) * 88));
      cell.innerHTML = '<span class="doctor-chart__count">' + d.count + "</span>" +
        '<div class="doctor-chart__bar" style="height:' + pct + '%"></div>';
      chart.appendChild(cell);
    });
    const labels = document.createElement("div");
    labels.className = "doctor-chart__labels";
    days.forEach((d) => {
      const label = document.createElement("div");
      if (d.isToday) label.className = "is-today";
      label.innerHTML = '<span class="doctor-chart__dn">' + d.name + "</span>" +
        '<span class="doctor-chart__dt">' + d.date + "</span>";
      labels.appendChild(label);
    });
    host.replaceChildren(chart, labels);
  }

  /* ---------- Latest review ---------- */

  function renderLatestReview(ratings) {
    const host = $("ov-review");
    if (!host) return;
    if (!ratings.length) {
      host.innerHTML = '<p class="doctor-empty">No reviews yet — patients can rate a visit once it is completed.</p>';
      return;
    }
    const sorted = [...ratings].sort((a, b) =>
      String(b.review_date || b.appt_date || "").localeCompare(String(a.review_date || a.appt_date || "")));
    const latest = sorted[0];
    const stars = Math.min(5, Math.max(0, Math.round(Number(latest.stars) || 0)));
    const starText = "★★★★★".slice(0, stars) + "☆☆☆☆☆".slice(0, 5 - stars);
    const when = latest.review_date ? fmtDay(String(latest.review_date).slice(0, 10)) : "";
    host.replaceChildren();
    const card = document.createElement("div");
    card.className = "doctor-review-card";
    card.innerHTML =
      '<div class="doctor-review-card__stars" role="img" aria-label="Rated ' + stars + ' out of 5">' + starText + "</div>" +
      (latest.review
        ? '<p class="doctor-review-card__text">“' + esc(latest.review) + "”</p>"
        : '<p class="doctor-review-card__text doctor-review-card__text--none">The patient left a rating without a written comment.</p>') +
      '<p class="doctor-review-card__meta">Verified Patient' + (when ? " · " + esc(when) : "") + "</p>";
    host.appendChild(card);
  }

  /* ---------- Availability toggle ---------- */

  let availabilityBusy = false;

  function applyAvailability(available) {
    const wrap = $("ov-availability");
    const text = $("ov-availability-text");
    const toggle = $("ov-availability-toggle");
    if (!wrap || !text || !toggle) return;
    text.textContent = available ? "Accepting appointments" : "Not accepting appointments";
    toggle.checked = available;
    toggle.setAttribute("aria-label", available ? "Accepting appointments" : "Not accepting appointments");
    wrap.classList.toggle("is-off", !available);
    wrap.hidden = false;
  }

  async function onAvailabilityToggle() {
    const toggle = $("ov-availability-toggle");
    if (!toggle || availabilityBusy) return;
    availabilityBusy = true;
    toggle.disabled = true;
    try {
      const payload = await fetchJson("api/doctors/toggle-availability.php", {
        method: "POST", headers: { Accept: "application/json", "Content-Type": "application/json" },
        body: "{}",
      });
      applyAvailability(Number(payload.available) === 1);
    } catch {
      // Revert the switch to the state the server still holds.
      toggle.checked = !toggle.checked;
      if (typeof showToast === "function") {
        showToast("Your availability could not be changed. Please try again.", "error");
      }
    } finally {
      toggle.disabled = false;
      availabilityBusy = false;
    }
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
      const doctorPayload = await fetchJson("api/appointments/get-doctor.php");
      const appointments = Array.isArray(doctorPayload.appointments) ? doctorPayload.appointments : [];

      const now = new Date();
      const dateNode = $("ov-date");
      if (dateNode) {
        dateNode.textContent = "Doctor workspace · " + now.toLocaleDateString("en-US", {
          weekday: "long", month: "long", day: "numeric", year: "numeric",
        });
      }
      const greeting = $("ov-greeting");
      if (greeting) {
        const hour = now.getHours();
        const dayPart = hour < 12 ? "Good morning" : hour < 18 ? "Good afternoon" : "Good evening";
        const doctorName = doctorPayload.doctor?.name || "";
        greeting.innerHTML = doctorName ? dayPart + ", <em>" + esc(doctorName) + "</em>" : dayPart;
      }

      renderStats(appointments);
      renderSchedule(appointments);
      renderAttention(appointments);
      renderWeek(appointments);
    } catch {
      const error = $("ov-error");
      if (error) {
        error.textContent = "Your overview could not be loaded completely. Check your connection and refresh the page.";
        error.hidden = false;
      }
      const today = $("ov-today");
      if (today) today.innerHTML = '<p class="doctor-empty">Your appointments could not be loaded.</p>';
      const attention = $("ov-attention");
      if (attention) attention.innerHTML = '<p class="doctor-empty">Your requests could not be loaded.</p>';
    } finally {
      if (refreshBtn) refreshBtn.disabled = false;
    }

    // Ratings and availability fill in independently; neither blocks the page.
    fetchJson("api/doctors/get-doctor-ratings.php")
      .then((payload) => {
        // Admin moderation (admin plan D6): reviews the admin hid are
        // flagged is_hidden=1 — excluded from the rating tile and the
        // latest-review quote until shown again.
        const ratings = (Array.isArray(payload.ratings) ? payload.ratings : [])
          .filter((r) => Number(r.is_hidden ?? 0) !== 1);
        renderRating(ratings);
        renderLatestReview(ratings);
      })
      .catch(() => { /* rating surfaces keep their neutral state */ });

    fetchJson("api/doctors/get-availability.php")
      .then((payload) => applyAvailability(Number(payload.available) === 1))
      .catch(() => { /* no toggle shown if availability cannot be read */ });
  }

  function start() {
    const toggle = $("ov-availability-toggle");
    if (toggle) toggle.addEventListener("change", onAvailabilityToggle);
    const refreshBtn = $("ov-refresh");
    if (refreshBtn) refreshBtn.addEventListener("click", loadAll);

    // Entrance: staggered fade + rise runs once under .is-entering;
    // the class (and its will-change) is removed when the last tile
    // lands so hover transforms are never overridden afterwards.
    const overview = document.querySelector(".doctor-overview");
    if (overview) {
      overview.classList.add("is-entering");
      window.setTimeout(() => overview.classList.remove("is-entering"), 1300);
    }
    // Idle animations pause while the tab is hidden.
    document.addEventListener("visibilitychange", () => {
      document.documentElement.classList.toggle("hb-anim-paused", document.hidden);
    });

    loadAll();
  }

  document.addEventListener("doctor:shell-ready", start);
})();
