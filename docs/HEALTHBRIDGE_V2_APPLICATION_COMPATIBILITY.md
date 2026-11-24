> **Status update — 24 November 2026.** The cutover this inventory tracked is complete: the application runs only on the V2 schema, the V1 schema and legacy frontend were retired, and the build is deployed live. The dated record below is unchanged.

# HealthBridge v2 application compatibility inventory

**Review date:** 18 November 2026
**Status:** Local Apache web/API cutover to the actual-data V2 database completed 20 November 2026; interactive role smoke tests, CLI/worker configuration and hosted release gates remain open.
**Current runtime:** Local Apache web/API uses canonical healthbridge_v2 with all 15 selectors set to v2. CLI processes still default to V1; the retained synthetic V2 prototype and preview remain separate. See docs/HEALTHBRIDGE_LOCAL_V2_CUTOVER_20261120.md.

## Historical pre-cutover safety boundary

**Cutover update (20 November 2026):** The following bullets record the guardrails used before local cutover. They do not describe the current Apache runtime. Local Apache now uses canonical V2; the original V1 database remains unchanged, CLI workers remain on V1, and hosted resources remain untouched. See docs/HEALTHBRIDGE_LOCAL_V2_CUTOVER_20261120.md.

- Keep the current application database selection and runtime behavior on v1.
- Do not set DB_NAME to a v2 prototype/restore database in the application or deployment.
- Use only explicitly named disposable local clones for database contract tests. Reads are preferred; any write-path test must run inside a transaction and roll back.
- No main HealthBridge, NOVA Home, Alwaysdata, hosted database, credential file or secret inspection is part of this phase.
- Preserve the existing dirty worktree. Do not stage, commit, push, deploy, reset, stash or clean unrelated files.
- A v2 schema-mode switch is not permitted until all affected routes/services and the complete acceptance suite are proven.

## Repository baseline checked

- Branch: `repair/healthbridge-staged-hardening`
- HEAD: `d4dd731b7578d9309d8eca50ec57bb319459dade`
- Worktree: 255 modified/untracked paths at the start of this compatibility review; staged paths: 0.
- Scan scope: PHP files under `api/`, `services/`, `includes/` and `pages/`; vendor, v2 migration scripts and tools were excluded.
- Static SQL-call scan found the following counts of distinct PHP files containing direct SQL references for each table family. These are caller-discovery counts, not runtime coverage:

| Legacy table | PHP files |
|---|---:|
| appointments | 36 |
| doctors | 16 |
| ratings | 11 |
| visit_notes | 14 |
| visit_workflow | 13 |
| prescriptions | 13 |
| user_preferences | 6 |
| visit_drafts | 5 |
| doctor_schedule_settings | 4 |
| doctor_schedule_weekly | 2 |

The counts overlap because individual files touch several domains. A full rewrite must also cover the non-core tables identified in the v2 redesign and ETL report.

## Confirmed v1-to-v2 contract changes

### Doctor identity and profile

The application already treats `users.id` as the canonical doctor identifier in booking and schedule endpoints. In the inspected source fixture, all 32 legacy `doctors.id` values differed from their corresponding `doctors.user_id`; code must not expose or join on the surrogate profile ID as though it were the user ID.

| v1 application contract | v2 contract | Required adapter behavior |
|---|---|---|
| `doctors.user_id` | `doctor_profiles.doctor_user_id` | Keep the public/API doctor ID equal to `users.id`. |
| `doctors.name` | `users.name` | Read/write the account name once. |
| `doctors.specialty` | `doctor_profiles.specialty` | Preserve legacy display fallback while department_id is authoritative. |
| `doctors.exp` | `doctor_profiles.experience_years` | Translate the field name and preserve integer units. |
| `doctors.emoji` | `doctor_profiles.profile_icon` | Translate display field; retain the original legacy value in its archive. |
| `doctors.rating` cache | `v_doctor_rating_summary` over `ratings` | Compute rating from source facts; never write a stale cache in v2. |
| `doctors.available` plus schedule flag | `doctor_profiles.accepts_new_patients` and `doctor_schedule_settings.is_available` | Keep profile visibility/acceptance and operational schedule availability as distinct concepts until product semantics are explicitly settled. |

Relevant direct callers include `api/doctors/get.php`, `get_all.php`, `profile.php`, `add.php`, `update.php`, `delete.php`, `toggle-status.php`, `toggle-availability.php`, `submit-rating.php`, `api/departments/assign-doctor.php`, `services/DepartmentService.php` and `services/DashboardAnalyticsService.php`.

### Schedule and availability

| v1 | v2 | Compatibility requirement |
|---|---|---|
| `doctor_schedule_settings.doctor_id` | `doctor_schedule_settings.doctor_user_id` | Same account identity, new column name. |
| `appointment_duration` | `appointment_duration_min` | Preserve the API's `appointment_duration` response field. |
| `break_start/end` VARCHAR(5) | `break_start/end` TIME | Normalize values to the existing HH:MM API representation. |
| `doctor_schedule_weekly` | `doctor_weekly_schedules` | Translate table and FK names. |
| weekly row surrogate `id` | composite PK `(doctor_user_id, day_of_week)` | Do not rely on a persistent weekly row ID; the current UI is driven by day_of_week. |

The shared `ScheduleService` owns schedule reads, writes, validation and slot generation. `api/schedule/get.php`, `api/schedule/update.php`, `api/doctors/get-availability.php`, `get-public-availability.php` and `toggle-availability.php` depend on it. The frontend expects `doctor_id`, `appointment_duration`, `max_appointments_per_day`, `is_available`, and weekly day/time fields. Keep this response DTO stable while the underlying SQL differs.

A schedule-only port is not sufficient: public availability calls slot generation, which queries bookings and reschedule holds. Do not enable the v2 schedule path for availability/booking until those reservations are migrated too.

### Appointments and rescheduling

v1 `appointments` contains `doctor_id`, `user_id`, `date`, text `time`, `appointment_time_range`, a title-cased `status`, and embedded pending/requested reschedule columns. v2 uses `doctor_user_id`, `patient_user_id`, `starts_at`, `duration_minutes`, lowercase `booking_status`, display snapshots and a separate `appointment_reschedule_requests` child table.

The old `Reschedule Requested` status is not a direct v2 booking status. The synthetic ETL represents the booking as confirmed and preserves the request separately. Runtime compatibility must therefore query both the appointment and active request rows when deciding which time ranges are reserved. At minimum, pending/suggested requests must preserve the original booking and the currently proposed/requested alternative according to the original workflow state. This logic needs a transition-by-transition mapping from `RescheduleService`; it must not be approximated by a simple `starts_at` lookup.

Critical paths include `api/appointments/book.php`, `get-available-slots.php`, `get.php`, `get-doctor.php`, `approve.php`, `decline.php`, `update.php`, reschedule endpoints, `services/ScheduleService.php` and `services/RescheduleService.php`.

### Clinical, ratings, preferences, authentication and audit

- v1 `visit_workflow` plus `visit_notes` map to v2 `clinical_encounters`; `visit_drafts` maps to `encounter_drafts`. This is a lifecycle mapping, not a table rename. Workflow transitions, note ownership and draft isolation must all be preserved.
- v1 ratings use `user_id` and `doctor_id`; v2 uses `patient_user_id` and `doctor_user_id`, with a unique rating per appointment and a derived aggregate view.
- Mixed legacy preferences are separated into `user_preferences` and `admin_preferences`. Existing settings API fields must remain stable even when persisted separately.
- v1 login reads `users.password`; v2 stores `users.password_hash`. Login, registration, password reset, session versioning, verification and the registration worker need an explicit account/auth adapter before v2 can serve authenticated routes.
- v1 audit writers/readers use multiple legacy audit tables/services. v2 new writes belong in `audit_events`; old audit families are read-only archives. The historical Phase 8 table-shape collision remains an independent migration blocker.

## Defects and migration hazards to carry into implementation

1. **Doctor ID mismatch in analytics (known correctness defect).** `DashboardAnalyticsService::getDoctorAnalytics()` selects `doctors.id` and compares it with `appointments.doctor_id` / `ratings.doctor_id`, which are user IDs. In the inspected 32-doctor fixture this produced wrong appointment counts for 31/32 and wrong review counts for 29/32 profiles. Use `doctors.user_id` for v1 comparisons and the v2 profile key/aggregate view for v2.
2. **Availability can diverge.** Public doctor listing prefers `doctor_schedule_settings.is_available`, but dashboard KPIs read `doctors.available`. The schedule editor updates only schedule settings; the toggle endpoint updates both. Define one authoritative operational field and make all reads/writes agree.
3. **Legacy profile ID leaks into department operations.** `DepartmentService` currently accepts `doctors.id`; the API route performs a manual translation from the user ID. v2 has no separate doctor profile ID, so service parameters should use doctor_user_id consistently.
4. **Slot reservation semantics move tables.** v2 reschedule requests are independent rows. Booking tests must cover concurrent booking, pending requests, suggested requests, approval, rejection, cancellation and rebooking.
5. **Profile creation is multi-entity.** v1 doctor creation inserts a user plus a doctors row and preferences. v2 creation must create a user, doctor profile, schedule settings, preferences and audit/outbox records within the intended transaction boundary.

## First vertical slice and implementation order

Do not port a single schedule endpoint in isolation. The first compatibility slice is the complete **doctor directory/profile + operational availability + schedule + booking-slot reservation** path:

1. Add explicit v1/v2 repository adapters for doctor profiles and schedule settings/weekly rows. Keep the schema mode explicit and defaulted to v1; do not infer a new mode from the presence of a table.
2. Define a stable API DTO using users.id as doctor_id, the current profile fields, normalized schedule settings and seven day rows.
3. Port appointment range reads and reschedule-request reservation logic into dedicated repositories. Keep getAvailableSlots/validateBookingSlot on v1 until the v2 reservation implementation has equivalent tests.
4. Move doctor list/profile/create/update/archive, department assignment, ratings summary and dashboard analytics onto the repositories. Retain endpoint response shapes and authorization checks.
5. Add guarded CLI contract tests for both the legacy clone and v2 prototype. Test read DTO parity and transaction-rollback writes without leaving fixture changes behind.
6. Only after this slice passes should booking/reschedule endpoints be adapted. Clinical encounter, EMR/prescriptions, notifications/outbox, authentication/registration workers and audit display remain separate compatibility slices.

## Required acceptance tests

- Doctor IDs returned by public, admin and schedule endpoints always equal `users.id`; no code depends on legacy `doctors.id`.
- Doctor list, profile, create, edit, department reassignment, archive/reactivate and hidden/inactive filtering preserve current authorization and response contracts.
- Schedule get/update/reset round-trips all seven days, settings, breaks, availability and default rows on both schema modes. No read operation writes default rows.
- Profile acceptance and schedule availability remain distinct and produce a consistent public-booking decision.
- Slot validation accounts for stored appointment duration, original appointments, pending and suggested reschedules, hospital hours, breaks, daily capacity and excluded appointment IDs.
- Concurrent booking attempts for the same slot cannot both succeed.
- Rating submission remains one-per-appointment; aggregates use rating facts and match public/admin/dashboard displays.
- Dashboard analytics counts each doctor by canonical user identity.
- All test scripts enforce loopback host plus an exact disposable DB allowlist before opening a connection. Mutation tests roll back.
- PHP lint, targeted contract tests and the existing relevant regression suites pass. Full suites run at the end of the milestone, not repeatedly after unrelated edits.

## Current result and next checkpoint

This review identified the real caller surface and the schema/API differences needed for the first slice. It did not edit application PHP/JS, execute v2 against the application, connect to a database, or change database selection. No database, hosted resource, secrets, Git branch, commit or index was changed.

**Next implementation task:** create the explicit doctor-profile/schedule repository contracts and guarded dual-schema contract tests, then integrate them without changing the default v1 runtime. The booking/reschedule reservation adapter is a hard dependency before v2 public availability or booking can be enabled.


## 8. First opt-in repository implementation — 18 November 2026

Added three schema-explicit repository adapters. They are not automatically selected and are not yet injected into existing API endpoints or ScheduleService; the app remains on v1.

### Implemented classes

- `services/Repositories/DoctorProfileRepository.php`
  - Requires explicit `v1` or `v2` schema mode; no table-presence guessing.
  - Uses `users.id` as `doctor_id` and `user_id` in both modes.
  - Preserves the public directory's legacy `id` value from `doctors.id` in v1 and `legacy_doctor_profile_state.source_doctor_id` in v2. Admin listing preserves its existing `id = users.id` contract.
  - Aliases v2 experience/icon/rating/availability values to the current `exp`, `emoji`, `rating`, `rating_count`, `available` response fields.
  - Rating aggregates in v2 come from the facts-backed `v_doctor_rating_summary`; stale legacy cached values are not reused.

- `services/Repositories/DoctorScheduleRepository.php`
  - Reads one/all schedules in the current DTO shape: `settings`, seven `weekly` day rows, `doctor_id`, `appointment_duration`, and normalized HH:MM time strings.
  - Translates v1 `doctor_schedule_weekly` / v2 `doctor_weekly_schedules`; v2 weekly `id` is deliberately null because the new key is (doctor_user_id, day_of_week).
  - Supports settings and weekly upserts under the caller's transaction; it does not commit a transaction owned by the caller.
  - On v1 writes, changes to `is_available` also synchronize the duplicate `doctors.available` flag. In v2, operational availability remains in schedule settings and is separate from profile-level `accepts_new_patients`.
  - Does not create default weekly rows during reads.

- `services/Repositories/AppointmentReservationRepository.php`
  - Exposes the existing slot-reservation DTO as start/end minutes plus appointment ID.
  - v1 retains the original appointment and embedded pending/requested reschedule reservation rules.
  - v2 reserves the base booking, the patient's `requested_starts_at`, and, for suggested requests, the doctor's `proposed_starts_at`. Terminal approved/rejected/cancelled requests add no extra holds; the moved/current appointment remains reserved.
  - Supports excluding the current appointment during reschedule-slot validation and de-duplicates repeated reservation ranges.

### Runtime evidence

All three repositories and all three test scripts pass PHP 8.5.12 syntax checks.

- `tools/test_v2_compatibility_contract.php`: **11/11 assertions passed** against only the exact loopback fixtures `healthbridge_hb_auth_email_verify_20261114` and `healthbridge_v2_prototype_20261118`. The check compared doctor identity and public/admin visibility, profile fields, schedule settings and all seven days, reservation-date coverage, slot ranges, reschedule-state counts and appointment exclusion. It compared **448 doctor/date combinations** with zero reservation differences. The test is read-only.
- The existing fixture contains 12 `requested` reschedules and no `suggested` rows. The absence is reported as a coverage warning rather than assumed.
- `tools/test_v2_schedule_rollback.php`: passed for both fixtures. The v1 settings + duplicate-availability update and the v2 settings + weekly-row upsert were run within caller-owned transactions; both were rolled back, and the pre-test DTO/raw v1 availability flag matched exactly afterwards. The v1 test avoids weekly-row insert attempts so it does not consume its AUTO_INCREMENT counter.
- `tools/test_v2_reschedule_transitions_rollback.php`: **suggested, approved, rejected and cancelled** states all produced matching v1/v2 reservation ranges. It temporarily seeded the suggested and terminal states within transactions and rolled both fixtures back; the original pending-request records were verified restored.
- Two existing v1 cached ratings (doctors 22 and 23) have no rating facts; v2 correctly returns a facts-derived zero rather than reusing the cached 4.5 value. This is an intentional, visible data-contract difference and is not treated as a test failure.

### Remaining integration gates

The repository adapters are now consumed by `ScheduleService` when a caller explicitly selects `v1` or `v2`. Its one-argument constructor remains v1, so all existing endpoints continue using the v1 schema unchanged. API endpoints, JavaScript contracts, doctor profile mutation and department assignment paths are not yet switched to the repositories.

Remaining work:
1. adapt doctor create/update/archive, profile settings and department assignment using canonical doctor user IDs;
2. build the complete booking/reschedule transaction adapter and prove concurrent booking serialization, row locks and active request reservation behavior against v2;
3. run API response/auth/browser regression checks with a dedicated, explicit schema-mode test harness;
4. keep auth, clinical encounters, prescriptions, rating writes, preferences, audit, notifications and workers as separately scoped domain adapters;
5. decide whether the legacy `accept_new_patients` preference should block server-side new bookings. Current booking/reschedule callers validate operational schedule availability; a source scan found no direct consumption of the separate preference in those paths. Do not silently conflate it with schedule availability.

No database changes were committed. Read-only contract tests made no writes; mutation tests used caller-owned transactions, rolled back and verified source state restoration. No current endpoint or app DB configuration was changed; no production/hosted database, main app database, NOVA Home DB, .env or secret was accessed. No Git staging, commit, push or deploy occurred.

**Current disposition:** opt-in v1/v2 doctor profile, schedule and reservation repositories exist; `ScheduleService` now supports explicit v2 mode with 9/9 service-level contract assertions passing on the synthetic fixture. All existing callers still default to v1. Doctor/profile mutation and endpoint integration remain open.


## 9. Explicit dual-schema ScheduleService — 18 November 2026

The next integration step is implemented without changing existing endpoint construction:

- `ScheduleService::__construct(PDO $db, string $schema = 'v1')` accepts only explicit `v1` or `v2`; invalid schema values throw. Existing one-argument callers stay on v1.
- Schedule reads/listing delegate to `DoctorScheduleRepository`; schedule update, availability and reset writes use its transaction-aware save path; booked ranges delegate to `AppointmentReservationRepository`.
- The old v1-only write helpers were removed. Schema-specific schedule/appointment queries now live in the repositories; remaining `ScheduleService` logic for slot generation, hospital-hour intersection, capacity and validation is shared PHP logic.
- Hospital opening/closing TIME fields are formatted to the unchanged HH:MM DTO in v2.
- `updateSchedule` respects caller-owned transactions and only commits/rolls back when it opened the transaction. This preserves the existing booking/settings endpoint transaction boundaries.
- v1 availability writes update both legacy `doctor_schedule_settings.is_available` and the duplicate `doctors.available` flag. v2 writes only the operational schedule setting; `doctor_profiles.accepts_new_patients` remains separate.

### Service contract evidence

Added `tools/test_v2_schedule_service_contract.php`. It passed **9/9 assertions** read-only against the two exact loopback fixtures:
- 32 doctors had matching `getSchedule`, `getWorkingSchedule`, `getAllSchedules`, and public availability/next-slot output.
- All **448 doctor/date reservation combinations** matched booked ranges, available-slot generation and booking validation.
- Hospital hours were identical as HH:MM.
- A one-argument `ScheduleService` constructor was verified to retain v1 behavior.
- No fixture writes were issued.

Updated `tools/test_v2_schedule_rollback.php` to call the `ScheduleService` façade rather than the repository directly. It passed: v1 schedule update + availability toggle rolled back with both legacy flags restored; v2 settings/weekly writes rolled back; v2 reset-to-default produced seven expected weekday/weekend rows inside a transaction and rolled back exactly. The v1 weekly upsert is deliberately not used in this clone test because a rolled-back duplicate insert can still consume an AUTO_INCREMENT value.

### Separate profile acceptance vs operational schedule availability

The source public doctor SQL gives schedule availability priority via `COALESCE(doctor_schedule_settings.is_available, doctors.available)`. v2 now emits the same public `available` DTO from `COALESCE(doctor_schedule_settings.is_available, doctor_profiles.accepts_new_patients)`, while separately exposing `accepts_new_patients` and `is_available`. This does not silently change the current UI's schedule-based availability contract.

The read-only diagnostic `tools/inspect_v2_availability_flag_semantics.php` found 5 doctors with schedule availability off while their saved profile preference is on; the other 27 are on for both. Added `tools/test_v2_profile_availability_semantics_rollback.php`: turning profile acceptance off for one test doctor while leaving the schedule on preserved the schedule DTO; the two transactions were rolled back and the original rows rechecked.

**Open source behavior issue:** the doctor settings screen stores `accept_new_patients` separately, but a static caller scan found that the booking/reschedule services validate operational schedule availability and do not read that preference as an authorization/business rule. This is a separate product decision: decide whether the preference must block server-side new bookings, then enforce it consistently under the existing doctor lock if approved. It is intentionally not conflated with schedule availability in this compatibility slice.

The application API endpoints still instantiate `new ScheduleService($db)` and remain on v1; no endpoint, route, JavaScript or DB configuration was switched to v2. Booking/reschedule transaction isolation and concurrent-v2 booking tests, doctor create/update/archive, department assignment, auth/clinical/etc. domain adapters, historical Phase 8 and empty-table ETL fixtures, and cutover/rollback remain open.


## 10. Doctor management mutation repositories — 18 November 2026

Added `services/Repositories/DoctorManagementRepository.php`. It requires explicit `v1` or `v2` mode and uses `users.id` as the doctor identifier for all methods. It is not connected to existing endpoints; all current API routes remain v1.

### Supported operations

- **Create doctor:** validates name/email/hash/department, verifies an active admin actor and active department, creates the user and matching profile, notification-preference row, schedule settings and seven-day schedule, writes an audit event, and enqueues new-doctor notifications for active admins. The repository accepts only a password hash, not a raw password. New schedules default to 30-minute appointments, capacity 25, Monday-Friday 09:00-17:00, weekends off and operational availability enabled.
- **Update doctor:** updates name/email/phone on the canonical account. v1 also synchronizes the duplicate `doctors.name`; v2 has no duplicate name field. Unchanged fields are preserved, unique email is checked, and actual changes are audited.
- **Assign department:** takes canonical `users.id` in both modes, locks and validates the active department and doctor profile, updates department ID, and synchronizes specialty to department name to preserve current v1 display behavior. The v1 surrogate `doctors.id` is not accepted by this repository.
- **Activate/deactivate/archive:** explicit target state, caller-owned transaction support and `session_version` increment on every actual state transition. Deactivation checks active visits, active prescriptions and unfinished drafts before proceeding; it cancels only untouched appointments, leaves clinical history and doctor profile/schedule rows intact, records audit and notification outbox entries, and reactivates without restoring cancelled appointments.

### v1/v2 semantic mapping

- v1 user password column `password` vs v2 `password_hash`.
- v1 profile `doctors` vs v2 `doctor_profiles`; the canonical user ID is used in both.
- v1 duplicate doctor profile name is updated alongside `users.name`; v2 stores the display name only in `users`.
- v1 `admin_audit` writes are mapped to v2 `audit_events`; existing archive tables in v2 remain read-only.
- v2 appointment cancellation updates `booking_status`, closes active child reschedule requests, creates `appointment_events`, and writes notifications to the outbox. v1 preserves the current `Cancelled` appointment status path.
- Active visits map from `visit_workflow` statuses to `clinical_encounters.workflow_status`; active prescriptions and doctor drafts are checked in their respective schema models.

### Rollback-only test evidence

Added `tools/test_v2_doctor_management_rollback.php`. It passed against only the exact loopback fixtures `healthbridge_hb_auth_email_verify_20261114` and `healthbridge_v2_prototype_20261118`.

For each schema the test verified:
- doctor account/profile creation, password-hash verification, canonical user ID, active department, 7-day schedule defaults and creation audit;
- update of name/email/phone with read-back parity and audit;
- department reassignment and specialty synchronization with audit;
- new account deactivation/reactivation and two session-version increments;
- unfinished clinical work guard: doctor 9 was refused deactivation in both fixtures, with account state and session version unchanged;
- archive of doctor 3 cancelled exactly four untouched appointments in each fixture; v2 active reschedule requests were closed and appointment events recorded; reactivation worked;
- caller-owned transactions remained open throughout repository calls and were explicitly rolled back by the test;
- all created rows were absent afterwards, original doctor state and appointment counts were restored, and pre-test AUTO_INCREMENT high-water values were restored exactly.

Test result: **PASS on v1 and v2**. The disposable fixtures are unchanged. No endpoint, API/JS contract, database selection or hosted resource was switched. No main/protected/NOVA database, `.env` or secret was accessed. No staging, commit, push or deployment occurred.

**Current disposition:** doctor profile/schedule/reservation repositories and doctor management mutation repository are implemented and rollback-tested. Existing route integration remains intentionally deferred until booking/reschedule locking and concurrency compatibility is tested. `DepartmentService` and existing doctor API endpoints remain v1-only; the new repository's department method takes canonical `users.id`.


## 11. Booking and reschedule transaction compatibility — 18 November 2026

Added two explicit-schema repositories. They are opt-in transaction components; current booking/reschedule API endpoints still use the v1 service implementations and have not been switched.

### AppointmentBookingRepository

`services/Repositories/AppointmentBookingRepository.php` creates appointments in either schema and preserves the existing booking rules.

- Requires canonical `doctor_user_id`, `patient_user_id`, `department_id`, date/time, actor identity and actor role.
- Lock order: doctor account (`users.id`) → doctor profile → active department → schedule validation under the doctor lock → patient account. This is the same doctor-first serialization point used by rescheduling and archive.
- Verifies active doctor, profile/department relationship (including specialty fallback if department_id is null), active department, actor authorization and active patient.
- Runs full ScheduleService slot validation after the doctor/profile/department locks.
- Writes the v1 appointment fields or the v2 `starts_at`/`duration_minutes`/snapshot/`booking_status` fields.
- Audit event and notification outbox entries are part of the same caller-owned transaction. V2 also gets an `appointment_events` row.
- The repository never dispatches the notification worker; outbox processing remains post-commit work for the eventual API facade.

### RescheduleTransactionRepository

`services/Repositories/RescheduleTransactionRepository.php` handles request, suggest, accept suggestion, decline suggestion, cancel request, approve and reject.

- Lock order: doctor account → doctor profile → appointment → active v2 request row. It deliberately does not lock the patient user row, preserving the existing patient-archive/appointment lock order.
- V1 changes stay in the legacy appointment columns. V2 creates one child row per request; the parent booking remains confirmed while requested/suggested.
- Requested time and suggested proposal are separate holds. Declining a suggestion clears only the proposal and restores requested state; accepting/approving moves the parent appointment and closes the request as approved; reject/cancel leave the original/current booking intact and close the child request.
- All v2 transitions add appointment event and audit records and write notifications to the outbox. V1 continues to map audit to `admin_audit` and uses the existing status columns.
- Parent appointments that are cancelled cannot progress even if v1 has stale embedded reschedule flags or a v2 active child request remains. The explicit parent-status guard is covered by a negative test.
- Read DTO methods `getPendingRequestsForDoctor`, `getAllPendingRequests`, and `getRescheduleHistory` flatten v2 child records to the current legacy API fields. V2 `cancelled` history maps to legacy `none`; appointments with no request history return the same null/default shape as v1.

### Test evidence

- `tools/test_v2_booking_reschedule_rollback.php` passed on both v1 and v2 fixtures. It created a booking, simulated confirmation, requested a new time, suggested an alternative, declined the suggestion, approved the original request, started a second request and rejected it, then started a third request and cancelled it. Every stage checked slot reservations and read-history state. It also verified a cancelled parent blocks an otherwise active reschedule state. Caller-owned transactions were rolled back and all affected AUTO_INCREMENT values restored.
- `tools/test_v2_booking_concurrency.php` passed with two independent PDO connections to the v2 disposable clone. Connection A held the doctor row lock and inserted a booking inside an open transaction; B received the expected InnoDB lock-wait timeout on the same doctor; after A committed, B retried and was rejected by slot validation. The test removed its synthetic appointment, audit, event and outbox rows and restored the counter values.
- `tools/test_v2_reschedule_read_dto.php` passed **5/5 assertions** read-only. It compared per-doctor pending requests, the admin pending list, request history, and the no-history default across all 12 active requests from 11 doctors.
- The doctor-department assignment lock order was corrected to match booking: doctor account → doctor profile → department. This removes the prior inverted department-first lock sequence.

No current API route, JavaScript caller or runtime database selection was switched. The new transaction repositories are not called by `api/appointments/book.php` or existing reschedule endpoints yet. Existing route response/UI integration still requires the clinical encounter/workflow, patient appointment timeline, doctor dashboard and appointment detail read adapters. Keep v1 as the runtime default.

**Current disposition:** booking and reschedule transaction components plus their pending-read DTO layer are implemented and validated against the v1 and v2 fixture models. V2 duplicate-booking serialization is demonstrated on two connections. Existing API remains v1; no claim is made that the whole booking/clinical UI is v2 compatible yet.


## 12. Clinical encounter and patient timeline compatibility — 18 November 2026

Added `services/Repositories/ClinicalEncounterRepository.php` with explicit `v1`/`v2` modes. It maps v1 `visit_workflow` + `visit_notes` + `visit_drafts` to v2 `clinical_encounters` + `encounter_drafts`. Workflow states preserve the legacy API labels; v2 `uninitialized` remains hidden as "no workflow" until the workflow is explicitly created. A note-only encounter therefore remains note-only and does not implicitly create a Waiting workflow.

The repository supports workflow get/create/bulk read/transition, note get/list/save and draft get/save/delete/cleanup. It locks the appointment row before mutations, verifies appointment/patient/doctor ownership, and respects caller-owned transaction boundaries. `VisitWorkflowService` and `VisitDraftService` are now schema-explicit facades with v1 default for all existing one-argument callers.

### Clinical access and timeline integration

- `ClinicalAccessService` accepts v1/v2 and uses the normalized v2 encounter fields plus prescription owner keys. Active requested/suggested child reschedules are not treated as ordinary confirmed pre-visit access; documented or progressed care evidence still grants continuity-of-care access.
- `PatientPortalService` accepts v1/v2. Its patient appointment timeline maps v2 starts/status/doctor/department snapshots, workflow, prescriptions, ratings and latest request into the old DTO. Migrated source time/range snapshots are preserved while the appointment remains at the source time; a runtime-rescheduled appointment uses its retained duration at the new time.
- Patient medical timeline v2 uses clinical encounters, prescription and rating facts, `audit_events` for new reschedule actions, and the read-only `legacy_admin_audit` archive for historical patient changes.

### Existing route refactors (all remain on v1 by default)

- `api/medical/add-visit-note.php` and `complete-visit.php` use `VisitWorkflowService::saveNote`.
- `api/medical/get-emr-data.php`, `get-record.php`, `print-patient-emr.php`, `api/appointments/print.php`, `api/appointments/get-doctor.php`, `api/doctors/workspace-data.php` read notes/workflows through the service facade.
- `api/doctors/submit-rating.php` gets completion eligibility through the workflow facade.
- `save-visit-draft.php` and `get-visit-draft.php` use the workflow facade for completed-state protection; draft data uses `VisitDraftService`.
- The targeted clinical routes no longer make direct SQL calls to `visit_workflow`, `visit_notes` or `visit_drafts`. Account deletion, dashboard aggregates and other non-clinical service consumers remain a later scan/integration task.

### Test evidence (disposable local fixtures only)

- `tools/test_v2_clinical_encounter_rollback.php`: **451 workflows and 216 notes** matched; patient note-list DTOs matched; synthetic tests created/updated notes, created and upgraded note-only encounters, exercised Waiting → In Progress → Ready to Complete → In Progress → Completed, blocked invalid transitions, created/updated/deleted drafts, and rolled back both fixtures plus touched AUTO_INCREMENT counters.
- `tools/test_v2_clinical_access_parity.php`: **4,256 doctor/patient pairs** matched (32 doctors × 133 patients), with 366 allowed and 3,890 denied; both transactional lock checks passed.
- `tools/test_v2_patient_appointment_timeline.php`: **486 appointments across 116 patients** matched across all timeline DTO fields and category counts.
- `tools/test_v2_medical_timeline.php`: **1,079 medical timeline events across 133 patients** matched, including event payload descriptions and aggregate event types.
- All 20 touched clinical PHP files passed `php -l`; the four clinical compatibility suites passed after endpoint refactors. All mutations were on the two exact loopback disposable fixtures and rolled back.

No endpoint was opted into v2; all existing routes use default v1 service construction. No main/protected/hosted/NOVA DB, .env or secrets were accessed. No stage/commit/push/deploy occurred.

**Next domain:** prescription compatibility (`prescriptions` + `prescription_items`). Before implementing, re-inspect `PrescriptionService.php`, all prescription endpoints/print/read DTOs, and v2 DDL. The prescription API currently depends on v1 owner columns/status casing; do not guess columns, enum states, side effects or authorization semantics.

## 13. Prescription service and consumer compatibility - 18 November 2026

Added explicit schema support to `PrescriptionService(PDO, schema='v1')` while preserving the v1 default. The service maps v2 `patient_user_id`/`doctor_user_id` and lowercase `active/completed/cancelled` states to the existing API DTO (`patient_id`/`doctor_id`, title-case states). It now covers create, update, complete, cancel, detail, appointment lookup, patient/doctor/admin lists, search/status filters, item hydration and active-prescription counts.

Creation locks doctor and patient accounts before the appointment, verifies the canonical owner pair and confirmed status, and rejects v2 bookings with an active requested/suggested reschedule child. Prescription writes keep audit and notification enqueueing within the service transaction: v1 uses `admin_audit`; v2 writes `audit_events`; both use the notification outbox. A caller-owned `completeForAppointmentInTransaction` method lets visit completion finalize prescriptions without opening a nested transaction. Time-range DTO calculation now uses the supplied PDO connection and shared `AppointmentTime` helper rather than indirectly calling global `getDB()`.

`PatientPortalService::getPrescriptions` now supports v2 owner/status fields and reads doctor specialty/rating from v2 profile/rating structures. Existing consumers consolidated onto the service include `api/medical/complete-visit.php`, `api/medical/get-emr-data.php`, `api/doctors/workspace-data.php`, `api/medical/print-patient-emr.php`, `api/doctors/delete.php` and `api/doctors/toggle-status.php`. The printed EMR uses a bounded 10-record query with item counts; doctor archive/disable checks use schema-aware active-prescription counts.

### Prescription test evidence

- `tools/test_v2_prescription_service_rollback.php` passed on the exact disposable v1 and v2 fixtures.
- Read parity: **99 prescription headers and 186 medication items** matched; patient/doctor/admin list IDs matched; Active/Completed/Cancelled filters matched; sampled patient portal cards matched.
- Mutation coverage passed in both schemas: create, replace items/update notes, complete, cancel with reason, reject a non-owner doctor, and complete inside a caller-owned transaction followed by rollback.
- The test suppressed outbox dispatch, disabled notifications for its synthetic recipients, removed all generated prescription/item/audit rows, restored preferences and restored AUTO_INCREMENT values.
- All nine changed PHP/service/test files passed `php -l`.

The remaining portal health-summary/alerts/download/search queries, settings-driven doctor deactivation SQL, and a small set of stable appointment-ID prescription existence checks remain to be adapted or reviewed. The broader route set is not yet v2-compatible.

No endpoint was opted into v2; existing constructors continue to default to v1. Only the two disposable loopback fixtures were used. No main/protected/hosted/NOVA DB, .env or secrets were accessed. No Git staging/commit/push or deployment occurred.

**Next domain:** finish the remaining patient portal aggregates and account/settings consumers, then continue the broader cross-application SQL audit. No runtime cutover until every dependent workflow and worker has explicit schema coverage and end-to-end regression evidence.

### Patient portal read compatibility checkpoint - 18 November 2026

Expanded the schema-aware PatientPortalService read layer while retaining the v1 default:

- Added v2 dashboard overview using normalized appointments, latest reschedule child state, doctor profiles/rating summary, schedule availability, shared notification counts and hospital settings.
- Added v2 medical-profile read by joining patient_profiles and patient_medical_profiles, preserving the existing portal DTO.
- Added v2 favorite/recommended doctor aggregates using doctor_profiles, doctor_schedule_settings and v_doctor_rating_summary. The schedule's is_available remains distinct from accepts_new_patients; live v2 rating aggregates are used instead of known-stale legacy rating cache values.
- The v2 favorites aggregates exclude appointments with active reschedule children to preserve the legacy confirmed-only semantics. Unspecified tie order in legacy top-three/primary-doctor queries is treated as equivalent only when the fixture proves a shared cutoff/top-count tie.
- getNotifications continues to use the shared NotificationService because both schemas expose the same notifications and user_preferences read contract; grouped notifications were verified for parity.

tools/test_v2_patient_portal_snapshot.php now exercises all 133 synthetic patients across profile completion, medical profile, dashboard overview, notification groups, favorites compatibility, the 14-field health snapshot, health alerts, insights, downloads and selected unified-search terms. The suite passed on the exact disposable v1/v2 fixtures. PHP lint passed for the service and test. The fixture-only checks did not opt in any endpoint or change the runtime database selection.

**Still open:** broader direct-SQL/API compatibility audit; authentication and account lifecycle flows; notification outbox/worker behavior and downstream consumers; settings and deletion flows; complete end-to-end regression. Keep v1 as runtime default until all workflows and workers have evidence-backed v2 coverage. No main/protected/hosted/NOVA database, .env or secrets were accessed. No Git staging/commit/push or deployment occurred.
### Follow-up API and notification-worker audit - 18 November 2026

The notification read contract is shared across v1/v2 (notifications and user_preferences). NotificationService::processOutbox() also uses common outbox/recipient/preference/notification columns, and both v1 phase-15 and v2 outbox definitions expose the columns it references. This is a structural review only: the CLI worker itself was not run because tools/process_notification_outbox.php calls getDB() and must not be allowed to use an unverified default database. A bounded delivery/retry test against the exact disposable fixtures remains open.

The wider direct-SQL scan confirmed these application consumers still need schema-aware service delegation or explicit v2 branches:
- api/settings/update.php - legacy doctor availability, visit workflow/drafts/notes, appointments, and prescriptions.
- api/patients/delete.php and api/patient/cancel-appointment.php - legacy appointment/workflow/clinical tables.
- api/doctors/delete.php and api/doctors/toggle-status.php - direct legacy workflow/draft/note/appointment queries remain despite prescription lookups already using PrescriptionService.
- api/patients/update.php - direct medical_records read/write.
- services/DashboardAnalyticsService.php - direct legacy doctors, appointments, visit_workflow and medical_records aggregates.

A tested schema-aware DoctorManagementRepository already exists at services/Repositories/DoctorManagementRepository.php, but these API endpoints have not yet been wired to it. Do not interpret the repository's passing rollback tests as proof that the HTTP endpoints are v2-compatible. No runtime route or database switch was made.

## Doctor archive and status API integration checkpoint — 19 November 2026

The admin doctor archive and toggle-status endpoints now delegate lifecycle mutations to `DoctorManagementRepository` rather than maintaining separate legacy SQL workflows.

Implemented:
- Added an atomic `toggleDoctorActive()` operation. It locks the doctor row before choosing the next status, preserving toggle semantics when two administrators act concurrently.
- Added a distinct `archiveDoctor()` operation. It retains the historical `archive` audit action, preserves the archive-specific audit description, keeps appointment-cancellation notices transactional, and does not add the separate doctor account-status notice used by ordinary activation/deactivation.
- Kept `setDoctorActive()` as the explicit enable/disable operation. The archive-only audit override is private to the repository transition implementation.
- Replaced duplicated lifecycle SQL in `api/doctors/delete.php` and `api/doctors/toggle-status.php` with repository calls. Existing POST input validation, response fields, status/error behavior, and post-commit best-effort outbox dispatch are retained.
- Both HTTP endpoints explicitly instantiate the repository in `SCHEMA_V1` mode. This is intentional: current runtime remains v1, and this checkpoint does not introduce a schema-mode switch or perform a cutover. The repository v2 branch remains separately exercised by disposable-fixture tests.

Validation:
- PHP lint passed for `DoctorManagementRepository.php`, both endpoint files, and `test_v2_doctor_management_rollback.php`.
- `php tools/test_v2_doctor_management_rollback.php` passed against `healthbridge_hb_auth_email_verify_20261114` and `healthbridge_v2_prototype_20261118`.
- Contract coverage includes create/update/department assignment, atomic deactivate/reactivate, unfinished-clinical-work blocking, archive audit action (archive remains archive and does not create a deactivate event), untouched appointment cancellation, v2 reschedule cleanup/events, caller-owned transaction rollback, and AUTO_INCREMENT restoration.
- Both fixture runs ended with `RESULT=PASS`; fixture transactions were rolled back and AUTO_INCREMENT counters restored.

Not covered by this checkpoint:
- No live HTTP request harness was run against the endpoints. Their syntax and repository contract were verified, but end-to-end auth/session/JSON response behavior still belongs in the later API regression pass.
- Outbox delivery/retry remains a separate worker validation task. Dispatch here remains best-effort after the transactional outbox write.
- The endpoints are not yet selectable between v1 and v2 at runtime.

Next application-integration areas remain patient account update/archive/cancellation, settings/availability, dashboard analytics, notification worker behavior, and the final cross-module regression/cutover plan.


## Patient account and cancellation integration checkpoint — 19 November 2026

Added `services/Repositories/PatientManagementRepository.php` as an explicit v1/v2 adapter for admin patient profile updates, admin password resets, patient archive, and patient self-cancellation.

Implemented and wired:
- `api/patients/update.php`: profile/password mutations now use the repository. Identity, demographic, status, optional password hash, session-version, audit and notification-outbox writes are transactionally coordinated. The v2 demographic mapping targets `patient_profiles` (gender/date_of_birth), not `patient_medical_profiles`. The existing v1 controller-side read/validation/duplicate checks remain for now.
- `api/patients/delete.php`: delegates archive to the repository. It disables access, increments `session_version`, retains the patient/clinical chart, and cancels only bookings without clinical evidence.
- `api/patient/cancel-appointment.php`: delegates patient-owned appointment cancellation. The repository preserves doctor-first lock order, rejects cancelled/non-cancellable/started/completed bookings and unresolved reschedules, and writes audit + notification outbox data. V2 additionally records `appointment_events`.

V2-specific behavior:
- Archive maps demographics/account fields to the normalized schema, cancels only appointments without encounters/drafts/prescriptions/clinical evidence, closes active reschedule child requests and writes `patient_account_archived` appointment events.
- Patient self-cancellation uses `booking_status`, normalized encounter/request tables, v2 `audit_events`, and a `patient_cancelled` appointment event.

Validation:
- PHP lint passed for `PatientManagementRepository.php`, `api/patients/update.php`, `api/patients/delete.php`, `api/patient/cancel-appointment.php`, and `tools/test_v2_patient_management_rollback.php`.
- `php tools/test_v2_patient_management_rollback.php` passed against `healthbridge_hb_auth_email_verify_20261114` and `healthbridge_v2_prototype_20261118`.
- Evidence includes patient identity/demographic updates, audit writes, password hash verification and one session-version increment, future patient cancellation, v2 appointment event, patient archive, active reschedule-child cleanup, caller-owned transaction rollback, and AUTO_INCREMENT restoration.
- Both fixtures ended with `RESULT=PASS`. Test appointment selected in each fixture: 381. Synthetic changes were rolled back.

Boundaries:
- The three controllers remain explicitly pinned to `SCHEMA_V1`; v2 runtime routing/cutover is not enabled.
- `api/patients/update.php` still performs its initial patient read and pre-validation duplicate checks using v1 `medical_records` SQL. Its write path is repository-backed, but the controller is not yet schema-mode-neutral for HTTP v2.
- No end-to-end HTTP request harness was run; this checkpoint verifies repository contracts and controller syntax, not session/HTTP JSON behavior.
- Notification delivery/retry through the CLI outbox worker remains untested. Writes are durable; dispatch in the patient self-cancel/update flows remains best-effort after commit.

Next application areas remain settings/availability endpoints, dashboard analytics, auth/account lifecycle review, notification worker tests, and the final cross-module regression/cutover review.


## Doctor settings and dashboard analytics checkpoint — 19 November 2026

Added `services/Repositories/DoctorSettingsRepository.php` and connected the doctor settings write branch in `api/settings/update.php`.

Doctor settings mapping:
- V1 retains the existing preference columns and synchronizes `doctors.available` with `accept_new_patients`, while consultation duration is also propagated to `doctor_schedule_settings.appointment_duration` so booking reads use the new value.
- V2 maps `accept_new_patients` and `profile_visible` to `doctor_profiles`, and consultation duration to `doctor_schedule_settings.appointment_duration_min`.
- V2 operational schedule `is_available` remains separate from the doctor profile's `accepts_new_patients`.
- The legacy settings form exposes one global `working_hours_start/end` pair. V2 holds distinct weekday ranges in `doctor_weekly_schedules`; the repository explicitly rejects the old global-hours write in v2 instead of copying one range to all seven days.
- The settings controller remains explicitly pinned to V1. Its existing AuditService include remains available for other settings sections.

Dashboard analytics:
- `DashboardAnalyticsService` now accepts explicit `v1`/`v2` schema mode (default V1) and returns the existing top-level DTO in either mode.
- V2 KPI/appointment queries use `appointments.starts_at` and normalized booking status. Pending/suggested reschedule child requests are represented as `Reschedule Requested` and excluded from confirmed counts/completion-rate denominator, matching the legacy state distinction.
- Patient distributions use `patient_profiles`; doctor rankings use `doctor_profiles` and live `v_doctor_rating_summary`; availability uses `doctor_schedule_settings`; department metrics use normalized profile/appointment FKs; recent activity maps `audit_events`; system status keeps shared hospital/notification tables.
- `api/admin/dashboard-analytics.php` explicitly instantiates the service in V1 mode. No runtime v2 routing or cutover is enabled.

Validation:
- PHP lint passed for `DoctorSettingsRepository.php`, `DashboardAnalyticsService.php`, `api/settings/update.php`, `api/admin/dashboard-analytics.php`, and both new contract tests.
- `tools/test_v2_doctor_settings_rollback.php` passed on both approved v1/v2 fixtures. It checks field mapping, audit writes, the separation of acceptance and operational availability, rejection of unrepresentable global-hours changes in v2, transaction rollback, and audit AUTO_INCREMENT restoration.
- `tools/test_v2_dashboard_analytics_contract.php` passed on both fixtures. It checks stable DTO sections/fields, read-only aggregate values against direct fixture SQL, and recent-activity limits.
- Both doctor-settings fixture transactions were rolled back. Dashboard analytics checks were read-only. No fixture state was intentionally changed.

Remaining boundaries:
- `api/settings/get.php` and `assets/js/pages/settings.js` still assume V1 preferences and one global start/end time. They require a separate V2 read/UX adaptation to show the seven-day schedule before any V2 settings route can be enabled.
- No HTTP/session request harness was run for these endpoints; this checkpoint is repository contract + analytics read-contract + PHP syntax evidence.
- Dashboard service supports V2 internally but its HTTP controller remains V1-only.
- Notification worker delivery/retry remains open, as does final application regression/cutover readiness.


## Self-deactivation / account lifecycle checkpoint — 19 November 2026

Added `services/Repositories/AccountLifecycleRepository.php` and replaced the duplicated legacy SQL in the `delete_account` branch of `api/settings/update.php` with a repository call. The HTTP endpoint explicitly uses `SCHEMA_V1`; no runtime v2 route or cutover was added.

Preserved policy and behavior:
- Verifies the current authenticated user's password using the schema-specific password column (`users.password` in v1, `users.password_hash` in v2).
- Serializes active-admin self-deactivation by locking the active-admin set in deterministic ID order before locking the requesting account. The last active administrator cannot deactivate themselves.
- Blocks doctor self-deactivation while an active visit, active prescription, or unfinished visit draft still needs clinical responsibility.
- Cancels only appointments without clinical evidence for patient/doctor self-deactivation; preserves encounter/chart evidence.
- In V2, closes active reschedule child requests on cancelled appointments, records actor-specific `appointment_events`, writes audit to `audit_events`, and maps session revocation to `session_version`.
- Doctor cancellation notices are placed in the outbox in the same transaction and dispatched best-effort after commit. Patient/admin self-deactivation does not send a doctor cancellation notice.

Validation:
- PHP lint passed for the new repository, `api/settings/update.php`, and `tools/test_v2_account_lifecycle_rollback.php`.
- The rollback-only contract passed on both approved disposable fixtures.
- Verified patient and doctor self-deactivation, four untouched appointment cancellations per fixture, last-active-admin guard, unfinished-doctor-clinical-work guard, v2 event/audit mapping, session version increment, caller-owned transaction rollback, and AUTO_INCREMENT restoration.
- Both fixture runs ended with `RESULT=PASS`; all mutations were rolled back.

Boundary:
- This is repository + controller syntax/contract evidence, not an HTTP/session-level test.
- The `settings/get.php` endpoint and settings frontend still assume v1 preference fields and one global working-hours range. Weekly-hours read/UI adaptation remains necessary before V2 settings cutover.
- Outbox delivery/retry worker behavior still needs an isolated test harness.


## Authentication, registration and notification-worker checkpoint — 19 November 2026

### Authentication credentials and password resets
Added `services/Repositories/AuthCredentialRepository.php` and `services/Repositories/PasswordResetRepository.php`.
- Credential lookup maps V1 `users.password` and V2 `users.password_hash` to a stable `password_hash` DTO field. Login HTTP continues to instantiate explicit V1 mode.
- Password-reset repository maps V1 `password_resets` to V2 `password_reset_tokens`, whose required `token_hash` column has no legacy plaintext `token` column.
- Both schemas store SHA-256 token hashes only, retain the 60-minute expiration and per-email/IP limits, invalidate older tokens when a newer request is issued, consume every outstanding token on successful reset, and increment `session_version` exactly once.
- A missing/inactive account still produces a generic response; V2 stores an undisclosed random hash already marked used because the v2 token hash column is NOT NULL.
- A mail-delivery failure invalidates the just-created token without leaving a usable reset link. In V2, invalidation is by `used=1` because token_hash is non-nullable.
- Forgot/reset/login HTTP routes explicitly remain pinned to V1. No runtime v2 auth cutover occurred.

### Registration intake
`RegistrationIntakeService` now requires an explicit `v1`/`v2` schema argument (default V1); all current HTTP registration/verification/resend routes and the current CLI worker explicitly use V1.
- V2 registration creation writes `users.password_hash`, `patient_profiles`, and `patient_medical_profiles`; matched governorate/city IDs are assigned only after exact lookup, while original location text is retained.
- V2 registration audit writes to `audit_events`; V1 keeps the original `users.password`, `medical_records`, and `admin_audit` implementation.
- Registration queue and email-challenge state machine use the shared v1/v2 table contract. The V2 email-verification challenge confirmation path is tested for one-time consumption, sibling-token revocation, and queue release.
- No real registration email, resend email, or password-reset email was sent during this checkpoint. The existing configured mail workflow was not re-tested; tests use synthetic local data and a process-only fake encryption key only for direct service construction.

### Notification outbox worker
Added `tools/test_v2_notification_outbox_contract.php`. The production `tools/process_notification_outbox.php` was not run because it uses the default `getDB()` connection.
- Fixture-only harness verified successful delivery to in-app `notifications`, missing-recipient skip, disabled-preference skip, retry progression through eight attempts, and terminal failure.
- A temporary failure trigger is created only on the current allowlisted local fixture, then dropped in `finally`. Synthetic outbox/notification rows are removed, the recipient preference is restored, and both AUTO_INCREMENT counters are restored.
- Preflight refuses to execute if existing old delivered/skipped rows could be deleted by the worker's retention cleanup.
- Contract passed on both approved disposable fixtures.

Validation:
- PHP lint passed for `RegistrationIntakeService.php`, both auth repositories, changed login/forgot/reset/register/confirm/resend controllers, the registration CLI worker, and all four new auth/registration test harnesses.
- `test_v2_auth_credential_contract.php` passed on v1 and v2 (read-only).
- `test_v2_password_reset_contract.php` passed on v1 and v2 (token invalidation/rotation/rate limit/reset/session version/all token consumption; credentials and rows restored).
- `test_v2_registration_patient_aggregate_rollback.php` passed on v1 and v2 (account, normalized profiles, audit, outbox; transactions and counters restored).
- `test_v2_registration_email_challenge_contract.php` passed on v1 and v2 (single-use confirmation, sibling revocation, queue release and cleanup).
- `test_v2_notification_outbox_contract.php` passed on v1 and v2 (delivery/skip/retry/cleanup).

Remaining boundaries:
- No HTTP/session-level request harness has been run for login/forgot/reset/register endpoints. These results are repository/service contracts plus controller syntax checks, not full endpoint E2E evidence.
- The actual registration `processBatch()` worker and SMTP delivery were not executed in these tests. Only the registration account-creation helper and email challenge confirmation method were exercised directly with synthetic fixture state.
- Current routes and worker remain explicitly V1; v2 repository/service paths are tested but not enabled.
- Settings GET/frontend weekly hours, patient update's v1 pre-read, admin dashboard runtime route, broader direct-SQL scan, and final application regression/cutover readiness remain open.


## Settings GET and weekly schedule UX checkpoint — 19 November 2026

Completed the schema-aware settings read DTO and the conditional weekly schedule editor.

Repository:
- `DoctorSettingsRepository::getDoctorSettings()` now returns a stable `preferences` DTO plus `weekly_schedule`.
- V1 read maps `accept_new_patients` from the legacy doctor profile and `operational_available` from `doctor_schedule_settings.is_available`; these are no longer conflated.
- V2 read maps `accept_new_patients`/`profile_visible` from `doctor_profiles`, consultation duration and operational availability from `doctor_schedule_settings`, and all seven weekday rows through `ScheduleService`.

Settings HTTP:
- `api/settings/get.php` includes `weekly_schedule` in the response and calls the settings repository. It explicitly selects V1 at runtime, so the returned weekly schedule remains null and the existing global-hours settings remain active today.
- `api/settings/update.php` doctor writes now delegate to `DoctorSettingsRepository` rather than maintaining duplicate legacy mutation SQL. It explicitly selects V1. Its V2 branch supports a full `weekly_schedule` array; global start/end writes remain V1-only.
- Admin preference read/write has a dormant V2 mapping to `admin_preferences`, but the controller remains V1.

UI:
- Added a weekday-by-weekday editor to the settings modal and standalone settings page. It only appears when the API returns exactly seven weekly schedule rows; otherwise the existing V1 global-hours inputs remain visible.
- The editor submits each weekday and working flag as one `weekly_schedule` payload; time inputs are disabled for nonworking days. No global hours are sent when the weekly editor is active.
- Added responsive styling for the weekly schedule editor.

Validation:
- PHP lint passed for `DoctorSettingsRepository.php`, `api/settings/get.php`, `api/settings/update.php`, and `tools/test_v2_doctor_settings_rollback.php`.
- JavaScript `node --check assets/js/pages/settings.js` passed.
- `php tools/test_v2_doctor_settings_rollback.php` passed on both approved fixtures. It verifies V1 profile acceptance versus schedule availability read mapping, V2 profile and operational mappings, weekly schedule updates and readback, all-seven-days-off validation failure without schedule mutation, rejection of global-hours writes in V2, and caller-owned rollback/counter restoration.

Boundary:
- Both settings HTTP controllers remain pinned to V1. The weekly editor is implemented but dormant until an approved V2 route/cutover returns the V2 DTO.
- No browser-level/HTTP session test was run. Current production V1 settings UI and route remain unchanged in behavior.
## Clinical workflow, prescriptions and EMR read checkpoint — 19 November 2026

### Schema-aware clinical appointment context
Added `ClinicalEncounterRepository::getAppointmentContext()` and `lockAppointmentContext()` as a shared appointment-scope contract:
- V1 maps `doctor_id`, `user_id`, `status`, `date`, `time`, and `doctor`.
- V2 maps `doctor_user_id`, `patient_user_id`, `booking_status`, `starts_at`, and `doctor_name_snapshot`.
- Both return a stable DTO with normalized lowercase status, doctor/patient IDs, date/time and doctor display name.
- Locking requires a caller-owned transaction. Clinical HTTP callers read the initial scope, lock doctor and patient account rows first, then lock and revalidate the appointment row.

### Clinical write/read handlers
The following handlers now use the schema-neutral context and explicit schema selection while remaining pinned to V1 at runtime:
- `api/medical/add-visit-note.php`
- `api/medical/save-visit-draft.php`
- `api/medical/delete-visit-draft.php`
- `api/medical/transition-workflow.php`
- `api/medical/complete-visit.php`
- `api/medical/get-visit-draft.php`
- `api/prescriptions/create.php`, `update.php`, `complete.php`, `cancel.php`, `get.php`, and `print.php`

Prescription creation no longer depends on V1-only appointment-column names for its preflight read. The `PrescriptionService` still performs authoritative eligibility, ownership, duplicate and write checks inside its transaction. `PrescriptionService` now exposes `SCHEMA_V1`/`SCHEMA_V2` constants, and route constructors explicitly pass V1.

`PatientManagementRepository::recordMedicalHistoryUpdate()` now owns doctor-authored timeline writes:
- V1 writes to `medical_history_updates`.
- V2 writes to `medical_history_events`.
- When the immediately preceding V2 medical-profile update already wrote the same field event atomically, the compatibility follow-up is acknowledged without creating a duplicate.
- The repository locks doctor/patient accounts and rechecks the qualifying clinical relationship.

### Medical-record read model
`api/medical/get-record.php` now uses the shared `PatientPortalService::getMedicalProfile()` mapper for V2 split profile tables and normalized V2 appointment snapshots/durations for pending clinical visits. The V1 response shape is retained. `PatientPortalService` now exposes explicit schema constants. Its hospital-hours DTO also normalizes MySQL TIME values to `HH:MM` for a stable display contract.

### Verification
- PHP lint passed for the changed clinical repository, patient repository, prescription service, listed route files, `PatientPortalService`, and the medical-record endpoint.
- `test_v2_clinical_encounter_rollback.php` passed on V1 and V2, including appointment-context parity, transaction-only locking, workflow, notes, drafts, and rollback/counter restoration.
- `test_v2_clinical_access_parity.php` passed: 32 doctors × 133 patients = 4,256 access decisions, plus both lock paths.
- `test_v2_patient_medical_profile_rollback.php` passed on both fixtures, including history-event write/duplicate suppression and cleanup.
- `test_v2_prescription_service_rollback.php` passed: 99 headers, 186 medication items, list/filter/portal parity, create/update/complete/cancel and caller-owned completion rollback.
- `test_v2_patient_portal_snapshot.php` passed for all 133 patients across profile completion, medical profile, favorites, overview, notifications, health snapshot, health alerts and insights.
- `test_v2_medical_timeline.php` passed: 1,079 event DTOs with matching event-type counts across V1/V2.
- Synthetic writes were rolled back; fixture counters and temporary test state were restored. Read-only parity tests did not mutate fixture data.

## Doctor directory, rating and EMR/workspace read adapters — 19 November 2026

### Schema-aware read projections (all controllers remain pinned to V1)
- `api/medical/get-emr-data.php`: V2 split medical profile, appointment/reschedule snapshots, clinical-completion counts, ratings, administrative correction history and last clinical update are mapped to the legacy EMR DTO.
- `api/medical/print-patient-emr.php`: V2 profile, appointment, prescription and note projections are mapped to the existing print template DTO.
- `api/doctors/workspace-data.php`: V2 appointment projection supports selected/latest visit and timeline, normalized profile, notes/workflow, prescriptions and `medical_history_events`.
- `api/doctors/get.php`: V2 public doctor directory uses `doctor_profiles`, active departments, `doctor_schedule_settings` and `v_doctor_rating_summary`.
- `api/doctors/profile.php`: V2 admin doctor profile read projection includes normalized profile, appointment and review DTOs.
- `api/doctors/get_all.php`: V2 admin doctor list maps normalized profile/rating/schedule information.
- `api/doctors/get-availability.php` and `get-public-availability.php`: explicit schema selection now reaches the dual-schema ScheduleService.
- `RatingRepository` now provides schema-aware appointment rating, doctor review list and admin rating list readers. The corresponding `get-rating.php`, `get-doctor-ratings.php`, and `get-admin-ratings.php` routes use these methods.
- `PatientPortalService` and `ScheduleService` continue to preserve the V1 DTOs; schema choice is explicit at the route boundary.

### Latest evidence
- `tools/test_v2_emr_read_model_contract.php` passed read-only on both fixtures: all 133 patients, 32 doctors, appointment/profile/review DTOs, rating and notification counts, workflows, prescriptions, notes, V2 admin audit/history reads, and directory/admin doctor projections.
- `tools/test_v2_schedule_service_contract.php` passed: 32 doctors, 448 doctor/date pairs, 9 checks, zero failures. Public availability, next slots, booked ranges and booking validation match V1/V2.
- `tools/test_v2_doctor_settings_rollback.php` passed on both fixtures.
- `tools/test_v2_schedule_rollback.php` passed on both fixtures; all writes rolled back.
- `tools/test_v2_rating_rollback.php` passed on both fixtures; synthetic ratings/notifications rolled back and counters restored.
- PHP lint passed for all changed doctor profile/directory/availability/rating and EMR/workspace route files listed in this checkpoint.

### Remaining boundary
- Every changed HTTP route still explicitly selects V1. These V2 SQL/service branches are fixture-tested; no runtime cutover occurred.
- `api/doctors/toggle-rating-visibility.php` remains V1-only because the current V2 `ratings` table has no `is_hidden` attribute. Supporting hide/restore requires an explicit schema/product decision (for example, adding a normalized moderation visibility state); it must not silently ignore the action.
- Other admin doctor mutations remain V1-pinned at the route boundary even where their repositories have V2 contracts.
- No authenticated HTTP/session-level endpoint tests have yet exercised the new V2 controller branches. Existing evidence is PHP lint plus direct read-only fixture SQL/service/repository contracts.
- No production worker delivery, SMTP send, main database access, deployment, cutover, or Git mutation occurred.


## Rating visibility compatibility - 19 November 2026

The V2 rating moderation decision is implemented as an additive schema extension:
- V2 ratings.is_public is TINYINT(1) NOT NULL DEFAULT 1, constrained to 0/1. v_doctor_rating_summary now aggregates only rows where is_public=1.
- RatingRepository::setPublicVisibility() locks the active admin and rating, validates administrator authority, updates the schema-specific flag, and writes an audit row in the same transaction (admin_audit for V1, audit_events for V2). Repeating the current state is a no-op and creates no duplicate audit record.
- Doctor-private and administrator rating lists retain hidden reviews and expose is_hidden/is_public. Doctor dashboard aggregate/counts use public reviews only while retaining hidden cards with a “Hidden from public” label.
- Patient EMR keeps each patient's own review and maps V2 visibility to the existing hidden-review badge. Public V1 doctor directory aggregation filters is_hidden when that optional legacy column exists and avoids falling back to a potentially stale cached average when all reviews are hidden.
- Admin doctor-profile review projections expose visibility in both schema branches.

Migration database/v2/006_rating_visibility.sql is recorded on the approved V2 prototype as 2.0.6-rating-visibility (SHA-256 5180e6eafac849ccf03f5fca64259874c863bd773915823a5fbe54cb22f85eed). It was applied to the disposable V2 prototype only: all 79 source/target rating IDs matched; the approved V1 fixture itself has no ratings.is_hidden column, so its 79 rows defaulted to public. The migration runner supports both an empty V2 target before ETL and a populated prototype requiring an identity-checked legacy backfill. The ETL mapping version is now 2.1.1-synthetic-etl; it maps is_hidden to is_public when the source column exists, otherwise initializes public.

Evidence:
- tools/test_v2_rating_visibility_rollback.php: V2 hide/restore, filtered public aggregate, doctor/admin/patient private-read retention, authorization, audit idempotency, caller-owned transaction behavior, rollback and audit counter restoration passed. V1 read DTO compatibility passed; the V1 mutation subtest was skipped because the disposable V1 fixture has no is_hidden column.
- tools/test_v2_rating_rollback.php: rating write/aggregate/duplicate/outbox rollback passed on both fixtures.
- tools/test_v2_emr_read_model_contract.php: 133-patient EMR/workspace read contract passed, read-only.
- tools/test_v2_patient_portal_snapshot.php: all 133 patient portal snapshots passed, read-only.
- PHP lint passed for changed repositories, routes, migration/ETL scripts and tests. Node syntax checks passed for the three changed review UI scripts.

Boundary: api/doctors/toggle-rating-visibility.php remains explicitly pinned to V1. Its repository implementation supports V1 only when that database has ratings.is_hidden; no V2 HTTP cutover or authenticated HTTP/session test has been performed. No main/protected/hosted/NOVA database, Git staging/commit/push, or deployment was touched.


### Follow-up HTTP verification - 19 November 2026

The new tools/test_rating_visibility_http.php contract exercises the actual V1 login and toggle endpoints through a loopback PHP HTTP server pointed explicitly at the disposable V1 fixture. It passed authenticated admin login/session reuse, unauthenticated 401, cross-origin 403, wrong-method 405, invalid-value 400, missing-rating 404, hide/idempotency/restore, doctor-private/admin review retention, public doctor-directory aggregate exclusion/restoration, and exactly two correlated audit records. The public doctor directory contract matches doctors by user_id (the doctor table primary key is a separate identifier).

The test temporarily added V1 ratings.is_hidden and set a temporary fixture-admin password; its cleanup restored the prior password/session version, removed its two test audit entries, restored the audit counter, dropped the temporary column, and stopped the local server. Post-run output confirmed V1_FIXTURE_SCHEMA_RESTORED; the V1 mutation path is now HTTP-tested as well as repository-tested. No V2 route was activated.

## 20 November 2026 — Remaining V2 controller compatibility audit

### Scope and method
A read-only static census reviewed 91 PHP API controller files and traced role-sensitive routes to their repositories/services. The goal was to distinguish deliberate V1 runtime selection from missing V2 implementation. No API controller, service, frontend file, application DB selector or database schema was changed.

The authenticated V2 read HTTP contract separately passed 62/62 against the disposable V2 prototype for ten copied GET controllers. The temporary app copy and sessions were removed; original routes remain V1.

### Confirmed V2 domain layer coverage
- Appointment lifecycle: AppointmentLifecycleRepository covers admin/doctor pending transitions, workflow/encounter creation, events, audit and outbox writes.
- Booking and rescheduling: AppointmentBookingRepository, AppointmentReservationRepository, and RescheduleTransactionRepository cover reservation, child-request state, requested/proposed slots, and transaction ownership.
- Doctor and patient management: DoctorManagementRepository, PatientManagementRepository, and AccountLifecycleRepository cover account/profile mutations, department assignment, status/deactivation policy and audit/outbox.
- Schedule/settings: DoctorScheduleRepository, ScheduleService, and DoctorSettingsRepository map seven-day schedules, operational availability and profile acceptance separately.
- Clinical/EMR/prescriptions: ClinicalEncounterRepository, PatientPortalService, and PrescriptionService have V2 mappings and fixture contracts.
- Ratings, auth credentials/password reset, registration intake and notification outbox have schema-aware repository/service paths and dedicated contracts.
- DashboardAnalyticsService has a V2 mapping; PatientPortalService has V2 portal/EMR projections.

### Focused verification during this audit
- tools/test_v2_compatibility_contract.php: 11/11, read-only; 448 doctor/date reservation pairs matched. The fixture has no suggested reschedules, recorded as a coverage warning.
- tools/test_v2_booking_reschedule_rollback.php: PASS on V1 and V2; synthetic booking followed request, suggestion, decline suggestion, approval, second request/suggestion/rejection, cancellation and cancelled-parent guard. Transactions were rolled back and AUTO_INCREMENT high-water values restored.
- tools/test_v2_reschedule_read_dto.php: 5/5, read-only; 12 active request DTOs across 11 doctors matched.
- tools/test_v2_authenticated_http_read_contract.php: 62/62 authenticated HTTP assertions for ten V2-compatible read controllers.

### Remaining controller/integration blockers
1. **Rescheduling HTTP integration is still V1-only.** All eight reschedule controllers (request-reschedule, suggest-reschedule, accept-suggestion, decline-suggestion, approve-reschedule, reject-reschedule, cancel-reschedule-request, get-pending-reschedules) instantiate the legacy RescheduleService. That service directly reads/writes the embedded V1 appointment reschedule columns and has no schema selector. The dual-schema RescheduleTransactionRepository is not referenced by any API controller. Repository rollback and DTO tests therefore do not close the HTTP integration gap.
2. **Generic audit is V1-only.** AuditService has no schema parameter and its write method inserts into admin_audit. V2 domain repositories that write audit_events themselves are compatible, but any V2 controller path that still calls generic AuditService needs an explicit V2 audit adapter or a schema-aware domain repository. In particular, api/schedule/update.php uses default-V1 ScheduleService and generic AuditService.
3. **Several controllers still select V1 despite V2-capable services.** This includes appointment booking/lifecycle; doctor and patient management; prescription operations; doctor schedule/availability and settings; and admin analytics. The corresponding V2 service/repository contracts are valuable evidence, but they are not authenticated HTTP acceptance.
4. **Patient portal routes remain default/V1-bound.** api/patient/dashboard.php and api/patient/appointments.php construct PatientPortalService without selecting V2. Their V2 read-model contracts do not switch these HTTP callers.
5. **Settings is only partially ported.** DoctorSettingsRepository and AccountLifecycleRepository support V2, but settings GET/update controllers remain V1. The update controller still contains direct legacy preference/password paths, and generic audit writes are V1-only. The split admin_preferences mapping is not an application-wide settings port.
6. **Authentication is contract-tested below HTTP only.** Auth credential, password reset, registration intake/email challenge and outbox contracts pass on V1/V2 fixtures, but login/register/confirm/resend/forgot/reset HTTP controllers and the registration worker still select V1. V2 authenticated login/session lifecycle has not been accepted at HTTP level.
7. **Rating moderation has a V2 data model but no V2 HTTP route selection.** V2 uses ratings.is_public and schema-aware RatingRepository; the toggle controller remains pinned to V1. The older note above saying V2 lacked a moderation flag is superseded by the 19 November rating-visibility migration and repository work.

### Recommended implementation order
1. Integrate the tested RescheduleTransactionRepository behind a compatibility service or directly into all eight reschedule controllers, keeping the default schema explicitly V1. Add authenticated HTTP acceptance on an isolated V2 test database, including role/ownership checks and exact rollback/cleanup.
2. Add a schema-aware audit adapter for generic events and inventory every remaining AuditService caller before V2 routes use it. Do not write legacy admin_audit in V2.
3. Add V2 controller-mode HTTP harness coverage for appointment booking/lifecycle, doctor/patient management, prescriptions, schedule/settings, patient portal and analytics. Use a disposable clone for any committed HTTP mutation; do not run successful mutations against the retained V2 prototype without an explicit restoration plan.
4. Add authenticated HTTP coverage for V2 login/session, registration/verification and password reset before enabling any V2 auth path. Keep SMTP and production workers out of this test phase.
5. Reconcile department assignment, direct legacy SQL callers, profile/password preference writes and rating visibility route wiring; then run the complete regression and cutover-readiness review.

### Boundary
The 91-file census was source inspection only. The focused contracts above were loopback-only and used the exact approved disposable V1/V2 fixtures; write scenarios rolled back and restored counters. No main/protected/hosted database, .env, secrets, production worker, SMTP, Git stage/commit/push, deployment or runtime cutover was used. V1 remains the active application schema.

## 20 November 2026 - Reschedule repository bridge integration

The eight reschedule API controllers now route through a schema-explicit RescheduleService facade. The facade keeps the existing V1 implementation as the default and delegates to RescheduleTransactionRepository only when SCHEMA_V2 is explicitly passed. All eight source controllers currently pass SCHEMA_V1, so there is no runtime V2 activation.

V2 facade coverage includes the seven mutations (request, suggest, accept, decline, cancel, approve, reject) plus doctor pending-list, admin pending-list and reschedule-history reads. It preserves the legacy HTTP success/message response shape. V2 audit writes remain in the repository and target appointment_events/audit_events; notification_outbox writes share the repository transaction. The facade dispatches the outbox only after its own successful transaction commits and deliberately skips dispatch when a caller-owned transaction is active.

The V1 branch of RescheduleService was preserved. A pre-edit safety copy of the service and eight controllers is stored at C:\xampp85\migration-backups\healthbridge\reschedule-v2-bridge-20261120.

New direct facade test:
- tools/test_v2_reschedule_service_facade_rollback.php: PASS. It checks three-argument/default V1 selection, explicit V1 and V2 selection, invalid-schema rejection, pending reads, full request/suggest/decline/approve/accept/reject/cancel flow, V2 audit events and final appointment schedule. Its caller-owned transaction was rolled back and touched AUTO_INCREMENT counters restored.
- PHP lint passed for RescheduleService.php and all eight affected controllers.
- tools/test_v2_booking_reschedule_rollback.php: PASS on both V1 and V2 fixtures.
- tools/test_v2_reschedule_read_dto.php: 5/5; 12 requests across 11 doctors.
- tools/test_v2_reschedule_transitions_rollback.php: PASS.
- tools/test_v2_authenticated_http_read_contract.php: 62/62 authenticated HTTP assertions (regression run after the bridge).

Remaining reschedule acceptance: authenticated HTTP mutation coverage has not yet been run with V2 selectors. Build a temporary application copy and a separate disposable V2 database clone before testing successful HTTP mutations. Do not point copied mutation routes at the retained V2 prototype. The source routes must remain SCHEMA_V1 until the separate HTTP suite and explicit cutover approval.

Next: create the disposable V2 HTTP mutation test environment, then continue with the generic AuditService V2 adapter and the remaining V1-pinned controller families.

## 2026-11-20 — Reschedule HTTP route schema routing

The existing RescheduleService V2 facade and RescheduleTransactionRepository are now selectable from all eight reschedule HTTP endpoints. The endpoints call RescheduleService::schemaFromEnvironment() rather than hard-coding V1.

- HEALTHBRIDGE_RESCHEDULE_SCHEMA is the explicit route-level selector. An absent or blank value resolves to V1; v2 opts these reschedule routes into the V2 repository; unsupported values fail closed.
- The RescheduleService constructor itself still defaults to V1. Existing non-route callers are unchanged.
- V2 facade mutations continue to translate repository outcomes to the existing {success,message} HTTP contract. The facade dispatches the transactional notification outbox only after its own mutation commits; caller-owned transactions are not dispatched.
- The eight endpoints covered are request, suggest, accept, decline, approve, reject, cancel, and pending-list.

Verification on 2026-11-20:
- PHP syntax: 11/11 touched service, repository, test, and endpoint files passed php -l.
- tools/test_reschedule_schema_selector.php: 5/5 assertions passed (V1 default, process environment opt-in, Dotenv ENV, SERVER, invalid-value rejection).
- tools/test_v2_reschedule_service_facade_rollback.php: PASS. The V2 lifecycle exercised pending reads, request, suggestion, decline, approval, acceptance, rejection, cancellation, and audit_events; synthetic rows were rolled back and AUTO_INCREMENT counters restored.

Safety boundary: the live application remains V1 by default. No V2 cutover, database migration, Git stage/commit/push, or deployment was performed. Authenticated HTTP mutation tests against a disposable V2 DB clone remain outstanding; the repository/facade rollback test is not a substitute for those route-level tests.
## 2026-11-20 — Authenticated V2 reschedule HTTP mutation acceptance

Added tools/test_v2_reschedule_authenticated_http.php. It makes a uniquely named full clone of the approved V2 prototype (base-table DDL/data, foreign keys, views and triggers), runs a temporary copy of api/includes/services with HEALTHBRIDGE_RESCHEDULE_SCHEMA=v2, and creates synthetic patient/doctor/admin sessions. The test removes the temporary app/session files and drops its clone in finally; it also compares source fixture row counts and AUTO_INCREMENT values before/after.

Result: PASS, 35 assertions.
- All eight actual reschedule endpoints were called: request, suggest, accept suggestion, decline suggestion, approve, reject, cancel request, and pending list.
- Role boundaries were checked for all routes; pending-list method guard returned 405 for POST.
- Patient request appeared in both doctor and administrator pending-list DTOs.
- Full lifecycle passed through HTTP: request -> suggest -> decline -> approve; request -> suggest -> accept; request -> reject; request -> cancel.
- Final appointment remained confirmed at the accepted suggested time; cancelled request history projected as none.
- audit_events and notification/outbox records were verified.
- Test appointment ID: 491 in the disposable clone.
- Source V2 fixture fingerprint unchanged; the unique clone was dropped; HTTP server, temporary app copy and synthetic sessions were removed.

Evidence: C:\xampp85\migration-backups\healthbridge\hb-v2-reschedule-http-20261120\RESULT.md.

This closes the rescheduling route-level V2 acceptance gate. It does not authorize or perform V2 cutover. The main application continues to default to V1. Remaining application compatibility work includes generic schema-aware audit integration and other still-V1-pinned route families.
## 2026-11-20 — Generic audit adapter and broader V2 route selection

Added includes/SchemaSelector.php and included it through includes/db.php. The shared selector is explicit per domain and fails closed on unsupported values. A missing/blank selector always resolves to V1; it never guesses from database/table presence.

AuditService and AdminAuditService now support an optional explicit schema argument (default V1). V2 writes use audit_events; V2 filtered reads, actor/action/entity options, ordering and response DTOs map V2 columns back to the established audit API shape. Existing three-argument callers remain V1. Internal audit calls from prescription and registration services and clinical visit-note/completion endpoints now pass their selected schema. The admin audit GET and department audit writes also use the audit selector.

Explicit per-domain HTTP selectors are now wired for the existing V2-capable adapters:
- appointments: booking and appointment lifecycle
- doctors: management and public/admin profile reads
- patients: management operations
- clinical encounters and patient portal
- schedules and operational availability
- prescriptions
- ratings and rating visibility
- analytics
- authentication repositories (credential lookup, registration intake, password reset)
- generic audit
- rescheduling remains on its dedicated selector

All selectors default to V1. This is routing compatibility, not a global V2 mode switch. Mixed settings controllers and CLI/background workers remain V1-pinned where their complete data/side-effect paths have not been ported.

Verification:
- 101 PHP files in the API tree plus core schema selector/service/test files passed syntax validation.
- tools/test_v2_audit_service_contract.php: PASS, 25/25 across V1 and V2; write/read DTO aliases, filter options, redaction, invalid schema and rollback/counter restoration.
- Expanded tools/test_v2_authenticated_http_read_contract.php: PASS, 82 assertions. Includes V2 EMR/portal, doctor/admin reads, ratings, generic audit, schedule/availability, prescriptions and analytics with role/session boundaries. The retained V2 fixture is read-only in this suite.
- 13 existing V2 domain regression suites passed: appointment lifecycle, doctor management, patient management, prescription service, doctor settings, analytics, schedule, auth credential, password reset, registration email challenge, rating visibility, reschedule facade and reschedule authenticated HTTP.
- Expanded tools/test_v2_reschedule_authenticated_http.php: PASS, 54/54 assertions. It runs against a unique full disposable V2 clone and copied API/includes/services tree with V2 selected in the copied server. Besides all eight reschedule endpoints, it exercises patient booking plus admin approval/decline, V2 schedule update/get and available-slot reads, and rating visibility hide/restore. It confirms audit/outbox side effects, removes the app/session/server copy, drops the clone, and verifies the source V2 fixture fingerprint is unchanged.

Evidence: C:\xampp85\migration-backups\healthbridge\hb-v2-application-http-mutation-20261120\RESULT.md.

Still not accepted for V2 runtime use:
- api/settings/get.php, api/settings/update.php, and related settings paths still have mixed direct legacy preference/password SQL. Doctor settings repository coverage does not mean these full application paths are V2-ready.
- Registration intake and notification CLI workers are still V1/default-runtime processes. They were not run, and no SMTP/email was sent by this work.
- Authentication HTTP login/registration/email verification/password-reset end-to-end was not fully accepted in this batch. Auth repository contracts passed, and HTTP selectors were added, but no mail-dependent endpoint or worker was invoked.
- Doctor/patient creation/update/status and prescription create/cancel have V1/V2 service/repository rollback coverage and V2 routing selectors, but were not all executed as authenticated HTTP mutation routes in this batch.
- Other legacy direct-SQL endpoints outside the audited selector list need a further route-by-route compatibility review.

No global V2 cutover, production database change, Git stage/commit/push, or deployment was performed. The current application stays V1 by default.
## 2026-11-20 — Final V2 API compatibility expansion (department, patient audit, settings)

The final API compatibility pass extended the shared V1-default schema selectors into department and hospital settings domains and completed two remaining read projections.

### Department service and APIs
- DepartmentService now accepts an explicit schema (V1 default; invalid schema rejected).
- V2 doctor counts and search counts read doctor_profiles joined to users using doctor_user_id; V2 doctor assignment/removal writes doctor_profiles.department_id.
- V2 appointment counts continue to read the shared appointments.department_id field.
- Department GET/create/update/deactivate/activate/delete controllers now resolve HEALTHBRIDGE_DEPARTMENTS_SCHEMA.
- The assign-doctor controller uses the already V2-capable DoctorManagementRepository with HEALTHBRIDGE_DOCTORS_SCHEMA.
- Authenticated disposable-clone HTTP tests covered V2 department create, update, doctor assignment, doctor_count projection, restoration of the original doctor department, deactivate, reactivate, and delete.

### Patient audit history
- api/patients/get-audit.php now resolves the audit storage schema.
- V2 reads use audit_events and map its actor/action/time/user columns into the existing response DTO.
- The route retains ClinicalAccessService role/scope checks, with the V2 clinical selector.
- Authenticated read tests cover admin, a doctor authorized for the patient, patient self-access, and denial of unrelated patient access.

### Settings / appointment / profile completion
- User settings GET/UPDATE and global hospital settings GET/UPDATE are schema-aware via explicit settings/hospital selectors; V1 remains default.
- HospitalSettingsService V2 conflict detection reads DoctorScheduleRepository weekly schedules, not the legacy doctor_schedule_weekly table.
- Authenticated HTTP tests verified doctor weekly-settings update, admin default-tab persistence, hospital HH:MM normalization, conflict projection and admin hospital-name update.
- General appointment list, doctor appointment list, appointment print, and admin patient profile now have V2 snapshot/starts_at projections and role-scoped HTTP reads.

### Latest verification
- Whole API/services/includes PHP syntax sweep: 135 files PASS.
- git diff --check: PASS.
- tools/test_v2_audit_service_contract.php: 25/25 PASS on V1 and V2.
- tools/test_v2_authenticated_http_read_contract.php: 115 assertions PASS. Includes V2 appointment/doctor/profile/EMR/patient-audit/department/settings/ratings/prescription/schedule/audit/analytics read coverage and role/session boundaries. Evidence: C:\xampp85\migration-backups\healthbridge\hb-v2-authenticated-http-read-20261120\RESULT.md.
- tools/test_v2_reschedule_authenticated_http.php: 86 assertions PASS against a unique full disposable V2 clone. It now covers reschedule, booking/approval/decline, schedule update/get/slots, rating visibility, doctor/admin settings, hospital settings, and department CRUD/assignment/counts. Source V2 fingerprint unchanged; clone dropped and temporary app/session/server removed. Evidence: C:\xampp85\migration-backups\healthbridge\hb-v2-application-http-mutation-20261120\RESULT.md.
- The earlier 16-suite V2 repository/service regression batch passed; subsequent department/patient-audit changes were separately verified by the expanded 115-read and 86-mutation HTTP suites.

### Remaining non-cutover gates
- Authentication registration/email-verification and password-change HTTP flows are not fully accepted end-to-end. Repository contracts pass; no SMTP endpoint or worker was invoked.
- Registration intake and notification CLI/background workers remain outside this migration slice. Do not run them against real mail services.
- Password change through settings, self-deactivation through settings, and every doctor/patient/prescription mutation endpoint were not all accepted at the HTTP layer; service/repository contracts exist for several but are not equivalent to route-level acceptance.
- Contact/support/settings endpoints backed by shared tables were not exhaustively retested. Re-run a full application QA after the remaining acceptance work.
- No global V2 cutover, production database change, Git staging/commit/push, or deployment. All HTTP mutation tests used disposable clones and main runtime remains V1 by default.

## 20 November 2026 - Historical Phase 8 ETL compatibility boundary

The V2 application compatibility work and the existing synthetic ETL acceptance remain valid for their documented Phase 10+ source clone. They do **not** establish that the ETL can migrate every older V1 schema.

A static review of database/v2/migrate_synthetic_clone.php against the exact Phase 8 DDL found:

- The patient-audit copy selects patient_audit_log.field_name, but Phase 8 has no field_name column. The current ETL will fail at that copy step for a Phase 8 source. Its destination legacy_patient_audit_log already has entity_type and entity_id columns, but the ETL does not map those Phase 8 values.
- The schedule-audit copy omits Phase 8 change_type. The V2 legacy_schedule_audit_log has legacy_payload for source-only fields, but the ETL currently does not populate it. A partially adapted ETL could otherwise omit that value without a SQL error.
- Phase 8 declares both created_at columns as TIMESTAMP, while the V2 legacy tables store DATETIME. The ETL has no explicit session-timezone/UTC conversion rule for this transition.
- The existing ETL runner is bound to one approved synthetic Phase 10+ source clone and one retained V2 prototype target; it is a rehearsal script, not a generic upgrade runner.

Do not run the existing ETL against a Phase 8 source. Before claiming that path is supported, add source-schema detection, map entity_type/entity_id explicitly, preserve schedule change_type in a defined legacy payload, specify and test timestamp semantics, and verify all copied columns/NULLs/row counts on a newly created disposable Phase 8 source plus a separate disposable V2 target clone. Keep the retained V2 prototype read-only. No ETL or database was run or modified for this static compatibility review.


## 20 November 2026 — Legacy Phase 8 audit ETL acceptance

The earlier ETL boundary note is superseded for the exact Phase 8 patient/schedule audit-table signature. Mapping contract 2.1.2 now detects the source columns, preserves patient entity_type/entity_id and schedule change_type, retains nullable descriptions through V2 migration 2.0.7, and reads TIMESTAMP source values in a UTC session before storing them as V2 DATETIME instants. Text-field equivalence uses byte comparisons across source/target collations.

The focused disposable-clone rehearsal passed with one row in each audit archive, 3,468 lineage mappings, zero blocking exceptions, identical source fingerprints, and target mysqlcheck PASS. Evidence: C:\xampp85\migration-backups\healthbridge\hb-v2-phase8-etl-rehearsal-20261120\RESULT.md.

Scope remains narrow: the source clone had the approved modern synthetic V1 schema with only the two audit tables recreated to the Phase 8 DDL. This does not certify a raw pre-Phase-9/17 source database or arbitrary legacy schema drift. No application selector changed; V1 remains the runtime default. No cutover is authorized.


## Local V2 website preview — 20 November 2026

A separate local preview is available at **http://127.0.0.1:18555/** while its PHP server process is running. This is not a global V2 cutover: the ordinary Apache website at its existing URL still defaults to V1.

- Preview database: healthbridge_v2_local_preview_20261120, cloned from the retained healthbridge_v2_prototype_20261118.
- Clone acceptance: 37 base tables, one view, 42 foreign keys; all 37 CHECK TABLE checks passed; every source/target table row count matched; source prototype row counts were unchanged.
- All 14 application schema selectors are explicitly set to V2 in the preview server process. The preview uses a separate PHP session name (HBV2SESSID) and isolated session/temp directory.
- Smoke checks: home, doctor directory, services and login pages returned HTTP 200; doctor API returned 32 rows; department API returned 9 rows; unauthenticated protected endpoints correctly returned HTTP 401.
- The preview router blocks direct requests to .env, .git, database scripts, documentation, tools, includes, services and vendor paths. It is bound to loopback only.
- The V2 authenticated HTTP read and mutation acceptance evidence remains the 115-read / 86-mutation disposable-clone suites recorded above. The login page's Quick Demo Login controls are present, but a new independent login/session acceptance has not been recorded for this preview.
- Registration/email verification and password reset delivery, registration intake and notification workers are not declared accepted for preview use. Do not submit real registrations, send email, or run workers from this preview. No SMTP or scheduled task was invoked.
- No V1 database, retained V2 prototype, hosted database, application .env, Git staging/commit/push, or deployment was changed for this preview.

Preview runtime record: server PID 25544; isolated logs/session files under C:\xampp85\migration-backups\healthbridge\hb-v2-local-preview-20261120\. Stop the preview with Stop-Process -Id 25544 in PowerShell. The preview database is separate and is not the retained V2 prototype.

### V2 preview smoke-test correction - 20 November 2026

The first browser review showed missing navbar/sidebar fragments because the preview-only safety router had denied the entire includes directory. The router was narrowed to allow exactly includes/fragments/navbar.html and includes/fragments/sidebar.html; all other includes paths remain blocked. Both allowed fragments now return HTTP 200. Direct requests to includes/db.php, database/v2/README.md and .env still return HTTP 404.

The patient dashboard endpoint was exercised over the preview HTTP server with a separate synthetic patient session (user 2, current session_version) and no real account data was modified. POST /api/patient/dashboard.php returned HTTP 200 and success=true, with overview, health_snapshot, appointments, medical_timeline, prescriptions, profile, notifications, favorites, insights, downloads, health_alerts, profile_completion and the preview fields. The synthetic session file was removed. The first probe returned 401 because its test cookie was not attached; that harness error was corrected before the accepted run.

No PHP fatal, warning, notice or uncaught-exception entries were found in the preview server log at the time of the check. The already-open browser tab needs a refresh to request the corrected layout fragments; page text extraction can include inactive fallback markup and is not by itself proof that the dashboard's error state remains visible.

The router change is confined to the QA preview folder, not the application repository. The V1 Apache website and retained V2 prototype remain unchanged. The preview has not been globally cut over.

### 20 November 2026 — Authenticated browser validation follow-up

After the preview-only router correction, the browser rendered the doctor portal with the shared navbar/sidebar, doctor identity, appointment table, schedule, reviews, and notifications. The preview log recorded successful login/current-user requests and HTTP 200 for the doctor availability, appointments, ratings, notifications, hospital-hours, and schedule GET endpoints. The patient dashboard API had separately passed with an isolated synthetic patient session; admin dashboard data endpoints in the same preview log also returned HTTP 200. No PHP warning/fatal/notice appeared in the inspected latest log window. These are read/render smoke checks, not complete workflow acceptance.

A dashboard defect was found and corrected in assets/js/pages/doctor-dashboard.js: the patient queue had included pending/confirmed appointments from every date, including past/future bookings and already-completed visits. It now limits the queue to the local calendar date and active waiting/in-progress work; the timeline now uses the same local-date calculation to avoid UTC date-boundary drift. Node syntax check and git diff --check passed. This is a shared frontend correction and therefore applies to both V1 and the V2 preview; no database data was changed. Browser visual confirmation of the updated queue still requires a hard refresh.

The doctor portal settings form remains a known functional gap: its submit handler currently displays a success toast without persisting the selected preferences. Do not treat that form as accepted. Continue route-level testing for auth/password flows, settings/self-deactivation, doctor/patient/prescription mutations, shared contact/support settings, and worker behavior before V2 cutover.


### Scope clarification — frontend polish deferred to Milestone 3 (20 November 2026)

Owner direction: avoid incremental frontend/UI/UX/performance fixes during V2 compatibility validation. Milestone 3 will deliver a cohesive website redesign and address interface consistency, usability, responsiveness, information hierarchy, queue/timeline presentation and frontend performance together. The earlier QA note describing a queue/timeline frontend fix is superseded: that narrow edit was reverted immediately, so it is not part of the current implementation. The observed queue/timeline behavior is recorded as Milestone 3 backlog, not a V2 database blocker.

From this point, V2 preview work should prioritize schema-selector correctness, database/repository/service compatibility, authorization, route behavior, data integrity, and release-critical defects that prevent core workflows. Make a frontend change before Milestone 3 only when it is necessary to unblock a core V2 workflow or address a security/data-integrity issue; keep it minimal and document the exception. Do not treat visual polish or broad performance redesign as a V2 cutover prerequisite.


## 20 November 2026 — Expanded authenticated V2 mutation acceptance

The authenticated V2 HTTP mutation contract was extended and passed with **130 assertions** against a unique disposable full clone of `healthbridge_v2_prototype_20261118`. The retained prototype was read-only; the clone was dropped; the source table row counts and AUTO_INCREMENT fingerprint were unchanged. The temporary copied app, server and synthetic sessions were removed. Evidence: `C:\xampp85\migration-backups\healthbridge\hb-v2-application-http-mutation-20261120\RESULT.md`.

New route-level acceptance in this run:
- Admin doctor create/update/deactivate/reactivate. The user/profile relationship, department assignment and all seven default weekly schedule rows were verified.
- Admin patient create/update, demographics projection into `patient_profiles`, and creation of `patient_profiles` plus `patient_medical_profiles` aggregates.
- Patient settings read, password change and session-version refresh, self-deactivation, denial of subsequent access through the inactive-account guard (HTTP 403), and admin restoration.
- Doctor prescription create, duplicate-per-appointment rejection (HTTP 409), medication/notes update, cancellation, and patient/admin role denial (HTTP 403). A fresh confirmed synthetic appointment was created specifically for this lifecycle test.

The existing reschedule, booking/approval/decline, schedule, ratings, settings, hospital, department, and account status HTTP coverage also passed in the same run. This still does **not** accept registration/email verification/password reset delivery, registration intake/notification workers, exhaustive support/contact routes, or every remaining legacy direct-SQL endpoint. No SMTP/email or worker was invoked. V1 remains the runtime default; no cutover was performed.


### 20 November 2026 — Support/contact HTTP acceptance addendum

The expanded 20 November V2 authenticated HTTP mutation run was extended again and now passes **142 assertions** (superseding the earlier 130-assertion run above). It additionally verifies the shared V2 `contact_messages` path end-to-end: authenticated patient submission, patient-owned message retrieval, admin list retrieval, denial of patient access to the admin list/reply endpoints, admin reply persistence with timestamp, and exactly one in-app `support_reply` notification. The synthetic recipient's in-app message preference was enabled in the disposable clone. No email or worker was invoked. The same run still confirms the disposable clone was dropped and the retained V2 source fingerprint remained unchanged. Updated evidence: `C:\xampp85\migration-backups\healthbridge\hb-v2-application-http-mutation-20261120\RESULT.md`.

This accepts the tested contact/support HTTP paths against the V2 schema fixture. It does not close the separate registration/verification/reset-mail, intake/notification-worker, or remaining direct-SQL review gates. V1 remains default; no cutover occurred.


### 20 November 2026 — Prescription completion and archival acceptance update

The expanded authenticated V2 HTTP suite now passes **161 assertions**, superseding the earlier 142-assertion result. New route-level checks cover prescription completion (including patient/admin role denials) and admin archive of doctor and patient accounts. Archive assertions confirm sign-in is disabled while the doctor's profile, seven-day schedule, cancelled/completed prescription history, and audit event remain; for the patient, the patient profile, medical profile, session-version revocation, and archive audit record remain. Full run evidence is in `C:\xampp85\migration-backups\healthbridge\hb-v2-application-http-mutation-20261120\RESULT.md`.

The suite used a unique disposable clone; it was dropped, the temporary copied app/server/session artifacts were removed, and the retained V2 prototype row-count/AUTO_INCREMENT fingerprint remained unchanged. V1 remains default. This still does not certify email delivery/registration workers, all legacy direct-SQL endpoints, or production readiness.


### 20 November 2026 — Clinical EMR authenticated HTTP acceptance update

The latest expanded V2 authenticated HTTP suite passes **204 assertions**, superseding the earlier 161-assertion result. It now exercises the V2 clinical EMR routes through HTTP on a synthetic confirmed appointment in the disposable clone:
- Doctor visit-draft save/read/delete; patient role denials for draft write/read/delete.
- Doctor note creation and admin correction, with V2 encounter ownership and clinical fields verified.
- Patient self-scoped medical-record reads even when a different patient ID is supplied; assigned doctor and admin EMR reads.
- Denial of medical-record and EMR reads to a doctor with no appointment relationship to the patient.
- Workflow transitions to In Progress and Ready to Complete; patients cannot transition workflow, and clients cannot directly request Completed through the transition endpoint.
- Completion input validation, atomic clinical note/workflow completion, draft removal, completed-visit draft lockout, completion retry rejection, visit-history visibility, and preservation of the parent appointment's confirmed booking status.

Full run evidence: `C:\xampp85\migration-backups\healthbridge\hb-v2-application-http-mutation-20261120\RESULT.md`. The retained V2 prototype remained read-only; its row-count/AUTO_INCREMENT fingerprint was unchanged. The disposable clone and temporary app/server/session artifacts were removed. V1 remains default. This does not authorize cutover or close registration/email workers and remaining legacy direct-SQL review gates.


### 20 November 2026 — Full V2 clinical access and EMR read-parity verification

Two additional read-only comparison suites passed against the V1 reference and retained V2 prototype:
- Clinical access matrix: **32 doctors × 133 patients = 4,256 decisions**; all decisions matched (366 allowed, 3,890 denied). Both doctor/patient account-lock paths passed. Lock transactions were rolled back.
- EMR read models: all **133 patients** passed appointment DTO parity, ratings/notification count parity, V2 audit and medical-history reads, workspace workflow/prescription/note parity, and current-visit DTO checks.

Both scripts passed PHP syntax validation. These suites made no fixture writes. The broader test evidence complements the 204-assertion authenticated V2 HTTP route contract recorded above. It does not certify auth email/worker flows or remaining legacy direct-SQL routes, and does not authorize cutover.


### 20 November 2026 — Authenticated registration/login/password-reset HTTP acceptance

The expanded V2 authenticated HTTP suite now passes **225 assertions**, superseding the 204-assertion result. It now additionally verifies:
- Registration Step 1 validation and Step 2 encrypted intake queue submission; the acknowledgement does not disclose submitted email, phone or password, and no patient account is created before worker execution.
- Explicit email-confirmation POST using a synthetic challenge: the challenge is consumed once, the queue is released to pending with a verification timestamp, lookup HMAC/expiry data are cleared, and replay is rejected.
- Login denies an unverified account with the `EMAIL_VERIFICATION_REQUIRED` response and permits it after its verification timestamp is set.
- Password-reset token verification, successful one-time consumption, password hash update, exactly one session-version increment, rejection of the consumed token, old-password denial and new-password login.

No SMTP, reset email, registration-intake worker, resend route or notification worker was invoked. Registration HTTP used a synthetic per-run queue encryption key and a no-op Composer autoloader created only inside the temporary app copy; it did not read host key material or load a live mail transport. The synthetic test patient's original password hash, active status, verification fields and session version were restored before clone cleanup. Full evidence: `C:\xampp85\migration-backups\healthbridge\hb-v2-application-http-mutation-20261120\RESULT.md`.

This accepts the tested V2 HTTP request/credential state transitions, not real email deliverability or worker operations. Those remain separate acceptance gates. The unique disposable clone was dropped, the retained V2 prototype fingerprint was unchanged, and V1 remains the active/default schema.


### 20 November 2026 — V2 medical-profile mutation HTTP acceptance update

The latest complete V2 authenticated HTTP suite now passes **241 assertions**, superseding the 225-assertion result. The new medical-profile scenarios verify patient and unrelated-doctor write denials, the requirement for an administrative reason when changing sensitive fields, persistence into V2's split `patient_profiles` and `patient_medical_profiles` tables, inclusion of the correction reason in the admin EMR audit projection, doctor-authored `medical_history_events`, and deduplication when the legacy history follow-up endpoint receives the same change. The patient EMR read model reflects the latest corrected profile.

Updated evidence: `C:\xampp85\migration-backups\healthbridge\hb-v2-application-http-mutation-20261120\RESULT.md`. The run also retains the previously accepted registration/login/reset, clinical encounter, prescription, account, support, appointment, schedule, ratings, department and hospital route coverage. It created and dropped a unique disposable V2 clone; the retained prototype row-count/AUTO_INCREMENT fingerprint was unchanged. V1 remains default and no cutover was performed.


## 20 November 2026 — API direct-SQL / V2 selector compatibility inventory

A read-only heuristic inventory of PHP files under api/ identified 52 endpoints with SQL/database activity. The first-pass list was reviewed for endpoints that did not mention SchemaSelector directly. Absence of a route-level selector is not itself a V2 defect: several endpoints delegate schema selection to service/repository factories, and several others use intentionally shared V2 tables.

Reviewed examples:
- appointments/request-reschedule.php delegates to RescheduleService::schemaFromEnvironment().
- audit/get.php uses AdminAuditService::schemaFromEnvironment() and branches its actor-filter SQL by audit schema.
- departments/update.php delegates to DepartmentService and AdminAuditService with their schema factories.
- doctors/get-availability.php and doctors/get-public-availability.php use ScheduleService schema selection; public availability explicitly branches its doctor-profile lookup between V1 and V2.
- schedule/reset.php and schedule/update.php perform the schedule operation through ScheduleService; their preliminary users lookup targets the shared users table.
- patients/get_all.php reads the shared users table and does not query a V1-only patient profile table.
- settings contact-message routes and patient message projection use contact_messages, which is present in the tested V2 application path; support/contact HTTP acceptance is recorded above.
- notifications/notifications.php delegates notification CRUD to NotificationService.
- notifications/get-patient.php directly reads the shared V2 notifications table. V2 001_schema.sql defines ref_id, type, title, message, is_read and created_at as expected by this endpoint; the query aliases ref_id to the legacy appointment_id response field.

No confirmed V1-schema bypass or V2-breaking direct-SQL defect was established in this pass. The inventory is a static, heuristic review, not a complete SQL parser or runtime acceptance of every API route. Continue checking selector-bearing endpoints for unguarded V1-only SQL branches and complete the remaining browser/workflow regression before any cutover. Do not add selectors mechanically to shared-table queries. V1 remains the active/default schema; no application, database, hosted service, Git staging/commit/push or deployment was changed for this inventory.



## Local V2 web/API cutover — 20 November 2026

**Owner-directed local cutover completed.** The ordinary XAMPP/Apache website now uses the canonical database healthbridge_v2, with all 15 HEALTHBRIDGE_*_SCHEMA selectors explicitly set to v2 by a local-only block in the project's root .htaccess.

The V2 database was migrated from the actual local V1 healthbridge data, not from the synthetic preview fixture. Migration and post-cutover evidence, data counts, integrity results, rollback instructions and outstanding gates are recorded in docs/HEALTHBRIDGE_LOCAL_V2_CUTOVER_20261120.md.

The original V1 database healthbridge remains present and unchanged. The local CLI runtime still defaults to V1 because .env was deliberately not read or modified. Registration-intake and notification/outbox workers are therefore **not switched or accepted**; no worker, SMTP delivery, registration email, reset email or scheduled task was run. Hosted databases and deployment were not touched. Hosted P0*/P1 findings remain open high-priority release gates.

The root .htaccess V2 block is local-only and must not be deployed to hosting. Its original pre-cutover contents are backed up at:
C:\xampp85\migration-backups\healthbridge\hb-v2-cutover-canary-20261120\root-htaccess-before-v2-cutover.bak

The synthetic V2 prototype and preview remain separate. The new canonical V2 database passed 37/37 table integrity checks, the 42-FK orphan scan, service-read checks, and strict 37-table backup/restore equivalence. Interactive login/session smoke tests against the newly migrated canonical data remain to be completed by role before treating the entire application cutover as fully accepted. No Git staging, commit, push or deployment occurred.
