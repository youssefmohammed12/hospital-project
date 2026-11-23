/**
 * HealthBridge Public V2 — Doctor patient reviews (Phase 5).
 * Built new for Public V2; loads nothing from the legacy frontend.
 *
 * Data: GET api/doctors/get-doctor-ratings.php — the same endpoint the
 * Overview's rating tile and latest-review card consume. It returns the
 * doctor's FULL ratings list, so everything on this page is either a
 * field of that payload or arithmetic on the list itself: the average,
 * the count, and the per-star distribution (count of reviews at each
 * star level, computed from the list — never estimated).
 *
 * The endpoint carries no patient name, so reviews are attributed the
 * same way the Overview does: "Verified Patient".
 */
"use strict";

(() => {
  const ROOT = typeof getBasePath === "function" ? getBasePath() : "../../";
  const $ = (id) => document.getElementById(id);
  const esc = window.HBHelpers.esc;

  const starText = (n) => {
    const s = Math.min(5, Math.max(0, Math.round(Number(n) || 0)));
    return "★★★★★".slice(0, s) + "☆☆☆☆☆".slice(0, 5 - s);
  };

  const fmtDay = (iso) => {
    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(iso || ""));
    if (!m) return "";
    const d = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
    return new Intl.DateTimeFormat("en", { month: "short", day: "numeric", year: "numeric" }).format(d);
  };

  async function fetchJson(path) {
    const response = await fetch(ROOT + path, { credentials: "same-origin" });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || payload.success === false) {
      throw new Error(payload?.message || "Request failed.");
    }
    return payload;
  }

  function countUp(el, target, decimals) {
    if (!el) return;
    const reduce = window.matchMedia?.("(prefers-reduced-motion: reduce)").matches;
    if (reduce || !Number.isFinite(target)) {
      el.textContent = Number.isFinite(target) ? target.toFixed(decimals) : "—";
      return;
    }
    const start = performance.now();
    const dur = 700;
    const tick = (now) => {
      const t = Math.min(1, (now - start) / dur);
      el.textContent = (target * (1 - Math.pow(1 - t, 3))).toFixed(decimals);
      if (t < 1) requestAnimationFrame(tick);
    };
    requestAnimationFrame(tick);
  }

  function setView(view) {
    $("rev-loading").hidden = view !== "loading";
    $("rev-error").hidden = view !== "error";
    $("rev-empty").hidden = view !== "empty";
    $("rev-summary").hidden = view !== "list";
    $("rev-list-wrap").hidden = view !== "list";
    const main = $("doctor-main");
    if (main) main.setAttribute("aria-busy", view === "loading" ? "true" : "false");
  }

  function render(ratings) {
    const sorted = [...ratings].sort((a, b) =>
      String(b.review_date || b.appt_date || "").localeCompare(String(a.review_date || a.appt_date || "")));

    /* Summary — average and count are arithmetic on the list. */
    const avg = ratings.reduce((sum, r) => sum + (Number(r.stars) || 0), 0) / ratings.length;
    countUp($("rev-avg"), avg, 1);
    const starsEl = $("rev-avg-stars");
    if (starsEl) starsEl.textContent = starText(avg);
    const scoreEl = $("rev-score");
    if (scoreEl) {
      scoreEl.setAttribute("aria-label",
        "Average rating " + avg.toFixed(1) + " out of 5 from " + ratings.length +
        (ratings.length === 1 ? " review" : " reviews"));
    }
    $("rev-count").textContent = String(ratings.length);
    $("rev-count-word").textContent = ratings.length === 1 ? "review" : "reviews";
    const latest = sorted[0];
    const latestWhen = latest ? fmtDay(latest.review_date || latest.appt_date) : "";
    $("rev-note").textContent = latestWhen
      ? "Most recent review: " + latestWhen + ". Ratings are left by patients after a completed visit."
      : "Ratings are left by patients after a completed visit.";

    /* Distribution — counts per star level, from the same list. */
    const dist = $("rev-dist");
    if (dist) {
      const counts = [0, 0, 0, 0, 0, 0];
      ratings.forEach((r) => {
        const s = Math.min(5, Math.max(0, Math.round(Number(r.stars) || 0)));
        if (s >= 1) counts[s] += 1;
      });
      dist.innerHTML = [5, 4, 3, 2, 1].map((s) => {
        const pct = ratings.length ? Math.round((counts[s] / ratings.length) * 100) : 0;
        return (
          '<div class="drv-dist__row">' +
          '<span class="drv-dist__label">' + s + ' <span aria-hidden="true">★</span></span>' +
          '<span class="drv-dist__track"><span class="drv-dist__fill" style="width:' + pct + '%"></span></span>' +
          '<span class="drv-dist__count">' + counts[s] + "</span>" +
          "</div>"
        );
      }).join("");
      dist.hidden = false;
    }

    /* The reviews themselves, newest first. */
    $("rev-list").innerHTML = sorted.map((r) => {
      const stars = Math.min(5, Math.max(0, Math.round(Number(r.stars) || 0)));
      const when = fmtDay(r.review_date);
      const visit = fmtDay(r.appt_date);
      const meta = ["Verified Patient"];
      if (when) meta.push("Reviewed " + when);
      if (visit) meta.push("Visit " + visit + (r.appointment_time_range ? " · " + String(r.appointment_time_range) : ""));
      return (
        '<article class="drv-card">' +
        '<div class="drv-card__top">' +
        '<span class="doctor-review-card__stars" role="img" aria-label="Rated ' + stars + ' out of 5">' + starText(stars) + "</span>" +
        (when ? '<time class="drv-card__date">' + esc(when) + "</time>" : "") +
        "</div>" +
        (r.review
          ? '<p class="doctor-review-card__text">“' + esc(r.review) + "”</p>"
          : '<p class="doctor-review-card__text doctor-review-card__text--none">The patient left a rating without a written comment.</p>') +
        '<p class="doctor-review-card__meta">' + esc(meta.join(" · ")) + "</p>" +
        "</article>"
      );
    }).join("");

    setView("list");
  }

  async function load() {
    setView("loading");
    try {
      const payload = await fetchJson("api/doctors/get-doctor-ratings.php");
      // Admin moderation (admin plan D6): a review the admin hid is
      // flagged is_hidden=1 by the endpoint — it leaves the doctor's
      // own view too, summary counts included, until it is shown again.
      const ratings = (Array.isArray(payload.ratings) ? payload.ratings : [])
        .filter((r) => Number(r.is_hidden ?? 0) !== 1);
      if (!ratings.length) {
        setView("empty");
        return;
      }
      render(ratings);
    } catch (error) {
      console.error("Doctor reviews load failed:", error);
      setView("error");
    }
  }

  function start() {
    const root = document.querySelector(".doctor-reviews");
    if (root) {
      root.classList.add("is-entering");
      window.setTimeout(() => root.classList.remove("is-entering"), 1600);
    }
    $("rev-retry")?.addEventListener("click", load);
    load();
  }

  document.addEventListener("doctor:shell-ready", start);
})();
