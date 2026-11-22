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
      body: JSON.stringify({ sections: ["profile", "overview", "health_snapshot"] })
    },
    "Failed to load patient profile."
  );

  container.innerHTML = "";

  if (result.ok && result.data?.success) {
    const isRecord = value =>
      value !== null &&
      typeof value === "object" &&
      !Array.isArray(value);
    const sourceData = result.data.data;
    const data = isRecord(sourceData) ? sourceData : {};
    const profileSource = isRecord(data.profile) ? data.profile : null;

    const textField = (value, limit) =>
      typeof value === "string" ? value.slice(0, limit) : "";
    const dateField = (value, limit = 40) => {
      const candidate = textField(value, limit).trim();
      return candidate && !Number.isNaN(Date.parse(candidate)) ? candidate : "";
    };
    const metricField = (value, maximum) => {
      let number = value;
      if (typeof value === "string") {
        const candidate = value.trim();
        if (!candidate || candidate.length > 12 || !/^\d+(?:\.\d+)?$/.test(candidate)) {
          return null;
        }
        number = Number(candidate);
      }
      return typeof number === "number" &&
        Number.isFinite(number) &&
        number > 0 &&
        number <= maximum
        ? number
        : null;
    };
    const ageField = value => {
      const age = metricField(value, 130);
      return Number.isInteger(age) ? age : null;
    };

    if (!profileSource) {
      container.innerHTML = `
        <div class="empty-state">
          <div class="empty-icon"><i class="fas fa-user-slash" aria-hidden="true"></i></div>
          <h4>Profile Data Unavailable</h4>
          <p>We could not find your medical record.</p>
          <a href="dashboard.html" class="btn btn-primary btn-sm">Back to Dashboard</a>
        </div>`;
      return;
    }

    const locationSource = isRecord(profileSource.location) ? profileSource.location : {};
    const emergencySource = isRecord(profileSource.emergency_contact)
      ? profileSource.emergency_contact
      : {};
    const insuranceSource = isRecord(profileSource.insurance)
      ? profileSource.insurance
      : {};
    const profile = {
      name: textField(profileSource.name, 120) || "Patient",
      patient_number: textField(profileSource.patient_number, 40),
      national_id: textField(profileSource.national_id, 40),
      registered_at: dateField(profileSource.registered_at),
      date_of_birth: dateField(profileSource.date_of_birth),
      age: ageField(profileSource.age),
      gender: textField(profileSource.gender, 60),
      email: textField(profileSource.email, 254),
      phone: textField(profileSource.phone, 40),
      blood_type: textField(profileSource.blood_type, 20),
      height_cm: metricField(profileSource.height_cm, 300),
      weight_kg: metricField(profileSource.weight_kg, 1000),
      bmi: metricField(profileSource.bmi, 100),
      allergies: textField(profileSource.allergies, 1000),
      chronic_diseases: textField(profileSource.chronic_diseases, 1000),
      current_medications: textField(profileSource.current_medications, 1000),
      location: {
        city: textField(locationSource.city, 100),
        governorate: textField(locationSource.governorate, 100),
      },
      emergency_contact: {
        name: textField(emergencySource.name, 160),
        relationship: textField(emergencySource.relationship, 80),
        phone: textField(emergencySource.phone, 40),
      },
      insurance: {
        provider: textField(insuranceSource.provider, 160),
        number: textField(insuranceSource.number, 100),
      },
    };

    const overview = isRecord(data.overview) ? data.overview : {};
    const doctorSource = isRecord(overview.primary_doctor)
      ? overview.primary_doctor
      : null;
    const appointmentSource = isRecord(overview.next_appointment)
      ? overview.next_appointment
      : null;
    const primaryDoc = doctorSource
      ? {
          name: textField(doctorSource.name, 120),
          specialty: textField(doctorSource.specialty, 120),
          department: textField(doctorSource.department, 120),
        }
      : null;
    const nextAppt = appointmentSource
      ? { date: dateField(appointmentSource.date) }
      : null;
    const snapshotSource = isRecord(data.health_snapshot) ? data.health_snapshot : null;
    const snapshot = snapshotSource
      ? {
          total_appointments: safeCount(snapshotSource.total_appointments, 0),
          completed_appointments: safeCount(snapshotSource.completed_appointments, 0),
          active_prescriptions: safeCount(snapshotSource.active_prescriptions, 0),
          doctors_seen: safeCount(snapshotSource.doctors_seen, 0),
          last_visit: textField(snapshotSource.last_visit, 60),
        }
      : null;

    // Helper for rendering fields
    const renderField = (label, val, icon = "") => {
      const isMissing = !val || val === "Not set" || val === "None recorded" || val === "—";
      const iconHtml = icon
        ? `<i class="fas ${icon} patient-profile-field__icon" aria-hidden="true"></i>`
        : "";
      return `
        <div class="profile-field-card patient-profile-field">
          <div class="patient-profile-field__label">
            ${iconHtml}<span>${escapeHTML(label)}</span>
          </div>
          <div class="patient-profile-field__value${isMissing ? " patient-profile-field__value--missing" : ""}">
            ${escapeHTML(String(val || "—"))}
          </div>
        </div>`;
    };

    container.innerHTML = `
      <!-- Header Banner / Patient Identity -->
      <section class="card patient-profile-identity">
        <div class="patient-profile-identity__layout">
          <div class="patient-profile-identity__avatar">
            ${escapeHTML(getInitials(profile.name))}
          </div>
          <div class="patient-profile-identity__content">
            <h2 class="patient-profile-identity__name">${escapeHTML(profile.name || "Patient")}</h2>
            <div class="patient-profile-identity__meta">
              <span><i class="fas fa-id-badge patient-profile-identity__meta-icon" aria-hidden="true"></i> Patient #${escapeHTML(profile.patient_number || "-")}</span>
              <span><i class="fas fa-calendar-alt patient-profile-identity__meta-icon" aria-hidden="true"></i> Member Since ${profile.registered_at ? escapeHTML(formatDate(profile.registered_at)) : "—"}</span>
              <span><i class="fas fa-envelope patient-profile-identity__meta-icon" aria-hidden="true"></i> ${escapeHTML(profile.email || "—")}</span>
            </div>
          </div>
        </div>
      </section>

      <!-- Activity Summary Cards -->
      <section class="patient-profile-section">
        <h3 class="patient-profile-section__title"><i class="fas fa-chart-pie patient-profile-section__title-icon" aria-hidden="true"></i> Activity Summary</h3>
        <div class="patient-profile-kpi-grid">
          <div class="kpi-card-compact patient-profile-kpi">
            <div class="patient-profile-kpi__icon patient-profile-kpi__icon--primary"><i class="fas fa-calendar-check"></i></div>
            <div><div class="patient-profile-kpi__value">${safeCount(snapshot?.total_appointments, 0)}</div><div class="patient-profile-kpi__label">Total Visits</div></div>
          </div>
          <div class="kpi-card-compact patient-profile-kpi">
            <div class="patient-profile-kpi__icon patient-profile-kpi__icon--success"><i class="fas fa-circle-check"></i></div>
            <div><div class="patient-profile-kpi__value">${safeCount(snapshot?.completed_appointments, 0)}</div><div class="patient-profile-kpi__label">Completed</div></div>
          </div>
          <div class="kpi-card-compact patient-profile-kpi">
            <div class="patient-profile-kpi__icon patient-profile-kpi__icon--warning"><i class="fas fa-prescription"></i></div>
            <div><div class="patient-profile-kpi__value">${safeCount(snapshot?.active_prescriptions, 0)}</div><div class="patient-profile-kpi__label">Active Rx</div></div>
          </div>
          <div class="kpi-card-compact patient-profile-kpi">
            <div class="patient-profile-kpi__icon patient-profile-kpi__icon--info"><i class="fas fa-user-md"></i></div>
            <div><div class="patient-profile-kpi__value">${safeCount(snapshot?.doctors_seen, 0)}</div><div class="patient-profile-kpi__label">Doctors Visited</div></div>
          </div>
        </div>
      </section>

      <!-- Main Profile Grid -->
      <div class="patient-profile-main-grid">
        
        <!-- Personal Information -->
        <section class="card patient-profile-panel">
          <h3 class="patient-profile-panel__title"><i class="fas fa-user patient-profile-panel__title-icon" aria-hidden="true"></i> Personal Information</h3>
          <div class="patient-profile-field-grid">
            ${renderField("Patient Number", profile.patient_number, "fa-hashtag")}
            ${renderField("National ID", profile.national_id, "fa-address-card")}
            ${renderField("Date of Birth", profile.date_of_birth ? formatDate(profile.date_of_birth) : "", "fa-cake-candles")}
            ${renderField("Age", profile.age ? profile.age + " yrs" : "", "fa-hourglass-half")}
            ${renderField("Gender", profile.gender, "fa-venus-mars")}
            ${renderField("Phone", profile.phone, "fa-phone")}
            ${renderField("Email", profile.email, "fa-envelope")}
            ${renderField("Location", profile.location?.city ? `${profile.location.city}, ${profile.location.governorate}` : "", "fa-location-dot")}
          </div>
        </section>

        <!-- Medical Details -->
        <section class="card patient-profile-panel">
          <h3 class="patient-profile-panel__title"><i class="fas fa-notes-medical patient-profile-panel__title-icon" aria-hidden="true"></i> Medical Vitals & History</h3>
          <div class="patient-profile-field-grid">
            ${renderField("Blood Type", profile.blood_type, "fa-droplet")}
            ${renderField("Height", profile.height_cm ? profile.height_cm + " cm" : "", "fa-ruler-vertical")}
            ${renderField("Weight", profile.weight_kg ? profile.weight_kg + " kg" : "", "fa-weight-scale")}
            ${renderField("BMI", profile.bmi !== null ? `${profile.bmi} (${getBMICategory(profile.bmi)})` : "", "fa-heart-pulse")}
            ${renderField("Known Allergies", profile.allergies || "None recorded", "fa-allergies")}
            ${renderField("Chronic Diseases", profile.chronic_diseases || "None recorded", "fa-disease")}
            ${renderField("Current Medications", profile.current_medications || "None recorded", "fa-pills")}
          </div>
        </section>

        <!-- Hospital & Doctor Info -->
        <section class="card patient-profile-panel">
          <h3 class="patient-profile-panel__title"><i class="fas fa-hospital-user patient-profile-panel__title-icon" aria-hidden="true"></i> Care & Hospital Info</h3>
          <div class="patient-profile-provider">
            <div class="patient-profile-provider__label">PRIMARY CARE PROVIDER</div>
            ${primaryDoc ? `
              <div class="patient-profile-provider__content">
                <div class="patient-profile-provider__avatar">${escapeHTML(getInitials(primaryDoc.name))}</div>
                <div>
                  <h4 class="patient-profile-provider__name">${escapeHTML(primaryDoc.name)}</h4>
                  <p class="patient-profile-provider__specialty">${escapeHTML(primaryDoc.specialty || primaryDoc.department || "")}</p>
                </div>
              </div>` : `<p class="patient-profile-provider__empty">No primary doctor assigned yet.</p>`}
          </div>
          <div class="patient-profile-two-column-grid">
            ${renderField("Last Visit", snapshot?.last_visit || "—", "fa-clock-rotate-left")}
            ${renderField("Next Appointment", nextAppt ? `${formatDate(nextAppt.date)}` : "None scheduled", "fa-calendar-plus")}
          </div>
        </section>

        <!-- Emergency Contact & Insurance -->
        <section class="card patient-profile-panel">
          <h3 class="patient-profile-panel__title"><i class="fas fa-shield-heart patient-profile-panel__title-icon" aria-hidden="true"></i> Contact & Coverage</h3>
          <div class="patient-profile-subsection">
            <h4 class="patient-profile-subsection__title">Emergency Contact</h4>
            <div class="patient-profile-field-grid">
              ${renderField("Contact Name", profile.emergency_contact?.name, "fa-user-shield")}
              ${renderField("Relationship", profile.emergency_contact?.relationship, "fa-people-arrows")}
              ${renderField("Phone", profile.emergency_contact?.phone, "fa-phone")}
            </div>
          </div>
          <div>
            <h4 class="patient-profile-subsection__title">Insurance Information</h4>
            <div class="patient-profile-field-grid">
              ${renderField("Insurance Provider", profile.insurance?.provider, "fa-building-shield")}
              ${renderField("Policy / Member #", profile.insurance?.number, "fa-id-card")}
            </div>
          </div>
        </section>

      </div>`;
  } else {
    container.innerHTML = `
      <div class="empty-state">
        <div class="empty-icon"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i></div>
        <h4>Unable to Load Profile</h4>
        <p>Please check your connection and try again.</p>
        <button class="btn btn-outline btn-sm" data-page-action="retry">Retry</button>
      </div>`;
  }
});

function getInitials(name) {
  const safeName = typeof name === "string" ? name.trim() : "";
  if (!safeName) return "P";
  return safeName.split(/\s+/).map(n => n[0]).join("").substring(0, 2).toUpperCase();
}

function getBMICategory(bmi) {
  if (bmi < 18.5) return "Underweight";
  if (bmi < 25) return "Normal";
  if (bmi < 30) return "Overweight";
  return "Obese";
}