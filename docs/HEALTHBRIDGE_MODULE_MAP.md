# HealthBridge - Module and Dependency Map

**Audit date:** 5 November 2026  
**Project root:** C:\xampp85\htdocs\optimized  
**Working branch at audit start:** repair/healthbridge-staged-hardening  
**HEAD at audit start:** d4dd731b7578d9309d8eca50ec57bb319459dade  
**Status:** Initial static map; Phase 1 review remains in progress.

This document gives a practical map of how the major application areas connect. It is based on the current file tree, page script tags, exact frontend API literals, endpoint service references, service SQL references, and the checked-in master schema. It is not a complete runtime dependency graph. Validate each path while testing or modifying the relevant feature.

---

## 1. Application shape

HealthBridge is a same-origin PHP application with server-side JSON endpoints, a vanilla JavaScript frontend, shared PHP bootstrap/helpers, service classes, and MySQL/MariaDB persistence.

High-level request path:

1. A public or role-specific HTML page loads shared and page-specific JavaScript.
2. JavaScript calls one or more PHP API routes (often through the apiFetch helper in assets/js/core/main.js).
3. API routes load includes/auth.php, which loads database, response, validation, helpers, and permissions code and starts the session.
4. The route applies authentication, role, ownership, input, and workflow-state checks.
5. Business logic may be delegated to one or more service classes; some older/contained routes query PDO directly.
6. The route/service reads or mutates the canonical schema tables and may create audit or notification side effects.

Important distinction: includes/auth.php is the shared bootstrap. Its inclusion starts and validates session infrastructure, but the route must still call requireAuth()/requireRole() or apply an explicitly reviewed public/manual policy.

---

## 2. HTML page and JavaScript entry-point map

The current static tree contains five root-level public HTML pages and seventeen role-specific pages under pages/.

### Public pages

| HTML entry | Primary JavaScript | Main observed API/domain links |
|---|---|---|
| index.html | assets/js/core/main.js, assets/js/pages/home.js | GET api/departments/get.php (public active departments); GET api/settings/get-hospital.php (public hospital-hours subset); shared authenticated logout/settings controls are conditional |
| doctors.html | assets/js/core/main.js, assets/js/pages/doctors.js | GET api/departments/get.php, api/doctors/get.php, api/doctors/get-public-availability.php; doctor detail modal is local rendering; booking redirects to login or patient dashboard without a directory-page write |
| contact.html | assets/js/core/main.js, assets/js/pages/contact.js | POST once to same-origin api/settings/contact.php; the form does not forward submitted fields to a third-party form service (HB-SEC-008 locally resolved) |
| about.html | assets/js/core/main.js | No page-specific data controller/API; shared authentication, logout, and settings helpers remain conditional |
| services.html | assets/js/core/main.js, assets/js/pages/services.js | Service filters and FAQs are client-side; getSessionUser indirectly calls GET api/auth/current_user.php to personalize booking links, with expected anonymous 401 |

### Authentication pages

| HTML entry | JavaScript | Main API routes |
|---|---|---|
| pages/auth/login.html | core/main.js, core/auth.js | POST api/auth/login.php; embedded Forgot Password panel POSTs api/auth/forgot_password.php; local-development reset URL only, deployed/non-loopback request returns 503 until mail transport exists |
| pages/auth/register.html | core/main.js, core/auth.js | GET api/auth/locations.php for governorates/cities; POST api/auth/register.php Step 1 validation and Step 2 atomic registration; no current check-availability caller |
| pages/auth/reset-password.html | core/main.js, pages/reset-password.js | POST api/auth/reset_password.php; verify_only is read-only, final submit updates users and consumes password_resets tokens |

### Patient pages

| HTML entry | JavaScript | Main API routes/domains |
|---|---|---|
| pages/patient/dashboard.html | core/main.js, components/layout.js, pages/patient/dashboard.js | patient/dashboard.php (aggregated dashboard) |
| pages/patient/appointments.html | core/main.js, components/layout.js, modules/departments-loader.js, components/booking-wizard.js, pages/patient/appointments.js | patient/appointments.php (list/filter/search); booking, available slots, reschedule workflow, ratings, cancellation |
| pages/patient/patient-emr.html | core/main.js, modules/departments-loader.js, pages/patient-emr.js, modules/medical-records.js, modules/prescriptions.js, modules/notifications.js | Admin-only UI (server role guard); EMR aggregate, audit read, medical records, prescriptions, notifications, and read-only appointment data; controller also exposes separately guarded mutation actions |
| pages/patient/history.html | core/main.js, components/layout.js, pages/patient/history.js | patient/dashboard.php aggregation |
| pages/patient/insights.html | core/main.js, components/layout.js, pages/patient/insights.js | patient/dashboard.php aggregation; Chart.js external library |
| pages/patient/downloads.html | core/main.js, components/layout.js, pages/patient/downloads.js | patient/dashboard.php aggregation; print/download links |
| pages/patient/notifications.html | core/main.js, components/layout.js, pages/patient/notifications.js | notifications/notifications.php |
| pages/patient/prescriptions.html | core/main.js, components/layout.js, pages/patient/prescriptions.js | prescriptions/get.php, prescriptions/print.php |
| pages/patient/profile.html | core/main.js, components/layout.js, pages/patient/profile.js | patient/dashboard.php aggregation |
| pages/patient/settings.html | core/main.js, components/layout.js, pages/settings.js | settings/get.php, settings/update.php |

### Doctor pages

| HTML entry | JavaScript | Main API routes |
|---|---|---|
| pages/doctor/doctor-dashboard.html | core/main.js, modules/schedule.js, modules/notifications.js, pages/settings.js, pages/doctor-dashboard.js | appointments/get-doctor.php, appointments/update.php, reschedule actions, doctor availability and ratings, schedule, settings, notifications |
| pages/doctor/doctor-workspace.html | core/main.js, modules/prescriptions.js, pages/doctor-workspace.js, modules/notifications.js | doctors/workspace-data.php; visit workflow, notes/drafts, EMR updates, prescriptions, history updates |

### Admin pages

| HTML entry | JavaScript | Main API routes |
|---|---|---|
| pages/admin/admin.html | core/main.js, modules/departments-loader.js, pages/admin.js, modules/admin-departments.js, modules/admin-departments-loader.js, modules/admin-hospital-settings.js, modules/medical-records.js, modules/prescriptions.js, pages/settings.js, modules/schedule.js, modules/notifications.js, pages/admin-dashboard.js | Admin analytics, doctors/patients/departments management, audit, hospital settings, schedules, contact messages, records, prescriptions, notifications |
| pages/admin/admin-doctor-profile.html | core/main.js, pages/admin-doctor-profile.js, modules/schedule.js | Doctor details, audit filtered by entity, department assignment, schedule, status/archive, doctor appointments |

The full current HTML script-source inventory is reflected in the tables above. Verify scripts injected by includes/fragments/navbar.html and includes/fragments/sidebar.html when doing UI entry-point cleanup.

---

## 3. Shared frontend infrastructure

| File/module | Responsibility | Notes |
|---|---|---|
| assets/js/core/main.js | Shared application startup, current-user/session handling, navigation, API helper | apiFetch is the common request wrapper; review error shape and usage consistency in Phase 4 |
| assets/js/core/auth.js | Login, registration, password recovery, location/availability validation | Shared auth workflows |
| assets/js/components/layout.js | Loads shared navbar/sidebar fragments and handles role-specific layout behavior | Dynamic HTML injection means fragment scripts and path behavior should be checked before declaring assets unused |
| assets/js/components/booking-wizard.js | Shared booking and reschedule UI | Used from multiple entry points, including patient booking and admin profile context |
| assets/js/modules/notifications.js | Unified notification UI actions | Current frontend literals point to api/notifications/notifications.php rather than the older get-patient route |
| assets/js/modules/prescriptions.js | Shared prescription UI/actions/printing | Used by doctor and patient interfaces, also loaded on admin and EMR page contexts |
| assets/js/modules/schedule.js | Schedule/hospital hours UI | Shared by admin and doctor pages |
| assets/js/modules/medical-records.js | Shared medical-record presentation/actions | Loaded in doctor workspace and patient EMR/admin contexts |
| assets/js/modules/admin-departments.js and departments-loader.js | Admin CRUD and public/booking department list loading | Keep write operations separate from public active-department reads |

---

## 4. Backend module and service ownership

The thirteen service classes observed in services/ have the following primary responsibilities and direct SQL table references. Table links are extracted from SQL-shaped statements; dynamic SQL and inherited calls may not be fully represented.

| Service | Responsibility | Direct table references observed |
|---|---|---|
| PatientPortalService | Patient dashboard aggregation and related patient portal view data | appointments, users, doctors, departments, medical_records, visit_notes, visit_workflow, prescriptions, prescription_items, ratings, notifications, admin_audit, hospital_settings |
| ScheduleService | Doctor schedule settings/weekly hours, slot creation, booking range checks, hospital boundaries | appointments, doctor_schedule_settings, doctor_schedule_weekly, doctors, hospital_settings, users |
| RescheduleService | Patient/doctor reschedule lifecycle and reservation logic | appointments, users (may delegate schedule/notification concerns to other services) |
| PrescriptionService | Prescription header/item retrieval and lifecycle operations | appointments, prescriptions, prescription_items, users |
| VisitWorkflowService | Encounter workflow state transitions and lookup | appointments, visit_workflow |
| VisitDraftService | Doctor visit draft save/read/delete | visit_drafts (appointment/workflow context is also checked by routes) |
| NotificationService | Notification create/read/unread/preferences | notifications, user_preferences |
| AuditService | General audit event creation and retrieval | admin_audit, users; some audit categories are routed through other helpers/tables |
| AdminAuditService | Admin-specific audit facade/filtering | Extends/delegates to AuditService; inspect inheritance before duplicating audit queries |
| ClinicalAccessService | Shared doctor-to-patient clinical relationship policy for EMR reads and writes | appointments, users, visit_workflow, visit_notes, prescriptions |
| DepartmentService | Department list and management, doctor assignment, dependency checks | departments, doctors, appointments |
| HospitalSettingsService | Hospital settings and doctor schedule conflict checks | hospital_settings, doctor_schedule_weekly, users |
| DashboardAnalyticsService | Aggregated admin metrics and recent activity | appointments, users, doctors, departments, ratings, medical_records, notifications, visit_workflow, hospital_settings, admin_audit |

### Service dependency observations

- Appointment booking and rescheduling coordinate ScheduleService and RescheduleService and are core shared domain logic. Avoid implementing new slot rules in individual endpoints.
- Appointment/clinical flows also use VisitWorkflowService, PrescriptionService, NotificationService, AuditService, and VisitDraftService.
- PatientPortalService is a broad read-model/aggregation service. Its size and broad table reach make it a candidate for future decomposition, but do not split it until the route/query responsibilities and tests are mapped.
- ScheduleService is also large and should be treated as a core domain service, not split based only on line count.
- AdminAuditService inherits/delegates shared audit behavior; do not reintroduce duplicate query methods.

---

## 5. Domain workflow maps

### Authentication and account lifecycle

| Layer | Main components |
|---|---|
| UI | pages/auth/login.html, register.html, reset-password.html; settings pages |
| JS | assets/js/core/auth.js, assets/js/pages/reset-password.js, assets/js/pages/settings.js, assets/js/core/main.js |
| API | api/auth/login.php, register.php, current_user.php, logout.php, forgot_password.php, reset_password.php; api/settings/update.php for password and self-deactivation |
| Shared backend | includes/auth.php, permissions.php, validation.php, AuthAbuseRateLimiter.php, AuditService, NotificationService |
| Tables | users, password_resets, user_preferences, notifications, admin_audit |

### Public discovery and booking

| Layer | Main components |
|---|---|
| UI | index.html, doctors.html, patient appointments, booking wizard, admin doctor-profile booking context |
| JS | pages/home.js, pages/doctors.js, components/booking-wizard.js, pages/patient/appointments.js |
| API | api/departments/get.php, doctors/get.php, doctors/get-public-availability.php, appointments/get-available-slots.php, appointments/book.php, api/patient/appointments.php |
| Services | DepartmentService, ScheduleService, RescheduleService, AuditService, NotificationService |
| Tables | departments, doctors, users, appointments, doctor_schedule_settings, doctor_schedule_weekly, hospital_settings, notifications, audit tables |

### Appointment and reschedule lifecycle

| Layer | Main components |
|---|---|
| Patient UI | pages/patient/appointments.html and booking wizard |
| Doctor UI | pages/doctor/doctor-dashboard.html |
| Admin UI | pages/admin/admin.html and patient EMR/admin appointment actions |
| JS | pages/patient/appointments.js, components/booking-wizard.js, pages/doctor-dashboard.js, pages/admin.js |
| API | appointments/book.php, get-available-slots.php, get-doctor.php, update.php, request-reschedule.php, approve-reschedule.php, reject-reschedule.php, suggest-reschedule.php, accept-suggestion.php, decline-suggestion.php, cancel-reschedule-request.php, approve.php, decline.php; patient/cancel-appointment.php |
| Services | ScheduleService, RescheduleService, VisitWorkflowService, AuditService, NotificationService |
| Tables | appointments, doctors, doctor_schedule_settings, doctor_schedule_weekly, hospital_settings, visit_workflow, users, notifications, audit tables |

### Doctor workspace and clinical encounters

| Layer | Main components |
|---|---|
| UI | pages/doctor/doctor-workspace.html and doctor dashboard |
| JS | core/main.js, modules/prescriptions.js, pages/doctor-workspace.js, modules/notifications.js (these are the scripts directly loaded by the page) |
| API | doctors/workspace-data.php; medical/add-visit-note.php, update-record.php, add-history-update.php, get-visit-draft.php, save-visit-draft.php, delete-visit-draft.php, transition-workflow.php, complete-visit.php; prescriptions/get.php, create.php |
| Services | ClinicalAccessService, VisitWorkflowService, VisitDraftService, PrescriptionService, AuditService, NotificationService |
| Tables | appointments, visit_workflow, visit_drafts, medical_records, medical_history_updates, visit_notes, prescriptions, prescription_items, users, audit tables, notifications |

### Patient EMR, prescriptions, and print views

| Layer | Main components |
|---|---|
| UI | pages/patient/patient-emr.html, patient prescriptions/download pages, doctor workspace print actions |
| JS | pages/patient-emr.js, modules/medical-records.js, modules/prescriptions.js, pages/patient/prescriptions.js |
| API | medical/get-emr-data.php, get-record.php, print-patient-emr.php; prescriptions/get.php, create.php, update.php, cancel.php, complete.php, print.php; appointments/print.php; patients/get-audit.php |
| Services | ClinicalAccessService, PrescriptionService, VisitWorkflowService, AuditService, NotificationService |
| Tables | users, appointments, medical_records, medical_history_updates, visit_notes, prescriptions, prescription_items, ratings, patient_audit_log, medical_record_audit, notifications |

### Administration and operational support

| Layer | Main components |
|---|---|
| UI | pages/admin/admin.html, pages/admin/admin-doctor-profile.html |
| JS | pages/admin.js, pages/admin-dashboard.js, pages/admin-doctor-profile.js, modules/admin-departments.js, modules/admin-hospital-settings.js, modules/schedule.js |
| API | admin/dashboard-analytics.php; patients/*; doctors/*; departments/*; audit/get.php; schedule/*; settings/get-contact-messages.php, get-hospital.php, reply-contact-message.php, update-hospital.php |
| Services | DashboardAnalyticsService, DepartmentService, HospitalSettingsService, ScheduleService, AuditService/AdminAuditService, NotificationService |
| Tables | users, doctors, departments, appointments, ratings, contact_messages, hospital_settings, doctor_schedule_settings, doctor_schedule_weekly, admin_audit, notifications |

### Notifications and support messages

| Layer | Main components |
|---|---|
| UI | notification bell/modules, patient notifications, contact page, admin contact inbox |
| JS | modules/notifications.js, pages/patient/notifications.js, pages/contact.js, pages/admin.js |
| API | notifications/notifications.php; settings/contact.php, get-contact-messages.php, get-user-messages.php, reply-contact-message.php |
| Services | NotificationService |
| Tables | notifications, user_preferences, contact_messages, users |

---

## 6. Database domain map

As of 11 November 2026, the checked-in canonical schema contains 26 tables.

| Domain | Tables | Main owners/consumers |
|---|---|---|
| Identity and account lifecycle | users, user_preferences, password_resets | auth endpoints, settings, audit/notifications |
| Hospital reference/configuration | departments, egypt_governorates, egypt_cities, hospital_settings | public discovery, booking, admin settings |
| Doctor profile/scheduling | doctors, doctor_schedule_settings, doctor_schedule_weekly | doctor directory, booking, doctor dashboard, ScheduleService |
| Appointments and encounter state | appointments, visit_workflow, visit_drafts | booking, rescheduling, doctor workspace |
| Medical/clinical records | medical_records, medical_history_updates, visit_notes, prescriptions, prescription_items | EMR, visit workflow, prescriptions |
| Communication/feedback | notifications, notification_outbox, contact_messages, ratings | notification center, outbox worker, support, doctor reviews |
| Audit history | admin_audit, medical_record_audit, patient_audit_log, schedule_audit_log | audit service, EMR and administrative review |

### Important ownership conventions observed

- users.id is the common identity key used for patients, doctors, and admins.
- doctors.user_id links a doctor profile to its users identity.
- appointments.user_id is the patient user ID; appointments.doctor_id is the doctor user ID.
- medical_records.patient_id and visit_notes.patient_id represent patient identity.
- Prescription ownership is represented through patient_id, doctor_id, and appointment_id; the service enforces encounter-specific lifecycle checks.
- doctor_schedule_settings and doctor_schedule_weekly feed schedule availability; legacy availability values may still appear as fallback fields in some read paths.
- departments.id is the authoritative doctor department relationship where department_id is present; specialty remains a compatibility/display fallback.
- Audit history is split across general admin, medical-record, patient, and schedule tables; audit behavior must not be inferred solely from AdminAuditService naming.

---

## 7. Cross-cutting risks and architecture questions to resolve

1. **Manual session checks:** the initial audit found settings/get-contact-messages.php, settings/get-user-messages.php, settings/get-hospital.php, and departments/get.php using raw session values. All four now enforce GET and use requireRole()/requireAuth() (conditional for public read routes) in the local working copy. PHP lint passed; runtime session-revocation/role-change tests remain pending. The only remaining manual session/helper route without a central guard is auth/login.php, where manual session lifecycle is expected.
2. **Clinical doctor-to-patient access (locally repaired):** all seven doctor clinical patient-data routes now use services/ClinicalAccessService.php, including the broad api/doctors/workspace-data.php endpoint. A Confirmed appointment permits pre-visit review; a Cancelled appointment only preserves access when a visit workflow reached In Progress/Ready to Complete/Completed, or a visit note/prescription exists for that encounter. Cancelled appointments with only a Waiting workflow no longer grant access. Write routes check/lock the qualifying appointment inside their transactions. Historical local DB QA passed 6/6 across 453 doctor/patient pairs; the latest isolated fixture matched independent SQL for four linked pairs and passed 23 focused assertions, including cancelled-only denial through session-scoped temporary tables. Runtime HTTP/session regression remains pending (HB-SEC-001).
3. **Large read models:** PatientPortalService and ScheduleService are substantial and central. Split only after defining smaller ownership boundaries and adding regression tests.
4. **Direct queries vs services:** several endpoints query directly while other domains use services. Phase 4 should define when direct route queries are appropriate and when business logic belongs in a service.
5. **Notification delivery (Phase 15 outbox + appointment transaction integration):** NotificationService::create() writes a durable notification_outbox event and attempts immediate delivery outside an open business transaction. Transactional enqueue is now applied to appointment booking, admin approval/decline, doctor appointment-status update, and patient cancellation; these handlers dispatch a batch after commit and leave failed events durable for the CLI worker. Notification insert + outbox completion are atomic; transient failures use bounded retry/backoff; missing recipients or disabled preferences are skipped. The outbox has no recipient FK to avoid account/appointment lock cycles. Other post-commit call sites still have a crash window and need individual lock-order review; periodic worker scheduling/monitoring remains required. HB-REL-001 remains open.
6. **Shared EMR controller:** pages/patient/patient-emr.html loads a controller with several role-aware actions. Confirm its intended role contexts and avoid assuming the filename or page location represents patient-only functionality.
7. **Legacy patient portal controller (removed):** assets/js/pages/patient-portal.js was a 2,006-line legacy monolith. No current page or dynamic loader referenced it, and Git history shows the baseline dashboard already loaded assets/js/pages/patient/dashboard.js. The unused controller was removed locally on 5 November; its original remains in Git history. The similarly named patient-portal.css is still used and was retained.
8. **External frontend dependencies:** Leaflet, Chart.js, Font Awesome, and remote fonts/icons appear in page assets. Review CSP/network behavior and dependency pinning in release readiness.
9. **Archived migration executors / web-root boundary:** six tracked PHP scripts under database/archive/scripts/ had no current application caller. Five lacked central authentication and CLI-only guards; one is admin-only but permits a state-changing request without an explicit method contract. The six PHP executors have since been removed locally; Apache .htaccess rules now deny database/docs/tools/services/vendor and direct PHP requests under includes. Actual deployed-host reachability remains unverified. See docs/HEALTHBRIDGE_REPOSITORY_AUDIT.md and the findings register.
10. Authentication abuse controls (locally added): AuthAbuseRateLimiter.php applies host-local HMAC-based attempt limits to registration and login. Login allows 10 attempts/email and 60/IP per 15 minutes; unknown, wrong-password, and disabled-account failures share a generic 401. The login route also runs password_verify() on a cost-12 dummy bcrypt hash for unknown emails to reduce timing-based enumeration; recheck the cost if password-hashing policy changes. Registration Step 1 is validation-only; Step 2 duplicate and completed-registration outcomes now share a neutral success-shaped response. Phone uniqueness is database-enforced by the generated normalized key and unique index from Phase 14. Residual timing inference, contact ownership proof, authenticated HTTP regression, and shared multi-host limiter storage remain open.
11. **Booking doctor/department integrity (locally repaired):** api/appointments/book.php now resolves canonical doctors.user_id, verifies the active doctor's authoritative doctors.department_id matches the selected active department, allows specialty fallback only for NULL department_id, rejects ambiguous legacy name-only matches, and rechecks the profile + department under row locks inside the booking transaction. All four booking UIs submit doctor_id. Local direct DB QA passed 9/9; browser/HTTP booking regression remains pending. See HB-DB-002.
12. Public registration abuse (partially mitigated): api/auth/check-availability.php returns a neutral available:null response without querying account existence; the current UI no longer sends per-field probes; registration Step 1 validates format/password only. AuthAbuseRateLimiter.php throttles registration Step 1/2 and sign-in using HMAC-only host-local state. Step 2 duplicate and completed-registration responses now share a neutral confirmation; public patient_number/user_id fields were removed. Phase 14 adds indexed generated-column phone uniqueness, closing the application-only phone race without rewriting stored phone values. Contact ownership verification, residual timing review, authenticated registration regression, and shared multi-host storage remain open. See HB-AUTH-001 through HB-AUTH-003.

---

## 8. Phase 1 status

**Status: In Progress.** This first module map is available to guide the remaining route review. It does not yet prove every dependency at runtime.

Remaining Phase 1 work:
- [ ] Validate each route's full authorization and row ownership/state conditions.
- [ ] Confirm every page/controller entry point and any dynamically injected fragment scripts.
- [ ] Trace the exact request/response contracts for each critical flow.
- [ ] Verify static no-caller candidates against any external or dynamic consumer.
- [ ] Review direct SQL and service delegations beyond token-based table scans.
- [ ] Reconcile all migrations and fresh-install scripts against the live local QA schema.
- [ ] Build a prioritized, evidence-backed findings register.
- [ ] Mark Phase 1 complete only after the above review and the docs are cross-checked.

**No application PHP/JS, database schema, configuration, or data was modified to create this map.** No endpoint or asset was deleted. No commit, push, deployment, or hosted database operation was performed.


## 9. Refactor scope Ã¢â‚¬â€ 5 November 2026

The module map is used to improve and verify **existing** behavior. Do not add product capabilities or new user workflows during refactoring. Improvements are limited to correcting defects, clarifying module ownership, consolidating genuine duplication, reducing measured query/runtime cost, tightening authorization/data boundaries, improving existing-screen accessibility/usability, and adding regression evidence.

Large modules must be decomposed only after responsibilities and dependencies are mapped and tests protect the relevant existing journeys. Preserve the current PHP/PDO and vanilla JavaScript architecture unless a narrowly scoped component demonstrates a clear maintenance benefit. See `docs/HEALTHBRIDGE_PORTFOLIO_REFACTOR_SCOPE.md` for dependency evaluation and scope rules.


13. **Centralized patient API role checks:** `assertRole()` now checks a user already returned by `requireAuth()` without authenticating a second time, while `requireRole()` retains its original contract and always authenticates. The patient dashboard and appointments endpoints preserve auth -> method -> role ordering and use the explicit assertion helper. Existing `requireRole()` callers remain unchanged.


14. **Patient appointment timeline query:** `PatientPortalService::getAppointmentTimeline()` now selects workflow/prescription/rating fields directly rather than grouping and applying independent `MAX()` aggregates. This relies on the current schema's unique `appointment_id` constraints for all three related tables. The old and new read-only query outputs were identical for all 6 appointment rows in the isolated QA database. The CLI diagnostic now checks category and linked-record consistency.


## Settings module Ã¢â‚¬â€ self-service and global hospital boundaries (5 November 2026)

- `api/settings/get.php` reads only the authenticated user's profile, preferences and notification count; missing preference defaults are constructed in memory, not persisted by a read.
- `api/settings/update.php` always derives the target user from `requireAuth()`. Profile phone changes now use the shared Egyptian phone validator and normalized duplicate helper; unchanged legacy values remain supported and blank optional phones remain clearable. Doctor/admin preference sections retain explicit role gates.
- `api/settings/update-hospital.php` is admin-only. It now validates field types, strict appointment-duration allowlist, HH:MM times and boolean confirmation; computes effective new hours with the stored unchanged side and checks schedule conflicts if either side changes. Unexpected internal failures no longer disclose exception text to the client.


Settings update contract extension (5 November 2026): notification and doctor toggles accept only explicit boolean/0/1 values; doctor duration is validated against the existing 15Ã¢â‚¬â€œ120 minute, 5-minute-step control; work times use strict 24-hour HH:MM and a one-sided edit is checked against the stored counterpart inside the row-lock transaction; admin default-tab values match the existing five-option UI select. See `HB-API-014` in the findings register.


## 6 November 2026 — Notification transaction integration checkpoint

Transactional outbox enqueue now covers appointment booking/approval/decline/doctor status update/patient cancellation, PrescriptionService::create/update/complete/cancel, and api/medical/complete-visit.php. Prescription events are owned by the service transaction, not duplicated in endpoint post-commit code. Visit completion queues its existing appointment-completed event before committing clinical changes and dispatches after commit. Isolated regression passed 18/18 prescription checks and 6/6 visit-completion checks, including rollback after injected outbox failure. Reschedule and other clinical/account/settings/support call sites remain open under HB-REL-001; periodic CLI worker scheduling/monitoring is still required.


## 80. HB-REL-001 — transactional reschedule notifications (6 November 2026)

### Implementation
- Moved the existing notification enqueue calls into the seven transaction-owning `RescheduleService` mutations: `requestReschedule`, `suggestReschedule`, `acceptRescheduleSuggestion`, `declineRescheduleSuggestion`, `cancelRescheduleRequest`, `approveReschedule`, and `rejectReschedule`.
- Each transition now records its existing audit event, enqueues the existing recipient notifications through `NotificationService::create()` on the same PDO transaction, and only then commits. A notification preference read or outbox insert failure therefore reaches the existing service catch/rollback path and prevents a state change from committing without its required event.
- After commit, a shared best-effort dispatcher calls `NotificationService::processOutbox(50)`. Delivery failure is logged and leaves committed events for the existing CLI worker; it does not convert a successful reschedule into a false failure.
- Preserved notification types, titles, message text, recipients, reference IDs, route payloads, authorization checks, state transitions, slot revalidation, and audit calls. No duplicate notification logic was added to API routes.
- The `approveReschedule` unavailable-slot path still rolls back before sending its explanatory notification. That branch commits no appointment mutation to couple to an outbox event, so its independent notification remains best-effort after rollback.
- Preserved the existing lock order: pre-transaction doctor ID lookup, doctor `users` row lock, then appointment row lock. `getAppointment(..., true)` continues to lock only `appointments` and read patient display name without a locking join. Notification preferences use a normal non-locking SELECT, and `notification_outbox` intentionally has no recipient FK.

### Verification
- PHP syntax: `services/RescheduleService.php` passed.
- Transaction-order assertions: **7/7** notification enqueue calls now precede the corresponding commit; all seven dispatch calls follow commit.
- Disposable MariaDB integration: **64/64 assertions passed** across all seven service operations. Each operation was tested both normally and with a temporary `BEFORE INSERT` trigger forcing an outbox failure. In every forced-failure case the service operation failed, appointment state rolled back, and no outbox row remained. In every normal case the expected transition committed and all newly queued events reached a delivered/skipped terminal state with no pending events.
- Two-connection lock-order regression passed: an archive-style transaction locked the patient row first and then waited on the appointment; a concurrent reschedule-style transaction locked doctor then appointment, performed the non-locking patient/preferences reads, and inserted to the no-FK outbox. Both transactions committed; no deadlock was reported.
- QA source was an isolated copy of the local synthetic `healthbridge` database (157 users, 486 appointments, 99 prescriptions, 545 notifications). A generated `phone_normalized` collation mismatch was corrected only in the disposable SQL dump using a simplified expression; the real schema and generated column were not changed.
- Disposable schema and test harnesses were removed after testing. The SQL dump and test logs remain under `C:\xampp85\migration-backups\healthbridge`.
- Read-only post-test counts from the real local database remained **157 users / 486 appointments / 99 prescriptions / 545 notifications / 0 outbox rows**. No real appointment, audit, notification, preference, or schema row was changed.
- No commit, push, deployment, hosted database operation, or branch change was performed. Branch and HEAD remain unchanged.

### Remaining under HB-REL-001
- HB-REL-001 remains **P2 open / partially mitigated**. Transactional enqueue now covers the five appointment routes, all seven reschedule workflow mutations, four prescription mutations, and clinical visit completion.
- Remaining producers still require individual transaction and lock-order reviews: other clinical writers, account lifecycle/archive/status paths, registration/doctor creation, ratings, settings, and support replies. Do not bulk-move them or undo prior archive/deadlock protections.
- Periodic CLI worker scheduling and monitoring remain operational requirements. No Windows scheduled task or hosted scheduler was configured in this local pass.
## 81. HB-REL-001 — additional clinical notification writers (6 November 2026)

### Implementation
- `api/medical/add-visit-note.php` now enqueues the existing `TYPE_VISIT_NOTE_ADDED` event after its visit-note write and required audit, but before the existing transaction commits. The event therefore commits or rolls back with note creation/update and audit. Prompt delivery is attempted through `processOutbox(50)` after commit; dispatch errors are logged and left for the CLI worker.
- `api/medical/update-record.php` now enqueues the existing `TYPE_MEDICAL_RECORD_UPDATED` event inside the same transaction as the record update and universal audit, then attempts delivery after commit. Doctor/admin message wording, type, recipient, and `medical_record` reference are unchanged.
- Preserved existing authorization, validation, account lock ordering, appointment locking, and audit semantics. Doctor clinical paths continue to lock doctor then patient then appointment where applicable; administrator medical-record updates lock the patient before the medical-record row. The outbox still has no recipient FK and preference reads remain non-locking.

### Verification
- PHP syntax passed **2/2** for the changed endpoints; targeted `git diff --check` passed (only existing LF/CRLF working-copy warnings).
- Disposable integration on the existing `healthbridge_repair_test` schema passed **23/23 assertions** across visit-note create, visit-note update, and medical-record update. Each operation was tested with a forced `BEFORE INSERT` outbox failure and with normal delivery. Forced failures rolled back the associated clinical write and audit with no outbox event; successful operations committed and delivered their existing notice.
- The older repair-test schema was missing Phase 15's outbox table and the current `visit_notes.follow_up_instructions` column. The Phase 15 outbox migration and the missing visit-note column were applied only to `healthbridge_repair_test` so the fixture matches the current app schema. Test data was restored afterward: appointment 1 has no QA note, patient 2's medical_notes value was restored, and QA admin_audit/notifications/outbox rows were removed. The test schema remains aligned with the current application.
- Read-only verification of the populated local `healthbridge` database remained **157 users / 486 appointments / 99 prescriptions / 545 notifications / 0 outbox rows / 216 visit notes / 124 medical records**. No populated local clinical, audit, or notification rows were changed.
- No commit, push, deployment, hosted database operation, or branch change occurred.

### Remaining under HB-REL-001
- Transactional enqueue now also covers the remaining identified clinical API notification producers (`add-visit-note.php` and `update-record.php`). The completed clinical group is appointment lifecycle, seven reschedule transitions, four prescription mutations, visit completion, visit-note create/update, and medical-record update.
- Next review group: account lifecycle/archive/status and registration/doctor creation notification producers. Preserve the account-first lock order and the prior archive/reschedule deadlock fixes; do not move notifications in bulk.
- Ratings, settings, and support replies still require transaction ownership review. Periodic CLI worker scheduling and monitoring remain outstanding; no Windows scheduled task was created.
## 82. HB-REL-001 — patient/doctor status and doctor archive notifications (6 November 2026)

### Implementation
- `api/patients/toggle-status.php`: the existing `TYPE_ACCOUNT_STATUS_CHANGED` event is now enqueued after the status/session-version update and audit event but before commit. If enqueue fails, the patient status and session-version change roll back with the audit. Delivery runs only after commit.
- `api/doctors/toggle-status.php`: appointment-cancellation events and the doctor's account-status event are now enqueued in the same transaction as not-started appointment cancellation, doctor status/session-version change, and admin audit. Delivery is attempted only after commit.
- `api/doctors/delete.php` (reversible archive): patient appointment-cancellation events are enqueued in the same transaction as appointment cancellations, doctor sign-in disablement, and archive audit. Delivery is attempted only after commit.
- Preserved existing message text, recipients, notification types, reference IDs, response payloads, authorization, active-visit guards, appointment eligibility, and clinical-history retention.
- Lock safety: doctor status/archive still locks the doctor row before appointment/workflow rows. The outbox intentionally has no recipient FK and `NotificationService` preference reads are non-locking; enqueuing patient events while appointment rows are held therefore does not reacquire patient account rows. This preserves the previous fix for the patient-archive/doctor-archive deadlock. No account lock order was changed.

### Verification
- PHP syntax passed **3/3** for patient status toggle, doctor status toggle, and doctor archive endpoints.
- Disposable `healthbridge_repair_test` integration passed **25/25 assertions**. It exercised forced `BEFORE INSERT` outbox failure and normal operation for all three lifecycle paths. Forced failures rolled back patient/doctor `is_active` and `session_version`, appointment cancellations, audit rows, and notification-outbox rows. Successful runs committed state changes and delivered the expected account/cancellation notices.
- Doctor fixtures included two confirmed not-started appointments with `Waiting` workflows. The active-visit protection was preserved; the test did not alter the workflow state.
- The older disposable repair schema lacked `users.session_version`; it was added to `healthbridge_repair_test` only, with the same unsigned integer/default contract as the current application. No schema change was made to the populated local `healthbridge` database.
- After testing, QA patient/doctor `is_active` and `session_version` values and both appointment statuses were restored. QA admin-audit/outbox rows created by the test were deleted. The two pre-existing QA notifications were preserved. Final QA check: admin_audit=0, notifications=2, notification_outbox=0; users 1/2/3 active with session_version=1; appointments 1 and 3 returned to Confirmed.
- Migration backup copies of all three original endpoint files are under `C:\xampp85\migration-backups\healthbridge`.
- No commit, push, deployment, hosted database operation, branch change, or modification to the populated local `healthbridge` data was performed.

### Remaining under HB-REL-001
- Account-creation notification producers reviewed in this group: patient profile/password updates, doctor creation, and patient registration (Sections 83–85).
- Ratings (`api/doctors/submit-rating.php`), settings (`api/settings/update.php`), support replies (`api/settings/reply-contact-message.php`), and other support/contact event producers still need transaction-ownership review.
- Periodic `tools/process_notification_outbox.php` scheduling and monitoring remain outstanding. No Windows scheduled task or hosted scheduler was configured.

### HB-REL-001 — patient update notification transaction (Section 83, 2026-11-06)

- Updated api/patients/update.php. The password-only reset now enqueues TYPE_PASSWORD_CHANGED inside the credential/session-version/audit transaction. The general profile path now enqueues its existing account-status and profile-updated events inside the profile/medical-record/status/audit transaction.
- Both paths dispatch up to 50 committed outbox events after commit. Dispatch exceptions are logged and leave durable events available to the CLI worker. Enqueue errors are intentionally not swallowed inside the transaction, so the business mutation and its outbox events roll back together.
- Preserved notification recipients, types, titles, message text, reference type/ID, response payloads, permissions, validation, existing audit behavior, and patient-row-first locking. No additional row locks or recipient foreign-key checks were introduced.
- Backup: C:\xampp85\migration-backups\healthbridge\patients-update_pre_HB-REL-001_20261106.php.
- Verification: PHP syntax passed; source-order assertions passed for the password-only and profile/status paths; legacy post-commit create blocks are absent; git diff --check passed. Integration failure-injection against this route was not run in this checkpoint, so rollback behavior is not claimed as route-level integration-tested here.
- No database operation was performed during this code change. The populated local healthbridge database and the disposable QA database were not changed by this checkpoint. No commit, push, deployment, hosted database operation, or branch change.

### Remaining under HB-REL-001

- Account-creation notification producers reviewed in this group: patient profile/password updates, doctor creation, and patient registration (Sections 83–85).
- Ratings (api/doctors/submit-rating.php), settings (api/settings/update.php), support replies (api/settings/reply-contact-message.php), and other support/contact event producers still need transaction-ownership review.
- Periodic tools/process_notification_outbox.php scheduling and monitoring remain outstanding. No Windows scheduled task or hosted scheduler is configured.

### HB-REL-001 — doctor creation notification transaction (Section 84, 2026-11-06)

- Updated api/doctors/add.php. The active-admin recipient query and existing TYPE_NEW_DOCTOR enqueue calls now run before the doctor-creation transaction commits, after the user, doctor profile, user preferences, and required admin audit row have been written.
- The transaction now rolls back the doctor account, profile, preferences, audit, and outbox events together if a notification event cannot be enqueued. Up to 50 committed events are dispatched after commit; dispatch failures are logged and left for the CLI worker.
- Preserved active-admin recipient selection, notification type/title/message/reference, doctor fields/defaults, department validation, audit payload, authorization, and success response. Recipient and preference reads remain non-locking; no additional row locks were introduced.
- Backup: C:\xampp85\migration-backups\healthbridge\doctors-add_pre_HB-REL-001_20261106.php.
- Verification: PHP syntax passed; source-order assertions passed; the legacy post-commit create block is absent; git diff --check passed. Route-level database failure-injection testing was not run in this checkpoint.
- No database operation was performed during this code change. The populated local healthbridge database and disposable QA database were not changed. No commit, push, deployment, hosted database operation, or branch change.

### Remaining under HB-REL-001

- Account-creation notification producers reviewed in this group: patient profile/password updates, doctor creation, and patient registration (Sections 83–85).
- Ratings (api/doctors/submit-rating.php), settings (api/settings/update.php), support replies (api/settings/reply-contact-message.php), and other support/contact event producers still need transaction-ownership review.
- Periodic tools/process_notification_outbox.php scheduling and monitoring remain outstanding. No Windows scheduled task or hosted scheduler is configured.

### HB-REL-001 — patient registration notification transaction (Section 85, 2026-11-06)

- Updated api/auth/register.php. Step 2 now queries active admin recipients and enqueues the existing TYPE_NEW_PATIENT events before committing the patient account, default preferences, full medical record, and required patient audit entry.
- NotificationService is now required at the route header because its class is used inside the registration transaction. If any admin event cannot be enqueued, the inner transaction handler rolls back the complete registration. Up to 50 committed events are dispatched after commit; dispatch failures are logged and left for the CLI worker.
- Preserved Step 1 validation-only behavior, Step 2 validation, duplicate-identity response behavior, patient-number generation, patient fields, audit payload, admin recipient selection, notification content/type/reference, and public registration response. Admin recipient and preference reads remain non-locking; no new row locks were introduced.
- Backup: C:\xampp85\migration-backups\healthbridge\register_pre_HB-REL-001_20261106.php.
- Verification: PHP syntax passed; source-order assertions passed for the service include, notification enqueue before commit, and dispatch after commit; the legacy post-commit create block is absent; git diff --check passed. Route-level database failure-injection testing was not run in this checkpoint.
- No database operation was performed during this code change. The populated local healthbridge database and disposable QA database were not changed. No commit, push, deployment, hosted database operation, or branch change.

### Remaining under HB-REL-001

- Account creation notification producers reviewed in this sub-group: patient profile/password updates, doctor creation, and patient registration.
- Review any additional support/contact event producers not yet inventoried. api/settings/contact.php currently stores contact submissions without creating notifications.
- Periodic tools/process_notification_outbox.php scheduling and monitoring remain outstanding. No Windows scheduled task or hosted scheduler is configured.

### HB-REL-001 — doctor rating notification transaction (Section 86, 2026-11-06)

- Updated api/doctors/submit-rating.php. The rating insert, doctor average-rating recalculation, existing doctor notification, and existing first-admin notification now share one transaction.
- Duplicate-rating, missing-appointment, and non-completed-visit exits explicitly roll back the read transaction. Any exception rolls back the rating, aggregate update, and queued events together. The existing recipient selection, notification types/content/references, and success/error responses are preserved.
- Notification delivery is attempted only after commit. Dispatch failures are logged while committed events remain available to the CLI worker. No additional row locks or recipient foreign-key checks were introduced in this checkpoint.
- Backup: C:\xampp85\migration-backups\healthbridge\doctors-submit-rating_pre_HB-REL-001_20261106.php.
- Verification: PHP syntax passed; source inspection/static assertions passed for enqueue-before-commit, dispatch-after-commit, explicit early rollback, and exception rollback; git diff --check passed. Route-level database failure-injection testing was not run.
- No database operation was performed during this code change. The populated local healthbridge database and disposable QA database were not changed. No commit, push, deployment, hosted database operation, or branch change.

### Remaining under HB-REL-001

- Settings profile and doctor self-deactivation notifications still need transaction-ownership review in api/settings/update.php.
- Support reply notifications still need review in api/settings/reply-contact-message.php, along with other support/contact event producers.
- Periodic tools/process_notification_outbox.php scheduling and monitoring remain outstanding. No Windows scheduled task or hosted scheduler is configured.

### HB-REL-001 — user settings notification transaction (Section 87, 2026-11-06)

- Updated api/settings/update.php. The existing TYPE_PROFILE_UPDATED event is now enqueued inside the profile update, user-row lock, and audit transaction. The existing doctor self-deactivation appointment-cancellation events are also enqueued inside the same transaction as appointment cancellations, account deactivation/session-version change, and audit.
- Each branch dispatches committed events after commit. An enqueue failure now rolls back the related profile/deactivation mutation; dispatch failures are logged and left for the CLI worker.
- Preserved profile event wording/reference, doctor-only cancellation recipients/content/type/reference, response payloads, password and preference-only settings behavior, and existing account/appointment/admin lock ordering. Notification recipient and preference checks remain non-locking; the outbox has no recipient FK.
- Backup: C:\xampp85\migration-backups\healthbridge\settings-update_pre_HB-REL-001_20261106.php.
- Verification: PHP syntax passed; source-order assertions passed for profile and doctor self-deactivation; legacy post-commit create blocks are absent; git diff --check passed. Route-level database failure-injection testing was not run.
- No database operation was performed during this code change. The populated local healthbridge database and disposable QA database were not changed. No commit, push, deployment, hosted database operation, or branch change.

### HB-REL-001 — support reply notification transaction (Section 88, 2026-11-06)

- Updated api/settings/reply-contact-message.php. The route now starts a transaction, locks the selected contact_messages row with FOR UPDATE, updates reply/replied_at, and queues the existing TYPE_SUPPORT_REPLY event for a linked user before commit.
- A failed event insert rolls back the support reply. The event is dispatched after commit; dispatch failures are logged and left for the CLI worker. Anonymous contact messages still save replies without a user notification.
- Preserved reply validation, recipient, message content, event type/reference, and success/error responses. The contact-message row is the only newly introduced lock; notification preference reads are non-locking and the outbox has no recipient FK.
- Backup: C:\xampp85\migration-backups\healthbridge\reply-contact-message_pre_HB-REL-001_20261106.php.
- Verification: PHP syntax passed; source-order and rollback assertions passed; git diff --check passed. Route-level database failure-injection testing was not run.
- Reviewed api/settings/contact.php: it persists inbound contact messages but does not currently create notification events, so it was not changed or represented as an outbox producer.
- No database operation was performed during this code change. The populated local healthbridge database and disposable QA database were not changed. No commit, push, deployment, hosted database operation, or branch change.

### Remaining under HB-REL-001

- Review any additional support/contact event producers not yet inventoried. api/settings/contact.php currently stores contact submissions without creating notifications.
- Periodic tools/process_notification_outbox.php scheduling and monitoring remain outstanding. No Windows scheduled task or hosted scheduler is configured.

### HB-REL-001 — notification producer inventory and worker operations (Section 89, 2026-11-06)

- Completed a repository-wide search of NotificationService event creation and direct notifications/notification_outbox writes in api, services, and tools.
- Confirmed api/patient/cancel-appointment.php was already transactional: doctor account lock precedes appointment/workflow locks, the patient cancellation event is enqueued before commit, and dispatch occurs after commit.
- Confirmed api/settings/contact.php currently stores inbound contact messages without creating notification events. The only direct notifications-table insert outside NotificationService is in tools/seed_demo_hospital.php, the demo-data seeder; it is not a runtime event producer.
- Remaining runtime producer work is limited to any additional support/contact route not yet found by the source search. Appointment, reschedule, prescription, clinical, account creation/status, rating, settings profile/deactivation, and support-reply producers have been reviewed in the preceding checkpoints.
- Worker behavior verified by source inspection: CLI-only (HTTP requests return 404); default batch size 50; accepts --limit=1..100; prints delivered/skipped/retried/terminal_failed/pending counts; exits nonzero when terminal failures occur or the worker throws.
- No Windows Scheduled Task or hosted scheduler has been registered. Automatic delivery therefore still depends on post-commit dispatch attempts and manually running the CLI worker. Scheduler cadence, run identity, log retention, and failure alerting remain an operational decision.
- Suggested local invocation from the project root: C:\xampp85\php\php.exe C:\xampp85\htdocs\optimized\tools\process_notification_outbox.php --limit=100.
- No database operation was performed for this inventory checkpoint. No commit, push, deployment, hosted database operation, or branch change.

### HB-REL-001 — local notification outbox scheduled worker (Section 90, 2026-11-06)

- Created tools/run_notification_outbox_worker.ps1, a PowerShell wrapper that invokes C:\xampp85\php\php.exe tools/process_notification_outbox.php --limit=100, records start/output/exit-code lines, and removes daily worker log files older than 30 days.
- Registered Windows Scheduled Task HealthBridge Notification Outbox Worker with a five-minute repetition interval. Its first manual test run completed successfully (Last Result 0) and logged delivered=0 skipped=0 retried=0 terminal_failed=0 pending=0.
- Task settings: overlap policy IgnoreNew; four-minute execution limit; starts missed runs when available; allowed to start and continue on battery.
- Logs are written outside the web project at C:\xampp85\logs\healthbridge\notification-outbox\worker-YYYY-MM-DD.log.
- Permission constraint: the Desktop Commander session is not running with an elevated administrator token. Registration as NT AUTHORITY\SYSTEM was rejected with Access is denied, so the working task was registered under the configured local Windows account with Interactive logon. It runs every five minutes while that Windows user is logged in; it does not run while the user is signed out. A machine-wide/background task would require an elevated administrator registration.
- Monitoring currently consists of daily log output, task Last Run Time/Last Result, and worker exit codes. No email/push alerting is configured. Inspect the latest daily log and Task Scheduler result when investigating failures.
- The worker itself is CLI-only. No application/schema changes and no database writes were made by the scheduler setup beyond whatever the worker processes; the initial run found the queue empty and processed zero events.
- No commit, push, deployment, hosted database operation, or branch change.

### HB-REL-001 operational follow-up

- If unattended processing while signed out is required, register the same task under SYSTEM from an elevated administrator session. Do not duplicate the current task; update its principal and retain its five-minute trigger/logging settings.
- Consider external alerting for repeated worker failures/terminal outbox failures after the local runtime is stable.
## Current status reconciliation - 6 November 2026

- This module map remains an evolving dependency map. It is not a completed page-to-controller-to-route-to-service-to-table graph until Phase 1 tracing and caller verification are complete.
- The notification path is now producer -> NotificationService -> notification_outbox within the owning business transaction -> processOutbox after commit -> CLI retry worker. The local scheduled wrapper is tools/run_notification_outbox_worker.ps1.
- Transactional notification producer changes are recorded in root handoff Sections 80-90. Recent API changes include patient update, doctor creation, registration, ratings, settings, and support replies.
- Do not split large services/controllers based on line count alone. Complete responsibility and caller maps and targeted regression coverage first.
## 8 November 2026 — Doctor workspace/clinical encounter trace

### Actual entry points and direct callers

- The entry page is pages/doctor/doctor-workspace.html. Its actual script tags load assets/js/core/main.js, assets/js/modules/prescriptions.js, assets/js/pages/doctor-workspace.js, and assets/js/modules/notifications.js. It does not load modules/medical-records.js.
- doctor-workspace.js requires requireServerRole('doctor'), reads patient_id and optional appointment_id from the URL, and loads the selected chart using GET api/doctors/workspace-data.php.
- Direct workspace calls found in the controller: doctors/workspace-data.php; medical/transition-workflow.php; medical/add-visit-note.php; medical/save-visit-draft.php; medical/get-visit-draft.php; medical/delete-visit-draft.php; medical/complete-visit.php; medical/update-record.php; medical/add-history-update.php; prescriptions/get.php; and prescriptions/create.php.
- medical/get-emr-data.php and medical/get-record.php are related clinical read APIs used by the separate patient EMR flows, not direct URL literals in this workspace controller.

### Data ownership and workflow

- The workspace GET requires doctor role and the shared ClinicalAccessService::doctorCanAccessPatient() policy before returning patient data. The policy allows confirmed pre-visit review; a cancelled booking only preserves access if the same encounter reached In Progress/Ready to Complete/Completed or has a visit note/prescription.
- The workspace response reads patient identity from users; medical history from medical_records; bookings from appointments; workflow status from visit_workflow; visit notes from visit_notes; prescriptions/items from prescriptions and prescription_items; and the historical clinical timeline from medical_history_updates.
- Draft operations use visit_drafts and remain scoped to the authenticated doctor's own confirmed appointment. Workflow writes use VisitWorkflowService; prescription writes use PrescriptionService; clinical corrections/history use the existing record/history APIs and audit services.
- Visit-note updates, clinical record updates, workflow completion, prescription state changes, draft deletion, and their mandatory audit/outbox writes are intended to be transactionally coupled in their owning routes. Notification delivery is attempted after commit and retried by the existing outbox worker.
- The workspace GET now depends on ClinicalAccessService for its patient-scope gate and PrescriptionService for shared batched prescription-item hydration. Unused NotificationService.php and VisitWorkflowService.php includes were removed; visit workflow state is read with SQL joins in this read endpoint.

### Findings and current verification

- Fixed the missing authenticated $doctorId assignment in api/medical/delete-visit-draft.php. On the local PHP 8.5.12 runtime, an isolated undefined-variable-to-typed-int probe reproduced a warning followed by TypeError; the endpoint previously caught this as a 500.
- Changed api/medical/get-visit-draft.php from loose integer casting to shared parsePositiveInt(), returning HTTP 400 for malformed IDs.
- PHP lint passed for both draft routes, the shared hydrator service, both read APIs, and the external test harness. The parser passed 9/9 earlier cases; the dedicated built-in-server HTTP integration suite passed 41/41 assertions across draft authorization/lifecycle and both prescription read paths.
- The 41/41 disposable HTTP suite verified authenticated draft GET/POST behavior and restored its synthetic fixture tables exactly; the populated healthbridge database was not targeted.
- HB-PERF-002 is locally resolved: both workspace and patient EMR use the shared PrescriptionService::attachItems() helper; exact item-array, sort_order and item_count equivalence passed against independent SQL in the disposable HTTP suite.
- Existing draft service rollback tests did not catch the undefined variable at the route layer. Include endpoint-level assertions in future tests, not only service-level tests.

### Shared prescription read model — 8 November 2026

- `PrescriptionService::attachItems(array $prescriptions, ?string $countKey = null)` is now the single bounded batch hydrator. It deduplicates positive prescription IDs, selects items in batches of up to 500 IDs, orders by `prescription_id, sort_order`, initializes empty item arrays, and optionally derives a count property from the hydrated result.
- `PrescriptionService::search()` continues to use the private compatibility wrapper `withItems()` with its pre-existing `items_count` response field. Doctor workspace and patient EMR now call `attachItems(..., 'item_count')`, preserving their prior item and count response keys while sharing one implementation.
- The doctor workspace's per-prescription item query loop and both EMR/workspace correlated item-count subqueries have been removed. The patient EMR's former separate 30-line batch-hydration implementation has also been removed.
- Unused service includes were removed from the two read endpoints after direct source-use review. No new API or user-facing feature was introduced.
- HTTP integration and exact item-array/count equivalence across both endpoints passed 41/41 total assertions on the disposable database; the database was restored to its captured table counts.

### Patient portal page dependency map — 8 November 2026

- Nine standard patient pages live under `pages/patient/`: dashboard, appointments, downloads, history, insights, notifications, prescriptions, profile, and settings. The separate `patient-emr.html` entry is an EMR workflow, not the legacy dashboard.
- Their page-specific controllers are under `assets/js/pages/patient/`; appointments composes the booking wizard and appointment/rating/reschedule APIs, prescriptions uses its focused list/print APIs with a dashboard fallback, notifications uses the shared aggregate plus the focused notification API, and settings uses the shared `settings.js` controller.
- Dashboard, downloads, history, insights, notifications, prescriptions (fallback), and profile controllers call `api/patient/dashboard.php`. That endpoint delegates to `PatientPortalService::getAll()`, currently assembling 12 sections for every normal request. See HB-PERF-003 before altering this contract.
- The old 2,006-line `assets/js/pages/patient-portal.js` remains removed locally (pre-existing unstaged deletion). Its Git-baseline dashboard did not load it; no current application or dynamic loader references it. The nine standard pages still load `assets/css/pages/patient-portal.css`, which remains active.
- Local HTTP smoke checks returned 200 for all 10 patient-directory HTML files and linked first-party assets, and 404 for the retired script. This validates static delivery only; authenticated page behavior remains a separate acceptance gate.


### Patient portal selective aggregate contract — 8 November 2026

- `PatientPortalService::SECTION_METHODS` is the canonical ordered mapping from the 12 existing payload section names to their existing query methods. `getSelected($patientId, $sections)` validates a sequential, unique, allowlisted list before running any query; `getAll()` delegates to all keys in canonical order.
- The patient dashboard API's optional `sections` property selects a subset. Missing property preserves the legacy 18-key endpoint payload (12 base sections plus six derived dashboard aliases); the dashboard controller continues using that default. Search input is still handled first and is unchanged.
- Section-page dependencies: downloads → downloads; history → medical_timeline; insights → insights + health_snapshot + profile_completion + medical_timeline + prescriptions; notifications → notifications; prescriptions fallback → prescriptions; profile → profile + overview + health_snapshot.
- Appointment booking/list and settings remain on their focused APIs; no change was made to those controllers. Admin dashboard analytics calls a different service and is not coupled to this patient portal selection.
- HTTP regression passed 17/17. Selected response sections matched the complete aggregate; invalid selection forms returned 400; omitted sections preserved all 18 keys and ordering.


### Current entry-point reconciliation and authenticated browser acceptance — 8 November 2026

The 22-entry HTML census was rerun: five root-level public pages and 17 pages under `pages/`. The original page map remains the orientation baseline; current details below supersede the earlier abbreviated admin script list.

- `pages/admin/admin.html` loads: `core/main.js`, `modules/departments-loader.js`, `pages/admin.js`, `modules/admin-departments.js`, `modules/admin-departments-loader.js`, `modules/admin-hospital-settings.js`, `modules/medical-records.js`, `modules/prescriptions.js`, `pages/settings.js`, `modules/schedule.js`, `modules/notifications.js`, and `pages/admin-dashboard.js`.
- `pages/admin/admin-doctor-profile.html` loads `core/main.js`, `pages/admin-doctor-profile.js`, and `modules/schedule.js`. Its Schedule tab is lazy-loaded; it calls the shared schedule GET route with `doctor_id` in the query string (HB-API-023).
- Doctor dashboard loads the shared main, schedule, notification and settings infrastructure plus `pages/doctor-dashboard.js`; doctor workspace loads main, prescriptions, `pages/doctor-workspace.js`, and notifications.
- Seven patient pages were authenticated in browser: dashboard, downloads, history, insights, notifications, prescriptions, and profile. The original dashboard 18-key response/order, all six selective section contracts, and the prescription fallback were verified. Patient appointments/settings/EMR remain to be browser-tested.

**Runtime evidence:** patient ID 2, doctor ID 3, and admin ID 1 synthetic sessions were used through an isolated browser router targeting only loopback `healthbridge_repair_test`. Doctor dashboard, doctor workspace (patient 2 / appointment 1), admin dashboard, and admin-doctor-profile all rendered. The final staff harness blocked all state-changing methods; zero write attempts were observed. No database rows or schema were modified.


### Section 99 authenticated page/module runtime evidence - 8 November 2026

The current HTML script tags were rechecked against the three remaining entries and corrected in the page table above:

- pages/patient/appointments.html loads core/main.js, components/layout.js, modules/departments-loader.js, components/booking-wizard.js, and pages/patient/appointments.js. The authenticated patient browser run exercised the api/patient/appointments.php list/filter/search GET and observed startup reads for doctors, departments, and unread notifications.
- pages/patient/settings.html loads core/main.js, components/layout.js, and pages/settings.js. The patient role loads api/settings/get.php; the four patient tabs are Account, Password, Appearance, and Notifications, while Practice and Admin remain hidden.
- pages/patient/patient-emr.html loads core/main.js, modules/departments-loader.js, pages/patient-emr.js, modules/medical-records.js, modules/prescriptions.js, and modules/notifications.js. Despite its directory, the page controller calls requireServerRole('admin'); it is an admin-facing patient-record view, not the patient self-service EMR page. The aggregate endpoint is api/medical/get-emr-data.php?patient_id=...; the Audit tab performs the admin-only api/audit/get.php GET.

Authenticated browser checks passed on all three pages. The appointments page passed initial all, confirmed/all filters, and Cardiology search. Settings activated all four patient-visible panels and hid doctor/admin tabs. Patient EMR rendered all nine panels with synthetic admin ID 1 and synthetic patient ID 2. Every observed API response was 200; no page/console/failed-local/overflow errors or attempted writes were observed. The browser used read interception and did not execute any form, booking, cancellation, clinical, archive, rating, notification, or print action.

This supersedes the prior statement that patient appointments, settings, and EMR remained to be browser-tested. The app now has authenticated browser evidence for 10 patient-directory/EMR pages, both doctor pages, and both admin pages. The three auth entry pages were not driven through login, registration, or password-reset workflows in this section.


### Section 100 public entry-point runtime and shared action map - 8 November 2026

The five public rows above now reflect current root HTML script tags and observed runtime dependencies:

- Home: main.js and home.js; authenticated/public active department read plus the restricted public hospital-hours read. Nine departments rendered and both API calls succeeded.
- Doctors: main.js and doctors.js; department, doctor-directory, and batch public-availability GETs. Eight active doctors, nine department chips, and eight availability records rendered. The Cardiology search narrowed to one result. The doctor-detail modal is generated from in-memory directory data; guest booking is a login redirect/CTA, not a write from this page.
- Contact: main.js and contact.js; contact submission invokes a local POST and then an unconditional external Formspree POST with the same six submitted fields. The form's visible notice does not disclose the external destination. See HB-SEC-008. Browser QA checked visibility/fields only and never submitted.
- About: main.js only; no dedicated page controller or page-specific data endpoint found. Shared logout/settings helpers remain conditional on user interaction/authenticated page state.
- Services: main.js and services.js; filters/FAQs are client-side. services.js calls the shared getSessionUser() helper, which sends GET api/auth/current_user.php to preserve login-aware booking links; the anonymous response is expected HTTP 401 and leaves the page in logged-out state.

Anonymous root-page browser checks passed for all five entries. No browser writes were attempted. The only non-200 read was the expected services-page current_user 401; Chromium recorded the corresponding network status message, but there was no JavaScript exception or other local console error.

Static data contract review: the doctor directory response/table has no patient-count, education/board-certification, or languages fields, yet doctors.js hard-codes 100+ patients, Medical Degree / Board Certified, and English/Arabic for all doctors. Recorded as HB-DATA-001; do not treat those values as verified medical credentials or operational metrics.

### Public doctor API exception semantics

The catch blocks in api/doctors/get.php and api/doctors/get-public-availability.php now explicitly set HTTP 500 before returning their existing failure JSON payloads. This aligns transport status with an internal server failure while preserving successful response fields and public read behavior. PHP lint passed; availability failure and success status cases were exercised over local HTTP.


### Section 101 authentication runtime/dependency map - 8 November 2026

Login includes the Forgot Password panel in the same DOM. Registration reads public governorates/cities. Reset Password is token-addressed and uses a verify_only request before the final mutation.

Page/runtime evidence:
- login.html: core/main.js + core/auth.js. Empty credentials show client errors and make no request; Forgot Password panel open/back works.
- register.html: core/main.js + core/auth.js. Governorates GET loaded 27 rows; empty Step 1 produced six validation errors and no registration POST.
- reset-password.html: core/main.js + pages/reset-password.js. Missing token shows invalid state without API call. Synthetic token and intercepted verify/submit responses tested password policy without a real token/account.

Password policy is score >=4 of five checks in includes/validation.php::validatePassword() and assets/js/core/auth.js::checkPasswordStrength(). HB-AUTH-004 resolves the reset-page mismatch; reset JS and HTML now use the same policy/score labels and the 60-minute server token expiry.

Backend dependency map:
- Login -> AuthAbuseRateLimiter + users; host-temp HMAC files are outside web root.
- Registration Step 2 -> users, medical_records, user_preferences, admin_audit, notification_outbox; committed outbox delivery may create notifications. NotificationService and AuditService are shared dependencies.
- Forgot Password -> users + password_resets; no mailer exists. Loopback development only returns reset_url; deployed/non-loopback returns 503.
- Reset Password -> password_resets + users; verify_only is SELECT-only, actual reset updates password/session_version and consumes tokens transactionally.
- Locations -> egypt_governorates + egypt_cities via validation helpers.
- Current user -> users through requireAuth(), including account status, role, session timeout and session_version checks.
- Logout -> PHP session/cookie cleanup only.
- check-availability.php is retained compatibility validation with no exact current UI caller and no account-existence query.

Fixture limitation: repair_test has notification_outbox but lacks Phase 10 requested-reschedule columns; restore_test has Phase 10 fields but no Phase 15 notification_outbox. Master schema and active Phase 15 migration define it. Do not use restore_test for Step 2 registration/outbox integration until outbox is applied through an explicit safe fixture plan. No migration or write was performed.


HB-AUTH-005: the login Forgot Password panel has no production delivery transport; its API only exposes a reset URL on strict loopback development. Do not treat the current feature as deployed self-service email recovery.


### Section 102 API/service dependency census - 8 November 2026

The current API directory contains 89 PHP endpoints. Static route scanning confirms an explicit request-method check and an Allow header on each route's 405 branch. api/notifications/notifications.php is the only multi-verb endpoint found in this simple census and intentionally handles GET and POST.

Service caller counts from source references (overlapping where one endpoint uses several services):
- AuditService 28; AdminAuditService 12; ClinicalAccessService 15; DashboardAnalyticsService 1; DepartmentService 9; HospitalSettingsService 3; NotificationService 19; PatientPortalService 2; PrescriptionService 8; RescheduleService 8; ScheduleService 11; VisitDraftService 4; VisitWorkflowService 5.

Primary dependency domains:
- Audit services record administrative/business audit events and serve audit-log reads.
- ClinicalAccessService centralizes doctor-to-patient relationship checks used by EMR and clinical workflows.
- NotificationService uses user_preferences, notification_outbox, notifications and users.
- PatientPortalService aggregates patient-facing data including appointments, clinical/visit workflow, prescriptions, ratings, notifications and doctor/department context.
- PrescriptionService uses prescriptions, prescription_items, appointments and users.
- RescheduleService uses appointments/users; ScheduleService uses appointments, doctor_schedule_settings, doctor_schedule_weekly, hospital_settings and users.
- VisitDraftService uses visit_drafts; VisitWorkflowService uses appointments and visit_workflow.

Seven HB-CLEAN-001 routes remain compatibility candidates with no exact current JS/HTML path caller. Current API comparisons:
- Doctor dashboard gets appointment reschedule fields from appointments/get-doctor.php; the dedicated pending-reschedules route remains a narrower admin-all/doctor-self list contract.
- Current admin review UI gets per-doctor reviews from doctors/get-doctor-ratings.php; get-admin-ratings.php is a separate all-system admin result with no present UI caller.
- Patient notifications use notifications/notifications.php + NotificationService for paging/actions; get-patient.php is a legacy latest-20 view.
- EMR audit_history reads medical_record_audit; get-audit.php reads patient_audit_log, which has no application writer identified. Keep its route/table pending historical-data and external-consumer review.
- patients/profile.php is an older admin patient aggregate; its support-message payload is not proven equivalent to the current EMR aggregate.
- settings/get-user-messages.php is a user-scoped support inbox not found in the current UI.
- auth/check-availability.php is format-only and returns available:null; current registration does not call it.
No candidate was removed. Nine routes without central auth helper are the intentionally public authentication/lookup and active public doctor-directory/availability routes.

HB-API-025 tracks three source-level error-response paths without explicit HTTP status assignment: doctors/get-rating.php, patients/get-audit.php, and settings/contact.php. A thrown exception in these catch branches may retain HTTP 200 because jsonResponse() does not set status. This was not fault-injected and no route was modified.


### Section 103 API status-contract correction - 8 November 2026

HB-API-025 was directly reproduced for the two read routes using an isolated loopback PHP harness executing the actual endpoint source with synthetic patient identity and a PDO stub that throws before any SQL or DB connection. Both returned HTTP 200 / success:false before correction.

The existing catch blocks in api/doctors/get-rating.php and api/patients/get-audit.php now each set HTTP 500 before returning their existing generic JSON failure. No other route logic changed. Post-fix injected HTTP requests returned 500 / success:false.

Regression contracts: POST returns 405 / Allow: GET for both; invalid appointment ID returns 400; patient self-scope denies another patient with 403. api/settings/contact.php remains unchanged and untested due the existing prohibition on contact submissions. HB-API-025 is partially resolved; contact failure-status handling remains open.

No DB connection, real account, patient data, or contact form was used.


### Section 103 follow-up - HB-API-025 Contact catch (8 November 2026)

The Contact catch body was independently tested without running the endpoint or submitting form data. A temporary loopback PHP harness tokenized contact.php and executed only its verbatim catch body under a synthetic Exception. It bypassed request parsing, validation, DB access, persistence, and delivery. The unmodified catch fragment returned HTTP 200 / success:false. Adding http_response_code(500) changed the same fragment to HTTP 500 / success:false; the original generic JSON message is unchanged.

All three HB-API-025 exception-response branches now assign HTTP 500. The finding is resolved at catch-branch level, not a full Contact workflow certification. No contact submission or DB access took place.


### HB-DATA-001 - public doctor-profile claims corrected (8 November 2026)

Removed unsupported patient-volume, education/board-certification and language claims from the public doctor card/modal. The modal's inferred specialization marketing paragraph is now a plain department value. The card retains experience and patient rating; the modal retains Experience, Department, Patient Reviews, and schedule-backed fields where available.

Real public page + actual JS/CSS browser test passed with all three API requests intercepted/mocked: two supported card stats only, no unsupported text, modal information titles verified, mobile 390px layout passed, zero page errors. No API/database access or doctor-record modification occurred. HB-DATA-001 is locally resolved; live production acceptance remains unperformed.


### HB-SEC-008 follow-up - single-destination contact flow (8 November 2026)

The current contact-page flow posts submitted details once to the same-origin HealthBridge contact API. The Formspree endpoint and external payload copy were removed; the page now discloses HealthBridge-only form handling and advises against including urgent or sensitive medical information. The API inventory/module map rows above reflect the current behavior.

Mocked Chrome regression intercepted three synthetic local API POSTs before PHP: success response reset the form; failure response preserved the form; HTTP 500 with success:true was rejected. Zero Formspree requests, all unrelated external requests blocked, no PHP/database request, and zero browser page errors. HB-SEC-008 is locally resolved; production deployment verification remains pending.


### HB-SEC-007 deployed-boundary update (8 November 2026)

The deployed site is browser-accessible and returns HTTP 206 for ranged GETs to /.gitignore, /README.md, /composer.json and /database/healthbridge.sql. No response body was read. This confirms partial exposure of static internal metadata/schema despite local Apache denying matching paths. Local .htaccess protections have not been synchronized since the original hosting upload. Other sensitive paths remain unknown/ambiguous; do not describe them as protected. No hosting mutation was performed; controlled remediation awaits explicit approval.


### HB-SEC-007 hosted document-root boundary (8 November 2026)

The deployed InfinityFree document root is `/htdocs`. Following explicit authorization and a complete pre-change ZIP backup, a 724-byte root `.htaccess` now denies dot-env/Git metadata paths, Composer files, Markdown/SQL documents, direct requests to `database`, `docs`, `services`, `tools`, and `vendor`, and PHP under `includes`. Directory indexes are disabled. It deliberately leaves public HTML, the public `api` tree and non-PHP include fragments accessible.

Live browser verification: six public pages returned 200; six internal directory roots returned 403; 12/16 sensitive-path probes returned 403. The four prior 206 disclosures (`.gitignore`, README, Composer JSON, database SQL) now return 403. `.env`, `.git/HEAD`, `.git/config`, and `.htaccess` remain inconclusive because connections closed without an HTTP status. No response body, secret, SQL content, or PHP source was read. No hosted application module or DB changed. HB-SEC-007 remains P1/open until those unknown paths and remote historical migration-script presence are safely resolved.


## Deployment boundary update — local source is authoritative for current work (8 November 2026)

This module map describes the local working tree only. The hosted copy is frozen and must not be treated as synchronized with local source or accessed for routine inspection. Continue module tracing, service ownership work, refactoring, and regression verification locally at `C:\xampp85\htdocs\optimized`.

Do not use the hosting File Manager, FTP, control panel, remote filesystem, or hosted DB during local maintenance. No hosted source/configuration edits, uploads, deletions, cleanup, or DB changes are permitted. A hosted check is allowed only as a specifically justified, read-only, non-mutating public smoke test; local tests are the default.

The existing hosted `/htdocs/.htaccess` rule set remains as last applied; no further remote remediation is planned now. HB-SEC-007 remains P1 / partially mitigated / open because four dotfile responses and the remote historical migration-executor inventory are unresolved. This module map must not claim that local directory rules or source deletions are already deployed.

After local acceptance, prepare a clean runtime-only deployment artifact and fresh backup/rollback plan. The full replacement upload from the start requires explicit user approval; database migrations/data changes remain a separate approval and release gate.


### HB-SEC-001 authenticated route regression follow-up — 8 November 2026

The shared `ClinicalAccessService` policy is now covered by a focused authenticated loopback HTTP suite across the doctor workspace, unified EMR, medical-record read, printable EMR, legacy patient audit, medical-history update, and medical-record update routes. Allowed assigned-doctor reads returned 200; cross-doctor reads and write attempts returned 403; patient self-scope and doctor-only route boundaries passed. The printable EMR route now runs doctor authorization before patient existence lookup, preventing a 403-versus-404 patient-ID existence side channel. Suite result: **22/22 assertions**, with six monitored table counts unchanged. See root handoff Section 109 and the external QA runner in `C:\xampp85\migration-backups\healthbridge`.



### Session/role route regression — 8 November 2026

Authenticated local loopback checks passed **18/18** across `get-contact-messages.php`, `get-hospital.php`, `get-user-messages.php`, and `departments/get.php`. An additional **8/8** session-revocation checks confirmed stale session-version 401 and disabled doctor/admin 403, with temporary synthetic fixture account state restored exactly. The tests used `healthbridge_repair_test` only and changed no application records. See root handoff Section 110; QA runners are stored outside the repository under `C:\xampp85\migration-backups\healthbridge`.


### Shared response and Apache security-header boundary — 8 November 2026

`includes/response.php::sendCorsHeaders()` continues to enforce same-origin Origin and Fetch Metadata checks and now emits a narrow CSP fallback. Root `.htaccess` supplies the baseline headers for static files/error responses, conditional HSTS over HTTPS, and removes `X-Powered-By`; expression guards prevent duplicate values on PHP routes. Local HTML/CSS/JS/API/403 checks passed, as did the 10/10 Origin/Fetch Metadata suite. Full `script-src`/`style-src` CSP remains deferred pending source/origin inventory and migration.


### Appointment booking acceptance — 8 November 2026

`api/appointments/book.php` passed a 28/28 authenticated local loopback HTTP regression against the synthetic `healthbridge_repair_test` fixture. Tests covered the patient/admin/doctor role boundary, strict IDs, doctor/department consistency, inactive doctor/department, legacy-name ambiguity, valid patient self-book, admin-assisted booking, legacy unique-name compatibility, canonical DB identity and notification enqueue. Three temporary bookings and child/audit/notification records were removed; ten table counts and mutated fixture fields returned exactly to baseline. See root handoff Section 112 and external runner `C:\xampp85\migration-backups\healthbridge\test_hb_db_002_booking_http.php`.


### Admin patient-password reset route regression — 8 November 2026

`api/patients/update.php` password-only branch passed **18/18** authenticated local HTTP assertions. The synthetic patient hash, session_version and updated_at were restored exactly after confirming admin-only access, input validation, new hash verification and prior-session revocation. Audit/outbox/notification rows created by the test were removed. See root handoff Section 113 and external runner `C:\xampp85\migration-backups\healthbridge\test_hb_api_006_password_reset_http.php`. The actual Patient EMR browser modal remains a separate acceptance gate.


### Available-slot API validation — 8 November 2026

The POST-only `api/appointments/get-available-slots.php` route now strictly parses doctor_id, type-checks date before trimming, and validates a real YYYY-MM-DD calendar date. Local authenticated HTTP regression passed **21/21** and exactly matched `ScheduleService` for valid slots; appointment/audit/notification/outbox counts remained unchanged. See root handoff Section 114.


### Doctor-profile read routes — 8 November 2026

Admin-selected doctor appointments/ratings routes now use shared overflow-safe positive ID parsing while doctor sessions stay self-only. Authenticated local request regression passed 34/34, including the patient_id appointment alias and ratings anonymization; five relevant fixture counts were independently confirmed unchanged. See root handoff Section 115. Real browser profile-panel smoke remains pending.


### Login and authentication-abuse boundary — 8 November 2026

The login request path is `api/auth/login.php` → `includes/auth.php` (session and shared response policy, DB/validation helpers) → `includes/AuthAbuseRateLimiter.php` → `users` credential lookup. The login limiter keys normalized email and `REMOTE_ADDR` with HMAC-SHA256, holds counters and its secret in the host temporary directory outside the web root, and applies 10 attempts per email / 60 per source IP per 15 minutes. It does not trust forwarded IP headers.

An actual local loopback HTTP regression passed **20/20** against the synthetic repair fixture, proving the POST contract, identical generic unknown/wrong-password failures, 10/11 throttling behavior, bounded Retry-After, HMAC-only storage, and unchanged fixture state. Multi-host/shared storage is not implemented. See root handoff Section 116.


### API method-boundary verification — 8 November 2026

Every current `api/**/*.php` route (89 files) has source-level `REQUEST_METHOD` handling, an explicit 405 response branch, and an Allow declaration. The final role-aware local HTTP sweep sent bodyless unsupported PUT to all 89 routes and passed 90/90 assertions, including correct combined GET/POST support on the shared notification endpoint. A full disposable-database content/count/auto-increment snapshot stayed identical. This verifies unsupported-method rejection only; valid GET/POST authorization, business behavior, and UI callers remain separately tested. See handoff Section 117.


### Clinical access policy runtime evidence — 8 November 2026

The shared `ClinicalAccessService::doctorCanAccessPatient()` policy now has an additional actual-route integration check. EMR and doctor workspace routes both denied cancelled appointments with only Waiting workflow/no documents, and both allowed cancelled encounters with In Progress workflow or matching clinical documentation. Clinical session_version revocation and inactive-account rejection were also verified on the workspace route. The disposable DB was fully fingerprinted before/after and restored exactly. See handoff Section 118.


### Clinical write transaction and workflow routes — 8 November 2026

The eight clinical write/cancel routes received **37/37** authenticated local HTTP assertions. The run exercised input/role/ownership checks, waiting-visit completion guard, draft ownership, valid workflow transitions and rework, medical-record validation/admin correction reason rules, and patient cancellation blocks for active/completed encounters. A temporary workflow transition was restored exactly and the entire disposable DB snapshot matched baseline. See handoff Section 119.


### Medical read/profile API boundary verification — 8 November 2026

Admin patient/doctor profile APIs, the three medical readers, and pending-reschedule listing passed **32/32** local HTTP assertions for strict identifiers, role enforcement, patient self-scope, doctor assignment restrictions, and response-envelope consistency. The complete disposable DB content/count/auto-increment snapshot was unchanged. See handoff Section 120.


### Notification recipient isolation runtime proof — 8 November 2026

The combined notification endpoint was tested via authenticated HTTP for admin/patient/doctor roles. All four mutations were verified to remain scoped to the recipient ID, including forged patient IDs and cross-user notification IDs; patient and admin bulk operations preserved other users' notifications. The full notification table and original auto-increment were restored exactly after the test, with full DB snapshot equality. See handoff Section 121.


### Patient appointment pagination response — 8 November 2026

The patient appointment pagination callback now captures the page number needed for its response metadata. This corrected a real API issue where `pagination.current_page` was null. Authenticated loopback regression passed 22/22 for pagination metadata, role/scope, malformed values, overflow, safe boundaries, and clamping; the disposable DB snapshot remained unchanged. See handoff Section 122.


### Schedule API role and input boundary — 8 November 2026

The schedule GET/update/reset endpoints have **38/38** local authenticated HTTP regression assertions covering admin read scope, doctor self-scope, patient denial, strict target IDs, nested schedule field validation, and reset restrictions. Only invalid write requests were sent and the disposable database snapshot stayed unchanged. See handoff Section 123.


### Self-service and hospital settings persistence boundary - 8 November 2026

Self-service settings and hospital-settings routes passed 39/39 authenticated local HTTP assertions. The test revealed an early INSERT IGNORE that consumed preference sequence values on rejected requests; it was replaced by a lazy ensure-row helper called only after validation immediately before preference updates. Both invalid-input no-side-effect and valid legacy preference row creation/restoration were verified. See handoff Section 124.


### Prescription mutation API verification - 8 November 2026

The existing prescription module's four doctor-only mutation routes (create/update/complete/cancel) passed **54/54** authenticated local HTTP assertions for POST method contracts, role restrictions, strict appointment/prescription IDs, typed/length-bounded notes, list-shaped medication items, cancellation reason validation, and cross-doctor ownership denials. The test used only synthetic healthbridge_repair_test fixtures and did not run a successful mutation; the existing Completed prescription/item rows and complete DB fingerprint remained unchanged. No service or endpoint source changed during this verification. See handoff Section 125.


### Admin patient-profile medical fields API verification - 8 November 2026

The admin patient update route passed **36/36** synthetic-role authenticated HTTP assertions for patient ID, gender, DOB and account-status input validation plus admin-only role boundaries. No valid write was sent; patient profile/medical-record data and complete disposable DB fingerprints remained unchanged. See handoff Section 126.


### Reschedule mutation API input/role boundary - 8 November 2026

The seven reschedule mutation endpoints passed **63/63** authenticated HTTP assertions for POST-only contracts, patient/doctor role restrictions, malformed appointment IDs, and non-text date/time/notes/reason inputs. No valid state transition was invoked; full disposable DB snapshot and the synthetic appointment fixture remained unchanged. See handoff Section 127.


### Appointment cancellation and admin/doctor lifecycle route verification - 8 November 2026

Patient cancellation, admin approval/decline and doctor status update passed **62/62** authenticated local HTTP assertions. The test traversed the account-first rollback path without committing; patient/doctor ownership and role boundaries were checked; appointment fixtures and full disposable DB snapshot remained unchanged. Booking remains separately verified under HB-DB-002 (28/28). See handoff Section 128.


### Admin account archive/status API verification - 8 November 2026

Doctor/patient archive and toggle-status endpoints passed **58/58** authenticated local HTTP assertions for POST/role enforcement, strict IDs, and wrong account-role/missing target handling. No valid account action was sent; the full disposable DB and account snapshots remained identical. See handoff Section 129.


### Appointment lifecycle lock-order concurrency proof - 8 November 2026

Patient cancellation, admin approval/decline, and doctor update were run concurrently against a held synthetic doctor-account lock. The actual HTTP worker was observed at the doctor-account FOR UPDATE query; a competing transaction acquired patient and appointment in order and committed, after which the HTTP route returned rollback-safe 409. All four scenarios passed; full DB snapshot unchanged. See handoff Section 130.


### Read-route exception status handling - 8 November 2026

Rating and patient-audit actual read endpoints now have forced database-failure HTTP evidence: each returned 500 with a failure JSON envelope, recovered to 200 when the backing table was restored, and had its table DDL/rows/AUTO_INCREMENT verified unchanged. The contact catch remains separately isolated-tested without calling the endpoint. See handoff Section 131.


### Shared request-origin and session-cookie policy - 8 November 2026

All 89 API PHP files were found to reference the shared auth/bootstrap or authorization helpers; the common frontend apiFetch wrapper explicitly uses same-origin credentials. The extended Origin/Fetch Metadata and real auth.php bootstrap test passed 27/27 for additional origin boundaries, Fetch Metadata rejection, HTTP/HTTPS cookie flags, host-only/SameSite/HttpOnly/Secure attributes, session ID fixation/URL rejection, and cookie expiry. See handoff Section 132.


### Shared DOM output and navigation safety helpers - 8 November 2026

The shared frontend layer now exposes context-specific helpers for HTML encoding, safe CSS class tokens, strict positive identifiers, and same-origin URL validation. Targeted uses were applied to admin/doctor/patient dashboards, public doctor cards, patient EMR, prescription rendering, downloads, alerts, and medical-record error text. Dynamic doctor/patient names are JSON-serialized before HTML-escaped inline-handler attributes; URL handlers reject external and script schemes. Shared tab/notification navigation no longer interpolates untrusted values into CSS selectors.

Helper regression passed **35/35** and 22 modified JavaScript files parsed. The broad DOM sink review and browser-based XSS regression remain open. See handoff Section 133.


### Lower-traffic frontend output safety - 8 November 2026

Patient medical-history timeline color tokens are restricted to the server-defined palette, icon classes use the safe class helper, and formatted dates are escaped. Patient notification icons/IDs are constrained. Booking duration is normalized before display/calculation. Admin dashboard count/percentage output is normalized with `safeCount()`. The shared helpers now include `safeCssColor()` and `safeCount()`; expanded helper tests passed 50/50. See handoff Section 134.

### Public doctor directory - browser XSS and trust-data follow-up (8 November 2026)

`doctors.html` and `assets/js/pages/doctors.js` now have a mocked local Chrome/Puppeteer hostile-payload regression. The first run found API-backed `doctor.exp` rendered without numeric normalization; the fix constrains experience/rating output and appointment duration before rendering. Doctor names, department names and handler arguments remain safe text/serialized attributes in the card and detail modal.

The page consumes the existing public departments, doctors and availability APIs. `api/doctors/get.php` now adds `rating_count` while retaining the existing `rating` property for compatibility. The public directory uses the count to avoid describing a schema-default 4.5 as patient feedback when no ratings exist. Static trust metrics and unsupported certification copy have been replaced with factual directory capabilities.

Browser result: **21/21** passed, including a 390px mobile layout check; all API responses were mocked and no database request was made. Continue browser coverage on the admin, patient, doctor workspace and EMR pages. See handoff Section 135.


### Booking wizard and admin profile rating behavior - 8 November 2026

The reusable `assets/js/components/booking-wizard.js` consumes `api/doctors/get.php`. It now renders patient ratings only when `rating_count` is positive, otherwise “No ratings yet” with no star icon. Its experience display now normalizes the API/schema `exp` field. The actual card and preview render methods are included in the 26/26 mocked browser regression.

The admin profile flows in `assets/js/pages/admin.js` and `assets/js/pages/admin-doctor-profile.js` consume `api/doctors/profile.php` and compute their average from the review records in that response rather than displaying the doctor-table default. Patient EMR and admin department modules were checked and do not display the legacy public-feed rating. See handoff Section 136.
### Doctor dashboard reviews/notifications and admin modal review renderer - 8 November 2026

assets/js/pages/doctor-dashboard.js now derives the doctor review average from valid 1–5 integer patient ratings; invalid rows do not enter the breakdown. Empty/invalid-only data displays “No ratings yet”. The summary star strip follows the computed average, and each card emits at most five stars.

Doctor notifications now validate IDs before rendering action attributes and omit mark-read controls for invalid IDs. API notification collections/types are shape-checked and invalid timestamps use a neutral placeholder. Review cards and notification title/message content remain escaped.

Admin review-table paths in assets/js/pages/admin.js and assets/js/pages/admin-doctor-profile.js cap star rendering and show “Not rated” for invalid rows. The dashboard modal's initial profile average and lazy Reviews tab have been covered with hostile mocked payloads in the expanded staff browser runner.

Results: doctor ratings 2/2 scenarios; doctor notifications hostile payload passed; dedicated admin profile 2/2 rating scenarios; staff browser suite 4/4 including dashboard modal/lazy Reviews; patient browser suite 7/7 plus fallback. Five modified JS files passed syntax checks. See handoff Section 137.


### Clinical EMR and admin patient/schedule browser coverage - 8 November 2026

Section 138 exercises the rendering modules owned by:
- `assets/js/pages/doctor-workspace.js`: consumes `GET api/doctors/workspace-data.php`, plus its visit-draft read path. Hostile patient/clinical/timeline values are emitted as escaped text; age and aggregate medication counts use safe numeric normalization; malformed workflow status falls back to Waiting; malformed prescription ID displays `RX—`.
- `assets/js/pages/patient-emr.js`: consumes `GET api/medical/get-emr-data.php` and builds all patient EMR tabs. Counts/average rating are bounded; appointment, rating and notification IDs are validated before action controls are rendered; star values outside 1–5 show “Not rated”; notification type/icon selection is restricted to a fixed local map; timeline/title/clinical strings are escaped at the final HTML sink.

Section 139 additionally tests `assets/js/pages/admin.js` patient management and `assets/js/pages/admin-doctor-profile.js` Schedule & Availability. Patient identity/contact fields and schedule audit actor/description content remain escaped; malformed patient IDs cannot become a valid patient target; the schedule summary/editor/history render expected read-only content. Browser responses were mocked and mutation requests blocked. No API implementation changes were made. Runners: `test_hb_clinical_emr_browser_xss.js` and `test_hb_admin_patients_schedule_xss.js` in external migration-backups QA directory.


### Shared Patient Retry actions and public Doctor Directory controls - 9 November 2026

The shared assets/js/core/main.js layer now owns the once-bound data-page-action="retry" click delegate used by the six Patient subpages that render read-failure states: Downloads, History, Insights, Notifications, Prescriptions and Profile. These retry controls are generated by their page-specific controllers; reload behavior remains a full current-page reload.

assets/js/pages/doctors.js now owns one delegated click/keyboard action layer for generated Doctor Directory cards and the dynamic doctor detail modal. Action IDs must map to the current in-memory allDoctors collection. The modal continues to own presentation, while existing role and booking eligibility rules remain in bookFromDoctorModal.

### Admin generated schedule, support and audit actions - 9 November 2026

assets/js/pages/admin.js now also dispatches its remaining generated controls through dispatchAdminGeneratedAction() after the Admin role gate. Schedule actions resolve through allDoctorSchedules; support replies through allContactMessages; audit detail actions through the currently rendered audit row. The underlying schedule, reply-modal and audit-detail functions remain the behavior owners. No API contract was changed.



### Shared layout fragment trust boundary - 9 November 2026

`assets/js/components/layout.js` now uses `readTrustedLayoutFragment(response, requestedPath)` before either shared-fragment HTML sink. The helper requires a successful, non-redirected `text/html` response whose final origin/path/query exactly matches the expected local navbar/sidebar resource. The body is not consumed on rejection; the existing static fallback is shown. Navbar user names remain escaped and sidebar identity fields use `textContent`.


### Admin audit-detail renderer bounds - 9 November 2026

`assets/js/pages/admin.js` audit detail rendering is bounded: five nesting levels, 25 rendered items per array or object, 160-character displayed keys and 4,000-character displayed strings. Omitted values are indicated in the UI. Audit action/entity/date/name/IP/user-agent metadata is type-checked before formatting; invalid dates use a safe fallback. The shared audit page regression runner covers these limits and malformed type fixtures.


### Patient EMR slot-period tabs - 9 November 2026

`assets/js/pages/patient-emr.js` slot tabs are delegated via `data-emr-slot-period`. `emrSwitchSlotTab()` now uses that data property to synchronize the active class, `aria-selected`, tabindex and matching slot panel. The clinical EMR browser test verifies initial Morning selection and delegated Afternoon switching. Patient EMR script query is `v=4`.


### Admin Doctor Profile DOM option construction - 9 November 2026

`assets/js/pages/admin-doctor-profile.js` uses `populateDoctorProfileDepartmentSelect()` to fill Edit/Reassign controls with DOM `Option` nodes; active departments require object shape, string name and positive numeric ID. Booking department/doctor selects use the same DOM text approach and reject malformed/non-positive records. The booking modal title uses a fixed icon plus a text node instead of parsing the doctor name as HTML. Script cache query is `v=4`.


### Doctor Workspace visit references, prescription/timeline data - 9 November 2026

`assets/js/pages/doctor-workspace.js` now requires a positive validated current appointment ID before rendering an active visit form; invalid IDs show a static fail-closed state. The hidden appointment field uses the validated value. The no-active-visit route correctly resolves to the dashboard appointment anchor. Prescription and timeline records/nested prescription items are object-filtered; timeline medical-history labels use own-property lookup to prevent prototype-key inheritance. Doctor Workspace script query is `v=6`.


### Doctor Dashboard identity, action IDs and rating rendering - 9 November 2026

`assets/js/pages/doctor-dashboard.js` builds welcome/date/average-rating star presentation through DOM APIs instead of HTML strings. Header identity fields and rating records are type-normalized. Delegated appointment action IDs are checked with `safePositiveId()` before API dispatch. Script cache query is `v=4`.


### Admin Dashboard analytics normalizer - 9 November 2026

`assets/js/pages/admin-dashboard.js` runs API payloads through `normalizeDashboardPayload()` before render. Root records/list rows are shape-filtered; text, counts, ratings, completion percentages, status and chart datasets are normalized before display. `dashboardDecimal()` accepts only numeric scalars/canonical decimal strings and enforces the rating/percentage range. Admin Dashboard script query is `v=4`. Chart.js 4.4.1 loads from a fixed jsDelivr URL; SRI remains a dependency-review item.


### Booking Wizard doctor, draft and slot trust boundaries - 9 November 2026

`assets/js/components/booking-wizard.js` normalizes public doctor records and allows only positive IDs, bounded scalar fields, strict ratings/counts/duration and explicit availability. Department/doctor selection and submit require current valid options; unavailable doctors are rejected. Date/time must be real YYYY-MM-DD and HH:MM values. Draft restore revalidates against live department/doctor options and restores time only when the fresh slot response still contains it. Slot records are shape/time validated, labels/messages are bounded, and stale responses are ignored when selected doctor/date changes. Valid reschedule uses a normalized positive appointment reference and restores the normal booking UI on close. Concurrent initialization reuses `BookingWizard.initPromise`. Patient Appointments script uses `booking-wizard.js?v=6`.


### Patient Dashboard next-appointment title and care-team rating - 9 November 2026

`assets/js/pages/patient/dashboard.js` now builds the next-appointment heading with a fixed DOM icon and a text node. Care-team rating display accepts only numeric scalar values or canonical decimal strings within 0–5; structured values do not coerce into ratings. Existing helpers continue to escape content, constrain icons/colors/classes, validate appointment IDs and restrict alert/download URLs. Script cache query is `v=5`.


### Shared Prescriptions module render/action boundaries - 9 November 2026

`assets/js/modules/prescriptions.js` uses `safePositiveId()` for appointment/prescription references, filters detail medication arrays to record objects, escapes prescription and medication detail values, and inserts issue-modal context with textContent. List output escapes doctor/patient/date/department/status, safe-counts item totals and uses `safeClassToken()`. Doctor lifecycle buttons use fixed delegated `data-prescription-action` tokens only for doctor + Active + valid prescription ID; the global dispatcher and methods revalidate actions/IDs. Print opens the fixed same-origin print endpoint with noopener/noreferrer.


### Patient Notifications data normalization - 9 November 2026

`assets/js/pages/patient/notifications.js` caps the complete grouped list at 20 records. Group entries are filtered to records; notification IDs use `safePositiveId()`; title/message/time/type are string-only and bounded to 200/1,000/80/80 characters; read state is true only for boolean true, numeric 1 or string "1". Icon type uses a fixed mapper and safe class token; mark-read returns immediately for invalid IDs. Dynamic title has a dedicated `.notif-title-text` span. Page script cache query is `v=4`.


### Medical Records capability and tab rendering - 9 November 2026

`assets/js/modules/medical-records.js` accepts `can_edit` only as strict boolean true and only for local doctor/admin roles. Header action, Edit Modal, Visit Note modal and save methods independently check the capability/role. Six medical-record tabs each emit one `data-mr-tab` attribute. Existing form identity context uses textContent and safe positive patient/appointment IDs. Admin and Patient EMR script queries now load `medical-records.js?v=4`.


### Patient Appointments records, action references and ICS export - 9 November 2026

`assets/js/pages/patient/appointments.js` normalizes appointment objects/fields before rendering; IDs use `safePositiveId()`; status/category/reschedule state use known-value lists; counts/duration use safe counts; rating flags are strict booleans. Malformed-ID cards are non-actionable. Deep-link and direct action methods validate IDs and resolve against the current dataset. Pagination values are safe-count normalized and navigation is clamped to known page bounds. ICS export validates a real YYYY-MM-DD calendar date and HH:MM[:SS] time and neutralizes line breaks and delimiter characters in generated text; blob URL revocation is delayed. Script cache query is `v=6`.


### Patient Prescriptions list normalization - 9 November 2026

`assets/js/pages/patient/prescriptions.js` uses `normalizePrescription()` for both the direct API and dashboard fallback. Only object records/items are retained; list is capped at 20 prescriptions and 20 medication items each. Prescription IDs use `safePositiveId()`; doctor/department/specialty/date/notes and medication values are string-only with explicit limits; status is mapped to Active/Completed/Cancelled or Unknown. Invalid IDs are non-actionable. Page script query is `v=3`.


### Patient Appointments compatibility follow-up — 9 November 2026

The record normalizer in assets/js/pages/patient/appointments.js now carries the validated doctor_id and department_id needed by BookingWizard.openReschedule(). It also keeps the legitimate Reschedule Requested status and reschedule_requested category. Rating modal doctor/department labels use normalized bounded strings. The Appointments page loads appointments.js?v=7; the focused Patient Appointments/Downloads browser suite verifies the reschedule handoff and malformed-ID/rating/ICS boundaries.


### Shared NotificationService rendering and navigation boundaries — 9 November 2026

assets/js/modules/notifications.js uses fixed templates for the dropdown shell/clear/empty state. Notification item data is normalized to bounded strings, strict read state, positive-safe record/reference IDs and an own-property fixed icon map; titles/messages are escaped and relative timestamps are date-validated. Invalid IDs have no mark/delete controls, direct methods reject invalid IDs, and click routing uses a fixed type switch/page map plus constrained sidebar fallback token. Existing shared dropdown and Doctor notification browser suites pass hostile data, malformed fields/IDs, zero blocked writes, no page errors/XSS and mobile layout.


### NotificationService lifecycle and shared consumers — 9 November 2026

assets/js/modules/notifications.js has one active service lifecycle even when main.js and a role dashboard both call init(). Later init callers can update the unread callback without creating duplicate polling/fetches. initDropdown() binds once per active lifecycle using AbortController. destroy() clears polling, visibility listener, dropdown listeners/DOM and callback, and permits a fresh init. Shared module cache reference is v=5 in Admin, Doctor Dashboard, Doctor Workspace, Patient EMR and the main.js dynamic loader.


### Public Doctor Directory rendering and data boundary — 9 November 2026

assets/js/pages/doctors.js has four reviewed HTML assignments. Department chips, doctor cards and doctor detail modal escape dynamic text and use fixed icon/badge fragments. API doctor records are copied into an explicit allowlist; rating accepts only finite scalar numeric input within 0–5, while malformed ratings clear the displayed review count. Availability, doctor/department IDs, counts and experience are normalized. Profile/booking controls use delegated events and resolve the target against loaded doctors. The page script reference is doctors.js?v=4.


### Patient History timeline renderer boundary — 9 November 2026

assets/js/pages/patient/history.js now accepts only an array of record events, filters malformed entries, keeps the newest 200 API events, and normalizes date/title/description/icon/color before rendering. Metadata must be a non-array record; only the four displayed string properties (doctor, department, status, diagnosis) are copied with limits. Dynamic timeline text is escaped; colors/icons pass safeCssColor()/safeClassToken(). Page cache query is history.js?v=3. Full seven-page Patient browser suite passes with zero blocked writes and no XSS/page errors/mobile overflow.


### Patient Profile display normalization — 9 November 2026

assets/js/pages/patient/profile.js copies only fields displayed by the profile page into an explicit record model. Profile/overview/snapshot and nested objects must be non-array records. Text fields are bounded strings; dates are validated before formatting; counts use safeCount; age/vitals accept positive finite scalar numbers or strict numeric strings with maximums; BMI classification only receives normalized numeric input. Escaped output remains the final text boundary. Page reference is profile.js?v=2; complete seven-page Patient regression passes malformed/valid profile cases with zero blocked writes, no page errors/XSS and no mobile overflow.

### Patient Downloads route and rendering boundary — 9 November 2026

assets/js/pages/patient/downloads.js renders escaped bounded document text and safe icon tokens. Only record rows with validated string/date fields enter the view model; each category is capped at 50 newest rows for DOM performance. Download links derive their allowed API prefix from getBasePath() and permit only the two patient print route shapes: prescriptions/print.php with a positive ID, or appointments/print.php with a positive ID and optional exact summary type. Same-origin alone is not sufficient; path/query shape is also enforced. Approved links are canonicalized and use noopener/noreferrer. The print endpoints independently enforce patient ownership. Page loads downloads.js?v=3.


### Final DOM sink module review — Section 200 (9 November 2026)

The last four JavaScript modules now have their HTML assignments reviewed:
- `assets/js/modules/schedule.js` — three assignments. Schedule layout and availability markup use fixed application templates; time dropdown markup uses generated numeric values only. Hospital time parsing now validates `HH:MM` / `HH:MM:00`, defaults invalid or reversed ranges to 08:00–22:00 and caps generated choices at 49.
- `assets/js/pages/home.js` — two assignments. Department records are record/string filtered and bounded; visible text and the query data attribute are escaped; icon classes come from a fixed map.
- `assets/js/pages/services.js` — one constant label assignment.
- `assets/js/pages/settings.js` — one static Settings modal template with no API-derived interpolation.

Schedule module cache version is `v=4` in Admin Doctor Profile, Admin and Doctor Dashboard. The final module tests passed, including valid/malformed Schedule times, hostile Home data and the eight-page Patient suite with generated Settings modal coverage.

Current first-party assignment inventory is 261/261 across 27 modules: 256 `innerHTML =` plus five `innerHTML +=`. This supersedes the earlier 251/261 snapshot. DOM sink review is complete; inline styles, three loader callbacks, third-party resource/SRI and report-only CSP remain separate work.


### CSP resource and style-loading map - Section 201 (9 November 2026)

Shared script bootstrap:
- assets/js/core/main.js loads settings.js and modules/notifications.js only when not already present. It now uses a same-origin URL guard, Promise-based load/error handling and duplicate-initialization flags. There are no direct .onload property assignments.
- assets/js/pages/admin-dashboard.js loads pinned Chart.js 4.4.1 with the verified SHA-384 SRI hash and one-shot load/error event listeners.
- Font Awesome 6.5.1 is loaded from cdnjs across 22 page/print references; all 22 now include the verified SHA-384 integrity value and crossorigin.
- Leaflet 1.9.4 is loaded from unpkg for the Contact map; CSS and JS both retain SRI (2/2).

Third-party resource origins observed in source include Google Fonts (fonts.googleapis.com and fonts.gstatic.com), cdnjs.cloudflare.com (Font Awesome and font files), cdn.jsdelivr.net (Chart.js), unpkg.com (Leaflet and its assets), images.unsplash.com (public page backgrounds) and CARTO basemap tile hosts. OpenStreetMap/CARTO attribution URLs are ordinary links, not resource fetches; www.w3.org is the SVG namespace.

Inline-style ownership:
- Static HTML/PHP: original scan 461 style attributes across 138 files; after the .mt-4 pilot, 443 remain.
- Generated JS templates: 559 style attributes across 24 of 35 first-party JS files. The largest clusters include Patient EMR, Patient Insights/Profile, Doctor Workspace, prescriptions, Admin and Medical Records.
- Keep template-driven color, width, indentation and state values separate from static style migration; they need constrained token/value review before CSS classes or CSS custom properties are selected.
- The eight-page Patient report-only fixture recorded 3,057 style-src-attr events, dominated by repeated timeline/prescription/download/profile/notification templates. This is runtime activity, not a count of unique attributes.

The existing limited CSP baseline is in .htaccess and includes/response.php. No full resource policy or report-only header was added to application configuration.


### CSS utility ownership - Section 202 (9 November 2026)

Shared assets/css/style.css now defines the .mt-2, .mt-4, .mt-6, .mt-8, .mb-3, .mb-4, .mb-6, .mb-8 and .label-muted-sm utilities. Seventy exact markup style declarations have moved to these classes across Admin, Doctor, Patient, authentication and public pages. Current markup inventory is 391 remaining attributes across 138 files.

Generated templates under assets/js still contain 559 style attributes across 24 of 35 files. Dynamic width, color, indentation and state expressions are intentionally not included in static utility replacement; each owning module needs bounded-value/class mapping and functional regression evidence before inline style removal.


### Patient CSP class ownership and shared Settings cleanup - Section 203/204 (9 November 2026)

Patient page-specific styles now live in assets/css/pages/patient-portal.css, assets/css/modules/prescription.css and assets/css/pages/dashboard.css. History, Prescriptions, Downloads, Profile, Notifications, Insights and Dashboard renderers use semantic classes instead of inline styles for their migrated components. Color/status variants are fixed allowlisted classes.

Shared notification display state is being converted to hidden/classes in layout.js, core/main.js and modules/notifications.js. Settings page/modal/tab/password-strength styling is being converted to named CSS classes in pages/settings.html, assets/js/pages/settings.js and assets/css/pages/settings.css. These shared changes still need a final test run; see Section 203/204 in the handoff.


### Section 205 — Print CSS ownership and CSP coverage correction (9 November 2026)

Print template ownership is now externalized:
- `api/prescriptions/print.php` uses `print-meds-count`, `print-empty-message` and `print-meds-col--*` classes from `assets/css/modules/print-prescription.css`.
- `api/appointments/print.php` uses `appointment-notes` from `assets/css/print/appointment-print.css`.
- `api/medical/print-patient-emr.php` uses `emr-patient-id`, `emr-prescription-status` and `emr-prescription-meta` from `assets/css/print/medical-emr-print.css`.
All 13 prior inline-style declarations were removed and the three PHP print endpoints now report zero style-src-attr violations.

The disposable staff/public CSP router now explicitly serves static files with the Report-Only policy and the copied runners assert the header. Do not use the earlier false-negative staff/static-public zero counts. Corrected runtime coverage: Patient 8 pages/0; Doctor/Admin 4/241; homepage 29; Doctor Directory 22; six further public/auth pages 62; print three routes/0. All groups were style-src-attr only.

Source inventories remain separate: 306 comment-stripped style attrs in HTML/PHP (138 files); 612 quoted attrs in HTML/PHP/JS (282 markup and 330 generated markup across 27 files); 81 direct JS style-property assignments plus two cssText assignments. No first-party onload property callbacks remain. Full CSP stays out of app configuration.


### Section 206 — Public page CSS ownership

Homepage presentation is now owned by `assets/css/pages/home.css`: hero background, CTA sizing, urgent action icon, section spacing, hours/location headings, map link, testimonials, CTA panel and generated department empty state. The existing urgent-link/footer-logo rules were reused rather than duplicated.

Doctor Directory presentation is now owned by `assets/css/pages/doctors.css`: hero background, trust-bar spacing, main-section width/padding, How It Works spacing, modal rating spacing and the 12-item doctor-card entrance stagger. Generated page markup no longer emits inline style attributes.

Files `index.html`, `doctors.html`, `assets/js/pages/home.js` and `assets/js/pages/doctors.js` now contain zero inline-style attributes. The six-file backup is in `migration-backups/healthbridge/section206-public-style-extraction`. Both routes pass the corrected Report-Only and browser QA suites with zero violations.


### Section 207 — Doctor Dashboard style ownership

`assets/css/pages/doctor-dashboard.css` now owns presentation formerly embedded in `pages/doctor/doctor-dashboard.html` and `assets/js/pages/doctor-dashboard.js`. New classes cover notification trigger/badge, sidebar avatar, availability value, empty states, flush card/table wrappers, rating bars, section headers, profile spacing, settings form/checkbox/actions, dashboard note cells, inline actions, status/meta text and action links. The 48 old attributes are removed from the two source files.

`assets/js/modules/schedule.js` remains unchanged because it is shared with Admin Doctor Profile and needs a separate usage-aware migration. Corrected staff regression passes and Doctor Dashboard reports zero CSP events.


### Section 208 — Doctor Workspace CSS ownership

`assets/css/pages/doctor-workspace.css` now owns 44 extracted declarations formerly embedded in `pages/doctor/doctor-workspace.html` and `assets/js/pages/doctor-workspace.js`. All 78 inline-style attributes are removed from both sources. Classes cover modal widths, textarea minimum heights, muted/error/status text, responsive grids, modal summary panels, action groups, medical-history cards, visit controls, and prescription-row layout.

The three-file backup is under `migration-backups/healthbridge/section208-doctor-workspace-style-extraction`. Workspace regression and corrected staff Report-Only suite pass; Doctor Dashboard and Workspace each record zero CSP violations. Shared Schedule.js is not part of this migration.


### Sections 209–211 — Admin and Schedule CSS ownership

Admin Dashboard markup and generated fragments now use page-scoped `data-csp-style` selectors in `assets/css/pages/admin-dashboard.css`; source attributes in `admin.html` (83) and `admin.js` (46) are removed. The nested audit hierarchy keeps bounded 0/12/24/36px indentation without dynamic style strings.

Admin Doctor Profile styles now live in `assets/css/pages/admin-doctor-profile.css`; its four primary loading/error/success sections use `.is-hidden` visibility state and classList updates. The shared Schedule module owns its day-body expanded state, dirty-banner visibility and availability label state through `assets/css/modules/schedule.css`; `schedule.js` has zero inline style attributes and zero direct style-property setters.

All Admin and Doctor schedule interaction suites pass. The corrected four-page staff Report-Only suite is at zero violations.


## Sections 212–216 — Current CSP style ownership (9 November 2026)

| Area / module | Current style owner and state model | Remaining generated style attributes |
|---|---|---:|
| Public/auth pages | about.css, services.css, contact.css and auth.css; state classes in auth.js/reset-password.js | 0 static page attributes in these six templates |
| Patient Appointments | appointments.css; appointments.js and booking-wizard.js use state/variant classes | 0 static attributes; dynamic inline styles from these two JS files removed |
| Patient EMR page | patient-emr.css owns its 28 extracted static styles; prescription.css now linked for shared medication rows | Patient EMR JS template content remains in patient-emr.js |
| Admin Dashboard | admin-dashboard.css owns page presentation and loading/error states | 0 direct CSSOM assignments |
| Prescription rows | prescription.css owns the shared rx-medication-item style | prescriptions.js still emits 48 generated style attributes elsewhere |
| Doctor Dashboard | doctor-dashboard.css owns badge/rating presentation; rating widths use rating-bar-fill--0..100 classes | 0 direct CSSOM assignments |
| Doctor Workspace | doctor-workspace.css owns review errors and prescription-row spacing; review modal HTML has checklist and alert targets | 0 direct CSSOM assignments |
| Medical Records | Existing medical-records.css modules own static presentation | medical-records.js still emits 26 generated style attributes |
| Admin Departments | admin-departments.css remains the destination for remaining generated presentation | admin-departments.js still emits 2 generated style attributes |

The shared schedule.js module remains class-based after Section 211. Current JavaScript-generated style attributes are 183 across four modules: patient-emr.js (107), prescriptions.js (48), medical-records.js (26) and admin-departments.js (2). Current direct CSSOM assignments/csText/style.setProperty/setAttribute("style") inventory is zero.



## Section 217 — Admin Departments generated-style cleanup (9 November 2026)

Backup: migration-backups/healthbridge/section217-admin-departments-generated-style-extraction.

Removed both remaining generated style attributes from assets/js/modules/admin-departments.js. The empty-departments table cell now uses admin-departments-empty-cell, and the row action wrapper uses admin-department-actions. Their presentation belongs to the already-loaded assets/css/pages/admin-dashboard.css; no new stylesheet link or behavior change was required.

The targeted synthetic Admin browser regression passes both populated department rows and the empty state. It verifies the action wrapper computes to display:flex with a 4px gap, the empty state retains its padding/color presentation, and neither output contains a style attribute. Admin initial/skeleton/content/error/recovery checks also pass. CSP violations, page errors, failed local requests, HTTP errors and blocked writes: zero.

### Updated source inventory after Section 217
- Static markup: 138 HTML/PHP files; zero inline style attributes, inline event attributes, inline script blocks or inline style blocks.
- JavaScript-generated HTML: 181 style attributes remain: patient-emr.js 107, prescriptions.js 48 and medical-records.js 26. Admin Departments is now zero.
- CSSOM assignments: zero direct style-property assignments, zero cssText assignments, zero style.setProperty calls and zero setAttribute("style") calls.
- First-party onload property assignments: zero.
- innerHTML assignments: 256 as a separate inventory, not a fresh sink review.

Next generated-markup target: medical-records.js (26 style attributes), followed by prescriptions.js (48) and patient-emr.js (107). Full application CSP remains unenforced; continue corrected Report-Only and functional coverage.



## Section 218 — Medical Records generated-style extraction (9 November 2026)

Backup: migration-backups/healthbridge/section218-medical-records-generated-style-extraction.

Removed all 26 remaining generated inline-style attributes from assets/js/modules/medical-records.js and moved their presentation into the existing assets/css/modules/medical-records.css module stylesheet. Replaced inline styles with classes for loading/error messages, section headings, conditional full-width and spaced information cards, pre-wrapped medical notes, allergy/medication chip lists and variants, visit counts, edit/visit-note modal sizes, modal headers/actions, visit context blocks and large textareas.

Admin already loaded this shared stylesheet; its cache version was bumped to v3. Patient EMR also uses the MedicalRecords module but had not loaded the module stylesheet, so the same v3 stylesheet is now linked there. No duplicate page stylesheet was created.

The focused Admin browser regression renders Overview, Medical History, Allergies, Medications, Visit History and Emergency Contact using synthetic data. It verifies zero inline styles in rendered content, preserves Family History full-width behavior and note pre-wrapping, and checks both dynamic edit and visit-note modal sizing/actions. The Admin loading/skeleton/content/error/recovery checks also pass. CSP violations, page errors, failed local requests, HTTP errors and blocked writes: zero.

The Patient Appointments and Patient EMR Report-Only suite was rerun after the stylesheet link change. Both routes passed with the expected CSP response header, zero violations, zero page errors, failed local requests, HTTP errors or blocked writes, and no horizontal overflow at 390px. Patient EMR now loads the additional Medical Records module stylesheet. The corrected four-page staff Report-Only suite also passed with zero violations and no browser/network errors.

### Updated inventory after Section 218
- 138 HTML/PHP markup files: 0 real inline style attributes, 0 inline event attributes, 0 inline script blocks and 0 inline style blocks.
- 155 JavaScript-generated style attributes remain: prescriptions.js 48 and patient-emr.js 107.
- CSSOM: 0 direct style-property assignments, 0 cssText assignments, 0 style.setProperty calls and 0 setAttribute("style") calls.
- First-party onload property assignments: 0.
- innerHTML assignments: 256; this remains a separate inventory and is not a new DOM-sink review.

This supersedes the Section 217 total of 181 generated attributes: the 26 Medical Records attributes have now been removed. Next target: prescriptions.js (48 generated attributes), then patient-emr.js (107). Full application CSP enforcement remains deferred; continue the corrected Report-Only and functional regression coverage before considering enforcement.



## Section 219 — Shared Prescription module generated-style extraction (9 November 2026)

Backup: migration-backups/healthbridge/section219-prescriptions-generated-style-extraction.

Removed all 48 generated inline-style attributes from assets/js/modules/prescriptions.js and moved presentation into the already-shared assets/css/modules/prescription.css stylesheet. The class mapping covers issue/view/edit/cancel modal sizing, modal headers, appointment context, add-medication controls, textarea sizes, form action rows, dynamic medication-row headers/titles/remove buttons, medication field grids/spacing, prescription issue metadata, medication counts, signature reference, cancellation description, empty table cells and list item counts.

A specificity check found the shared .form-group textarea rule overrode the first class-based textarea height. The module stylesheet now uses .form-group textarea.rx-textarea--short and --medium selectors to preserve the original 80px and 100px heights.

### Verification
- node --check assets/js/modules/prescriptions.js: pass.
- Existing Admin prescription-list hostile-content regression: pass. Malformed IDs remain non-actionable; hostile labels/details remain escaped; no XSS marker or executable image/SVG nodes; state-changing requests 0; page errors 0.
- New synthetic Admin module browser test: pass for empty/list-count states, issue modal, dynamic add/remove medication rows, prescription detail modal, edit modal and cancel modal. All generated Prescription DOM checked with zero style attributes; CSS widths, spacing, textareas and flex action rows verified.
- CSP Report-Only header assertion: pass; violations 0; page errors 0; failed local requests 0; HTTP errors 0; blocked writes 0.
- Corrected 4-page Doctor/Admin staff suite: pass with zero violations and no browser/network errors.
- Corrected Patient Appointments and Patient EMR suite: both pass with zero violations, no errors/failed requests/blocked writes and no horizontal overflow at 390px.

### Updated inventory after Section 219
- 138 HTML/PHP markup files: zero real inline style attributes, inline event attributes, inline script blocks or inline style blocks.
- 107 JavaScript-generated style attributes remain, all in assets/js/pages/patient-emr.js.
- CSSOM: zero direct style-property assignments, zero cssText assignments, zero style.setProperty calls and zero setAttribute("style") calls.
- First-party onload property assignments: zero.
- innerHTML assignments: 256; a separate source inventory, not a fresh DOM-sink review.

This supersedes the Section 218 total of 155: all 48 Prescription module attributes have been removed. Next target: the remaining 107 generated styles in patient-emr.js. Continue with a backup, bounded class mapping and Patient EMR synthetic-data regression; this module renders extensive patient history, appointments, audit and timeline content, so preserve conditional layouts and hostile-value escaping. Full application CSP enforcement remains deferred.



## Section 220 — Patient EMR generated-style extraction (9 November 2026)

Backup: migration-backups/healthbridge/section220-patient-emr-generated-style-extraction.

Removed all 107 remaining generated style attributes from assets/js/pages/patient-emr.js and moved their presentation into the existing assets/css/pages/patient-emr.css stylesheet. The extraction covers the loading skeleton, compact status badge, stat icon tones, overview empty states and doctor chips, section headings/spacing, email wrapping, appointment action tones and table empty state, last clinical update, administrative versus clinical record cards, readonly presentation, prescription/visit-note/timeline sections, rating moderation controls, notification actions, booking-slot placeholders, all three timeline event markers, audit entries and role badges, audit history empty state and audit-table scrolling.

Timeline markers now use a closed set of explicit classes for appointment, visit-note and prescription events. The existing .emr-timeline::before pseudo-element owns the vertical rail, so the redundant inline-styled rail child was removed instead of duplicated.

### Verification
- node --check assets/js/pages/patient-emr.js: pass.
- Existing clinical EMR hostile-content regression: pass. Doctor Workspace and Patient EMR hostile payloads remain escaped; malformed IDs are rejected or have actions suppressed; timeline, ratings, appointments, notifications and audit handling pass; state-changing requests 0.
- Enhanced Report-Only browser regression: pass. The actual Content-Security-Policy-Report-Only response header is asserted on Doctor Workspace and Patient EMR; the Patient EMR test renders all nine tabs and the booking-slot flow using synthetic data. Generated content across all tab roots and booking slots has zero style attributes. All three timeline marker type classes and computed presentation are present. CSP violations 0, page errors 0, blocked writes 0.
- Corrected Patient Appointments and Patient EMR route suite: pass at 390px with zero Report-Only violations, page errors, local failures, HTTP errors or blocked writes.
- Corrected four-page Doctor/Admin suite: pass with zero Report-Only violations, page/console errors, failed local requests, HTTP errors or blocked writes.
- Reconciled markup-only scanner: 138 files, zero inline style attributes, zero inline script blocks and zero inline style blocks.
- Reconciled broad source scanner: 197 source files, zero inline style attributes, zero inline script/style blocks. The separate JS CSSOM inventory reports zero direct style-property assignments, zero cssText assignments, zero style.setProperty calls and zero setAttribute("style") calls.

### Final reconciled CSP source inventory after Section 220
- 138 HTML/PHP files: zero real static inline style attributes, zero inline event attributes, zero inline script blocks and zero inline style blocks.
- 35 JavaScript files scanned: zero JavaScript-generated HTML style attributes.
- CSSOM: zero direct style-property assignments, zero cssText assignments, zero setProperty calls and zero setAttribute("style") calls.
- First-party onload property assignments: zero.
- 256 innerHTML assignments remain as a separate source inventory; this does not reopen or replace the previously recorded 261/261 DOM-sink review.

The source-level inline-style cleanup is now at zero. This does not by itself authorize production CSP enforcement: route/content-state coverage is still bounded, external script/style origins and maps must be reviewed against real page dependencies, and the enforcing policy needs isolated QA validation before any deployment consideration. Expanded policy remains confined to disposable QA routers. No enforcing header was added to the application or production.

The corrected Report-Only route coverage remains 25 unique routes/pages with zero recorded violations: Patient 10, Doctor/Admin 4, homepage/Doctor Directory 2, other public/auth 6, and print 3. The overall engineering roadmap remains at its last documented estimate of 46%; this CSP work does not recalculate unrelated maintenance tasks.

Next: audit the current QA CSP directives and origin allowlist, compare them with the full route/dependency inventory, then create a disposable enforcing-QA router for controlled regression only. Keep the live application and production policy unchanged.



## Section 221 — Disposable enforcing-CSP validation and print-header correction (9 November 2026)

All work for this section is isolated under migration-backups/healthbridge/section221-csp-enforcement-qa. No application source header, live deployment, production environment or production database was changed.

### Test policy

The disposable routers use this enforcing policy:

default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self'; script-src 'self' https://cdn.jsdelivr.net https://unpkg.com; script-src-attr 'none'; style-src 'self' https://fonts.googleapis.com https://cdnjs.cloudflare.com https://unpkg.com; style-src-attr 'none'; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com https://unpkg.com data:; img-src 'self' data: blob: https://images.unsplash.com https://*.basemaps.cartocdn.com https://unpkg.com; connect-src 'self'

Every final enforcement runner now asserts the actual Content-Security-Policy response header contains style-src-attr 'none', and captures browser securitypolicyviolation events. All API-backed QA interactions use healthbridge_repair_test and all state-changing requests are blocked or stubbed.

### Enforcing route coverage

All 25 previously targeted unique route/page surfaces have now passed under the disposable enforcing policy with the response header asserted:

- Doctor/Admin: 4 routes — Doctor Dashboard, Doctor Workspace, Admin Dashboard and Admin Doctor Profile.
- Public/auth: 6 routes — About, Services, Contact, Login, Register and Password Reset.
- Patient: 10 routes — the 8-page Patient suite plus Appointments and Patient EMR.
- Public discovery: 2 routes — homepage/departments and Doctor Directory.
- Print: 3 routes — Prescription, Appointment and Patient EMR print.

The full synthetic Patient EMR test also passes under enforcement across all nine tabs, appointment-slot content, timeline marker variants and hostile/malformed values. The eight-page Patient suite passes its hostile-content, data normalization, downloads, modal delegation and password-toggle checks. Doctor/Admin suite passes all four routes. Public/auth and public discovery suites pass mobile, hostile-content and interaction assertions. Print tests pass external script/stylesheet expectations, auto-print hooks and mobile layout. The print authorization matrix passes 12 ownership/role cases with the expected 200/403 statuses and no state-changing requests.

Final enforcing test outcomes: zero recorded CSP policy events in every suite; zero page/console errors; zero failed same-origin requests; zero HTTP errors; zero blocked writes. Print role tests report zero state-changing requests. The two Patient route tests remain free of horizontal overflow at 390px.

### Important correction — print route response headers

The earlier Section 218–220 notes described 25 routes as Report-Only tested. That count was overstated. The 3 PHP print endpoints include includes/auth.php, which calls sendCorsHeaders() in includes/response.php. That helper sets its own narrower application CSP:

object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'

The helper therefore replaced the disposable router's Report-Only policy on those print responses. Their prior zero-event results did not prove the full Report-Only policy was applied. Treat the prior full-policy Report-Only coverage as 22 routes, not 25; the 3 print routes had the application's narrower CSP.

For Section 221 only, both disposable enforcing routers now buffer the three print endpoint responses and reapply the full QA enforcing policy in a shutdown handler, including early 403 authorization responses. The print header assertion now passes for Prescription, Appointment and EMR print success/denial cases. This workaround exists only in the copied QA routers. No change was made to includes/response.php, includes/auth.php or the print endpoint files.

This reveals a future application-level policy integration issue: the shared sendCorsHeaders() helper and standalone printable HTML responses need a single intentional CSP design before an enforcing header is considered for the application. Do not copy the QA shutdown workaround into production as-is.

### Current verified source inventory

The final reconciled source scan remains at zero:
- 138 HTML/PHP markup files: zero inline style attributes, inline event attributes, inline script blocks or inline style blocks.
- 35 JavaScript files: zero generated HTML style attributes.
- Zero direct CSSOM style assignments, cssText assignments, style.setProperty calls or setAttribute("style") calls.
- Zero first-party onload property assignments.
- 256 innerHTML assignments remain a separate inventory; the prior 261/261 DOM-sink review is unchanged.

### Limits and next work

The enforcing suite proves that the copied QA routers emit the tested policy and the tested routes render without recorded policy events. Some browser runners intentionally block external requests to keep synthetic QA deterministic; this means the allowlist still needs dedicated dependency-loading validation (Google Fonts, cdnjs, jsDelivr, unpkg, Unsplash and Carto map tiles) rather than assuming a zero-event run proves every third-party asset loaded.

Next work: audit actual external dependency loading for each relevant public/auth, contact/map and staff route under an allowlisted browser harness; reconcile the application shared CSP helper with print responses; then design a single application-level CSP and test it in an isolated copy before considering any live header change. The full enforcing policy remains limited to disposable QA routers. No commit, push or deployment was made. Overall engineering roadmap remains at the last documented 46% estimate; this CSP work does not recalculate unrelated maintenance tasks.



## Section 221B — External dependency loading under enforcing CSP (9 November 2026)

Additional enforcing-policy browser runs used allowlisted test copies under migration-backups/healthbridge/section221-csp-enforcement-qa. These copies permit only the QA CSP's declared external origins plus data/blob resources explicitly allowed for image rendering; they record actual HTTP responses, blocked origins and request failures.

### Seven external-dependency suites — all pass

- Doctor/Admin staff suite: Google Fonts and Font Awesome load; the Admin Dashboard also loads Chart.js 4.4.1 from jsDelivr.
- Patient Appointments/Patient EMR route suite: Google Fonts, Font Awesome, font files and data-URI SVG resources load.
- Full synthetic Patient EMR suite: all nine tabs and booking slots pass with external fonts/icons loaded; no style attrs, policy events, hostile-content execution or blocked writes.
- Patient eight-page suite: History, Downloads, Prescriptions, Notifications, Dashboard, Insights and Settings all pass; fonts/icons load. Insights' isolated data-validation test deliberately aborts the Chart.js request to preserve its chart-construction spy; the real Chart.js script is independently verified with HTTP 200 by the Admin suite.
- Public/auth suite: Google Fonts, Font Awesome, unpkg and all three Carto basemap hosts are requested successfully across About, Services, Contact, Login, Register and Password Reset.
- Public homepage/departments suite: Google Fonts, Font Awesome and Unsplash hero imagery load successfully.
- Doctor Directory suite: Google Fonts, Font Awesome and Unsplash imagery load successfully.

### External response summary

Across the seven suites, 147 approved external/data/blob responses returned HTTP 200:

| Host / resource | HTTP 200 responses |
|---|---:|
| cdnjs.cloudflare.com | 62 |
| fonts.googleapis.com | 30 |
| fonts.gstatic.com | 30 |
| data/blob image resources | 16 |
| unpkg.com | 2 |
| images.unsplash.com | 2 |
| a.basemaps.cartocdn.com | 1 |
| b.basemaps.cartocdn.com | 2 |
| c.basemaps.cartocdn.com | 1 |
| cdn.jsdelivr.net (Chart.js 4.4.1) | 1 |
| **Total** | **147** |

Across these external-validation runs: blocked/unapproved external origins 0, external request failures 0, and enforcing CSP policy events 0. The intentionally stubbed Patient Insights Chart.js request is a test-harness behavior, not a network failure; the real resource was verified separately on Admin.

This validates the current allowlist's known dependency families on the routes above. It does not prove every future or rarely used application route has no other external dependency. Keep the allowlist narrowly scoped and re-run dependency tests when pages/assets change.


## Section 222 — Security-header path update (9 November 2026)

The application has two server response paths: PHP routes pass through `includes/auth.php` / `includes/response.php`, while static HTML and assets are served by Apache using the root `.htaccess` fallback. Both paths now emit the same `HEALTHBRIDGE_CONTENT_SECURITY_POLICY` value. The three authenticated print endpoints are HTML responses produced by PHP but inherit the shared helper's policy; they no longer depend on the temporary Section 221 QA-router shutdown override.

`tools/qa/verify-csp-policy-sync.php` provides a read-only consistency check between the PHP constant and Apache fallback. It passes. All 24 static HTML files and 89 API PHP endpoints returned the exact policy in the active-source HTTP sweep. Browser verification covered four representative static routes and all 12 existing print role/ownership cases. Tests ran under a separate Apache listener and synthetic QA database; no state-changing request was issued. The policy integration is local only and not deployed.


## Section 223 — Authentication page behavior update (9 November 2026)

The reset-password UI's six strength-meter states are now independently confirmed in Chrome under the enforcing local CSP. A transition-aware assertion waits 400ms for the fill's 300ms CSS transition, then verifies matching state classes, labels, widths and colors. Weak submission is blocked; a valid-strength submission is intercepted and mocked. No real reset API request or database write occurs. No source-code modification was required.


## Section 224 — URL/navigation dependency map update (9 November 2026)

URL-bearing flows were traced across core auth/navigation, notifications, Services, Doctor Directory, Patient Document Center, print actions and admin/patient exports. Trust boundaries are handled by same-origin parsing (`safeSameOriginUrl`), exact route/parameter allowlists in downloads, positive numeric IDs for print routes, fixed role-aware navigation maps, encoded search/booking query parameters, SRI on the Chart.js CDN script and same-origin validation in the local dynamic script loader. Export `blob:` URLs are generated from in-memory Blob objects. A Chrome helper regression accepted 4 valid same-origin cases and rejected 9 unsafe cases under the active local CSP. This is a bounded map, not a complete runtime URL call graph.


## Section 225 — Patient Document Center URL boundary (9 November 2026)

The Patient Document Center renderer was browser-tested with synthetic API data. It enabled only the four expected prescription/appointment print URL combinations and disabled 13 malformed or hostile URL targets. Link `target`/`rel` isolation and hostile title/doctor text escaping passed. All API traffic was mocked before reaching the app; no DB activity. The page remained usable at 390px with no CSP events/errors/inline styles. No source edit required.


**Section 224 addendum:** Less common URL-bearing properties were checked too. No dynamic `srcdoc`, `srcset`, form action, poster or manifest assignments and no document-write calls were found. Services has the single `setAttribute("href")` call, and its target is generated by the fixed role dashboard map.


## Section 226 - Roadmap progress recalibration (9 November 2026)

The qualitative six-phase estimate has been recalibrated after Sections 220-225. Phase 2 (Security audit) moves from 55% to 58% to recognize the reconciled inline-style/CSSOM cleanup, consistent enforcing CSP integration and 24-page/89-endpoint header validation, dynamic URL trust-boundary review, and hostile Document Center regression. Phase 6 (Regression/release readiness) moves from 36% to 38% for the added enforcing-CSP browser checks, print authorization matrix, password-strength states and Patient Document Center browser suite. Other phase estimates remain unchanged because these focused results do not complete their separate exit criteria.

| Phase | Updated estimate |
|---|---:|
| 1. Understand and map | 60% |
| 2. Security audit | 58% |
| 3. Database/workflow reliability | 60% |
| 4. Architecture standardization | 40% |
| 5. Cleanup/refactoring | 25% |
| 6. Regression/release readiness | 38% |

Equal-weight mean: (60 + 58 + 60 + 40 + 25 + 38) / 6 = 46.83%, rounded to **47% overall**. This is a qualitative planning estimate, not a test-coverage ratio, completion guarantee, or release-readiness claim. HB-SEC-010 and all six phase exit gates remain open. Continue prioritizing the remaining low-traffic dynamic sinks, clinical authorization journeys, data/restore reliability, architecture ownership, refactoring and full release verification.

