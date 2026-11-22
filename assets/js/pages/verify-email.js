(() => {
  "use strict";

  const status = document.getElementById("verify-email-status");
  const confirmButton = document.getElementById("confirm-registration-email");
  const loginLink = document.getElementById("verify-email-login-link");

  // Keep the bearer token in memory only. URL fragments are not included in
  // HTTP requests or Apache access logs; remove it from the visible URL at once.
  const fragment = window.location.hash.startsWith("#")
    ? window.location.hash.slice(1)
    : "";
  const token = new URLSearchParams(fragment).get("token") || "";
  window.history.replaceState(null, "", window.location.pathname);

  const setStatus = (message, type = "") => {
    if (!status) return;
    status.textContent = message;
    status.dataset.state = type;
  };

  if (loginLink) loginLink.classList.add("is-hidden");

  if (!/^[a-f0-9]{64}$/i.test(token)) {
    setStatus("This verification link is missing or invalid. Request a new verification email from the registration page.", "error");
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
      const response = await fetch("../../api/auth/confirm-registration-email.php", {
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
        result.message || "Your email address is verified. Your account is now being prepared. You can sign in shortly.",
        "success"
      );
      confirmButton.classList.remove("loading");
      confirmButton.classList.add("is-hidden");
      if (loginLink) loginLink.classList.remove("is-hidden");
    } catch (error) {
      setStatus(
        error instanceof Error ? error.message : "Email verification could not be completed. Request a new link.",
        "error"
      );
      confirmButton.disabled = false;
      confirmButton.classList.remove("loading");
    }
  });
})();
