# HealthBridge Milestone 3 — Register animation set

Date: 21 November 2026  
Status: Implemented locally; focused verification PASS. Owner review remains open.

## Scope and non-negotiable constraints

Implemented the four owner-approved animation groups on Register only. The existing Register story heading and paragraph remain. The old role-connection diagram has been replaced with a live patient-card preview; existing feature cues remain beneath it.

The preview is a client-side presentation of what the visitor is entering. It is labelled as a draft preview and does not represent a submitted/verified patient record until the existing registration endpoint accepts the final request.

No new image assets, dependencies, canvas, framework, API endpoint, database field, PHP handler, or business rule was added or changed.

## 1. Patient card assembly

- Replaced the Register-only left-panel role diagram with an ivory HealthBridge patient-profile card, retaining the panel heading and supporting paragraph.
- First/last name values update the card through textContent while typing. The avatar shows the entered initials. Before entry it displays “Your name” and a neutral plus mark.
- The detail and status fields reserve fixed vertical space. Their initial copy explicitly says this is a preview, not a submitted record; empty values never masquerade as actual patient data.
- After the existing Step 1 validation request succeeds, the card gets a brass top band and performs a single 520ms gentle 3D tilt flourish. A state guard prevents that flourish from replaying when visitors go back and edit their account information.
- After the existing Step 2 client-side validation passes, the details line shows the selected blood type (when present) and the card status becomes “Profile complete”.
- Only after the existing final Step 2 API request succeeds does the preview show “Emergency contact on file.” and update its badge to “REGISTRATION RECEIVED”. The wording is not shown as a submitted state before acceptance.
- Editing earlier completed details invalidates the corresponding preview-complete status without creating a request.

Performance: text updates use ordinary DOM text nodes. The band and one-time card flourish use CSS transforms; the card uses static borders/shadows, with no animated blur, filter or box-shadow. Measured card width/height remain unchanged while the visitor's name and initials update.

## 2. Slow aurora

Two low-opacity radial-gradient glows sit behind the card in the deep-green story panel:
- Gold: 20-second transform-only drift.
- Teal: 22-second transform-only drift, phase-offset so the two fields move across one another.

Each glow has a fixed blur(34px) filter. The filter is not animated; only the layer transform changes. Opacity remains restrained and the card sits above both fields. No new image asset or live blur effect is used.

Reduced motion hides the aurora entirely.

## 3. Stepper and transitions

- The connector is a single gold fill layer whose transform uses scaleX. It advances as the completed-step count changes, with a 400ms transition.
- Each of the three step circles contains an inline SVG checkmark. The number fades away and the SVG stroke draws from dash offset 22 to zero over 350ms. The SVG occupies the existing circle, so the stepper geometry does not grow or move.
- The active step enters with a 250ms opacity and slight translateY crossfade.

Performance: line fill and step transitions animate transform/opacity; the check is a short SVG stroke-dashoffset animation. No continuous paint-heavy effect is attached to the stepper.

## 4. Micro-polish

- Inputs, selects and textareas on Register draw a brass underline on focus over 200ms. The effect is a one-shot background-line draw, not a loop; existing input geometry and focus outline remain.
- “Next Step” and final submit buttons receive a narrow gradient shine sweep on a five-second cycle. The shine pseudo-element is clipped inside the button and translates using transform only. It stops immediately on hover/press and while the button is disabled.

No animated box-shadow, blur or filter is used.

## Reduced motion

Under prefers-reduced-motion: reduce:
- Aurora is hidden.
- The card does not flip; the gold band and checkmark are rendered in their final static states.
- Step and underline drawing animations/transitions stop.
- Button shine is removed.
- The patient-card copy and initials continue to update because those are content changes, not motion.

The reduced-motion full registration flow was tested separately from the normal-motion flow.

## Verification

Runner:
D:\dev-toolkit\scripts\healthbridge-register-animation-audit-20261121.mjs

Raw evidence:
C:\xampp85\migration-backups\healthbridge\register-animation-set-20261121\qa\register-animation-audit.json

Final result:
- **114/114 assertions PASS.**
- Five visual states: 320px dark, 390px light, 768px dark, 1440px light, and 390px dark with reduced motion.
- Two complete mocked registration flows: normal motion at 390px and reduced motion at 320px.
- Three axe WCAG 2.0/2.1 A/AA scans; zero violations.
- Live name/initials, honest placeholders, card dimension stability, one-time flourish, gold band, stepper fill/check draws, Step 2 blood-type detail, final emergency line and unchanged final payload key set all pass.
- Aurora computed blur remains unchanged while its transform moves. Button shine is confirmed at five seconds and pauses on hover.
- No horizontal overflow, failed requests, external requests, JavaScript exceptions or unexpected console errors.
- All registration POST requests were intercepted. No real registration, database mutation, login or email send occurred.
- No screenshots were created or saved.

## Isolation and source ownership

Register loads only Public V2 assets. Browser inspection found an empty legacy-asset list; a separate HTML source scan also found no references to assets/js/core/main.js, assets/js/core/auth.js, assets/css/style.css, or legacy page assets.

Register's stylesheet is assets/public-v2/css/pages/auth.css?v=4. Its page controller is assets/public-v2/js/pages/auth-entry.js?v=3. The shared Login page uses the same files, but the new visual effects are scoped to .auth-register-page; Login retains its existing presentation.

No backend/API/auth/session/CSRF/RBAC/database/business logic changes. No stage, commit, push or deployment.

## Backup and handoff

Pre-edit source copies and SHA-256 manifest:
C:\xampp85\migration-backups\healthbridge\register-animation-set-20261121\source-before\
C:\xampp85\migration-backups\healthbridge\register-animation-set-20261121\source-before-manifest.json

Final-source manifest:
C:\xampp85\migration-backups\healthbridge\register-animation-set-20261121\final-source-manifest.json

Legacy references on this page: none.
