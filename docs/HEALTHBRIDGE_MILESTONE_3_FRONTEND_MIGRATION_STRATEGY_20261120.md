# HealthBridge Milestone 3 — Frontend Migration Strategy

**Decision confirmed:** 20 November 2026  
**Status:** Approved execution direction for Milestone 3  
**Applies to:** Public website, authentication, patient portal, doctor workspace and admin area.

## Decision

Rebuild the **frontend presentation layer progressively, one page or tightly related page group at a time**. Do not attempt a wholesale refactor of every legacy frontend file before delivering the redesigned pages, and do not rewrite the entire application.

Keep the current PHP-rendered application model with vanilla JavaScript and CSS. Preserve the existing backend, PHP services, API endpoints and payload contracts, database schema/data, authentication and session behavior, authorization/RBAC, role boundaries, routes, booking rules and established business workflows.

The target is a clean, maintainable frontend with clear shared ownership and page-level code—not a new SPA and not a permanent layer of new overrides over the old implementation.

## Why this approach

The source inventory and initial live-page changes show that styling and behavior are distributed across global stylesheets, page stylesheets, large classic JavaScript controllers and shared markup. Existing selectors, cascade order, global functions and event handlers can make apparently small changes affect several pages. A sweeping cleanup would create broad regression risk before the replacement UI is proven; endlessly appending styles would preserve the same maintainability problem.

A progressive replacement gives each page a bounded migration, lets the new design be tested against real application data and workflows, and creates a safe point to remove the old page-specific implementation after its consumers have moved.

## Target architecture principles

1. **Keep the server-rendered application.** PHP remains responsible for page delivery and existing server-side behavior. Do not introduce React, Vue or a full client-side router for this milestone.
2. **One shared design foundation.** Maintain a coherent source of truth for design tokens, typography, global reset/base behavior, layout primitives, navigation and reusable components.
3. **Shared patterns have one owner.** Buttons, fields, dialogs, alerts, tables, cards, loading/empty/error states and common navigation should not be reimplemented inconsistently on each page.
4. **Page code owns page behavior.** Each migrated page should have a clear markup/style/interaction boundary. Page-specific CSS and JavaScript should not silently leak into unrelated routes.
5. **Modularize only after mapping.** Continue using vanilla JavaScript. Introduce native ES modules incrementally only after each page's script callers, global dependencies, event handlers and load order are mapped. No framework or new dependency is presumed.
6. **Do not preserve legacy code merely for its own sake.** Existing backend and workflow behavior must be preserved; obsolete presentation code does not. Remove replaced CSS/JS only when it is proven no longer needed.

The exact directory/file layout will be finalized during the shared architecture review before the page migration expands. Do not create parallel component systems or rename/move files speculatively.

## Page-by-page migration procedure

For each page or small related group:

1. **Inventory its boundary:** route and role access; PHP includes; CSS files and selector overlap; script load order; global variables/functions; inline handlers; DOM selectors; API endpoints and response shapes; navigation targets; and shared components used by other pages.
2. **Record the before-state:** capture exact backups of every source file that will be edited outside the webroot, record hashes, identify the current behavior and reuse valid existing QA evidence.
3. **Define the regression gate before editing:** specify the page's expected data loading, interactions, validation, navigation, role behavior and responsive/accessibility checks. Keep any mutating workflow out of the test unless separately authorized and safely isolated.
4. **Implement the new presentation cleanly:** use the shared design foundation and validated prototypes as references, but connect the real page to its existing APIs and server-side behavior. Do not replace real data with prototype placeholders.
5. **Replace rather than layer:** once the new page implementation is ready, remove or rewrite the superseded page-specific selectors and handlers. Avoid leaving an accumulating override block, duplicate event listener, dead branch or parallel old/new component implementation.
6. **Verify behavior and visual quality:** check expected API responses and payload compatibility, search/filter/sort/dialog/form/navigation behavior, keyboard focus, accessible names, axe findings, light/dark presentation where supported, and representative mobile/tablet/desktop widths. Check browser errors and unintended network/API activity.
7. **Retire confirmed obsolete code:** search all callers, selectors, imports/includes, inline references and dynamic usage before deleting a function, rule, asset or file. Static search alone is not enough for dynamically referenced behavior. Keep backups and record exactly what was removed.
8. **Close the page:** store the result and limitations in the QA evidence index and update the milestone/handoff status before moving to the next page group.

## CSS cleanup rules

- Avoid appending permanent override blocks to the end of already large stylesheets as the default implementation strategy.
- Keep tokens and truly shared primitives centralized; keep page composition and page-specific states with the page.
- Before removing or consolidating a selector, inspect every markup/page consumer, specificity, source order, media query, theme selector and state variant.
- Remove obsolete declarations/rules once the migrated page and any other consumers no longer rely on them.
- Prefer a small number of deliberate cascade layers/ownership boundaries over escalating specificity or repeated important-flag patches.
- Do not delete styles merely because one page's current viewport does not display them.

## JavaScript cleanup rules

- Map script inclusion order, global APIs, shared state, inline event handlers, delegated listeners, dynamic imports/includes and page-specific DOM dependencies before changing ownership.
- Preserve request methods, URLs, headers, payloads, response handling, CSRF/auth assumptions, role checks and state transitions.
- Avoid loading large page controllers globally when they are only needed on a specific route; make loading modular only when the caller map and regression coverage make that safe.
- Remove duplicate listeners, unreachable branches and apparently dead functions only after reference/dynamic-use checks and page regression.
- Do not split a large controller simply to achieve smaller files; split along verified functional ownership.

## Safety and rollback rules

- Keep the existing dirty worktree intact. Do not reset, clean, stash, switch branches, stage, commit, push or deploy without explicit authorization.
- Back up changed source files outside the webroot before editing; never overwrite unrelated user changes.
- Do not inspect .env values or secrets.
- Do not touch hosted resources, NOVA, unrelated projects or databases as part of the UI migration.
- No database mutations or real patient data for visual work. Use synthetic data and non-mutating previews for UI checks unless a separate isolated test is explicitly approved.
- Registration and notification workers remain disabled/deferred; do not run them or send email.
- If a migration fails its regression gate, restore only the affected page files from the exact backup or repair the specific regression; do not roll back unrelated work.

## Current execution checkpoint — 20 November 2026

- M3.0 inventory and baseline are complete; the evidence and framework decision are recorded in HEALTHBRIDGE_MILESTONE_3_AUDIT_20261120.md.
- Five M3.3 reference prototypes (Patient Dashboard, Doctor Dashboard, Admin Overview, Public Doctors Directory and Auth/Booking) have been validated with 62 zero-violation automated axe scans across the documented test combinations. Manual keyboard/assistive-technology review remains open.
- M3.4 has begun in the live local site: a provisional shared visual foundation was applied to assets/css/style.css. Controlled comparison evidence is stored under C:\\xampp85\\migration-backups\\healthbridge\\milestone3-foundation-20261120\\FOUNDATION_RESULT.md. This is a first foundation slice, not permission to keep appending permanent overrides or to lock the tokens.
- M3.5 first live integration: Public Doctors Directory passes 19/19 interactions, 10/10 zero-violation axe scans, 8/8 responsive/theme checks, three read-only APIs HTTP 200 and 32 doctor cards. Its page-owned CSS is 43.4% smaller after consolidation. Detailed result: C:\\xampp85\\migration-backups\\healthbridge\\milestone3-directory-20261120\\DOCTORS_PAGE_RESULT.md.
- M3.5 second public/auth integration group: homepage/public entry passes 16/16 interactions, 8/8 zero-violation axe scans, 8/8 responsive/theme checks, 9 live department links and three observed department GET responses HTTP 200. Login/register/reset/verify passes 27/27 interactions, 44/44 zero-violation axe scans across four routes, and 32/32 layout checks. Both audits record zero browser page exceptions, zero non-CSP console errors and zero POSTs. Result: docs/HEALTHBRIDGE_MILESTONE_3_AUTH_PUBLIC_ENTRY_RESULT_20261120.md; raw evidence and backups are in the dated milestone3-auth, milestone3-public-entry, milestone3-shared-script-cache and milestone3-signup-route folders.
- The home/auth styles are now page-owned and consolidated. Home.css decreased from 10,567 to 8,499 bytes (19.6%) and home.js from 7,289 to 3,873 bytes (46.9%). Auth.css decreased from 31,894 to 28,336 bytes (11.2%). The fictitious emergency phone, clinic details, insurance brands and testimonials were removed. Nine stale login.html#signup references were corrected; 22 main.js consumers were cache-bumped v6→v7 after 22/22 exact backup hash checks.
- The actual shared/page ownership and runtime dependencies are mapped in docs/HEALTHBRIDGE_MILESTONE_3_FRONTEND_ARCHITECTURE_REVIEW_20261120.md. Preserve classic script order: main.js owns shared helpers, modal baseline and the authenticated settings/notification loader. Auth business controllers, PHP/APIs, role/session and booking contracts were not changed. Existing global Google Fonts/inline-style CSP warnings remain; do not weaken CSP.
- Next page group: inspect and migrate remaining public informational pages (About, Services and Contact), correcting demo-truth/copy/routes and shared-shell consistency with focused regression. Then proceed to the Patient portal, Doctor workspace and Admin area. Before each group, capture backups, map CSS/JS callers and define the regression gate; after each group, replace old page-owned code and prove behavior before retiring assets.

## Acceptance rule

A page is considered migrated only when its new interface is integrated with the real application and its agreed route, data, interaction, authorization, responsive and accessibility regressions pass. A visually correct prototype alone is not an integrated page. A successful migration should leave less duplicate/obsolete frontend code—not another layer of it.

## 20 November 2026 — M3.5 About, Services and Contact redesign completed

This is the latest M3.5 checkpoint and supersedes the previous next-action note that listed About, Services and Contact as still outstanding. The three live public information pages have now been rebuilt as honest HealthBridge student-project demonstration pages:

- **About:** redesigned project story, student-project framing, design principles, synthetic role workspaces and project-navigation CTAs.
- **Services:** redesigned capability catalogue with six Patient/Clinician/Admin cards, accessible role filters, live result status, connected-workflow section and truthful project FAQ.
- **Contact:** redesigned project-feedback page. Removed fictional emergency phone, physical hospital address, clinic directory, unsupported medical-service claims, fabricated social links and the map to a fictional site. The existing contact form remains available for synthetic project feedback only.

The form retains its existing IDs/names and same-origin request contract. assets/js/pages/contact.js and api/settings/contact.php were not modified in this group. The API only stores contact messages; no email is sent by this endpoint. QA used a blank form submit solely to verify client-side required-field validation. **No POST was recorded.**

**Final automated evidence:**
- 3/3 routes HTTP 200.
- 24/24 axe WCAG 2.0/2.1 A/AA scans, zero violations.
- 24/24 responsive layouts at 320/390/768/1440 px in dark/light themes, zero horizontal overflow.
- Services: all six cards, four filters, filter status/aria states and FAQ state synchronized; four keyboard activation checks passed (Enter/Space for filters and FAQ).
- Contact: field contract preserved, empty validation passed, no submit; no map or Leaflet assets.
- Internal links: About 22, Services 25, Contact 18 anchors; seven unique internal targets checked per page, all HTTP 200.
- Zero browser exceptions, non-CSP console errors, local HTTP errors or POST requests. PHP syntax check for the existing contact endpoint passed.

Result: docs/HEALTHBRIDGE_MILESTONE_3_PUBLIC_INFORMATION_PAGES_RESULT_20261120.md.
Structured results: C:\xampp85\migration-backups\healthbridge\milestone3-public-info-20261120-1525\public-info-results.json.
Repeatable test scripts: D:\dev-toolkit\scripts\healthbridge-m3-public-info-audit.mjs, healthbridge-m3-public-info-link-audit.mjs, and healthbridge-m3-public-info-keyboard-audit.mjs.
Pre-edit rollback copies and SHA-256 manifest: C:\xampp85\migration-backups\healthbridge\milestone3-public-info-20261120-1525\.

No backend/API/auth/session/CSRF/RBAC/role/booking logic, database schema/data, SMTP, workers, secrets, hosted services or Git state was changed by this page group. No staging, commit, push or deployment occurred.

**Next M3.5 page group:** Patient portal. Re-map the page's routes, shared scripts, role checks and API contracts; back up each changed file; redesign the actual patient experience page by page and test synthetic/read-only flows only. Then continue with Doctor workspace and Admin area. M3.4 tokens remain provisional. Manual owner, keyboard-only and assistive-technology review remains open. Hosted P0*/P1 gates remain high-priority deployment blockers.

## Follow-up compatibility verification

A final contract review found that the previous Services controller also performed server-session synchronization when no local browser identity was present. That behavior was retained: the redesigned Services controller calls the existing getSessionUser helper when getUser returns no local identity, then changes the six capability links and bottom CTA to the role dashboard only when a user is returned.

- Isolated synthetic role-route audit passed for patient, doctor and admin. All six capability links and the bottom CTA point to the correct role dashboard; the patient route retains the existing #book fragment. These tests set only a synthetic hb_user value in disposable Playwright contexts and perform zero current-user requests and zero POSTs.
- In the unauthenticated public audit, the existing current_user.php GET was observed once and returned HTTP 401 as expected for a guest. The response and associated browser resource message are recorded separately in public-info-results.json; they are not classified as an unexpected application console/HTTP error.
- No authentication operation, sign-in or role-protected page access was performed.

Additional repeatable test: D:\dev-toolkit\scripts\healthbridge-m3-public-info-role-routing-audit.mjs
Keyboard test: D:\dev-toolkit\scripts\healthbridge-m3-public-info-keyboard-audit.mjs


## 20 November 2026 — Homepage motion implementation completed

The homepage now has its first page-level motion pass. The work is implemented in page-owned `assets/css/pages/home.css` and `assets/js/pages/home.js`; it does not add an animation dependency or alter shared `main.js` / `style.css`.

The pass includes an ordered hero entrance, one-shot section reveals, intentional stagger for grouped cards, transform-based navigation underline, restrained pathway hover, search focus and button press feedback. Mid-page scroll restoration skips the hero sequence; reveal content remains fail-open; reduced motion is honored; narrow-screen direction changes avoid horizontal overflow.

Automated verification passed: 8/8 section motion checks, hero sequence and cleanup, native mid-page refresh, reduced-motion and no-JS fallbacks, 12 responsive/theme combinations, 8 narrow-motion overflow checks, 12 zero-violation axe scans, and zero browser exceptions/non-CSP console errors. Full evidence and file list: `docs/HEALTHBRIDGE_MILESTONE_3_HOME_MOTION_RESULT_20261120.md`.

**Current gate:** pause for owner visual review of the animated homepage. Do not apply motion to the next page until the owner approves this page and explicitly says “next”.


## 20 November 2026 — Homepage motion timing correction

Following the owner's report that motion was not visible, headed Chrome testing at 1920×1080 found that the previous positive root margin and immediate initial-viewport reveal allowed pathway/capability animations to finish while the visitor was reading the hero. A mid-page scroll restoration also intentionally skips the offscreen hero entrance.

The homepage now observes sections with a 96px bottom reading-area inset; scroll events use the same reveal boundary. A restored mid-page `pageshow`/load still force-releases already-visible content immediately. Hero and section movement/duration have been increased slightly. Fail-open visibility and reduced-motion handling remain intact.

Final motion audit and headed Chrome 1920×1080 natural-entry test both pass; details: `docs/HEALTHBRIDGE_MILESTONE_3_HOME_MOTION_RESULT_20261120.md`, evidence folders `milestone3-home-motion-20261120` and `milestone3-home-motion-visibility-timing-fix-20261120`. Current homepage cache versions are CSS v12 / JS v10. Owner visual review remains the gate.


## 20 November 2026 — Contact Public V2 integration checkpoint

The Contact page's earlier public-information redesign is now integrated into the standalone Public V2 asset architecture. This supersedes the earlier Contact implementation note that described the legacy assets/css/pages/contact.css and assets/js/pages/contact.js as the active presentation/controller pair.

- New route assets: assets/public-v2/css/pages/contact.css and assets/public-v2/js/pages/contact.js; route HTML remains contact.html.
- The contact feedback form's field contract and same-origin POST payload to api/settings/contact.php are preserved. The route makes no request until a valid visitor submit. Automated submit tests were intercepted/mocked and performed zero real API/database writes.
- Public V2 shared runtime and canonical site footer are reused. No new framework or dependency was added.
- Acceptance evidence, exact file scope, route/link checks and pre-edit rollback location are recorded in docs/HEALTHBRIDGE_MILESTONE_3_CONTACT_PUBLIC_V2_RESULT_20261120.md.
- Old Contact CSS/JS files are unreferenced by active page markup and were intentionally left untouched because they were already modified in the dirty worktree before this migration. Do not delete them as part of unrelated cleanup.
- Next gate: owner visual review of Contact. Do not proceed to the Patient portal or another page until Contact is explicitly approved.


## 21 November 2026 — Mandatory Public V2 isolation gate

The owner's architectural requirement is explicit: a redesigned/migrated page must not retain any runtime connection to legacy frontend CSS or JavaScript. Public V2 is a progressive frontend replacement, not a compatibility skin over the old frontend.

For every page accepted as migrated:
1. Its linked CSS and JS assets must be owned by assets/public-v2/ only. It may use the shared Public V2 base/tokens/components/runtime/footer and route-owned V2 modules.
2. It must not load assets/css/style.css, assets/css/pages/*, assets/js/core/main.js, assets/js/core/auth.js, or any legacy page controller.
3. Functionality previously supplied by a legacy global script must be moved into the V2 shared runtime or page-owned V2 module before its script reference is removed.
4. Keep PHP/API contracts, auth/session/CSRF/RBAC behavior, database schema/data and business logic intact unless a separately approved backend task explicitly changes them.
5. Run a static legacy-path scan over the migrated HTML and V2 executable CSS/JS, followed by browser tests that inspect the assets actually loaded at runtime. A page is not marked migrated until both checks pass.
6. Do not delete a legacy shared file globally while any unmigrated route still calls it. Remove only the migrated page's dependency; retire shared legacy assets after the complete caller map and migration coverage justify it.

Applied to Home, About, Services, Contact, Doctors, Login and Register on 21 November 2026. Detailed evidence and remaining non-migrated auth routes are recorded in docs/HEALTHBRIDGE_MILESTONE_3_PUBLIC_V2_ISOLATION_REGISTER_WIZARD_20261121.md.