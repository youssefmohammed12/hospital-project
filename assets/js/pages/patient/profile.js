/**
 * HealthBridge — Patient Profile Page Controller
 * Renders comprehensive patient profile: personal details, medical info,
 * hospital info, primary doctor, emergency contacts, insurance, and activity summary.
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
    "Failed to load patient profile."
  );

  container.innerHTML = "";

  if (result.ok && result.data?.success) {
    const data = result.data.data;
    const profile = data?.profile;
    const overview = data?.overview;
    const snapshot = data?.health_snapshot;

    if (!profile) {
      container.innerHTML = `
        <div class="empty-state">
          <div class="empty-icon"><i class="fas fa-user-slash" aria-hidden="true"></i></div>
          <h4>Profile Data Unavailable</h4>
          <p>We could not find your medical record.</p>
          <a href="dashboard.html" class="btn btn-primary btn-sm">Back to Dashboard</a>
        </div>`;
      return;
    }

    const primaryDoc = overview?.primary_doctor;
    const nextAppt = overview?.next_appointment;

    // Helper for rendering fields
    const renderField = (label, val, icon = "") => {
      const isMissing = !val || val === "Not set" || val === "None recorded" || val === "—";
      const iconHtml = icon ? `<i class="fas ${icon}" style="color:var(--primary);margin-right:var(--s2)"></i>` : "";
      return `
        <div class="profile-field-card" style="background:var(--bg-card);border:1px solid var(--border-light);border-radius:var(--r-md);padding:var(--s4)">
          <div style="font-size:0.8rem;color:var(--text-muted);margin-bottom:var(--s1);display:flex;align-items:center">
            ${iconHtml}<span>${escapeHTML(label)}</span>
          </div>
          <div style="font-weight:600;font-size:0.95rem;color:${isMissing ? "var(--text-muted)" : "var(--text-primary)"}">
            ${escapeHTML(String(val || "—"))}
          </div>
        </div>`;
    };

    container.innerHTML = `
      <!-- Header Banner / Patient Identity -->
      <div class="card" style="margin-bottom:var(--s6);padding:var(--s6);background:linear-gradient(135deg, var(--bg-card) 0%, var(--bg-surface) 100%);border:1px solid var(--border)">
        <div style="display:flex;align-items:center;gap:var(--s5);flex-wrap:wrap">
          <div style="width:72px;height:72px;border-radius:50%;background:linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);display:flex;align-items:center;justify-content:center;font-size:1.8rem;color:#fff;font-weight:700">
            ${getInitials(profile.name)}
          </div>
          <div style="flex:1;min-width:200px">
            <h2 style="margin:0 0 var(--s1) 0;font-size:1.4rem;color:var(--text-primary)">${escapeHTML(profile.name || "Patient")}</h2>
            <div style="display:flex;gap:var(--s3);color:var(--text-secondary);font-size:0.85rem;flex-wrap:wrap">
              <span><i class="fas fa-id-badge" style="color:var(--primary)"></i> Patient #${escapeHTML(profile.patient_number || "-")}</span>
              <span><i class="fas fa-calendar-alt" style="color:var(--primary)"></i> Member Since ${profile.registered_at ? formatDate(profile.registered_at) : "—"}</span>
              <span><i class="fas fa-envelope" style="color:var(--primary)"></i> ${escapeHTML(profile.email || "—")}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Activity Summary Cards -->
      <div style="margin-bottom:var(--s6)">
        <h3 style="margin-bottom:var(--s4);font-size:1.1rem"><i class="fas fa-chart-pie" style="color:var(--primary)"></i> Activity Summary</h3>
        <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(180px, 1fr));gap:var(--s4)">
          <div class="kpi-card-compact" style="background:var(--bg-card);border:1px solid var(--border-light);border-radius:var(--r-lg);padding:var(--s4);display:flex;align-items:center;gap:var(--s3)">
            <div style="width:40px;height:40px;border-radius:50%;background:var(--primary-subtle);display:flex;align-items:center;justify-content:center;color:var(--primary);font-size:1.2rem"><i class="fas fa-calendar-check"></i></div>
            <div><div style="font-size:1.2rem;font-weight:700">${snapshot?.total_appointments || 0}</div><div style="font-size:0.75rem;color:var(--text-muted)">Total Visits</div></div>
          </div>
          <div class="kpi-card-compact" style="background:var(--bg-card);border:1px solid var(--border-light);border-radius:var(--r-lg);padding:var(--s4);display:flex;align-items:center;gap:var(--s3)">
            <div style="width:40px;height:40px;border-radius:50%;background:rgba(34, 197, 94, 0.1);display:flex;align-items:center;justify-content:center;color:var(--success);font-size:1.2rem"><i class="fas fa-circle-check"></i></div>
            <div><div style="font-size:1.2rem;font-weight:700">${snapshot?.completed_appointments || 0}</div><div style="font-size:0.75rem;color:var(--text-muted)">Completed</div></div>
          </div>
          <div class="kpi-card-compact" style="background:var(--bg-card);border:1px solid var(--border-light);border-radius:var(--r-lg);padding:var(--s4);display:flex;align-items:center;gap:var(--s3)">
            <div style="width:40px;height:40px;border-radius:50%;background:rgba(234, 179, 8, 0.1);display:flex;align-items:center;justify-content:center;color:var(--warning);font-size:1.2rem"><i class="fas fa-prescription"></i></div>
            <div><div style="font-size:1.2rem;font-weight:700">${snapshot?.active_prescriptions || 0}</div><div style="font-size:0.75rem;color:var(--text-muted)">Active Rx</div></div>
          </div>
          <div class="kpi-card-compact" style="background:var(--bg-card);border:1px solid var(--border-light);border-radius:var(--r-lg);padding:var(--s4);display:flex;align-items:center;gap:var(--s3)">
            <div style="width:40px;height:40px;border-radius:50%;background:rgba(6, 182, 212, 0.1);display:flex;align-items:center;justify-content:center;color:var(--info);font-size:1.2rem"><i class="fas fa-user-md"></i></div>
            <div><div style="font-size:1.2rem;font-weight:700">${snapshot?.doctors_seen || 0}</div><div style="font-size:0.75rem;color:var(--text-muted)">Doctors Visited</div></div>
          </div>
        </div>
      </div>

      <!-- Main Profile Grid -->
      <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(300px, 1fr));gap:var(--s6)">
        
        <!-- Personal Information -->
        <div class="card" style="padding:var(--s5)">
          <h3 style="margin-bottom:var(--s4);font-size:1.05rem;display:flex;align-items:center;gap:var(--s2)"><i class="fas fa-user" style="color:var(--primary)"></i> Personal Information</h3>
          <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(140px, 1fr));gap:var(--s3)">
            ${renderField("Patient Number", profile.patient_number, "fa-hashtag")}
            ${renderField("National ID", profile.national_id, "fa-address-card")}
            ${renderField("Date of Birth", profile.date_of_birth ? formatDate(profile.date_of_birth) : "", "fa-cake-candles")}
            ${renderField("Age", profile.age ? profile.age + " yrs" : "", "fa-hourglass-half")}
            ${renderField("Gender", profile.gender, "fa-venus-mars")}
            ${renderField("Phone", profile.phone, "fa-phone")}
            ${renderField("Email", profile.email, "fa-envelope")}
            ${renderField("Location", profile.location?.city ? `${profile.location.city}, ${profile.location.governorate}` : "", "fa-location-dot")}
          </div>
        </div>

        <!-- Medical Details -->
        <div class="card" style="padding:var(--s5)">
          <h3 style="margin-bottom:var(--s4);font-size:1.05rem;display:flex;align-items:center;gap:var(--s2)"><i class="fas fa-notes-medical" style="color:var(--primary)"></i> Medical Vitals & History</h3>
          <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(140px, 1fr));gap:var(--s3)">
            ${renderField("Blood Type", profile.blood_type, "fa-droplet")}
            ${renderField("Height", profile.height_cm ? profile.height_cm + " cm" : "", "fa-ruler-vertical")}
            ${renderField("Weight", profile.weight_kg ? profile.weight_kg + " kg" : "", "fa-weight-scale")}
            ${renderField("BMI", profile.bmi !== null ? `${profile.bmi} (${getBMICategory(profile.bmi)})` : "", "fa-heart-pulse")}
            ${renderField("Known Allergies", profile.allergies || "None recorded", "fa-allergies")}
            ${renderField("Chronic Diseases", profile.chronic_diseases || "None recorded", "fa-disease")}
            ${renderField("Current Medications", profile.current_medications || "None recorded", "fa-pills")}
          </div>
        </div>

        <!-- Hospital & Doctor Info -->
        <div class="card" style="padding:var(--s5)">
          <h3 style="margin-bottom:var(--s4);font-size:1.05rem;display:flex;align-items:center;gap:var(--s2)"><i class="fas fa-hospital-user" style="color:var(--primary)"></i> Care & Hospital Info</h3>
          <div style="margin-bottom:var(--s4);padding:var(--s4);background:var(--bg-surface);border-radius:var(--r-md);border:1px solid var(--border-light)">
            <div style="font-size:0.8rem;color:var(--text-muted);margin-bottom:var(--s2)">PRIMARY CARE PROVIDER</div>
            ${primaryDoc ? `
              <div style="display:flex;align-items:center;gap:var(--s3)">
                <div style="width:48px;height:48px;border-radius:50%;background:var(--primary-subtle);display:flex;align-items:center;justify-content:center;font-weight:700;color:var(--primary)">${getInitials(primaryDoc.name)}</div>
                <div>
                  <h4 style="margin:0;font-size:0.95rem">${escapeHTML(primaryDoc.name)}</h4>
                  <p style="margin:0;font-size:0.8rem;color:var(--text-secondary)">${escapeHTML(primaryDoc.specialty || primaryDoc.department || "")}</p>
                </div>
              </div>` : `<p style="margin:0;font-size:0.85rem;color:var(--text-muted)">No primary doctor assigned yet.</p>`}
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--s3)">
            ${renderField("Last Visit", snapshot?.last_visit || "—", "fa-clock-rotate-left")}
            ${renderField("Next Appointment", nextAppt ? `${formatDate(nextAppt.date)}` : "None scheduled", "fa-calendar-plus")}
          </div>
        </div>

        <!-- Emergency Contact & Insurance -->
        <div class="card" style="padding:var(--s5)">
          <h3 style="margin-bottom:var(--s4);font-size:1.05rem;display:flex;align-items:center;gap:var(--s2)"><i class="fas fa-shield-heart" style="color:var(--primary)"></i> Contact & Coverage</h3>
          <div style="margin-bottom:var(--s4)">
            <h4 style="font-size:0.85rem;color:var(--text-muted);margin-bottom:var(--s2);text-transform:uppercase">Emergency Contact</h4>
            <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(140px, 1fr));gap:var(--s3)">
              ${renderField("Contact Name", profile.emergency_contact?.name, "fa-user-shield")}
              ${renderField("Relationship", profile.emergency_contact?.relationship, "fa-people-arrows")}
              ${renderField("Phone", profile.emergency_contact?.phone, "fa-phone")}
            </div>
          </div>
          <div>
            <h4 style="font-size:0.85rem;color:var(--text-muted);margin-bottom:var(--s2);text-transform:uppercase">Insurance Information</h4>
            <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(140px, 1fr));gap:var(--s3)">
              ${renderField("Insurance Provider", profile.insurance?.provider, "fa-building-shield")}
              ${renderField("Policy / Member #", profile.insurance?.number, "fa-id-card")}
            </div>
          </div>
        </div>

      </div>`;
  } else {
    container.innerHTML = `
      <div class="empty-state">
        <div class="empty-icon"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i></div>
        <h4>Unable to Load Profile</h4>
        <p>Please check your connection and try again.</p>
        <button class="btn btn-outline btn-sm" onclick="location.reload()">Retry</button>
      </div>`;
  }
});

function getInitials(name) {
  if (!name) return "P";
  return name.split(" ").map(n => n[0]).join("").substring(0, 2).toUpperCase();
}

function getBMICategory(bmi) {
  if (bmi < 18.5) return "Underweight";
  if (bmi < 25) return "Normal";
  if (bmi < 30) return "Overweight";
  return "Obese";
}