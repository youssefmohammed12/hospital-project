/**
 * HealthBridge — Authentication Page JavaScript
 * Handles: login form, forgot password flow, and 2-step registration wizard.
 *
 * Used by: login.html, register.html
 */

"use strict";

document.addEventListener("DOMContentLoaded", () => {
  initLoginForm();
  initForgotPassword();
  initRegistrationWizard();

  // Auto-switch to signup tab if URL has #signup (backward compatibility)
  if (window.location.hash === "#signup") {
    const signupBtn = document.querySelector('[data-panel="signup"]');
    if (signupBtn) signupBtn.click();
  }
});

/* ============================================================
   LOGIN FORM
   ============================================================ */
function initLoginForm() {
  const loginForm = document.getElementById("login-form");
  if (!loginForm) return;

  loginForm.addEventListener("submit", async (e) => {
    e.preventDefault();

    const submitBtn = document.getElementById("login-submit");
    const email = loginForm.querySelector('[name="email"]')?.value.trim() || "";
    const password = loginForm.querySelector('[name="password"]')?.value || "";
    const rememberMe = document.getElementById("remember-me")?.checked || false;

    // Clear previous errors
    clearFieldError("login-email-error");
    clearFieldError("login-password-error");

    // Client-side validation
    let hasError = false;

    if (!email) {
      showFieldError("login-email-error", "Email address is required.");
      hasError = true;
    } else if (!isValidEmail(email)) {
      showFieldError("login-email-error", "Please enter a valid email address.");
      hasError = true;
    }

    if (!password) {
      showFieldError("login-password-error", "Password is required.");
      hasError = true;
    }

    if (hasError) return;

    // Show loading state
    submitBtn.classList.add("loading");
    submitBtn.disabled = true;

    try {
      const res = await fetch(getBasePath() + "api/auth/login.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ email, password }),
      });
      const result = await res.json();

      if (!result.success) {
        showToast(result.message || "Login failed.", "error");
        showFieldError("login-password-error", result.message || "Invalid credentials.");
        submitBtn.classList.remove("loading");
        submitBtn.disabled = false;
        return;
      }

      // Save user data
      saveUser(result.user);

      // Handle "Remember me" - extend session persistence
      if (rememberMe) {
        // The server-side session already handles this; we just save the preference
        localStorage.setItem("hb_remember", "true");
      } else {
        localStorage.removeItem("hb_remember");
      }

      showToast(`Welcome back, ${result.user.name}!`, "success");

      // Animate success
      submitBtn.classList.remove("loading");
      submitBtn.classList.add("success");
      submitBtn.innerHTML = '<i class="fas fa-check" aria-hidden="true"></i>';

      setTimeout(() => {
        window.location.href = getDashboardUrl(result.user.role);
      }, 800);
    } catch {
      showToast("Login service unavailable. Please ensure XAMPP is running.", "error");
      submitBtn.classList.remove("loading");
      submitBtn.disabled = false;
    }
  });
}

/* ============================================================
   FORGOT PASSWORD FLOW
   ============================================================ */
function initForgotPassword() {
  const mainAuth = document.getElementById("main-auth");
  const forgotPanel = document.getElementById("panel-forgot");
  const openBtn = document.getElementById("open-forgot");
  const backBtn = document.getElementById("back-to-login");
  const forgotForm = document.getElementById("forgot-form");
  const resultWrap = document.getElementById("forgot-result");
  const resultMsg = document.getElementById("forgot-result-msg");
  const resetLink = document.getElementById("forgot-reset-link");

  if (!openBtn || !forgotPanel) return;

  // Show forgot panel
  openBtn.addEventListener("click", () => {
    if (mainAuth) mainAuth.style.display = "none";
    forgotPanel.style.display = "block";
    resultWrap.style.display = "none";
    forgotForm.style.display = "";
    forgotForm.reset();
  });

  // Back to login
  backBtn?.addEventListener("click", () => {
    forgotPanel.style.display = "none";
    if (mainAuth) mainAuth.style.display = "";
  });

  // Forgot form submission
  forgotForm?.addEventListener("submit", async (e) => {
    e.preventDefault();
    const submitBtn = document.getElementById("forgot-submit");
    const email = forgotForm.querySelector('[name="email"]')?.value.trim() || "";

    if (!email) {
      showToast("Please enter your email address.", "error");
      return;
    }

    submitBtn.classList.add("loading");
    submitBtn.disabled = true;

    try {
      const res = await fetch(getBasePath() + "api/auth/forgot_password.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ email }),
      });
      const result = await res.json();

      if (!result.success) {
        showToast(result.message || "Something went wrong.", "error");
        submitBtn.classList.remove("loading");
        submitBtn.disabled = false;
        return;
      }

      // Hide form, show result
      forgotForm.style.display = "none";
      resultMsg.textContent = result.message;

      if (result.reset_url) {
        resetLink.href = result.reset_url;
        resetLink.style.display = "";
      } else {
        resetLink.style.display = "none";
      }

      resultWrap.style.display = "block";
      submitBtn.classList.remove("loading");
      submitBtn.disabled = false;
    } catch {
      showToast("Unable to reach server. Is XAMPP running?", "error");
      submitBtn.classList.remove("loading");
      submitBtn.disabled = false;
    }
  });
}

/* ============================================================
   REGISTRATION WIZARD
   ============================================================ */
function initRegistrationWizard() {
  const step1 = document.getElementById("wizard-step-1");
  const step2 = document.getElementById("wizard-step-2");
  const successScreen = document.getElementById("reg-success");

  if (!step1) return; // Not on registration page

  // Step 1 → Next
  const step1Next = document.getElementById("step1-next");
  step1Next?.addEventListener("click", () => handleStep1Submit());

  // Allow Enter key on step 1 to trigger next
  const step1Form = document.getElementById("step1-form");
  step1Form?.addEventListener("keydown", (e) => {
    if (e.key === "Enter") {
      e.preventDefault();
      handleStep1Submit();
    }
  });

  // Step 2 → Previous
  document.getElementById("step2-prev")?.addEventListener("click", () => goToStep(1));

  // Step 2 → Submit
  const step2Submit = document.getElementById("step2-submit");
  step2Submit?.addEventListener("click", () => handleStep2Submit());

  // Allow Enter key on step 2 to trigger submit
  const step2Form = document.getElementById("step2-form");
  step2Form?.addEventListener("keydown", (e) => {
    if (e.key === "Enter") {
      e.preventDefault();
      handleStep2Submit();
    }
  });

  // ── Live validation: Password strength ──
  const pwInput = document.getElementById("reg-password");
  pwInput?.addEventListener("input", () => updatePasswordStrength(pwInput.value));

  // ── Live validation: Confirm password ──
  const confirmInput = document.getElementById("reg-confirm-password");
  confirmInput?.addEventListener("input", () => {
    const errorEl = document.getElementById("confirm-password-error");
    if (confirmInput.value && pwInput?.value && confirmInput.value !== pwInput.value) {
      showFieldError("confirm-password-error", "Passwords do not match.");
    } else {
      clearFieldError("confirm-password-error");
    }
  });

  // ── Live validation: Email uniqueness (debounced) ──
  const emailInput = document.getElementById("reg-email");
  emailInput?.addEventListener("input", debounce(async () => {
    const email = emailInput.value.trim();
    if (!email || !isValidEmail(email)) return;
    await checkAvailability("email", email, "email-error", "email-success");
  }, 500));

  // ── Live validation: Phone format + uniqueness (debounced) ──
  const phoneInput = document.getElementById("reg-phone");
  phoneInput?.addEventListener("input", debounce(async () => {
    const phone = phoneInput.value.trim();
    if (!phone) {
      clearFieldError("phone-error");
      clearFieldSuccess("phone-success");
      return;
    }
    const cleanPhone = phone.replace(/\s/g, "");
    // Format validation first
    if (!/^01[0125]\d{8}$/.test(cleanPhone)) {
      if (phone.length >= 11) {
        showFieldError("phone-error", "Please enter a valid Egyptian phone number.");
      } else {
        clearFieldError("phone-error");
      }
      clearFieldSuccess("phone-success");
      return;
    }
    // Format is valid — check uniqueness
    clearFieldError("phone-error");
    const result = await checkAvailability("phone", cleanPhone, "phone-error", "phone-success");
  }, 500));

  // ── Live validation: Emergency contact phone format ──
  const emPhoneInput = document.getElementById("reg-emergency-phone");
  emPhoneInput?.addEventListener("input", () => {
    const phone = emPhoneInput.value.trim();
    if (!phone) {
      clearFieldError("emergency-phone-error");
      clearFieldSuccess("emergency-phone-success");
      return;
    }
    const cleanPhone = phone.replace(/\s/g, "");
    if (/^01[0125]\d{8}$/.test(cleanPhone)) {
      clearFieldError("emergency-phone-error");
      showFieldSuccess("emergency-phone-success", "Valid phone number");
    } else if (phone.length >= 11) {
      showFieldError("emergency-phone-error", "Please enter a valid Egyptian phone number.");
      clearFieldSuccess("emergency-phone-success");
    } else {
      clearFieldError("emergency-phone-error");
      clearFieldSuccess("emergency-phone-success");
    }
  });

  // ── Live validation: National ID format ──
  const nidInput = document.getElementById("reg-national-id");
  nidInput?.addEventListener("input", debounce(async () => {
    const nid = nidInput.value.trim();
    if (!nid) {
      clearFieldError("national-id-error");
      clearFieldSuccess("national-id-success");
      return;
    }
    if (nid.length === 14 && /^\d{14}$/.test(nid)) {
      // Check availability
      await checkAvailability("national_id", nid, "national-id-error", "national-id-success");
    } else if (nid.length >= 14) {
      showFieldError("national-id-error", "National ID must be exactly 14 digits.");
      clearFieldSuccess("national-id-success");
    } else {
      clearFieldError("national-id-error");
      clearFieldSuccess("national-id-success");
    }
  }, 500));

  // ── Load governorates ──
  loadGovernorates();

  // ── Governorate change → load cities ──
  document.getElementById("reg-governorate")?.addEventListener("change", (e) => {
    const govId = e.target.value;
    loadCities(govId);
  });
}

/* ── Step Navigation ─────────────────────────────────────── */
function goToStep(step) {
  const step1 = document.getElementById("wizard-step-1");
  const step2 = document.getElementById("wizard-step-2");

  // Update step visibility
  step1?.classList.toggle("active", step === 1);
  step2?.classList.toggle("active", step === 2);

  // Update progress indicators
  const ind1 = document.getElementById("step-indicator-1");
  const ind2 = document.getElementById("step-indicator-2");
  const label1 = document.getElementById("step-label-1");
  const label2 = document.getElementById("step-label-2");
  const conn1 = document.getElementById("connector-1");

  if (step === 1) {
    ind1?.classList.add("active");
    ind1?.classList.remove("completed");
    ind2?.classList.remove("active", "completed");
    label1?.classList.add("active");
    label1?.classList.remove("completed");
    label2?.classList.remove("active", "completed");
    conn1?.classList.remove("completed");
  } else {
    ind1?.classList.remove("active");
    ind1?.classList.add("completed");
    ind2?.classList.add("active");
    ind2?.classList.remove("completed");
    label1?.classList.remove("active");
    label1?.classList.add("completed");
    label2?.classList.add("active");
    label2?.classList.remove("completed");
    conn1?.classList.add("completed");
  }

  // Scroll to top of form panel
  const panel = document.querySelector(".auth-form-panel");
  if (panel) panel.scrollTop = 0;
}

/* ── Step 1: Submit Account Info ──────────────────────────── */
let step1Data = {}; // Store step 1 data locally for step 2

async function handleStep1Submit() {
  const firstName = document.getElementById("reg-first-name")?.value.trim() || "";
  const lastName = document.getElementById("reg-last-name")?.value.trim() || "";
  const email = document.getElementById("reg-email")?.value.trim() || "";
  const phone = document.getElementById("reg-phone")?.value.trim() || "";
  const password = document.getElementById("reg-password")?.value || "";
  const confirmPassword = document.getElementById("reg-confirm-password")?.value || "";

  // Clear all errors
  document.querySelectorAll("#step1-form .field-error").forEach((el) => {
    el.classList.remove("show");
    el.textContent = "";
  });

  let hasError = false;

  // Validate first name
  if (!firstName || firstName.length < 2) {
    showFieldError("first-name-error", "First name must be at least 2 characters.");
    hasError = true;
  }

  // Validate last name
  if (!lastName || lastName.length < 2) {
    showFieldError("last-name-error", "Last name must be at least 2 characters.");
    hasError = true;
  }

  // Validate email
  if (!email) {
    showFieldError("email-error", "Email address is required.");
    hasError = true;
  } else if (!isValidEmail(email)) {
    showFieldError("email-error", "Please enter a valid email address.");
    hasError = true;
  }

  // Validate phone
  if (!phone) {
    showFieldError("phone-error", "Phone number is required.");
    hasError = true;
  } else if (!/^01[0125]\d{8}$/.test(phone.replace(/\s/g, ""))) {
    showFieldError("phone-error", "Please enter a valid Egyptian phone number.");
    hasError = true;
  }

  // Validate password
  const pwChecks = checkPasswordStrength(password);
  if (!pwChecks.valid) {
    showFieldError("password-error", "Password does not meet all requirements.");
    hasError = true;
  }

  // Validate confirm password
  if (!confirmPassword) {
    showFieldError("confirm-password-error", "Please confirm your password.");
    hasError = true;
  } else if (password !== confirmPassword) {
    showFieldError("confirm-password-error", "Passwords do not match.");
    hasError = true;
  }

  if (hasError) return;

  // Show loading
  const nextBtn = document.getElementById("step1-next");
  nextBtn.classList.add("loading");
  nextBtn.disabled = true;

  try {
    const res = await fetch(getBasePath() + "api/auth/register.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        step: 1,
        first_name: firstName,
        last_name: lastName,
        email,
        phone,
        password,
        confirm_password: confirmPassword,
      }),
    });
    const result = await res.json();

    nextBtn.classList.remove("loading");
    nextBtn.disabled = false;

    if (!result.success) {
      showToast(result.message || "Validation failed.", "error");
      // Show server errors on fields if available
      if (result.errors && Array.isArray(result.errors)) {
        // Map generic errors to fields
        const msg = result.errors.join(". ");
        showToast(msg, "error");
      }
      return;
    }

    // Store ALL step 1 data locally on the client (no user_id from server)
    step1Data = {
      first_name: firstName,
      last_name: lastName,
      email,
      phone,
      password,
      confirm_password: confirmPassword,
    };

    // Move to step 2
    goToStep(2);
    showToast(result.message, "success");
  } catch {
    showToast("Registration service unavailable. Please ensure XAMPP is running.", "error");
    nextBtn.classList.remove("loading");
    nextBtn.disabled = false;
  }
}

/* ── Step 2: Submit Patient Info ──────────────────────────── */
async function handleStep2Submit() {
  if (!step1Data.first_name || !step1Data.email) {
    showToast("Session expired. Please start registration again.", "error");
    goToStep(1);
    return;
  }

  const dob = document.getElementById("reg-dob")?.value || "";
  const gender = document.getElementById("reg-gender")?.value || "";
  const bloodType = document.getElementById("reg-blood-type")?.value || "";
  const nationalId = document.getElementById("reg-national-id")?.value.trim() || "";
  const governorate = document.getElementById("reg-governorate")?.value || "";
  const city = document.getElementById("reg-city")?.value || "";
  const address = document.getElementById("reg-address")?.value.trim() || "";
  const emergencyName = document.getElementById("reg-emergency-name")?.value.trim() || "";
  const emergencyPhone = document.getElementById("reg-emergency-phone")?.value.trim() || "";
  const emergencyRel = document.getElementById("reg-emergency-rel")?.value || "";
  const allergies = document.getElementById("reg-allergies")?.value.trim() || "";
  const chronic = document.getElementById("reg-chronic")?.value.trim() || "";
  const medications = document.getElementById("reg-medications")?.value.trim() || "";
  const insuranceProvider = document.getElementById("reg-insurance-provider")?.value.trim() || "";
  const insuranceNumber = document.getElementById("reg-insurance-number")?.value.trim() || "";

  // Clear all errors
  document.querySelectorAll("#step2-form .field-error").forEach((el) => {
    el.classList.remove("show");
    el.textContent = "";
  });

  let hasError = false;

  // Validate DOB
  if (!dob) {
    showFieldError("dob-error", "Date of birth is required.");
    hasError = true;
  }

  // Validate gender
  if (!gender) {
    showFieldError("gender-error", "Gender is required.");
    hasError = true;
  }

  // Validate National ID
  if (!nationalId) {
    showFieldError("national-id-error", "National ID is required.");
    hasError = true;
  } else if (!/^\d{14}$/.test(nationalId)) {
    showFieldError("national-id-error", "National ID must be exactly 14 digits.");
    hasError = true;
  }

  // Validate governorate
  if (!governorate) {
    showFieldError("governorate-error", "Governorate is required.");
    hasError = true;
  }

  // Validate city
  if (!city) {
    showFieldError("city-error", "City is required.");
    hasError = true;
  }

  // Validate address
  if (!address) {
    showFieldError("address-error", "Full address is required.");
    hasError = true;
  }

  // Validate emergency contact
  if (!emergencyName) {
    showFieldError("emergency-name-error", "Emergency contact name is required.");
    hasError = true;
  }

  if (!emergencyPhone) {
    showFieldError("emergency-phone-error", "Emergency contact phone is required.");
    hasError = true;
  } else if (!/^01[0125]\d{8}$/.test(emergencyPhone.replace(/\s/g, ""))) {
    showFieldError("emergency-phone-error", "Please enter a valid Egyptian phone number.");
    hasError = true;
  }

  if (!emergencyRel) {
    showFieldError("emergency-rel-error", "Relationship is required.");
    hasError = true;
  }

  if (hasError) return;

  // Show loading
  const submitBtn = document.getElementById("step2-submit");
  submitBtn.classList.add("loading");
  submitBtn.disabled = true;

  try {
    // Submit ALL data (Step 1 + Step 2) in a single request
    const res = await fetch(getBasePath() + "api/auth/register.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        step: 2,
        first_name: step1Data.first_name,
        last_name: step1Data.last_name,
        email: step1Data.email,
        phone: step1Data.phone,
        password: step1Data.password,
        confirm_password: step1Data.confirm_password,
        national_id: nationalId,
        date_of_birth: dob,
        gender,
        blood_type: bloodType,
        governorate,
        city,
        address,
        emergency_contact_name: emergencyName,
        emergency_contact_phone: emergencyPhone,
        emergency_contact_rel: emergencyRel,
        allergies,
        chronic_diseases: chronic,
        current_medications: medications,
        insurance_provider: insuranceProvider,
        insurance_number: insuranceNumber,
      }),
    });
    const result = await res.json();

    submitBtn.classList.remove("loading");
    submitBtn.disabled = false;

    if (!result.success) {
      showToast(result.message || "Registration failed.", "error");
      return;
    }

    // Clear stored step 1 data
    step1Data = {};

    // Show success screen
    showSuccessScreen(result.patient_number);
  } catch {
    showToast("Registration service unavailable. Please ensure XAMPP is running.", "error");
    submitBtn.classList.remove("loading");
    submitBtn.disabled = false;
  }
}

/* ── Success Screen ───────────────────────────────────────── */
function showSuccessScreen(patientNumber) {
  // Hide wizard steps
  const ws1 = document.getElementById("wizard-step-1");
  if (ws1) ws1.classList.remove("active");
  const ws2 = document.getElementById("wizard-step-2");
  if (ws2) ws2.classList.remove("active");
  const wp = document.getElementById("wizard-progress");
  if (wp) wp.style.display = "none";
  const afh = document.querySelector(".auth-form-header");
  if (afh) afh.style.display = "none";

  // Show success
  const successEl = document.getElementById("reg-success");
  const numberDisplay = document.getElementById("patient-number-display");
  if (numberDisplay) numberDisplay.textContent = patientNumber;
  if (successEl) successEl.classList.add("show");

  // Auto-redirect after 5 seconds
  setTimeout(() => {
    window.location.href = getBasePath() + "pages/auth/login.html";
  }, 5000);
}

/* ============================================================
   PASSWORD STRENGTH
   ============================================================ */
function updatePasswordStrength(password) {
  const checks = checkPasswordStrength(password);
  const fill = document.getElementById("pw-strength-fill");
  const label = document.getElementById("pw-strength-label");
  const checklist = document.getElementById("pw-checklist");

  // Update strength bar
  if (fill) {
    fill.className = "pw-strength-fill";
    if (password.length > 0) {
      fill.classList.add(checks.strength);
    }
  }

  // Update label
  if (label) {
    const labels = {
      weak: "Weak",
      medium: "Medium",
      strong: "Strong",
      very_strong: "Very Strong",
    };
    label.textContent = password.length > 0 ? labels[checks.strength] || "" : "";
  }

  // Update checklist
  if (checklist) {
    checklist.classList.toggle("show", password.length > 0);
    const items = checklist.querySelectorAll(".pw-check-item");
    items.forEach((item) => {
      const check = item.dataset.check;
      item.classList.toggle("met", checks.checks[check] || false);
    });
  }
}

function checkPasswordStrength(password) {
  const checks = {
    min_length: password.length >= 8,
    has_uppercase: /[A-Z]/.test(password),
    has_lowercase: /[a-z]/.test(password),
    has_number: /[0-9]/.test(password),
    has_special: /[^A-Za-z0-9]/.test(password),
  };

  const score = Object.values(checks).filter(Boolean).length;

  let strength = "weak";
  if (score >= 5) strength = "very_strong";
  else if (score >= 4) strength = "strong";
  else if (score >= 3) strength = "medium";

  return {
    valid: score >= 4,
    strength,
    checks,
  };
}

/* ============================================================
   AVAILABILITY CHECK
   ============================================================ */
async function checkAvailability(field, value, errorId, successId) {
  try {
    const res = await fetch(getBasePath() + "api/auth/check-availability.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ field, value }),
    });
    const result = await res.json();

    if (!result.success) {
      clearFieldError(errorId);
      clearFieldSuccess(successId);
      return;
    }

    if (result.available) {
      clearFieldError(errorId);
      showFieldSuccess(successId, result.message);
    } else {
      clearFieldSuccess(successId);
      showFieldError(errorId, result.message);
    }
  } catch {
    // Silently fail - don't block registration
    clearFieldError(errorId);
    clearFieldSuccess(successId);
  }
}

/* ============================================================
   LOCATIONS (Governorates & Cities)
   ============================================================ */
async function loadGovernorates() {
  const select = document.getElementById("reg-governorate");
  if (!select) return;

  try {
    const res = await fetch(getBasePath() + "api/auth/locations.php?type=governorates");
    const result = await res.json();

    if (!result.success || !result.governorates) return;

    result.governorates.forEach((gov) => {
      const option = document.createElement("option");
      option.value = gov.name;
      option.textContent = gov.name;
      select.appendChild(option);
    });
  } catch {
    // Fallback: show hardcoded governorates
    const fallback = [
      "Alexandria", "Aswan", "Asyut", "Beheira", "Beni Suef", "Cairo",
      "Dakahlia", "Damietta", "Faiyum", "Gharbia", "Giza", "Ismailia",
      "Kafr El Sheikh", "Luxor", "Matrouh", "Minya", "Monufia", "New Valley",
      "North Sinai", "Port Said", "Qalyubia", "Qena", "Red Sea", "Sharqia",
      "Sohag", "South Sinai", "Suez",
    ];
    fallback.forEach((name) => {
      const option = document.createElement("option");
      option.value = name;
      option.textContent = name;
      select.appendChild(option);
    });
  }
}

async function loadCities(governorateName) {
  const citySelect = document.getElementById("reg-city");
  if (!citySelect) return;

  // Clear existing options
  citySelect.innerHTML = '<option value="">Select city</option>';
  citySelect.disabled = true;

  if (!governorateName) return;

  // Try to find governorate ID from the select options
  const govSelect = document.getElementById("reg-governorate");
  const selectedOption = Array.from(govSelect?.options || []).find(
    (opt) => opt.value === governorateName
  );

  // We need the governorate ID, so we'll fetch cities by name
  try {
    // First get governorates to find the ID
    const govRes = await fetch(getBasePath() + "api/auth/locations.php?type=governorates");
    const govResult = await govRes.json();
    if (!govResult.success) return;

    const gov = govResult.governorates.find((g) => g.name === governorateName);
    if (!gov) return;

    const res = await fetch(
      getBasePath() + `api/auth/locations.php?type=cities&governorate_id=${gov.id}`
    );
    const result = await res.json();

    if (!result.success || !result.cities) return;

    result.cities.forEach((city) => {
      const option = document.createElement("option");
      option.value = city.name;
      option.textContent = city.name;
      citySelect.appendChild(option);
    });

    citySelect.disabled = false;
  } catch {
    // Fallback: enable free text entry
    citySelect.disabled = false;
    citySelect.innerHTML = '<option value="">Type your city</option>';
    // Convert to text input as fallback
    const input = document.createElement("input");
    input.type = "text";
    input.id = "reg-city";
    input.name = "city";
    input.placeholder = "Enter your city";
    input.required = true;
    input.setAttribute("aria-required", "true");
    citySelect.parentNode.replaceChild(input, citySelect);
  }
}

/* ============================================================
   FIELD ERROR / SUCCESS HELPERS
   ============================================================ */
function showFieldError(elementId, message) {
  const el = document.getElementById(elementId);
  if (!el) return;
  el.textContent = message;
  el.classList.add("show");

  // Also mark the associated input
  const group = el.closest(".form-group");
  if (group) {
    const input = group.querySelector("input, select, textarea");
    if (input) {
      input.classList.add("error");
      input.classList.remove("success");
    }
  }
}

function clearFieldError(elementId) {
  const el = document.getElementById(elementId);
  if (!el) return;
  el.classList.remove("show");
  el.textContent = "";

  const group = el.closest(".form-group");
  if (group) {
    const input = group.querySelector("input, select, textarea");
    if (input) input.classList.remove("error");
  }
}

function showFieldSuccess(elementId, message) {
  const el = document.getElementById(elementId);
  if (!el) return;
  el.textContent = message;
  el.classList.add("show");

  const group = el.closest(".form-group");
  if (group) {
    const input = group.querySelector("input, select, textarea");
    if (input) {
      input.classList.add("success");
      input.classList.remove("error");
    }
  }
}

function clearFieldSuccess(elementId) {
  const el = document.getElementById(elementId);
  if (!el) return;
  el.classList.remove("show");
  el.textContent = "";

  const group = el.closest(".form-group");
  if (group) {
    const input = group.querySelector("input, select, textarea");
    if (input) input.classList.remove("success");
  }
}

/* ============================================================
   UTILITY HELPERS
   ============================================================ */
function isValidEmail(email) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
}

/* ── QUICK LOGIN FOR DEMO ACCOUNTS ─────────────────────────── */
window.fillDemo = function (email, password) {
  const emailInput = document.getElementById("login-email");
  const passwordInput = document.getElementById("login-password");
  const form = document.getElementById("login-form");
  if (emailInput && passwordInput && form) {
    emailInput.value = email;
    passwordInput.value = password;
    if (typeof form.requestSubmit === "function") {
      form.requestSubmit();
    } else {
      const submitEvent = new Event("submit", { cancelable: true, bubbles: true });
      form.dispatchEvent(submitEvent);
    }
  }
};