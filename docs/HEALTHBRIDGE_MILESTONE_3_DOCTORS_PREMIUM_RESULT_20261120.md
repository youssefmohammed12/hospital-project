# HealthBridge — Doctors Directory premium redesign result

**Date:** 20 November 2026  
**Scope:** Public Doctors directory only  
**Status:** Implemented; automated local gate passed; awaiting owner visual approval  
**Route:** `http://127.0.0.1/optimized/doctors.html`

## Direction and design

The approved homepage direction has been carried into the directory: deep-green anchors, warm ivory/sage surfaces, restrained teal actions, brass/gold details, editorial serif display headings, quieter sans-serif body text, fine borders and restrained shadows. The light theme keeps readable light surfaces while retaining the same deep-green footer as dark mode.

The previous legacy directory presentation was replaced rather than layered under permanent overrides. The page now has a composed editorial hero, a CSS-built directory preview, a short discovery highlight strip, a dedicated search/filter panel, refined specialist cards, a clear process section, a truthful portfolio CTA and the approved homepage footer anatomy. No external doctor/clinic imagery, fake operational contact details, patient testimonials or invented performance claims were added. The visual preview is explicitly labelled as illustrative; the 32 loaded profiles remain synthetic demo records.

The footer repeats the approved homepage treatment: preserved brand description and mark, Explore / Your access / Departments groups, five existing department query links plus All departments, copyright and one scope disclaimer. The mint CTA remains directly above the deep-green footer.

## Interaction and presentation work

- Rebuilt only `doctors.html`, `assets/css/pages/doctors.css` and `assets/js/pages/doctors.js`.
- Replaced the prior page CSS with one owned, responsive implementation; added breakpoints for phone, tablet and desktop. Dynamic doctor cards use consistent initials, specialty labels, clear availability and experience/rating presentation.
- Replaced directory Font Awesome icon markup with small inline SVGs for chips, schedule details and modal information. Font Awesome remains referenced at the page shell because the shared signed-in navigation can inject its own icons; shared navigation code was not changed.
- Added restrained hero choreography and scroll-triggered section/card reveals using transform/opacity only. Reveal timing begins as content enters the reading area (96px bottom inset); dynamic result cards are registered after rendering. With reduced motion, no entrance/reveal classes are installed. Without IntersectionObserver, content remains visible. No-JavaScript content does not receive hidden reveal styles.
- Improved semantics: skip link, labelled search/sort, department filter group and pressed state, explicit mobile-menu expanded state, live results count, named profile controls, dialog label and keyboard region.
- The profile dialog now isolates background content with `inert` and `aria-hidden`, maintains its focus trap, closes with Escape or its close control, restores prior background state, and returns focus to the triggering profile button.
- Cache references are now `doctors.css?v=4` and `doctors.js?v=6`.

The existing public API and behavior contracts were retained: department, doctor and public-availability GET calls; normalized/fallback record behavior; `?q=` department search; search, sort, filter and empty state; the existing profile data; and role-aware booking handoff for guest/patient/doctor/admin. No write endpoint or database operation was invoked.

## Automated verification

**Comprehensive Playwright + axe-core audit: PASS**
- Route HTTP 200 and 32 profile cards.
- All three unique read-only directory endpoints returned HTTP 200; repeated route loads produced successful GET responses only.
- 12 viewport/theme combinations: 320, 360, 390, 768, 1024 and 1440px in light and dark themes. Zero horizontal overflow, correct 1/2/3-column grid, deep-green footer in both themes, teal brand mark and ivory plus.
- 14 axe-core WCAG 2.0/2.1 A/AA scans: zero violation instances.
- 28/28 interaction checks passed: search, empty state, department filters, All departments reset, alphabetical sort, mobile navigation, theme toggle, URL query handoff, and profile-dialog focus/keyboard behavior.
- Profile dialog role/name, keyboard region, background isolation, Tab/Shift+Tab trap, Escape, close button and focus restoration passed.
- All six footer department routes are present and use the already-supported `doctors.html?q=` behavior; URL query prefills search and activates the matching chip.
- 3/3 motion checks passed: hero entrance, section reveal and dynamic-card reveal. All 5 reduced-motion checks and 4/4 missing-IntersectionObserver fallback checks passed; both cases keep content visible and controls usable.
- Zero failed local assets, zero API mutation requests, zero browser page exceptions and zero non-CSP console errors.

The page still reports CSP warnings from the existing shared stylesheet policy (including the blocked legacy Sora import and an existing inline-style warning). The page now uses a local system font stack; the shared stylesheet and CSP policy were intentionally not changed in this page pass.

## Lighthouse comparison

Local Lighthouse 13.6 throttled lab runs, same local route and runner before/after this presentation pass:

| Profile | Measure | Before | Final |
|---|---|---:|---:|
| Mobile | Performance | 79 | 86 |
| Mobile | Accessibility | 96 | 100 |
| Mobile | Best Practices | 100 | 100 |
| Mobile | SEO | 100 | 60 |
| Mobile | FCP | 2.1s | 3.2s |
| Mobile | LCP | 5.0s | 3.2s |
| Mobile | TBT | 40ms | 0ms |
| Mobile | CLS | 0 | 0 |
| Desktop | Performance | 97 | 98 |
| Desktop | Accessibility | 96 | 100 |
| Desktop | Best Practices | 100 | 100 |
| Desktop | SEO | 100 | 60 |
| Desktop | FCP | 0.6s | 0.9s |
| Desktop | LCP | 1.1s | 0.9s |
| Desktop | TBT | 0ms | 0ms |
| Desktop | CLS | 0 | 0 |

The SEO score reduction is intentional: the local portfolio demonstration retains `noindex,nofollow,noarchive`. Lighthouse identifies the page as blocked from indexing; do not remove the project's noindex policy to inflate this category. FCP varied upward in these local runs while LCP, TBT and the aggregate Performance scores improved; treat the readings as lab diagnostics, not field Core Web Vitals.

## Source and safety boundary

Application files changed in this page pass:
- `doctors.html`
- `assets/css/pages/doctors.css`
- `assets/js/pages/doctors.js`

No other page, shared `assets/css/style.css`, shared `assets/js/core/main.js`, PHP, API, database, authentication/session/CSRF/RBAC, dashboard or business logic was changed. Existing unrelated dirty worktree items were preserved. No dependency was added, and there was no staging, commit, push, deployment, branch operation, .env inspection or DB mutation.

Pre-edit source copies:
`C:\xampp85\migration-backups\healthbridge\milestone3-doctors-refresh-20261120\source-before\`

SHA-256 after this pass:
- `doctors.html`: `5aa126120fd036fbfee0062dd397a5ecb55fce15e9cf29c2bfb255fa7c879aa5`
- `assets/css/pages/doctors.css`: `881e8a0f14e8943a2b864a716b1d1b5749d7ae3e33b45d5fb4ac91f023b4af51`
- `assets/js/pages/doctors.js`: `4822709b0a4949390e807d8589bfe2d0d1221d41288b1a23b043ade858ce41c5`

Evidence:
- Playwright/Axe runner: `D:\dev-toolkit\scripts\healthbridge-m3-doctors-premium-audit.mjs`
- Playwright/Axe JSON: `C:\xampp85\migration-backups\healthbridge\milestone3-doctors-refresh-20261120\doctors-premium-audit.json`
- Lighthouse baseline/final JSON: same dated evidence folder (`lighthouse-before-*.json`, `lighthouse-final-*.json`).
- Before-source manifest and rollback copies: same dated evidence folder.

## Owner review gate

This directory is a page-local candidate pending visual review. Please hard refresh the local Doctors page and review both themes at desktop and mobile widths. Try search, department chips, sort, a profile open/close, Escape/focus return, and the scroll reveals. Do not roll this page treatment to another page or begin another page until the owner approves this actual integration and says `next`.


## Doctors hero live directory samples — 21 November 2026

The hero's single generic “Sample profile” row has been replaced by three cards using the real synthetic entries rendered by the directory: **Dr. Ahmed Hassan — Cardiology — 12 years — Next: Tomorrow • 9:30 AM**; **Dr. Yasmin Mansour — Dentistry — 15 years — Next: Wed, Nov 25 • 10:45 AM**; **Dr. Tarek Mansour — Dermatology — 8 years — Next: Mon, Nov 23 • 9:00 AM**. Profile values were read from the rendered directory cards rather than copied from independently invented data.

The read-only public endpoints confirm **32 sample doctors · 9 active departments**. The mock search dressing was removed and replaced by that non-interactive count line. The existing deep-green preview shell, header, “Specialist discovery.” heading, department chips and attached floating note remain. Caption: “Samples from the directory below · Synthetic records”.

The three stacked cards crossfade with a 15s complete CSS-only opacity cycle (~5s per profile); hover/focus pauses all slides and reduced motion shows the first profile statically. No JS loop/timer/dependency/asset. Mobile uses a compact stack at 390/320; hero-to-next-section spacing is 72px desktop / 65px phone. Cache ref `doctors.css?v=9`.

Focused hero: 78/78 assertions, six responsive/theme states (1440/390/320 × light/dark), four reduced-motion states, four axe A/AA scans zero violations, hover/focus pause pass, no overflow, page/network/console errors, blocked writes or external requests. Broader Doctors regression: 19/19 existing interactions; 8 responsive/theme listing layouts no overflow/grid failure; 10 axe A/AA scans zero violations including modal; three GET endpoints HTTP 200; 32 rendered profiles; zero browser exceptions/non-CSP console errors.

Full note: `docs/HEALTHBRIDGE_MILESTONE_3_DOCTORS_HERO_LIVE_SAMPLES_20261121.md`. Raw QA JSON and pre-edit backups are under `C:\xampp85\migration-backups\healthbridge\doctors-hero-live-samples-20261121\`. No screenshots were created or saved. Only `doctors.html` and `assets/public-v2/css/pages/doctors.css` changed; no controller/API/DB/auth/security/other-page changes, no dependency, no stage/commit/push/deploy.


## Doctors hero rotation construction repair — 21 November 2026

- Replaced the offset/inconsistent profile rotation with one shared `doctorsSampleProfileCycle` keyframes definition: `15s linear infinite`, with the only per-card difference being `animation-delay: 0s / 5s / 10s`.
- Shared phase: 0% opacity 0 → 6% opacity 1 → 29.333% held at 1 → 40% opacity 0 → 100% opacity 0. Each next profile enters at the five-second slot boundary while the previous one is still fading out, giving a deliberate ~1s crossfade overlap.
- Removed per-card positional offsets. All three cards are absolute with `inset:0` and the same padding, gap and centre alignment. Fixed slot CSS height is 73px above 380px and 85px at/under 380px; every card box is identical at each responsive size.
- No profile rotation timer/loop in JS. Hover/focus pause remains. Reduced motion leaves Dr. Ahmed Hassan visible with the other two hidden.
- Focused QA: 36 assertions over 1440/390/320 × light/dark; three hand-off moments measured two simultaneously visible cards; full 15s cycle sampled every 100ms without a blank slot (minimum opacity sum 0.1297 during initial fade); pause/reduced-motion states all passed; no page/console/network errors, blocked writes or external requests.
- Cache ref `doctors.css?v=10`. Full detail: `docs/HEALTHBRIDGE_MILESTONE_3_DOCTORS_HERO_ROTATION_FIX_20261121.md`; evidence at `C:\xampp85\migration-backups\healthbridge\doctors-hero-rotation-construction-20261121\qa\rotation-construction-audit.json`.
- Only `doctors.html` and page-owned `assets/public-v2/css/pages/doctors.css` changed. The profiles/data/count/caption/chips/floating chip, directory controller, APIs and all other routes remain untouched. No screenshots saved; no stage/commit/push/deploy.
