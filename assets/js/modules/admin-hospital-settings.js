/**
 * HealthBridge — Admin Hospital Settings Enhancement
 * Extends hospital settings functionality to include hospital information fields.
 *
 * Used by: admin.html
 */

"use strict";

// Override the loadHospitalSettings function to include hospital info fields
const originalLoadHospitalSettings = window.loadHospitalSettings;

window.loadHospitalSettings = async function() {
  const openSelect = document.getElementById("hospital-open-time");
  const closeSelect = document.getElementById("hospital-close-time");
  const durationSelect = document.getElementById("hospital-default-duration");
  const saveBtn = document.getElementById("hospital-settings-save-btn");

  // Hospital info fields
  const nameInput = document.getElementById("hospital-name");
  const phoneInput = document.getElementById("hospital-phone");
  const emailInput = document.getElementById("hospital-email");
  const addressInput = document.getElementById("hospital-address");
  const descInput = document.getElementById("hospital-description");

  if (!openSelect || !closeSelect) return;

  // Populate time dropdowns once
  if (openSelect.options.length === 0) {
    const timeOptions = generateFullTimeOptions();
    timeOptions.forEach((t) => {
      openSelect.add(new Option(t.label, t.value));
      closeSelect.add(new Option(t.label, t.value));
    });
  }

  setLoading(saveBtn, true, "Loading...");

  const result = await apiFetch(
    (getBasePath() + "api/settings/get-hospital.php"),
    {},
    "Failed to load settings.",
  );

  setLoading(saveBtn, false, "Save Settings");

  if (!result.ok || !result.data?.success) {
    showToast(result.data?.message || "Failed to load settings.", "error");
    return;
  }

  const settings = result.data.settings;
  if (settings) {
    openSelect.value = settings.appointment_open_time || "08:00";
    closeSelect.value = settings.appointment_close_time || "22:00";
    if (durationSelect) {
      durationSelect.value = settings.default_appointment_duration || "30";
    }
    
    // Load hospital info fields
    if (nameInput) nameInput.value = settings.hospital_name || "";
    if (phoneInput) phoneInput.value = settings.hospital_phone || "";
    if (emailInput) emailInput.value = settings.hospital_email || "";
    if (addressInput) addressInput.value = settings.hospital_address || "";
    if (descInput) descInput.value = settings.hospital_description || "";
  }
};

// Override the saveHospitalSettings function to include hospital info fields
const originalSaveHospitalSettings = window.saveHospitalSettings;

window.saveHospitalSettings = async function() {
  const openSelect = document.getElementById("hospital-open-time");
  const closeSelect = document.getElementById("hospital-close-time");
  const durationSelect = document.getElementById("hospital-default-duration");
  const saveBtn = document.getElementById("hospital-settings-save-btn");

  // Hospital info fields
  const nameInput = document.getElementById("hospital-name");
  const phoneInput = document.getElementById("hospital-phone");
  const emailInput = document.getElementById("hospital-email");
  const addressInput = document.getElementById("hospital-address");
  const descInput = document.getElementById("hospital-description");

  if (!openSelect || !closeSelect || !durationSelect) {
    showToast("Settings form not found.", "error");
    return;
  }

  const openTime = openSelect.value;
  const closeTime = closeSelect.value;
  const duration = parseInt(durationSelect.value, 10);

  if (openTime >= closeTime) {
    showToast("Opening time must be before closing time.", "error");
    return;
  }

  // Validate hospital info
  if (emailInput.value && !validateEmail(emailInput.value)) {
    showToast("Please enter a valid email address.", "error");
    return;
  }

  setLoading(saveBtn, true, "Saving...");

  const result = await apiFetch(
    (getBasePath() + "api/settings/update-hospital.php"),
    {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        appointment_open_time: openTime,
        appointment_close_time: closeTime,
        default_appointment_duration: duration,
        hospital_name: nameInput.value.trim(),
        hospital_phone: phoneInput.value.trim(),
        hospital_email: emailInput.value.trim(),
        hospital_address: addressInput.value.trim(),
        hospital_description: descInput.value.trim(),
      }),
    },
    "Failed to save settings.",
  );

  setLoading(saveBtn, false, "Save Settings");

  if (result.data?.success) {
    showToast("Hospital settings updated successfully!", "success");
  } else if (result.data?.requires_confirmation) {
    // Conflict detected - show confirmation dialog
    const confirmed = confirm(
      `${result.data.message}\n\nAffected schedules: ${result.data.conflict_count}\n\nDo you want to proceed with the change?`
    );
    if (confirmed) {
      // Save with confirmation flag
      setLoading(saveBtn, true, "Saving...");
      const confirmResult = await apiFetch(
        (getBasePath() + "api/settings/update-hospital.php"),
        {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            appointment_open_time: openTime,
            appointment_close_time: closeTime,
            default_appointment_duration: duration,
            hospital_name: nameInput.value.trim(),
            hospital_phone: phoneInput.value.trim(),
            hospital_email: emailInput.value.trim(),
            hospital_address: addressInput.value.trim(),
            hospital_description: descInput.value.trim(),
            confirmed: true,
          }),
        },
        "Failed to save settings.",
      );
      setLoading(saveBtn, false, "Save Settings");
      
      if (confirmResult.data?.success) {
        showToast("Hospital settings updated successfully!", "success");
      } else {
        showToast(confirmResult.data?.message || "Failed to save settings.", "error");
      }
    }
  } else {
    showToast(result.data?.message || "Failed to save settings.", "error");
  }
};

function validateEmail(email) {
  const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  return re.test(email);
}

