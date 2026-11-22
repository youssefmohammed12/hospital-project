/*
 * HealthBridge Contact / Project Feedback — Public V2 controller.
 * Keeps the existing POST contract to api/settings/contact.php.
 * No email, map, Leaflet, legacy main.js or dashboard dependency.
 */
"use strict";

(() => {
  function initContactForm() {
    const form = document.getElementById("contact-form");
    if (!form) return;

    const fields = {
      name: form.elements.namedItem("name"),
      email: form.elements.namedItem("email"),
      phone: form.elements.namedItem("phone"),
      department: form.elements.namedItem("department"),
      subject: form.elements.namedItem("subject"),
      message: form.elements.namedItem("message"),
    };

    const successMessage = document.getElementById("form-success");
    const errorMessage = document.getElementById("form-error");
    const submitButton = form.querySelector('button[type="submit"]');
    const submitLabel = submitButton?.querySelector(".contact-submit__label");
    const defaultSubmitLabel = submitLabel?.textContent || "Save demo feedback";
    let isSubmitting = false;

    function clearMessages() {
      [successMessage, errorMessage].forEach((message) => {
        if (!message) return;
        message.hidden = true;
        message.textContent = "";
      });
    }

    function showMessage(target, message) {
      if (!target) return;
      target.textContent = message;
      target.hidden = false;
      const reduceMotion = window.matchMedia?.("(prefers-reduced-motion: reduce)").matches;
      target.scrollIntoView({
        behavior: reduceMotion ? "auto" : "smooth",
        block: "nearest",
      });
    }

    function markInvalid(field, invalid) {
      if (!field) return;
      if (invalid) {
        field.setAttribute("aria-invalid", "true");
      } else {
        field.removeAttribute("aria-invalid");
      }
    }

    Object.values(fields).forEach((field) => {
      field?.addEventListener("input", () => markInvalid(field, false));
      field?.addEventListener("change", () => markInvalid(field, false));
    });

    function validate(values) {
      const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
      const checks = [
        { field: fields.name, valid: values.name.length >= 2, message: "Please enter a demo name with at least 2 characters." },
        { field: fields.email, valid: emailPattern.test(values.email), message: "Please enter a valid demo email address." },
        { field: fields.message, valid: values.message.length >= 10, message: "Please enter feedback with at least 10 characters." },
      ];

      for (const check of checks) {
        markInvalid(check.field, !check.valid);
        if (!check.valid) {
          showMessage(errorMessage, check.message);
          check.field?.focus();
          return false;
        }
      }
      return true;
    }

    async function submitFeedback(values) {
      const basePath = typeof getBasePath === "function" ? getBasePath() : "";
      const endpoint = new URL(basePath + "api/settings/contact.php", document.baseURI);

      if (endpoint.origin !== window.location.origin) {
        throw new Error("The feedback endpoint must remain same-origin.");
      }

      const response = await fetch(endpoint.href, {
        method: "POST",
        credentials: "same-origin",
        headers: {
          "Accept": "application/json",
          "Content-Type": "application/json",
        },
        body: JSON.stringify(values),
      });

      const result = await response.json().catch(() => null);
      if (!response.ok || !result || result.success !== true) {
        throw new Error("The feedback request was not accepted.");
      }
      return result;
    }

    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      if (isSubmitting) return;

      clearMessages();

      const values = {
        name: String(fields.name?.value || "").trim(),
        email: String(fields.email?.value || "").trim(),
        phone: String(fields.phone?.value || "").trim(),
        department: String(fields.department?.value || "General Project Feedback"),
        subject: String(fields.subject?.value || "").trim(),
        message: String(fields.message?.value || "").trim(),
      };

      if (!validate(values)) return;

      isSubmitting = true;
      form.setAttribute("aria-busy", "true");
      if (submitButton) {
        submitButton.disabled = true;
        submitButton.classList.add("is-loading");
      }
      if (submitLabel) submitLabel.textContent = "Saving feedback…";

      try {
        await submitFeedback(values);
        form.reset();
        Object.values(fields).forEach((field) => markInvalid(field, false));
        showMessage(successMessage, "Thank you — your demo feedback has been saved.");
      } catch {
        showMessage(errorMessage, "We could not save your feedback right now. Please try again later.");
      } finally {
        isSubmitting = false;
        form.removeAttribute("aria-busy");
        if (submitButton) {
          submitButton.disabled = false;
          submitButton.classList.remove("is-loading");
        }
        if (submitLabel) submitLabel.textContent = defaultSubmitLabel;
      }
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initContactForm, { once: true });
  } else {
    initContactForm();
  }
})();
