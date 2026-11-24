# HealthBridge Public V2 — Standalone Frontend Architecture

**Date:** 20 November 2026  
**Status:** Implemented for Home and Doctors; automated local gate passed; owner review pending  
**Purpose:** Establish a clean, independent CSS/JavaScript foundation for every public page redesigned from this point forward.

## Decision

The redesigned public website now has its own frontend asset layer under:

`assets/public-v2/`

The Home and Doctors pages no longer load or depend on the legacy public/dashboard assets:

- `assets/css/style.css`
- `assets/css/pages/settings.css`
- `assets/js/core/main.js`
- `assets/js/pages/home.js`
- `assets/js/pages/doctors.js`
- the Font Awesome CDN stylesheet previously used by these pages

The legacy files are deliberately retained because dashboards and public pages that have not yet been migrated still use them. Do not delete or modify the legacy global assets as part of this redesign. A redesigned page must not add a dependency back to them.

The HTML entry routes remain at their existing paths (`index.html`, `doctors.html`, etc.). Only the presentation/controller assets move into Public V2. Keeping route files in place preserves existing URLs, query parameters, links, API-relative paths and the current PHP application architecture.

## New asset tree

```
assets/public-v2/
├── README.md
├── css/
│   ├── tokens.css
│   ├── base.css
│   ├── components.css
│   └── pages/
│       ├── home.css
│       └── doctors.css
└── js/
    ├── core/
    │   └── site.js
    └── pages/
        ├── home.js
        └── doctors.js
```

### CSS responsibilities

- `tokens.css`: shared Public V2 semantic design tokens, spacing/radius foundations and responsive constants.
- `base.css`: reset, document defaults, typography, focus and motion-reduction baseline.
- `components.css`: shared public navigation, buttons, toast feedback, and common footer/layout primitives.
- `pages/<page>.css`: page-owned composition and component styling. These stylesheets may use only the shared Public V2 foundation and their own page-scoped tokens.

The current Home/Doctors page stylesheets have been migrated into the new folder as standalone page-owned stylesheets. They do not import the old global CSS. Their visual language is preserved as the migration baseline; future page redesign work can replace or simplify these page files without changing the global foundation.

### JavaScript responsibilities

- `js/core/site.js`: standalone shared public runtime. It applies the saved theme early, initializes the public navigation and theme toggle, handles public auth navigation/logout, creates accessible toast feedback, and exposes only the small helper contract used by public page controllers.
- `js/pages/<page>.js`: page-owned API reads, rendering, filtering, dialog and page motion behavior. Keep it free of dashboard/global legacy dependencies.

The current shared helper contract includes:

`getBasePath()`, `getUser()`, `getDashboardUrl()`, `escapeHTML()`, `safePositiveId()`, `safeCount()`, `debounce()`, `showToast()`, and `logoutUser()`.

The Doctors controller uses the new runtime's fallback sample set and helpers. It retains the existing read-only departments/doctors/availability API contracts, query-driven department selection, filtering/sorting, profile dialog, background isolation/focus restoration, and the role-aware booking handoff.

Theme selection continues to persist in `localStorage["hb_theme"]`. For an authenticated user, an explicit user-triggered theme change also retains the existing `api/settings/update.php` appearance-preference request. No appearance update is sent during page initialization.

## Public V2 page integration contract

Every newly redesigned public page must follow this structure. Do not link the legacy stylesheet or script stack.

In the page `<head>`, after the metadata and before the stylesheets:

```html
<script src="assets/public-v2/js/core/site.js?v=1"></script>
<link rel="stylesheet" href="assets/public-v2/css/tokens.css?v=1" />
<link rel="stylesheet" href="assets/public-v2/css/base.css?v=1" />
<link rel="stylesheet" href="assets/public-v2/css/components.css?v=1" />
<link rel="stylesheet" href="assets/public-v2/css/pages/PAGE.css?v=1" />
```

Before `</body>`:

```html
<script defer src="assets/public-v2/js/pages/PAGE.js?v=1"></script>
```

Create the matching `css/pages/PAGE.css` and `js/pages/PAGE.js` only when the page needs its own styling/behavior. Reuse the global Public V2 header, theme, buttons, toast and footer patterns. Do not duplicate those patterns inside each page file.

Before accepting a page:
1. Confirm its Network panel contains only Public V2 CSS/JS for its presentation layer; no legacy `style.css`, `main.js`, page controller, `settings.css` or icon CDN dependency.
2. Check both themes and all agreed widths, including boundary widths around each breakpoint.
3. Compare shared header/footer/component computed styles against already migrated pages.
4. Test navigation/theme with pointer and keyboard, ARIA state, page-specific controls, links and safe API reads.
5. Run axe A/AA, check overflow, page exceptions and failed assets.
6. Preserve the current API/auth/session/role/booking contracts. No visual task should require database/schema changes.

A new public page is not considered migrated merely because it visually resembles an existing page. It must use the Public V2 asset layer and pass shared-component parity checks.

## Footer visibility issue — Doctors page

The Doctors footer already contained both requested strings in its HTML. They appeared to be missing because the previous Doctors motion controller included `.footer-bottom` in its IntersectionObserver reveal targets. The pending-reveal CSS sets opacity to zero until that observer fires. The footer's legal/scope row was therefore hidden while it waited for the reveal boundary.

Public V2 corrects this at the source:
- The footer and its bottom legal/scope row are no longer motion-reveal targets.
- The bottom row is explicitly kept visible.
- The footer elements now have stable IDs (`#footer-bottom`, `#footer-scope`) for regression checks.

The exact visible footer copy is:

- `© 2026 HealthBridge`
- `Portfolio demonstration · Synthetic records only · No live medical services`

## Migration completed in this pass

Home and Doctors now reference only these assets:

| Route | Stylesheets | Scripts |
|---|---|---|
| `index.html` | `tokens.css`, `base.css`, `components.css`, `pages/home.css` | `core/site.js`, `pages/home.js` |
| `doctors.html` | `tokens.css`, `base.css`, `components.css`, `pages/doctors.css` | `core/site.js`, `pages/doctors.js` |

No class/ID or route/query contract used by the page features was removed. The Doctors controller no longer creates its own parallel mobile-menu ARIA handler; the Public V2 runtime owns the shared menu state.

## Automated local verification

Reusable runner:

`D:\dev-toolkit\scripts\healthbridge-public-v2-foundation-audit.mjs`

Latest result:

`C:\xampp85\migration-backups\healthbridge\milestone3-public-v2-foundation-20261120\public-v2-foundation-results.json`

Result: **PASS**

- 32 responsive/theme cases: Home and Doctors × 8 widths (320, 360, 390, 560, 561, 768, 1024, 1440) × light/dark.
- Zero horizontal overflow.
- Eight axe WCAG 2.0/2.1 A/AA scans; zero violation instances.
- Zero legacy stylesheet/controller requests from either migrated page.
- Zero browser exceptions, console errors, failed assets, local HTTP errors or API mutation attempts.
- Home departments loaded nine live read-only API cards.
- Doctors loaded 32 profiles; departments, doctors and public availability GET endpoints returned HTTP 200.
- Home-to-directory search query contract passed; the Cardiology query produced matching directory results.
- Public mobile navigation and theme toggles passed on both pages.
- Doctor profile dialog opened and closed with Escape; focus/background state was restored.
- Doctors footer copy was present and visible at computed opacity 1 in both theme checks and responsive cases.
- JavaScript syntax checks passed for all three Public V2 runtime/controller files.

The browser QA context used a guest identity and blocked non-GET API methods. No authenticated user preference update, logout, booking, database write or backend mutation was performed.

## Scope and remaining work

This is an asset architecture migration and standalone runtime foundation—not the final visual redesign of every remaining public page. About, Services, Contact, Auth and the authenticated dashboards have not been migrated by this task. The existing legacy assets stay in place for those pages until their own scoped migration.

No PHP/API endpoint, database schema/data, session/authentication implementation, RBAC, worker, secret or deployment configuration changed. No dependency was added. No staging, commit, push or deployment occurred.

**Owner review:** Please review Home and Doctors using the new isolated asset stack, especially the Doctors footer legal/scope row and both themes/mobile navigation. Keep the next public-page migration gated on this review.

## Shared component consolidation — 20 November 2026

The first Home/Doctors foundation pass established reusable files; this follow-up removed duplicated implementations between the two migrated pages.

### Shared once in Public V2

- Brand mark and wordmark layout now live in `assets/public-v2/css/components.css`. The logo is one inline-flex component with consistent vertical centering, icon dimensions and mobile sizing. This fixes the Home logo mark's alignment after legacy CSS removal.
- The active navigation underline is defined once in the shared navigation component. Both the desktop navigation and the mobile dropdown use the same pseudo-element, so the Home active link receives the same visible underline as Doctors.
- The mobile dropdown structure, visibility, spacing, top offset (from the shared `--public-nav-height` token), and toggle display are owned by `components.css`. Page CSS no longer carries duplicate mobile-menu layout rules.
- Generic scroll reveals now share one observer and one animation vocabulary. `site.js` watches `[data-public-reveal]`, handles dynamically inserted cards, reduced motion, and the no-IntersectionObserver fallback. `components.css` owns the `rise`, `left`, `right`, and `card` keyframes. Home/Doctors controllers only mark targets; dynamic department/doctor cards carry the same marker at render time.

### Kept page-specific

- Home retains its page-specific hero entrance, its direction choices and stagger timing.
- Doctors retains its page-specific hero entrance.
- Neither page duplicates the generic IntersectionObserver lifecycle or the reusable reveal keyframes.

The shared reveal observer starts only on Public V2 pages. A target is hidden only after the shared runtime has confirmed motion is allowed and IntersectionObserver is available; otherwise content remains visible. Reduced-motion changes during a visit remove pending/active reveal classes.

### Targeted verification

The shared navigation/brand/motion refactor was backed up before edits at:

`C:\\xampp85\\migration-backups\\healthbridge\\public-v2-shared-polish-20261120\\source-before\\`

Manifest: `source-before-manifest.json`.

This follow-up does not change backend/API/database/auth/session/RBAC behavior, the route HTML, or legacy assets. No dependency, commit, push, or deployment was introduced. Home and Doctors remain the only Public V2 pages migrated in this pass; do not start another page until owner approval.


### Final local verification — 20 November 2026

The final shared-component state has been rechecked, not just the pre-consolidation baseline:

- Navigation/brand audit PASS: 8 responsive/theme combinations; zero failures and zero browser errors.
- Shared reveal audit: Home animation variants all fired (23 targets per case); Doctors shared reveals all fired (44 targets per case); no pending/active reveal classes remained after scrolling. Reduced-motion and missing-IntersectionObserver fallback checks kept all content visible.
- Full foundation audit PASS: 32 responsive/theme cases, zero overflow, 8 axe A/AA scans with zero violations, 8 interaction checks with zero failures, zero legacy asset requests, and zero browser/resource/API mutation errors.
- Repeatable runner and raw result paths are recorded in `docs/HEALTHBRIDGE_QA_EVIDENCE_INDEX.md`.

The backup and SHA-256 manifest remain under `C:\xampp85\migration-backups\healthbridge\public-v2-shared-polish-20261120\`. No route HTML, backend/API/database/auth/session/RBAC logic or legacy assets changed. No commit, push or deployment occurred.


## Doctors directory presentation refinement — 20 November 2026

The Doctors page was refined in its directory and spacing only. The page frame remains in the established homepage design language: hero/illustration, header structure, deep-green footer and bottom CTA copy were retained.

- Page rhythm now measures 72px from the What you can explore strip to the Browse by specialty heading, 73px from directory disclaimer to Journey heading, and 83px from Journey cards to CTA content.
- Card facts are no longer repeated: the stars line is the only rating summary, appointment duration replaces the duplicate rating stat, and one next-availability line replaces the status pill/date pair. The profile dialog consumes the same card presentation summary.
- Display-only synthetic directory values are varied in the client controller (rating distribution, next-slot labels/times and working-day patterns). These values are not persisted or sent to the API. API/database availability, booking eligibility and booking behavior remain unchanged. Do not present the new display variations as authoritative booking slots.
- Card visual styling now uses serif names, muted specialty-tinted initials, half-star rendering and a filled teal profile button.
- The desktop department chip set fits one row; search label/placeholder match; footer lists all nine departments and adds Home/Contact to Explore; the highlight strip has a numberless treatment.
- The profile dialog is viewport-capped with internal scrolling while keeping its header/close action visible.

The current Doctors route cache versions are doctors.css?v=2 and doctors.js?v=2. Only doctors.html, assets/public-v2/css/pages/doctors.css and assets/public-v2/js/pages/doctors.js changed in the application code for this round.

Repeatable runners and evidence are listed in docs/HEALTHBRIDGE_QA_EVIDENCE_INDEX.md. Backup and hash manifest: C:\xampp85\migration-backups\healthbridge\doctors-page-polish-20261120\.

No shared Home assets, backend/API/database/auth/session/RBAC/booking behavior or legacy frontend files changed. The next page remains gated on owner approval of the current Home/Doctors work.


## Doctors page visual correction — 20 November 2026

The follow-up correction was restricted to `doctors.html` and `assets/public-v2/css/pages/doctors.css`.

- The preview brand mark uses two absolutely positioned pseudo-elements centered inside the 27px mark. The previous text "+" glyph has been removed.
- Navigation button typography on Doctors now matches the shared Home button contract. A synthetic authenticated state was used to compare the computed "Log out" button style on both routes in light and dark modes; all six typographic properties match.
- The directory section's top and bottom padding were reduced, Journey heading top padding was reduced, a 70px bottom page-background gap was added after the process cards, and the CTA band's internal top padding was reduced to 14px. The intended outcome is a visible gap before the mint CTA band while maintaining a total cards-to-CTA-label rhythm around 80px.
- Numbering remains absent from the highlights strip and present only on the Journey cards. CSS cache version advanced to `v=3`.
- The standard 30-minute appointment duration on all 32 cards was left untouched. No API, database, session/auth, booking, or backend behavior changed.



### Final geometry recheck — Doctors page — 20 November 2026

A second browser geometry pass refined the disclaimer-to-Journey rhythm after the initial correction was found too compressed in CSS-pixel coordinates. The final values are:
- Highlights band bottom → directory eyebrow top: 60 CSS px.
- Disclaimer bottom → Journey eyebrow top: 53 CSS px (a 20px reduction from the previous 73px DOM baseline).
- Journey cards bottom → CTA band top: 70 CSS px of page background.
- Journey cards bottom → CTA eyebrow top: 84 CSS px total, including the CTA's 14px internal top padding.

This supersedes the intermediate 43px disclaimer-to-Journey measurement in the first correction draft. Final Doctors CSS cache version: `v=4`. The latest audit result JSON is the same dated correction result file and reflects this final layout.


## Doctors CTA and footer typography alignment — 20 November 2026

This correction covers the existing Home/Doctors Public V2 footer pair and the Doctors CTA spacing only.

- Doctors CTA section was moved up by 14 CSS pixels: the page-background gap from the Journey process cards to the pale CTA band is now 56px; internal band top padding remains 14px.
- Doctors footer navigation is rebalanced so its nine department links do not form one long column. Explore and Your access remain adjacent; Departments spans the full nav width with a three-column link grid on desktop/tablet and a two-column grid on narrow mobile.
- Home footer navigation labels now match the Doctors label typography exactly: Inter/system inherited font stack, 0.68rem, 700 weight, 0.12em letter spacing, uppercase and block display. Other measured footer text styles already matched and were left intact.
- `index.html` references `home.css?v=2`; `doctors.html` references `doctors.css?v=5`.
- Runner: `D:\dev-toolkit\scripts\healthbridge-doctors-cta-footer-alignment-audit.mjs`.
- Evidence: `C:\xampp85\migration-backups\healthbridge\doctors-cta-footer-alignment-20261120\doctors-cta-footer-alignment-results.json`.
- Result: PASS across 20 responsive/theme cases. Home/Doctors footer typography matches in both themes; zero horizontal overflow; all nine Doctors departments remain present and appear in 3 desktop rows / 5 narrow-mobile rows; CTA geometry is 56px card-bottom-to-band and 14px band-to-label; four axe A/AA scans have zero violations; zero browser errors/non-GET API requests.
- Only `home.css`, `doctors.css` and their two stylesheet cache references changed. No backend/API/DB/auth/session/RBAC/booking behavior, markup, JavaScript, other public pages, or dashboards changed. Owner review remains the gate before any other page migration.



## Site-wide public footer component — 20 November 2026

The public footer is now one shared component rather than several independently edited page footers.

- Canonical semantic markup and all link labels/order are stored once in includes/fragments/footer.html.
- The visual contract is centralized in assets/public-v2/css/footer.css. It fixes the surface to deep green in both themes, preserves the teal/ivory brand mark and wordmark, gold small-caps headings, unbulleted links, and the exact two-line legal/scope row.
- assets/public-v2/js/core/footer.js opts in pages through [data-shared-footer]. It captures its script URL during evaluation, derives the application root from /assets/public-v2/js/core/footer.js, fetches the fragment same-origin, rejects redirects/non-HTML/unexpected URLs, validates the footer root, and resolves relative links from the application root. Nested auth routes therefore receive the same absolute destinations as root routes.
- The footer layout is brand column at left; Explore and Departments stacked in the middle; Your access at right. Department links use three columns on desktop/tablet and two columns at narrow mobile widths.
- Current opt-in routes: index.html, doctors.html, services.html, about.html, contact.html, and pages/auth/login.html. The first five replace their former inline footer markup; Login now hosts the same footer after its main content. The remaining auth and workspace HTML routes inspected did not previously carry a public footer, so no additional footer was introduced there.
- Page-owned content above the footer was left unchanged. Root pages and nested Login only add the shared CSS/JS references and one footer host. Doctors additionally received the requested page-background gap change: Journey card bottom to CTA band top is 90px; CTA top to eyebrow remains 14px.

The six routes were checked in dark and light themes. Rendered footer markup is identical across all 12 route/theme combinations. Responsive checks cover 320, 390, 560, 768, 1024, and 1440 CSS-pixel widths on every route. Full QA details and the isolated pre-existing legacy-page console/CSP notices are recorded in docs/HEALTHBRIDGE_QA_EVIDENCE_INDEX.md.


## Contact page Public V2 integration — 20 November 2026

The Contact route now uses the standalone Public V2 foundation. The page is presented as a project-feedback surface rather than a hospital contact channel.

- contact.html loads only Public V2 tokens/base/components, Contact-owned CSS, and the shared footer CSS/runtime. It no longer loads legacy style.css, legacy Contact/settings styles, main.js, the legacy Contact controller, Font Awesome, Google Fonts, map assets, or any external stylesheet/script.
- Page presentation is owned by assets/public-v2/css/pages/contact.css; page behavior is owned by assets/public-v2/js/pages/contact.js. The design includes the feedback hero, CSS-only project-note illustration, synthetic-data/no-medical-support boundaries, the feedback form, guidance cards and project navigation.
- The CTA targets the form card through #feedback-form. The skip link targets #main-content.
- The form's existing field IDs/names, required status, constraints, category values, and JSON payload are retained: name, email, phone, department, subject, message. The controller still submits a same-origin JSON POST to api/settings/contact.php on explicit user submission only. It uses the shared getBasePath() helper, blocks invalid name/email/message before sending, exposes busy/disabled state, and handles success/failure without exposing server internals.
- The existing PHP endpoint and database contract were not edited. The endpoint does not send email; page copy states that successful feedback is stored and does not promise a reply or medical service.
- The canonical footer is loaded from the same fragment as Home and Doctors. QA compared rendered footer markup exactly with Home and computed footer styles in both themes.
- Legacy assets/css/pages/contact.css and assets/js/pages/contact.js are no longer referenced by active page markup. They were left untouched in the working tree because they were already modified before this migration; their dated backups are included with the pre-edit source manifest.

QA runner: D:\dev-toolkit\scripts\healthbridge-contact-public-v2-audit.mjs.
Raw result: C:\xampp85\migration-backups\healthbridge\contact-public-v2-20261120\contact-public-v2-results.json.
Source backup and SHA-256 manifest: C:\xampp85\migration-backups\healthbridge\contact-public-v2-20261120\source-before\ and source-before-manifest.json.

Final audit: route HTTP 200; 20 responsive/theme combinations (320, 360, 390, 560, 561, 768, 900, 1024, 1440, 1920px × dark/light) with zero overflow; eight axe WCAG 2.0/2.1 A/AA scans with zero violations; zero browser exceptions, failed requests or local HTTP errors. Skip link, heading landmark, active Contact navigation, CTA form anchor, shared mobile/theme controls, mobile menu Escape behavior and theme switching passed. Empty fields, invalid email and a too-short message were blocked before any request. Success and failure responses were tested with two intercepted/mocked POSTs; zero real API writes occurred. The form request payload matched the existing endpoint contract exactly.

Seven internal page/auth/query targets linked from the page returned HTTP 200. The existing endpoint passed php -l. node --check and scoped git diff --check passed.

Application files changed: contact.html, assets/public-v2/css/pages/contact.css, assets/public-v2/js/pages/contact.js. Initial migration cache references were contact.css?v=2 and contact.js?v=1; after the 21 November owner-review polish, the current Contact CSS reference is contact.css?v=3 and the controller remains v1. Shared footer files, shared runtime, backend/API/PHP, database/schema/data, authentication/session/CSRF/RBAC, SMTP/workers and booking behavior remain unchanged. No dependency, Git staging, commit, push or deployment occurred.

Owner review is the next gate. Do not start another page migration until the Contact page has been reviewed and explicitly approved.


### Contact reveal-motion follow-up — 20 November 2026

Contact marks seven shared Public V2 reveal targets: two hero entrances, three rise targets, and two right-direction guidance-card entrances. A normal-motion Playwright pass scrolled through the full page and confirmed all seven reveal animations started and finished, with no page errors. Separate reduced-motion and missing-IntersectionObserver runs confirmed all seven targets remain visible without pending/enter classes. Runner: D:\dev-toolkit\scripts\healthbridge-contact-motion-audit.mjs.


## About page Public V2 integration — 21 November 2026

The `about.html` route has now migrated to the standalone Public V2 asset layer.

- The route loads `tokens.css`, `base.css`, `components.css`, `pages/about.css`, and the shared `footer.css`. It loads the shared `site.js` and canonical `footer.js` only.
- The new page-owned presentation is `assets/public-v2/css/pages/about.css`. No page-specific controller is needed: About has no unique stateful interaction, and the shared runtime handles its theme/navigation and 15 `data-public-reveal` targets.
- The About HTML retains the existing route and project-story purpose, provides the accessible skip link, and explicitly frames HealthBridge as a student-built software demonstration with synthetic records only—not a healthcare provider.
- The legacy `assets/css/pages/about.css` remains untouched for worktree safety and is no longer referenced by the active route.
- The canonical shared footer markup and computed styles match Home exactly in dark and light themes.

Runner: `D:\dev-toolkit\scripts\healthbridge-about-public-v2-audit.mjs`.  
Result: `C:\xampp85\migration-backups\healthbridge\about-public-v2-20261121-114539\qa\about-public-v2-results.json`.

Automated local gate: **PASS** — HTTP 200; 20 responsive/theme cases at 320/360/390/560/561/768/900/1024/1440/1920 px with zero overflow; eight axe WCAG 2.0/2.1 A/AA scans with zero violations; 35 anchors and seven unique internal targets with no failures; zero page/console errors, failed requests, local HTTP errors or non-GET API requests; no legacy CSS/JS or external font/icon assets. Shared navigation and theme behavior passed. All 15 reveal targets complete under normal motion and remain visible with reduced motion or without IntersectionObserver.

Backup and hash manifests: `C:\xampp85\migration-backups\healthbridge\about-public-v2-20261121-114539\source-before\`, `manifest.json` and `docs-manifest.json`.

No shared Public V2 runtime, footer source, page route destination, PHP/API/backend, database, auth/session/CSRF/RBAC, role or booking behavior changed. No dependency, Git staging, commit, push or deployment.

**Current review gate:** About awaits the owner's visual review. The requested next public page is Services; do not begin it until About is approved. Dashboards remain last.
