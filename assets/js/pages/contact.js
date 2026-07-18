/**
 * HealthBridge — Contact Page JavaScript
 * Handles: Leaflet map with persistent light tiles, contact form with
 *          validation and dual submission.
 *
 * Uses shared helpers from main.js:
 *   - apiFetch() — for local DB submission
 *   - showToast() — for fallback notifications
 *
 * Used by: contact.html
 */

"use strict";

const FORMSPREE_ENDPOINT = "https://formspree.io/f/mojpgdab";

document.addEventListener("DOMContentLoaded", () => {
  initMap();
  initContactForm();
});

/* ============================================================
   LEAFLET MAP
   Interactive map using light voyager tiles ONLY (will not turn dark)
   ============================================================ */

function initMap() {
  const mapElement = document.getElementById("contact-map");
  if (!mapElement) return;

  // Location: Fayoum General Hospital, Egypt
  const lat = 29.3162;
  const lng = 30.8500;

  const map = L.map("contact-map", {
    center: [lat, lng],
    zoom: 15,
    scrollWheelZoom: false, // Prevents page scroll capture
    zoomControl: true,
  });

  // Persistent light tile layer (keeps Voyager theme regardless of theme toggles)
  const lightTiles = "https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png";
  const attribution = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors &copy; <a href="https://carto.com/attributions">CARTO</a>';

  L.tileLayer(lightTiles, {
    attribution,
    maxZoom: 19,
  }).addTo(map);

  // Custom CSS-styled marker with glow animation
  const customIcon = L.divIcon({
    html: `<div class="map-marker-pin"></div>`,
    className: "custom-map-marker",
    iconSize: [40, 40],
    iconAnchor: [20, 40],
    popupAnchor: [0, -40],
  });

  L.marker([lat, lng], { icon: customIcon })
    .addTo(map)
    .bindPopup(`
      <div class="map-popup-content">
        <h5>HealthBridge Fayoum Clinic</h5>
        <p>El-Nabawy El-Mohandes Street, Faiyum, Egypt</p>
      </div>
    `)
    .openPopup();
}

/* ============================================================
   CONTACT FORM
   Dual submission: local DB (primary) + Formspree (backup)
   ============================================================ */

function initContactForm() {
  const form = document.getElementById("contact-form");
  if (!form) return;

  const submitBtn = form.querySelector('[type="submit"]');
  const successMsg = document.getElementById("form-success");
  const errorMsg = document.getElementById("form-error");

  form.addEventListener("submit", async (e) => {
    e.preventDefault();

    // Clear previous messages
    if (successMsg) successMsg.classList.remove("visible");
    if (errorMsg) errorMsg.classList.remove("visible");

    // Collect data
    const name = form.querySelector('[name="name"]')?.value.trim() || "";
    const email = form.querySelector('[name="email"]')?.value.trim() || "";
    const phone = form.querySelector('[name="phone"]')?.value.trim() || "";
    const department = form.querySelector('[name="department"]')?.value || "General Inquiry";
    const subject = form.querySelector('[name="subject"]')?.value.trim() || "";
    const message = form.querySelector('[name="message"]')?.value.trim() || "";

    // Frontend validation
    if (!name || !email || !message) {
      showError("Please fill in all required fields (name, email, message).");
      return;
    }
    if (name.length < 2) {
      showError("Name must be at least 2 characters.");
      return;
    }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      showError("Please enter a valid email address.");
      return;
    }
    if (message.length < 10) {
      showError("Message must be at least 10 characters.");
      return;
    }

    // Loading state
    if (submitBtn) {
      submitBtn.classList.add("btn-loading");
      submitBtn.disabled = true;
    }

    // 1. Submit to local database
    let dbResult = await apiFetch((getBasePath() + "api/settings/contact.php"), {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ name, email, phone, department, subject, message }),
    });
    const dbSuccess = dbResult.data?.success;

    // 2. Submit to Formspree (best-effort)
    let formspreeSuccess = false;
    try {
      const formData = new FormData();
      formData.append("name", name);
      formData.append("email", email);
      formData.append("phone", phone);
      formData.append("department", department);
      if (subject) formData.append("subject", subject);
      formData.append("message", message);

      const response = await fetch(FORMSPREE_ENDPOINT, {
        method: "POST",
        body: formData,
        headers: { Accept: "application/json" },
      });
      formspreeSuccess = response.ok;
    } catch {
      // Formspree failed — non-critical
    }

    // Show result
    if (dbSuccess || formspreeSuccess) {
      showSuccess(dbResult.data?.message || "Thank you! Your message has been sent successfully.");
      form.reset();
    } else {
      showError("Unable to send message. Please check your connection or try again later.");
    }

    if (submitBtn) {
      submitBtn.classList.remove("btn-loading");
      submitBtn.disabled = false;
    }
  });

  function showSuccess(msg) {
    if (successMsg) {
      successMsg.textContent = msg;
      successMsg.classList.add("visible");
      successMsg.scrollIntoView({ behavior: "smooth", block: "nearest" });
    } else {
      showToast(msg, "success");
    }
  }

  function showError(msg) {
    if (errorMsg) {
      errorMsg.textContent = msg;
      errorMsg.classList.add("visible");
      errorMsg.scrollIntoView({ behavior: "smooth", block: "nearest" });
    } else {
      showToast(msg, "error");
    }
  }
}

