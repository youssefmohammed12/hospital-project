/**
 * HealthBridge — Auth Token Pages (Public V2)
 * Handles: reset-password (token verify + new password + strength meter)
 *          and verify-email (explicit confirmation POST).
 *
 * Used by: reset-password.html, verify-email.html
 * Depends on: site.js (getBasePath, showToast) and auth-entry.js
 *             (checkPasswordStrength, password toggles) — both loaded
 *             by these pages; every init below is element-guarded like
 *             auth-entry.js, so the file is inert where its page is absent.
 * Loads NOTHING legacy.
 */

"use strict";

/* Fallback scorer, identical to auth-entry.js, used only if that file's
   global is somehow unavailable. */
function tokenPagePasswordScore(password) {
  if (typeof window.checkPasswordStrength === "function") {
    return window.checkPasswordStrength(password);
  }
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
  return { valid: score >= 4, strength, checks };
}

document.addEventListener("DOMContentLoaded", () => {
  initResetPasswordPage();
  initVerifyEmailPage();
});

/* ============================================================
   RESET PASSWORD
   ============================================================ */
function initResetPasswordPage() {
  const loadingEl = document.getElementById("reset-loading");
  if (!loadingEl) return;

  const invalidEl = document.getElementById("reset-invalid");
  const formWrapEl = document.getElementById("reset-form-wrap");
  const successEl = document.getElementById("reset-success");
  const token = new URLSearchParams(window.location.search).get("token");

  function showPanel(el) {
    [loadingEl, invalidEl, formWrapEl, successEl].forEach((p) => {
      if (p) p.classList.add("is-hidden");
    });
    if (el) el.classList.remove("is-hidden");
  }

  // No token in URL → invalid immediately (same rule as the legacy page).
  if (!token || token.length < 60) {
    showPanel(invalidEl);
    return;
  }

  // Server-side token verification on page load; the submit re-checks.
  (async () => {
    showPanel(loadingEl);
    try {
      const res = await fetch(getBasePath() + "api/auth/reset_password.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ token, verify_only: true, password: "", confirm_password: "" }),
      });
      const result = await res.json();
      if (
        !result.success &&
        (String(result.message || "").includes("invalid") ||
          String(result.message || "").includes("expired"))
      ) {
        showPanel(invalidEl);
        return;
      }
    } catch {
      // Network error — still show the form; the submit will surface it.
    }
    showPanel(formWrapEl);
  })();

  // Strength meter (V2 fill-class model, same scoring as registration).
  const pwInput = document.getElementById("reset-password");
  const fill = document.getElementById("pw-strength-fill");
  const label = document.getElementById("pw-strength-label");
  pwInput?.addEventListener("input", () => {
    const pw = pwInput.value;
    const result = tokenPagePasswordScore(pw);
    if (fill) {
      fill.className = "pw-strength-fill";
      if (pw.length > 0) fill.classList.add(result.strength);
    }
    if (label) {
      const labels = { weak: "Weak", medium: "Medium", strong: "Strong", very_strong: "Very Strong" };
      label.textContent = pw.length > 0 ? labels[result.strength] || "" : "";
    }
  });

  const resetForm = document.getElementById("reset-form");
  resetForm?.addEventListener("submit", async (e) => {
    e.preventDefault();

    const submitBtn = document.getElementById("reset-submit");
    const password = document.getElementById("reset-password")?.value || "";
    const confirmPw = document.getElementById("reset-confirm")?.value || "";

    const scored = tokenPagePasswordScore(password);
    if (!scored.valid) {
      const met = Object.values(scored.checks).filter(Boolean).length;
      showToast(
        "Password currently meets " + met + " of 5 requirements. Please meet at least 4.",
        "error"
      );
      return;
    }
    if (password !== confirmPw) {
      showToast("Passwords do not match.", "error");
      return;
    }

    submitBtn?.classList.add("loading");
    if (submitBtn) submitBtn.disabled = true;

    try {
      const res = await fetch(getBasePath() + "api/auth/reset_password.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ token, password, confirm_password: confirmPw }),
      });
      const result = await res.json();

      if (!result.success) {
        if (
          String(result.message || "").includes("invalid") ||
          String(result.message || "").includes("expired")
        ) {
          showPanel(invalidEl);
        } else {
          showToast(result.message || "Reset failed. Please try again.", "error");
          submitBtn?.classList.remove("loading");
          if (submitBtn) submitBtn.disabled = false;
        }
        return;
      }

      showPanel(successEl);
      const successMsg = document.getElementById("reset-success-msg");
      if (successMsg && result.message) successMsg.textContent = result.message;

      let countdown = 3;
      const countEl = document.getElementById("redirect-countdown");
      if (countEl) countEl.textContent = String(countdown);
      const timer = setInterval(() => {
        countdown--;
        if (countEl) countEl.textContent = String(countdown);
        if (countdown <= 0) {
          clearInterval(timer);
          window.location.href = "login.html";
        }
      }, 1000);
    } catch {
      showToast("Server error. Is XAMPP running?", "error");
      submitBtn?.classList.remove("loading");
      if (submitBtn) submitBtn.disabled = false;
    }
  });
}

/* ============================================================
   VERIFY EMAIL
   ============================================================ */
function initVerifyEmailPage() {
  const status = document.getElementById("verify-email-status");
  if (!status) return;

  const confirmButton = document.getElementById("confirm-registration-email");
  const loginLink = document.getElementById("verify-email-login-link");

  // Keep the bearer token in memory only. URL fragments are not included in
  // HTTP requests or server access logs; remove it from the visible URL at once.
  const fragment = window.location.hash.startsWith("#")
    ? window.location.hash.slice(1)
    : "";
  const token = new URLSearchParams(fragment).get("token") || "";
  window.history.replaceState(null, "", window.location.pathname);

  const setStatus = (message, type = "") => {
    status.textContent = message;
    status.dataset.state = type;
  };

  if (loginLink) loginLink.classList.add("is-hidden");

  if (!/^[a-f0-9]{64}$/i.test(token)) {
    setStatus(
      "This verification link is missing or invalid. Request a new verification email from the registration page.",
      "error"
    );
    if (confirmButton) confirmButton.disabled = true;
    if (loginLink) loginLink.classList.remove("is-hidden");
    return;
  }

  confirmButton?.addEventListener("click", async () => {
    if (!confirmButton || confirmButton.disabled) return;

    confirmButton.disabled = true;
    confirmButton.classList.add("loading");
    setStatus("Confirming your email address…");

    try {
      const response = await fetch(getBasePath() + "api/auth/confirm-registration-email.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        cache: "no-store",
        credentials: "same-origin",
        body: JSON.stringify({ token }),
      });
      const result = await response.json();

      if (!response.ok || !result.success) {
        throw new Error(result.message || "This verification link is invalid or has expired.");
      }

      setStatus(
        result.message ||
          "Your email address is verified. Your account is now being prepared. You can sign in shortly.",
        "success"
      );
      confirmButton.classList.remove("loading");
      confirmButton.classList.add("is-hidden");
      if (loginLink) loginLink.classList.remove("is-hidden");
    } catch (error) {
      setStatus(
        error instanceof Error
          ? error.message
          : "Email verification could not be completed. Request a new link.",
        "error"
      );
      confirmButton.disabled = false;
      confirmButton.classList.remove("loading");
    }
  });
}
