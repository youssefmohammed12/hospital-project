/**
 * HealthBridge — Public V2 project capabilities.
 * Keeps workspace filtering, single-open FAQ behavior and server-session
 * aware destinations without depending on the legacy public-site runtime.
 */
"use strict";

document.addEventListener("DOMContentLoaded", () => {
  initCapabilityFilters();
  initProjectFaq();
  void initWorkspaceDestinations();
});

function initCapabilityFilters() {
  const buttons = Array.from(document.querySelectorAll(".filter-pill[data-filter]"));
  const cards = Array.from(document.querySelectorAll("#capability-grid .service-card[data-category]"));
  const status = document.getElementById("capability-status");

  if (!buttons.length || !cards.length) return;

  const roleLabels = {
    patient: "patient",
    clinician: "clinician",
    admin: "administration",
  };

  const applyFilter = (requestedCategory) => {
    const category = ["all", "patient", "clinician", "admin"].includes(requestedCategory)
      ? requestedCategory
      : "all";
    let visibleCount = 0;

    cards.forEach((card) => {
      const matches = category === "all" || card.dataset.category === category;
      card.hidden = !matches;
      if (matches) visibleCount += 1;
    });

    buttons.forEach((button) => {
      const active = button.dataset.filter === category;
      button.classList.toggle("is-active", active);
      button.setAttribute("aria-pressed", String(active));
    });

    if (!status) return;

    if (category === "all") {
      status.textContent = "Showing all " + visibleCount + " capabilities.";
      return;
    }

    status.textContent = "Showing " + visibleCount + " " +
      (roleLabels[category] || category) + " " +
      (visibleCount === 1 ? "capability." : "capabilities.");
  };

  buttons.forEach((button) => {
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
        otherQuestion.closest(".faq-card")?.classList.toggle("is-open", expanded);
      });
    });
  });
}

/**
 * The former Services page checked the local identity first, then queried
 * current_user.php when there was no cached user. Keep that same-origin GET
 * fallback so an active server session still gets role-specific destinations.
 */
async function resolveWorkspaceUser() {
  const cached = typeof getUser === "function" ? getUser() : null;
  if (isSupportedWorkspaceUser(cached)) return cached;

  const basePath = typeof getBasePath === "function" ? getBasePath() : "";
  try {
    const response = await fetch(basePath + "api/auth/current_user.php", {
      method: "GET",
      credentials: "same-origin",
      headers: { Accept: "application/json" },
    });

    if (!response.ok) {
      clearCachedWorkspaceUser();
      return null;
    }

    const result = await response.json();
    if (!result?.success || !isSupportedWorkspaceUser(result.user)) {
      clearCachedWorkspaceUser();
      return null;
    }

    // Match the previous getSessionUser helper: keep the canonical session
    // identity in local storage so the public shell and page links stay aligned.
    try {
      localStorage.setItem("hb_user", JSON.stringify(result.user));
    } catch {
      // The current route can still use the verified session identity even
      // when browser storage is unavailable.
    }
    return result.user;
  } catch {
    return null;
  }
}

function clearCachedWorkspaceUser() {
  try {
    localStorage.removeItem("hb_user");
  } catch {
    // The guest state is still respected for this page view.
  }
}

function isSupportedWorkspaceUser(user) {
  return Boolean(
    user &&
    typeof user === "object" &&
    !Array.isArray(user) &&
    ["patient", "doctor", "admin"].includes(user.role)
  );
}

function getWorkspaceDestination(role) {
  if (typeof getDashboardUrl === "function") {
    const dashboard = getDashboardUrl(role);
    return role === "patient" ? dashboard + "#book" : dashboard;
  }

  const basePath = typeof getBasePath === "function" ? getBasePath() : "";
  const routes = {
    patient: "pages/patient/dashboard.html#book",
    doctor: "pages/doctor/doctor-dashboard.html",
    admin: "pages/admin/admin.html",
  };
  return basePath + (routes[role] || routes.patient);
}

async function initWorkspaceDestinations() {
  const links = Array.from(document.querySelectorAll("[data-workspace-link]"));
  if (!links.length) return;

  const user = await resolveWorkspaceUser();
  if (!isSupportedWorkspaceUser(user)) return;

  const basePath = typeof getBasePath === "function" ? getBasePath() : "";
  const loginPath = basePath + "pages/auth/login.html";
  const destination = getWorkspaceDestination(user.role);

  links.forEach((link) => {
    if (link.getAttribute("href") !== loginPath) return;

    link.href = destination;

    if (link.classList.contains("services-text-link")) {
      const label = "Go to your workspace";
      const textNode = Array.from(link.childNodes).find((node) => node.nodeType === Node.TEXT_NODE);
      if (textNode) {
        textNode.textContent = label + " ";
      } else {
        link.prepend(document.createTextNode(label + " "));
      }
      link.setAttribute("aria-label", label);
      return;
    }

    const label = link.classList.contains("service-link")
      ? "Open your workspace"
      : "Go to Dashboard";
    link.textContent = label;
    link.setAttribute("aria-label", label);
  });
}
