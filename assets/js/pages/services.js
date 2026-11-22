/**
 * HealthBridge — Project capabilities page.
 * Handles accessible workspace filtering and the project FAQ accordion.
 */
"use strict";

document.addEventListener("DOMContentLoaded", () => {
  initCapabilityFilters();
  initProjectFaq();
  initBookingLinks();
});

/** Keep the existing role-aware workspace destinations for signed-in users. */
async function initBookingLinks() {
  let user = typeof getUser === "function" ? getUser() : null;

  if (!user && typeof getSessionUser === "function") {
    user = await getSessionUser();
  }

  if (!user) return;

  const basePath = typeof getBasePath === "function" ? getBasePath() : "";
  const dashboardUrl = typeof getDashboardUrl === "function"
    ? getDashboardUrl(user.role) + (user.role === "patient" ? "#book" : "")
    : basePath + "pages/patient/dashboard.html";

  document.querySelectorAll(".service-link, .services-bottom-cta .btn-primary").forEach((link) => {
    const href = link.getAttribute("href") || "";
    if (href !== basePath + "pages/auth/login.html") return;

    link.setAttribute("href", dashboardUrl);
    link.textContent = link.classList.contains("service-link")
      ? "Open your workspace"
      : "Go to Dashboard";
  });
}

function initCapabilityFilters() {
  const filterButtons = Array.from(document.querySelectorAll(".filter-pill[data-filter]"));
  const cards = Array.from(document.querySelectorAll("#capability-grid .service-card[data-category]"));
  const status = document.getElementById("capability-status");

  if (!filterButtons.length || !cards.length) return;

  function applyFilter(category) {
    let visibleCount = 0;

    cards.forEach((card) => {
      const matches = category === "all" || card.dataset.category === category;
      card.hidden = !matches;
      if (matches) visibleCount += 1;
    });

    filterButtons.forEach((button) => {
      const isActive = button.dataset.filter === category;
      button.classList.toggle("active", isActive);
      button.setAttribute("aria-pressed", String(isActive));
    });

    if (status) {
      if (category === "all") {
        status.textContent = "Showing all " + visibleCount + " capabilities.";
      } else {
        status.textContent = "Showing " + visibleCount + " " + category + " " +
          (visibleCount === 1 ? "capability." : "capabilities.");
      }
    }
  }

  filterButtons.forEach((button) => {
    button.addEventListener("click", () => applyFilter(button.dataset.filter || "all"));
  });

  applyFilter("all");
}

function initProjectFaq() {
  const questions = Array.from(document.querySelectorAll(".faq-question[aria-controls]"));
  if (!questions.length) return;

  questions.forEach((question) => {
    question.addEventListener("click", () => {
      const panelId = question.getAttribute("aria-controls");
      const panel = panelId ? document.getElementById(panelId) : null;
      if (!panel) return;

      const shouldOpen = question.getAttribute("aria-expanded") !== "true";

      questions.forEach((otherQuestion) => {
        const otherPanelId = otherQuestion.getAttribute("aria-controls");
        const otherPanel = otherPanelId ? document.getElementById(otherPanelId) : null;
        const expanded = otherQuestion === question && shouldOpen;
        otherQuestion.setAttribute("aria-expanded", String(expanded));
        if (otherPanel) otherPanel.hidden = !expanded;
        otherQuestion.closest(".faq-card")?.classList.toggle("open", expanded);
      });
    });
  });
}
