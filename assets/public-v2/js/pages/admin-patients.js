/*
 * HealthBridge Admin — Patients register (Phase 3).
 *
 * The full register from api/patients/get_all.php (131 patients) with
 * client-side search + status filter, the Add Patient flow
 * (api/patients/add.php) and the row actions Deactivate/Reactivate
 * (toggle-status.php) and Archive (delete.php) — each behind a confirm
 * step that states the real consequence the endpoint applies:
 *   - toggle: flips is_active and revokes the patient's live sessions
 *     (session_version bump); appointments are NOT touched, and the
 *     patient is notified in-app.
 *   - archive: disables sign-in, revokes sessions, and cancels only
 *     appointments whose clinical work has not started; the record,
 *     EMR, prescriptions and audit history are retained. Archiving an
 *     already-inactive account is refused by the server (409), so the
 *     Archive action is only offered on active rows.
 * Every row links to the consolidated Patient Profile.
 * Depends on the Public V2 shared site.js runtime.
 */
"use strict";

(() => {
  const ROOT = typeof getBasePath === "function" ? getBasePath() : "../../";
  const $ = (id) => document.getElementById(id);
  const esc = window.HBHelpers.esc;
  const num = (v) => { const n = Number(v); return Number.isFinite(n) ? n : 0; };
  const fmtDate = (v) => (typeof window.HBHelpers.formatDate === "function" && v)
    ? window.HBHelpers.formatDate(v, "—") : String(v || "—");
  const initialsOf = (name) => String(name || "").trim().split(/\s+/).filter(Boolean)
    .slice(0, 2).map((part) => part.charAt(0).toUpperCase()).join("") || "—";

  let patients = [];

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

  function rowHtml(p) {
    const active = num(p.is_active) === 1;
    return "<tr data-id=\"" + num(p.id) + "\">" +
      "<td><div class=\"apat-pat\">" +
        "<span class=\"apat-avatar\" aria-hidden=\"true\">" + esc(initialsOf(p.name)) + "</span>" +
        "<span><a class=\"apat-pat__name\" href=\"patient-profile.html?id=" + num(p.id) + "\">" + esc(p.name) + "</a>" +
        "<span class=\"apat-pat__email\">" + esc(p.email || "—") + "</span></span>" +
      "</div></td>" +
      "<td>" + esc(p.phone || "—") + "</td>" +
      "<td>" + esc(fmtDate(String(p.created_at || "").slice(0, 10))) + "</td>" +
      "<td><span class=\"admin-pill " + (active ? "is-active" : "is-inactive") + "\">" + (active ? "Active" : "Inactive") + "</span></td>" +
      "<td><div class=\"apat-row-actions\">" +
        "<a class=\"admin-btn admin-btn--ghost\" href=\"patient-profile.html?id=" + num(p.id) + "\">Profile</a>" +
        "<button class=\"admin-btn admin-btn--ghost\" type=\"button\" data-action=\"toggle\" data-id=\"" + num(p.id) + "\">" + (active ? "Deactivate" : "Reactivate") + "</button>" +
        (active ? "<button class=\"admin-btn admin-btn--danger\" type=\"button\" data-action=\"archive\" data-id=\"" + num(p.id) + "\">Archive</button>" : "") +
      "</div></td></tr>";
  }

  function currentFilters() {
    return {
      q: String($("apat-search").value || "").trim().toLowerCase(),
      status: $("apat-status").value,
    };
  }

  function applyFilters() {
    const { q, status } = currentFilters();
    const visible = patients.filter((p) => {
      if (status !== "" && String(num(p.is_active)) !== status) return false;
      if (q) {
        const hay = [p.name, p.email, p.phone].join(" ").toLowerCase();
        if (!hay.includes(q)) return false;
      }
      return true;
    });
    const tbody = $("apat-tbody");
    if (!visible.length) {
      tbody.innerHTML = "<tr><td colspan=\"5\" class=\"apat-empty-cell\">No patients match these filters. <button class=\"admin-btn admin-btn--ghost\" type=\"button\" id=\"apat-reset\">Clear filters</button></td></tr>";
      $("apat-reset")?.addEventListener("click", () => {
        $("apat-search").value = ""; $("apat-status").value = "";
        applyFilters();
      });
    } else {
      tbody.innerHTML = visible.map(rowHtml).join("");
    }
    $("apat-count").textContent = "Showing " + visible.length + " of " + patients.length;
  }

  function renderSummary() {
    const active = patients.filter((p) => num(p.is_active) === 1).length;
    $("apat-summary").innerHTML = "<strong>" + patients.length + " patients</strong> · " +
      active + " active · " + (patients.length - active) + " inactive";
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

  let pending = null; // { kind: "toggle"|"archive", patient }

  function askConfirm(kind, patient) {
    pending = { kind, patient };
    const active = num(patient.is_active) === 1;
    if (kind === "toggle" && active) {
      $("apat-confirm-title").textContent = "Deactivate this patient?";
      $("apat-confirm-text").textContent = "Deactivating " + patient.name + " blocks sign-in and signs them out everywhere (their live sessions are revoked). Their appointments are not changed, and the patient is notified in-app. You can reactivate the account at any time.";
      $("apat-confirm-ok").textContent = "Deactivate patient";
    } else if (kind === "toggle") {
      $("apat-confirm-title").textContent = "Reactivate this patient?";
      $("apat-confirm-text").textContent = patient.name + " will be able to sign in again with their existing password.";
      $("apat-confirm-ok").textContent = "Reactivate patient";
    } else {
      $("apat-confirm-title").textContent = "Archive this patient?";
      $("apat-confirm-text").textContent = "Archiving " + patient.name + " disables sign-in, signs them out everywhere, and cancels their appointments that have not started yet. The account, medical records, prescriptions and audit history are kept — this is the soft delete, nothing is erased. An archived account can be brought back with Reactivate.";
      $("apat-confirm-ok").textContent = "Archive patient";
    }
    openOverlay("apat-confirm-overlay");
  }

  async function runPending() {
    if (!pending) return;
    const { kind, patient } = pending;
    pending = null;
    closeOverlay("apat-confirm-overlay");
    try {
      const url = kind === "toggle" ? "api/patients/toggle-status.php" : "api/patients/delete.php";
      const payload = await api(url, { method: "POST", body: JSON.stringify({ id: num(patient.id) }) });
      let message = payload.message || "Done.";
      if (kind === "archive" && num(payload.cancelled_appointments) > 0) {
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
    const errEl = $("apat-add-error");
    errEl.hidden = true;
    const body = {
      name: $("apat-add-name").value.trim(),
      email: $("apat-add-email").value.trim(),
      password: $("apat-add-password").value,
    };
    const phone = $("apat-add-phone").value.trim();
    if (phone) body.phone = phone;
    if (!body.name || !body.email || !body.password) {
      errEl.textContent = "Name, email and password are all required.";
      errEl.hidden = false;
      return;
    }
    const btn = $("apat-add-submit");
    btn.disabled = true;
    try {
      const payload = await api("api/patients/add.php", { method: "POST", body: JSON.stringify(body) });
      closeOverlay("apat-add-overlay");
      $("apat-add-form").reset();
      toast(payload.message || "Patient added.", "success");
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
      const payload = await api("api/patients/get_all.php");
      patients = Array.isArray(payload.patients) ? payload.patients : [];
      renderSummary();
      applyFilters();
      document.getElementById("admin-main")?.removeAttribute("aria-busy");
    } catch (err) {
      $("apat-error").hidden = false;
      $("apat-tbody").innerHTML = "<tr><td colspan=\"5\" class=\"apat-empty-cell\">The register could not be loaded.</td></tr>";
      document.getElementById("admin-main")?.removeAttribute("aria-busy");
    }
  }

  function start() {
    ["apat-search", "apat-status"].forEach((id) => {
      $(id).addEventListener("input", applyFilters);
      $(id).addEventListener("change", applyFilters);
    });
    $("apat-tbody").addEventListener("click", (event) => {
      const btn = event.target.closest("button[data-action]");
      if (!btn) return;
      const patient = patients.find((p) => num(p.id) === num(btn.dataset.id));
      if (patient) askConfirm(btn.dataset.action, patient);
    });
    $("apat-add-open").addEventListener("click", () => openOverlay("apat-add-overlay"));
    $("apat-add-close").addEventListener("click", () => closeOverlay("apat-add-overlay"));
    $("apat-add-cancel").addEventListener("click", () => closeOverlay("apat-add-overlay"));
    $("apat-add-form").addEventListener("submit", submitAdd);
    $("apat-confirm-close").addEventListener("click", () => closeOverlay("apat-confirm-overlay"));
    $("apat-confirm-cancel").addEventListener("click", () => closeOverlay("apat-confirm-overlay"));
    $("apat-confirm-ok").addEventListener("click", runPending);
    ["apat-add-overlay", "apat-confirm-overlay"].forEach((id) => {
      $(id).addEventListener("click", (event) => { if (event.target === event.currentTarget) closeOverlay(id); });
    });
    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape") { closeOverlay("apat-add-overlay"); closeOverlay("apat-confirm-overlay"); }
    });
    load();
  }

  document.addEventListener("admin:shell-ready", start, { once: true });
})();
