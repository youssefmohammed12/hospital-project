/**
 * HealthBridge Public V2 — Patient Profile (Phase 5)
 * Built new for Public V2; loads nothing from the legacy frontend.
 * Privacy: the National ID is masked client-side to its last 4 digits —
 * the full number is never written into the page.
 */
(function () {
  "use strict";

  const ROOT = window.location.pathname.includes("/pages/patient/")
    ? "../../"
    : "../";
  const API = ROOT + "api/patient/dashboard.php";

  const $ = (id) => document.getElementById(id);
  const text = (v, fallback = "") => (v == null ? fallback : String(v));
  const esc = window.HBHelpers.escText;
  const NOT_RECORDED = "Not recorded";

  function maskNationalId(value) {
    const digits = text(value).replace(/\s+/g, "");
    if (!digits) return NOT_RECORDED;
    if (digits.length <= 4) return "••••";
    return "••••••••••" + digits.slice(-4);
  }

  function formatDate(value) { return window.HBHelpers.formatDate(value, NOT_RECORDED); }

  function initials(name) {
    return text(name)
      .split(/\s+/)
      .filter(Boolean)
      .slice(0, 2)
      .map((part) => part.charAt(0).toUpperCase())
      .join("");
  }

  function row(label, value) {
    return (
      '<div class="pf-row"><dt>' + esc(label) + "</dt><dd>" +
      esc(value === "" || value == null ? NOT_RECORDED : value) + "</dd></div>"
    );
  }

  function setView(view) {
    $("pf-loading").hidden = view !== "loading";
    $("pf-error").hidden = view !== "error";
    $("pf-content").hidden = view !== "content";
  }

  function render(p) {
    $("pf-avatar").textContent = initials(p.name);
    $("pf-name").textContent = text(p.name, "Patient");
    $("pf-identity-meta").textContent = [
      p.patient_number ? "Patient " + p.patient_number : "",
      p.registered_at ? "Member since " + formatDate(p.registered_at) : "",
    ].filter(Boolean).join(" · ");

    // The stored address often already ends with the city/governorate —
    // append each part only if it is not already in the text.
    let location = text(p.location?.address);
    for (const part of [p.location?.city, p.location?.governorate]) {
      if (part && !location.includes(part)) location = location ? location + ", " + part : part;
    }
    $("pf-personal").innerHTML =
      row("Full name", p.name) +
      row("Date of birth", p.date_of_birth ? formatDate(p.date_of_birth) + (p.age ? " · age " + p.age : "") : "") +
      row("Gender", p.gender) +
      row("Phone", p.phone) +
      row("Email", p.email) +
      row("Address", location) +
      row("National ID", maskNationalId(p.national_id));
    $("pf-personal-note").textContent =
      "For your privacy, only the last 4 digits of your National ID are shown.";

    const joinList = (list, single) =>
      Array.isArray(list) && list.length ? list.join(", ") : text(single);
    $("pf-medical").innerHTML =
      row("Blood type", p.blood_type) +
      row("Height", p.height_cm ? p.height_cm + " cm" : "") +
      row("Weight", p.weight_kg ? p.weight_kg + " kg" : "") +
      row("BMI", p.bmi ? String(p.bmi) : "") +
      row("Allergies", joinList(p.allergies_list, p.allergies)) +
      row("Chronic diseases", joinList(p.chronic_diseases_list, p.chronic_diseases)) +
      row("Current medications", p.current_medications) +
      row("Previous surgeries", p.previous_surgeries) +
      row("Family history", p.family_history) +
      row("Medical notes", p.medical_notes);

    const ec = p.emergency_contact || {};
    $("pf-emergency").innerHTML =
      row("Name", ec.name) +
      row("Relationship", ec.relationship) +
      row("Phone", ec.phone);

    const ins = p.insurance || {};
    $("pf-insurance").innerHTML =
      row("Provider", ins.provider) +
      row("Policy number", ins.number);

    setView("content");
  }

  async function load() {
    setView("loading");
    try {
      const response = await fetch(API, {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ sections: ["profile"] }),
      });
      const payload = await response.json();
      if (!response.ok || !payload.success || !payload.data?.profile) {
        throw new Error(payload?.error?.message || payload?.message || "Could not load your profile.");
      }
      render(payload.data.profile);
    } catch (error) {
      console.error("Patient profile load failed:", error);
      $("pf-error-message").textContent = error.message || "Something went wrong while loading your profile.";
      setView("error");
    }
  }

  document.addEventListener("click", (event) => {
    if (event.target.closest('[data-pf-action="retry"]')) load();
  });

  load();
})();
