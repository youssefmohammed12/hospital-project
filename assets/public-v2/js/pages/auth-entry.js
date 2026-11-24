/**
 * HealthBridge — Authentication Page JavaScript
 * Handles: login form, forgot password flow, and 3-stage registration wizard.
 *
 * Used by: login.html, register.html
 */

"use strict";


/* Public V2 auth helpers: no legacy main.js dependency. */
function saveUser(user) {
  localStorage.setItem("hb_user", JSON.stringify(user));
}

function safeSameOriginUrl(value, fallback = "") {
  try {
    if (typeof value !== "string") return fallback;
    const candidate = value.trim();
    if (!candidate) return fallback;
    const url = new URL(candidate, window.location.href);
    if (!["http:", "https:"].includes(url.protocol)) return fallback;
    if (url.username || url.password || url.origin !== window.location.origin) return fallback;
    return url.pathname + url.search + url.hash;
  } catch {
    return fallback;
  }
}

function initAuthPasswordToggles() {
  if (window._hbPublicAuthPasswordBound) return;
  document.addEventListener("click", (event) => {
    const target = event.target instanceof Element ? event.target : null;
    const button = target?.closest("[data-password-toggle]");
    if (!button) return;
    const input = button.closest(".pw-field")?.querySelector("input");
    if (!input) return;
    const visible = input.type === "password";
    input.type = visible ? "text" : "password";
    const label = visible ? "Hide password" : "Show password";
    button.setAttribute("aria-label", label);
    button.setAttribute("title", label);
  });
  window._hbPublicAuthPasswordBound = true;
}


document.addEventListener("DOMContentLoaded", () => {
  initAuthPasswordToggles();
  bindAuthDemoAccounts();
  initLoginForm();
  initLoginVerificationResend();
  initForgotPassword();
  initPatientCardPreview();
  initRegisterFocusLines();
  initRegistrationWizard();
  initRegistrationVerificationResend();

  // Auto-switch to signup tab if URL has #signup (backward compatibility)
  if (window.location.hash === "#signup") {
    const signupBtn = document.querySelector('[data-panel="signup"]');
    if (signupBtn) signupBtn.click();
  }
});

/**
 * Demo shortcuts map fixed role identifiers to fixed local demo credentials.
 * No executable handler strings or credentials are stored in markup.
 */
function bindAuthDemoAccounts() {
  if (window._hbAuthDemoActionsBound) return;

  const demoAccounts = Object.freeze({
    admin: "admin@healthbridge.com",
    patient: "patient@healthbridge.com",
    doctor: "ahmed.hassan@healthbridge.com",
  });

  document.addEventListener("click", (event) => {
    const target = event.target instanceof Element ? event.target : null;
    const button = target?.closest("[data-demo-account]");
    if (!button) return;

    event.preventDefault();
    const email = demoAccounts[button.dataset.demoAccount];
    if (email && typeof window.fillDemo === "function") {
      window.fillDemo(email, "password");
    }
  });

  window._hbAuthDemoActionsBound = true;
}

/* ============================================================
   LOGIN FORM
   ============================================================ */
function initLoginForm() {
  const loginForm = document.getElementById("login-form");
  if (!loginForm) return;

  const emailInput = loginForm.querySelector('[name="email"]');
  const rememberMeInput = document.getElementById("remember-me");

  // Remember only the email address, never the password or server session.
  try {
    const rememberedEmail = localStorage.getItem("hb_remember_email");
    if (rememberedEmail && emailInput && !emailInput.value) {
      emailInput.value = rememberedEmail;
      if (rememberMeInput) rememberMeInput.checked = true;
    }
    localStorage.removeItem("hb_remember");
  } catch {
    // Authentication remains available when browser storage is disabled.
  }

  loginForm.addEventListener("submit", async (e) => {
    e.preventDefault();

    const submitBtn = document.getElementById("login-submit");
    const email = emailInput?.value.trim() || "";
    const password = loginForm.querySelector('[name="password"]')?.value || "";
    const rememberMe = rememberMeInput?.checked || false;

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
        if (result.code === "EMAIL_VERIFICATION_REQUIRED") {
          showToast(result.message || "Please verify your email before signing in.", "error");
          showFieldError("login-password-error", result.message || "Email verification is required.");

          const resendPanel = document.getElementById("login-verification-resend");
          const resendEmail = document.getElementById("login-verification-resend-email");
          if (resendPanel) resendPanel.classList.remove("is-hidden");
          if (resendEmail && email) resendEmail.value = email;
        } else {
          showToast(result.message || "Login failed.", "error");
          showFieldError("login-password-error", result.message || "Invalid credentials.");
        }
        submitBtn.classList.remove("loading");
        submitBtn.disabled = false;
        return;
      }

      // Save user data
      saveUser(result.user);

      // Remember only the email address when explicitly selected.
      try {
        if (rememberMe) {
          localStorage.setItem("hb_remember_email", email);
        } else {
          localStorage.removeItem("hb_remember_email");
        }
      } catch {
        // The server-side session does not depend on browser storage.
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
    if (mainAuth) mainAuth.classList.add("is-hidden");
    forgotPanel.classList.remove("is-hidden");
    resultWrap.classList.add("is-hidden");
    forgotForm.classList.remove("is-hidden");
    forgotForm.reset();
  });

  // Back to login
  backBtn?.addEventListener("click", () => {
    forgotPanel.classList.add("is-hidden");
    if (mainAuth) mainAuth.classList.remove("is-hidden");
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
      forgotForm.classList.add("is-hidden");
      resultMsg.textContent = result.message;

      const safeResetUrl = safeSameOriginUrl(result.reset_url, "");
      if (safeResetUrl) {
        resetLink.href = safeResetUrl;
        resetLink.rel = "noopener noreferrer";
        resetLink.classList.remove("is-hidden");
      } else {
        resetLink.removeAttribute("href");
        resetLink.classList.add("is-hidden");
      }

      resultWrap.classList.remove("is-hidden");
      submitBtn.classList.remove("loading");
      submitBtn.disabled = false;
    } catch {
      showToast("Unable to reach server. Is XAMPP running?", "error");
      submitBtn.classList.remove("loading");
      submitBtn.disabled = false;
    }
  });
}

let patientCardState = {
  completedStep: 0,
  accountComplete: false,
  profileComplete: false,
  emergencyOnFile: false,
  assemblyPlayed: false,
  accountNeedsReview: false,
  profileNeedsReview: false,
};
let currentRegistrationStep = 1;

function syncWizardProgress() {
  [1, 2, 3].forEach((number) => {
    const completed = number <= patientCardState.completedStep;
    const active = number === currentRegistrationStep;
    const circle = document.getElementById('step-indicator-' + number);
    const label = document.getElementById('step-label-' + number);
    const item = document.getElementById('progress-item-' + number);
    circle?.classList.toggle('active', active);
    circle?.classList.toggle('completed', completed);
    label?.classList.toggle('active', active);
    label?.classList.toggle('completed', completed);
    item?.classList.toggle('active', active);
    item?.classList.toggle('completed', completed);
  });
  const progress = document.querySelector('.wizard-step-indicator');
  if (progress) {
    progress.dataset.currentStep = String(currentRegistrationStep);
    progress.dataset.completedSteps = String(Math.min(2, patientCardState.completedStep));
  }
}

function updatePatientCardName() {
  const card = document.getElementById('patient-preview-card');
  if (!card) return;
  const first = document.getElementById('reg-first-name')?.value.trim() || '';
  const last = document.getElementById('reg-last-name')?.value.trim() || '';
  const fullName = [first, last].filter(Boolean).join(' ');
  const initials = [first, last].filter(Boolean).map((part) => Array.from(part)[0] || '').join('').slice(0, 2);
  const name = document.getElementById('patient-card-name');
  const avatar = document.getElementById('patient-card-avatar');
  const caption = document.getElementById('patient-card-caption');
  if (name) {
    name.textContent = fullName || 'Your name';
    name.classList.toggle('is-placeholder', !fullName);
  }
  if (avatar) {
    avatar.textContent = initials || '+';
    avatar.classList.toggle('is-placeholder', !initials);
  }
  if (caption) caption.textContent = fullName ? 'PATIENT IDENTITY' : 'Your initials will appear here';
}

function renderPatientCardState() {
  const card = document.getElementById('patient-preview-card');
  if (!card) return;
  const badge = document.getElementById('patient-card-badge');
  const status = document.getElementById('patient-card-status');
  const details = document.getElementById('patient-card-details');
  const emergency = document.getElementById('patient-card-emergency');
  card.classList.toggle('is-profile-complete', patientCardState.profileComplete);
  card.classList.toggle('is-emergency-complete', patientCardState.emergencyOnFile);
  if (badge) {
    badge.textContent = patientCardState.emergencyOnFile ? 'REGISTRATION RECEIVED' : patientCardState.profileComplete ? 'PROFILE COMPLETE' : patientCardState.accountComplete ? 'ACCOUNT COMPLETE' : 'DRAFT PREVIEW';
  }
  if (status) {
    status.textContent = patientCardState.emergencyOnFile ? 'Your registration request has been received' : patientCardState.profileComplete ? 'Profile complete' : patientCardState.accountComplete ? 'Account details complete' : patientCardState.accountNeedsReview ? 'Account details changed — recheck this step' : 'A preview — not a submitted record';
  }
  if (details) {
    if (patientCardState.profileComplete) {
      const bloodType = document.getElementById('reg-blood-type')?.value || '';
      details.textContent = bloodType ? 'Blood type · ' + bloodType : 'Patient profile details complete';
      details.classList.remove('is-placeholder');
    } else {
      details.textContent = patientCardState.profileNeedsReview ? 'Profile details changed — recheck this step' : 'Your details will appear after Step 2';
      details.classList.add('is-placeholder');
    }
  }
  if (emergency) emergency.classList.toggle('is-visible', patientCardState.emergencyOnFile);
}

function completePatientCardAccount() {
  const card = document.getElementById('patient-preview-card');
  patientCardState.completedStep = Math.max(patientCardState.completedStep, 1);
  patientCardState.accountComplete = true;
  patientCardState.accountNeedsReview = false;
  if (card) {
    card.classList.add('is-account-complete');
    if (!patientCardState.assemblyPlayed) {
      patientCardState.assemblyPlayed = true;
      if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        card.classList.add('is-flourishing');
        card.addEventListener('animationend', (event) => {
          if (event.animationName === 'registerPatientCardFlourish') card.classList.remove('is-flourishing');
        });
      }
    }
  }
  renderPatientCardState();
}

function completePatientCardProfile() {
  patientCardState.completedStep = Math.max(patientCardState.completedStep, 2);
  patientCardState.accountComplete = true;
  patientCardState.profileComplete = true;
  patientCardState.accountNeedsReview = false;
  patientCardState.profileNeedsReview = false;
  renderPatientCardState();
}

function completePatientCardEmergency() {
  patientCardState.completedStep = 3;
  patientCardState.emergencyOnFile = true;
  renderPatientCardState();
  syncWizardProgress();
}

function invalidatePatientCardFrom(step) {
  if (step <= 1) {
    patientCardState.completedStep = 0;
    patientCardState.accountComplete = false;
    patientCardState.profileComplete = false;
    patientCardState.emergencyOnFile = false;
    patientCardState.accountNeedsReview = true;
    patientCardState.profileNeedsReview = false;
  } else {
    patientCardState.completedStep = Math.min(patientCardState.completedStep, 1);
    patientCardState.profileComplete = false;
    patientCardState.emergencyOnFile = false;
    patientCardState.profileNeedsReview = true;
  }
  renderPatientCardState();
  syncWizardProgress();
}

function initRegisterFocusLines() {
  document.querySelectorAll('.auth-register-page .form-group input:not([type=checkbox]):not([type=radio]), .auth-register-page .form-group select, .auth-register-page .form-group textarea').forEach((field) => {
    const applyLine = (focused) => {
      if (field.tagName === 'SELECT') {
        field.style.backgroundSize = focused ? '100% 2px, 16px 16px' : '0px 2px, 16px 16px';
      } else {
        field.style.backgroundSize = focused ? '100% 2px' : '0px 2px';
      }
    };
    field.addEventListener('focus', () => applyLine(true));
    field.addEventListener('blur', () => applyLine(false));
    if (document.activeElement === field) applyLine(true);
  });
}

function initPatientCardPreview() {
  const card = document.getElementById('patient-preview-card');
  if (!card) return;
  updatePatientCardName();
  renderPatientCardState();
  syncWizardProgress();
  const accountForm = document.getElementById('step1-form');
  accountForm?.querySelectorAll('input').forEach((input) => {
    input.addEventListener('input', () => {
      updatePatientCardName();
      if (patientCardState.completedStep >= 1 || patientCardState.profileComplete) invalidatePatientCardFrom(1);
    });
  });
  const profileForm = document.getElementById('step2-form');
  profileForm?.querySelectorAll('input, select, textarea').forEach((field) => {
    const invalidate = () => {
      if (patientCardState.profileComplete || patientCardState.completedStep >= 2) invalidatePatientCardFrom(2);
    };
    field.addEventListener('input', invalidate);
    field.addEventListener('change', invalidate);
  });
}

function initRegistrationWizard() {
  const step1 = document.getElementById("wizard-step-1");
  if (!step1) return;

  const step1Form = document.getElementById("step1-form");
  const step2Form = document.getElementById("step2-form");
  const step3Form = document.getElementById("step3-form");

  document.getElementById("step1-next")?.addEventListener("click", handleStep1Submit);
  document.getElementById("step2-prev")?.addEventListener("click", () => goToStep(1));
  document.getElementById("step2-next")?.addEventListener("click", handleStep2Continue);
  document.getElementById("step3-prev")?.addEventListener("click", () => goToStep(2));
  document.getElementById("step3-submit")?.addEventListener("click", handleStep3Submit);

  step1Form?.addEventListener("keydown", (event) => {
    if (event.key === "Enter" && !event.target.closest("button")) {
      event.preventDefault();
      handleStep1Submit();
    }
  });

  step2Form?.addEventListener("keydown", (event) => {
    if (event.key !== "Enter" || event.target.closest("button")) return;
    if (event.target instanceof HTMLTextAreaElement) return;
    event.preventDefault();
    handleStep2Continue();
  });

  step3Form?.addEventListener("keydown", (event) => {
    if (event.key !== "Enter" || event.target.closest("button")) return;
    if (event.target instanceof HTMLTextAreaElement) return;
    event.preventDefault();
    handleStep3Submit();
  });

  const pwInput = document.getElementById("reg-password");
  pwInput?.addEventListener("input", () => updatePasswordStrength(pwInput.value));

  const confirmInput = document.getElementById("reg-confirm-password");
  confirmInput?.addEventListener("input", () => {
    if (confirmInput.value && pwInput?.value && confirmInput.value !== pwInput.value) {
      showFieldError("confirm-password-error", "Passwords do not match.");
    } else {
      clearFieldError("confirm-password-error");
    }
  });

  const emailInput = document.getElementById("reg-email");
  emailInput?.addEventListener("input", debounce(() => {
    const email = emailInput.value.trim();
    if (email && isValidEmail(email)) clearFieldError("email-error");
  }, 500));

  const phoneInput = document.getElementById("reg-phone");
  phoneInput?.addEventListener("input", debounce(() => {
    const phone = phoneInput.value.trim();
    if (!phone) {
      clearFieldError("phone-error");
      return;
    }
    const cleanPhone = phone.replace(/\s/g, "");
    if (!/^01[0125]\d{8}$/.test(cleanPhone)) {
      if (phone.length >= 11) {
        showFieldError("phone-error", "Please enter a valid Egyptian phone number.");
      } else {
        clearFieldError("phone-error");
      }
      return;
    }
    clearFieldError("phone-error");
  }, 500));

  const emergencyPhoneInput = document.getElementById("reg-emergency-phone");
  emergencyPhoneInput?.addEventListener("input", () => {
    const phone = emergencyPhoneInput.value.trim();
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

  const nationalIdInput = document.getElementById("reg-national-id");
  nationalIdInput?.addEventListener("input", debounce(() => {
    const nationalId = nationalIdInput.value.trim();
    if (!nationalId) {
      clearFieldError("national-id-error");
    } else if (nationalId.length === 14 && /^\d{14}$/.test(nationalId)) {
      clearFieldError("national-id-error");
    } else if (nationalId.length >= 14) {
      showFieldError("national-id-error", "National ID must be exactly 14 digits.");
    } else {
      clearFieldError("national-id-error");
    }
  }, 500));

  loadGovernorates();
  document.getElementById("reg-governorate")?.addEventListener("change", (event) => {
    loadCities(event.target.value);
  });
}/* ── Step Navigation ─────────────────────────────────────── */
function goToStep(step) {
  const currentStep = Math.min(3, Math.max(1, Number(step) || 1));
  currentRegistrationStep = currentStep;
  [1, 2, 3].forEach((number) => {
    document.getElementById("wizard-step-" + number)?.classList.toggle("active", number === currentStep);
  });
  syncWizardProgress();

  const headerTitle = document.querySelector(".auth-register-page .auth-form-header h2");
  const headerDescription = document.getElementById("registration-step-description");
  const content = {
    1: { title: "Account details", description: "Step 1 of 3 · Your sign-in and contact information" },
    2: { title: "Patient profile", description: "Step 2 of 3 · Your identity and address" },
    3: { title: "Emergency contact", description: "Step 3 of 3 · Required contact and optional details" },
  }[currentStep];
  if (headerTitle) headerTitle.textContent = content.title;
  if (headerDescription) headerDescription.textContent = content.description;

  const panel = document.querySelector(".auth-form-panel");
  const stepTitle = document.querySelector("#wizard-step-" + currentStep + " .wizard-step-title");
  stepTitle?.focus({ preventScroll: true });
  if (panel) {
    panel.scrollTop = 0;
    const navHeight = document.querySelector(".navbar")?.getBoundingClientRect().height || 74;
    const top = Math.max(0, panel.getBoundingClientRect().top + window.scrollY - navHeight - 18);
    const behavior = window.matchMedia("(prefers-reduced-motion: reduce)").matches ? "auto" : "smooth";
    window.scrollTo({ top, behavior });
  }
}

/* ── Step 1: Submit Account Info ──────────────────────────── */
let step1Data = {}; // Account details, held in memory only until final submission.
let step2Data = {}; // Patient profile details, held in memory only until final submission.

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

  // Validate password against the same four-of-five rule as the server.
  const pwChecks = checkPasswordStrength(password);
  if (!pwChecks.valid) {
    const unmetRules = [
      !pwChecks.checks.min_length && "8 or more characters",
      !pwChecks.checks.has_uppercase && "an uppercase letter",
      !pwChecks.checks.has_lowercase && "a lowercase letter",
      !pwChecks.checks.has_number && "a number",
      !pwChecks.checks.has_special && "a character other than A–Z, a–z, or 0–9",
    ].filter(Boolean);

    showFieldError(
      "password-error",
      `Password must meet at least 4 of these 5 checks. Not met: ${unmetRules.join(", ")}.`
    );
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

    // Assemble the patient card after the existing validation request succeeds.
    completePatientCardAccount();

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
async function handleStep2Continue() {
  const dob = document.getElementById("reg-dob")?.value || "";
  const gender = document.getElementById("reg-gender")?.value || "";
  const bloodType = document.getElementById("reg-blood-type")?.value || "";
  const nationalId = document.getElementById("reg-national-id")?.value.trim() || "";
  const governorate = document.getElementById("reg-governorate")?.value || "";
  const city = document.getElementById("reg-city")?.value || "";
  const address = document.getElementById("reg-address")?.value.trim() || "";

  document.querySelectorAll("#step2-form .field-error").forEach((element) => {
    element.classList.remove("show");
    element.textContent = "";
  });

  let hasError = false;
  if (!dob) {
    showFieldError("dob-error", "Date of birth is required.");
    hasError = true;
  }
  if (!gender) {
    showFieldError("gender-error", "Gender is required.");
    hasError = true;
  }
  if (!nationalId) {
    showFieldError("national-id-error", "National ID is required.");
    hasError = true;
  } else if (!/^\d{14}$/.test(nationalId)) {
    showFieldError("national-id-error", "National ID must be exactly 14 digits.");
    hasError = true;
  }
  if (!governorate) {
    showFieldError("governorate-error", "Governorate is required.");
    hasError = true;
  }
  if (!city) {
    showFieldError("city-error", "City is required.");
    hasError = true;
  }
  if (!address) {
    showFieldError("address-error", "Full address is required.");
    hasError = true;
  }
  if (hasError) return;

  step2Data = {
    date_of_birth: dob,
    gender,
    blood_type: bloodType,
    national_id: nationalId,
    governorate,
    city,
    address,
  };
  completePatientCardProfile();
  goToStep(3);
}

/* ── Step 3: Emergency Contact and Final Submission ───────── */
async function handleStep3Submit() {
  if (!step1Data.first_name || !step1Data.email) {
    showToast("Session expired. Please start registration again.", "error");
    goToStep(1);
    return;
  }
  if (!step2Data.date_of_birth) {
    showToast("Please complete your patient profile first.", "error");
    goToStep(2);
    return;
  }

  const emergencyName = document.getElementById("reg-emergency-name")?.value.trim() || "";
  const emergencyPhone = document.getElementById("reg-emergency-phone")?.value.trim() || "";
  const emergencyRel = document.getElementById("reg-emergency-rel")?.value || "";
  const allergies = document.getElementById("reg-allergies")?.value.trim() || "";
  const chronic = document.getElementById("reg-chronic")?.value.trim() || "";
  const medications = document.getElementById("reg-medications")?.value.trim() || "";
  const insuranceProvider = document.getElementById("reg-insurance-provider")?.value.trim() || "";
  const insuranceNumber = document.getElementById("reg-insurance-number")?.value.trim() || "";

  document.querySelectorAll("#step3-form .field-error").forEach((element) => {
    element.classList.remove("show");
    element.textContent = "";
  });

  let hasError = false;
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

  const submitButton = document.getElementById("step3-submit");
  submitButton.classList.add("loading");
  submitButton.disabled = true;

  try {
    const response = await fetch(getBasePath() + "api/auth/register.php", {
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
        ...step2Data,
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
    const result = await response.json();

    submitButton.classList.remove("loading");
    submitButton.disabled = false;

    if (!result.success) {
      showToast(result.message || "Registration failed.", "error");
      return;
    }

    completePatientCardEmergency();
    const registrationEmail = step1Data.email || "";
    step1Data = {};
    step2Data = {};
    showSuccessScreen(registrationEmail);
  } catch {
    showToast("Registration service unavailable. Please ensure XAMPP is running.", "error");
    submitButton.classList.remove("loading");
    submitButton.disabled = false;
  }
}/* ── Success Screen ───────────────────────────────────────── */
function showSuccessScreen(registrationEmail = "") {
  // Hide wizard steps
  const ws1 = document.getElementById("wizard-step-1");
  if (ws1) ws1.classList.remove("active");
  const ws2 = document.getElementById("wizard-step-2");
  if (ws2) ws2.classList.remove("active");
  const ws3 = document.getElementById("wizard-step-3");
  if (ws3) ws3.classList.remove("active");
  const wp = document.getElementById("wizard-progress");
  if (wp) wp.classList.add("is-hidden");
  const rpn = document.getElementById("reg-privacy-note");
  if (rpn) rpn.classList.add("is-hidden");
  const afh = document.querySelector(".auth-form-header");
  if (afh) afh.classList.add("is-hidden");

  // Remove credential fields from the DOM after the final response is handled.
  const passwordInput = document.getElementById("reg-password");
  const confirmPasswordInput = document.getElementById("reg-confirm-password");
  if (passwordInput) passwordInput.value = "";
  if (confirmPasswordInput) confirmPasswordInput.value = "";

  // Keep the same neutral acknowledgment for new and duplicate outcomes.
  const successEl = document.getElementById("reg-success");
  if (successEl) successEl.classList.add("show");

  const verificationEmail = document.getElementById("registration-verification-email");
  if (verificationEmail && isValidEmail(registrationEmail)) {
    verificationEmail.value = registrationEmail;
  }

  // Do not auto-redirect. A patient must confirm the email link before the
  // registration worker is allowed to create the account and medical profile.
}

/* ── Login-page verification resend ─────────────────────── */
function initLoginVerificationResend() {
  const form = document.getElementById("login-verification-resend-form");
  const panel = document.getElementById("login-verification-resend");
  const openButton = document.getElementById("open-login-verification-resend");
  const emailInput = document.getElementById("login-verification-resend-email");
  const loginEmail = document.getElementById("login-email");
  const submitBtn = document.getElementById("login-verification-resend-submit");
  const message = document.getElementById("login-verification-resend-message");
  if (!form || !panel) return;

  const openPanel = () => {
    panel.classList.remove("is-hidden");
    if (emailInput && loginEmail?.value.trim()) {
      emailInput.value = loginEmail.value.trim();
    }
    emailInput?.focus();
  };

  openButton?.addEventListener("click", openPanel);

  form.addEventListener("submit", async (event) => {
    event.preventDefault();

    const email = emailInput?.value.trim() || "";
    if (!email || !isValidEmail(email)) {
      if (message) {
        message.textContent = "Enter a valid email address.";
        message.className = "verification-resend-message error";
      }
      emailInput?.focus();
      return;
    }

    submitBtn?.classList.add("loading");
    if (submitBtn) submitBtn.disabled = true;
    if (message) {
      message.textContent = "";
      message.className = "verification-resend-message";
    }

    try {
      const response = await fetch(getBasePath() + "api/auth/resend-registration-verification.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ email }),
      });
      const result = await response.json();

      if (!response.ok || !result.success) {
        throw new Error(result.message || "Unable to process the resend request.");
      }

      if (message) {
        message.textContent = result.message || "If a registration is waiting for verification, a new email will be sent.";
        message.className = "verification-resend-message success";
      }
    } catch (error) {
      if (message) {
        message.textContent = error.message || "Verification email service is temporarily unavailable.";
        message.className = "verification-resend-message error";
      }
    } finally {
      submitBtn?.classList.remove("loading");
      if (submitBtn) submitBtn.disabled = false;
    }
  });
}

/* ── Registration verification resend ────────────────────── */
function initRegistrationVerificationResend() {
  const form = document.getElementById("registration-verification-resend-form");
  if (!form) return;

  const emailInput = document.getElementById("registration-verification-email");
  const submitBtn = document.getElementById("registration-verification-resend-submit");
  const message = document.getElementById("registration-verification-resend-message");

  form.addEventListener("submit", async (event) => {
    event.preventDefault();

    const email = emailInput?.value.trim() || "";
    if (!email || !isValidEmail(email)) {
      if (message) {
        message.textContent = "Enter a valid email address.";
        message.className = "verification-resend-message error";
      }
      emailInput?.focus();
      return;
    }

    submitBtn?.classList.add("loading");
    if (submitBtn) submitBtn.disabled = true;
    if (message) {
      message.textContent = "";
      message.className = "verification-resend-message";
    }

    try {
      const response = await fetch(getBasePath() + "api/auth/resend-registration-verification.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ email }),
      });
      const result = await response.json();

      if (!response.ok || !result.success) {
        throw new Error(result.message || "Unable to process the resend request.");
      }

      if (message) {
        message.textContent = result.message || "If a registration is waiting for verification, a new email will be sent.";
        message.className = "verification-resend-message success";
      }
    } catch (error) {
      if (message) {
        message.textContent = error.message || "Verification email service is temporarily unavailable.";
        message.className = "verification-resend-message error";
      }
    } finally {
      submitBtn?.classList.remove("loading");
      if (submitBtn) submitBtn.disabled = false;
    }
  });
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
   LOCATIONS (Governorates & Cities)
   ============================================================ */
let governoratesPromise = null;

function getGovernorates() {
  if (!governoratesPromise) {
    governoratesPromise = fetch(getBasePath() + "api/auth/locations.php?type=governorates")
      .then((response) => {
        if (!response.ok) throw new Error("Failed to load governorates.");
        return response.json();
      })
      .then((result) => {
        if (!result.success || !Array.isArray(result.governorates)) {
          throw new Error("Invalid governorates response.");
        }

        const validGovernorates = result.governorates
          .filter((governorate) =>
            governorate &&
            typeof governorate === "object" &&
            !Array.isArray(governorate) &&
            safePositiveId(governorate.id) > 0 &&
            typeof governorate.name === "string" &&
            governorate.name.trim() !== ""
          )
          .map((governorate) => ({
            id: safePositiveId(governorate.id),
            name: governorate.name.trim().slice(0, 80),
          }));

        if (validGovernorates.length === 0) {
          throw new Error("No valid governorates were returned.");
        }
        return validGovernorates;
      })
      .catch((error) => {
        governoratesPromise = null;
        throw error;
      });
  }

  return governoratesPromise;
}

async function loadGovernorates() {
  const select = document.getElementById("reg-governorate");
  if (!select) return;

  try {
    const governorates = await getGovernorates();

    governorates.forEach((gov) => {
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

let cityLoadRequestId = 0;

async function loadCities(governorateName) {
  const requestId = ++cityLoadRequestId;
  const citySelect = document.getElementById("reg-city");
  if (!citySelect) return;

  // Clear existing options
  citySelect.innerHTML = '<option value="">Select city</option>';
  citySelect.disabled = true;

  if (!governorateName) return;

  // Reuse the cached lookup rather than fetching all governorates again.
  try {
    const governorates = await getGovernorates();
    if (requestId !== cityLoadRequestId) return;

    const gov = governorates.find((g) => g.name === governorateName);
    if (!gov) return;

    const res = await fetch(
      getBasePath() + `api/auth/locations.php?type=cities&governorate_id=${gov.id}`
    );
    const result = await res.json();
    if (requestId !== cityLoadRequestId) return;

    if (!result.success || !Array.isArray(result.cities)) return;

    const validCities = result.cities
      .filter((city) =>
        city &&
        typeof city === "object" &&
        !Array.isArray(city) &&
        typeof city.name === "string" &&
        city.name.trim() !== ""
      )
      .map((city) => city.name.trim().slice(0, 80));

    validCities.forEach((cityName) => {
      const option = document.createElement("option");
      option.value = cityName;
      option.textContent = cityName;
      citySelect.appendChild(option);
    });

    citySelect.disabled = false;
  } catch {
    if (requestId !== cityLoadRequestId) return;

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