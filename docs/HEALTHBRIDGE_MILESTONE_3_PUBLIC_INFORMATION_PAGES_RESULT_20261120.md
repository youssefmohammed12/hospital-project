# HealthBridge Milestone 3 — Public Information Pages Result

**Date:** 20 November 2026  
**Scope:** About, Services/Project Capabilities, and Contact/Project Feedback  
**Status:** Integrated locally; automated page gates passed. Manual owner/assistive-technology review remains open.

## What changed

The three legacy public information pages have been redesigned as part of the HealthBridge student portfolio demonstration. The work is a real presentation/UX rebuild—not only code maintenance.

- **About:** Replaced the fictional hospital origin/impact story with a project overview, honest student-project framing, design principles, and the three synthetic role workspaces. Added a custom CSS product/workspace illustration, responsive content sections and direct discovery/sign-in calls to action.
- **Services:** Reframed the page as a capability catalogue for the Patient, Clinician and Administration workspaces. It now has six capability cards, accessible role filters, a connected-workflow explanation and a project-scope FAQ. Old claims about real clinical services, insurance, remote consultations and hospital diagnostics were removed.
- **Contact:** Reframed the page as a project-feedback form. Removed the fictitious emergency number, clinic address, phone directory, parking/transport claims, Leaflet map and fabricated social links. Added a clear warning that this is not a healthcare provider, and that the feedback form is not for medical or sensitive information.

All three pages now consistently identify HealthBridge as a student-built software demonstration and explicitly state that sample records are synthetic. The public pages are marked noindex, nofollow and noarchive. Existing global theme/shell assets remain shared; each page now owns its layout and responsive CSS.

## Preserved contracts and boundaries

- The Contact form retains the original form ID, field IDs/names (name, email, phone, department, subject, message), and its existing validation/result elements.
- assets/js/pages/contact.js and api/settings/contact.php were not changed in this page group. The existing same-origin contact API remains the only submission destination; the prior third-party Formspree fallback had already been removed in the preceding work.
- The form copy accurately explains that accepted messages are stored in the application's contact-message table and are not emailed by this endpoint. Synthetic details only.
- assets/js/pages/services.js now owns only the new capability-filter and FAQ interactions. Filters update their aria-pressed state and a live result count; the single-open FAQ updates aria-expanded, hidden and visual state together.
- Existing login/register routes, main.js?v=7, shared navigation and global theme behavior remain in use.
- No backend/API/auth/session/CSRF/RBAC/role/booking logic, database schema/data, SMTP or worker configuration was changed. No contact form submission or other POST was performed. The only contact interaction test was an intentionally blank submit to verify the client-side required-field message.

## Automated validation

| Gate | Result |
|---|---:|
| Public routes HTTP 200 | 3/3 |
| axe WCAG 2.0/2.1 A/AA scans | 24/24, zero violations |
| Responsive theme/layout cases | 24/24, zero horizontal overflow |
| Viewports | 320, 390, 768, 1440 px |
| Themes | Dark and light |
| Internal anchor groups checked | About 22, Services 25, Contact 18 |
| Unique internal targets per page | 7 each; all returned HTTP 200 |
| Services filter + FAQ click assertions | Passed |
| Services filter + FAQ keyboard assertions | 4/4 passed (Enter and Space) |
| Contact form field contract | Preserved |
| Contact empty-field validation | Passed; no submission |
| Browser page exceptions | 0 |
| Non-CSP browser console errors | 0 |
| Local HTTP errors | 0 |
| POST requests during QA | 0 |
| PHP contact handler syntax | php -l passed |

The three-route result JSON is at:
C:\xampp85\migration-backups\healthbridge\milestone3-public-info-20261120-1525\public-info-results.json

Repeatable scripts:
- D:\dev-toolkit\scripts\healthbridge-m3-public-info-audit.mjs
- D:\dev-toolkit\scripts\healthbridge-m3-public-info-link-audit.mjs
- D:\dev-toolkit\scripts\healthbridge-m3-public-info-keyboard-audit.mjs

## Rollback evidence

Exact source copies and SHA-256 manifest were created before this page group was edited:
C:\xampp85\migration-backups\healthbridge\milestone3-public-info-20261120-1525\source-before\

The manifest is:
C:\xampp85\migration-backups\healthbridge\milestone3-public-info-20261120-1525\manifest.json

Eight files were backed up: three HTML templates, three page-owned CSS files, and the Services and Contact controllers. The copied files are preserved unchanged. No Git staging, commit, push or deployment was performed.

## Remaining work

- Manual keyboard-only and assistive-technology review; automated axe does not replace it.
- Manual visual review/owner acceptance of the presentation and provisional visual tokens.
- Continue the M3.5 redesign with the Patient portal, then Doctor workspace and Admin area.
- Run wider regression and performance profiling in M3.6/M3.7. This local page result is not a production-readiness sign-off; hosted P0*/P1 release gates remain open.

## Follow-up compatibility verification

A final contract review found that the previous Services controller also performed server-session synchronization when no local browser identity was present. That behavior was retained: the redesigned Services controller calls the existing getSessionUser helper when getUser returns no local identity, then changes the six capability links and bottom CTA to the role dashboard only when a user is returned.

- Isolated synthetic role-route audit passed for patient, doctor and admin. All six capability links and the bottom CTA point to the correct role dashboard; the patient route retains the existing #book fragment. These tests set only a synthetic hb_user value in disposable Playwright contexts and perform zero current-user requests and zero POSTs.
- In the unauthenticated public audit, the existing current_user.php GET was observed once and returned HTTP 401 as expected for a guest. The response and associated browser resource message are recorded separately in public-info-results.json; they are not classified as an unexpected application console/HTTP error.
- No authentication operation, sign-in or role-protected page access was performed.

Additional repeatable test: D:\dev-toolkit\scripts\healthbridge-m3-public-info-role-routing-audit.mjs
Keyboard test: D:\dev-toolkit\scripts\healthbridge-m3-public-info-keyboard-audit.mjs
