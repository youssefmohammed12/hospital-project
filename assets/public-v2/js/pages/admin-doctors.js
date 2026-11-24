/*
 * HealthBridge Admin — Doctors directory (Phase 2).
 *
 * The full directory from api/doctors/get_all.php (32 doctors) with
 * client-side search + department/status filters, the Add Doctor flow
 * (api/doctors/add.php) and the row actions Disable/Enable
 * (toggle-status.php) and Archive (delete.php, a soft delete) — each
 * behind a confirm step that states the real consequence the endpoint
 * applies (disabling cancels the doctor's not-started appointments).
 * Every row links to the consolidated Doctor Profile (D2).
 * Depends on the Public V2 shared site.js runtime.
 */
"use strict";

(() => {
  const ROOT = typeof getBasePath === "function" ? getBasePath() : "../../";
  const $ = (id) => document.getElementById(id);
  const esc = window.HBHelpers.esc;
  const num = (v) => { const n = Number(v); return Number.isFinite(n) ? n : 0; };
  const initialsOf = (name) => String(name || "").trim().split(/\s+/).filter(Boolean)
    .slice(0, 2).map((part) => part.charAt(0).toUpperCase()).join("") || "—";

  let doctors = [];
  let departments = [];

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

  /* ---------- Rendering ---------- */

  function stars(rating) {
    const r = Math.round(num(rating));
    return "★★★★★".slice(0, r) + "☆☆☆☆☆".slice(0, 5 - r);
  }

  function rowHtml(d) {
    const active = num(d.is_active) === 1;
    const available = num(d.available) === 1;
    return "<tr data-id=\"" + num(d.id) + "\">" +
      "<td><div class=\"adoc-doc\">" +
        "<span class=\"adoc-avatar\" aria-hidden=\"true\">" + esc(initialsOf(d.name)) + "</span>" +
        "<span><a class=\"adoc-doc__name\" href=\"doctor-profile.html?id=" + num(d.id) + "\">" + esc(d.name) + "</a>" +
        "<span class=\"adoc-doc__email\">" + esc(d.email || "—") + "</span></span>" +
      "</div></td>" +
      "<td>" + esc(d.department_name || "—") + "</td>" +
      "<td>" + (d.exp === null || d.exp === undefined ? "—" : num(d.exp) + " yrs") + "</td>" +
      "<td><span class=\"adoc-stars\" aria-label=\"Rated " + esc(String(d.rating ?? "0")) + " out of 5\">" + stars(d.rating) + " <small>" + esc(Number(num(d.rating).toFixed(1))) + "</small></span></td>" +
      "<td><span class=\"admin-pill " + (active ? "is-active" : "is-inactive") + "\">" + (active ? "Active" : "Inactive") + "</span> " +
        "<span class=\"adoc-avail" + (available ? "" : " is-off") + "\"><i aria-hidden=\"true\"></i>" + (available ? "Available" : "Unavailable") + "</span></td>" +
      "<td><div class=\"adoc-row-actions\">" +
        "<a class=\"admin-btn admin-btn--ghost\" href=\"doctor-profile.html?id=" + num(d.id) + "\">Profile</a>" +
        "<button class=\"admin-btn admin-btn--ghost\" type=\"button\" data-action=\"toggle\" data-id=\"" + num(d.id) + "\">" + (active ? "Disable" : "Enable") + "</button>" +
        (active ? "<button class=\"admin-btn admin-btn--danger\" type=\"button\" data-action=\"archive\" data-id=\"" + num(d.id) + "\">Archive</button>" : "") +
      "</div></td></tr>";
  }

  function currentFilters() {
    return {
      q: String($("adoc-search").value || "").trim().toLowerCase(),
      dept: $("adoc-dept").value,
      status: $("adoc-status").value,
    };
  }

  function applyFilters() {
    const { q, dept, status } = currentFilters();
    const visible = doctors.filter((d) => {
      if (dept && String(d.department_id) !== dept) return false;
      if (status !== "" && String(num(d.is_active)) !== status) return false;
      if (q) {
        const hay = [d.name, d.email, d.specialty, d.department_name].join(" ").toLowerCase();
        if (!hay.includes(q)) return false;
      }
      return true;
    });
    const tbody = $("adoc-tbody");
    if (!visible.length) {
      tbody.innerHTML = "<tr><td colspan=\"6\" class=\"adoc-empty-cell\">No doctors match these filters. <button class=\"admin-btn admin-btn--ghost\" type=\"button\" id=\"adoc-reset\">Clear filters</button></td></tr>";
      $("adoc-reset")?.addEventListener("click", () => {
        $("adoc-search").value = ""; $("adoc-dept").value = ""; $("adoc-status").value = "";
        applyFilters();
      });
    } else {
      tbody.innerHTML = visible.map(rowHtml).join("");
    }
    $("adoc-count").textContent = "Showing " + visible.length + " of " + doctors.length;
  }

  function renderSummary() {
    const active = doctors.filter((d) => num(d.is_active) === 1).length;
    const available = doctors.filter((d) => num(d.available) === 1).length;
    $("adoc-summary").innerHTML = "<strong>" + doctors.length + " doctors</strong> · " +
      active + " active · " + available + " available for bookings · " + departments.length + " departments";
  }

  /* ---------- Modals ---------- */

  function openOverlay(id) {
    const el = $(id);
    el.hidden = false;
    requestAnimationFrame(() => el.classList.add("is-open"));
  }
  function closeOverlay(id) {
    const el = $(id);
    el.classList.remove("is-open");
    el.hidden = true;
  }

  /* ---------- Actions ---------- */

  let pending = null; // { kind: "toggle"|"archive", doctor }

  function askConfirm(kind, doctor) {
    pending = { kind, doctor };
    const active = num(doctor.is_active) === 1;
    if (kind === "toggle" && active) {
      $("adoc-confirm-title").textContent = "Disable this doctor?";
      $("adoc-confirm-text").textContent = "Disabling " + doctor.name + " blocks sign-in and new bookings, and cancels their not-started appointments (patients are notified). You can enable the account again at any time.";
      $("adoc-confirm-ok").textContent = "Disable doctor";
    } else if (kind === "toggle") {
      $("adoc-confirm-title").textContent = "Enable this doctor?";
      $("adoc-confirm-text").textContent = doctor.name + " will be able to sign in again and take new bookings.";
      $("adoc-confirm-ok").textContent = "Enable doctor";
    } else {
      $("adoc-confirm-title").textContent = "Archive this doctor?";
      $("adoc-confirm-text").textContent = "Archiving " + doctor.name + " disables sign-in and cancels their not-started appointments. The profile, schedule and clinical history are kept. This is the soft delete — nothing is erased.";
      $("adoc-confirm-ok").textContent = "Archive doctor";
    }
    openOverlay("adoc-confirm-overlay");
  }

  async function runPending() {
    if (!pending) return;
    const { kind, doctor } = pending;
    pending = null;
    closeOverlay("adoc-confirm-overlay");
    try {
      const url = kind === "toggle" ? "api/doctors/toggle-status.php" : "api/doctors/delete.php";
      const payload = await api(url, { method: "POST", body: JSON.stringify({ id: num(doctor.id) }) });
      let message = payload.message || "Done.";
      if (kind === "toggle" && num(payload.cancelled_appointments) > 0) {
        message += " " + payload.cancelled_appointments + " not-started appointment(s) were cancelled.";
      }
      toast(message, "success");
      await load();
    } catch (err) {
      toast(err.message, "error");
    }
  }

  async function submitAdd(event) {
    event.preventDefault();
    const errEl = $("adoc-add-error");
    errEl.hidden = true;
    const body = {
      name: $("adoc-add-name").value.trim(),
      email: $("adoc-add-email").value.trim(),
      password: $("adoc-add-password").value,
      department_id: num($("adoc-add-dept").value) || "",
    };
    if (!body.name || !body.email || !body.password || !body.department_id) {
      errEl.textContent = "Name, email, password and department are all required.";
      errEl.hidden = false;
      return;
    }
    const btn = $("adoc-add-submit");
    btn.disabled = true;
    try {
      const payload = await api("api/doctors/add.php", { method: "POST", body: JSON.stringify(body) });
      closeOverlay("adoc-add-overlay");
      $("adoc-add-form").reset();
      toast(payload.message || "Doctor added.", "success");
      await load();
    } catch (err) {
      errEl.textContent = err.message;
      errEl.hidden = false;
    } finally {
      btn.disabled = false;
    }
  }

  /* ---------- Load ---------- */

  async function load() {
    try {
      const [docsPayload, deptPayload] = await Promise.all([
        api("api/doctors/get_all.php"),
        api("api/departments/get.php"),
      ]);
      doctors = Array.isArray(docsPayload.doctors) ? docsPayload.doctors : [];
      departments = Array.isArray(deptPayload.departments) ? deptPayload.departments : [];
      const deptSel = $("adoc-dept");
      const current = deptSel.value;
      deptSel.innerHTML = "<option value=\"\">All departments</option>" + departments.map((d) =>
        "<option value=\"" + num(d.id) + "\">" + esc(d.name) + "</option>").join("");
      deptSel.value = current;
      $("adoc-add-dept").innerHTML = "<option value=\"\">Choose a department…</option>" + departments.map((d) =>
        "<option value=\"" + num(d.id) + "\">" + esc(d.name) + "</option>").join("");
      renderSummary();
      applyFilters();
      document.getElementById("admin-main")?.removeAttribute("aria-busy");
    } catch (err) {
      $("adoc-error").hidden = false;
      $("adoc-tbody").innerHTML = "<tr><td colspan=\"6\" class=\"adoc-empty-cell\">The directory could not be loaded.</td></tr>";
      document.getElementById("admin-main")?.removeAttribute("aria-busy");
    }
  }

  function start() {
    ["adoc-search", "adoc-dept", "adoc-status"].forEach((id) => {
      $(id).addEventListener("input", applyFilters);
      $(id).addEventListener("change", applyFilters);
    });
    $("adoc-tbody").addEventListener("click", (event) => {
      const btn = event.target.closest("button[data-action]");
      if (!btn) return;
      const doctor = doctors.find((d) => num(d.id) === num(btn.dataset.id));
      if (doctor) askConfirm(btn.dataset.action, doctor);
    });
    $("adoc-add-open").addEventListener("click", () => openOverlay("adoc-add-overlay"));
    $("adoc-add-close").addEventListener("click", () => closeOverlay("adoc-add-overlay"));
    $("adoc-add-cancel").addEventListener("click", () => closeOverlay("adoc-add-overlay"));
    $("adoc-add-form").addEventListener("submit", submitAdd);
    $("adoc-confirm-close").addEventListener("click", () => closeOverlay("adoc-confirm-overlay"));
    $("adoc-confirm-cancel").addEventListener("click", () => closeOverlay("adoc-confirm-overlay"));
    $("adoc-confirm-ok").addEventListener("click", runPending);
    ["adoc-add-overlay", "adoc-confirm-overlay"].forEach((id) => {
      $(id).addEventListener("click", (event) => { if (event.target === event.currentTarget) closeOverlay(id); });
    });
    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape") { closeOverlay("adoc-add-overlay"); closeOverlay("adoc-confirm-overlay"); }
    });
    load();
  }

  document.addEventListener("admin:shell-ready", start, { once: true });
})();
