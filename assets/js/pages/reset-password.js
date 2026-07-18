/**
 * HealthBridge — Reset Password Page JavaScript
 * Handles: server-side token verification on load, password strength meter,
 *          form submission, and auto-redirect to login on success.
 *
 * Used by: reset-password.html
 */

"use strict";

document.addEventListener("DOMContentLoaded", async () => {
  const token = new URLSearchParams(window.location.search).get("token");

  const loadingEl  = document.getElementById("reset-loading");
  const invalidEl  = document.getElementById("reset-invalid");
  const formWrapEl = document.getElementById("reset-form-wrap");
  const successEl  = document.getElementById("reset-success");

  // Show only one panel at a time
  function showPanel(el) {
    [loadingEl, invalidEl, formWrapEl, successEl].forEach(p => {
      if (p) p.style.display = "none";
    });
    if (el) el.style.display = "";
  }

  // No token in URL → invalid immediately
  if (!token || token.length < 60) {
    showPanel(invalidEl);
    return;
  }

  // ── Server-side token verification on page load ──────────
  showPanel(loadingEl);
  try {
    const res    = await fetch((getBasePath() + "api/auth/reset_password.php"), {
      method:  "POST",
      headers: { "Content-Type": "application/json" },
      // Send an empty password to just verify the token exists
      body:    JSON.stringify({ token, verify_only: true, password: "", confirm_password: "" }),
    });
    const result = await res.json();

    // "Passwords do not match" or "at least 6 chars" = token is valid, form should show
    // "invalid or has expired" = token is bad
    if (!result.success && (result.message?.includes("invalid") || result.message?.includes("expired"))) {
      showPanel(invalidEl);
      return;
    }
  } catch {
    // Network error — still show the form; the submit will catch real errors
  }

  showPanel(formWrapEl);

  // ── Password Strength Meter ──────────────────────────────
  const pwInput     = document.getElementById("reset-password");
  const strengthBar = document.getElementById("pw-strength-fill");
  const strengthLbl = document.getElementById("pw-strength-label");

  pwInput?.addEventListener("input", () => {
    const pw  = pwInput.value;
    let score = 0;
    if (pw.length >= 6)            score++;
    if (pw.length >= 10)           score++;
    if (/[A-Z]/.test(pw))          score++;
    if (/[0-9]/.test(pw))          score++;
    if (/[^A-Za-z0-9]/.test(pw))  score++;

    const levels = [
      { label: "",            color: "transparent", width: "0%"   },
      { label: "Very Weak",   color: "#ef4444",     width: "20%"  },
      { label: "Weak",        color: "#f97316",     width: "40%"  },
      { label: "Fair",        color: "#eab308",     width: "60%"  },
      { label: "Strong",      color: "#22c55e",     width: "80%"  },
      { label: "Very Strong", color: "#22d3ee",     width: "100%" },
    ];

    const level = levels[score] || levels[0];
    if (strengthBar) {
      strengthBar.style.width      = level.width;
      strengthBar.style.background = level.color;
    }
    if (strengthLbl) {
      strengthLbl.textContent = level.label;
      strengthLbl.style.color = level.color;
    }
  });

  // ── Reset Form Submission ────────────────────────────────
  const resetForm = document.getElementById("reset-form");
  resetForm?.addEventListener("submit", async (e) => {
    e.preventDefault();

    const submitBtn = resetForm.querySelector('[type="submit"]');
    const password  = document.getElementById("reset-password")?.value || "";
    const confirmPw = document.getElementById("reset-confirm")?.value || "";

    if (password.length < 6) {
      showToast("Password must be at least 6 characters.", "error");
      return;
    }
    if (password !== confirmPw) {
      showToast("Passwords do not match.", "error");
      return;
    }

    setLoading(submitBtn, true, "Resetting…");

    try {
      const res    = await fetch((getBasePath() + "api/auth/reset_password.php"), {
        method:  "POST",
        headers: { "Content-Type": "application/json" },
        body:    JSON.stringify({ token, password, confirm_password: confirmPw }),
      });
      const result = await res.json();

      if (!result.success) {
        // Token expired or invalid during submit
        if (result.message?.includes("invalid") || result.message?.includes("expired")) {
          showPanel(invalidEl);
        } else {
          showToast(result.message || "Reset failed. Please try again.", "error");
          setLoading(submitBtn, false, "Reset Password");
        }
        return;
      }

      // ── Success: show message then redirect ──────────────
      showPanel(successEl);

      // Update the success panel message dynamically
      const successMsg = document.getElementById("reset-success-msg");
      if (successMsg) successMsg.textContent = result.message;

      // Auto-redirect to login after 3 seconds
      let countdown  = 3;
      const countEl  = document.getElementById("redirect-countdown");
      if (countEl) countEl.textContent = countdown;

      const timer = setInterval(() => {
        countdown--;
        if (countEl) countEl.textContent = countdown;
        if (countdown <= 0) {
          clearInterval(timer);
          window.location.href = getBasePath() + "pages/auth/login.html";
        }
      }, 1000);

    } catch {
      showToast("Server error. Is XAMPP running?", "error");
      setLoading(submitBtn, false, "Reset Password");
    }
  });
});

