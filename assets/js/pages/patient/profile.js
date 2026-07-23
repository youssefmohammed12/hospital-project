/**
 * HealthBridge — Patient Profile Page
 * Loads and displays patient profile data.
 */
"use strict";

document.addEventListener("DOMContentLoaded", async () => {
  requireAuth();
  
  const user = getUser();
  if (!user || user.role !== "patient") {
    window.location.href = getBasePath() + "pages/auth/login.html";
    return;
  }

  const container = document.getElementById("profile-container");
  if (!container) return;

  const result = await apiFetch(
    getBasePath() + "api/patient/dashboard.php",
    {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({})
    },
    "Failed to load profile."
  );

  container.innerHTML = "";

  if (result.ok && result.data?.success) {
    const profile = result.data.data?.profile;
    if (!profile) {
      container.innerHTML = '<div class="empty-state"><div class="empty-icon"><i class="fas fa-user" aria-hidden="true"></i></div><h4>Profile data not available</h4><p>Please complete your registration.</p><a href="dashboard.html" class="btn btn-primary btn-sm">Back to Dashboard</a></div>';
      return;
    }

    // Personal Information
    container.innerHTML = `
      <div class="profile-section" style="margin-bottom:var(--s6)">
        <h3 style="margin-bottom:var(--s4)"><i class="fas fa-user" aria-hidden="true"></i> Personal Information</h3>
        <div class="profile-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:var(--s4)">
          ${renderField("Patient Number", profile.patient_number)}
          ${renderField("Full Name", profile.name)}
          ${renderField("Email", profile.email)}
          ${renderField("Phone", profile.phone || "Not set")}
          ${renderField("Date of Birth", profile.date_of_birth || "Not set")}
          ${renderField("Age", profile.age ? profile.age + " years" : "—")}
          ${renderField("Gender", profile.gender || "Not set")}
          ${renderField("National ID", profile.national_id || "Not set")}
        </div>
      </div>
      <div class="profile-section" style="margin-bottom:var(--s6)">
        <h3 style="margin-bottom:var(--s4)"><i class="fas fa-notes-medical" aria-hidden="true"></i> Medical Information</h3>
        <div class="profile-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:var(--s4)">
          ${renderField("Blood Type", profile.blood_type || "Not set")}
          ${renderField("Height", profile.height_cm ? profile.height_cm + " cm" : "Not set")}
          ${renderField("Weight", profile.weight_kg ? profile.weight_kg + " kg" : "Not set")}
          ${renderField("BMI", profile.bmi !== null ? profile.bmi : "—")}
          ${renderField("Allergies", profile.allergies || "None recorded")}
          ${renderField("Chronic Diseases", profile.chronic_diseases || "None recorded")}
        </div>
      </div>
      <div class="profile-section" style="margin-bottom:var(--s6)">
        <h3 style="margin-bottom:var(--s4)"><i class="fas fa-phone" aria-hidden="true"></i> Emergency Contact</h3>
        <div class="profile-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:var(--s4)">
          ${renderField("Contact Name", profile.emergency_contact?.name || "Not set")}
          ${renderField("Relationship", profile.emergency_contact?.relationship || "Not set")}
          ${renderField("Phone", profile.emergency_contact?.phone || "Not set")}
        </div>
      </div>
      <div class="profile-section">
        <h3 style="margin-bottom:var(--s4)"><i class="fas fa-id-card" aria-hidden="true"></i> Insurance</h3>
        <div class="profile-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:var(--s4)">
          ${renderField("Provider", profile.insurance?.provider || "Not set")}
          ${renderField("Policy Number", profile.insurance?.number || "Not set")}
        </div>
      </div>`;
  } else {
    container.innerHTML = '<div class="empty-state"><div class="empty-icon"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i></div><h4>Unable to Load Profile</h4><p>Please try again later.</p><button class="btn btn-outline btn-sm" onclick="location.reload()">Try Again</button></div>';
  }
});

function renderField(label, value) {
  const isMissing = value === "Not set" || value === "None recorded" || value === "—";
  return `<div class="profile-field"><span class="field-label">${escapeHTML(label)}</span><span class="field-value${isMissing ? " missing" : ""}">${escapeHTML(String(value))}</span></div>`;
}