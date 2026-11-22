# HealthBridge Public V2 Assets

This is the standalone asset layer for redesigned public pages.

## Structure

- `css/tokens.css` â€” shared semantic design tokens.
- `css/base.css` â€” reset, typography, focus, and motion baseline.
- `css/components.css` â€” shared navigation, brand mark, buttons, toasts, and reveal primitives.
- `css/footer.css` â€” the single shared public-footer presentation used by every opted-in route.
- `css/pages/<page>.css` â€” page-only presentation.
- `js/core/site.js` â€” public navigation, theme, auth navigation/logout, toast, and shared helper contract.
- `js/core/footer.js` â€” secure same-origin loader for the canonical footer fragment.
- `js/pages/<page>.js` â€” page-specific behavior and API reads.
- Canonical footer markup lives at includes/fragments/footer.html (outside this asset folder).

## Shared footer opt-in

Where a page should display the public footer, add one <div data-shared-footer aria-busy="true"></div> at the footer position, then load css/footer.css and defer js/core/footer.js. The loader resolves the application root from its own script URL, fetches includes/fragments/footer.html from the same origin, validates the response, and resolves every footer route against that root. Do not copy footer markup into individual pages or create page-specific footer styles.

## New-page rule

Every public page redesigned from this point forward must consume the Public V2 foundation and put all page-specific CSS/JS in this folder. Do not link:

- `assets/css/style.css`
- `assets/css/pages/settings.css`
- `assets/js/core/main.js`
- legacy `assets/js/pages/*.js` controllers
- legacy icon/font CDN stylesheets

Keep route HTML in its current location unless a separately reviewed routing change is approved. This preserves current links, query strings and relative API routes.

## Script loading

Load `js/core/site.js` synchronously in the head (it applies the saved theme before first paint and registers DOM-ready setup). Load the page controller with `defer` before the closing body tag.

Load the four stylesheets in this order: tokens, base, components, page-specific.

## Compatibility

The Public V2 runtime exposes classic-script globals for page controllers: `getBasePath`, `getUser`, `getDashboardUrl`, `escapeHTML`, `safePositiveId`, `safeCount`, `debounce`, and `showToast`. Do not duplicate these helpers in page files.

Legacy assets remain in the repository for pages not migrated yet. Do not delete or change them as part of a Public V2 page task.

## Shared navigation, brand and motion

- The shared navigation component owns the active-link underline, link spacing, mobile dropdown layout, toggle visibility, and menu surface. Page CSS should only set page-specific nav colors/spacing when genuinely different.
- The `.logo`, `.footer-logo`, `.home-brand-mark`, and `.home-brand-name` styles are shared. Keep the mark in the shared component layer so its size and alignment do not drift between pages.
- `site.js` owns one IntersectionObserver-based scroll-reveal runtime. Page controllers only mark elements with `data-public-reveal` values: `rise`, `left`, `right`, or `card`. Dynamically inserted cards are observed automatically.
- The shared reveal runtime is fail-open: reduced-motion users and browsers without IntersectionObserver receive visible content without waiting on animation. On narrow screens, directional reveals become vertical to avoid lateral movement near the viewport edge.
- Keep page-specific hero choreography in the page controller/CSS where the motion is unique. Do not recreate the shared scroll observer or generic reveal keyframes inside a page.


## Contact page â€” Public V2

contact.html is now a migrated Public V2 route. It uses css/pages/contact.css and js/pages/contact.js; it preserves the existing feedback form field contract and sends the same same-origin JSON POST to api/settings/contact.php only after the visitor submits valid data. The page is explicitly project-feedback only, uses synthetic details, does not promise email delivery, and has no map/Leaflet or external font/icon dependency. It consumes the canonical shared footer and the shared site.js theme/navigation/reveal runtime.

The old assets/css/pages/contact.css and assets/js/pages/contact.js are not referenced by active markup. They remain unchanged because they were already modified in the existing worktree; retire them only in a separately reviewed cleanup after confirming the working-tree ownership.

Contact QA is documented in docs/HEALTHBRIDGE_MILESTONE_3_CONTACT_PUBLIC_V2_RESULT_20261120.md and docs/HEALTHBRIDGE_QA_EVIDENCE_INDEX.md.


## About page â€” Public V2

`about.html` is now a migrated Public V2 route. It uses `css/pages/about.css`, the shared `js/core/site.js` runtime and canonical footer loader/styles. Its content is static, so no `js/pages/about.js` controller is required; the 15 `data-public-reveal` markers are handled by the single shared reveal observer. It loads no legacy stylesheet/controller, Font Awesome, Google Fonts or other external font/icon asset. The old `assets/css/pages/about.css` file is retained unchanged for worktree safety and is no longer referenced by active About markup.

About migration QA and rollback evidence are documented in `docs/HEALTHBRIDGE_MILESTONE_3_ABOUT_PUBLIC_V2_RESULT_20261121.md` and `docs/HEALTHBRIDGE_QA_EVIDENCE_INDEX.md`.


## Shared public signal strip

Use the shared three-column information strip for short, non-interactive project facts/highlights across Public V2 routes. The component lives in `css/components.css` and uses these classes:

- `.public-signal-strip` â€” shared themed band and top/bottom border.
- `.public-signal-strip__grid` â€” equal three-column desktop layout; automatically stacks at 768px and below.
- `.public-signal` â€” consistent item alignment, gap, padding and separators.
- `.public-signal__value` â€” shared serif number; use `.public-signal__value--label` for a short text label such as `DEMO`.
- `.public-signal__copy`, `.public-signal__title`, `.public-signal__detail` â€” fixed typography, spacing and line-height for the copy.

Page markup may set its content container width and wording only. Do not re-declare or override signal-strip font families, font sizes, weights, line heights, gaps, padding, borders, backgrounds or responsive stacking in page CSS. The current migrated uses are Doctors, About and Contact. Home's three pathway cards are interactive links with a separate card role, so they intentionally keep their own layout.


## Home hero role-photo rotation

The Home hero reuses the original Patient photo and adds local Doctor/Admin Pexels assets at the same 1600Ã—1067 source ratio. Its existing role pills are accessible pressed-state buttons. `js/pages/home.js` owns the seven-second rotation, manual-stop behavior, hover/focus pause, reduced-motion handling, and post-first-contentful-paint deferred loading. `css/pages/home.css` provides opacity-only transitions; do not add transforms/slides or alter the shared hero copy/layout as part of photo updates.


## Services page â€” Public V2

`services.html` is now migrated to the Public V2 foundation. It uses `css/pages/services.css` and `js/pages/services.js`, shared tokens/base/components, the shared `site.js` theme/navigation/reveal runtime, and the canonical footer loader. Its controller owns the accessible workspace filters, single-open FAQ interaction and the existing same-origin `current_user.php` session fallback for role-specific workspace destinations. The legacy Services CSS/controller remain in the repository unchanged and are no longer referenced by active Services markup. Migration and focused QA are documented in `docs/HEALTHBRIDGE_MILESTONE_3_SERVICES_PUBLIC_V2_RESULT_20261121.md`.


Services role-session compatibility: when no local user exists, the Services controller calls the existing same-origin current-user GET. It caches a valid canonical response in \`hb_user\`, and removes stale \`hb_user\` when the endpoint says the visitor is unauthenticated or returns an invalid role, matching the former \`getSessionUser()\` storage contract.

## Services owner-review refinement â€” 21 November 2026

The Services overview keeps its raised `Different permissions, connected workflows` note attached to the card edge. Its reserved lower inset protects the example-workflows disclaimer; measured note overlap/clearance is 44px/45px desktop and 38px/39px mobile. All five Services section-content transitions are calibrated to 65â€“90px visible gaps.

The Services role-aware destination handler covers all eight workspace CTAs, including the hero link. Patient destination `#book` now resolves to the existing Book Appointment button (`id="book"`); no dashboard booking behavior is added. Guest links stay on the shared sign-in page until the identity is resolved.

See `docs/HEALTHBRIDGE_MILESTONE_3_SERVICES_PUBLIC_V2_RESULT_20261121.md` for the implementation and QA measurements.

## Services hero shared-workflow diagram â€” 21 November 2026

The Services hero overview uses a static connected-system map instead of repeating the three workspace names as text rows. Patient portal, Doctor workspace and Admin console connect to a central **Shared system** hub. Flow captions: â€œBooks appointmentâ€, â€œAppears in doctor queueâ€, and â€œOversees departments, settings + audit trailâ€. Desktop uses a horizontal fan-in; at 1150px and below the map becomes a vertical connected sequence ending at the hub. It uses the existing page icon style, hairline connectors, brass numerals and labels; no animation or JavaScript is involved.

Keep the deep-green overview shell, HEALTHBRIDGE/LOCAL DEMO header, WORKSPACE OVERVIEW label, headline, example-workflows disclaimer and the floating â€œDifferent permissions, connected workflows.â€ callout intact when iterating on this component. The callout must remain attached to the lower-right card edge without covering diagram content or the disclaimer. The active stylesheet reference is assets/public-v2/css/pages/services.css?v=4.

Implementation and focused QA are recorded in docs/HEALTHBRIDGE_MILESTONE_3_SERVICES_PUBLIC_V2_RESULT_20261121.md; raw geometry, spacing and full-page audit evidence is under C:\xampp85\migration-backups\healthbridge\services-hero-shared-workflow-20261121\qa\.

## Services hero connector alignment and motion â€” 21 November 2026

The Services hero map uses two verified layouts. Above 1240px, three role rows connect horizontally to three input ports on the Shared system hub; the ports use CSS Grid subgrid rows so each arrow lands at the matching port. At 1240px and below, each role and short flow caption forms a vertical sequence, with a downward arrow landing at the next role and finally the central hub.

A small CSS-only pulse is enabled: three staggered hub-port halo pulses on desktop, and sequential downward-arrow opacity pulses on tablet/phone. The 3.6-second sequence uses only transform and opacity; no JavaScript, timers, dependency or external resource is involved. prefers-reduced-motion disables the pulse and retains static visible connectors.

Current stylesheet cache reference: assets/public-v2/css/pages/services.css?v=5. Focused geometry, full Services regression, spacing and before/after performance results are documented in docs/HEALTHBRIDGE_MILESTONE_3_SERVICES_PUBLIC_V2_RESULT_20261121.md, with raw evidence under C:\xampp85\migration-backups\healthbridge\services-hero-arrows-animation-20261121\qa\.


## Services hero owner-directed diagram revision - 21 November 2026

This revision supersedes the previous Services hero connector/halo description above. Desktop connector arrowheads have been removed while retaining the three hub ring dots and horizontal lines into them. The phone/tablet arrows are replaced by one CSS Grid-spanning vertical spine centred on the role stack, with ring dots at role-card edges and one final arrowhead into the Shared system hub. Captions remain beside/below their cards.

A filled 9px gold signal with a soft glow travels along each desktop connector, staggered at 0s / 1.2s / 2.4s on a 3.6-second cycle; phone/tablet uses one travelling dot down the spine. Arrival has a stronger scale pulse. Only transform/opacity animate; reduced-motion leaves the complete diagram static. No JavaScript, timer, dependency or external resource was added.

Current stylesheet cache reference: `assets/public-v2/css/pages/services.css?v=6`. Result and screenshot record: `docs/HEALTHBRIDGE_MILESTONE_3_SERVICES_HERO_OWNER_FIX_20261121.md`. Four final desktop/phone x light/dark screenshots and raw QA/performance evidence are under `C:\xampp85\migration-backups\healthbridge\services-hero-owner-fix-20261121\qa\`.


### Services hero four-second signal / desktop caption adjustment - 21 November 2026

Current cache reference: `assets/public-v2/css/pages/services.css?v=7`. Travelling signals and arrival pulses now run on a 4-second cycle, with desktop offsets 0s / 1.333s / 2.666s. Desktop horizontal connectors now remain fully visible through the complete gap; captions sit below the line, clear of the signal path. Phone/tablet keeps the one centred vertical spine and its under-card captions. Reduced-motion behavior is unchanged. No tests were rerun for this small owner-requested adjustment.


### Services hero desktop caption font adjustment - 21 November 2026

Desktop flow captions beneath the horizontal connector lines now use `0.55rem` (previously `0.5rem`) for slightly better readability. Phone/tablet caption sizes and the 4-second signal timing are unchanged. Cache reference remains `services.css?v=7`. No tests rerun by owner request.


## About hero build-journey timeline â€” 21 November 2026

The About hero card now tells the project's story in a three-stop vertical timeline rather than repeating the Services workspace list. Copy is tied to project records: The foundation (three connected workspaces); Milestone 2, local security/reliability hardening and the V2 database redesign, completed 20 Nov 2026; Milestone 3, the Public V2 page-by-page rebuild, in progress since 20 Nov 2026. No claim is made that the V2 redesign schema is already deployed.

The 1px brass spine has three ring markers and a filled gold signal that travels down it on a 4-second CSS-only cycle, pausing at each stop with arrival pulses. Motion is transform/opacity only; reduced motion retains the static timeline. Both themes reuse About page tokens. The existing card shell/header, â€œDifferent roles. Connected workflows.â€ heading, synthetic-record footer note and closing caption are preserved. Cache reference: `assets/public-v2/css/pages/about.css?v=6`.

Result and evidence: `docs/HEALTHBRIDGE_MILESTONE_3_ABOUT_HERO_BUILD_JOURNEY_20261121.md`; four desktop/phone light/dark captures and QA under `C:\xampp85\migration-backups\healthbridge\about-build-journey-20261121\qa\`.


## Doctors hero directory-backed sample profiles â€” 21 November 2026

The Doctors hero preview now samples the directory's actual synthetic profiles rather than using a placeholder. Displayed entries: Dr. Ahmed Hassan (Cardiology, 12 years, Next: Tomorrow â€¢ 9:30 AM); Dr. Yasmin Mansour (Dentistry, 15 years, Next: Wed, Nov 25 â€¢ 10:45 AM); Dr. Tarek Mansour (Dermatology, 8 years, Next: Mon, Nov 23 â€¢ 9:00 AM). Verified against the rendered directory's three corresponding department cards.

Read-only public data confirms 32 sample doctors across 9 active departments. Count line is non-interactive; removed the faux search box. Hero card shell/header/heading/chips/floating note stay in place; caption states â€œSamples from the directory below Â· Synthetic recordsâ€.

Stacked featured profiles rotate in a 15s CSS opacity cycle (~5s/profile), pause on hover/focus and respect reduced motion by keeping first profile static. Mobile is checked at 390/320; hero spacing 72px desktop / 65px phone. Cache ref `doctors.css?v=9`. Focused QA 78/78, reduced-motion 4/4, four axe zero-violation scans; wider Doctors regression 19/19 interactions, eight responsive/theme states, ten axe scans zero violations, three GET APIs HTTP 200 and no overflow/browser errors. Full detail: `docs/HEALTHBRIDGE_MILESTONE_3_DOCTORS_HERO_LIVE_SAMPLES_20261121.md`. No screenshots saved.


## Doctors hero rotation construction repair â€” 21 November 2026

The three real profile cards now run from one 15s shared CSS keyframe cycle; only delays differ (0s / 5s / 10s). Shared phase fades in by 6%, holds through 29.333%, then fades out by 40%. Each 5s profile hand-off has an intentional ~1s overlap so the outgoing and incoming card are both visible. All cards have `position:absolute; inset:0` and identical internal layout. Slot height is fixed at 73px above 380px and 85px at/under 380px. No JS timer. Hover/focus pause and reduced-motion first-profile fallback remain. Focused audit: 36 assertions Ã— 1440/390/320 Ã— light/dark; all overlap, cycle-continuity, pause and reduced-motion checks passed. Cache ref `doctors.css?v=10`. Detail: `docs/HEALTHBRIDGE_MILESTONE_3_DOCTORS_HERO_ROTATION_FIX_20261121.md`. No screenshot saved.


## Contact hero received stamp â€” 21 November 2026

The existing tilted field note now carries a gold double-border stamp: â€œRECEIVED Â· READ IN THE ADMIN SUPPORT INBOXâ€. This is deliberately factual: feedback is stored for reading in the admin support inbox; no visitor reply is promised. The note, chips, closing line and caption remain intact, with clearance reserved below the closing line. The optional â€œClearer journeys.â€ italic accent is now gold.

The stamp lands once in 250ms when the existing Contact hero panel enters the viewport, then stays at its -7Â° angle. The shared one-shot reveal provides the trigger; no new JS/dependency/timer. Reduced motion and no-IntersectionObserver fallback show it statically. Cache reference: assets/public-v2/css/pages/contact.css?v=6.

Focused QA: 68/68 assertions across 1440/390/320 Ã— dark/light; no note-content overlap or document overflow; reduced-motion/no-observer fallback and one-time replay prevention pass; two axe A/AA scans zero violations (27 passes each); zero page/console/request errors. Full result: docs/HEALTHBRIDGE_MILESTONE_3_CONTACT_HERO_RECEIVED_STAMP_20261121.md; evidence at C:\xampp85\migration-backups\healthbridge\contact-received-stamp-20261121\qa\stamp-audit.json. No screenshots created or saved.


## Authentication entry pages â€” Public V2

pages/auth/login.html and pages/auth/register.html are migrated to the same standalone V2 foundation. They load shared tokens, base, components, theme/toast runtime and footer, with css/pages/auth.css and js/pages/auth-entry.js owning authentication presentation and page interactions. They do not load legacy assets/js/core/main.js, assets/js/core/auth.js, assets/css/style.css, or the previous assets/css/pages/auth-entry.css.

The V2 auth controller defines only the auth-specific helpers it needs (saveUser, same-origin reset-link validation and password visibility controls); shared navigation/theme/toast/base-path helpers are owned by js/core/site.js. Existing PHP authentication, location lookup, registration and verification endpoints and request payload contracts remain unchanged.

Registration uses three focused stages: account credentials (existing step: 1 validation request), patient identity/address, then emergency contact and optional health/insurance details. The last two stages are client-side only until the existing final step: 2 request, which still submits the same field names in one combined payload. Optional fields are collapsed by default. Account and profile values remain in page memory until final submission; no new browser persistence is introduced.


## Migration acceptance rule â€” no legacy frontend dependencies

A page is considered migrated only when all stylesheet and script references resolve to assets/public-v2/, its runtime behavior is provided by Public V2 shared/page-owned modules, and both source scans and browser-loaded-asset checks show no legacy frontend dependencies. Preserve backend API and security contracts. Do not delete shared legacy files globally until no unmigrated routes still consume them.
## Register page animation ownership

Register-only effects are defined in css/pages/auth.css under the .auth-register-page scope, with live card state owned by js/pages/auth-entry.js. The card preview is fed from the existing form values and current wizard validation state; it creates no extra API request or browser persistence. All continuous glows/shine use transform-only animation; the aurora filters are static. Reduced-motion removes all motion and keeps text updates. Acceptance: docs/HEALTHBRIDGE_MILESTONE_3_REGISTER_ANIMATION_SET_20261121.md.


## Official HealthBridge mark and auth entry consistency â€” 21 November 2026

The approved HealthBridge building SVG path is the single Public V2 brand mark. Use that exact path inside the .home-brand-mark and .site-footer__mark wrappers, in auth .logo-icon brand slots, the Register patient preview-card mark, and favicon.svg. Do not recreate the previous plus with CSS pseudo-elements or introduce path variants. The shared footer source remains includes/fragments/footer.html and its loader requests fragment version v2. Header/footer CSS versions for the current seven migrated pages are components.css?v=4 and footer.css?v=2.

The Login and Register shared left-panel presentation is scoped to .auth-entry-page. The aurora/focus-underline effects can be shared by Login and Register; the patient preview card, stepper and wizard-specific shine remain .auth-register-page-only. The Login Sign In button uses its own #login-submit shine rule. Under reduced motion, hide the aurora and stop decorative shine/draw motion; preserve readable static content. The auth brand content and wordmark area intentionally have no border, shadow or framed icon tile. Keep the navbar logo chip outside that borderless left-panel rule.

Current cache references: auth.css?v=5; components.css?v=4; footer.css?v=2; footer.js?v=2; About page stylesheet about.css?v=7; favicon URL v=2. reset-password.html and verify-email.html are still legacy routes and receive the approved mark only when those routes are migrated.


## Canonical logo treatment â€” owner correction, 22 November 2026

Use the Login navbar treatment at every migrated logo location: #e8d1a8 SVG stroke, 1px solid rgba(217, 191, 150, 0.48) outline, and transparent square background. Keep each wrapper's own existing dimensions and radius. The shared public header uses .home-brand-mark; the shared footer uses .site-footer__mark; auth nav/story-panel marks use .logo-icon. The About product-preview mark and Register patient-card mark share the same treatment.

Do not restore a filled teal brand tile or white SVG strokes. The SVG path itself is unchanged. The auth story-panel containers (.auth-brand-content and .auth-brand-logo) stay borderless; only the icon square inside .auth-brand-logo gets the gold outline.

Current cache refs: components.css?v=5, footer.css?v=3, auth.css?v=6. About page stylesheet URL is about.css?v=8 (the cache key is refreshed; its CSS rules did not require editing).


## HealthBridge wordmark hover and idle motion â€” 22 November 2026

The shared HealthBridge wordmark uses two CSS-only effects in components.css across the seven migrated pages (both themes): a one-shot hover letter wave and an occasional idle shimmer with a gold underline. The header/footer wordmarks share the same component; Login and Register also apply it to their story-panel wordmarks.

Hover and keyboard focus-visible wave: 12 letters, 175ms each, 25ms left-to-right stagger, 2px peak lift, 450ms total. Pointer hover is enabled only under (hover: hover). Keyboard focus-visible receives the same wave and gold overlay. The original title glyphs remain fully opaque in their theme colour while the gold overlay animates.

Idle: two animations per wordmark only (one aria-hidden shimmer layer and one underline pseudo-element), running on a 10-second cycle. The visible shimmer/draw window is 1.4 seconds (77â€“91%); the remaining 8.6 seconds are visually still. The 1px underline draws in 0.3s, holds for 0.8s, then fades for 0.3s. Idle shimmer and underline pause while hovered or keyboard-focused. No JavaScript timer/listener, dependency, external font, animated blur, filter or shadow is used. prefers-reduced-motion disables both effects.

Current cache references: components.css?v=10 on all seven migrated pages; footer.js?v=3 loads the canonical includes/fragments/footer.html?v=3.

Implementation report: docs/HEALTHBRIDGE_MILESTONE_3_WORDMARK_ANIMATIONS_20261122.md. Browser audit: 499/499 assertions PASS across 7 pages Ã— dark/light plus one reduced-motion state per page. Local performance check: 14 samples, median full-page transfer 160.2KB, median load 293ms and median FCP 328ms (unthrottled local lab, not field CWV); shared components.css transfer 17.8KB. Both audits report zero external, failed or POST requests, no layout resize/overflow and no screenshots. No stage/commit/push/deploy.

## Patient workspace â€” Public V2 shell

The nine patient workspace routes (Dashboard, Appointments, Document Center, Medical History, Health Insights, Notifications, Prescriptions, Profile and Settings) use a dedicated V2 shell. The new shell presentation is `css/pages/patient-dashboard.css`; the shell controller is `js/core/patient-shell.js`. Both are independent of the legacy patient portal assets.

Each route loads the shared `tokens.css`, `base.css`, `components.css`, and the patient workspace stylesheet, followed by the shared `js/core/site.js` runtime and the patient shell controller. The shell renders its own patient-only header and sidebar; it does not fetch the old navbar/sidebar fragments or load `assets/js/components/layout.js`.

Do not add references from these patient routes to legacy `assets/css/**` or `assets/js/**` files, including the former global runtime, patient page controllers, booking wizard or module scripts. Reimplement route behavior in V2-owned files as each patient dashboard phase is completed. The active route list and phase status are tracked in `docs/HEALTHBRIDGE_PATIENT_DASHBOARD_PHASE_0_SHELL_20261122.md`.


## Patient dashboard Phase 0 correction â€” 22 November 2026

The nine patient workspace HTML routes use the new shell and now reference `css/pages/patient-dashboard.css?v=6` and `js/core/patient-shell.js?v=2`. The Patient Dashboard Phase 1 overview is rendered by the dedicated V2 controller `js/pages/patient-dashboard.js?v=2` on Dashboard only; it uses the approved V2 shared runtime and preserves the existing `POST api/patient/dashboard.php` contract. Dashboard content tests intercept this existing endpoint with synthetic data; they do not write to the database.

The patient Settings route loads `js/pages/patient-settings.js` only. This V2 module waits for `patient:shell-ready`, then makes a read-only `GET api/settings/get.php` request to prefill the authenticated patient's account fields. The Practice/Admin sections and dead Settings tab bar are removed from the patient route. The approved Danger Zone text remains unchanged.

Both route-specific controllers live entirely under `assets/public-v2/js/**`. Do not attach the prior `assets/js/pages/patient/dashboard.js` or `assets/js/pages/settings.js` files to patient routes. They remain legacy source references for other workspaces and migration history only.

Correction report: `docs/HEALTHBRIDGE_PATIENT_DASHBOARD_PHASE_0_CORRECTION_20261122.md`. Final per-page shell audit: 9/9 pages, 54/54 states, with a page-status matrix. No PHP/API/database/auth changes; no screenshots.


## Patient Dashboard Phase 1 â€” patient-first overview

The Dashboard now focuses on the authenticated patient's greeting, appointment-booking availability, the earliest valid future appointment, up to three actionable updates, a three-value explained care/account snapshot, three quick actions, and one recent-activity feed (maximum three entries). The dashboard no longer embeds the old care-team, latest-prescription, notifications, downloads or insights-preview widgets.

The controller selects the next appointment from the existing dashboard aggregate and filters cancelled/completed/missed records, past dates and past same-day appointment times. A pending reschedule continues to show the currently scheduled slot until the change is accepted. The endpoint and request payload remain unchanged; the overview DTO now includes additive booking-status metadata (`closed_reason`, `day_name`, `operating_days`).

The patient shell preserves the patient name as an accessible name on narrow screens and keeps the notification badge's hidden state hidden. These changes are shared by all nine patient workspace routes.

Current references: `css/pages/patient-dashboard.css?v=7`, `js/core/patient-shell.js?v=3`, Dashboard-only `js/pages/patient-dashboard.js?v=3`. Booking is open Mondayâ€“Friday during configured hours; Saturday/Sunday closures are explicitly named by the dashboard, and the hours line identifies Monâ€“Fri. See `docs/HEALTHBRIDGE_PATIENT_DASHBOARD_PHASE_1_RESULT_20261122.md` and `docs/HEALTHBRIDGE_PATIENT_DASHBOARD_BOOKING_STATUS_CLARIFICATION_20261122.md` for implementation and QA evidence. Phase 1 remains approved. Phase 2 work was authorized, implemented and corrected locally; final owner acceptance is pending. Phase 3 remains paused.


## Patient Appointments Phase 2 - Public V2 - 22 November 2026

The Appointments route uses the patient Public V2 shell and route-owned assets:
- css/pages/patient-appointments.css?v=5
- js/pages/patient-appointments.js?v=5

The route covers paginated cards, search and status filters, next-up appointment, details, booking/reschedule workflows, suggested-time responses, cancellations, ratings and ICS export. Search is kept behind a compact Search control beside Filter; its input is collapsed by default and expands on demand, with the original debounced GET contract preserved. The controller and stylesheet remain independent of legacy patient JavaScript/CSS. Existing API endpoints and request contracts are preserved.

### Owner correction round - 22 November 2026

- Job 1: a route-scoped [hidden] { display:none !important; } rule prevents loading, error and empty elements from overriding their hidden state. Lifecycle coverage confirms loading only while delayed requests are pending, all non-list states hidden after successful nine-card data, empty only after a successful zero-record response, and error/retry only after a failed request.
- Job 2: the dialog root is portalled to document.body; initial loading, booking and reschedule wizard states use the viewport-contained flex panel. The doctor-directory GET is delayed in QA by 450ms to verify the initial loading shell is already visible before any department tile appears. Measured loading / Department / step-three heights: 1440x900 = 289.10 / 446.45 / 561.38px; 320x740 = 295.65 / 494.86 / 574.96px; 320x640 = 295.65 / 494.86 / 576.55px. Title stays visible; footer is pinned as a sibling below the scrollable step body when choices/actions are available; no inner wizard-body scrollbar was measured.
- Synthetic occupied slot: Doctor ID 701, 29 Nov 2026, existing booking 57999 at 09:30. The page displays 9:30 AM with a visible Taken label on a disabled button. ScheduleService filters booked ranges out of the live available-slots response; this rendering was validated with an explicit synthetic unavailable slot, not claimed as a real live API result.
- Calendar export is a labelled Add to calendar action in future appointment cards and their Details dialog. It is not shown for completed/cancelled/missed or past appointments. ICS export was checked with V1 HH:MM:SS and V2 duration_minutes.
- Cancellation confirmation includes the selected doctor, date and time.
- Appointment HTML/JS/CSS source strings were repaired where stored mojibake was present. All three files now strict-decode as UTF-8 with BOM; HTML declares UTF-8; no common mojibake sequences remain. Wizard step label, doctor’s apostrophe, checkmark and result-count dash now decode correctly.
- Wizard spacing follow-up: at 1920x858, Step 1/2 content-to-button gaps are 15.97px / 15.92px (~16px); a replay of the backed-up pre-follow-up source with the same fixtures measured 14.85px in both steps, so the larger screenshot-reported band was not reproduced locally. The current layout removes the automatic footer top margin. Header gaps are ~16px; footer padding 14px top/bottom; Step 3 empty-state clearance 17.92px and four slot rows use 12px gaps; Step 4 label/value gap 6px and Notes/footer clearance 17px. Step 3 entry focuses the date field. All four steps and 16 synthetic slots fit without an inner body scrollbar. See docs/HEALTHBRIDGE_PATIENT_APPOINTMENTS_PHASE_2_WIZARD_SPACING_20261122.md.

- Wizard transitions use one persistent shell: the same panel, header, progress wrapper and footer DOM nodes remain mounted through Step 1→2 and Step 2→3. Step content crossfades/slides with opacity/transform over 230ms and panel height animates over 230ms. Progress fills and completed steps show ✓; reduced-motion mode swaps instantly and suppresses animation.
- Patient shell and wizard share the actual Login SVG building mark and existing HealthBridge wordmark component: idle shimmer plus hover letter-wave. The same shared shell is loaded on all nine patient routes using patient-shell.js?v=3 and patient-dashboard.css?v=7; the existing components.css?v=10 supplies the brand component.
- Final audit PASS: 319/319 assertions across 26 browser states, four axe WCAG 2.0/2.1 A/AA scans with zero violations. 77 mocked API requests; 9 intercepted POST requests; zero external, failed or unexpected API requests, page errors or unexpected console errors. One expected handled application diagnostic was separately recorded for the synthetic failure-state test. No screenshots, real patient writes, staging, commit, push or deployment.

Reports:
- docs/HEALTHBRIDGE_PATIENT_APPOINTMENTS_PHASE_2_JOB_1_STATE_VISIBILITY_20261122.md
- docs/HEALTHBRIDGE_PATIENT_APPOINTMENTS_PHASE_2_JOB_2_WIZARD_AND_SLOT_DETAILS_20261122.md
- docs/HEALTHBRIDGE_PATIENT_DASHBOARD_PHASE_2_RESULT_20261122.md
- docs/HEALTHBRIDGE_PATIENT_APPOINTMENTS_PHASE_2_WIZARD_SPACING_20261122.md
- docs/HEALTHBRIDGE_PATIENT_APPOINTMENTS_PHASE_2_OWNER_FOLLOW_UP_20261122.md

Phase 2 implementation and corrections are complete locally. Owner acceptance is pending. Phase 3 remains paused until the owner re-checks and approves Phase 2.
