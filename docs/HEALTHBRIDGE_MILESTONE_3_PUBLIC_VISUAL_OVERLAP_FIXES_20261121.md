# HealthBridge Milestone 3 — Public-page visual overlap corrections

**Date:** 21 November 2026  
**Status:** Implemented locally; focused responsive layout checks passed.

## Corrections

- **Home (index.html):** The HEALTHBRIDGE chip in the hero photo no longer receives both left and right offsets on phone widths. It now sizes to its text, with a safe maximum width, rather than stretching the translucent background across the image.
- **Contact (contact.html):** The “A student-built project · Feedback only” caption is no longer absolutely layered over the feedback sheet. It now occupies its own grid row after the sheet and uses a readable high-contrast surface, so it remains visible on narrow screens.
- **Services (services.html):** The example-workflows disclaimer remains inside the overview card. The floating “Different permissions, connected workflows” note now sits in reserved space below the card instead of covering the disclaimer. The reserved area is sized for desktop and mobile note heights.

All page copy and interaction behavior are unchanged. The three stylesheet cache references were incremented so browsers request the fixes: Home home.css?v=5, Contact contact.css?v=5, Services services.css?v=2.

## Focused QA

**PASS: 48/48 Playwright layout assertions.**

The runner tested Home, Contact and Services at 320, 360, 390, 560, 640, 768, 1024 and 1440 CSS pixels, in both light and dark color schemes.

- Home: hero label stays content-sized and its text does not overflow.
- Contact: caption starts below the feedback sheet and stays inside the visual panel.
- Services: disclaimer and floating note do not overlap; the note stays inside the reserved visual area.
- Zero failed assertions and zero recorded page/console errors.

Runner: D:\dev-toolkit\scripts\healthbridge-visual-overlap-fixes-check.mjs  
Raw result: C:\xampp85\migration-backups\healthbridge\public-v2-visual-overlap-fixes-20261121\layout-check.json

This was a focused layout check, not a full page accessibility audit or final HealthBridge regression.

## Scope and safety

Only these six application files changed:

- index.html
- contact.html
- services.html
- assets/public-v2/css/pages/home.css
- assets/public-v2/css/pages/contact.css
- assets/public-v2/css/pages/services.css

Pre-edit copies and hashes: C:\xampp85\migration-backups\healthbridge\public-v2-visual-overlap-fixes-20261121\source-before\  
Final source hash manifest: C:\xampp85\migration-backups\healthbridge\public-v2-visual-overlap-fixes-20261121\final-source-manifest.json

No JavaScript, shared component, backend/API, database, authentication/session/CSRF/RBAC, booking logic, workers, SMTP, dependencies or hosted resources changed. Nothing was staged, committed, pushed or deployed.