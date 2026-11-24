# HealthBridge Doctors Hero — Rotation Construction Fix
Date: 21 November 2026

## Fault and construction change
Rebuilt the three-profile crossfade as one shared CSS timeline rather than correcting individual card phases independently. No JavaScript timer, interval, frame loop or per-card duration was involved or added; the Doctors page controller is untouched.

All three profile cards use the single `doctorsSampleProfileCycle` keyframes definition and the same `15s linear infinite` animation. Their only timing difference is:
- Dr. Ahmed Hassan: `animation-delay: 0s`
- Dr. Yasmin Mansour: `animation-delay: 5s`
- Dr. Tarek Mansour: `animation-delay: 10s`

The shared keyframes are: `0% opacity:0`; `6% opacity:1`; `29.333% opacity:1`; `40% and 100% opacity:0`. This fades in during the first 0.9s, holds, then fades out from 4.4s to 6s. Each next card begins at the five-second mark, creating a deliberate **one-second overlap** while the outgoing card is still fading out. The same timeline repeats continuously every 15 seconds.

## Identical card boxes and slot
Each card is `position:absolute; inset:0` within the same fixed-height slot. They share the same 10px 12px padding, centred vertical alignment and 10px internal gap. The previous per-card top/right/bottom/left offsets are removed.

The slot is fixed at:
- **73px** for widths above 380px.
- **85px** at 380px and below.

Those are responsive slot heights; within either layout all three card rectangles are identical. The tallest-content requirement is met without card-specific dimensions or position changes.

## Retained behavior
- Hover and keyboard focus pause all three cards.
- `prefers-reduced-motion: reduce` disables the animation and leaves Dr. Ahmed Hassan (the first profile) statically visible.
- The three profiles, exact directory data, count line, caption, department chips, hero shell and floating chip are unchanged.
- No JavaScript/controller, directory, API, database, theme, authentication or other page code changed.
- No screenshots were created or saved.

## Focused validation
**PASS**
- 36 focused assertions across 1440/390/320px in dark and light themes.
- Verified exactly one shared keyframes definition and one animation declaration, no profile-rotation code in page JS, and delays exactly 0/5/10 seconds.
- Verified all cards have identical bounding rectangles, zero insets, identical padding/alignment and a shared 15-second linear infinite animation.
- Verified three overlap hand-offs at 5.1s, 10.1s and 15.1s. At each hand-off two adjacent cards had non-zero opacity (approximately 0.13–0.55), confirming a true crossfade.
- Sampled the full cycle every 100ms after animation start; minimum combined card opacity was 0.1297, with no blank slot.
- Hover pause and keyboard-focus pause both passed.
- Reduced-motion behavior passed at four states (1440/390 × dark/light): first profile opacity 1, other profiles 0, animation disabled.
- Zero page errors, console errors, failed requests, local HTTP errors, blocked write requests or external requests.

Raw evidence: `C:\xampp85\migration-backups\healthbridge\doctors-hero-rotation-construction-20261121\qa\rotation-construction-audit.json`.

## Changed files and safety boundary
Only application files changed:
- `doctors.html` — stylesheet cache reference bumped to `doctors.css?v=10`.
- `assets/public-v2/css/pages/doctors.css` — shared profile timeline and identical fixed card boxes.

Pre-edit source backups and matching SHA-256 values are under `C:\xampp85\migration-backups\healthbridge\doctors-hero-rotation-construction-20261121\source-before\`. Final source/evidence hash manifest: `final-source-manifest.json`.

No screenshots; no staging, commit, push or deployment. The broader HealthBridge milestone regression remains scheduled for the end of Milestone 3.
