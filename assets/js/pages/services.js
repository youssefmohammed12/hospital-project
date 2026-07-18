/**
 * HealthBridge — Services Page JavaScript
 * Handles category filtering and interactive FAQ accordion.
 *
 * Used by: services.html
 */

"use strict";

document.addEventListener("DOMContentLoaded", () => {
  initServiceFilters();
  initFaqAccordion();
  initBookingLinks();
});

/**
 * Update booking link targets dynamically if the user is already logged in
 */
async function initBookingLinks() {
  let user = typeof getUser === "function" ? getUser() : null;

  // If no local storage session, check server-side session sync
  if (!user && typeof getSessionUser === "function") {
    user = await getSessionUser();
  }

  if (!user) return;

  const dashboardUrl = typeof getDashboardUrl === "function" 
    ? getDashboardUrl(user.role) + (user.role === "patient" ? "#book" : "") 
    : getBasePath() + "pages/patient/dashboard.html";

  // Select card links and CTA buttons that lead to login.html
  const bookingLinks = document.querySelectorAll('.service-link, .services-cta .btn-primary');
  bookingLinks.forEach((link) => {
    const href = link.getAttribute("href") || "";
    if (href.includes(getBasePath() + "pages/auth/login.html")) {
      link.setAttribute("href", dashboardUrl);
      if (link.classList.contains("service-link")) {
        link.innerHTML = "Book Now &rarr;";
      } else {
        link.textContent = "Go to Dashboard";
      }
    }
  });
}

/**
 * Initialize dynamic filtering of services by category
 */
function initServiceFilters() {
  const filterButtons = document.querySelectorAll(".filter-pill");
  const serviceCards = document.querySelectorAll(".service-card");

  if (!filterButtons.length || !serviceCards.length) return;

  filterButtons.forEach((btn) => {
    btn.addEventListener("click", () => {
      // 1. Update active tab styling
      filterButtons.forEach((b) => b.classList.remove("active"));
      btn.classList.add("active");

      const category = btn.dataset.filter;

      // 2. Filter service cards with smooth transitions
      serviceCards.forEach((card) => {
        const cardCategory = card.dataset.category;

        if (category === "all" || cardCategory === category) {
          card.classList.remove("hide");
          card.classList.add("show");
        } else {
          card.classList.remove("show");
          card.classList.add("hide");
        }
      });
    });
  });
}

/**
 * Initialize expandable FAQ items
 */
function initFaqAccordion() {
  const faqQuestions = document.querySelectorAll(".faq-question");

  if (!faqQuestions.length) return;

  faqQuestions.forEach((questionBtn) => {
    questionBtn.addEventListener("click", () => {
      const currentCard = questionBtn.closest(".faq-card");
      if (!currentCard) return;

      const isOpen = currentCard.classList.contains("open");

      // Close all other FAQ cards (accordion behavior)
      document.querySelectorAll(".faq-card").forEach((card) => {
        if (card !== currentCard) {
          card.classList.remove("open");
        }
      });

      // Toggle current FAQ card
      currentCard.classList.toggle("open", !isOpen);
    });
  });
}


