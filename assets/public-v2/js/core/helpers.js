/*
 * HealthBridge Public V2 — shared page helpers.
 *
 * Single definitions of the small escaping/formatting helpers the
 * dashboard page scripts used to copy into every file. Namespaced
 * under window.HBHelpers so nothing collides with site.js globals —
 * site.js keeps its own escapeHTML, whose apostrophe entity (&#039;)
 * intentionally differs from esc's (&#39;) below; the two are NOT
 * interchangeable and were never merged.
 *
 * Only helpers whose copies were verified identical (or identical
 * modulo one fallback string, passed as an argument) live here.
 * Variants that differ stay in their page files on purpose — see the
 * per-helper notes. Load order: after site.js, before the page
 * scripts (the shells load it too, before shell-core.js).
 */
"use strict";

(() => {
  /* HTML escaping, five-character map (& < > " '), apostrophe as
     &#39;. Was copied identically into doctor-queue, doctor-
     appointments, doctor-workspace, doctor-overview, doctor-schedule
     and doctor-reviews. */
  const esc = (value) => String(value ?? "").replace(/[&<>"']/g, (c) => ({
    "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;",
  })[c]);

  /* HTML escaping, four characters (& < > ") — apostrophes pass
     through untouched. Was copied identically into patient-
     prescriptions, patient-history, patient-notifications,
     patient-documents, patient-profile and doctor-notifications,
     where it was built on each file's local text() (null/undefined
     become ""). Kept as its own helper: merging it into esc would
     change rendered output on those pages. */
  const escText = (value) =>
    (value == null ? "" : String(value))
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");

  /* "Nov 4, 2026" from a leading YYYY-MM-DD date. Non-matching input
     passes through as its string form; null/undefined yields the
     caller's fallback (each page's copy differed only in that
     string: "Date to be confirmed" on Prescriptions, "Not recorded"
     on Profile, "" in the Document Center). Was formatDate in
     patient-prescriptions, patient-profile and patient-documents. */
  const formatDate = (value, nullFallback = "") => {
    const s = value == null ? "" : String(value);
    const m = s.match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (!m) return value == null ? nullFallback : s;
    return new Intl.DateTimeFormat("en", {
      month: "short",
      day: "numeric",
      year: "numeric",
    }).format(new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3])));
  };

  /* Locale date from a full date/datetime value ("—" when empty,
     the raw string when unparseable). Was copied identically into
     doctor-profile and doctor-settings. patient-settings keeps its
     own formatDate: it omits the space→"T" normalization, which can
     parse differently, so it was not merged. */
  const formatDateTime = (value) => {
    if (!value) return "—";
    const date = new Date(String(value).replace(" ", "T"));
    if (Number.isNaN(date.getTime())) return String(value);
    return date.toLocaleDateString(undefined, {
      year: "numeric", month: "short", day: "numeric",
    });
  };

  /* "2:30 PM" from an "HH:MM" string; unparseable input passes
     through as its string form. Was copied identically into
     doctor-queue, doctor-appointments, doctor-workspace,
     doctor-overview and doctor-schedule. (patient-appointments and
     patient-dashboard have their own formatTime with different
     fallbacks — not this one, and not merged.) */
  const fmtTime = (hhmm) => {
    const m = /^(\d{1,2}):(\d{2})/.exec(String(hhmm || ""));
    if (!m) return String(hhmm || "");
    let h = Number(m[1]);
    const suffix = h >= 12 ? "PM" : "AM";
    h = h % 12 === 0 ? 12 : h % 12;
    return h + ":" + m[2] + " " + suffix;
  };

  /* "Nov 4, 2026 · 2:30 PM" (date only when no time is recorded)
     from a "YYYY-MM-DD[ HH:MM]" timestamp; unparseable input passes
     through as its string form. Was copied identically into
     patient-notifications and doctor-notifications. */
  const formatWhen = (createdAt) => {
    const s = createdAt == null ? "" : String(createdAt);
    const m = s.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/);
    if (!m) return s;
    const date = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]),
      m[4] ? Number(m[4]) : 0, m[5] ? Number(m[5]) : 0);
    const day = new Intl.DateTimeFormat("en", {
      month: "short", day: "numeric", year: "numeric",
    }).format(date);
    const time = m[4]
      ? new Intl.DateTimeFormat("en", { hour: "numeric", minute: "2-digit" }).format(date)
      : "";
    return time ? day + " · " + time : day;
  };

  window.HBHelpers = Object.freeze({
    esc, escText, formatDate, formatDateTime, fmtTime, formatWhen,
  });
})();
