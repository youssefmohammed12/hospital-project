"use strict";

/*
 * HealthBridge Public V2 — Doctor Profile (Phase 6).
 * Built new for Public V2; loads nothing from the legacy frontend.
 *
 * Data: GET api/settings/get.php (identity, professional record,
 * preferences — the same payload the Settings page edits) joined with
 * the public directory record from GET api/doctors/get.php (department
 * name + computed rating, matched on user_id) and the appointments book
 * from GET api/appointments/get-doctor.php for the glance counts —
 * the same arithmetic the Overview performs. Nothing here is estimated:
 * specialty, department and experience have NO doctor self-service
 * editor (the only update path is the admin endpoint), so they are
 * shown as the record and the page links to the editors that do exist.
 */
(() => {
  const ROOT = typeof getBasePath === "function" ? getBasePath() : "../../";
  const $ = (id) => document.getElementById(id);
  let started = false;

  const starText = (n) => {
    const s = Math.min(5, Math.max(0, Math.round(Number(n) || 0)));
    return "★★★★★".slice(0, s) + "☆☆☆☆☆".slice(0, 5 - s);
  };

  function initials(name) {
    const words = String(name || "").replace(/^Dr\.\s*/i, "").trim().split(/\s+/).filter(Boolean);
    if (!words.length) return "–";
    return (words[0][0] + (words[1]?.[0] || "")).toUpperCase();
  }

  function formatDate(value) { return window.HBHelpers.formatDateTime(value); }

  function setText(id, value) {
    const node = $(id);
    if (node) node.textContent = value == null || value === "" ? "—" : String(value);
  }

  async function fetchJson(path) {
    const response = await fetch(ROOT + path, { credentials: "same-origin" });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || payload.success === false) {
      throw new Error(payload?.message || "Request failed.");
    }
    return payload;
  }

  async function load() {
    const main = $("doctor-main");
    try {
      const [settings, directory, book] = await Promise.all([
        fetchJson("api/settings/get.php"),
        fetchJson("api/doctors/get.php").catch(() => null),
        fetchJson("api/appointments/get-doctor.php").catch(() => null),
      ]);

      const profile = settings.profile || {};
      if (profile.role !== "doctor") {
        throw new Error("This profile page is available to doctor accounts only.");
      }
      const doctor = settings.doctor || {};
      const prefs = settings.preferences || {};
      const mine = (directory?.data || directory?.doctors || []).find(
        (row) => Number(row.user_id ?? row.id) === Number(profile.id)
      ) || null;

      // Identity
      setText("dpf-avatar", initials(profile.name));
      setText("dpf-name", profile.name);
      const roleBits = [...new Set([doctor.specialty, mine?.department_name || null].filter(Boolean))];
      setText("dpf-role", roleBits.length ? roleBits.join(" · ") : "Doctor");

      const rating = Number(mine?.rating ?? NaN);
      const ratingCount = Number(mine?.rating_count ?? 0);
      if (mine && Number.isFinite(rating) && ratingCount > 0) {
        $("dpf-rating-stars").textContent = starText(rating);
        setText("dpf-rating-text", `${rating.toFixed(1)} · from ${ratingCount} review${ratingCount === 1 ? "" : "s"}`);
        $("dpf-rating").hidden = false;
      }

      const available = Number(prefs.operational_available ?? doctor.available ?? 0) === 1;
      const availPill = $("dpf-availability");
      availPill.textContent = available ? "Accepting appointments" : "Not accepting appointments";
      availPill.classList.toggle("dpf-pill--off", !available);
      availPill.hidden = false;

      const visible = Number(prefs.profile_visible ?? doctor.profile_visible ?? 1) === 1;
      const visPill = $("dpf-visibility");
      visPill.textContent = visible ? "Public profile visible" : "Public profile hidden";
      visPill.hidden = false;

      // Record
      setText("dpf-f-name", profile.name);
      setText("dpf-f-specialty", doctor.specialty);
      setText("dpf-f-department", mine?.department_name);
      setText("dpf-f-exp", doctor.exp != null ? `${doctor.exp} years` : null);
      setText("dpf-f-email", profile.email);
      setText("dpf-f-phone", profile.phone);
      setText("dpf-f-duration", prefs.consultation_duration != null ? `${prefs.consultation_duration} minutes` : null);
      setText("dpf-f-max", prefs.max_appointments_per_day);
      setText("dpf-f-accept", prefs.accept_new_patients != null ? (Number(prefs.accept_new_patients) === 1 ? "Yes" : "No") : null);
      setText("dpf-f-created", formatDate(profile.created_at));
      setText("dpf-f-status", profile.is_active ? "Active" : "Disabled");

      // Glance counts — the Overview's arithmetic on the same payload.
      const appointments = book?.appointments || [];
      if (book && Array.isArray(appointments)) {
        const active = appointments.filter((a) => a.status !== "Cancelled");
        const waiting = active.filter((a) => a.visit_workflow?.status === "Waiting");
        const completed = active.filter((a) => a.visit_workflow?.status === "Completed");
        setText("dpf-glance-total", appointments.length);
        setText("dpf-glance-completed", completed.length);
        setText("dpf-glance-waiting", waiting.length);
        $("dpf-glance").hidden = false;
      }

      $("dpf-loading").hidden = true;
      $("dpf-content").hidden = false;
    } catch (error) {
      $("dpf-loading").hidden = true;
      $("dpf-error").hidden = false;
    } finally {
      main?.setAttribute("aria-busy", "false");
      const root = document.querySelector(".doctor-profilepage");
      if (root) {
        root.classList.add("is-entering");
        window.setTimeout(() => root.classList.remove("is-entering"), 1600);
      }
    }
  }

  function start() {
    if (started) return;
    started = true;
    $("dpf-retry")?.addEventListener("click", () => {
      $("dpf-error").hidden = true;
      $("dpf-loading").hidden = false;
      load();
    });
    load();
  }

  document.addEventListener("doctor:shell-ready", start);
  if (document.querySelector(".main-content.doctor-shell-ready")) {
    queueMicrotask(start);
  }
})();
