# HealthBridge — Home Hero Three-Journey Photo Rotation

Date: 21 November 2026  
Scope: Home hero only. No other page or application area was changed.

## Implementation

- Kept the existing Patient image at `assets/images/healthbridge-doctor-consultation.jpg` unchanged. Its existing alt text is unchanged.
- Added the owner-selected Pexels images by Tima Miroshnichenko: photo 5452300 for Doctor and photo 5452242 for Admin. Both are stored locally; the page does not hotlink to Pexels.
- All three images share the existing source dimensions/aspect ratio: 1600×1067 (approximately 3:2). The two new JPEGs were re-encoded as optimized progressive JPEGs:
  - `healthbridge-hero-doctor.jpg`: 108,925 bytes.
  - `healthbridge-hero-admin.jpg`: 196,234 bytes.
- Photos occupy the same absolutely positioned stage with `object-fit: cover`; only opacity changes. The transition is 900ms. Removed the prior fixed image scale so there is no zoom/Ken Burns effect.
- Converted the existing Patient / Doctor / Admin pills into accessible buttons with `aria-pressed`. Their existing pill appearance is retained; the active state tracks the visible photo.
- Auto-advance moves through Patient → Doctor → Admin every seven seconds. A pill click selects that photo and permanently disables auto-advance for the rest of the visit.
- Pointer hover anywhere in the hero or keyboard focus anywhere inside it pauses the timer. The timer restarts with a fresh six-second interval after the pointer/focus leaves.
- With `prefers-reduced-motion: reduce`, Patient is shown statically, auto-advance is disabled and image transitions are removed. Manual pill selection remains available.
- Patient remains eager-loaded with high fetch priority. Doctor/Admin use `loading="lazy"` and deferred `data-src`; their source URLs are assigned only after the browser reports first contentful paint and an idle callback is scheduled.
- Hero heading, subtext, main actions, demo invitation, photo caption, photo box sizing, and all non-hero Home content remain unchanged.

## Focused QA

- JavaScript syntax check: PASS (`node --check`).
- Playwright photo-rotation audit: **44/44 checks passed**.
  - Confirmed initial Patient image, exact alt text, 1600×1067 dimensions, and lazy/eager loading priorities.
  - Confirmed alternate image requests started after first contentful paint in the measured run (FCP 576ms; alternate requests 606.2ms and 606.4ms).
  - Confirmed the six-second Doctor/Admin auto-advance order, manual selection lock, pointer pause/resume, focus pause/resume, and reduced-motion behavior.
  - Confirmed stable photo-stage dimensions at 1440px (570×560), 390px (354×420), and 320px (284×420); zero horizontal overflow at all three widths.
  - Zero browser/console errors, failed requests, local HTTP errors, or API write attempts.
- axe WCAG 2.0/2.1 A/AA scans scoped to the Home hero: four scans (dark/light × 1440/390px), zero violations, 18 passes per scan and two incomplete/manual-review items per scan.
- QA runners:
  - `D:\dev-toolkit\scripts\healthbridge-home-hero-photo-rotation-audit.mjs`
  - `D:\dev-toolkit\scripts\healthbridge-home-hero-photo-rotation-axe.mjs`
- Raw evidence:
  - `C:\xampp85\migration-backups\healthbridge\home-hero-photo-rotation-20261121\qa-results.json`
  - `C:\xampp85\migration-backups\healthbridge\home-hero-photo-rotation-20261121\axe-results.json`

## Changed files and backup

Changed application files:
- `index.html` — Home hero image markup, button controls, and Home-only cache keys (`home.css?v=4`, `home.js?v=2`).
- `assets/public-v2/css/pages/home.css` — opacity-only photo layers, button styling, reduced-motion transition rule.
- `assets/public-v2/js/pages/home.js` — deferred loading, rotation, active-state, pause/resume, manual-stop and reduced-motion behavior.
- `assets/images/healthbridge-hero-doctor.jpg` (new).
- `assets/images/healthbridge-hero-admin.jpg` (new).

Source backup and initial SHA-256 manifest:
`C:\xampp85\migration-backups\healthbridge\home-hero-photo-rotation-20261121\source-backup-manifest.json`

No backend/PHP/API, database/schema/data, authentication/session/CSRF/RBAC, booking logic, shared footer, Services, About, Doctors, Contact, dashboards, workers/SMTP, or hosted resource was changed. No dependency was added. Nothing was staged, committed, pushed, or deployed.

This is focused Home hero QA, not the final full HealthBridge regression suite. The implementation is ready for owner visual review. Existing About review/approval gate remains unchanged; Services is still next only after About approval, with dashboards last.


## 21 November 2026 — Owner-requested interval adjustment

- Changed the automatic role-photo interval from six to seven seconds. Both the initial timer and recurring successful-advance timer now use 7000ms; the 1200ms retry remains only for an image-load failure.
- Targeted Playwright smoke test PASS: Patient remains active at 6.3 seconds; Doctor becomes active immediately after seven seconds. Zero browser errors.
- Only assets/public-v2/js/pages/home.js was edited for behavior; index.html now references home.js?v=3 to invalidate the cached controller. The JS and index.html backups plus before/after SHA-256 are recorded under C:\xampp85\migration-backups\healthbridge\home-hero-photo-rotation-20261121\seven-second-adjustment\. JavaScript syntax check passed.
- The original 44-check suite and four axe scans were run before this timing-only adjustment; the targeted smoke test directly validates the new seven-second interval.
