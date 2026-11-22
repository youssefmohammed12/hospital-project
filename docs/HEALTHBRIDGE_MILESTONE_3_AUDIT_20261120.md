# HealthBridge — Milestone 3.0 Discovery and Baseline Audit

**Date:** 20 November 2026  
**Status:** Discovery/baseline completed; UX architecture and design-system work can begin.  
**Full reproducible evidence:** `C:\xampp85\migration-backups\healthbridge\milestone3-audit-20261120\RESULT.md`  
**Owner-approved strategy:** `docs/HEALTHBRIDGE_MILESTONE_3_PLAN.md`

## 1. Executive outcome

HealthBridge has enough structure to support an ambitious visual/UX redesign without replacing the backend or forcing a frontend framework migration. The present frontend is PHP-served HTML with classic vanilla JavaScript, shared CSS, role-specific pages and existing APIs. It is not a React/Vue app and has no package/build manifest in the repository. The recommended path is to keep that model, build a cohesive design system, gradually improve component/module ownership and use native CSS/JavaScript where possible.

The largest near-term quality opportunities are:
- Mobile landing-page performance (local Lighthouse LCP 5.7 seconds; Performance 77).
- Accessibility defects across the three role dashboards, despite Lighthouse reporting 100 accessibility.
- Shared styles/scripts are over-included on some role pages and page controllers are large/global.
- The homepage hero's remote CSS background image is not discoverable in the initial HTML, and important third-party fonts/icons are in the critical request chain.
- Every secondary role page still needs responsive/accessibility coverage beyond the primary dashboards checked here.

No application code was edited in this discovery pass.

## 2. Page and frontend inventory

The current page tree contains 23 browser pages plus two HTML layout fragments:

| Area | Pages |
|---|---:|
| Public website | 5 |
| Authentication | 4 |
| Admin | 2 |
| Doctor | 2 |
| Patient | 10 |
| **Total browser pages** | **23** |
| Shared HTML fragments | 2 |

Source census (excluding `.git` and `vendor`):
- 25 HTML files including fragments.
- 24 CSS files, 562,923 source bytes.
- 36 JavaScript files, 997,231 source bytes.
- 192 PHP files.
- No application-owned image/font assets were found under `assets/`; 11 PNGs under `docs/screenshots` are documentation assets, not runtime assets.
- The HealthBridge root contains no `package.json`, Vite/Webpack configuration or `tsconfig.json`; `composer.json` is present.

CSS is split across a 46 KB global stylesheet, page styles, module styles, a single small component stylesheet and print styles. The `base/` and `layout/` directories are empty; `components/` only contains `modal-fix.css`. There are 66 media-query occurrences, 52 keyframe blocks, only two `prefers-reduced-motion` occurrences and 231 `!important` occurrences. These counts indicate cleanup opportunities but do not justify a blind rewrite.

The global stylesheet currently sets a dark navy/cyan identity, Sora typography, radial glow, an animated body entrance and a 30px-blur fixed navigation treatment. The visual system is spread between global tokens and role/page-specific CSS overrides; repeated shared CSS is linked on several pages.

JavaScript is structured into `core/`, `components/`, `modules/` and `pages/`, but pages currently load classic script files rather than ES modules. Shared functions are global. `layout.js` injects navbar/sidebar fragments by fetch and then re-runs global initialization hooks. The largest controllers are:
- `assets/js/pages/admin.js`: about 110 KB.
- `assets/js/pages/patient-emr.js`: about 110 KB.
- `assets/js/pages/doctor-workspace.js`: about 88 KB.
These are high-value caller/ownership mapping candidates, not candidates for automatic line-count splitting.

Representative page asset over-inclusion is visible in role portals: the Admin page loads 10 stylesheets and 12 scripts; the Doctor dashboard 8 stylesheets and 5 scripts; the Doctor workspace 9 stylesheets and 4 scripts; Patient dashboard 6 stylesheets and 3 scripts. This makes shared asset ownership, lazy loading and dependency scoping an important design-system concern.

Observed third-party frontend dependencies include Google Fonts Sora, Font Awesome 6.5.1, Chart.js 4.4.1 and Leaflet 1.9.4 on specific pages. Preserve Chart.js/Leaflet where existing functionality requires them; scope their downloads to routes that use them.

## 3. Current UX / visual direction

The existing application already has responsive CSS, light/dark theme selectors, skeleton states, navigation fragments, role-specific pages and some animation. The problem is not absence of styling; it is inconsistency and accumulated page-specific layers. Milestone 3 should not replace one generic template with another.

Explore a premium, calm healthcare direction:
- Deep teal/blue-green brand anchor with warm neutral surfaces.
- Strong typographic hierarchy, consistent density and spacing, refined borders/elevation.
- Semantic, accessible status colors and legible tables/forms.
- Shared navigation, page headers, cards, forms, filters, dialogs and feedback states.
- Role-specific layouts and task hierarchy within one visual language.
- Carefully restrained motion, with reduced-motion support and no decorative animation that slows or distracts.
The exact palette, typography and components remain design decisions for M3.1–M3.3, not pre-approved hard-coded tokens.

## 4. Responsive and accessibility baseline

The reproducible Chromium script checked 33 combinations: eight public/auth pages × three viewports, plus the Admin/Doctor/Patient primary dashboards × three viewports. Viewports were 390×844, 768×1024 and 1440×900.

- 33/33 page/viewport checks completed.
- 0 horizontal document-overflow cases.
- 0 uncaught page errors.
- The previously recorded 804px patient-dashboard overflow was not reproduced on the current primary patient dashboard at 390px. This does not close responsive coverage for the other 10 patient pages, Doctor workspace, Admin profile or all dynamic states.

The existing E2E landing/public/auth set ran 15 tests: 14 passed and one failed. The failure was the mobile dark-theme axe check: one action-card paragraph has a 4.4:1 contrast ratio against a 4.5:1 WCAG AA normal-text requirement. Lighthouse's accessibility category still reports 100.

The separate axe scan of the primary role dashboards found:
- Admin: low-contrast active-status badges; five quick-action accessible-name mismatches; two scrollable table regions without keyboard focus.
- Doctor: low-contrast queue avatars/badges/primary action/reason text; refresh button accessible-name mismatch; availability switch missing an accessible label/name (critical).
- Patient: low-contrast chips/status/primary CTA and multiple visible-label/accessibility-name mismatches.
Full selectors/samples are in `responsive-axe-baseline.json` and `RESULT.md`.

These are existing defects to resolve through the redesign and verify with axe plus keyboard/manual tests. Do not infer that a 100 Lighthouse accessibility score means no accessibility issues.

## 5. Performance baseline

One Lighthouse 13.6.0 local lab run per mode, at `http://127.0.0.1/optimized/`:

| Metric | Desktop | Mobile |
|---|---:|---:|
| Performance | 96 | 77 |
| Accessibility | 100 | 100 |
| Best Practices | 100 | 100 |
| SEO | 100 | 100 |
| FCP | 0.7 s | 2.1 s |
| LCP | 1.3 s | 5.7 s |
| Speed Index | 0.8 s | 2.2 s |
| TBT | 0 ms | 0 ms |
| CLS | 0.001 | 0 |
| Transfer size | 767 KiB | 767 KiB |

These single-run local lab values are directional, not field/p75 values. The LCP element is the homepage hero. Its background image is assigned in `assets/css/pages/home.css` from `images.unsplash.com`; Lighthouse reports that the LCP resource is not discoverable in the initial document and has no high fetch priority. The observed request chain includes Font Awesome CSS and regular/solid font files plus Google Fonts Sora. Lighthouse estimated unused CSS at 63 KiB and minification savings of 21 KiB CSS / 17 KiB JS.

Priority for subsequent performance work: verify the hero asset/network path, make the LCP resource discoverable and appropriately prioritized, remove duplicate/unused critical font/icon loads only after caller maps, and scope CSS/JS/dependencies by route. TBT and CLS are already strong in the current homepage lab run; don't spend time on those unless new profiling indicates a regression.

## 6. Runtime console / policy follow-up

The baseline script observed 21 console errors, with zero page errors. Three 401 responses appeared around auth/session transitions. Repeated CSP messages reported Google Fonts requests blocked by `connect-src 'self'` and inline style operations blocked by `style-src`. The current static source audit previously found no static/generated inline style attributes and no direct CSSOM writes, so source attribution is needed (possibly a library/runtime operation) before changing any security header. Do not relax the enforcing CSP just to hide console output.

## 7. Framework and library decision

**Decision: keep the PHP-rendered application and vanilla HTML/CSS/JavaScript for Milestone 3. No React/Vue rewrite and no new frontend dependency at this stage.**

Reasoning:
- The 23 pages are role/task-oriented server-served screens with established PHP APIs and session guards.
- Existing capabilities are already implemented; the current weaknesses are visual consistency, CSS/JS ownership, accessibility and asset scoping, not a demonstrated inability to express the UI.
- A framework migration would require build/runtime/deployment changes and broad regression of authentication, navigation, forms and role workflows without a measured product benefit.
- CSS transitions and native browser APIs are sufficient for the intended motion and shared patterns; an animation framework is not justified.
- Existing Chart.js/Leaflet functionality should be retained and loaded only where needed.

Preferred incremental architecture:
1. Keep PHP routes and endpoint contracts unchanged.
2. Create a token-driven design system in CSS and reusable semantic HTML patterns.
3. Introduce native ES modules for new shared UI components/page entry points in small reversible steps, after caller mapping and CSP checks. Classic global modules should not be converted all at once.
4. Consider Vite only if measured needs justify bundling, route-level code splitting, asset hashing or build-time validation. Treat it as a separate, versioned dependency/build decision with a documented rollback path.
5. Keep QA dependencies in `D:\dev-toolkit`, not the application manifest.

## 8. M3.0 exit and next action

M3.0 has produced the page/source inventory, representative CSS/JS and external dependency map, first UX/visual-system observations, desktop/mobile performance baseline, public/auth E2E outcome, primary-role responsive/axe baseline and framework decision.

Next: **M3.1 UX architecture**. Define information architecture and task journeys per role; map navigation/page hierarchy and recurring patterns; prioritize accessibility issues and route-specific asset dependencies; then create the design-system specification and a representative set of prototype screens before broad implementation.

No application source, databases, workers, hosted resources, .env, Git index or deployment target were changed in this audit.

## 10. Initial navigation and task map

This is the current route-level map from page markup and the existing accepted workflow evidence. M3.1 will expand it into step-by-step journeys, decision points, success/error/empty states, navigation transitions and responsive interaction requirements.

- **Public visitor:** Home → Doctors / Services / About / Contact → Login or registration. Public navigation is repeated in the public pages and the authenticated navbar fragment.
- **New account:** Register wizard → verification state/link → Login → role-specific landing page. Password recovery/reset is a separate authentication journey.
- **Patient:** Patient Dashboard → Book Appointment / Appointments; supporting destinations include Medical History, Prescriptions, Notifications, Downloads, Profile, Health Insights and Settings. Patient self-service tasks should be organized around upcoming care and clear next actions.
- **Doctor:** Doctor Dashboard → Dashboard / Patient Queue / Appointments / Schedule / Notifications / Reviews / Profile / Settings. Clinical tasks continue into the Doctor Workspace for patient/encounter review, visit notes, prescriptions and completion. The dashboard and workspace are separate page surfaces today.
- **Admin:** Admin Dashboard → Overview, Appointments, Doctors, Patients, Prescriptions, Departments, Support Messages, System Settings, Audit Log and Settings. The Admin UI is one large page with section/tab navigation.

M3.1 must validate task priority and route semantics with actual behavior/caller mapping; this route-level list is an orientation aid, not proof that every link, state or workflow has been tested.