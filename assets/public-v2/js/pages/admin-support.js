/*
 * HealthBridge Admin — Support Messages (Phase 6).
 *
 * One inbox over api/settings/get-contact-messages.php, which
 * returns every contact_messages row. The two sender kinds are
 * derived from user_id, and the page never blurs them (plan D3):
 *
 *  - Registered sender (user_id set): a reply saved through
 *    api/settings/reply-contact-message.php is delivered to the
 *    sender's account as an in-app notification (verified live:
 *    the patient's notification list gains a support_reply entry).
 *    The UI may say "delivered" for these — in-app, never email.
 *  - Guest sender (user_id NULL): the same endpoint stores the
 *    reply on the message and creates NO notification for anyone
 *    (verified live: the system-wide notification count does not
 *    move). The endpoint still answers "Reply sent successfully.",
 *    which is why this page writes its own feedback wording: the
 *    guest is labelled undeliverable before replying, the save
 *    button says what it really does, and the saved state reads
 *    "Reply saved — not deliverable". The word "sent" is never
 *    used for a guest.
 *
 * There is no mark-read endpoint for contact messages (nothing in
 * the API ever writes is_read), so the page offers no read-state
 * control it could not persist. Status is derived honestly from
 * the reply fields: Awaiting reply / Replied.
 * Depends on the Public V2 shared site.js runtime.
 */
"use strict";

(() => {
  const ROOT = typeof getBasePath === "function" ? getBasePath() : "../../";
  const $ = (id) => document.getElementById(id);
  const esc = window.HBHelpers.esc;
  const formatDateTime = window.HBHelpers.formatDateTime;
  const num = (v) => { const n = Number(v); return Number.isFinite(n) ? n : 0; };

  let messages = [];
  let current = null; // message open in the detail modal

  async function api(url, options) {
    const response = await fetch(ROOT + url, {
      credentials: "same-origin",
      headers: { Accept: "application/json", ...(options?.body ? { "Content-Type": "application/json" } : {}) },
      ...options,
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload?.success) {
      throw new Error(payload?.message || ("Request failed: " + url));
    }
    return payload;
  }

  function toast(message, type) {
    if (typeof window.showToast === "function") window.showToast(message, type || "info");
  }

  const isGuest = (m) => m.user_id === null || m.user_id === undefined || m.user_id === "";
  const hasReply = (m) => typeof m.reply === "string" && m.reply.trim() !== "";

  /* ---------- Rendering ---------- */

  function senderBadge(m) {
    return isGuest(m)
      ? "<span class=\"admin-pill is-cancelled\">Guest</span>"
      : "<span class=\"admin-pill is-progress\">Registered</span>";
  }

  function statusPill(m) {
    if (!hasReply(m)) return "<span class=\"admin-pill is-pending\">Awaiting reply</span>";
    return isGuest(m)
      ? "<span class=\"admin-pill is-cancelled\">Reply saved — not deliverable</span>"
      : "<span class=\"admin-pill is-done\">Replied — delivered in-app</span>";
  }

  function rowHtml(m) {
    return "<tr data-id=\"" + num(m.id) + "\">" +
      "<td><span class=\"asup-name\">" + esc(m.name || "—") + "</span>" +
        "<span class=\"asup-email\">" + esc(m.email || "") + "</span></td>" +
      "<td><span class=\"asup-subject\">" + esc(m.subject || "(No subject)") + "</span></td>" +
      "<td>" + esc(m.department || "—") + "</td>" +
      "<td><span class=\"asup-date\">" + esc(formatDateTime(m.created_at)) + "</span></td>" +
      "<td><span class=\"asup-badges\">" + senderBadge(m) + statusPill(m) + "</span></td>" +
      "<td class=\"asup-actions-col\"><button class=\"admin-btn admin-btn--ghost\" type=\"button\" data-action=\"view\" data-id=\"" + num(m.id) + "\">View</button></td>" +
      "</tr>";
  }

  function visibleMessages() {
    const q = $("asup-search").value.trim().toLowerCase();
    const sender = $("asup-sender").value;
    const status = $("asup-status").value;
    return messages.filter((m) => {
      if (sender === "guest" && !isGuest(m)) return false;
      if (sender === "registered" && isGuest(m)) return false;
      if (status === "awaiting" && hasReply(m)) return false;
      if (status === "replied" && !hasReply(m)) return false;
      if (q) {
        const hay = [m.name, m.email, m.subject, m.message, m.department].join(" ").toLowerCase();
        if (!hay.includes(q)) return false;
      }
      return true;
    });
  }

  function render() {
    const guests = messages.filter(isGuest).length;
    const awaiting = messages.filter((m) => !hasReply(m)).length;
    $("asup-summary").innerHTML =
      "<strong>" + messages.length + "</strong> message" + (messages.length === 1 ? "" : "s") +
      " · <strong>" + guests + "</strong> guest · <strong>" + (messages.length - guests) + "</strong> registered" +
      " · <strong>" + awaiting + "</strong> awaiting reply";

    const visible = visibleMessages();
    $("asup-count").textContent = "Showing " + visible.length + " of " + messages.length;
    const tbody = $("asup-tbody");
    if (!messages.length) {
      tbody.innerHTML = "<tr><td colspan=\"6\" class=\"asup-empty-cell\">No support messages yet. When someone writes through the public contact form, their message will appear here.</td></tr>";
      return;
    }
    if (!visible.length) {
      tbody.innerHTML = "<tr><td colspan=\"6\" class=\"asup-empty-cell\">No messages match the current search and filters.</td></tr>";
      return;
    }
    tbody.innerHTML = visible.map(rowHtml).join("");
  }

  async function load() {
    try {
      const payload = await api("api/settings/get-contact-messages.php");
      messages = Array.isArray(payload.messages) ? payload.messages : [];
      render();
    } catch (err) {
      $("asup-tbody").innerHTML = "<tr><td colspan=\"6\" class=\"asup-empty-cell\">The inbox could not be loaded.</td></tr>";
      $("asup-error").hidden = false;
    }
    document.getElementById("admin-main")?.removeAttribute("aria-busy");
  }

  /* ---------- Detail + reply ---------- */

  function openOverlay() {
    const el = $("asup-detail-overlay");
    el.hidden = false;
    requestAnimationFrame(() => el.classList.add("is-open"));
  }

  function closeOverlay() {
    const el = $("asup-detail-overlay");
    el.classList.remove("is-open");
    el.hidden = true;
    current = null;
  }

  function fillDetail(m) {
    current = m;
    const guest = isGuest(m);
    $("asup-detail-title").textContent = m.subject || "(No subject)";
    $("asup-sender-block").innerHTML =
      "<span class=\"asup-sender__name\">" + esc(m.name || "—") + "</span>" +
      "<span class=\"asup-sender__meta\">" + esc(m.email || "") +
        (m.phone ? " · " + esc(m.phone) : "") +
        " · " + esc(m.department || "General Inquiry") +
        " · Received " + esc(formatDateTime(m.created_at)) + "</span>" +
      senderBadge(m);

    const note = $("asup-delivery-note");
    if (guest) {
      note.className = "asup-delivery is-guest";
      note.innerHTML = "<strong>Guest sender — no reply can reach them.</strong> " +
        "This sender wrote without a HealthBridge account, and this system sends no email. " +
        "Anything you save below is stored on this message only, as an internal record — it is not delivered anywhere.";
    } else {
      note.className = "asup-delivery is-registered";
      note.innerHTML = "<strong>Registered sender.</strong> " +
        "Your reply is delivered to this sender's HealthBridge account as an in-app notification — they see it in their notification centre. No email is sent.";
    }

    $("asup-message-body").textContent = m.message || "";

    const replied = hasReply(m);
    $("asup-reply-view").hidden = !replied;
    if (replied) {
      $("asup-reply-view-title").textContent = guest ? "Saved reply (not delivered)" : "Reply";
      $("asup-reply-body").textContent = m.reply;
      $("asup-reply-meta").textContent =
        "Saved " + formatDateTime(m.replied_at) + (guest
          ? " · Stored on this message only — the sender cannot see it."
          : " · Delivered to the sender as an in-app notification.");
    }

    $("asup-reply-label").textContent = replied ? "Edit the reply" : "Your reply";
    $("asup-reply-text").value = replied ? m.reply : "";
    $("asup-reply-hint").textContent = guest
      ? "Saving stores the text on this message. It will not be sent anywhere."
      : (replied
          ? "Saving again replaces the stored reply and notifies the sender again."
          : "The sender is notified in their HealthBridge account as soon as you save.");
    $("asup-reply-submit").textContent = guest
      ? (replied ? "Save Reply (still not deliverable)" : "Save Reply (cannot be delivered)")
      : (replied ? "Save Reply" : "Send Reply");
    $("asup-reply-error").hidden = true;
    openOverlay();
  }

  async function submitReply(event) {
    event.preventDefault();
    if (!current) return;
    const errEl = $("asup-reply-error");
    const reply = $("asup-reply-text").value.trim();
    if (!reply) {
      errEl.textContent = "Please enter a reply.";
      errEl.hidden = false;
      return;
    }
    errEl.hidden = true;
    const btn = $("asup-reply-submit");
    btn.disabled = true;
    try {
      await api("api/settings/reply-contact-message.php", {
        method: "POST",
        body: JSON.stringify({ id: num(current.id), reply }),
      });
      const guest = isGuest(current);
      current.reply = reply;
      current.replied_at = new Date().toISOString();
      const idx = messages.findIndex((m) => num(m.id) === num(current.id));
      if (idx >= 0) messages[idx] = current;
      render();
      fillDetail(current);
      // The endpoint answers "Reply sent successfully." for guests too;
      // the toast wording here is the honest one for each case.
      toast(guest
        ? "Reply saved on the message. It cannot be delivered — the sender has no account."
        : "Reply saved and delivered to the sender's in-app notifications.", "success");
    } catch (err) {
      errEl.textContent = err.message || "The reply could not be saved.";
      errEl.hidden = false;
    }
    btn.disabled = false;
  }

  /* ---------- Wiring ---------- */

  function start() {
    ["asup-search", "asup-sender", "asup-status"].forEach((id) => {
      $(id).addEventListener("input", render);
      $(id).addEventListener("change", render);
    });
    $("asup-tbody").addEventListener("click", (event) => {
      const btn = event.target.closest("[data-action=\"view\"]");
      if (!btn) return;
      const m = messages.find((x) => num(x.id) === num(btn.dataset.id));
      if (m) fillDetail(m);
    });
    $("asup-reply-form").addEventListener("submit", submitReply);
    $("asup-detail-close").addEventListener("click", closeOverlay);
    $("asup-detail-cancel").addEventListener("click", closeOverlay);
    $("asup-detail-overlay").addEventListener("click", (event) => {
      if (event.target === event.currentTarget) closeOverlay();
    });
    document.addEventListener("keydown", (event) => { if (event.key === "Escape") closeOverlay(); });
    load();
  }

  document.addEventListener("admin:shell-ready", start, { once: true });
})();
