# HealthBridge Doctors Hero — Live Directory Samples
Date: 21 November 2026

## Scope and outcome
Replaced only the Doctors hero preview's placeholder profile row with three real synthetic profiles already rendered by the directory below. The non-interactive mock search field was removed and replaced with a truthful directory-count line. The dark-green shell, brand/status header, “Specialist discovery.” heading, three department chips and attached floating note remain. The preview's original desktop/mobile position and size were preserved within the measured 2px tolerance.

## Directory-backed profiles
The samples were selected by department from the actual public directory's rendered cards, and their name, experience and displayed availability were checked against that rendering.

| Department | Profile | Experience | Availability as rendered in the directory |
|---|---|---:|---|
| Cardiology | Dr. Ahmed Hassan | 12 years | Next: Tomorrow • 9:30 AM |
| Dentistry | Dr. Yasmin Mansour | 15 years | Next: Wed, Nov 25 • 10:45 AM |
| Dermatology | Dr. Tarek Mansour | 8 years | Next: Mon, Nov 23 • 9:00 AM |

The public read-only API confirmed **32 active sample doctor profiles and 9 active departments**. The count line reads: **32 sample doctors · 9 departments**. The nine department names are Cardiology, Dentistry, Dermatology, General Practice, Gynecology, Neurology, Ophthalmology, Orthopedics and Pediatrics.

All values above are synthetic directory records. Availability labels reflect the page's existing public display transformation at the time of verification; the directory controller and its behavior were not changed.

## Motion and responsive behavior
- Three stacked mini profile cards rotate in a **15-second full cycle**, approximately **5 seconds per profile**.
- Crossfade uses CSS opacity only. No JavaScript timer/loop, dependency, external asset or extra request was added.
- Hover and keyboard focus pause all profile animations.
- `prefers-reduced-motion: reduce` removes the animation and leaves the first profile, Dr. Ahmed Hassan, visible.
- Both light and dark theme states are covered. The stack simplifies at 390px and 320px with no document overflow, clipping or label overlap.
- Hero-to-following-section gap: **72px desktop**, **65px at phone widths**, within the requested 65–90px rhythm.
- The floating note remains attached to the preview shell; its text does not cover the caption or sample profile copy.
- Stylesheet cache reference: `assets/public-v2/css/pages/doctors.css?v=9`.

## Validation
**Focused hero audit: PASS**
- 78/78 assertions across six width/theme states (1440, 390 and 320px × dark/light).
- Four reduced-motion states all passed.
- Four axe WCAG 2.0/2.1 A/AA scans: zero violations.
- Hover pause and keyboard-focus pause both verified.
- Confirmed count, samples, exact directory parity, unchanged heading/chips, shell position/size, text fit, non-overflow, hero rhythm, caption and floating-note clearance.
- Zero page/console errors, failed requests, local HTTP errors, blocked writes or external requests.

**Broader Doctors page regression: PASS**
- HTTP 200; 32 real directory cards and all three public GET endpoints returned HTTP 200.
- 19/19 existing directory/modal interactions passed.
- 8 responsive/theme listing layouts (320/390/768/1440px × light/dark): zero overflow and correct card grid layout.
- 10 axe A/AA scans including the two theme states of the profile dialog: zero violations.
- Zero browser exceptions and non-CSP console errors.

Raw QA evidence:
- `C:\xampp85\migration-backups\healthbridge\doctors-hero-live-samples-20261121\qa\doctors-hero-live-samples-audit.json`
- `C:\xampp85\migration-backups\healthbridge\doctors-hero-live-samples-20261121\qa\full-doctors-page-results.json`

## Files and boundaries
Application changes are limited to:
- `doctors.html` — hero count line and three directory-backed sample profile cards; stylesheet cache version v9.
- `assets/public-v2/css/pages/doctors.css` — stacked card styling, 15s opacity rotation, pause/reduced-motion behavior and small-screen fit/spacing.

The directory itself, its JavaScript controller, GET APIs, profile cards, filters, modal, booking flows, PHP, database, authentication/session/CSRF/RBAC, shared foundation and other routes were not modified. No dependencies were added. No Git staging, commit, push or deployment occurred. No screenshots were created or saved.

Pre-edit copies and matching SHA-256 values are in `C:\xampp85\migration-backups\healthbridge\doctors-hero-live-samples-20261121\source-before\`. Final source/evidence hash manifest: `final-source-manifest.json`.
