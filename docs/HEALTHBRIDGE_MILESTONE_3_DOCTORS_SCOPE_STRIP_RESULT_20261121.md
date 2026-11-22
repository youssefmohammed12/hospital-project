# HealthBridge — Doctors Directory Scope Band

Date: 21 November 2026  
Scope: Doctors page only.

## What changed

The standalone one-line directory disclaimer now has its own full-width information band, visually aligned with the About page's project-scope band.

- Added the small-caps brass label **ABOUT THIS DIRECTORY**.
- Added a full-width band surface with fine top and bottom borders.
- In light mode the band is white; in dark mode it uses the Doctors page's deep-green surface token. Text and border colors remain theme-aware.
- Removed the old circular “i” marker.
- Preserved the existing disclaimer body word-for-word:

  > HealthBridge is a student-built software demonstration. All profiles and records are synthetic; this directory does not provide medical advice or access to real healthcare services.

- Moved the new band outside the directory-results section, between the directory and “The Journey” content, so it reads as a deliberate page-level information strip.
- Bumped only the Doctors stylesheet cache reference from `doctors.css?v=7` to `doctors.css?v=8`.

The existing three-column “What you can explore” strip was not modified. About, Contact and every other page remain untouched.

## Focused QA

- Playwright: **8 responsive/theme states**, four widths (1440, 768, 390, 320) × light/dark.
- **80 assertions passed**: exact body copy, label, uppercase/brass treatment, top/bottom hairlines, icon removal, placement, retained three-column discovery strip, stable rendering, light-mode white surface and zero horizontal overflow.
- axe WCAG 2.0/2.1 A/AA: **4 scans**, light/dark × 1440/390px; zero violations, six passes per scan, zero incomplete/manual-review items.
- Zero browser/console errors, failed requests, local HTTP errors or API write attempts.
- Runner: `D:\dev-toolkit\scripts\healthbridge-doctors-scope-strip-audit.mjs`.
- Raw evidence: `C:\xampp85\migration-backups\healthbridge\doctors-directory-scope-strip-20261121\qa-results.json`.

## Changed files and rollback

Only these application files changed:
- `doctors.html`
- `assets/public-v2/css/pages/doctors.css`

Pre-edit source backups and SHA-256: `C:\xampp85\migration-backups\healthbridge\doctors-directory-scope-strip-20261121\source-backup-manifest.json`. Final current hashes: `final-source-manifest.json`.

No shared component, About, Contact, Home, Services, backend/API, database/schema/data, authentication/session/CSRF/RBAC, booking logic, or hosted resource was changed. No dependency was added. Nothing was staged, committed, pushed or deployed.

This was a focused Doctors presentation and accessibility check, not the final full HealthBridge regression suite.
