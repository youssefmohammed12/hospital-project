> **Superseded — 24 November 2026.** This inventory was audited against the V1-era codebase on 5 November 2026. Endpoints have since been added, changed and removed (for example `api/auth/check-availability.php` no longer exists). Read the current endpoints from `api/` directly. Kept as a historical record of the V1 → V2 build. For the current system, see `README.md` and `docs/ARCHITECTURE.md`.

# HealthBridge - API Endpoint Inventory

**Audit date:** 5 November 2026  
**Project:** C:\xampp85\htdocs\optimized  
**Working branch at latest check:** repair/healthbridge-staged-hardening  
**HEAD at latest check:** d4dd731b7578d9309d8eca50ec57bb319459dade  
**Scope:** Static source inventory of the current api/ tree and literal endpoint references in frontend JavaScript/HTML. This is not a completed penetration test or proof of runtime behavior.

## 1. Inventory snapshot

- **API PHP files:** 89. The previous 93-route discovery count is stale; four API routes have since been removed from the current tree.
- **Frontend source files scanned:** 54 JavaScript/HTML files under assets/js/, pages/, and root-level HTML after removing the unused 2,006-line legacy patient portal controller.
- **Exact API path literal occurrences:** 175.
- **Unique frontend-file to API-route caller mappings:** 133.
- **Endpoints with at least one exact frontend path literal:** 82.
- **Endpoints with no exact frontend path literal:** 7.
- **Routes with explicit REQUEST_METHOD logic detected:** 89; without an explicit check detected: 0.
- **Route-level auth evidence counts:** requireRole=44, requireAuth-only=36, manual session/helper=1, no explicit route auth=8.

### Interpretation limits

- includes/auth.php initializes the session and common security response behavior; including it does not by itself authenticate the caller. This inventory distinguishes route-level requireAuth()/requireRole() calls from manual session checks and routes with no visible route-level auth call.
- A requireAuth() row does not mean the route is fully authorized. Resource ownership, doctor/patient relationships, role allowlists, and workflow-state rules must still be inspected in the route and service.
- Method evidence records HTTP-method tokens used in route code. It does not automatically prove that every branch rejects unsupported methods.
- Caller matching is exact literal path matching. Dynamically assembled URLs, injected fragments, bookmarks, external integrations, and direct API consumers may not be found by this scan.
- Table references are extracted from SQL-shaped source fragments and filtered against the 26 tables in database/healthbridge.sql (including notification_outbox); dynamic table names and service-delegated queries may be missing.
- Service references list service files explicitly included or instantiated in each route. Inherited/delegated dependencies may not appear in the route row.
- Do not delete or rewrite a route based only on this document. Validate all callers, public entry points, and runtime behavior first.

## 2. Route inventory

| Endpoint | Method evidence | Route-level auth evidence | Explicit service dependencies | Direct SQL table references | Frontend literal callers |
|---|---|---|---|---|---|
| api/admin/dashboard-analytics.php | GET-only | Central requireAuth + explicit admin allowlist | DashboardAnalyticsService | Delegated / not extracted | assets/js/pages/admin-dashboard.js |
| api/appointments/accept-suggestion.php | Explicit method logic mentions POST (branch semantics need review) | Central requireAuth; role/resource scope still needs review | RescheduleService | Delegated / not extracted | assets/js/pages/patient/appointments.js |
| api/appointments/approve-reschedule.php | Explicit method logic mentions POST (branch semantics need review) | Central requireAuth; role/resource scope still needs review | RescheduleService, ScheduleService | Delegated / not extracted | assets/js/pages/doctor-dashboard.js |
| api/appointments/approve.php | Explicit method logic mentions POST (branch semantics need review) | Central role guard: admin | AuditService, NotificationService, VisitWorkflowService | appointments, users | assets/js/pages/admin.js<br>assets/js/pages/patient-emr.js |
| api/appointments/book.php | Explicit method logic mentions POST (branch semantics need review) | Central requireAuth; role/resource scope still needs review | AuditService, NotificationService, ScheduleService | appointments, departments, users | assets/js/components/booking-wizard.js<br>assets/js/pages/admin-doctor-profile.js<br>assets/js/pages/admin.js<br>assets/js/pages/patient-emr.js |
| api/appointments/cancel-reschedule-request.php | Explicit method logic mentions POST (branch semantics need review) | Central requireAuth; role/resource scope still needs review | RescheduleService | Delegated / not extracted | assets/js/pages/patient/appointments.js |
| api/appointments/decline-suggestion.php | Explicit method logic mentions POST (branch semantics need review) | Central requireAuth; role/resource scope still needs review | RescheduleService | Delegated / not extracted | assets/js/pages/patient/appointments.js |
| api/appointments/decline.php | Explicit method logic mentions POST (branch semantics need review) | Central role guard: admin | AuditService, NotificationService | appointments, users | assets/js/pages/admin.js<br>assets/js/pages/patient-emr.js |
| api/appointments/get-available-slots.php | POST-only; returns 405 with Allow: POST | Central requireAuth; role/resource scope still needs review | ScheduleService | Delegated / not extracted | assets/js/components/booking-wizard.js<br>assets/js/pages/patient-emr.js |
| api/appointments/get-doctor.php | GET-only | Central requireAuth; doctors may read their own data, admins must provide doctor_id | VisitWorkflowService | appointments, prescriptions, users, visit_notes | assets/js/pages/admin.js<br>assets/js/pages/doctor-dashboard.js |
| api/appointments/get-pending-reschedules.php | GET-only | Central requireAuth plus explicit doctor/admin allowlist; doctors receive own requests, admins receive all | RescheduleService | appointments, users (delegated) | No exact frontend literal found; retained compatibility route |
| api/appointments/get.php | GET-only | Central requireAuth + explicit admin/patient allowlist; each role receives only its permitted rows | Direct query/helper; no service include detected | appointments | assets/js/pages/admin.js |
| api/appointments/print.php | GET-only; private/no-store response | Central requireAuth; admin unrestricted, doctor and patient scoped to their own appointment | Direct query/helper; no service include detected | appointments, prescription_items, prescriptions, users, visit_notes | assets/js/pages/patient-emr.js |
| api/appointments/reject-reschedule.php | Explicit method logic mentions POST (branch semantics need review) | Central requireAuth; role/resource scope still needs review | RescheduleService, ScheduleService | Delegated / not extracted | assets/js/pages/doctor-dashboard.js |
| api/appointments/request-reschedule.php | Explicit method logic mentions POST (branch semantics need review) | Central requireAuth; role/resource scope still needs review | RescheduleService, ScheduleService | Delegated / not extracted | assets/js/components/booking-wizard.js |
| api/appointments/suggest-reschedule.php | Explicit method logic mentions POST (branch semantics need review) | Central requireAuth; role/resource scope still needs review | RescheduleService | Delegated / not extracted | assets/js/pages/doctor-dashboard.js |
| api/appointments/update.php | Explicit method logic mentions POST (branch semantics need review) | Central role guard: doctor | AuditService, NotificationService, VisitWorkflowService | appointments, users | assets/js/pages/doctor-dashboard.js |
| api/audit/get.php | GET-only; patient_id and limit filters used by the EMR audit tab; authenticated browser read returned 200 | Central role guard: admin | AdminAuditService, AuditService | admin_audit, users | assets/js/pages/admin-doctor-profile.js<br>assets/js/pages/admin.js<br>assets/js/pages/patient-emr.js |
| api/auth/check-availability.php | POST-only; validates text and identifier formats only; returns available:null without account-existence query | Public compatibility route; no authentication | Shared validation helpers; no service | None | No exact frontend literal caller; retained for older/external clients pending compatibility decision |
| api/auth/current_user.php | GET-only; anonymous/invalid session returns 401; identity is checked for account status, role, session age and session_version | Central requireAuth | Direct users query in includes/auth.php | users | assets/js/core/main.js; indirectly called by services.js getSessionUser() |
| api/auth/forgot_password.php | POST-only; email text/format validation; generic response; loopback APP_ENV=development returns reset_url; deployed/non-loopback returns 503 because no mail transport | Public token request; no session required | Direct queries; no service or mailer | password_resets, users | Embedded Forgot Password panel in assets/js/core/auth.js |
| api/auth/locations.php | GET-only; text type; strict positive governorate_id; browser read returned 27 governorates | Public registration lookup | getGovernorates()/getCitiesByGovernorate() in includes/validation.php | egypt_governorates, egypt_cities | assets/js/core/auth.js |
| api/auth/login.php | POST-only; strict email/password; host-local HMAC limiter; unknown/inactive/wrong-password share generic 401; dummy bcrypt verification; successful login rotates session ID | Public credential authentication | AuthAbuseRateLimiter; direct users query | users (plus host temp HMAC files) | assets/js/core/auth.js |
| api/auth/logout.php | POST-only; clears PHP session and expires cookie; no business-table mutation | Idempotent session cleanup; no current auth required | destroyAuthSession() in includes/auth.php | None | assets/js/core/main.js |
| api/auth/register.php | POST-only; strict step/text fields; Step 1 no DB/uniqueness query; Step 2 atomic patient creation and generic duplicate/success response | Public registration with abuse limiter | AuthAbuseRateLimiter, AuditService, NotificationService | users, medical_records, user_preferences, admin_audit, notification_outbox; delivery may create notifications | assets/js/core/auth.js |
| api/auth/reset_password.php | POST-only; 64-hex token SHA-256 lookup; verify_only SELECT-only; final reset transaction updates password/session_version and consumes tokens | Public capability-token route; no session required | Direct transactional queries; validatePassword() | password_resets, users | assets/js/pages/reset-password.js |
| api/departments/activate.php | Explicit method logic mentions POST (branch semantics need review) | Central role guard: admin | AdminAuditService, AuditService, DepartmentService | Delegated / not extracted | assets/js/modules/admin-departments.js |
| api/departments/assign-doctor.php | Explicit method logic mentions POST (branch semantics need review) | Central role guard: admin | AdminAuditService, AuditService, DepartmentService | departments, doctors | assets/js/pages/admin-doctor-profile.js |
| api/departments/create.php | Explicit method logic mentions POST (branch semantics need review) | Central role guard: admin | AdminAuditService, AuditService, DepartmentService | Delegated / not extracted | assets/js/modules/admin-departments.js |
| api/departments/deactivate.php | Explicit method logic mentions POST (branch semantics need review) | Central role guard: admin | AdminAuditService, AuditService, DepartmentService | Delegated / not extracted | assets/js/modules/admin-departments.js |
| api/departments/delete.php | Explicit method logic mentions POST (branch semantics need review) | Central role guard: admin | AdminAuditService, AuditService, DepartmentService | Delegated / not extracted | assets/js/modules/admin-departments.js |
| api/departments/get.php | GET-only; public callers receive active departments; admin may request inactive rows after validated authentication; public browser read returned 200 | Conditional central requireAuth for an existing session | DepartmentService | departments (service-derived) | assets/js/modules/admin-departments.js<br>assets/js/modules/departments-loader.js<br>assets/js/pages/admin-doctor-profile.js<br>assets/js/pages/admin.js<br>assets/js/pages/doctors.js<br>assets/js/pages/home.js |
| api/departments/update.php | Explicit method logic mentions POST (branch semantics need review) | Central role guard: admin | AdminAuditService, AuditService, DepartmentService | Delegated / not extracted | assets/js/modules/admin-departments.js |
| api/doctors/add.php | Explicit method logic mentions POST (branch semantics need review) | Central role guard: admin | AdminAuditService, AuditService, DepartmentService, NotificationService | doctors, user_preferences, users | assets/js/pages/admin.js |
| api/doctors/delete.php | Explicit method logic mentions POST (branch semantics need review) | Central role guard: admin | AdminAuditService, AuditService, NotificationService | appointments, users, visit_workflow | assets/js/pages/admin-doctor-profile.js<br>assets/js/pages/admin.js |
| api/doctors/get-admin-ratings.php | GET-only; returns system ratings/reviews collection | Central role guard: admin; full identifying details are admin-only | Direct query/helper; no service include detected | appointments, ratings, users | No exact frontend literal found; retained pending external-contract review |
| api/doctors/get-availability.php | GET-only | Central role guard: doctor | ScheduleService | doctor_schedule_settings, doctors | assets/js/pages/doctor-dashboard.js |
| api/doctors/get-doctor-ratings.php | GET-only | Central requireAuth; doctor may read self, admin must provide valid doctor_id; other roles denied | Direct query/helper; no service include detected | appointments, ratings, users | assets/js/pages/admin.js<br>assets/js/pages/doctor-dashboard.js |
| api/doctors/get-public-availability.php | GET-only; optional doctor_id must be a positive integer; exceptions now return HTTP 500; migrated-fixture batch returned eight availability records | Public endpoint; single-doctor reads reject inactive accounts | ScheduleService | appointments, doctor_schedule_settings, doctor_schedule_weekly, doctors, hospital_settings, users | assets/js/pages/doctors.js |
| api/doctors/get-rating.php | GET-only; appointmentId must be positive integer | Central requireRole: patient; rating query is scoped to authenticated patient ID | Direct query/helper; no service include detected | ratings | assets/js/pages/patient/appointments.js |
| api/doctors/get.php | GET-only; returns active public directory fields; catches unexpected errors as HTTP 500 | Public endpoint; response filters inactive accounts/departments | Direct query/helper; no service include detected | departments, doctor_schedule_settings, doctors, ratings, users | assets/js/components/booking-wizard.js<br>assets/js/modules/admin-departments-loader.js<br>assets/js/pages/admin-doctor-profile.js<br>assets/js/pages/admin.js<br>assets/js/pages/doctors.js<br>assets/js/pages/patient-emr.js |
| api/doctors/get_all.php | GET-only | Central role guard: admin | Direct query/helper; no service include detected | doctors, users | assets/js/pages/admin.js |
| api/doctors/profile.php | GET-only; id must be a positive integer | Central role guard: admin | Direct query/helper; no service include detected | appointments, departments, doctors, ratings, users | assets/js/pages/admin-doctor-profile.js<br>assets/js/pages/admin.js |
| api/doctors/submit-rating.php | Explicit method logic mentions POST (branch semantics need review) | Central role guard: patient | NotificationService | appointments, doctors, ratings, users, visit_workflow | assets/js/pages/patient/appointments.js |
| api/doctors/toggle-availability.php | Explicit method logic mentions POST (branch semantics need review) | Central role guard: doctor | AuditService, ScheduleService | doctors, users | assets/js/pages/doctor-dashboard.js |
| api/doctors/toggle-rating-visibility.php | Explicit method logic mentions POST (branch semantics need review) | Central role guard: admin | Direct query/helper; no service include detected | ratings | assets/js/pages/patient-emr.js |
| api/doctors/toggle-status.php | Explicit method logic mentions POST (branch semantics need review) | Central role guard: admin | AdminAuditService, AuditService, NotificationService | appointments, users, visit_workflow | assets/js/pages/admin-doctor-profile.js<br>assets/js/pages/admin.js |
| api/doctors/update.php | POST-only; doctor ID and optional department ID parsed strictly; changed non-empty phone validated and checked for normalized duplicates | Central role guard: admin; updates doctor account/profile | AdminAuditService, AuditService, DepartmentService | departments, doctors, users | assets/js/pages/admin-doctor-profile.js<br>assets/js/pages/admin.js |
| api/doctors/workspace-data.php | GET-only; patient_id and any supplied appointment_id use strict parsePositiveInt validation; prescription items use shared bounded batch hydration and item_count is derived from hydrated rows | Central role guard: doctor; ClinicalAccessService gates the full patient workspace using a Confirmed appointment or documented/started encounter evidence | ClinicalAccessService, PrescriptionService | appointments, medical_history_updates, medical_records, prescription_items, prescriptions, users, visit_notes, visit_workflow | assets/js/pages/doctor-workspace.js |
| api/medical/add-history-update.php | POST-only; patient_id parsed by shared parsePositiveInt; field allowlist and text/number values validated | Central role guard: doctor; ClinicalAccessService locks qualifying encounter | ClinicalAccessService | appointments, medical_history_updates | assets/js/pages/doctor-workspace.js |
| api/medical/add-visit-note.php | POST-only; appointment_id parsed by shared parsePositiveInt; note fields text-only and max-length validated | Central requireAuth; doctor own-appointment scope/admin correction scope | AuditService, NotificationService | appointments, users, visit_notes | assets/js/modules/medical-records.js<br>assets/js/pages/doctor-workspace.js |
| api/medical/complete-visit.php | POST-only; appointment_id parsed by shared parsePositiveInt; clinical fields text-only and max-length validated | Central role guard: doctor; appointment ownership and confirmed-state checks are transactional | AuditService, NotificationService, VisitDraftService, VisitWorkflowService | appointments, prescriptions, users, visit_notes | assets/js/pages/doctor-workspace.js |
| api/medical/delete-visit-draft.php | POST-only; appointment_id parsed by shared parsePositiveInt; authenticated doctor ID is derived before account lock; appointment row locked transactionally before deletion | Central role guard: doctor; only own confirmed appointment | ClinicalAccessService, VisitDraftService | appointments, users, visit_drafts | assets/js/pages/doctor-workspace.js |
| api/medical/get-emr-data.php | GET-only; staff patient_id must be a strict positive integer; patient role is forced to own ID; prescription items use shared bounded batch hydration | Central requireAuth; doctor uses ClinicalAccessService; admin may read any patient; patient sees self only | ClinicalAccessService, PrescriptionService | appointments, medical_history_updates, medical_record_audit, medical_records, notifications, prescription_items, prescriptions, ratings, users, visit_notes | assets/js/pages/patient-emr.js |
| api/medical/get-record.php | GET-only; staff patient_id must be a strict positive integer; patient role is forced to own ID | Central requireAuth; doctor uses ClinicalAccessService; admin may read any patient; patient sees self only | ClinicalAccessService | appointments, medical_records, users, visit_notes | assets/js/modules/medical-records.js |
| api/medical/get-visit-draft.php | GET-only; appointment_id now uses strict parsePositiveInt and malformed values return 400 | Central role guard: doctor; only own confirmed, non-completed appointment | VisitDraftService | appointments, visit_workflow, visit_drafts | assets/js/pages/doctor-workspace.js |
| api/medical/print-patient-emr.php | GET-only; staff patient_id must be a strict positive integer; patient role is forced to own ID; private/no-store; doctor authorization runs before patient lookup to avoid patient-ID existence disclosure | Central requireAuth; doctor uses ClinicalAccessService before checking patient existence; admin may print any patient; patient may print self | ClinicalAccessService | appointments, medical_records, prescription_items, prescriptions, users, visit_notes | assets/js/pages/patient-emr.js |
| api/medical/save-visit-draft.php | POST-only; appointment_id parsed by shared parsePositiveInt; draft fields text-only/max length; appointment row locked in a transaction before draft upsert | Central role guard: doctor; only own non-completed confirmed appointment | VisitDraftService | appointments, visit_workflow | assets/js/pages/doctor-workspace.js |
| api/medical/transition-workflow.php | POST-only; appointment_id parsed by shared parsePositiveInt; status must be a string and allowed transition state | Central role guard: doctor; appointment ownership and confirmed-state row lock | VisitWorkflowService | appointments, users | assets/js/pages/doctor-workspace.js |
| api/medical/update-record.php | POST-only; patient_id parsed by shared parsePositiveInt; reason text-only/max 1000; text fields, numeric height/weight and valid date_of_birth validated | Central requireAuth; doctor uses ClinicalAccessService with appointment-first locking; admin may update any patient; patient denied | AuditService, NotificationService, ClinicalAccessService | appointments, medical_record_audit, medical_records, users | assets/js/modules/medical-records.js<br>assets/js/pages/doctor-workspace.js<br>assets/js/pages/patient-emr.js |
| api/notifications/get-patient.php | GET-only; returns latest 20 notifications and unread count | Central requireAuth; list and count are scoped to authenticated user ID; retained legacy patient-facing route | Direct query/helper; no service include detected | notifications | No exact frontend literal found; retained pending legacy/external usage review |
| api/notifications/notifications.php | GET/POST only; Allow: GET, POST; page and mark/delete IDs use strict integer parsing with page-offset overflow guard; admin patient_id strictly parsed and target must have role patient | Central requireAuth; admin may select a patient target; all other roles remain bound to their own authenticated user ID | NotificationService reads/counts/status operations scoped by user_id; notification creation is queued by NotificationService through the internal Phase 15 outbox | notifications, notification_outbox | assets/js/modules/notifications.js; assets/js/pages/patient-emr.js; assets/js/pages/patient/notifications.js |
| api/patient/appointments.php | GET-only; page/limit must be valid integers (page Ã¢â€°Â¥1; limit 1Ã¢â‚¬â€œ50); page offsets are checked against integer overflow; status is restricted to the current UI filter set; search must be text; malformed/unsafe filters return 400 | Central requireAuth + assertRole: patient (method check remains between authentication and role assertion) | PatientPortalService | Delegated / not extracted | assets/js/pages/patient/appointments.js |
| api/patient/cancel-appointment.php | POST-only; `id` parsed by shared parsePositiveInt; appointment row locked inside transaction; rejects Completed, In Progress and Ready to Complete workflows | Central role guard: patient; cancellation limited to own future Pending/Confirmed appointments; audit commits with cancellation | AuditService, NotificationService | appointments, doctors, visit_workflow | assets/js/pages/patient/appointments.js |
| api/patient/dashboard.php | POST-only; optional JSON `search` must be a string or returns 400; optional `sections` must be a non-empty ordered list of unique allowlisted section keys (maximum 12) or returns 400; omitting `sections` retains the complete legacy dashboard response | Central requireAuth + assertRole: patient (method check remains between authentication and role assertion) | PatientPortalService | Delegated / not extracted | assets/js/pages/patient/dashboard.js<br>assets/js/pages/patient/downloads.js<br>assets/js/pages/patient/history.js<br>assets/js/pages/patient/insights.js<br>assets/js/pages/patient/notifications.js<br>assets/js/pages/patient/prescriptions.js<br>assets/js/pages/patient/profile.js |
| api/patients/add.php | Explicit method logic mentions POST (branch semantics need review) | Central role guard: admin | AuditService | medical_records, user_preferences, users | assets/js/pages/admin.js |
| api/patients/delete.php | Explicit method logic mentions POST (branch semantics need review) | Central role guard: admin | AuditService | appointments, users, visit_workflow | assets/js/pages/admin.js<br>assets/js/pages/patient-emr.js |
| api/patients/get-audit.php | GET-only; patient_id and optional limit are strict positive integers (limit capped at 100) | Central requireAuth; patient sees self only; doctor uses ClinicalAccessService; admin may read any patient | ClinicalAccessService | patient_audit_log, users | No exact frontend literal found; legacy compatibility route retained |
| api/patients/get_all.php | Explicit method logic mentions GET (branch semantics need review) | Central role guard: admin | Direct query/helper; no service include detected | users | assets/js/pages/admin.js |
| api/patients/profile.php | GET-only; id must be a positive integer | Central role guard: admin | Direct query/helper; no service include detected | appointments, contact_messages, ratings, users | No exact frontend literal found; retained compatibility route |
| api/patients/toggle-status.php | Explicit method logic mentions POST (branch semantics need review) | Central role guard: admin | AuditService, NotificationService | users | assets/js/pages/admin.js<br>assets/js/pages/patient-emr.js |
| api/patients/update.php | POST-only; full profile edit or password-only reset; patient_id strict positive integer | Central requireRole: admin; target is checked as patient; password-only path locks row and increments session_version | AuditService, NotificationService | medical_records, users | assets/js/pages/patient-emr.js |
| api/prescriptions/cancel.php | Explicit method logic mentions POST (branch semantics need review) | Central role guard: doctor | NotificationService, PrescriptionService | Delegated / not extracted | assets/js/modules/prescriptions.js |
| api/prescriptions/complete.php | Explicit method logic mentions POST (branch semantics need review) | Central role guard: doctor | NotificationService, PrescriptionService | Delegated / not extracted | assets/js/modules/prescriptions.js |
| api/prescriptions/create.php | Explicit method logic mentions POST (branch semantics need review) | Central role guard: doctor | NotificationService, PrescriptionService | appointments, users | assets/js/modules/prescriptions.js<br>assets/js/pages/doctor-workspace.js |
| api/prescriptions/get.php | GET-only; optional single-record id is validated as a positive integer; status/search/date filters are type/format/range validated | Central requireAuth; patient list is forced to own patient_id; doctor list is forced to own doctor_id; single records are checked against patient_id/doctor_id; admin may list/read all | PrescriptionService | Delegated / not extracted | assets/js/modules/prescriptions.js<br>assets/js/pages/admin.js<br>assets/js/pages/doctor-workspace.js<br>assets/js/pages/patient/prescriptions.js |
| api/prescriptions/print.php | GET-only; positive prescription ID required; private/no-store response | Central requireAuth; patient may print own prescription; doctor may print prescriptions they created; admin may print all | PrescriptionService | Delegated / not extracted | assets/js/modules/prescriptions.js<br>assets/js/pages/patient-emr.js<br>assets/js/pages/patient/prescriptions.js |
| api/prescriptions/update.php | Explicit method logic mentions POST (branch semantics need review) | Central role guard: doctor | NotificationService, PrescriptionService | Delegated / not extracted | assets/js/modules/prescriptions.js |
| api/schedule/get.php | GET-only; admin doctor_id is strictly parsed when supplied; omitted ID or explicit "0" returns the admin overview | Central requireAuth; doctor is forced to own schedule; admin may read one doctor or all schedules; other roles denied | ScheduleService | Delegated / not extracted | assets/js/modules/schedule.js; assets/js/pages/admin-doctor-profile.js (GET ?doctor_id=); assets/js/pages/admin.js |
| api/schedule/reset.php | POST-only; doctor_id strictly parsed as positive integer; target must be a doctor account | Central role guard: admin | ScheduleService | users | assets/js/modules/schedule.js<br>assets/js/pages/admin.js |
| api/schedule/update.php | POST-only; admin doctor_id strictly parsed; weekly/settings objects and typed fields validated; max appointments/day constrained to 1Ã¢â‚¬â€œ100 | Central requireAuth; doctor is forced to own schedule; admin may update a selected doctor; other roles denied | AuditService, ScheduleService | users | assets/js/modules/schedule.js |
| api/settings/contact.php | POST-only; strict text validation; stores a message in contact_messages; the browser sends one same-origin request only (HB-SEC-008 locally resolved) | Intentionally public; if a valid session exists it is revalidated and user_id is attached; anonymous submissions store NULL user_id | Direct query/helper; no service include detected | contact_messages, users | assets/js/pages/contact.js |
| api/settings/get-contact-messages.php | GET-only; returns contact messages for the admin support inbox | Central role guard: admin | Direct query/helper; no service include detected | contact_messages | assets/js/pages/admin.js |
| api/settings/get-hospital.php | GET-only; public response is restricted to the public settings subset; admin receives full settings after validated authentication; home-page read returned 200 | Conditional central requireAuth for an existing session | HospitalSettingsService | hospital_settings | assets/js/modules/admin-hospital-settings.js<br>assets/js/modules/schedule.js<br>assets/js/pages/admin.js<br>assets/js/pages/home.js |
| api/settings/get-user-messages.php | GET-only; returns submitted contact messages and support replies | Central requireAuth; query is scoped to the authenticated user's ID | Direct query/helper; no service include detected | contact_messages | No exact frontend literal found; retained pending legacy/external usage review |
| api/settings/get.php | GET-only; returns profile, preferences and unread count; does not create preference rows during reads | Central requireAuth; profile/preferences/notifications are scoped to authenticated user; doctor data only included for doctor role | Direct query/helper; no service include detected | doctors, notifications, user_preferences, users | assets/js/pages/settings.js |
| api/settings/reply-contact-message.php | POST-only; message ID strictly parsed; reply must be text and contain 1Ã¢â‚¬â€œ5000 characters; notification delivery is best-effort after reply save | Central role guard: admin; lookup/update is by selected contact message ID | NotificationService | contact_messages | assets/js/pages/admin.js |
| api/settings/update-hospital.php | POST-only; supplied fields are type-checked; duration uses shared allowlist; hours use strict HH:MM and complete effective pair/conflicts are checked for either changed side; confirmation must be boolean | Central requireAuth + explicit admin-only gate | AdminAuditService, HospitalSettingsService | Delegated / not extracted | assets/js/modules/admin-hospital-settings.js<br>assets/js/pages/admin.js |
| api/settings/update.php | POST-only; profile fields are text-only and changed non-empty phone uses format/normalized-duplicate validation; preference toggles accept only boolean/0/1; doctor duration is 15Ã¢â‚¬â€œ120 in 5-minute steps; work times are strict HH:MM and checked against the stored counterpart; admin tab is allowlisted | Central requireAuth; every section acts on authenticated user ID; doctor/admin-only sections explicitly check role | AuditService, NotificationService | appointments, doctors, user_preferences, users, visit_workflow | assets/js/core/main.js<br>assets/js/pages/settings.js |

### 2.1 Routes with no explicit method guard detected

All **89 current API PHP files** now contain an explicit `REQUEST_METHOD` check or method branch in the reviewed source; no files remain without a method reference. The eight previously unguarded routes were read endpoints and now enforce GET. This completes the static no-method census, not runtime verification: older method branches and actual HTTP 405/Allow responses still require integration tests.

### 2.2 Routes with no centralized route guard or manual session check detected

This source scan found **eight** endpoints with neither `requireAuth()` / `requireRole()` nor a direct session/helper check. Several are expected to be available before login or intentionally public; confirm and document the public contract and ensure returned fields remain limited.

- `api/auth/check-availability.php` Ã¢â‚¬â€ registration availability check.
- `api/auth/forgot_password.php` Ã¢â‚¬â€ password recovery request.
- `api/auth/locations.php` Ã¢â‚¬â€ public registration location lookup.
- `api/auth/logout.php` Ã¢â‚¬â€ logout/session cleanup can be invoked without a current authenticated identity; verify method and session-destruction behavior.
- `api/auth/register.php` Ã¢â‚¬â€ public registration.
- `api/auth/reset_password.php` Ã¢â‚¬â€ token-based recovery path; verify token validation/consumption and public response policy.
- `api/doctors/get-public-availability.php` Ã¢â‚¬â€ public doctor availability read.
- `api/doctors/get.php` Ã¢â‚¬â€ public doctor directory read.

### 2.3 Manual session decisions without a central guard

The source scan now finds **one** route with manual session logic but no `requireAuth()` / `requireRole()` call:

- `api/auth/login.php` Ã¢â‚¬â€ expected manual session lifecycle for unauthenticated login.

The four settings/department routes that previously made privileged decisions from raw session fields have now been updated locally to use the central validator (or a role guard) and explicit GET contracts. PHP lint passed for all four; runtime session-revocation/role-change regression tests remain pending.

## 3. Routes with no exact frontend literal caller

These are investigation candidates, not confirmed dead code. A route may have a dynamically constructed caller, direct external consumer, bookmark, legacy integration, or documentation-only use. Verify before retirement.

- **api/appointments/get-pending-reschedules.php**
- **api/auth/check-availability.php**
- **api/doctors/get-admin-ratings.php**
- **api/notifications/get-patient.php**
- **api/patients/get-audit.php**
- **api/patients/profile.php**
- **api/settings/get-user-messages.php**

Initial interpretation:
- **api/auth/check-availability.php** - retained POST compatibility route; it now checks only format and returns a neutral response, and the current UI no longer calls it. Keep until external consumers are ruled out.
- **api/appointments/get-pending-reschedules.php** - documented reschedule read route; current doctor dashboard receives appointment/reschedule state through api/appointments/get-doctor.php, but confirm before considering consolidation.
- **api/doctors/get-admin-ratings.php** - admin-only ratings listing; no current frontend literal was found. Check whether admin ratings are loaded indirectly or the endpoint is abandoned.
- **api/notifications/get-patient.php** - older patient notification query; the current UI uses the unified api/notifications/notifications.php route. Likely overlap, but verify contract and external usage.
- **api/patients/get-audit.php** - patient audit query; compare with current EMR response and universal admin audit route before considering removal.
- **api/patients/profile.php** - admin patient-profile aggregation; no exact frontend caller was found. Check for a dynamic or legacy patient-profile entry point.
- **api/settings/get-user-messages.php** - current-user support message query; patient dashboard aggregation may cover this data, but no direct caller was found.

## 4. Initial security and contract follow-ups

These are source-review findings to carry into Phase 2. Severity is provisional until runtime and complete business-policy verification. The four session-related settings/department issues were hardened locally later in this pass; see the P2 section below and the handoff for verification limits.

### P1 - booking doctor/department integrity Ã¢â‚¬â€ locally repaired

- The original issue was confirmed: the route previously accepted active doctor and active department independently, and all four booking screens posted display names. This allowed a mismatched doctor/department pair or ambiguous name lookup.
- `api/appointments/book.php` now validates the canonical `doctor_id` (`users.id`), loads the linked doctor profile, verifies its authoritative `doctors.department_id` matches the active selected department, and rejects mismatch.
- For older profiles only when `department_id IS NULL`, specialty must match the selected department name exactly after trimming/case normalization. Current local data has no NULL-department doctor profiles, but the compatibility rule is retained.
- Legacy external name-only callers remain supported only when exactly one active doctor with that name matches the selected department. The API queries at most two matches and returns 409 on ambiguity; it never chooses an arbitrary name match.
- The route repeats doctor-profile and department checks under row locks inside the booking transaction, so a concurrent department reassignment/deactivation cannot invalidate the preflight check.
- All four current frontends now carry `doctors.user_id` through the option value and booking payload: `assets/js/components/booking-wizard.js`, `assets/js/pages/admin.js`, `assets/js/pages/admin-doctor-profile.js`, and `assets/js/pages/patient-emr.js`. The admin profile preselection also uses user ID, and EMR filtering now uses authoritative `department_name` with the legacy specialty fallback.
- Verification: source scan found exactly four booking callers and all have stable-ID payloads; PHP lint passed for the booking endpoint; Node syntax passed for all four changed JS files; read-only local DB relation/lock QA **9/9 passed**. No appointment data was inserted or modified.
- Runtime HTTP/browser booking journey remains pending; HB-DB-002 is marked locally fixed, not production/fully E2E closed.

### P1 - public registration abuse and account enumeration
- **api/auth/check-availability.php** previously queried account existence for email, phone, and National ID and returned field-specific availability. It now performs format validation only and returns the same neutral `available: null` response for all valid values; it no longer queries the users table. The current `assets/js/core/auth.js` form no longer sends per-field uniqueness probes.
- api/auth/register.php keeps Step 1 validation-only. Step 2 still enforces email, normalized phone, and National ID uniqueness, but duplicate and completed-registration outcomes now share the same success-shaped confirmation and the public response omits patient_number/user_id. Duplicate-key races are mapped to the same neutral outcome. This removes the direct status/body oracle, but residual timing inference and the absence of email/phone ownership verification remain open.
- Local syntax checks pass for both PHP endpoints and the JS controller. No account rows were created or modified for this change.
- **Remaining P1 risk:** account creation is still possible without proving email/phone ownership, and there is no dedicated in-app per-IP/device signup throttle or CAPTCHA. Generic conflict feedback reduces field-specific enumeration but does not eliminate all inference or automated signup. Deployment-level WAF/gateway rules remain unverified. A proper next phase needs dedicated rate-limit storage/gateway controls plus a real contact-verification workflow; do not reuse password-reset attempt rows for signup throttling.

### P1 - clinical access policy Ã¢â‚¬â€ locally repaired

- The initial issue was shared across **api/medical/get-emr-data.php**, **api/medical/get-record.php**, **api/medical/print-patient-emr.php**, **api/medical/update-record.php**, **api/medical/add-history-update.php**, and **api/patients/get-audit.php**: a Cancelled appointment alone counted as a doctor-patient relationship.
- Added `services/ClinicalAccessService.php` and applied the same policy to all six routes:
  - A current `Confirmed` appointment allows pre-visit record review.
  - If the appointment is `Cancelled`, access remains only if the linked visit workflow reached `In Progress`, `Ready to Complete` or `Completed`, a visit note exists, or a prescription exists for that same doctor/patient/appointment.
  - A cancelled appointment with only a `Waiting` workflow and no note/prescription is not sufficient.
- The two write routes use the shared service inside their transactions; the service can lock the qualifying appointment. `update-record.php` locks the qualifying appointment before locking the patient row, maintaining an appointment-first clinical write lock order. `add-history-update.php` now inserts its history event within the same transaction as its locked authorization check.
- Local data distribution: **35 cancelled appointments** have no workflow, visit note or prescription evidence. The service does not grant those relationships access by themselves. There are **216 visit notes**, **99 prescriptions**, and the local confirmed visit workflows remain available for legitimate access.
- Read-only QA: **6/6 passed** across **453 distinct doctor/patient pairs**, including comparison against the SQL policy for every pair, denial of cancelled/no-evidence relationships, confirmed pre-visit access, invalid-ID denial, requirement for a transaction in lock mode, and successful `FOR UPDATE` execution inside a rolled-back transaction.
- PHP lint subsequently passed for the new service and all six affected routes (**7/7**). Local Apache configuration syntax check returned `Syntax OK`. Browser/session HTTP regression remains pending; no clinical data was written or changed. HB-SEC-001 is locally fixed, runtime authorization verification pending.

### P2 - manual session checks and route consistency
- The initial scan found four session-based settings/department routes using raw session values. All four have now been updated locally to use requireRole()/requireAuth() and explicit GET-only contracts: **api/settings/get-contact-messages.php**, **api/settings/get-user-messages.php**, **api/settings/get-hospital.php**, and **api/departments/get.php**.
- PHP lint passed for all four. Runtime tests for stale/revoked sessions, role changes, public response shape and 405 behavior remain pending.
- **api/settings/get-user-messages.php** still has no exact frontend literal caller and remains listed as an API cleanup candidate under HB-CLEAN-001; the auth/method hardening does not prove it is still needed.

### P2 - HTTP contract consistency
- Several read endpoints have no explicit method guard in source. Record the intended GET-only contract and add consistent 405/Allow handling during the security/architecture pass where appropriate.
- **api/appointments/get-available-slots.php** now enforces POST and returns 405 with `Allow: POST` for other methods. PHP lint passed; the HTTP method response still needs a local browser/API regression check.
- GET-style reads such as doctor appointments, ratings, and lookup endpoints should be checked for explicit read-only/method behavior.

### Additional policy/verification items
- Confirm no endpoint exposes sensitive records merely because the caller is authenticated; review every requireAuth() route for row-level ownership and allowed-role checks.
- Confirm public routes intentionally expose only public fields, particularly doctor directory/availability, hospital settings, and active department data.
- Confirm every state-changing route has a documented method/content-type contract and the frontend sends the same contract.
- Cross-check audit and notification side effects against transaction boundaries; some notifications remain best-effort until a durable outbox/retry approach is selected.

## 5. Evidence patterns and required review

| Evidence pattern | Interpretation | Required next review |
|---|---|---|
| requireRole("admin") | Central authenticated admin role check is present | Verify resource IDs, audit actor, method, validation, and transaction scope |
| requireRole("doctor") | Central authenticated doctor role check is present | Verify assignment/ownership of each patient/appointment/record |
| requireRole("patient") | Central authenticated patient role check is present | Verify patient identity is bound to session, not caller-supplied ID |
| requireAuth() only | Central session/account validation is present | Verify permitted roles and every resource ownership/state rule |
| Raw session or role reads | Route uses session values directly | Replace privileged decisions with central validated identity or prove safe equivalent |
| No route-level auth call | Could be intentionally public or use implicit policy | Confirm documented public status and minimize returned fields |
| No explicit method guard | Method contract is unclear or indirect | Confirm read-only/write behavior and enforce explicit allowed methods |

## 6. Phase 1 completion status

**Status: In Progress.** The route census and exact static frontend literal map are captured. The following work remains before Phase 1 can be marked complete:

- [ ] Review every route complete authorization and row-level ownership logic, not just guard calls.
- [ ] Confirm dynamic URL construction, form actions, injected fragments, external integrations, and direct API consumers.
- [ ] Add or validate request/response contracts and status-code behavior for all routes.
- [ ] Trace critical page journeys through JS to endpoint to service/helper to tables.
- [ ] Reconcile route dependencies with reschedule documentation and all current migration/schema files.
- [ ] Confirm the six no-literal-caller routes with static and runtime evidence; decide keep, document, consolidate, or remove only after review.
- [ ] Complete/review the module map and prioritized findings register.
- [ ] Re-run counts and update both roadmap and root handoff with final Phase 1 evidence.

**No application source, SQL, configuration, or database records were changed while producing this inventory.** No endpoint was removed or modified. No commit, push, deployment, or hosted database operation was performed.


## 7. Scope rule Ã¢â‚¬â€ 5 November 2026

This inventory supports the repair and refactor of existing endpoint behavior. Do not add new API features or broaden route capabilities as part of this work. Focus on accurate method/authentication/authorization/ownership/validation/response/side-effect contracts, safe behavior, regression tests, and retirement of endpoints only when their lack of use and compatibility impact have been proven. If a security correction needs a new user-facing workflow, record the options for a separate decision. See `docs/HEALTHBRIDGE_PORTFOLIO_REFACTOR_SCOPE.md`.


## 5 November 2026 Ã¢â‚¬â€ Doctor workspace clinical authorization follow-up

The `api/doctors/workspace-data.php` row now records strict patient/optional appointment ID parsing and the shared `ClinicalAccessService` gate. This follow-up expands HB-SEC-001 from the original six-route integration to **seven** doctor clinical patient-data routes. Local PHP lint and 23 focused service/route assertions passed; the isolated fixture's four linked pairs matched independent SQL, and session-scoped temporary tables covered cancelled-only denial and accepted encounter evidence. Authenticated browser/HTTP regression is still pending.


## 5 November 2026 Ã¢â‚¬â€ Notification and prescription endpoint review

The combined notification route now has strict admin-selected patient ID validation with a patient-role lookup, self-scoping for non-admin roles, strict page/notification ID parsing, a page-offset overflow check, and explicit GET/POST method handling. The NotificationService methods were verified to scope every read/count/mutation by user ID. Temporary-fixture regression passed **23/23**; authenticated runtime role testing remains pending.

The prescription list and print rows were corrected after source verification: patient/doctor list scopes are derived from the authenticated identity, individual reads/prints enforce prescription ownership, admins have the documented global view, and print responses are private/no-store. No route code change was necessary.


## 5 November 2026 Ã¢â‚¬â€ Admin schedule overview caller follow-up

The earlier ScheduleManager GET migration did not cover the independent admin overview caller in `assets/js/pages/admin.js`. That caller had still sent POST-with-JSON to the GET-only route; local HTTP reproduced 405 / `Allow: GET`. It now issues GET without a body. Schedule get/update/reset target IDs and update payload structures/field types are now validated consistently. Focused QA passed 17/17; authenticated admin browser acceptance remains pending.


## 5 November 2026 Ã¢â‚¬â€ Settings input and ownership validation follow-up

The account settings GET route is confirmed read-only for missing preference defaults and uses only the authenticated user's profile/preferences/notification count. The self-service update route now type-checks profile values and validates a changed, non-empty phone using the shared Egyptian format and normalized uniqueness helpers while preserving unchanged legacy phone data. The admin-only hospital update route validates typed values, strict duration/time/confirmation types, and conflict-checks the full proposed hours pair when either side changes. Findings `HB-API-012` and `HB-API-013` were added; focused temporary-table/helper suites passed 15/15 and 17/17 respectively. No persistent account, hospital, or schedule data changed. Authenticated browser acceptance remains pending.


## 5 November 2026 Ã¢â‚¬â€ Settings preference input contract follow-up

The `api/settings/update.php` inventory row now also records strict boolean/0/1 toggle representations, doctor consultation-duration bounds/step, valid HH:MM work times with stored-counterpart checks, and the admin default-tab allowlist. The existing settings UI payload was verified to match these constraints. Focused input/UI regression passed **54/54**; authenticated browser acceptance remains pending.


## 5 November 2026 Ã¢â‚¬â€ Contact/reply endpoint follow-up

The contact submission row now records strict JSON text types for required/supplied optional fields while retaining intentional public/anonymous use and session association when signed in. The admin reply row now records strict positive message IDs, 1Ã¢â‚¬â€œ5000 reply text, admin-only access, and best-effort notification after the reply is persisted. The admin contact list remains admin-only. Focused QA passed **22/22**; authenticated/browser acceptance remains pending.


## 5 November 2026 Ã¢â‚¬â€ Compatibility endpoint and unmatched read scope review

The legacy availability endpoint now requires text field/value, retains neutral `available: null`, and advertises its POST contract. The unmatched user-message, legacy notification, ratings, contact inbox, and pending-reschedule reads have been reviewed for self/role scope; no route was removed, as absent current frontend literals do not prove there are no external consumers. Compatibility and authenticated browser acceptance remain pending.


## 5 November 2026 Ã¢â‚¬â€ Reschedule mutation endpoint input contract follow-up

The request, suggest, approve, reject, accept-suggestion, decline-suggestion, and cancel-reschedule-request routes now strictly parse appointment IDs with the shared positive-integer helper and reject non-text date/time/notes/reason fields before trimming. Existing role checks and JSON/form fallback contracts were retained. The shared service's ownership/state/locking/slot-validation behavior was reviewed and left unchanged in this pass. PHP lint passed for all seven routes and the service; authenticated browser acceptance and focused malformed-payload runtime tests remain pending.


## 5 November 2026 Ã¢â‚¬â€ Booking and cancellation lifecycle boundary follow-up

The booking, admin approval/decline, doctor update, and patient cancellation routes were rechecked against the visit workflow. Confirmation and workflow creation remain atomic; cancellation continues to preserve started/completed encounters. Patient cancellation now follows doctor-first then appointment locking, matching booking/rescheduling/archive lock order. Admin approve/decline and doctor update use strict appointment IDs; doctor status is type-checked; booking validates supplied patient IDs and notes. PHP lint and parser checks passed. Concurrency and authenticated browser testing remain pending.


## 5 November 2026 Ã¢â‚¬â€ Account deactivation/archive endpoint follow-up

The doctor/patient archive and reversible status-toggle rows were rechecked against the admin UI. All four admin mutation routes require POST/admin access and now strictly parse the target account ID using `parsePositiveInt()`. Doctor disable/archive checks active encounter workflow state and cancels only eligible not-started appointments; patient archive cancels eligible not-started appointments while reversible patient status toggle only changes account access. Self-service deactivation is scoped to the authenticated user, requires password confirmation, protects the last active admin, retains clinical/history rows, and writes its audit event in the same transaction. Current UI callers remain on the same routes/payload shape. Authenticated role/browser regression and DB concurrency tests remain pending.


## 5 November 2026 Ã¢â‚¬â€ Reschedule locking/read-scope correction

The shared `RescheduleService::getAppointment()` locking read now locks only the appointment row. It no longer locks the joined patient `users` row; the patient's display name is fetched separately as a non-locking informational read. This avoids the reverse lock dependency with patient archive/self-deactivation, which locks the patient account before its appointments. A disposable two-connection InnoDB test reproduced the previous 1213 deadlock and passed after the correction (both transactions committed). No endpoint or request/response contract changed. Authenticated browser acceptance remains pending.


## 5 November 2026 Ã¢â‚¬â€ Self-deactivation last-admin concurrency guard

`api/settings/update.php` now serializes administrator self-deactivation by locking active admin rows in ascending ID order before locking the current account. The last-admin check counts those locked rows rather than using an unlocked `COUNT(*)`. This closes the concurrent two-admin path that could otherwise deactivate every administrator. Two-connection InnoDB regression passed after the fix; no route or payload contract changed. Authenticated browser acceptance remains pending.


## 5 November 2026 Ã¢â‚¬â€ Mutation lock-order note

No endpoint or payload was added. Existing appointment and clinical mutation routes now obtain account locks before appointment/workflow locks using the shared doctor Ã¢â€ â€™ patient Ã¢â€ â€™ appointment order: api/appointments/approve.php, decline.php, update.php; api/medical/add-visit-note.php, complete-visit.php, transition-workflow.php, add-history-update.php, update-record.php, save-visit-draft.php, and delete-visit-draft.php. Appointment identity is rechecked after locking. Schedule update/reset remains isolated to schedule-settings then weekly rows.


## 5 November 2026 Ã¢â‚¬â€ Doctor archive notification transaction note

api/doctors/delete.php and api/doctors/toggle-status.php now commit appointment cancellations, account state and audit before creating the existing best-effort patient cancellation notifications. The reversible status route's doctor account-status notification is also post-commit. This avoids taking patient recipient FK locks while appointment/workflow rows are held; current notification content and API response shape are unchanged.


## 5 November 2026 Ã¢â‚¬â€ Prescription mutation and completion transaction note

Existing prescription create/update/complete/cancel routes retain their role and state contracts. Their IDs now use strict positive-integer parsing, and cancellation reason is explicitly text/length validated. PrescriptionService account/appointment/prescription locks now follow doctor Ã¢â€ â€™ patient Ã¢â€ â€™ appointment Ã¢â€ â€™ prescription order. api/medical/complete-visit.php still commits clinical note, prescription statuses, workflow, draft deletion and audit together; only the best-effort patient notification moved after commit.


## 5 November 2026 Ã¢â‚¬â€ Patient medical-record profile validation

api/patients/update.php continues to lock the patient account before medical-record mutation and commit its audit atomically. Optional gender now permits only Male/Female/Other or empty/null; date of birth must be a real YYYY-MM-DD calendar date when supplied; is_active accepts only native booleans or 0/1 integer/string values (explicit null retains no-change behavior). The existing patient EMR UI payload is compatible.

## 5 November 2026 Ã¢â‚¬â€ Account lifecycle notification contract follow-up

The combined admin patient-profile endpoint retains its current POST payload and response contract. When is_active genuinely changes, its post-commit notification now uses the existing account-status event; if other profile fields also changed, the existing profile-updated event is sent separately. A no-status-change profile edit preserves the prior generic profile notification. Self-service doctor deactivation still returns the same response fields and cancellation count; patient cancellation notifications are now isolated per appointment so one failed insert does not suppress subsequent attempts. Session revocation, transactional audit, and appointment state contracts are unchanged. No endpoint or request field was added.
## 5 November 2026 Ã¢â‚¬â€ Deployment path and access-boundary verification

Read-only GET checks against the configured public hostname could not obtain an HTTP response: HTTP returned an empty reply and HTTPS closed before the HTTP request received a status. Consequently no public API or sensitive path was considered pass/fail in this run. Local Apache returned 403 for all 13 sensitive-path probes and 200 for the app homepage. The root/nested .htaccess rules are present locally but untracked in Git; whether they were uploaded to the deployed htdocs is unknown. No endpoint contract was changed. Repeat external GET checks only after the host responds and after confirming the deployed protection files and removal of historical migration executors in the hosting file manager.
### Deployment verification note Ã¢â‚¬â€ 5 November 2026

The hosted instance has not received a post-repair upload. Its original deployment included the project files and used the repository SQL snapshot current at that time. Consequently this inventory's local path restrictions are not evidence of remote protection. Remote HTTP checks were inconclusive (no HTTP response). Re-verify the deployed boundary only after the user authorizes hosting selection and a clean redeployment.

### Internal notification delivery worker (not an HTTP endpoint)
- All existing backend notification call sites continue to use NotificationService::create(); the service now writes a notification_outbox record and attempts immediate dispatch when it is outside an open business transaction.
- If called during a business transaction, the outbox row commits or rolls back with the mutation. The separate CLI worker inserts the user notification and marks the event delivered in one transaction.
- tools/process_notification_outbox.php is CLI-only and the existing tools/.htaccess denies HTTP requests. It accepts --limit=1..100; default is 50.
- The worker retries transient delivery failures with 30s, 60s, 2m, 4m, 8m, 16m and 32m delays (eight total attempts including the immediate attempt). Terminal failed rows remain for operator review. A periodic host task is required for automatic retries and events queued inside a business transaction. No public outbox endpoint was added.
- Transactional enqueue is now used by `api/appointments/book.php`, `approve.php`, `decline.php`, `update.php`, and `api/patient/cancel-appointment.php`: each writes its notification_outbox event before the appointment transaction commits and attempts a batch dispatch after commit.
- Prescription notifications are queued from `PrescriptionService::create()`, `update()`, `complete()`, and `cancel()` before each prescription/audit transaction commits. The corresponding `api/prescriptions/*.php` routes no longer create duplicate post-commit notices. `api/medical/complete-visit.php` queues its existing appointment-completed event before committing the clinical note, prescription updates, workflow, draft deletion and audit; it dispatches only after commit.
- Other mutation handlers still require an individual review; do not infer that every notification call site is crash-gap-free.

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

- This endpoint inventory remains a working audit artifact, not proof that every route has been dynamically tested or every caller mapped. Older route counts and auth summaries are dated snapshots; re-scan the current tree before access-policy or deletion decisions.
- Notification changes since the initial inventory are recorded in root handoff Sections 80-90. The most recent route changes are patient update, doctor creation, patient registration, doctor ratings, settings profile/deactivation, and support replies.
- The patient cancellation endpoint was rechecked and already enqueues its event in the doctor-first/appointment-second transaction. The public contact endpoint stores submissions without emitting a notification.
- Syntax and source-order checks are not substitutes for per-route HTTP/database integration tests. Targeted disposable-database failure-injection tests are still required for the six routes changed in Sections 83-88.
- The local outbox worker runs every five minutes as the interactive Windows user; see handoff Section 90 for its login limitation and log location.

## 8 November 2026 — Route inventory reconfirmation

The static scan was rerun against the current application source tree using the inventory's original scope: `assets/js/`, `pages/`, and root-level HTML. This is 54 files (32 JavaScript plus 22 root/page HTML); the two reusable files under `includes/fragments/` are intentionally excluded.

The recheck confirms **89 API endpoints, 175 exact path-literal occurrences, 133 unique frontend-file-to-route mappings, 82 endpoints with an exact literal, and seven without**. The seven no-literal routes remain:
- `api/appointments/get-pending-reschedules.php`
- `api/auth/check-availability.php`
- `api/doctors/get-admin-ratings.php`
- `api/notifications/get-patient.php`
- `api/patients/get-audit.php`
- `api/patients/profile.php`
- `api/settings/get-user-messages.php`

Each route was re-read and filename searches were repeated across the project source. Their role/method/data scopes remain documented above; no direct current JS/HTML path caller was found. This does not rule out dynamically assembled URLs, out-of-repository consumers, bookmarks, or deployed legacy integrations. Preserve all seven until those contracts are ruled out; do not remove routes or tables from static absence alone.


## 8 November 2026 — Clinical draft and shared prescription read-model verification

The doctor workspace and patient EMR prescription read paths now share `PrescriptionService::attachItems()`. The helper performs bounded batches of up to 500 prescription IDs, preserves `sort_order`, and optionally returns an item-count field derived from the fetched rows. Both APIs preserve their existing `items` and `item_count` response keys. The workspace N+1 and duplicate EMR hydration code/correlated count subqueries were removed.

The draft GET route now rejects malformed query IDs with 400; the draft DELETE route derives the doctor ID from the authenticated role result before account locking. The built-in-server HTTP suite at `C:\xampp85\migration-backups\healthbridge\test_clinical_draft_and_workspace.php` passed **41/41 assertions** against `healthbridge_repair_test`, including draft access/lifecycle and exact ordered prescription-item equivalence for both API responses. All fixture table counts returned to baseline. Application PHP lint passed **114/114**; no migration or `healthbridge` production-like database write was made.


## Patient portal page/API dependency recheck — 8 November 2026

The page-to-route mapping was rechecked against current HTML script tags and the nested page controllers:

- Appointment UI: `api/patient/appointments.php`, `api/patient/cancel-appointment.php`, appointment accept/decline/cancel-reschedule routes, doctor-rating read/write routes, and booking-wizard availability/booking/reschedule/doctor routes.
- Seven page controllers (dashboard, downloads, history, insights, notifications, prescriptions fallback, and profile) call `api/patient/dashboard.php`; notifications also calls `api/notifications/notifications.php`. Prescriptions normally uses `api/prescriptions/get.php` and `api/prescriptions/print.php`.
- Settings uses `api/settings/get.php` and `api/settings/update.php`. The separate patient EMR entry loads its own controller/modules and endpoints.

**HB-PERF-003:** the shared dashboard endpoint calls `PatientPortalService::getAll()` and returns all 12 aggregate sections on each normal request, although individual page controllers consume subsets. No change to method, authentication, response shape, or caller behavior is made here. Measure per-page needs and add disposable-database equivalence tests before optimizing.


## Patient dashboard selective section behavior — 8 November 2026

The `api/patient/dashboard.php` row now documents the optional strict `sections` list. This property is not required: a request without it still returns the complete legacy payload and all six dashboard preview aliases. When present, it can contain one or more unique keys from the fixed 12-section service allowlist (maximum 12), in caller-requested order. Invalid scalar, empty, associative, duplicated, unknown, or over-limit lists return 400. Search continues to take precedence and remains unchanged.

The six section-page callers now send exact requirements: `downloads`; `medical_timeline`; `insights + health_snapshot + profile_completion + medical_timeline + prescriptions`; `notifications`; `prescriptions` (fallback only); and `profile + overview + health_snapshot`. The dashboard sends no selection and uses the complete payload.

The read-only authenticated HTTP test passed **17/17**, including default full response key/order compatibility, all six selected payloads, invalid input responses, method/authentication behavior, and search. The service measurement suite confirmed 35 queries/19,959 bytes for full versus 2–15 queries/648–5,112 bytes for each selected request. Only the disposable `healthbridge_repair_test` database was queried; no rows were written.


## Authenticated browser contract follow-up — 8 November 2026

**HB-API-023:** `api/schedule/get.php` remains GET-only. The admin doctor-profile Schedule tab now passes its selected doctor as `?doctor_id=` on GET instead of sending POST JSON. The route keeps strict positive-ID parsing and admin-only target access. Before the caller fix, local authenticated browser testing reproduced POST → 405; after the fix, opening the lazy-loaded Schedule tab returned GET → 200. The other schedule callers (`modules/schedule.js`, `pages/admin.js`) retain their existing contracts.

The patient dashboard endpoint's 18-key no-selection response and all six section-page contracts were also exercised in authenticated Chrome. Doctor workspace, doctor dashboard, admin dashboard, and admin doctor profile were checked against the disposable database. No production/live DB was used and no state-changing action was executed.


## Section 99 - Patient appointments, settings, and EMR read-route verification - 8 November 2026

Authenticated synthetic-role Chrome checks completed the remaining patient/staff page read contracts on loopback healthbridge_repair_test:

- GET /api/patient/appointments.php?page=1&limit=10&status=all&search= returned 200 for the patient appointments initial state. Confirmed and all filter requests, plus search=Cardiology, also returned 200. The route remained patient-scoped and no cancellation, reschedule, booking, or rating write route was called.
- GET /api/settings/get.php returned 200 for synthetic patient ID 2. The UI displayed Account, Password, Appearance, and Notifications and hid the Practice/Admin sections. No api/settings/update.php request was made.
- GET /api/medical/get-emr-data.php?patient_id=2 returned 200 for synthetic admin ID 1. The UI's requireServerRole('admin') guard confirms that the current EMR page is admin-only even though the underlying API supports patient and doctor access under separate permission rules.
- Activating the EMR Audit tab made GET /api/audit/get.php?patient_id=2&limit=50; the request returned 200. The endpoint source is GET-only and requires the admin role. The inventory row above is corrected accordingly.
- All nine EMR tabs rendered; all observed API responses returned 200. The browser intercepted all non-GET/HEAD/OPTIONS methods before dispatch and recorded zero attempted writes. No page, console, local-request, or horizontal-overflow errors were observed.

Only the disposable QA database was used. Table counts matched the latest verified fixture baseline; no migration or write was performed. This section is read-path/browser evidence only and does not certify any of the page's separately exposed mutation actions.


## Section 100 - Public entry route contract verification (8 November 2026)

- Anonymous Home GETs: api/departments/get.php and api/settings/get-hospital.php both returned 200 and successful payloads.
- Anonymous Doctors GETs: api/departments/get.php, api/doctors/get.php, and api/doctors/get-public-availability.php returned 200/success on the migrated fixture. The availability service reads doctor_schedule_settings, doctor_schedule_weekly, appointments, and hospital_settings in addition to doctor/user identity.
- Anonymous Services lookup: api/auth/current_user.php returned the expected 401 for no valid session. services.js catches this and keeps the visitor logged out; the browser emits one generic failed-resource 401 console message.
- Contact writes were not executed. The local POST route is listed above; the browser controller additionally posts the same payload to Formspree without conditioning the external POST on local failure. See HB-SEC-008.
- Error contract: both api/doctors/get.php and api/doctors/get-public-availability.php now set HTTP 500 in their existing exception handlers. The availability error was reproduced on a stale disposable schema and returns 500; migrated-fixture success remains 200. A deliberate POST to the GET-only availability endpoint returned 405 with Allow: GET.

The current repair_test database is missing appointments.requested_reschedule_date and requested_reschedule_time, both present in database/healthbridge.sql and the active Phase 10 migration. The restore_test disposable fixture has both fields; its public availability query returns eight successful records. Use restore_test for ScheduleService/reschedule-dependent QA until repair_test is explicitly restored or migrated. No database schema/data changes were made.


## Section 101 - Authentication route dependency and contract verification (8 November 2026)

- Login is POST-only, queries users, and uses host-temp AuthAbuseRateLimiter; successful login rotates PHP session ID and carries session_version.
- Registration is POST-only. Step 1 is validation-only and rate-limited in host-temp state; Step 2 is atomic across users, medical_records, user_preferences, admin_audit and notification_outbox. NotificationService may later create notifications after outbox commit.
- Forgot Password is POST-only, stores token hashes/attempt rows in password_resets and checks active users. It does not send email: loopback APP_ENV=development may return a reset_url; non-loopback/deployed calls return 503 until a mail transport is configured.
- Reset Password is POST-only and token-based. verify_only=true uses SELECT only; final submission locks password_resets and users and transactionally changes password/session_version and consumes token rows.
- Locations is GET-only and reads egypt_governorates / egypt_cities via shared helpers.
- Current user is GET-only and calls requireAuth(), which checks users account status, role, session age and session_version.
- Logout is POST-only session/cookie cleanup. check-availability.php is a compatibility POST route, format-validates only, returns available:null, and has no exact frontend caller.

HTTP regression: GET to login/register/forgot_password/reset_password/logout/check-availability returned 405 / Allow: POST. Locations GET returned 200/27 governorates; locations POST returned 405 / Allow: GET. Anonymous current_user returned 401. A synthetic invalid 64-hex reset token with verify_only=true returned 400; source confirms this branch is read-only.

Registration requires notification_outbox via NotificationService. Canonical schema and active Phase 15 migration define it, but it exists in healthbridge_repair_test and is absent from healthbridge_restore_test. The latter is suitable for Phase 10 schedule tests but not yet a current registration/outbox integration fixture. Do not infer production DB schema from either disposable fixture.


HB-AUTH-005 records that api/auth/forgot_password.php is intentionally 503 outside strict loopback development until a mail transport is configured; it does not send mail or issue production reset URLs.


## Section 102 - API method/auth census and compatibility-route dependency review (8 November 2026)

Static source census: 89/89 API PHP files contain REQUEST_METHOD checks and 89/89 include an Allow header for their 405 branch. The unified notification route intentionally allows GET and POST. This does not mean every unsupported-method branch has received an individual HTTP test. Nine routes without central requireAuth()/requireRole() calls are the expected public authentication, logout, registration, location, public doctor-directory and public availability routes.

Service map: 13 service classes are referenced by API routes. Source-level caller counts overlap: AdminAuditService 12, AuditService 28, ClinicalAccessService 15, DashboardAnalyticsService 1, DepartmentService 9, HospitalSettingsService 3, NotificationService 19, PatientPortalService 2, PrescriptionService 8, RescheduleService 8, ScheduleService 11, VisitDraftService 4, VisitWorkflowService 5. Direct SQL/table literals were identified in 68 routes; a static scan may not resolve dynamically composed queries.

The seven HB-CLEAN-001 routes were compared to current paths and services; exact JS/HTML references were zero for all seven. They remain in the inventory because overlap does not prove external compatibility or response equivalence:
- appointments/get-pending-reschedules.php vs appointments/get-doctor.php: dedicated pending collection versus all doctor appointments with reschedule fields.
- auth/check-availability.php: format-only compatibility route; no current UI caller, returns available:null.
- doctors/get-admin-ratings.php: all-system admin reviews; active UI uses the per-doctor get-doctor-ratings.php route.
- notifications/get-patient.php vs notifications/notifications.php: legacy latest-20 list versus paginated GET plus read/delete actions.
- patients/get-audit.php reads patient_audit_log, while EMR audit_history reads medical_record_audit and the admin audit route reads admin_audit. No application writer to patient_audit_log was found.
- patients/profile.php: legacy admin profile aggregate; current EMR is not proven equivalent for its support-message fields.
- settings/get-user-messages.php: user-scoped contact/reply list without a current UI caller; not the same as admin contact inbox.

Focused anonymous HTTP regression returned 401 for all six protected candidate routes and the current unified notification route. check-availability GET returned 405 / Allow: POST. No authenticated role-scope or legacy external-client contract was exercised.

HB-API-025: source scan identified catch paths in doctors/get-rating.php, patients/get-audit.php and settings/contact.php that call jsonResponse(false, ...) without setting an HTTP error status. jsonResponse() itself does not set the status. This remains an un-reproduced runtime failure-path concern; do not change until a safe fault-injection test is authorized. The Contact route must not be submitted for that test.


## Section 103 - API exception response status regression (8 November 2026)

HB-API-025 was reproduced for two read routes with an isolated loopback HTTP harness executing the actual endpoint files. A synthetic authenticated patient identity was supplied and fake PDO prepare() threw before SQL, so no database connection or data operation occurred.

Before correction, doctors/get-rating.php and patients/get-audit.php both returned HTTP 200 with success:false. Each existing catch block now assigns HTTP 500. Post-fix injected failures return HTTP 500 / success:false and preserve the same JSON error messages.

Focused regression also passed: POST to each GET-only endpoint returns 405 / Allow: GET; invalid appointmentId returns 400; patient ID 3 requested by synthetic patient ID 2 returns 403.

HB-API-025 is partially resolved. The contact route api/settings/contact.php is unchanged and still has the source-level concern; no contact submission or contact fault injection was performed.


### Section 103 follow-up - HB-API-025 Contact catch (8 November 2026)

The Contact catch body was independently tested without running the endpoint or submitting form data. A temporary loopback PHP harness tokenized contact.php and executed only its verbatim catch body under a synthetic Exception. It bypassed request parsing, validation, DB access, persistence, and delivery. The unmodified catch fragment returned HTTP 200 / success:false. Adding http_response_code(500) changed the same fragment to HTTP 500 / success:false; the original generic JSON message is unchanged.

All three HB-API-025 exception-response branches now assign HTTP 500. The finding is resolved at catch-branch level, not a full Contact workflow certification. No contact submission or DB access took place.


### HB-DATA-001 - public doctor-profile claims corrected (8 November 2026)

Removed unsupported patient-volume, education/board-certification and language claims from the public doctor card/modal. The modal's inferred specialization marketing paragraph is now a plain department value. The card retains experience and patient rating; the modal retains Experience, Department, Patient Reviews, and schedule-backed fields where available.

Real public page + actual JS/CSS browser test passed with all three API requests intercepted/mocked: two supported card stats only, no unsupported text, modal information titles verified, mobile 390px layout passed, zero page errors. No API/database access or doctor-record modification occurred. HB-DATA-001 is locally resolved; live production acceptance remains unperformed.


### HB-SEC-008 follow-up - single-destination contact flow (8 November 2026)

The current contact-page flow posts submitted details once to the same-origin HealthBridge contact API. The Formspree endpoint and external payload copy were removed; the page now discloses HealthBridge-only form handling and advises against including urgent or sensitive medical information. The API inventory/module map rows above reflect the current behavior.

Mocked Chrome regression intercepted three synthetic local API POSTs before PHP: success response reset the form; failure response preserved the form; HTTP 500 with success:true was rejected. Zero Formspree requests, all unrelated external requests blocked, no PHP/database request, and zero browser page errors. HB-SEC-008 is locally resolved; production deployment verification remains pending.


### HB-SEC-007 deployed document-root update (8 November 2026)

The public document root returned HTTP 206 to browser GET requests with a one-byte Range for /.gitignore, /README.md, /composer.json and /database/healthbridge.sql. Bodies were canceled after headers and not read. This confirms that some static internal files are directly served. The endpoint inventory is unchanged; the hosting directory-level access policy is not. Local .htaccess protection does not establish deployed protection because the hosted tree remains the original upload.


### HB-SEC-007 hosted web-root access policy (8 November 2026)

No API endpoint or method contract was changed by this operation. A root `/htdocs/.htaccess` now protects repository metadata/configuration, Markdown/SQL documents and internal service/database/docs/tools/vendor trees, while preserving public page routes, `/api`, and non-PHP HTML fragments inside `includes`. Live checks showed the home page and five public HTML pages HTTP 200; six internal directory roots HTTP 403; and 12/16 sensitive path probes HTTP 403. The four formerly confirmed 206 file disclosures now return 403. Four dotfile probes (`.env`, `.git/HEAD`, `.git/config`, `.htaccess`) still return no usable status and must remain marked unknown. No API request that mutates application data was sent; no database was connected to. HB-SEC-007 remains P1/open pending the remaining boundary checks.


## Hosted-environment boundary — local endpoint contracts remain the test source (8 November 2026)

Endpoint method, authorization, caller, service, and table analysis must continue against the local source tree at `C:\xampp85\htdocs\optimized`. The hosted website is frozen and must not be accessed for routine endpoint inventory, PHP-source checks, remote directory inspection, or DB checks. Do not change hosted files/configuration or invoke hosted API mutation routes.

A public-host check is permitted only when there is a specific reason to verify a public, read-only behavior that cannot be established locally. Never test live account, appointment, contact, admin, clinical, or other mutation workflows. Continue endpoint regression on local Apache with disposable synthetic-data databases.

The last hosted root `/htdocs/.htaccess` remains untouched. HB-SEC-007 is still P1 / partially mitigated / open: four dotfile paths returned no usable HTTP status and the remote historical migration-executor inventory is unresolved. This endpoint inventory and local access-control results do not certify the remote deployment.

The eventual release is a full clean replacement upload from the final local tree, not piecemeal synchronization. Before it, prepare a sanitized runtime-only package, take a fresh hosted-file backup, and define rollback. Require explicit approval for file deployment; treat database migration/data changes as a separate release operation.


## HB-SEC-001 print-route privacy regression — 8 November 2026

`api/medical/print-patient-emr.php` now authorizes doctor access with `ClinicalAccessService::doctorCanAccessPatient()` before looking up the patient row. This prevents the doctor-only 403/404 response difference from revealing whether an arbitrary patient ID exists. Admin and patient behavior remains unchanged. The authenticated synthetic-fixture loopback suite passed 22/22 across the seven clinical data routes and denied both tested unauthorized clinical writes; six monitored table counts were unchanged. See root handoff Section 109.


## HB-SEC-002/003/004/005 authenticated route checks — 8 November 2026

Local loopback verification against the synthetic fixture confirmed the route contracts for `api/settings/get-contact-messages.php`, `api/settings/get-hospital.php`, `api/settings/get-user-messages.php`, and `api/departments/get.php`. The role/method/visibility suite passed **18/18**. Stale session-version and disabled-account follow-up passed **8/8**, confirming 401 for stale sessions and 403 for inactive doctor/admin sessions before protected responses. Test-only fixture state was restored; no application records changed. See main handoff Section 110.


## Shared API origin/header policy — 8 November 2026

The API bootstrap's `sendCorsHeaders()` rejects mismatched Origin values and state-changing requests carrying non-same-origin Fetch Metadata; it does not emit `Access-Control-Allow-Origin`. A read-only local loopback regression passed **10/10**, including hostile/opaque/unexpected-port Origin denial, Fetch Metadata denial, same-origin method behavior and OPTIONS behavior. PHP also emits the narrow CSP fallback. Apache root configuration applies the baseline response headers to static and API responses without duplicate values, HSTS only on HTTPS, and suppresses X-Powered-By. Local header and syntax evidence is in root handoff Section 111. A fully restrictive script/style CSP and public-host verification remain pending.


## Appointment booking authenticated route evidence — 8 November 2026

`api/appointments/book.php` passed **28/28** authenticated local loopback HTTP assertions against the synthetic fixture. Role, malformed ID, department ID/name, doctor/department, inactive profile/department, ambiguous legacy name, and invalid admin patient target checks passed. Valid patient self-booking, admin-assisted booking, and unique legacy name-only compatibility persisted canonical identities and queued notification events. Three test bookings and dependent data were cleaned; ten table counts returned to baseline. No application source or hosted resource was changed. See root handoff Section 112.


## Patient admin password-only update route — authenticated regression — 8 November 2026

`api/patients/update.php` admin password-only branch passed **18/18** local authenticated HTTP assertions against the synthetic fixture: role/method denial, weak/mismatched password rejection, successful admin reset, new hash verification, session-version increment and immediate prior-session rejection. Original synthetic credential fields were restored; audit, notification and outbox rows returned exactly to baseline. Browser modal acceptance remains pending. See main handoff Section 113.


## `api/appointments/get-available-slots.php` input/HTTP verification — 8 November 2026

This route remains POST-only and now rejects coerced/partial doctor IDs, non-string date values, malformed dates and impossible calendar dates before querying ScheduleService. Local authenticated HTTP suite passed **21/21** and verified valid slots exactly match the shared service response; no application rows changed. See root handoff Section 114.


## Doctor profile GET route role/ID regression — 8 November 2026

`api/appointments/get-doctor.php` and `api/doctors/get-doctor-ratings.php` now parse optional/required doctor_id with shared `parsePositiveInt()`. Admins can target valid doctor accounts; doctor sessions remain self-only; patient/anonymous roles are denied. Local authenticated HTTP request suite passed **34/34**, including appointment patient_id alias and anonymized rating fields. Five fixture counts were independently verified unchanged after the test server stop. Browser tab smoke remains open. See handoff Section 115.


## Login endpoint - HB-AUTH-002/003 request regression (8 November 2026)

`POST /api/auth/login.php` passed **20/20** local authenticated HTTP contract assertions using only synthetic fixture identities. GET returns 405/Allow POST; malformed JSON field types and invalid email shape return 400 before rate accounting; wrong-password and nonexistent-email requests both return the same HTTP 401 and generic JSON message. The 10th per-email attempt remains within the allowed window, while the 11th and subsequent attempts return 429 with Retry-After.

The loopback server used isolated temporary limiter and session storage. HMAC-only counter keys were verified, raw email identifiers were absent from persisted limiter state, the synthetic patient/account snapshot and five relevant table counts were unchanged, and the temporary directory was removed. Runner: `C:\xampp85\migration-backups\healthbridge\test_hb_auth_002_003_login_http.php`. Comparative timing measurement and multi-host shared limiter design remain open. See main handoff Section 116.


## All-route HTTP method-contract sweep — 8 November 2026

All **89** current API PHP route files were exercised using an unsupported, bodyless `PUT`. A role-aware batched local loopback suite confirmed HTTP 405 and a valid `Allow` header for every route (89/89), with 90/90 total assertions including a complete disposable-database integrity snapshot. The route-specific supported method declarations remain GET and/or POST; `api/notifications/notifications.php` correctly advertises both. No route received a request body, and no application table rows or AUTO_INCREMENT values changed. Runner: `C:\xampp85\migration-backups\healthbridge\test_hb_api_002_method_contract_http.php`. See root handoff Section 117.


## Clinical EMR/workspace cancelled-encounter acceptance — 8 November 2026

Actual `GET /api/medical/get-emr-data.php` and `GET /api/doctors/workspace-data.php` were exercised against temporary, synthetic cancelled-encounter states. Both deny a cancelled-only relationship with Waiting workflow and no documentation; both allow a cancelled encounter with In Progress workflow; both allow a cancelled encounter backed by matching visit-note/prescription documentation. The clinical workspace also rejects stale doctor sessions (401) and inactive doctors (403). Suite: 14/14 assertions with complete disposable-database restore/fingerprint equality. Runner: `C:\xampp85\migration-backups\healthbridge\test_hb_sec_001_cancelled_evidence_http.php`. See root handoff Section 118.


## Clinical write/cancellation HTTP regression — 8 November 2026

The following eight POST endpoints were exercised with synthetic authenticated sessions: `api/medical/add-history-update.php`, `add-visit-note.php`, `complete-visit.php`, `delete-visit-draft.php`, `save-visit-draft.php`, `transition-workflow.php`, `update-record.php`, and `api/patient/cancel-appointment.php`. The **37/37** assertions cover strict IDs/types, role and appointment ownership, required note fields, waiting-workflow completion denial, draft ownership, legal transition/rework path, medical-record validation/admin reason requirement, and cancellation restrictions. Complete DB fingerprint/count/AUTO_INCREMENT equality was verified after restoring the single temporary workflow change. External runner: `C:\xampp85\migration-backups\healthbridge\test_hb_api_008_clinical_write_http.php`. Successful persistence and browser acceptance remain separate.


## HB-API-007 read/profile endpoint runtime verification — 8 November 2026

Local authenticated HTTP coverage passed **32/32** across `api/patients/profile.php`, `api/doctors/update.php` (invalid payloads only), `api/medical/get-emr-data.php`, `api/medical/get-record.php`, `api/medical/print-patient-emr.php`, and `api/appointments/get-pending-reschedules.php`. The sweep verified malformed ID/type rejection, role checks, patient self-scoping on JSON/print records, doctor access restriction, and pending-reschedule JSON envelope consistency. Full disposable DB snapshot matched before/after. Runner: `C:\xampp85\migration-backups\healthbridge\test_hb_api_007_read_contract_http.php`. See main handoff Section 120.


## Combined notifications endpoint GET/POST regression — 8 November 2026

`api/notifications/notifications.php` passed **32/32** local authenticated HTTP assertions. The test covered GET pagination/validation, admin patient targeting, patient/doctor self-scope, malformed IDs/actions, `mark_read`, `mark_all_read`, `delete`, `delete_all`, and cross-recipient mutation denial. Exact original notification rows and AUTO_INCREMENT were restored, and full disposable DB snapshot equality confirmed. External runner: `C:\xampp85\migration-backups\healthbridge\test_hb_api_010_notifications_http.php`. Real-browser notification UI acceptance remains open.


## Patient appointments pagination HTTP regression — 8 November 2026

`GET /api/patient/appointments.php` passed **22/22** authenticated HTTP assertions. The run discovered and fixed a missing `$page` closure capture that caused `pagination.current_page` to be null. It now verifies returned current page/page size, patient self-scope, GET-only contract, role denial, malformed/array/overflow page/limit/filter inputs, safe offset boundary, clamping and max page size. Full disposable DB snapshot remained unchanged. Runner: `C:\xampp85\migration-backups\healthbridge\test_hb_api_009_patient_pagination_http.php`.


## Schedule endpoint authenticated HTTP verification — 8 November 2026

`api/schedule/get.php`, `api/schedule/update.php`, and `api/schedule/reset.php` passed **38/38** local authenticated HTTP assertions for GET/POST contracts, admin overview/detail, doctor self-scope, role denial, strict IDs, weekly/settings validation, supported values, and missing-doctor reset behavior. Invalid POSTs only; no schedule writes. Full disposable DB row/count/AUTO_INCREMENT snapshot matched baseline. Runner: `C:\xampp85\migration-backups\healthbridge\test_hb_api_011_schedule_http.php`. See main handoff Section 123.


## Settings and hospital-settings HTTP regression - 8 November 2026

api/settings/update.php and api/settings/update-hospital.php passed 39/39 authenticated local HTTP assertions. Invalid sections/profile fields/preferences, role gates, contact/time/duration/hour-boundary validation and strict boolean confirmation were exercised. The self-settings endpoint now creates missing user_preferences rows only after a supported payload validates; this removes auto-increment sequence side effects from rejected requests. A temporary valid appearance update on a synthetic legacy user proved the creation path and restored the full preference row/sequence exactly. Full disposable DB and hospital settings snapshots matched baseline. Runner: C:\xampp85\migration-backups\healthbridge\test_hb_api_012_013_settings_http.php.


## HB-API-020 - prescription mutation authenticated HTTP coverage (8 November 2026)

The four existing mutation routes under api/prescriptions (create.php, update.php, complete.php, cancel.php) passed **54/54** authenticated local HTTP assertions using synthetic doctor/admin/patient sessions. All are POST-only/Allow POST and doctor-only. Tests rejected malformed, array, overflowing and non-positive IDs; untyped/overlong notes; invalid medication-list shapes; and cancellation reasons that were untyped, blank or longer than 2,000 characters. Wrong-doctor appointment/prescription requests were denied. The sole completed fixture prescription and its item row remained unchanged; full disposable DB row fingerprints/counts/AUTO_INCREMENT matched baseline. External runner: C:\xampp85\migration-backups\healthbridge\test_hb_api_020_prescription_http.php. Successful mutation and doctor-browser acceptance remain open.


## HB-API-021 - admin patient profile mutation input contract - 8 November 2026

POST api/patients/update.php passed **36/36** authenticated synthetic-role HTTP assertions. Coverage includes method/role gates, strict patient ID, gender allowed values/types, DOB type/calendar/format, account-status boolean/0/1 enforcement, and patient-role target existence. No successful mutation was attempted; complete disposable DB/profile snapshots remained unchanged. External runner: C:\xampp85\migration-backups\healthbridge\test_hb_api_021_patient_profile_http.php. See handoff Section 126. Successful update/status-change and browser acceptance remain open.


## HB-API-017 reschedule mutation route runtime coverage - 8 November 2026

Seven existing routes (request-reschedule, suggest-reschedule, accept-suggestion, decline-suggestion, approve-reschedule, reject-reschedule, cancel-reschedule-request) passed **63/63** authenticated local HTTP assertions. Method/role boundaries, strict appointment IDs, and date/time/reason/notes JSON types were verified. No valid mutation was sent; all DB row fingerprints/counts/AUTO_INCREMENT values matched baseline. Runner: C:\xampp85\migration-backups\healthbridge\test_hb_api_017_reschedule_http.php. Service-state transition and browser acceptance remain separate.


## HB-API-018 appointment lifecycle route runtime verification - 8 November 2026

Patient cancel, admin approve/decline, and doctor update routes passed **62/62** local authenticated HTTP assertions. Method/role, strict IDs, decline reason and doctor status fields, direct date/time denial, wrong-owner checks, and confirmed-state rollback paths were verified. Booking was not repeated; HB-DB-002 already provides 28/28 actual-route coverage. Full disposable DB/appointment snapshots unchanged. Runner: C:\xampp85\migration-backups\healthbridge\test_hb_api_018_booking_cancel_http.php. Concurrent lock contention and successful lifecycle/browser flows remain open.


## HB-API-019 account archive/status route runtime verification - 8 November 2026

The four admin endpoints api/doctors/delete.php, api/doctors/toggle-status.php, api/patients/delete.php, and api/patients/toggle-status.php passed **58/58** authenticated local HTTP assertions. Method/role, malformed and coercible ID rejection, wrong account-type and missing-target 404s were verified. No valid archive or status toggle was invoked; full disposable DB and account snapshots matched baseline. Runner: C:\xampp85\migration-backups\healthbridge\test_hb_api_019_account_archive_http.php. Successful lifecycle/browser/concurrency acceptance remains open.


## HB-API-018 concurrent lifecycle lock-order verification - 8 November 2026

A true two-connection regression passed **20/20** for patient cancel, admin approve, admin decline, and doctor update. Each actual HTTP worker was observed running its doctor account FOR UPDATE query while the account was held by another transaction; the contender then acquired patient and appointment locks in account-first order, committed without deadlock, and the route resumed with 409. Full disposable DB/appointment snapshot unchanged. Runner: C:\xampp85\migration-backups\healthbridge\test_hb_api_018_lock_order_concurrency.php. See handoff Section 130.


## HB-API-025 exception status verification - 8 November 2026

api/doctors/get-rating.php and api/patients/get-audit.php passed **13/13** actual-route fault-injection assertions: 200 normal read, 500 generic failure after temporary backing-table rename, and 200 after exact table restoration. Full DB snapshot and original table DDL/rows/AUTO_INCREMENT matched baseline. api/settings/contact.php was not called; its catch was independently tested in isolation. See root handoff Section 131.


## Shared Origin/Fetch Metadata and session-cookie runtime verification - 8 November 2026

The original Origin/Fetch Metadata suite is 10/10; the extended local HTTP suite passed **27/27**. It verifies scheme/host/userinfo/path/query/fragment Origin boundaries, Sec-Fetch-Site values, cross-site requests without Origin, and the real auth bootstrap's HTTP/HTTPS cookie flags, strict session mode, cookie-only transport, URL session-ID rejection, and secure cookie expiry. The suite also found no API PHP route without a shared auth/bootstrap or authorization helper reference. Runner: C:\xampp85\migration-backups\healthbridge\test_hb_origin_session_cookie_extended.php. See handoff Section 132.


## Frontend output and URL-context remediation - 8 November 2026

Although not an API contract change, the local endpoint/UI audit traced API-derived profile, timeline, status, ID, and navigation values into data-fed HTML. Targeted remediation now escapes those HTML text/attribute contexts, constrains CSS class tokens and IDs, JSON-serializes handler name arguments, and allows only same-origin alert/reset/document URLs (document print routes additionally require `/api/`). Three PHP print endpoints were source-reviewed for raw text output. Helper tests passed 35/35 and 22 JavaScript syntax checks passed. Full browser DOM payload coverage, remaining page sinks, and strict CSP migration remain open. See handoff Section 133.


## Patient portal timeline/notification and admin dashboard output hardening - 8 November 2026

The patient portal medical-timeline response supplies fixed server-side icon/color enums plus patient/doctor/clinical free text. Frontend output now restricts CSS color tokens to the exact service palette/hex values, constrains icon classes, and escapes dates/metadata. Notification mark-read IDs are validated. Booking duration and admin-dashboard KPI/count/percentage aggregates are normalized before HTML/SVG output. Expanded helper tests passed 50/50. This is frontend output remediation; no API contract changed. See handoff Section 134.

## Public doctor directory response extension - 8 November 2026

`GET api/doctors/get.php` now includes the additive integer aggregate `rating_count` (`COUNT(DISTINCT r.id)`) alongside its existing doctor fields. The previous `rating` property and `COALESCE(AVG(r.stars), d.rating)` fallback remain unchanged for backward compatibility with current consumers. The public doctors page uses `rating_count` to decide whether a patient average may be displayed; no-rating doctors are labelled “No ratings yet”.

PHP syntax passed. The 21/21 browser regression used intercepted/mock API payloads, so it verifies client behavior but does not execute the new aggregate query. A read-only disposable-database SQL/output check remains required. No schema migration or DB mutation was made. See handoff Section 135.


## Doctor profile rating display compatibility - 8 November 2026

No further API change was required for `GET api/doctors/profile.php?id=`: it already returns the doctor profile and the actual review rows. The two admin profile consumers now derive their visible average from valid 1–5 values in those rows and show “No ratings yet” when no valid rating exists. The profile endpoint's existing `doctor.rating` field is retained for compatibility, but the admin display no longer relies on it.

The public `GET api/doctors/get.php` additive `rating_count` field is consumed by the public directory and booking wizard. The new aggregate query was read-only executed on `healthbridge_repair_test` (8 active doctors; 7 unrated, 1 rated). See handoff Section 136.
## Doctor rating/notification frontend consumers - 8 November 2026

No additional API contract changes were introduced in this browser-hardening pass. The following read endpoints are consumed by the tested flows:

- GET api/doctors/get-doctor-ratings.php: doctor dashboard, admin dashboard doctor's profile modal lazy Reviews tab, and dedicated admin profile tab.
- GET api/doctors/profile.php?id=: admin dashboard doctor-profile modal and dedicated admin doctor-profile page.
- GET api/notifications/notifications.php?page=...: notification service backing doctor notification cards and page notification count.

Frontend consumers now validate rating values and notification IDs; the existing public doctors rating response field remains unchanged for compatibility. The staff page run verifies the modal opens and its lazy review endpoint responds; hostile profile/rating payloads were mocked at the browser boundary. No endpoint persistence was tested or changed. See handoff Section 137.


## Clinical/admin page browser-consumer coverage - Sections 138-139 (8 November 2026)

No endpoint contract or server implementation change was made in these sections. They extend browser verification of existing GET consumers:

- Doctor workspace: `GET api/doctors/workspace-data.php?patient_id=...&appointment_id=...`; its visit-draft read is mocked in the hostile-payload runner.
- Patient EMR: `GET api/medical/get-emr-data.php?patient_id=...`.
- Admin patient table: `GET api/patients/get_all.php`.
- Admin doctor schedule and schedule history: `GET api/schedule/get.php?doctor_id=...`, `GET api/audit/get.php?entity_type=...`, and the existing hospital-settings read required by page initialization.

For the target payload endpoint(s), Chrome interception substitutes synthetic response JSON while the local QA router establishes an allowlisted `healthbridge_repair_test` session. The runner blocks every state-changing browser request. Sections 138-139 therefore verify client rendering and existing request consumers, not database mutation, production response data, or live-host endpoint behavior. See root handoff Sections 138-139.
