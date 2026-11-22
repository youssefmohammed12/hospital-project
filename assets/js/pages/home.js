/**
 * Homepage controller.
 * Preserves the doctor-directory query contract and the read-only departments API.
 * Owns the homepage entrance and scroll-reveal choreography; shared page behavior
 * remains in core/main.js.
 */
"use strict";

document.addEventListener("DOMContentLoaded", () => {
  const searchInput = document.getElementById("hero-search");
  const searchSubmit = document.getElementById("hero-search-submit");

  const goToDoctors = () => {
    const query = searchInput?.value?.trim() || "";
    const destination = new URL(getBasePath() + "doctors.html", window.location.href);
    if (query) destination.searchParams.set("q", query);
    window.location.assign(destination.href);
  };

  searchInput?.addEventListener("keydown", (event) => {
    if (event.key !== "Enter") return;
    event.preventDefault();
    goToDoctors();
  });

  searchSubmit?.addEventListener("click", (event) => {
    event.preventDefault();
    goToDoctors();
  });

  const navMenu = document.getElementById("nav-menu");
  const navToggle = document.querySelector(".nav-toggle");
  if (navMenu && navToggle) {
    const syncNavState = () => {
      const expanded = navMenu.classList.contains("open");
      navToggle.setAttribute("aria-controls", "nav-menu");
      navToggle.setAttribute("aria-expanded", String(expanded));
      navToggle.setAttribute("aria-label", expanded ? "Close navigation menu" : "Open navigation menu");
    };

    syncNavState();
    navToggle.addEventListener("click", syncNavState);
    navMenu.querySelectorAll("a").forEach((link) => link.addEventListener("click", syncNavState));
  }

  loadDepartmentCards();
});

async function loadDepartmentCards() {
  const grid = document.getElementById("home-departments-grid");
  if (!grid) return;

  grid.setAttribute("aria-busy", "true");

  try {
    const response = await fetch(getBasePath() + "api/departments/get.php", {
      credentials: "same-origin",
    });
    if (!response.ok) throw new Error("Department request failed.");

    const result = await response.json();
    const departments = Array.isArray(result?.departments)
      ? result.departments
          .filter((department) =>
            department &&
            typeof department === "object" &&
            !Array.isArray(department) &&
            typeof department.name === "string" &&
            department.name.trim() !== ""
          )
          .map((department) => ({
            name: department.name.trim().slice(0, 100),
            description: typeof department.description === "string" && department.description.trim()
              ? department.description.trim().slice(0, 160)
              : "Explore listed doctors",
          }))
      : [];

    if (!result?.success || departments.length === 0) {
      grid.innerHTML = '<p class="dept-empty-state" role="status">Department examples are currently unavailable.</p>';
      return;
    }

    grid.innerHTML = departments.map((department, index) => {
      const query = new URLSearchParams({ q: department.name }).toString();
      const href = getBasePath() + "doctors.html?" + query;
      const number = String(index + 1).padStart(2, "0");

      return '<a class="dept-card fade-in-stagger-item" href="' + escapeHTML(href) + '">' +
        '<span class="dept-number" aria-hidden="true">' + number + '</span>' +
        '<span class="dept-copy"><strong>' + escapeHTML(department.name) + '</strong>' +
        '<small>' + escapeHTML(department.description) + '</small></span>' +
        '<span class="dept-arrow" aria-hidden="true">↗</span></a>';
    }).join("");
  } catch (error) {
    console.warn("Could not load department cards:", error);
    grid.innerHTML = '<p class="dept-empty-state" role="status">Department examples are currently unavailable.</p>';
  } finally {
    grid.setAttribute("aria-busy", "false");
  }
}

/**
 * Homepage-only, fail-open scroll reveal.
 * Hidden state is applied by JavaScript; CSS keeps content visible if this
 * controller does not run. Each staggered child is observed independently.
 */
function initHomepageReveal() {
  const reducedMotion = window.matchMedia?.("(prefers-reduced-motion: reduce)").matches;
  if (reducedMotion) return;

  const pendingElements = new Set();
  let observer = null;
  let checkQueued = false;

  const isInViewport = (element) => {
    const rect = element.getBoundingClientRect();
    return rect.width > 0 &&
      rect.height > 0 &&
      rect.bottom >= 0 &&
      rect.top <= window.innerHeight &&
      rect.right >= 0 &&
      rect.left <= window.innerWidth;
  };

  const reveal = (element) => {
    if (!element.isConnected || !element.classList.contains("home-reveal-pending")) {
      observer?.unobserve(element);
      return;
    }

    observer?.unobserve(element);
    element.classList.remove("home-reveal-pending");
    element.classList.add("home-reveal-enter");

    const finish = (event) => {
      // Directional variants use different keyframe names; all are one-shot.
      if (event.target !== element) return;
      element.classList.remove("home-reveal-enter");
      element.removeEventListener("animationend", finish);
    };
    element.addEventListener("animationend", finish);
  };

  if ("IntersectionObserver" in window) {
    observer = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) reveal(entry.target);
      });
    }, {
      root: null,
      // Trigger near the reading area, not at the very bottom edge.
      // This prevents lower sections from completing while the visitor is
      // still reading the hero on a tall display.
      rootMargin: "0px 0px -96px 0px",
      threshold: 0,
    });
  }

  const watch = (element) => {
    if (!(element instanceof HTMLElement) || pendingElements.has(element)) return;

    // Without an observer, fail open: keep the content visible and skip motion.
    if (!observer) return;

    pendingElements.add(element);
    element.classList.add("home-reveal-pending");

    // A restored mid-page position is handled immediately. On a first visit
    // at the top, the observer's inset bottom edge waits for a natural scroll.
    if (window.scrollY > 80 && isInViewport(element)) {
      reveal(element);
    } else {
      observer.observe(element);
    }
  };

  const forget = (element) => {
    if (!(element instanceof HTMLElement)) return;
    observer?.unobserve(element);
    pendingElements.delete(element);
    element.classList.remove("home-reveal-pending", "home-reveal-enter");
  };

  const isInRevealZone = (element) => {
    const rect = element.getBoundingClientRect();
    const revealBoundary = Math.max(0, window.innerHeight - 96);
    return rect.width > 0 &&
      rect.height > 0 &&
      rect.bottom > 0 &&
      rect.top < revealBoundary &&
      rect.right >= 0 &&
      rect.left <= window.innerWidth;
  };

  const checkPending = (forceVisible = false) => {
    pendingElements.forEach((element) => {
      if (!element.isConnected) {
        forget(element);
      } else if (
        element.classList.contains("home-reveal-pending") &&
        (forceVisible ? isInViewport(element) : isInRevealZone(element))
      ) {
        reveal(element);
      }
    });
  };

  let forceCheckQueued = false;
  const scheduleCheck = (forceVisible = false) => {
    forceCheckQueued = forceCheckQueued || forceVisible;
    if (checkQueued) return;

    checkQueued = true;
    requestAnimationFrame(() => {
      checkQueued = false;
      const shouldForce = forceCheckQueued;
      forceCheckQueued = false;
      checkPending(shouldForce);
    });
  };

  document.querySelectorAll(".home-page .fade-in").forEach((element) => {
    // The hero has a dedicated, ordered entrance and must not receive a second reveal.
    if (!element.closest(".home-hero")) watch(element);
  });

  document.querySelectorAll(".home-page .fade-in-stagger").forEach((container) => {
    if (container.closest(".home-hero")) return;
    Array.from(container.children).forEach(watch);
  });

  // Department cards are injected after the page loads; include new cards too.
  const departmentGrid = document.getElementById("home-departments-grid");
  if (departmentGrid && "MutationObserver" in window) {
    const gridObserver = new MutationObserver((records) => {
      records.forEach((record) => {
        record.removedNodes.forEach(forget);
        record.addedNodes.forEach(watch);
      });
      if (window.scrollY > 80) scheduleCheck(true);
    });
    gridObserver.observe(departmentGrid, { childList: true });
  }

  // Re-check restored positions only when the browser actually restored
  // a mid-page scroll. A first load at the top must not pre-play lower sections.
  const checkRestoredViewport = () => {
    if (window.scrollY > 80) scheduleCheck(true);
  };

  window.addEventListener("pageshow", checkRestoredViewport, { once: true });
  window.addEventListener("load", checkRestoredViewport, { once: true });
  window.addEventListener("scroll", () => scheduleCheck(), { passive: true });
  window.addEventListener("resize", () => scheduleCheck(true), { passive: true });
}

/**
 * Plays the hero introduction once, after the browser has restored its scroll
 * position. A reload or history restore in the middle of the page skips it.
 */
function initHomepageHeroEntrance() {
  const hero = document.querySelector(".home-hero");
  const imageNote = hero?.querySelector(".home-hero__image-note");
  const reducedMotion = window.matchMedia?.("(prefers-reduced-motion: reduce)");

  if (!hero || !imageNote || reducedMotion?.matches) return;

  const startEntrance = () => {
    window.requestAnimationFrame(() => {
      if (window.scrollY > 80 || reducedMotion?.matches) return;

      const finishEntrance = (event) => {
        if (event.target !== imageNote || event.animationName !== "homeHeroRevealIn") return;
        hero.classList.remove("home-hero--enter");
        imageNote.removeEventListener("animationend", finishEntrance);
      };

      imageNote.addEventListener("animationend", finishEntrance);
      hero.classList.add("home-hero--enter");
    });
  };

  // pageshow fires after the browser has restored its scroll position.
  window.addEventListener("pageshow", startEntrance, { once: true });

  reducedMotion?.addEventListener?.("change", (event) => {
    if (event.matches) hero.classList.remove("home-hero--enter");
  });
}

initHomepageHeroEntrance();
initHomepageReveal();