# HealthBridge — Milestone 3.2 Visual Design System Specification

**Date:** 20 November 2026  
**Status:** Proposed design direction and token specification; ready for representative prototyping.  
**UX architecture:** `docs/HEALTHBRIDGE_MILESTONE_3_UX_ARCHITECTURE_20261120.md`  
**Baseline:** `docs/HEALTHBRIDGE_MILESTONE_3_AUDIT_20261120.md`

## 1. Design direction — Calm Clinical / Deep Teal

HealthBridge should feel like a contemporary, premium healthcare platform: trustworthy, calm, precise and welcoming. It should look like one deliberate product across public, patient, doctor and admin areas—not a collection of generic dashboards.

Recommended direction:
- **Brand anchor:** deep teal/evergreen, supported by a restrained sea-glass/mint accent.
- **Surfaces:** warm off-white canvas, clean white elevated surfaces and subtle neutral dividers; avoid cold saturated blue backgrounds on every screen.
- **Typography:** crisp system-first sans-serif with a clear hierarchy and comfortable line height.
- **Density:** compact enough for appointments, records and admin tables; generous enough to distinguish sections and improve scanability.
- **Shape:** modest radii, thin borders, restrained shadows; avoid excessive pills, glassmorphism, glow and gradients.
- **Status language:** accessible semantic colors paired with text labels and/or icons. Color never carries status alone.
- **Motion:** subtle, short, intentional transitions and micro-interactions that communicate state. Avoid constant motion, page-wide decorative animation or expensive blur/filters.

The goal is recognizably healthcare without looking like a dated hospital template or an overly playful wellness app.

## 2. Recommended color token draft

Tokens are semantic, not page-specific. The exact contrast and displayed combinations must be validated with axe and computed-color tests during prototype work before values are locked.

### Light theme — recommended default direction

| Token | Proposed value | Use |
|---|---|---|
| `--color-brand-950` | `#103A37` | Deep brand surfaces / high emphasis |
| `--color-brand-800` | `#155B55` | Primary text/icon emphasis on light surfaces |
| `--color-brand-700` | `#176B63` | Primary button and main brand action |
| `--color-brand-600` | `#1C8177` | Hover/active accent when contrast permits |
| `--color-brand-100` | `#DCEFEB` | Soft brand surface |
| `--color-brand-50` | `#EFF7F5` | Subtle highlights and selected surfaces |
| `--color-canvas` | `#F5F8F6` | App/page background |
| `--color-surface` | `#FFFFFF` | Cards, menus and dialogs |
| `--color-surface-raised` | `#FFFFFF` | Elevated surfaces with border/shadow distinction |
| `--color-surface-subtle` | `#EDF3F0` | Secondary sections and selected states |
| `--color-text-primary` | `#172E2B` | Main text |
| `--color-text-secondary` | `#4D625D` | Secondary body text |
| `--color-text-muted` | `#5C716B` | Metadata only after contrast validation |
| `--color-border` | `#DCE6E1` | Dividers and control borders |
| `--color-border-strong` | `#BCCDC6` | Emphasized boundaries |

Semantic status candidates:
- Success: dark green foreground with a pale green surface.
- Warning: deep amber/brown foreground with a pale amber surface.
- Danger: dark red foreground with a pale red surface.
- Info: deep blue-teal foreground with a pale blue surface.
- Neutral: deep slate foreground with a soft neutral surface.

Do not reuse low-contrast light-theme combinations found in the baseline (for example, white text on the current cyan primary or green status text on a similarly light green background). Validate every badge, small label, link, button, chart label and muted-text pairing.

### Dark theme — preserve as a deliberate alternate

The current product supports a light/dark toggle. Recommended redesign should retain a dark mode only if every shared component and role area is intentionally themed and verified—not as partial selector overrides.

Candidate direction:
- Canvas: deep green-charcoal, not pure black.
- Surfaces: layered charcoal/evergreen with clear elevation steps.
- Text: warm near-white primary, desaturated green-grey secondary.
- Brand: brighter sea-glass/teal reserved for selected/interactive accents.
- Status colors: brighter accessible foregrounds on deep tinted surfaces.
- Borders: visible neutral/green-grey borders, not only transparent glows.

Do not use a dark-theme token that passes on one card but fails on a tinted, photographic or nested surface. Contrast validation must include the rendered background.

## 3. Typography

Use a system-first font stack to avoid a blocking external font request and reduce dependency on Google Fonts under the current CSP:
`Inter, "Segoe UI", system-ui, -apple-system, BlinkMacSystemFont, sans-serif`.

If Inter is not installed, the system font fallback remains clean and consistent. Do not depend on downloading a remote font to render the first viewport. A locally hosted licensed font may be considered later only if it materially improves the finished design.

Proposed hierarchy:
- Display/marketing hero: 40–52 px desktop, fluid down to 32–38 px mobile; semibold/bold, tight but readable line height.
- Page title: 28–32 px desktop, 24–28 px mobile; semibold.
- Section title: 20–24 px; semibold.
- Card title: 16–18 px; semibold.
- Body: 15–16 px; line-height around 1.5–1.65.
- Labels/control text: 13–14 px; medium weight; never shrink required instructional text to tiny metadata.
- Metadata: 12–13 px only where secondary and contrast-checked.

Avoid using multiple font families, gradients inside text, exaggerated letter spacing or uppercase labels across all cards.

## 4. Layout, spacing and geometry

Use a 4 px base spacing unit. The shared scale should favor repeatable values:
- 4, 8, 12, 16, 20, 24, 32, 40, 48, 64 and 80 px.
- Common card padding: 20–24 px desktop, 16–20 px mobile.
- Page gutters: 32 px wide desktop, 24 px tablet, 16 px mobile.
- Content max width: about 1440 px; reading-heavy form/content areas narrower.
- Desktop authenticated shell: stable sidebar plus top header and a flexible content region.
- Sidebar: approximately 248–264 px expanded, with a measured compact/collapsed state only if it improves navigation.
- Top header: 64–72 px depending on role shell.
- Grid: 12 columns desktop; 8 tablet; 4 mobile, with content-specific spans.

Geometry:
- Controls: 10–12 px radius.
- Cards: 14–18 px radius.
- Large dialogs/sheets: 18–22 px radius.
- Small status indicators: pill/rounded-full only where the shape supports their meaning.
- Use 1 px neutral borders and one restrained shadow level per elevation. Do not combine thick border + strong shadow + glow.

## 5. Elevation and surfaces

Use a small, consistent elevation ladder:
1. Page canvas: no shadow.
2. Standard card: subtle border; little or no shadow.
3. Popover/dropdown: clear border plus modest shadow.
4. Dialog/drawer: stronger but soft shadow and a dimmed backdrop.
5. Sticky top/side navigation: separate through border/surface color rather than large blur.

Avoid 30 px backdrop blur on fixed navigation as a default. It adds paint/compositing cost and can reduce text clarity on mobile.

## 6. Motion and interaction rules

Motion should signal state change, not decorate empty space.

| Motion | Recommended range | Implementation |
|---|---:|---|
| Hover/focus color/border | 120–160 ms | CSS color/border/opacity |
| Button press | 100–140 ms | Small transform/opacity, no layout shift |
| Tab/panel transition | 160–220 ms | Opacity + small translate |
| Menu/drawer/dialog | 180–240 ms | Opacity + transform |
| Toast enter/exit | 180–220 ms | Transform + opacity |
| Large page/section reveal | Avoid by default | Use only for specific context, not all pages |

Use a consistent easing family (for example, cubic-bezier(0.2, 0.7, 0.2, 1)). Animate transform and opacity, not width/height/top/left or blur-heavy filters. Avoid infinite skeleton shimmer when a static skeleton or restrained pulse communicates loading sufficiently. Never make users wait for a decorative entrance animation before accessing controls.

For `prefers-reduced-motion: reduce`, remove non-essential transform/scroll/entrance animations, disable smooth scrolling and make state changes immediate. Motion must never be the only indicator of selection, loading or success.

No animation library is recommended; CSS and native JavaScript are sufficient.

## 7. Responsive layout rules

| Range | Behavior |
|---|---|
| ≤480 px | Single-column content, compact top bar, drawer/bottom role navigation, full-width forms, modal sheets |
| 481–767 px | Compact/tablet layout, flexible filters, 1–2 column content depending on minimum readable width |
| 768–1023 px | Tablet navigation, 2-column content where card widths remain usable |
| 1024–1279 px | Desktop shell with compact spacing and adaptive columns |
| ≥1280 px | Full desktop role shell, 12-column content grid and controlled max width |

Do not treat the 768 px breakpoint as a universal switch for every component. Tables, sidebars, dialogs and forms may need their own content-driven breakpoints. Validate at 390×844, 768×1024 and 1440×900 plus a narrow 320–360 px stress case before closure.

## 8. Component inventory and visual contract

### Shell and navigation
- PublicHeader, PublicMobileMenu, PublicFooter.
- AuthShell, AuthStepProgress.
- AppHeader, RoleSidebar/Drawer, AccountMenu, NotificationMenu.
- Breadcrumbs, PageHeader, PageActions, SectionHeader.
- Primary navigation links and local tabs with distinct semantics.

### Data presentation
- StatCard/KPI, StatusBadge, Avatar/Identity, AppointmentCard, DoctorCard, PatientIdentityHeader.
- DataTable, MobileRecordCard, Pagination, SearchField, FilterBar, AppliedFilterChip.
- Timeline, EmptyState, ErrorState, LoadingSkeleton, InlineAlert.
- ChartCard and export controls (retain existing behavior and dependency scoping).

### Inputs and feedback
- Button variants: primary, secondary/outline, quiet/ghost, danger and icon-only.
- TextField, TextArea, Select, DatePicker, Checkbox, Switch and FieldGroup.
- Dialog/ConfirmDialog, Drawer/Sheet, Toast/Notification, ProgressIndicator.
- BookingWizard, VisitProgress and EncounterContext (patterns built around current workflows, not new features).

Every component gets documented visual states: default, hover, active, focus-visible, disabled, loading, invalid, success and read-only where relevant. Components that can be empty/error/loading must have those states specified—not only their success design.

## 9. Accessibility contract

- Normal text contrast target: at least 4.5:1; large text at least 3:1. Validate actual computed foreground/background for nested and transparent surfaces.
- Visible focus must be obvious, with a consistent 2–3 px outline/offset; do not remove native focus without replacement.
- Interactive targets should aim for 44×44 CSS px on touch surfaces where practical.
- All inputs need persistent programmatic labels; placeholders are examples, not labels.
- Icon-only buttons require a useful accessible name and tooltip/title where it helps.
- Visible link/button text must be included in its accessible name.
- Page navigation uses links and `aria-current=page`; selected local tabs use correct tab semantics and keyboard behavior.
- Scrollable tables and regions must be keyboard-focusable with clear region names.
- Dialogs need an accessible title, focus entry, focus containment where appropriate, Escape handling when safe and focus restoration.
- Status must not rely on color alone.
- Support browser zoom/reflow, reduced motion and logical DOM/visual order.

## 10. Icon and illustration strategy

Do not introduce a third-party icon font or icon framework at this stage. The existing Font Awesome stylesheet and font files are part of the public first-load request chain. During the component build, use a small consistent set of inline SVG icons for shared controls and retain current Font Awesome on legacy screens until their callers are migrated. Scope any remaining legacy icon requests by route. Do not use emoji as product icons.

Avoid stock-photo dependence in dashboard cards. Use real product data, CSS surfaces and restrained SVG decoration. Keep decorative illustration non-blocking and never use it to obscure functional content.

## 11. Performance guardrails for the design system

- No external font request is required for first paint.
- No icon font request is required for new shared components.
- No animation runtime/dependency.
- No heavy glass blur, large box-shadow glow or continuous animation in repeated cards.
- Images must declare dimensions/aspect ratio, be appropriately sized and lazy-loaded below the fold when suitable.
- Hero/LCP imagery must be discoverable and high-priority where appropriate; do not leave the LCP image hidden in a CSS background without a reason.
- Load chart/map libraries only on pages that use them; do not pull Chart.js into patient/public pages or Leaflet into unrelated routes.
- Page-specific CSS/JS should be loaded only by the relevant route when the existing dependency map allows it.
- Compare the same Lighthouse/Playwright conditions from the M3.0 baseline before/after prototype and implementation.

## 12. M3.2 outcome and prototype gate

This specification establishes the recommended visual direction, light/dark token families, typography, spacing, layout, responsive rules, motion, component inventory and accessibility contract. It does not lock final color values until the prototype passes contrast tests across representative components and both themes.

Next M3.3 prototype order:
1. Patient Dashboard (primary patient experience and component coverage).
2. Doctor Dashboard (task-focused queue and appointment work).
3. Admin Overview (dense operations/analytics layout).
4. Public Doctors Directory (public discovery/search pattern).
5. Authentication and booking interactions (shared form/progress/dialog patterns).

The prototype should use synthetic content only, preserve the current route/contract model and be isolated from the production page files until its visual direction is validated.


## Patient Dashboard prototype validation addendum — 20 November 2026

The first representative Patient Dashboard prototype is at `C:\\xampp85\\migration-backups\\healthbridge\\milestone3-prototype-20261120\\patient-dashboard.html`. It implements the initial Calm Clinical / Deep Teal direction, including both light and dark variants, and was tested by Playwright 1.65 Chromium and axe-core 4.14.0. Across 320, 390, 768 and 1440 px viewports, both themes had zero WCAG 2.0/2.1 A/AA axe violations and no horizontal document overflow. The theme switch, mobile drawer, notification popover and no-submit booking preview were tested. The dark theme needed dedicated text/link/avatar overrides; do not simply invert light tokens without checking component surfaces. Raw results and reproduction source are in the same milestone prototype folder.

This validates the direction on the Patient Dashboard only. It does not lock global tokens, certify other role pages or close the design-system milestone. The Doctor Dashboard, Admin Overview and public directory need representative content-density and theme checks before final token approval. Prototype QA is automated; manual keyboard and assistive-technology verification remains open.


## Patient, Doctor and Admin prototype addendum — 20 November 2026

The Patient Dashboard, Doctor Dashboard and Admin Overview prototypes now share the proposed Calm Clinical / Deep Teal direction and light/dark semantic palette. All are static files outside the Apache webroot under `C:\\xampp85\\migration-backups\\healthbridge\\milestone3-prototype-20261120\\`; none calls HealthBridge APIs or reads application records.

Each screen passed Playwright/axe WCAG 2.0/2.1 A/AA at 320, 390, 768 and 1440 CSS px in both themes (24 scans total, all zero violations), with zero horizontal document overflow. The Doctor prototype separately validates queue/schedule/request patterns; the Admin prototype validates grouped nav, KPI/analytics and a desktop table-to-mobile-card switch. Theme, drawer, notification and screen-specific interactions passed. Raw tests and JSON reports are retained in the prototype folder.

This is encouraging evidence for the shared direction, but it is not a global token lock. Public Doctors Directory and auth/booking prototypes remain. The Admin table and patient/doctor dense task views must still be checked after those screens are included. Manual keyboard and assistive-technology review remains open.


## Public Directory + Authentication/Booking validation — 20 November 2026

The remaining representative M3.3 public/auth references are now complete: `doctors-directory.html` and `auth-and-booking.html` in the isolated milestone prototype folder. The directory validates public search, department selection, sorting, profile dialog, responsive public navigation and sample availability states. The auth/booking concept covers sign-in, two-step patient registration, verification/recovery/reset states and the existing four-step patient appointment journey.

The pair passed 38/38 axe WCAG 2.0/2.1 A/AA scans in light and dark themes (16 viewport/theme plus 22 state/theme scans), eight width checks (320/390/768/1440), zero horizontal overflow and 18/18 scripted interactions. Combined with the Patient/Doctor/Admin references, five prototypes have now passed 62 axe scans with zero violations.

These checks support keeping the proposed Calm Clinical / Deep Teal direction consistent across public, auth and role-specific surfaces. They do **not** lock global token values: final colors, contrast, spacing and component variants remain provisional until owner review and validation on the integrated PHP pages. Automated axe checks do not replace manual keyboard, focus, zoom/reflow or assistive-technology review.



## Integrated Directory validation — 20 November 2026

The provisional visual system has now been applied to the real Public Doctors Directory, not only to the isolated prototype. The page-owned implementation was consolidated into one stylesheet (31,315 bytes before consolidation → 17,718 bytes after, a 43.4% reduction), while shared tokens and global shell styles remain in style.css.

The integrated page passed 10 automated WCAG 2.0/2.1 A/AA axe scans with zero violations, including the profile dialog in both light and dark themes. Eight responsive/theme checks at 320/390/768/1440 px had zero horizontal overflow and the expected 1/1/2/3-column doctor grid. The audit also passed 19/19 interactions. Result and limitations are documented in the QA Evidence Index and the dated Directory result folder.

This confirms the page-level implementation path; it does not lock the global tokens or establish accessibility certification. Owner design review, manual keyboard/zoom/reflow and assistive-technology validation, and integrated checks on the remaining public/auth/role pages remain open.



## Integrated homepage and authentication validation — 20 November 2026

The provisional visual system is now integrated into the actual homepage and authentication pages as well as the Public Doctors Directory.

The homepage uses the shared token/shell layer with a page-owned content layout. The fake clinic/trust content was replaced with a truthful synthetic-demo notice and a guided entry journey. The dynamic department list remains backed by the existing read-only endpoint; cards are native links and search/department navigation preserve the current doctor-directory query contract.

The four auth pages (login, registration, reset and email verification) share one page-owned stylesheet. The layout and interaction presentation was rebuilt while keeping the existing auth controllers and all form/API/token contracts intact. The theme was checked explicitly in both light and dark states.

Automated integrated validation:
- Homepage: 8/8 axe WCAG 2.0/2.1 A/AA scans zero violations; 8/8 viewport/theme layouts no overflow; 16/16 scripted public-entry interactions pass.
- Auth: 44/44 axe scans zero violations; 32/32 route/viewport/theme layout checks no overflow; 27/27 scripted interactions pass across four HTTP 200 routes.
- No browser page exceptions, non-CSP console errors or POST requests occurred in either audit.

This confirms the shared direction on the current public entry/auth group, not a global design-token lock. Owner visual review, manual keyboard-only, zoom/reflow and assistive-technology checks remain open. Existing global font/CSP warnings remain documented for a separate M3.6 pass; Content Security Policy was not weakened.

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


## Owner-directed public-page art direction — 20 November 2026

The owner has provided a more specific direction for the public-facing redesign: **HealthBridge — Modern Clinical Excellence**. This direction supersedes the generic M3.4 Calm Clinical / Deep Teal proposal for the public homepage checkpoint; it does not yet lock global role-workspace tokens.

Public-page palette:
- Deep Ink: \`#102B2B\`
- Clinical Teal: \`#17645F\`
- Soft Sage: \`#E2EEE9\`
- Warm Ivory: \`#F6F7F3\`
- Muted Brass: \`#B89563\`, used sparingly as a detail color

The requested visual language is editorial modern healthcare: distinctive display headings with readable interface typography, confident asymmetric compositions, carefully chosen photography, varied page-specific layouts, thin rules and restrained component shapes. Avoid generic SaaS/hospital templates, repeated identical cards, excessive gradients and glass effects.

The homepage is the first real-page implementation and must be reviewed before its style is rolled out. Its source/result record is \`docs/HEALTHBRIDGE_MILESTONE_3_HOME_PREMIUM_20261120.md\`. Existing shared navigation/theme and IntersectionObserver motion are reused; no new animation dependency is introduced. This is not approval to copy the homepage layout into Patient, Doctor or Admin workspaces.
