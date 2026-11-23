/*
 * HealthBridge Admin — Audit Log (Phase 6).
 *
 * A read-only viewer over GET api/audit/get.php (plan D5 — no
 * chain work, no export). All filtering and pagination are the
 * endpoint's own: search, action, entity type + id, actor,
 * actor role, patient id, doctor id, date range, page/limit.
 * The action / entity-type / actor dropdowns are populated from
 * the endpoint's filter_options on first load.
 *
 * Entries carry old_value / new_value as JSON strings (or NULL).
 * They are rendered as readable field-by-field diffs — changed
 * fields as "Field: before → after", creations as the recorded
 * field values under a "Record created" caption — never as raw
 * JSON dumps. If a value ever fails to parse as JSON it is shown
 * as plain text rather than hidden.
 *
 * The trail reaches back to 9 Nov 2026: eleven older rows from
 * the legacy_admin_audit table were merged into audit_events on
 * 23 Nov 2026 (fresh ids; original timestamps kept), so this
 * viewer shows the whole trail in one place.
 * Depends on the Public V2 shared site.js runtime.
 */
"use strict";

(() => {
  const ROOT = typeof getBasePath === "function" ? getBasePath() : "../../";
  const $ = (id) => document.getElementById(id);
  const esc = window.HBHelpers.esc;
  const formatDateTime = window.HBHelpers.formatDateTime;
  const num = (v) => { const n = Number(v); return Number.isFinite(n) ? n : 0; };

  let page = 1;
  let totalPages = 1;
  let total = 0;
  let entriesById = new Map();
  let optionsLoaded = false;

  async function api(url) {
    const response = await fetch(ROOT + url, {
      credentials: "same-origin",
      headers: { Accept: "application/json" },
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload?.success) {
      throw new Error(payload?.message || ("Request failed: " + url));
    }
    return payload;
  }

  /* ---------- Diff formatting ---------- */

  function parseValue(v) {
    if (v === null || v === undefined || v === "") return null;
    if (typeof v !== "string") return v;
    try { return JSON.parse(v); } catch { return v; }
  }

  const isObj = (v) => v !== null && typeof v === "object" && !Array.isArray(v);

  function humanKey(k) {
    const s = String(k).replace(/_/g, " ").trim();
    return s ? s.charAt(0).toUpperCase() + s.slice(1) : String(k);
  }

  function fmtVal(v) {
    if (v === null || v === undefined) return "—";
    if (typeof v === "boolean") return v ? "Yes" : "No";
    if (typeof v === "object") return JSON.stringify(v);
    return String(v);
  }

  // Returns { kind: "created"|"removed"|"changed"|"none"|"scalar", lines: [{label, from, to}] }
  function diffOf(entry) {
    const o = parseValue(entry.old_value);
    const n = parseValue(entry.new_value);
    if (isObj(o) && isObj(n)) {
      const keys = [...new Set([...Object.keys(o), ...Object.keys(n)])];
      const lines = keys
        .filter((k) => JSON.stringify(o[k] ?? null) !== JSON.stringify(n[k] ?? null))
        .map((k) => ({ label: humanKey(k), from: o[k] ?? null, to: n[k] ?? null }));
      return { kind: lines.length ? "changed" : "none", lines };
    }
    if (!isObj(o) && isObj(n)) {
      return { kind: "created", lines: Object.keys(n).map((k) => ({ label: humanKey(k), from: null, to: n[k] })) };
    }
    if (isObj(o) && !isObj(n)) {
      return { kind: "removed", lines: Object.keys(o).map((k) => ({ label: humanKey(k), from: o[k], to: null })) };
    }
    if (o === null && n === null) return { kind: "none", lines: [] };
    return { kind: "scalar", lines: [{ label: "Value", from: o, to: n }] };
  }

  function diffCellHtml(entry) {
    const d = diffOf(entry);
    if (d.kind === "none" || !d.lines.length) {
      return "<span class=\"aalog-nodiff\">No field changes recorded</span>";
    }
    const caption = d.kind === "created" ? "<span class=\"aalog-diff__cap\">Record created</span>"
      : d.kind === "removed" ? "<span class=\"aalog-diff__cap\">Record removed</span>" : "";
    const lines = d.lines.map((l) =>
      d.kind === "created"
        ? "<span class=\"aalog-diff__line\"><span class=\"aalog-diff__key\">" + esc(l.label) + ":</span> " + esc(fmtVal(l.to)) + "</span>"
        : "<span class=\"aalog-diff__line\"><span class=\"aalog-diff__key\">" + esc(l.label) + ":</span> " + esc(fmtVal(l.from)) + " <span class=\"aalog-diff__arrow\">→</span> " + esc(fmtVal(l.to)) + "</span>"
    ).join("");
    return caption + lines;
  }

  /* ---------- Rendering ---------- */

  const ACTION_PILL = {
    create: "is-done", book: "is-done", activate: "is-done",
    update: "is-progress", reassign: "is-progress",
    cancel: "is-cancelled", delete: "is-cancelled", deactivate: "is-cancelled",
  };

  function cap(s) { const t = String(s || ""); return t ? t.charAt(0).toUpperCase() + t.slice(1) : t; }

  function rowHtml(e) {
    return "<tr data-id=\"" + num(e.id) + "\">" +
      "<td><span class=\"aalog-date\">" + esc(formatDateTime(e.created_at)) + "</span></td>" +
      "<td><span class=\"aalog-actor\">" + esc(e.actor_name || "—") + "</span>" +
        "<span class=\"aalog-role\">" + esc(cap(e.actor_role)) + "</span></td>" +
      "<td><span class=\"admin-pill " + (ACTION_PILL[String(e.action || "").toLowerCase()] || "") + "\">" + esc(cap(e.action)) + "</span></td>" +
      "<td>" + esc(cap(e.entity_type)) + " <span class=\"aalog-entity-id\">#" + esc(e.entity_id ?? "—") + "</span></td>" +
      "<td><span class=\"aalog-desc\">" + esc(e.description || "—") + "</span></td>" +
      "<td><span class=\"aalog-diff\">" + diffCellHtml(e) + "</span></td>" +
      "<td class=\"aalog-actions-col\"><button class=\"admin-btn admin-btn--ghost\" type=\"button\" data-action=\"view\" data-id=\"" + num(e.id) + "\">Details</button></td>" +
      "</tr>";
  }

  function render(payload) {
    total = num(payload.total);
    totalPages = Math.max(1, num(payload.total_pages) || 1);
    page = num(payload.page) || 1;
    const entries = Array.isArray(payload.entries) ? payload.entries : [];
    entriesById = new Map(entries.map((e) => [num(e.id), e]));

    $("aalog-summary").innerHTML = "<strong>" + total + "</strong> audit entr" + (total === 1 ? "y" : "ies") + " in the current trail";

    const tbody = $("aalog-tbody");
    if (!entries.length) {
      tbody.innerHTML = "<tr><td colspan=\"7\" class=\"aalog-empty-cell\">" +
        (total === 0 && page === 1
          ? "No audit entries match these filters."
          : "No audit entries on this page.") +
        "</td></tr>";
    } else {
      tbody.innerHTML = entries.map(rowHtml).join("");
    }

    const per = num(payload.per_page) || 20;
    const from = total === 0 ? 0 : (page - 1) * per + 1;
    const to = Math.min(total, page * per);
    $("aalog-pager-info").textContent = "Showing " + from + "–" + to + " of " + total + " · Page " + page + " of " + totalPages;
    $("aalog-prev").disabled = page <= 1;
    $("aalog-next").disabled = page >= totalPages;
  }

  function fillOptions(payload) {
    if (optionsLoaded) return;
    optionsLoaded = true;
    const fo = payload.filter_options || {};
    const fill = (id, values) => {
      const sel = $(id);
      const current = sel.value;
      for (const v of values || []) {
        const opt = document.createElement("option");
        opt.value = v.value;
        opt.textContent = v.label;
        sel.appendChild(opt);
      }
      sel.value = current;
    };
    fill("aalog-action", (fo.actions || []).map((a) => ({ value: a, label: cap(a) })));
    fill("aalog-entity-type", (fo.entity_types || []).map((t) => ({ value: t, label: cap(t) })));
    // The endpoint names this list "admins"; it is every distinct actor.
    fill("aalog-actor", (fo.admins || []).map((a) => ({ value: String(a.actor_id), label: a.name })));
  }

  /* ---------- Loading ---------- */

  function queryString() {
    const p = new URLSearchParams();
    const set = (key, val) => { const v = String(val ?? "").trim(); if (v) p.set(key, v); };
    set("search", $("aalog-search").value);
    set("action", $("aalog-action").value);
    set("entity_type", $("aalog-entity-type").value);
    set("actor_id", $("aalog-actor").value);
    set("actor_role", $("aalog-role").value);
    set("entity_id", $("aalog-entity-id").value);
    set("patient_id", $("aalog-patient-id").value);
    set("doctor_id", $("aalog-doctor-id").value);
    set("date_from", $("aalog-date-from").value);
    set("date_to", $("aalog-date-to").value);
    p.set("limit", $("aalog-limit").value || "20");
    p.set("page", String(page));
    return p.toString();
  }

  async function load() {
    try {
      const payload = await api("api/audit/get.php?" + queryString());
      fillOptions(payload);
      render(payload);
      $("aalog-error").hidden = true;
    } catch (err) {
      $("aalog-tbody").innerHTML = "<tr><td colspan=\"7\" class=\"aalog-empty-cell\">The audit log could not be loaded.</td></tr>";
      $("aalog-error").hidden = false;
    }
    document.getElementById("admin-main")?.removeAttribute("aria-busy");
  }

  /* ---------- Detail modal ---------- */

  function openOverlay() {
    const el = $("aalog-detail-overlay");
    el.hidden = false;
    requestAnimationFrame(() => el.classList.add("is-open"));
  }

  function closeOverlay() {
    const el = $("aalog-detail-overlay");
    el.classList.remove("is-open");
    el.hidden = true;
  }

  function fillDetail(e) {
    const d = diffOf(e);
    const rows = d.lines.length
      ? d.lines.map((l) =>
          "<tr><td>" + esc(l.label) + "</td><td>" + esc(fmtVal(l.from)) + "</td><td>" + esc(fmtVal(l.to)) + "</td></tr>").join("")
      : "<tr><td colspan=\"3\" class=\"aalog-nodiff\">No field changes recorded for this entry.</td></tr>";
    const capKind = d.kind === "created" ? "Record created — the After column is the recorded state."
      : d.kind === "removed" ? "Record removed — the Before column is the last recorded state." : "";
    $("aalog-detail-title").textContent = cap(e.action) + " · " + cap(e.entity_type) + " #" + (e.entity_id ?? "—");
    $("aalog-detail-body").innerHTML =
      "<dl class=\"aalog-meta\">" +
      "<div><dt>Actor</dt><dd>" + esc(e.actor_name || "—") + " (" + esc(cap(e.actor_role)) + ")</dd></div>" +
      "<div><dt>When</dt><dd>" + esc(formatDateTime(e.created_at)) + "</dd></div>" +
      "<div><dt>Entry</dt><dd>#" + num(e.id) + "</dd></div>" +
      "<div><dt>IP address</dt><dd>" + esc(e.ip_address || "—") + "</dd></div>" +
      "</dl>" +
      (e.description ? "<p class=\"aalog-detail-desc\">" + esc(e.description) + "</p>" : "") +
      (capKind ? "<p class=\"aalog-detail-desc\">" + esc(capKind) + "</p>" : "") +
      "<div class=\"admin-table-wrap\"><table class=\"admin-table aalog-diff-table\">" +
      "<thead><tr><th scope=\"col\">Field</th><th scope=\"col\">Before</th><th scope=\"col\">After</th></tr></thead>" +
      "<tbody>" + rows + "</tbody></table></div>" +
      (e.user_agent ? "<p class=\"aalog-ua\">Recorded from: " + esc(e.user_agent) + "</p>" : "");
    openOverlay();
  }

  /* ---------- Wiring ---------- */

  function start() {
    $("aalog-filters").addEventListener("submit", (event) => {
      event.preventDefault();
      page = 1;
      load();
    });
    $("aalog-reset").addEventListener("click", () => {
      $("aalog-filters").reset();
      page = 1;
      load();
    });
    $("aalog-limit").addEventListener("change", () => { page = 1; load(); });
    $("aalog-prev").addEventListener("click", () => { if (page > 1) { page -= 1; load(); } });
    $("aalog-next").addEventListener("click", () => { if (page < totalPages) { page += 1; load(); } });
    $("aalog-tbody").addEventListener("click", (event) => {
      const btn = event.target.closest("[data-action=\"view\"]");
      if (!btn) return;
      const e = entriesById.get(num(btn.dataset.id));
      if (e) fillDetail(e);
    });
    $("aalog-detail-close").addEventListener("click", closeOverlay);
    $("aalog-detail-cancel").addEventListener("click", closeOverlay);
    $("aalog-detail-overlay").addEventListener("click", (event) => {
      if (event.target === event.currentTarget) closeOverlay();
    });
    document.addEventListener("keydown", (event) => { if (event.key === "Escape") closeOverlay(); });
    load();
  }

  document.addEventListener("admin:shell-ready", start, { once: true });
})();
