# HealthBridge Milestone 3 — Frontend Architecture Review

**Date:** 20 November 2026  
**Scope:** Read-only review of the current local PHP-rendered frontend structure, shared asset loading and the first migrated page.  
**Decision:** Preserve the current runtime and rebuild page presentation incrementally. This review is an ownership map for M3, not approval for a wholesale folder move or framework conversion.

## Executive finding

The current frontend is not a single coherent component system. It is a PHP/HTML-rendered application with a large global stylesheet, many page-specific stylesheets and classic JavaScript files that communicate through globals and fixed script order. There are reusable folders (core, components, modules, pages), but ownership is not consistently enforced: some page CSS contains old versions plus later overrides, and shared runtime code dynamically loads authenticated UI assets.

The efficient path is to establish explicit ownership boundaries, rebuild one page group at a time, and replace the old page-owned layer after regression. Do not move every file or split the entire JavaScript layer before the page contracts are mapped.

## 1. Observed source structure

### CSS

The current CSS tree contains:
- **style.css** — global stylesheet, approximately 2,549 lines at review time. It currently owns tokens, reset/base rules, shared navigation/footer, common controls, layout primitives, global modal overlay behavior and cross-site theme rules.
- **pages/** — page-owned stylesheets for public pages, auth, patient/doctor/admin pages and settings.
- **modules/** — domain-feature styles such as medical records, prescriptions, schedule and print prescription.
- **components/modal-fix.css** — a shared modal compatibility/fix stylesheet.
- **base/** and **layout/** — directories currently present but empty.

The boundaries are therefore partly implicit. A global file still contains many unrelated concerns, while page files may contain years of accumulated overrides. The Directory stylesheet was a concrete example: it had grown to 31,315 bytes with separate legacy and newer layers.

### JavaScript

The current JS tree contains:
- **core/main.js** — global helpers, theme/navigation/toast/back-to-top initialization, scroll effects, the modal system and authenticated shared-UI bootstrap. It is approximately 1,107 lines.
- **core/auth.js** — authentication-specific shared behavior.
- **components/layout.js** and **components/booking-wizard.js** — reusable layout and booking UI scripts.
- **modules/** — schedule, notifications, prescriptions, medical records and department/admin feature modules.
- **pages/** — page controllers, including nested patient and print page controllers.
- **pages/doctors.js** — the Doctors Directory controller.

Although the folders suggest modular ownership, this is currently a classic-script runtime. The inspected application pages load ordinary script tags; no type="module" entrypoint/import architecture was found. There is no application package.json/frontend build manifest in the audited baseline. Functions and data are intentionally shared through global scope and script execution order.

## 2. Shared runtime contracts that must be preserved

### Global bootstrap and modal behavior

main.js initializes shared behavior at DOMContentLoaded, including the modal system. Its modal system closes the top-most .modal-overlay.open on Escape and closes a dialog when the backdrop itself is clicked. The Doctors Directory's profile modal also needs to manage its own focus lifecycle. Its page-level Escape handling is registered in capture phase so it can restore focus to the initiating doctor button before the shared bubbling handler runs.

This is a demonstrated dependency, not a reason to rewrite the global modal manager during the Directory migration. Other page dialogs may depend on its current generic behavior.

### Authenticated settings and notifications

For an authenticated user, main.js:
1. ensures assets/css/pages/settings.css exists in the page;
2. loads assets/js/pages/settings.js if openSettings is not already defined;
3. loads assets/js/modules/notifications.js if NotificationService is not available;
4. initializes the shared notification service/dropdown.

The loading path enforces same-origin script URLs and has explicit load-failure handling. The Directory currently includes settings.css statically, while the global runtime also has a missing-style fallback. Do not remove this include or change the loader as incidental cleanup; authenticated navigation, settings and notification interactions need their own focused regression.

### Global page helpers and fallback data

The Directory controller depends on utilities from main.js (for example getBasePath, getUser, showToast and escapeHTML) and on the shared DOCTORS_FALLBACK data defined by that same classic script. The order in doctors.html is deliberate: global main.js loads before page-specific doctors.js.

Do not defer, rename, convert to module scope or remove global declarations until every caller and load-order dependency has been mapped. The global file should eventually be decomposed, but not as a prerequisite for each page rebuild.

## 3. Doctors Directory asset and behavior ownership

Current page load order:
1. assets/css/style.css — shared foundation and shell.
2. Font Awesome stylesheet.
3. Google Sora stylesheet link.
4. assets/css/pages/doctors.css — page-owned visual layer.
5. assets/css/pages/settings.css — shared authenticated settings panel styling.
6. assets/js/core/main.js — global bootstrap, helpers and shared modules.
7. assets/js/pages/doctors.js — Directory data, interaction and profile dialog.

The global stylesheet also contains a Sora @import, duplicating the page's explicit Google Fonts link. The browser reports CSP-blocked external font connection and existing inline-style warnings. These are recorded for a later shared asset/CSP/performance review. Do not weaken the Content Security Policy to suppress the warnings.

### Page-owned responsibilities

doctors.html owns the Directory page structure and accessible labels. doctors.css owns its hero refinements, trust strip, department-chip rail, search/sort layout, result cards, availability presentation, how-it-works section, booking CTA and doctor-profile dialog content. doctors.js owns the page's three read endpoints, response normalization, search, department filtering, sorting, card rendering, profile detail and existing role-aware booking navigation.

### API and business boundaries

The live page reads:
- api/departments/get.php
- api/doctors/get.php
- api/doctors/get-public-availability.php

Department identity remains based on the validated numeric department_id; the display name uses the API's department_name, then the loaded department map, then the legacy specialty display fallback. Missing ratings remain neutral ("No ratings yet") instead of displaying a fabricated zero-star score.

Search, department filter, existing sort options, doctor profile and the role-aware booking redirects are existing behaviors. Do not replace live records with prototype examples or change routes/API payloads/availability rules as part of visual cleanup.

## 4. Target ownership model for the progressive rebuild

Use the following boundaries for new/rebuilt code. This describes the destination architecture; it does not require moving every current file immediately.

| Layer | Owns | Must not absorb |
|---|---|---|
| Global CSS foundation | Tokens, reset, base typography, global shell, universal controls and stable shared primitives | Page-specific section/card/modal variants |
| Shared layout/components | Navigation/footer, accessible dialog primitives, buttons/inputs, toast/notification surface and repeated layout patterns | Page data, page-specific filtering or booking rules |
| Domain modules | Reusable schedule, medical-record, prescription and booking UI behavior | Role permissions or domain rules copied into presentation code |
| Page-owned HTML/CSS/JS | One route's structure, visual composition, data presentation and event wiring | Duplicated global components or server/business logic |

Keep a page stylesheet loaded after the global foundation. Within it, scope page styles to that route's root or page-specific classes; avoid generic selectors that leak into other routes. A shared pattern should be extracted only after at least two real consumers have been inspected, not because a prototype happens to contain a similar card.

For JavaScript, preserve classic global scripts during the current migration sequence. Native ES modules can be considered later, after the global namespace, script order, dynamic loaders, inline handlers and cross-page callers are mapped and the build/hosting implications are documented. Do not add a bundler or frontend dependency without a measured need.

## 5. CSS/JS retirement rules

For every page migration:
1. Capture exact pre-edit copies and hashes outside the webroot.
2. Inventory the page's HTML classes/IDs, stylesheet order, script order, API calls, global helpers and navigation targets.
3. Mark selectors/functions as confirmed-live, candidate-unused or unknown. Static search alone is not enough when selectors are built dynamically or used by inline scripts.
4. Replace the page-owned source with a cohesive implementation; do not leave a new override layer at the end of the old file.
5. Remove a candidate only after repository-wide and dynamic-caller checks, and after focused regression passes.
6. Re-run the same page tests after cleanup. Preserve the previous file copy and test evidence for rollback.
7. Record unresolved global debt (for example duplicate font loading) separately rather than fixing unrelated app-wide issues during a page migration.

## 6. First application of this model — Doctors Directory

The previous Directory CSS had grown to 31,315 bytes. It has now been rewritten as one cohesive page-owned stylesheet of 17,718 bytes (43.4% smaller), rather than retaining old rules and appending another override block. The rewrite keeps the shared global token/shell layer in style.css and scopes Directory layout, cards, filters and dialog content to the page.

Confirmed orphan rules for .doctor-info-banner and .avail-dot were removed after repository-wide HTML/JS/PHP/CSS searches found no consumers. The unused local activeDepartmentName variable was also removed. The existing department map is now used as a display-name fallback, preserving department_id as the canonical identifier. No global controller or backend was refactored.

Source backups:
- Initial page-migration copies: C:\xampp85\migration-backups\healthbridge\milestone3-directory-20261120\source-before\.
- Exact pre-consolidation Directory stylesheet: C:\xampp85\migration-backups\healthbridge\milestone3-directory-20261120\source-before-consolidation\doctors.css.before-consolidation.

## 7. Next architecture application order

Proceed in small page groups, in this order:
1. Shared public shell and authentication/booking pages.
2. Patient portal and patient subpages.
3. Doctor dashboard/workspace.
4. Admin overview and admin management pages.
5. Shared accessibility/performance consolidation after representative pages are migrated.

Before each group, map its route/role/API/global dependencies and back up its source. After each group, run the relevant route, responsive, accessibility and actual-interaction tests. Do not start broad asset deletion or global JS decomposition until the full caller map and regression coverage justify it.

## 8. Explicit out-of-scope items

This review does not authorize database changes, PHP/API/auth/session/RBAC/booking modifications, SMTP/worker actions, hosted-resource access, deployment, .env inspection, Git staging/commit/push, framework migration, or broad deletion of old files. Hosted P0*/P1 release gates remain open. Automated axe checks do not replace manual keyboard, zoom/reflow or assistive-technology review.
