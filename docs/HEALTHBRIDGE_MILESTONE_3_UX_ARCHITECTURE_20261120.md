# HealthBridge — Milestone 3.1 UX Architecture and Role Journeys

**Date:** 20 November 2026  
**Status:** M3.1 architecture draft completed from current source inspection; proposed information architecture is ready for design-system work and owner review.  
**Baseline reference:** `docs/HEALTHBRIDGE_MILESTONE_3_AUDIT_20261120.md`  
**Milestone sequence:** `docs/HEALTHBRIDGE_MILESTONE_3_PLAN.md`

## 1. Purpose and constraints

This document maps the current HealthBridge navigation, pages and high-value user journeys, then proposes a clearer hierarchy for the redesign. It is based on existing HTML, JavaScript controllers, route parameters and API callers—not imagined features.

The target remains a premium portfolio demonstration using synthetic data. Preserve:
- Existing PHP routes, API contracts, session/role checks and data ownership.
- Existing booking, appointment lifecycle, doctor schedule, clinical encounter, prescription, patient-record, review, notification and admin workflows.
- Existing demo account behavior, while keeping it visually distinct from real sign-in.
- The current application language (English) and the existing Egyptian registration context.

No new business capability is approved by this IA. A design change must not silently broaden an Admin or Patient permission, add clinical powers to a role, or change appointment/visit lifecycle rules.

## 2. Current page and navigation architecture

### Public website

| Route | Current purpose | Primary user task |
|---|---|---|
| `index.html` | Public landing page | Understand the product and choose a next step |
| `doctors.html` | Search/sort/filter doctor directory | Find a suitable doctor and assess availability |
| `services.html` | Service/department catalogue and FAQ | Understand available care categories |
| `about.html` | Organization/story content | Understand the demo identity and purpose |
| `contact.html` | Inquiry form and contact information | Send a non-urgent inquiry |

Public navigation currently repeats Home, Doctors, Services, About and Contact, with Login and Sign Up. The doctors page supports a URL search query, department chips, sort order, availability and doctor-detail interaction. The services page supports category filters and FAQ expansion. The contact page submits an inquiry to HealthBridge's own contact system; its copy warns against using it for urgent or sensitive medical information.

### Authentication

| Route | Purpose | Key states |
|---|---|---|
| `pages/auth/login.html` | Sign in | Initial, validation, submitting, invalid credentials, verification required, success, service unavailable |
| `pages/auth/register.html` | Two-step patient registration | Account details, profile/medical details, field errors, submitting, email-verification pending, resend result |
| `pages/auth/verify-email.html` | Verify registration email | Invalid/expired, verification result, next action |
| `pages/auth/reset-password.html` | Redeem reset link and set password | Invalid/expired token, form validation, submitting, success |
| Login's recovery panel | Request password reset | Form, generic result, service unavailable |
| Login's resend panel | Resend verification email | Email entry, submitting, result |

The login screen includes fixed Admin/Patient/Doctor demo shortcuts. These are a portfolio/demo affordance, not a normal customer task, and should remain visually secondary and explicitly labelled as demo access.

Registration Step 1 captures account/contact credentials. Step 2 captures demographic details, National ID, governorate/city/address, emergency contact and optional/available medical details. The wizard uses two API submissions and then an email-verification state. The redesign should group information, explain required versus optional fields and privacy expectations, and avoid making the second step feel like an unrelated form.

### Patient portal

Current patient pages:

| Route | Purpose | Primary job |
|---|---|---|
| `pages/patient/dashboard.html` | Overview/home | See next appointment, current health summary and shortcuts |
| `pages/patient/appointments.html` | Appointment management | Book, review, filter, cancel, reschedule and rate visits |
| `pages/patient/history.html` | Medical history | Review health-history information |
| `pages/patient/prescriptions.html` | Prescriptions | Find, inspect and print prescriptions |
| `pages/patient/notifications.html` | Notification centre | Read and manage notifications |
| `pages/patient/downloads.html` | Document centre | Find available downloadable documents |
| `pages/patient/insights.html` | Health insights | Review available insight summaries |
| `pages/patient/profile.html` | Profile | Review personal profile details |
| `pages/patient/settings.html` | Account preferences | Update account, password, appearance and notification preferences |

The patient portal uses a fetched shared navbar/sidebar fragment. Its sidebar currently links to the main patient destinations and places Settings, View Site and Logout in the footer. The shared top bar also exposes Settings, Notifications, Dashboard and Logout. This creates two navigation layers; the redesign should clarify their distinct purposes instead of repeating every destination in both places.

Important distinction: `pages/patient/patient-emr.html` is **not** a patient-facing portal route. It is the Admin's patient-detail/EMR workspace and must be presented under Admin > Patients > Patient EMR, while retaining its existing route and Admin role guard.

### Doctor workspace

| Route | Purpose | Primary job |
|---|---|---|
| `pages/doctor/doctor-dashboard.html` | Doctor home and operational workspace | Review today's work, patient queue, appointments, schedule, notifications, feedback and profile/settings |
| `pages/doctor/doctor-workspace.html?patient_id=…&appointment_id=…` | Patient/appointment-specific clinical workspace | Review patient context, document the encounter, manage prescription and complete the visit |

The Doctor Dashboard contains eight main sections: Overview, Patient Queue, Appointments, Schedule, Notifications, Reviews, Profile and Settings. An appointment/queue action opens the separate Doctor Workspace with both patient and appointment identifiers in the URL. The Workspace has Overview, Medical History, Visit, Prescriptions and Timeline tabs. Lab Results and Files tabs are currently disabled placeholders.

The redesign must make the transition between the doctor's daily overview and the patient-specific clinical workspace explicit. The Workspace needs a persistent patient/appointment identity and a clear return path to the originating queue or appointment context. Do not merge encounter editing into a generic Doctor Dashboard panel.

### Admin operations

| Route | Purpose | Primary job |
|---|---|---|
| `pages/admin/admin.html` | Main Admin panel | Monitor operations and manage appointments, doctors, patients, prescriptions, departments, messages, settings and audit |
| `pages/admin/admin-doctor-profile.html?id=…` | Doctor detail workspace | Review/edit doctor profile, department, schedule, appointments, reviews and audit |
| `pages/patient/patient-emr.html?patient_id=…` | Admin patient detail workspace | Review patient record, demographics, appointments, medical records, prescriptions, visit notes, ratings, notifications, timeline and audit |

The Admin panel is one large page with anchored section navigation. Existing areas are Overview, Appointments, Doctors, Patients, Prescriptions, Departments, Support Messages, System Settings and Audit Log, with Settings in the footer. Doctor and Patient detail screens are separate routes. Admin access to the Patient EMR is administrative/review-oriented; clinical record and prescription lifecycle ownership remains with the Doctor where the existing contract specifies it.

## 3. Proposed information architecture

This hierarchy is a design recommendation, not a change to current route names.

### Public header

**Primary:** Home · Find a Doctor · Services · About · Contact  
**Account actions:** Log in · Create account

Keep one clear primary action at a time. Home should guide visitors toward Find a Doctor or account entry. Directory filters and search should remain visible and understandable when the result set is empty. Services content should direct visitors to a relevant existing doctor/search or sign-in path without inventing extra booking functions.

### Authentication shell

Use one calm, focused auth layout with:
- Clear page title and one sentence explaining the current task.
- Compact progress for multi-step registration.
- Consistent form groups, field-level errors and inline helper text.
- A predictable secondary path: Back to sign in, resend verification, or return to the requested task where supported.
- A distinct success/pending state with one next action.
- Demo shortcuts separated from normal sign-in.

Avoid showing login, resend, recovery and registration forms as competing primary actions on one crowded surface.

### Patient portal

**Main navigation, recommended order:**
1. Overview
2. Appointments
3. Medical History
4. Prescriptions
5. Documents
6. Notifications
7. Health Insights

**Account/support group:** Profile · Settings · View Site · Logout

Keep Appointments close to the Overview because booking and next-visit management are core patient jobs. The Overview should prioritize the next appointment and clear next action, then show health summary and recent activity. Medical History and Prescriptions should be easy to distinguish: one is the longitudinal record, the other is a set of issued medication instructions. Documents is the place for downloadable artifacts, not a second medical-history screen.

Use the top bar for global identity, notification access and a compact account menu. Use the sidebar for durable page navigation. Avoid duplicating all sidebar items in the top bar.

### Doctor experience

**Doctor Dashboard main navigation:**
1. Overview
2. Patient Queue
3. Appointments
4. Schedule
5. Notifications
6. Reviews
7. Profile
8. Settings

The Overview should answer: what needs attention now, what is next, and what changed? The Patient Queue and Appointments must be distinguishable:
- Queue: patients ready/eligible for consultation or active visit work.
- Appointments: scheduled visit list and scheduling decisions.

The doctor-specific Workspace should use a separate local sub-navigation:
- Overview — visit/patient context.
- Medical History — review and update permitted history areas.
- Visit — current encounter notes and workflow.
- Prescriptions — create, review and update prescriptions allowed by the current workflow.
- Timeline — chronological context.

Keep patient identity, appointment date/time and current visit state persistently visible. Show an obvious route back to Appointments or Queue. Lab Results and Files are currently inactive placeholders; do not present them as live capabilities. Hide them from the active navigation or clearly disclose that they are unavailable, without adding functionality.

### Admin experience

Recommended grouped navigation:

- **Overview:** Analytics and operational summary.
- **Operations:** Appointments, Doctors, Patients.
- **Clinical review:** Prescriptions.
- **Configuration:** Departments, System Settings.
- **Communication:** Support Messages.
- **Governance:** Audit Log.
- **Account:** Settings, View Site, Logout.

The grouping is meant to reduce navigation scanning, not to create new sections. A grouped sidebar may retain current anchor targets. Detail workspaces need consistent context:
- Admin > Doctors > Doctor Profile (tabs: Overview, Professional, Schedule, Appointments, Reviews, Audit).
- Admin > Patients > Patient EMR (tabs: Overview, Appointments, Medical Records, Prescriptions, Visit Notes, Ratings, Notifications, Timeline and Audit where currently implemented).

Avoid treating the Admin patient EMR as a second patient portal. Use explicit Admin context and preserve review-only versus mutation permissions.

## 4. Priority user journeys

### Journey A — Public visitor finds a doctor

**Entry:** Home or Doctors.

1. Visitor sees a clear Find a Doctor action.
2. Directory loads active departments and doctor records.
3. Visitor searches by name/specialty, filters by department and sorts results.
4. Each result communicates the available profile details, rating (when meaningful), experience, department and appointment availability without promising an unverified slot.
5. Visitor opens the existing doctor-detail interaction and chooses the current booking/sign-in path.
6. If authentication is required, the user is taken to Login; after successful login the role-specific route is used.

**Required alternate states:** directory loading, no results, data unavailable/fallback, missing rating, unavailable doctor, login required, session expired.

### Journey B — Patient registers and verifies

1. Visitor chooses Create account.
2. Step 1 collects account identity/contact and password.
3. Inline validation identifies each error next to its field; the user can correct without losing unrelated input.
4. Step 2 collects demographic/profile and emergency-contact information, with medical detail fields clearly grouped and required/optional status obvious.
5. Submission moves to an explicit check-email/verification-pending state.
6. User opens the email link and reaches the verification result.
7. User returns to sign in; a separate resend path is available when needed.

**Required alternate states:** email/phone/National ID validation, duplicate or rejected submission, network/server failure, pending verification, expired/invalid verification link and resend result.

### Journey C — Patient signs in and understands the next step

1. Patient enters credentials on Login.
2. Form shows validation, submitting and service-error states.
3. Successful login routes to the Patient Dashboard.
4. Dashboard first communicates welcome/context and the next appointment or a clear no-upcoming-appointment state.
5. Primary actions route to Appointments or relevant existing patient destination.
6. User can access notifications and profile/settings from the global account layer.

**Required alternate states:** invalid credentials, verification required, reset password, session expiry and role mismatch.

### Journey D — Patient books an appointment

**Entry:** Patient Dashboard booking action or Appointments > Book Appointment.

1. Open the booking wizard; identify whether it is a new booking or rescheduling.
2. Step 1: select Department.
3. Step 2: select Doctor from that department.
4. Step 3: select Date and an available Time slot (slots are loaded for the selected doctor/date).
5. Step 4: review Department, Doctor, Date, Time and Duration; confirm patient name and optional notes.
6. Submit once; show loading/disabled submit to prevent duplicate activation.
7. On success show a reference number and a Done action; refresh the appointment list/next appointment summary.
8. On failure keep the selected context and clearly explain the next safe action.

The current wizard saves an in-progress draft, prevents leaving while dirty, and clears it after success. Keep those protections but make them understandable and not alarming.

**Required alternate states:** no departments/doctors, doctor unavailable, no slots for selected date, slot changed/expired, validation, request timeout, duplicate submission, server rejection and successful booking.

### Journey E — Patient manages an existing appointment

1. Patient opens Appointments.
2. Search/filter by status (All, Upcoming, Confirmed, Reschedule, Completed, Cancelled, Missed).
3. A featured next appointment summarizes the most time-sensitive visit.
4. View opens detail.
5. Confirmed visits allow reschedule or cancel when current rules allow.
6. A reschedule request opens the same wizard with the existing department/doctor retained and locked while date/time are changed; the reason is optional as currently implemented.
7. If the doctor suggests a new time, patient reviews the suggestion and accepts or declines.
8. Completed visits expose Rate Visit when eligible; an existing rating can be viewed.
9. Pending/processing outcomes and updates must be clearly distinguished from final confirmation.

**Required alternate states:** no appointments, no filter results, cancel confirmation, pending reschedule request, suggested reschedule, rejected/cancelled/missed/completed status, rating already submitted and server error.

### Journey F — Doctor handles today's work

1. Doctor signs in and reaches the Doctor Dashboard.
2. Overview presents today's schedule, queue, pending decisions and meaningful notification count.
3. Doctor opens Patient Queue or Appointments depending on the task.
4. Appointment actions depend on current status: accept/decline pending request, open EMR for a qualifying confirmed visit, or propose a reschedule.
5. Reschedule suggestions are clearly pending the other party's response; approval/rejection actions are labelled with their consequence.
6. Doctor opens the Patient Workspace with patient and appointment context preserved.

**Required alternate states:** empty queue, no appointments today, pending decision, unavailable patient/appointment context, session expiry and data-load failure.

### Journey G — Doctor completes a consultation

1. Doctor enters the Workspace from a valid queue/appointment action.
2. Workspace loads the correct patient and appointment; missing/invalid IDs show a safe return path.
3. Doctor reviews Overview and Medical History.
4. Doctor starts/continues the visit when the existing workflow allows.
5. Visit tab supports note creation and draft autosave; unsaved-change protection warns before leaving where necessary.
6. Doctor creates/updates a prescription within the current encounter workflow.
7. Doctor reviews encounter/prescription context and completes the visit.
8. Completion state is explicit; the patient/appointment context remains available for read-only review and return to the dashboard.

**Required alternate states:** no patient selected, appointment not eligible, draft saving, save failure/retry, validation, already-completed encounter, incomplete prescription, confirm-completion dialog and success state.

### Journey H — Admin manages daily operations

1. Admin signs in and reaches Overview.
2. Overview answers core operational questions and offers quick access to existing actions.
3. Admin opens Appointments, Doctors or Patients based on the task.
4. Appointments supports search/filter and the current approve/decline actions for eligible pending bookings.
5. Doctors and Patients support directory search/filter and record-level actions.
6. Selecting a doctor opens the Doctor Profile detail workspace; selecting a patient opens the Admin Patient EMR.
7. Departments, Support Messages, System Settings and Audit Log are distinct configuration/communication/governance destinations.
8. Success, disabled state, validation or confirmation must be shown without losing the selected record context.

**Required alternate states:** loading/error/empty tables, filters with no match, pending-only actions unavailable after status change, failed save, conflict/reload, confirmation before destructive action, and session expiry.

### Journey I — Admin reviews a patient's record

1. Admin opens Patients.
2. Admin chooses the EMR action for the intended patient.
3. Admin Patient EMR opens with the target patient identity and Admin context visible.
4. Admin reviews overview, appointments, medical records, prescriptions, visit notes, ratings, notifications, timeline and audit history where present.
5. Administrative profile/account actions remain visually separate from clinical review.
6. Any allowed administrative correction requires a reason and clear confirmation; Admin does not acquire Doctor-owned clinical lifecycle actions.

**Required alternate states:** invalid patient ID, patient not found, partial section load, no data in a section, unavailable permission and confirmation result.

## 5. Cross-product page anatomy

Use a consistent anatomy without making every page look identical:

1. **Global shell:** product identity, role-aware global actions and persistent navigation.
2. **Context line:** breadcrumb or clear section title; on detail pages include the selected patient/doctor/appointment context.
3. **Page header:** one clear title, short purpose statement and at most one dominant primary action.
4. **Main content:** grouped cards, tables, forms or timeline according to the task.
5. **Secondary controls:** filters/search/export placed beside the content they affect.
6. **Feedback region:** success, validation, warning or error at the point the user needs it.
7. **Responsive action placement:** primary actions remain reachable when a page scrolls; avoid overlaying content.

### Shared interaction patterns

- Search/filter: show applied criteria, result count, clear-filter action and no-result state.
- Table/list: clear column hierarchy, row actions with accessible names, mobile alternative, pagination and loading/empty/error states.
- Detail page: stable identity header, clear back path, local tabs and meaningful section-level empty states.
- Modal/dialog: descriptive title, close action, correct focus entry/restore, Escape behavior where safe, and explicit confirmation for destructive actions.
- Form: visible labels, helper text, inline error, submitting/disabled state, success result and preservation of input on recoverable failure.
- Async content: skeleton only for the region loading; do not hide the entire page when one independent widget fails.
- Status: a text label plus color/shape; never communicate status with color alone.
- Navigation: links for route changes; buttons/tabs for same-page state changes. Active route and selected tab need correct semantics.

## 6. Responsive information architecture

### Small mobile (390 px baseline)

- Public navigation becomes a single accessible menu with Login/Create Account still easy to reach.
- Patient/Doctor/Admin side navigation becomes a compact drawer or role-aware mobile navigation. Do not leave a narrow permanently visible desktop sidebar.
- Page header stacks; primary action remains near the title and is not pushed below a long toolbar.
- Search/filter controls stack or become a compact filter surface with an obvious applied-filter state.
- Appointment/doctor/patient lists use readable cards or explicitly labelled horizontal scrollers. Any scroller must be keyboard accessible.
- Multi-column forms become one column; field grouping and progress remain obvious.
- Modals use nearly full-screen mobile sheets where needed, with a visible close/back control and a safe scrollable body.
- The patient/appointment context stays visible in Doctor Workspace, including when the local tab content scrolls.

### Tablet (768 px baseline)

- Allow collapsible role navigation and two-column content only where cards remain readable.
- Avoid three-column KPI or form arrangements that create narrow controls.
- Keep list filters and table actions usable without clipping.

### Desktop (1440 px baseline)

- Use a stable content width and consistent page grid.
- Role navigation may remain expanded; detail workspaces retain context and local tabs.
- Avoid overly wide text lines and full-width forms; let tables use the available space.

## 7. Content and trust review items

These are content/UX review items, not verified backend defects:
- The public/about pages contain hard-coded testimonials, patient/booking/clinic metrics and claims such as “verified” or “24/7”. Before portfolio presentation, confirm each item is clearly fictional/demo copy or backed by the synthetic data actually shown; remove any statement that could be mistaken for a real operational guarantee.
- Public contact/helpline copy includes +1-style phone examples while registration validation is for Egyptian mobile numbers and the site identifies Faiyum, Egypt. Reconcile the fictional contact details and emergency wording before public portfolio release; never imply the demo contact route is an emergency service.
- Consistently label the system as a demonstration using synthetic data. Avoid wording that suggests live clinical care, real triage, real insurance or real clinic coverage.
- Use consistent terms across screens: “Appointments” (not a mixture of visits/bookings unless a distinction exists), “Patient Queue” (doctor operational queue), “Visit” (clinical encounter), “Medical History” (longitudinal record), and “Prescription” (medication order/document).

## 8. Prioritized UX architecture backlog

| Priority | ID | Work item | Reason |
|---|---|---|---|
| P0 | UXIA-01 | Unify global shell patterns while retaining role-specific navigation | Current patient fragment and inline Doctor/Admin shells drift |
| P0 | UXIA-02 | Clarify Patient Dashboard → Appointments → booking/reschedule flows | Core self-service journey with four-step wizard and multiple outcomes |
| P0 | UXIA-03 | Make Doctor Dashboard → Patient Workspace transition context-safe | Patient/appointment IDs and encounter ownership must remain clear |
| P0 | UXIA-04 | Make Admin navigation grouped and detail-page context explicit | One large anchored panel plus separate Doctor and Patient detail routes |
| P0 | UXIA-05 | Define all loading/empty/error/permission/confirmation states | Prevents blank screens and unclear outcomes |
| P0 | UXIA-06 | Fix known role-dashboard accessibility issues as shared patterns are implemented | Contrast, accessible-name, labels and scroll keyboard access defects found in M3.0 |
| P1 | UXIA-07 | Clarify Patient topbar vs sidebar responsibility | Avoid duplicated destinations and menu scanning |
| P1 | UXIA-08 | Improve registration grouping and privacy/required-field explanation | Long Step 2 form mixes demographic, emergency and medical information |
| P1 | UXIA-09 | Clarify notification surfaces across topbar and dedicated role pages | Keep global alert access distinct from full notification history |
| P1 | UXIA-10 | Review inactive Doctor Workspace tabs | Lab Results and Files are disabled placeholders today |
| P1 | UXIA-11 | Reconcile public claims/contact copy with synthetic demo boundaries | Avoid unsupported operational claims or misleading emergency wording |
| P2 | UXIA-12 | Reconcile repeated shared assets/controllers and caller ownership | Reduces visual drift and improves later refactoring safety |

## 9. Design-system and prototype specifications

The following representative screen specifications are recommended for M3.2–M3.3:

### Prototype A — Patient Dashboard (first)

- Top shell: HealthBridge brand, notifications, account menu and compact mobile navigation.
- Page context: Overview and patient greeting.
- Main priority: next appointment or no-upcoming-appointment action.
- Secondary modules: appointment summary, health snapshot/alerts, care team, latest prescription, recent activity/notifications and documents/insights previews where currently available.
- Clear primary action: Book Appointment.
- Explicit partial-widget error states; a failed secondary card must not erase the complete dashboard.
- Mobile: single-column priority order; appointment CTA and status visible without hunting.

### Prototype B — Doctor Dashboard

- Persistent doctor identity and availability state.
- Overview priority area: today's queue and next appointments.
- Distinct queue and scheduled appointments sections.
- Clear status and decision controls for pending/confirmed/reschedule states.
- One-click, context-preserving Open Workspace/Consult path.
- Mobile: queue cards with readable patient/time/department/status and accessible action targets.

### Prototype C — Admin Overview and navigation

- Grouped sidebar and clear current-section indicator.
- KPI/analytics hierarchy with fewer competing accents.
- Quick actions visually secondary to key operational summary.
- Appointment/doctor/patient directory routes accessible from clear operational navigation.
- Chart exports remain discoverable but do not dominate the screen.

### Prototype D — Public Doctors Directory

- Search, department filters and sort are a single coherent toolbar.
- Cards prioritize name, department/specialty, rating context, experience and meaningful availability.
- Loading/no-results/unavailable states are designed rather than blank.
- Booking action leads to the actual existing authentication/booking path.

### Prototype E — Authentication and Booking

- Auth: compact, focused sign-in with secondary recovery/resend and visibly separate demo shortcuts.
- Booking wizard: preserve the 4-step Department → Doctor → Date & Time → Confirmation flow, visible progress, summary, draft/leave protection and success reference. The wizard is a task-focused modal on the Appointments page, not a replacement for the Appointments page itself.

## 10. M3.1 outcome and next phase

M3.1 source inspection has mapped the public/auth/patient/doctor/admin route structures, role navigation, four-step booking journey, patient appointment lifecycle, doctor dashboard-to-workspace transition, Doctor encounter path, Admin operations and dedicated patient/doctor detail workspaces. The proposed role hierarchy, page anatomy, responsive rules, state requirements, content-review items and representative prototype briefs are recorded here.

**M3.1 architecture draft is ready for visual design.** Owner review is still needed before treating the proposed navigation grouping and labels as final. No route, feature, API contract, permission, database, source or runtime behavior was changed.

Next: M3.2 — finalize visual design tokens and component-state rules against this UX architecture, then M3.3 — build representative screen prototypes for review before broad implementation.
