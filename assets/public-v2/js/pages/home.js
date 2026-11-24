/**
 * Homepage controller.
 * Preserves the doctor-directory query contract and the read-only departments API.
 * Owns the homepage department feed and marks targets for shared scroll reveals.
 * Shared theme, navigation, authentication helpers and toast feedback live in
 * the standalone Public V2 runtime at ../core/site.js.
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

  initHeroPhotoRotation();
  loadDepartmentCards();
});

function initHeroPhotoRotation() {
  const hero = document.querySelector(".home-hero");
  const photoStage = hero?.querySelector(".home-hero__photo");
  const photos = Array.from(photoStage?.querySelectorAll("img[data-hero-photo]") || []);
  const controls = Array.from(hero?.querySelectorAll("[data-hero-photo-choice]") || []);

  if (!hero || photos.length !== 3 || controls.length !== 3) return;

  const motionPreference = window.matchMedia?.("(prefers-reduced-motion: reduce)");
  const pendingLoads = new WeakMap();
  let activeIndex = Math.max(0, photos.findIndex((photo) => photo.classList.contains("is-visible")));
  let manuallyStopped = false;
  let pointerInside = hero.matches(":hover");
  let focusInside = hero.contains(document.activeElement);
  let timer = null;

  const clearRotationTimer = () => {
    if (timer !== null) {
      window.clearTimeout(timer);
      timer = null;
    }
  };

  const canAutoAdvance = () =>
    !manuallyStopped &&
    !motionPreference?.matches &&
    !pointerInside &&
    !focusInside;

  const ensurePhotoLoaded = (photo) => {
    if (photo.complete && photo.naturalWidth > 0) return Promise.resolve(true);

    const existing = pendingLoads.get(photo);
    if (existing) return existing;

    const promise = new Promise((resolve) => {
      let settled = false;

      const finish = (loaded) => {
        if (settled) return;
        settled = true;
        photo.removeEventListener("load", onLoad);
        photo.removeEventListener("error", onError);
        resolve(loaded);
      };

      const onLoad = () => finish(photo.naturalWidth > 0);
      const onError = () => finish(false);

      photo.addEventListener("load", onLoad, { once: true });
      photo.addEventListener("error", onError, { once: true });

      if (!photo.getAttribute("src") && photo.dataset.src) {
        photo.src = photo.dataset.src;
        delete photo.dataset.src;
      }

      if (photo.complete) finish(photo.naturalWidth > 0);
    });

    pendingLoads.set(photo, promise);
    return promise;
  };

  const updateVisiblePhoto = () => {
    photos.forEach((photo, index) => {
      const isActive = index === activeIndex;
      photo.classList.toggle("is-visible", isActive);

      if (isActive) photo.removeAttribute("aria-hidden");
      else photo.setAttribute("aria-hidden", "true");
    });

    controls.forEach((control) => {
      const isActive = control.dataset.heroPhotoChoice === photos[activeIndex].dataset.heroPhoto;
      control.classList.toggle("is-active", isActive);
      control.setAttribute("aria-pressed", String(isActive));
    });
  };

  const showPhoto = async (index, automatic = false) => {
    if (index < 0 || index >= photos.length) return false;

    const loaded = await ensurePhotoLoaded(photos[index]);
    if (!loaded || (automatic && !canAutoAdvance())) return false;

    activeIndex = index;
    updateVisiblePhoto();
    return true;
  };

  const scheduleNextPhoto = (delay = 7000) => {
    clearRotationTimer();
    if (!canAutoAdvance()) return;

    timer = window.setTimeout(async () => {
      timer = null;
      if (!canAutoAdvance()) return;

      const nextIndex = (activeIndex + 1) % photos.length;
      const shown = await showPhoto(nextIndex, true);

      if (!canAutoAdvance()) return;
      scheduleNextPhoto(shown ? 7000 : 1200);
    }, delay);
  };

  const syncPauseState = () => {
    if (pointerInside || focusInside) clearRotationTimer();
    else scheduleNextPhoto();
  };

  controls.forEach((control) => {
    control.addEventListener("click", () => {
      manuallyStopped = true;
      clearRotationTimer();

      const index = photos.findIndex(
        (photo) => photo.dataset.heroPhoto === control.dataset.heroPhotoChoice
      );
      if (index >= 0) void showPhoto(index);
    });
  });

  hero.addEventListener("pointerenter", () => {
    pointerInside = true;
    syncPauseState();
  });

  hero.addEventListener("pointerleave", () => {
    pointerInside = false;
    syncPauseState();
  });

  hero.addEventListener("focusin", () => {
    focusInside = true;
    syncPauseState();
  });

  hero.addEventListener("focusout", (event) => {
    focusInside = event.relatedTarget instanceof Node && hero.contains(event.relatedTarget);
    syncPauseState();
  });

  const handleMotionPreferenceChange = (event) => {
    if (event.matches) {
      clearRotationTimer();
      if (!manuallyStopped) void showPhoto(0);
    } else {
      scheduleNextPhoto();
    }
  };

  if (motionPreference?.addEventListener) {
    motionPreference.addEventListener("change", handleMotionPreferenceChange);
  } else {
    motionPreference?.addListener?.(handleMotionPreferenceChange);
  }

  updateVisiblePhoto();

  // Wait for the browser's first-contentful-paint signal before assigning
  // the lazy sources, keeping alternate role photos off the critical path.
  const loadAlternatesWhenIdle = () => {
    const loadAlternates = () => photos.slice(1).forEach((photo) => void ensurePhotoLoaded(photo));

    if (typeof window.requestIdleCallback === "function") {
      window.requestIdleCallback(loadAlternates, { timeout: 1600 });
    } else {
      window.setTimeout(loadAlternates, 250);
    }
  };

  if (performance.getEntriesByName("first-contentful-paint").length > 0) {
    loadAlternatesWhenIdle();
  } else if (typeof window.PerformanceObserver === "function") {
    let paintObserver;
    let fallbackTimer;

    try {
      paintObserver = new PerformanceObserver((list) => {
        const firstContentfulPaint = list.getEntries().some(
          (entry) => entry.name === "first-contentful-paint"
        );
        if (!firstContentfulPaint) return;

        paintObserver.disconnect();
        window.clearTimeout(fallbackTimer);
        loadAlternatesWhenIdle();
      });
      paintObserver.observe({ type: "paint", buffered: true });

      // Fail open in browsers that expose PerformanceObserver but no paint entry.
      fallbackTimer = window.setTimeout(() => {
        paintObserver.disconnect();
        loadAlternatesWhenIdle();
      }, 2200);
    } catch {
      window.setTimeout(loadAlternatesWhenIdle, 1000);
    }
  } else {
    window.setTimeout(loadAlternatesWhenIdle, 1000);
  }

  scheduleNextPhoto();
}

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

      return '<a class="dept-card fade-in-stagger-item" data-public-reveal="card" href="' + escapeHTML(href) + '">' +
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
 * Declare homepage reveal targets and their choreography. Public V2's shared
 * runtime owns IntersectionObserver, reduced-motion, cleanup and dynamic nodes.
 */
function prepareHomepageReveals() {
  const mark = (selector, variant = "rise") => {
    document.querySelectorAll(selector).forEach((element) => {
      element.dataset.publicReveal = variant;
    });
  };

  document.querySelectorAll(".home-page .fade-in").forEach((element) => {
    if (!element.closest(".home-hero")) element.dataset.publicReveal = "rise";
  });
  mark(".home-page .fade-in-stagger > *", "rise");
  mark(".home-page .home-section-intro, .home-page .home-demo-overview__copy", "left");
  mark(".home-page .home-capability-list > *, .home-page .home-demo-steps > *", "right");
}

prepareHomepageReveals();

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