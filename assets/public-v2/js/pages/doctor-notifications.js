/**
 * HealthBridge Public V2 — Doctor Notifications (Phase 5).
 * Built new for Public V2; loads nothing from the legacy frontend.
 *
 * Mirrors the patient Notifications page behaviour-for-behaviour on the
 * same endpoint (api/notifications/notifications.php), so the doctor
 * bell and this page always agree: GET lists, POST performs actions.
 * Opening the page marks everything read on the server (the bell badge
 * clears here and on every other page); items stay listed. The bell's
 * own dropdown-open mark-read lives in doctor-shell.js and does not
 * conflict: both paths end at the same server state.
 */
"use strict";

(() => {
  const ROOT = typeof getBasePath === "function" ? getBasePath() : "../../";
  const API = ROOT + "api/notifications/notifications.php";

  const $ = (id) => document.getElementById(id);
  const text = (v, fallback = "") => (v == null ? fallback : String(v));
  const esc = (v) =>
    text(v)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");

  const TYPE_LABELS = {
    appointment_confirmed: "Appointment",
    appointment_updated: "Appointment",
    appointment_cancelled: "Appointment",
    prescription_issued: "Prescription",
    account_status_changed: "Account",
  };
  const GROUPS = [
    ["today", "Today"],
    ["yesterday", "Yesterday"],
    ["this_week", "This week"],
    ["earlier", "Earlier"],
  ];

  function groupOf(createdAt) {
    const m = text(createdAt).match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (!m) return "earlier";
    const date = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
    const now = new Date();
    const startOfDay = (d) => new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime();
    const days = Math.round((startOfDay(now) - startOfDay(date)) / 86400000);
    if (days <= 0) return "today";
    if (days === 1) return "yesterday";
    if (days < 7) return "this_week";
    return "earlier";
  }

  function formatWhen(createdAt) {
    const m = text(createdAt).match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/);
    if (!m) return text(createdAt);
    const date = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]),
      m[4] ? Number(m[4]) : 0, m[5] ? Number(m[5]) : 0);
    const day = new Intl.DateTimeFormat("en", { month: "short", day: "numeric", year: "numeric" }).format(date);
    const time = m[4]
      ? new Intl.DateTimeFormat("en", { hour: "numeric", minute: "2-digit" }).format(date)
      : "";
    return time ? day + " · " + time : day;
  }

  const state = { notifications: [] };
  const unreadCount = () => state.notifications.filter((n) => !n.is_read).length;

  function setView(view) {
    $("dnt-loading").hidden = view !== "loading";
    $("dnt-error").hidden = view !== "error";
    $("dnt-empty").hidden = view !== "empty";
    $("dnt-content").hidden = view !== "list";
    const main = $("doctor-main");
    if (main) main.setAttribute("aria-busy", view === "loading" ? "true" : "false");
  }

  function render() {
    const unread = unreadCount();
    $("dnt-count").innerHTML =
      "<strong>" + state.notifications.length + "</strong> notifications · " +
      (unread ? "<strong>" + unread + " unread</strong>" : "all read");
    $("dnt-mark-all").hidden = unread === 0;

    let index = 0;
    $("dnt-groups").innerHTML = GROUPS.map(([key, label]) => {
      const items = state.notifications.filter((n) => groupOf(n.created_at) === key);
      if (!items.length) return "";
      return (
        '<h2 class="dnt-group-title">' + label + '</h2><div class="dnt-list">' +
        items.map((n) => {
          const i = index++;
          return (
            '<article class="dnt-item ' + (n.is_read ? "is-read" : "is-unread") + '" style="--dnt-i:' + Math.min(i, 10) + '"' +
            (n.is_read ? "" : ' data-dnt-action="mark-one" data-dnt-id="' + n.id + '" role="button" tabindex="0" title="Mark as read"') + ">" +
            '<span class="dnt-dot" aria-hidden="true"></span>' +
            '<div class="dnt-item-main"><div class="dnt-item-topline"><h3>' + esc(n.title) + "</h3>" +
            '<span class="dnt-time">' + esc(formatWhen(n.created_at)) + "</span></div>" +
            (n.message ? "<p>" + esc(n.message) + "</p>" : "") +
            '<span class="dnt-tag">' + esc(TYPE_LABELS[n.type] || "Notification") + "</span></div>" +
            "</article>"
          );
        }).join("") +
        "</div>"
      );
    }).join("");
    setView(state.notifications.length ? "list" : "empty");
  }

  async function post(action, id) {
    const response = await fetch(API, {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(id ? { action, id } : { action }),
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || payload.success === false) {
      throw new Error(payload?.message || "The notification action failed.");
    }
    return payload;
  }

  /* Keep the header bell in step with this page's own actions (the
     shell populated its badge at load, before these run). */
  function syncBell() {
    const badge = document.getElementById("doctor-notification-badge");
    if (!badge) return;
    const unread = unreadCount();
    badge.hidden = unread === 0;
    badge.textContent = unread > 99 ? "99+" : String(unread);
    if (unread === 0) {
      document.querySelectorAll(".doctor-notification-item.is-unread")
        .forEach((row) => row.classList.remove("is-unread"));
    }
  }

  async function markOne(id) {
    try {
      await post("mark_read", id);
      const item = state.notifications.find((n) => n.id === id);
      if (item) item.is_read = true;
      render();
      syncBell();
    } catch (error) {
      console.error("Mark as read failed:", error);
      if (typeof window.showToast === "function") window.showToast("Could not mark that notification as read.", "error");
    }
  }

  async function markAll() {
    const button = $("dnt-mark-all");
    button.disabled = true;
    try {
      await post("mark_all_read");
      state.notifications.forEach((n) => (n.is_read = true));
      render();
      syncBell();
    } catch (error) {
      console.error("Mark all as read failed:", error);
      if (typeof window.showToast === "function") window.showToast("Could not mark all notifications as read.", "error");
    } finally {
      button.disabled = false;
    }
  }

  async function load() {
    setView("loading");
    try {
      const response = await fetch(API + "?page=1", { credentials: "same-origin" });
      const payload = await response.json();
      if (!response.ok || !payload.success) {
        throw new Error(payload?.message || "Could not load notifications.");
      }
      state.notifications = (Array.isArray(payload.notifications) ? payload.notifications : []).map((n) => ({
        id: Number(n.id),
        type: text(n.type),
        title: text(n.title, "Notification"),
        message: text(n.message),
        is_read: n.is_read === true || n.is_read === 1 || n.is_read === "1",
        created_at: text(n.created_at),
      }));
      // Opening this page counts as seeing the notifications: mark
      // them all read on the server so the header bell (here and on
      // every other page) clears, while the items stay listed.
      if (state.notifications.some((n) => !n.is_read)) {
        try {
          await post("mark_all_read");
          state.notifications.forEach((n) => (n.is_read = true));
          syncBell();
        } catch (error) {
          console.error("Mark all as read on page open failed:", error);
        }
      }
      render();
    } catch (error) {
      console.error("Doctor notifications load failed:", error);
      $("dnt-error-message").textContent = error.message || "Something went wrong while loading your notifications.";
      setView("error");
    }
  }

  function start() {
    const root = document.querySelector(".doctor-notifs");
    if (root) {
      root.classList.add("is-entering");
      window.setTimeout(() => root.classList.remove("is-entering"), 1600);
    }
    document.addEventListener("click", (event) => {
      const item = event.target.closest('[data-dnt-action="mark-one"]');
      if (item) { markOne(Number(item.dataset.dntId)); return; }
      if (event.target.closest('[data-dnt-action="retry"]')) load();
    });
    document.addEventListener("keydown", (event) => {
      if (event.key !== "Enter" && event.key !== " ") return;
      const item = event.target.closest?.('[data-dnt-action="mark-one"]');
      if (item) { event.preventDefault(); markOne(Number(item.dataset.dntId)); }
    });
    $("dnt-mark-all").addEventListener("click", markAll);

    load();
  }

  document.addEventListener("doctor:shell-ready", start);
})();
