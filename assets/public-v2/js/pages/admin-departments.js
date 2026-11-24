/*
 * HealthBridge Admin — Departments (Phase 5).
 *
 * The full department set from api/departments/get.php
 * (include_inactive=1, so inactive departments stay manageable),
 * with the counts the endpoint computes per department
 * (doctor_count, appointment_count). Create / edit ride
 * create.php / update.php; activate / deactivate ride their own
 * endpoints; delete.php is permanent and the server guards it —
 * it refuses (HTTP 400) any department that still has assigned
 * doctors ("Cannot delete department with assigned doctors.
 * Deactivate instead.") or appointment history, and the confirm
 * copy says exactly that before the attempt. Doctor assignment
 * rides api/departments/assign-doctor.php {doctor_id (users.id),
 * department_id}; it refuses inactive departments, and the
 * doctor's specialty follows the department name.
 * Depends on the Public V2 shared site.js runtime.
 */
"use strict";

(() => {
  const ROOT = typeof getBasePath === "function" ? getBasePath() : "../../";
  const $ = (id) => document.getElementById(id);
  const esc = window.HBHelpers.esc;
  const num = (v) => { const n = Number(v); return Number.isFinite(n) ? n : 0; };

  let departments = [];
  let doctors = [];

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

  function rowHtml(d) {
    const active = d.status === "active";
    return "<tr data-id=\"" + num(d.id) + "\">" +
      "<td><span class=\"adept-name\">" + esc(d.name) + "</span>" +
        (d.description ? "<span class=\"adept-desc\">" + esc(d.description) + "</span>" : "") + "</td>" +
      "<td><span class=\"admin-pill " + (active ? "is-active" : "is-inactive") + "\">" + (active ? "Active" : "Inactive") + "</span></td>" +
      "<td><span class=\"adept-num\">" + num(d.doctor_count) + "</span></td>" +
      "<td><span class=\"adept-num\">" + num(d.appointment_count) + "</span></td>" +
      "<td><div class=\"adept-row-actions\">" +
        "<button class=\"admin-btn admin-btn--ghost\" type=\"button\" data-action=\"edit\" data-id=\"" + num(d.id) + "\">Edit</button>" +
        (active ? "<button class=\"admin-btn admin-btn--ghost\" type=\"button\" data-action=\"assign\" data-id=\"" + num(d.id) + "\">Assign Doctor</button>" : "") +
        (active
          ? "<button class=\"admin-btn admin-btn--ghost\" type=\"button\" data-action=\"deactivate\" data-id=\"" + num(d.id) + "\">Deactivate</button>"
          : "<button class=\"admin-btn admin-btn--ghost\" type=\"button\" data-action=\"activate\" data-id=\"" + num(d.id) + "\">Activate</button>") +
        "<button class=\"admin-btn admin-btn--danger\" type=\"button\" data-action=\"delete\" data-id=\"" + num(d.id) + "\">Delete</button>" +
      "</div></td></tr>";
  }

  function applyFilters() {
    const q = String($("adept-search").value || "").trim().toLowerCase();
    const status = $("adept-status").value;
    const visible = departments.filter((d) => {
      if (status && d.status !== status) return false;
      if (q) {
        const hay = [d.name, d.description || ""].join(" ").toLowerCase();
        if (!hay.includes(q)) return false;
      }
      return true;
    });
    const tbody = $("adept-tbody");
    if (!visible.length) {
      tbody.innerHTML = "<tr><td colspan=\"5\" class=\"adept-empty-cell\">No departments match these filters. <button class=\"admin-btn admin-btn--ghost\" type=\"button\" id=\"adept-reset\">Clear filters</button></td></tr>";
      $("adept-reset")?.addEventListener("click", () => {
        $("adept-search").value = ""; $("adept-status").value = "";
        applyFilters();
      });
    } else {
      tbody.innerHTML = visible.map(rowHtml).join("");
    }
    $("adept-count").textContent = "Showing " + visible.length + " of " + departments.length;
  }

  function renderSummary() {
    const active = departments.filter((d) => d.status === "active").length;
    const docTotal = departments.reduce((sum, d) => sum + num(d.doctor_count), 0);
    const apptTotal = departments.reduce((sum, d) => sum + num(d.appointment_count), 0);
    $("adept-summary").innerHTML = "<strong>" + departments.length + " departments</strong> · " +
      active + " active · " + docTotal + " doctors assigned · " + apptTotal + " appointments on record";
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
  const OVERLAYS = ["adept-form-overlay", "adept-assign-overlay", "adept-confirm-overlay"];
  function closeAll() { OVERLAYS.forEach(closeOverlay); }

  /* ---------- Create / Edit ---------- */

  let editing = null; // department being edited, or null for create

  function openForm(department) {
    editing = department || null;
    $("adept-form-error").hidden = true;
    $("adept-form-title").textContent = department ? "Edit Department" : "Add Department";
    $("adept-form-submit").textContent = department ? "Save Changes" : "Add Department";
    $("adept-form-name").value = department ? department.name : "";
    $("adept-form-desc").value = department ? (department.description || "") : "";
    $("adept-form-status-field").hidden = !department;
    $("adept-form-status").value = department ? department.status : "active";
    openOverlay("adept-form-overlay");
  }

  async function submitForm(event) {
    event.preventDefault();
    const errEl = $("adept-form-error");
    errEl.hidden = true;
    const name = $("adept-form-name").value.trim();
    if (!name) {
      errEl.textContent = "Department name is required.";
      errEl.hidden = false;
      return;
    }
    const body = { name, description: $("adept-form-desc").value.trim() };
    let url = "api/departments/create.php";
    if (editing) {
      url = "api/departments/update.php";
      body.id = num(editing.id);
      body.status = $("adept-form-status").value;
    }
    const btn = $("adept-form-submit");
    btn.disabled = true;
    try {
      const payload = await api(url, { method: "POST", body: JSON.stringify(body) });
      closeOverlay("adept-form-overlay");
      $("adept-form").reset();
      toast(payload.message || "Saved.", "success");
      await load();
    } catch (err) {
      errEl.textContent = err.message;
      errEl.hidden = false;
    } finally {
      btn.disabled = false;
    }
  }

  /* ---------- Assign doctor ---------- */

  let assigning = null; // target department

  function openAssign(department) {
    assigning = department;
    $("adept-assign-error").hidden = true;
    $("adept-assign-title").textContent = "Assign Doctor to " + department.name;
    $("adept-assign-note").textContent = department.name + " currently has " + num(department.doctor_count) +
      " doctor(s). Pick a doctor to move into this department.";
    const sel = $("adept-assign-doctor");
    sel.innerHTML = "<option value=\"\">Choose a doctor…</option>" + doctors
      .filter((d) => num(d.department_id) !== num(department.id))
      .map((d) => "<option value=\"" + num(d.id) + "\">" + esc(d.name) +
        " — currently " + esc(d.department_name || "no department") + "</option>").join("");
    openOverlay("adept-assign-overlay");
  }

  async function submitAssign(event) {
    event.preventDefault();
    const errEl = $("adept-assign-error");
    errEl.hidden = true;
    const doctorId = num($("adept-assign-doctor").value);
    if (!doctorId || !assigning) {
      errEl.textContent = "Choose a doctor to assign.";
      errEl.hidden = false;
      return;
    }
    const btn = $("adept-assign-submit");
    btn.disabled = true;
    try {
      const payload = await api("api/departments/assign-doctor.php", {
        method: "POST",
        body: JSON.stringify({ doctor_id: doctorId, department_id: num(assigning.id) }),
      });
      closeOverlay("adept-assign-overlay");
      toast(payload.message || "Doctor assigned.", "success");
      await load();
    } catch (err) {
      errEl.textContent = err.message;
      errEl.hidden = false;
    } finally {
      btn.disabled = false;
    }
  }

  /* ---------- Activate / Deactivate / Delete ---------- */

  let pending = null; // { kind, department }

  function askConfirm(kind, d) {
    pending = { kind, department: d };
    const title = $("adept-confirm-title");
    const text = $("adept-confirm-text");
    const ok = $("adept-confirm-ok");
    if (kind === "deactivate") {
      title.textContent = "Deactivate this department?";
      text.textContent = "Deactivating " + d.name + " hides it from booking and blocks new doctor assignments. Its " +
        num(d.doctor_count) + " doctor(s) keep their assignment and its " + num(d.appointment_count) +
        " appointment(s) of history stay untouched. You can activate it again at any time.";
      ok.textContent = "Deactivate";
    } else if (kind === "activate") {
      title.textContent = "Activate this department?";
      text.textContent = d.name + " becomes bookable again, and doctors can be assigned to it.";
      ok.textContent = "Activate";
    } else {
      title.textContent = "Delete this department permanently?";
      if (num(d.doctor_count) > 0) {
        text.textContent = "The server will refuse this delete: " + d.name + " still has " + num(d.doctor_count) +
          " doctor(s) assigned, and a department with assigned doctors cannot be deleted. Deactivate it instead — deactivation keeps every record and can be undone.";
      } else if (num(d.appointment_count) > 0) {
        text.textContent = "The server will refuse this delete: " + d.name + " still has " + num(d.appointment_count) +
          " appointment(s) on record, and a department with appointment history cannot be deleted. Deactivate it instead — deactivation keeps every record and can be undone.";
      } else {
        text.textContent = d.name + " has no doctors and no appointment history, so the server allows a permanent delete. This cannot be undone.";
      }
      ok.textContent = "Delete Permanently";
    }
    openOverlay("adept-confirm-overlay");
  }

  async function runPending() {
    if (!pending) return;
    const { kind, department } = pending;
    pending = null;
    closeOverlay("adept-confirm-overlay");
    const url = {
      activate: "api/departments/activate.php",
      deactivate: "api/departments/deactivate.php",
      delete: "api/departments/delete.php",
    }[kind];
    try {
      const payload = await api(url, { method: "POST", body: JSON.stringify({ id: num(department.id) }) });
      toast(payload.message || "Done.", "success");
      await load();
    } catch (err) {
      // The delete guard lands here: the server's refusal, verbatim.
      toast(err.message, "error");
    }
  }

  /* ---------- Load ---------- */

  async function load() {
    try {
      const [deptPayload, docsPayload] = await Promise.all([
        api("api/departments/get.php?include_inactive=1"),
        api("api/doctors/get_all.php"),
      ]);
      departments = Array.isArray(deptPayload.departments) ? deptPayload.departments : [];
      doctors = Array.isArray(docsPayload.doctors) ? docsPayload.doctors : [];
      renderSummary();
      applyFilters();
      document.getElementById("admin-main")?.removeAttribute("aria-busy");
    } catch (err) {
      $("adept-error").hidden = false;
      $("adept-tbody").innerHTML = "<tr><td colspan=\"5\" class=\"adept-empty-cell\">The department list could not be loaded.</td></tr>";
      document.getElementById("admin-main")?.removeAttribute("aria-busy");
    }
  }

  function start() {
    ["adept-search", "adept-status"].forEach((id) => {
      $(id).addEventListener("input", applyFilters);
      $(id).addEventListener("change", applyFilters);
    });
    $("adept-tbody").addEventListener("click", (event) => {
      const btn = event.target.closest("button[data-action]");
      if (!btn) return;
      const department = departments.find((d) => num(d.id) === num(btn.dataset.id));
      if (!department) return;
      if (btn.dataset.action === "edit") openForm(department);
      else if (btn.dataset.action === "assign") openAssign(department);
      else askConfirm(btn.dataset.action, department);
    });
    $("adept-add-open").addEventListener("click", () => openForm(null));
    $("adept-form-close").addEventListener("click", () => closeOverlay("adept-form-overlay"));
    $("adept-form-cancel").addEventListener("click", () => closeOverlay("adept-form-overlay"));
    $("adept-form").addEventListener("submit", submitForm);
    $("adept-assign-close").addEventListener("click", () => closeOverlay("adept-assign-overlay"));
    $("adept-assign-cancel").addEventListener("click", () => closeOverlay("adept-assign-overlay"));
    $("adept-assign-form").addEventListener("submit", submitAssign);
    $("adept-confirm-close").addEventListener("click", () => closeOverlay("adept-confirm-overlay"));
    $("adept-confirm-cancel").addEventListener("click", () => closeOverlay("adept-confirm-overlay"));
    $("adept-confirm-ok").addEventListener("click", runPending);
    OVERLAYS.forEach((id) => {
      $(id).addEventListener("click", (event) => { if (event.target === event.currentTarget) closeOverlay(id); });
    });
    document.addEventListener("keydown", (event) => { if (event.key === "Escape") closeAll(); });
    load();
  }

  document.addEventListener("admin:shell-ready", start, { once: true });
})();
