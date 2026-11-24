> **Status update — 24 November 2026.** Hosted release is complete: the site is live at https://youssef1.alwaysdata.net and both CLI workers run there against the V2 database. Statements below about open hosted gates and V1-default CLI workers describe the local state at the time of writing. The dated record below is unchanged.

# HealthBridge database V2 schema and migration reference

**Current status (20 November 2026):** Canonical actual-data V2 database is active for local Apache web/API. The synthetic V2 prototype, the V1 email-verification source fixture, the V2 restore5 fixture and `healthbridge_repair_test` are retained because current QA tools reference them. Fourteen unused QA/ETL/restore databases were exported and retired; the preview clone was included. Their logical dumps, SHA-256 manifest, restore instructions and retirement record are outside the web root at `C:\xampp85\migration-backups\healthbridge\db-retirement-20261120\`. The isolated preview server and database are no longer running/present. Interactive canonical-V2 role login coverage, CLI/worker configuration and hosted release gates remain open; see docs/HEALTHBRIDGE_LOCAL_V2_CUTOVER_20261120.md. Dated historical sections below preserve the state at the time they were written and may describe the former V1-default/preview period.  
**Target engine:** MariaDB 10.11.16.  
**Source fixture:** `healthbridge_hb_auth_email_verify_20261114` (disposable synthetic clone only).  
**Prototype target:** `healthbridge_v2_prototype_20261118`.  
**Restore target:** `healthbridge_v2_restore5_20261118`.

**Retired V1 artifacts:** Former V1 fresh-install SQL, Phase 10–17 forward migrations, legacy migration DDL and V1-only PHP CLI helpers are preserved outside the web root at C:\xampp85\migration-backups\healthbridge\retired-v1-artifacts-20261120\. The SHA-256 manifest is included there. The active V2 schema and QA tools remain in this folder; this is not a general fresh-install bundle.
## Safety and scope

- The v2 prototype is separate from the current v1 database. The ETL runner requires `DB_NAME` to be the exact disposable source clone and requires explicit `HEALTHBRIDGE_ETL_SOURCE_SCHEMA` / `HEALTHBRIDGE_ETL_TARGET_SCHEMA` values. Both schema names must use the dedicated disposable-test prefixes, and the database host must be loopback.
- No statement in this folder changes `healthbridge`, `nova_home`, an Alwaysdata DB, or any hosted resource.
- Local Apache web/API uses canonical healthbridge_v2 through a local-only .htaccess override. CLI workers still default to V1; do not deploy the local override to hosted environments. The synthetic prototype remains separate from the canonical database.
- The v1 source clone was fingerprinted before and after ETL/restore verification; all 28 table row counts and SHA-256 fingerprints matched exactly.
- The database v2 work is not a production schema certification or a generic upgrade runner. The exact Phase 8 patient/schedule audit-table signature now has a dedicated mapping rehearsal (20 November 2026); other historical schema drift, older full-install baselines and live-install migration histories remain outside that acceptance.

## Design decisions

- `users.id` is the canonical account identity. `doctor_profiles.doctor_user_id` is both profile PK and FK to `users.id`; no second doctor identity is used by v2.
- Patient demographic/contact data is split into `patient_profiles`; current clinical summary is in `patient_medical_profiles`.
- Doctor profile, preferences, admin UI preferences and schedules have separate owners.
- Doctor weekly schedules use one composite key and TIME values. Availability has one canonical field.
- Appointments use `starts_at`, `duration_minutes`, booking-only status and explicit historical display snapshots. Reschedules and appointment events are children.
- `clinical_encounters` combines workflow state and note content. `encounter_drafts` remains separate for autosave.
- Composite appointment-owner foreign keys enforce that clinical encounters, prescriptions and ratings match the patient and doctor recorded on their appointment.
- Ratings are source facts; `v_doctor_rating_summary` calculates the aggregate instead of relying on a stale profile cache.
- The notification outbox intentionally has no users FK to preserve the previously tested lock-order policy. The registration intake queue also has no users FK because it exists before account creation.
- New audit events use `audit_events`. The known audit families are preserved in source-specific legacy archives.
- `schema_migrations`, `migration_row_map`, and `migration_exceptions` track versions/checksums, per-row lineage and accepted/blocking exceptions.
- The generated normalized phone expression explicitly uses `utf8mb4_unicode_ci` for every text operand so a logical dump can be restored under a different session default collation without generated-column errors.

## Files and intended run order

The original schema-preparation wrappers below still contain fixed source/target constants; they are not generic target-clone tools. Read each wrapper's constants and prepare a separate disposable copy before running them. The Phase 8 ETL acceptance used already-prepared, separately named source and V2 target clones; it did not write to the retained prototype.

1. `apply_prototype.php` applies `001_schema.sql` and records version 2.0.0 (35 tables and one view at the base-schema stage).
2. `apply_incremental.php` applies `002_appointment_legacy_snapshots.sql` (version 2.0.1), preserving original appointment time/range text.
3. `apply_archives.php` applies `003_legacy_value_archives.sql` (version 2.0.2), retaining the removed doctor-profile cache/identity and mixed user-preference fields verbatim.
4. `apply_phone_collation.php` applies `005_phone_normalization_collation.sql` (version 2.0.5) before data import, so the generated column is deterministic for both ETL and logical restore.
5. `apply_rating_visibility.php` applies `006_rating_visibility.sql` (version 2.0.6) and must run before ETL.
6. `apply_legacy_audit_archive_nullability.php` applies `007_legacy_audit_archive_nullability.sql` (version 2.0.7) on the empty V2 target clone, allowing historical Phase 8 NULL schedule-audit descriptions to be retained.
7. `migrate_synthetic_clone.php` (mapping contract 2.1.2) performs the one-transaction V1-to-V2 synthetic ETL and commits only after count, ownership, field-equivalence and blocking-exception gates pass.
8. `apply_owner_constraints.php` applies `004_appointment_owner_constraints.sql` (version 2.0.3) after ETL, because its composite constraints validate patient/doctor ownership on populated appointment-linked rows.
9. `verify_migrated_data.php` runs read-only field-equivalence, foreign-key and CHECK TABLE validation.

For the ETL stage, `DB_NAME` must be the exact prefixed disposable source clone, and the target variable must identify a separately prepared, empty V2 clone with schema versions 2.0.0–2.0.7 recorded. Never set either variable to the protected `healthbridge` database or retained prototype:

```powershell
$env:DB_NAME = 'healthbridge_hb_etl_source_phase8_20261120'
$env:HEALTHBRIDGE_ETL_SOURCE_SCHEMA = $env:DB_NAME
$env:HEALTHBRIDGE_ETL_TARGET_SCHEMA = 'healthbridge_v2_etl_target_phase8_20261120'
php database/v2/apply_legacy_audit_archive_nullability.php
php database/v2/migrate_synthetic_clone.php
```

The ETL runner refuses a non-empty target. A failed transaction rolls its row inserts, lineage, exceptions and ETL ledger entry back together. Schema DDL is versioned and intentionally not automatically dropped if a DDL statement fails; stop and inspect the named disposable target.

## Completed synthetic rehearsal

See `ETL_RESULT.md` for exact results. The accepted target currently has:

- 37 InnoDB base tables, one derived view, 42 foreign keys and 23 CHECK constraints.
- 166 accounts, 32 doctor profiles, 133 patient demographic profiles and 133 clinical profiles.
- 486 appointments, 12 separate reschedule requests and 451 clinical encounters (216 notes were merged into the associated workflows; 235 workflow rows have no note).
- 99 prescriptions, 186 prescription items and 79 rating facts.
- 558 notifications, 13 outbox rows, 20 encrypted registration jobs and 11 one-time email challenges.
- 3,466 source-to-target lineage rows and 100 accepted legacy warnings; zero open blocking exceptions.

All 27 field-equivalence checks returned zero differences, including generated phone normalization. All 42 foreign keys were orphan-free. The eight negative constraint tests passed. A separate logical backup was restored to `healthbridge_v2_restore5_20261118`; all 37 table row hashes, 379 column definitions, 178 index rows, 42 FK constraints/48 FK column mappings, 23 checks, one view, engine/collation and AUTO_INCREMENT values matched; CHECK TABLE passed 37/37.

## Explicit unresolved / untested cases

- 52 medical-record location values did not have an exact governorate/city lookup match. Their original text is retained and no speculative FK IDs were assigned.
- 30 legacy doctor rating caches differed from the ratings facts. Cache values were archived; the v2 aggregate is derived from rating rows.
- 12 legacy `Reschedule Requested` statuses were mapped to confirmed bookings with separate pending request rows, based on the current Confirmed-only reschedule service path.
- Six 12-hour appointment strings were normalized to `starts_at`; original time and range display strings are retained.
- The 18 November Phase 17 baseline had zero patient-audit and schedule-audit rows. A separate 20 November populated Phase 8 audit-signature rehearsal now proves the exact legacy patient `entity_type`/`entity_id` and schedule `change_type` shape is mapped and preserved. This does not certify every other historical schema variant or a raw pre-Phase-9-to-17 database.
- There were no password-reset rows, drafts, medical-history-update rows or contact-message rows in this clone. Their non-empty source mappings have not been runtime-rehearsed here.
- The copy preserves data and lineage; it does not prove current API compatibility. No PHP endpoint or frontend page has been switched to v2.
- The first four restore attempts exposed collation and PowerShell text-encoding issues. Those attempts were kept as separate failed test targets; the accepted restore used a collation-explicit generated expression and byte-preserving file redirection. The final restore has exact row hashes.
- No production/hosted database has been inspected or changed. No cutover is authorized.

## Next phase

The first opt-in v1/v2 repository slice now exists under `services/Repositories/`: doctor profile reads, schedule settings/weekly rows and appointment/reschedule slot reservations. Its read-only cross-schema contract suite and transaction-rollback schedule/reschedule tests are recorded in `docs/HEALTHBRIDGE_V2_APPLICATION_COMPATIBILITY.md`. These classes are not wired into existing API endpoints; the runtime remains v1. Next, adapt the remaining `ScheduleService` queries (hospital TIME formatting, capacity, all booking reads, validation, batch availability and reset), then doctor create/update/archive and department operations. A schedule-only rename is unsafe because slot generation depends on original bookings and active request/proposal holds. Then cover authentication/session handling, patient profiles, clinical encounter lifecycle, EMR, prescriptions, rating writes/summary, audit display, notifications, registration verification and workers. Do not retire v1 tables or point the current application at v2 until endpoint contracts, UI flows, legacy consumers, full restore, end-to-end journeys and a separate cutover/rollback plan pass.


### ScheduleService compatibility milestone — 18 November 2026

The opt-in repositories are now consumed by `ScheduleService(PDO, schema='v1')`. Pass explicit `v2` only in dedicated tests/future opted-in callers; never infer the mode from installed tables. Existing endpoints still use the one-argument constructor and therefore stay v1. Service-level read parity passed 9/9 assertions across 32 doctors and 448 reservation date pairs, including public availability, next-slot, slot generation and validation. Service-level rollback tests cover v1 schedule/availability writes, v2 settings/weekly writes and v2 seven-day reset defaults.

The doctor profile preference `accepts_new_patients` remains distinct from operational schedule availability. The current booking/reschedule services do not enforce the preference as a server-side rule; decide this separately before changing the policy. Full endpoint integration, concurrent booking tests, doctor mutation/department assignment, auth/clinical/notification domains and cutover/rollback remain open.


### Doctor management compatibility milestone — 18 November 2026

Added the explicit-mode `DoctorManagementRepository` for account/profile creation, identity updates, department assignment and activate/deactivate/archive. All methods use `users.id`, map v1 `admin_audit` to v2 `audit_events`, and keep writes inside caller-owned transactions. New doctor creation sets up the v2 profile, preferences and default schedule. Deactivation applies the existing clinical-work guard, cancels only untouched appointments, closes active reschedule requests and emits appointment events/outbox notices.

The rollback-only contract passed against the disposable v1 and v2 fixtures: doctor create/update/assignment, status/session-version transitions, clinical-work guard and archive/reactivate with four appointment cancellations per fixture. Rows were rolled back and AUTO_INCREMENT counters restored. Existing API endpoints and DepartmentService remain v1. Do not opt them into v2 until the booking/reschedule transaction and concurrency compatibility tests pass.


### Booking and reschedule compatibility milestone — 18 November 2026

Added explicit-mode `AppointmentBookingRepository` and `RescheduleTransactionRepository`. Booking locks doctor, profile and department before validating the slot and locking the patient; audit, events and outbox entries are transactional. Rescheduling maps all legacy transitions to v2 `appointment_reschedule_requests` child rows, preserves original/requested/proposed slot reservations, and records v2 appointment events/audit. Pending-request and history read DTOs retain existing API fields.

The full booking/reschedule sequence passed rollback tests in both v1 and v2 clones. A two-PDO v2 concurrency test showed the second booking blocks on the doctor row lock and is rejected by revalidation after the first commit. Read DTO parity passed 5/5 checks for all 12 pending source requests and no-history defaults. Existing API controllers remain on v1. Do not switch them until the clinical encounter/workflow and appointment timeline read paths are adapted and regression-tested.


### Clinical encounter compatibility milestone — 18 November 2026

Added `ClinicalEncounterRepository` with explicit v1/v2 mapping for workflows, clinical notes and drafts. The v2 `uninitialized` state preserves note-only encounters without manufacturing a Waiting workflow. `VisitWorkflowService` and `VisitDraftService` default to v1; existing routes remain there.

Clinical endpoint refactors now use shared services for note reads/writes, EMR/print, doctor workspace, rating eligibility and drafts. v2 clinical access and patient timeline DTOs match the synthetic v1 fixture: 451 workflows, 216 notes, 4,256 access decisions, 486 appointment timeline records and 1,079 medical events. Seeded writes and transitions rolled back on both fixtures; all targeted lint/tests passed. No endpoint has been switched to v2.

Next: prescription service/data compatibility. Inspect exact v1/v2 DDL and endpoint contracts before implementation. No prescription code has been changed yet.

### Prescription service compatibility milestone - 18 November 2026

Added explicit v1/v2 support to `PrescriptionService`. It maps canonical v2 owner columns and lowercase statuses to the current DTO while retaining the v1 default. Create/update/complete/cancel, role-scoped reads, search/status filters and item hydration are schema-aware. V2 creation rejects active child reschedule requests. Audit is written to `audit_events`; notifications are enqueued transactionally. Visit completion calls a caller-owned transaction method rather than opening a nested transaction.

The prescription compatibility suite passed: all 99 headers and 186 medication lines matched; list/filter and sampled patient portal card parity passed; create/update/complete/cancel, ownership rejection and outer-transaction rollback passed on both disposable fixtures. Test rows/audits/preferences and AUTO_INCREMENT counters were restored. Selected EMR, workspace, print and doctor archive/disable consumers now use the shared service.

Existing API routes still instantiate v1 by default. Patient portal aggregates/alerts/downloads/search and remaining account/settings consumers are not yet fully compatible; do not switch runtime or controllers based on this milestone alone.

### Patient portal read compatibility checkpoint - 18 November 2026

Expanded the schema-aware PatientPortalService read layer while retaining the v1 default:

- Added v2 dashboard overview using normalized appointments, latest reschedule child state, doctor profiles/rating summary, schedule availability, shared notification counts and hospital settings.
- Added v2 medical-profile read by joining patient_profiles and patient_medical_profiles, preserving the existing portal DTO.
- Added v2 favorite/recommended doctor aggregates using doctor_profiles, doctor_schedule_settings and v_doctor_rating_summary. The schedule's is_available remains distinct from accepts_new_patients; live v2 rating aggregates are used instead of known-stale legacy rating cache values.
- The v2 favorites aggregates exclude appointments with active reschedule children to preserve the legacy confirmed-only semantics. Unspecified tie order in legacy top-three/primary-doctor queries is treated as equivalent only when the fixture proves a shared cutoff/top-count tie.
- getNotifications continues to use the shared NotificationService because both schemas expose the same notifications and user_preferences read contract; grouped notifications were verified for parity.

tools/test_v2_patient_portal_snapshot.php now exercises all 133 synthetic patients across profile completion, medical profile, dashboard overview, notification groups, favorites compatibility, the 14-field health snapshot, health alerts, insights, downloads and selected unified-search terms. The suite passed on the exact disposable v1/v2 fixtures. PHP lint passed for the service and test. The fixture-only checks did not opt in any endpoint or change the runtime database selection.

**Still open:** broader direct-SQL/API compatibility audit; authentication and account lifecycle flows; notification outbox/worker behavior and downstream consumers; settings and deletion flows; complete end-to-end regression. Keep v1 as runtime default until all workflows and workers have evidence-backed v2 coverage. No main/protected/hosted/NOVA database, .env or secrets were accessed. No Git staging/commit/push or deployment occurred.

## Application compatibility update — 19 November 2026

Admin doctor archive and status-toggle controllers now delegate to the schema-aware `DoctorManagementRepository`. The repository exposes an atomic toggle operation and a separate archive operation so audit semantics remain stable (`archive` is not rewritten to `deactivate`). The archive path still writes appointment-cancellation notices transactionally but omits the separate doctor account-status notice; ordinary enable/disable continues to notify the doctor.

The repository contract passed against both approved disposable fixtures, including v2 appointment/reschedule cleanup and appointment event creation. PHP syntax checks passed for the repository, both controller wrappers, and the contract test.

Important: the two controllers explicitly remain in `SCHEMA_V1` mode. The v2 repository behavior is test-proven, but v2 HTTP routing and runtime cutover are not enabled by this update. Full HTTP regression and notification worker delivery/retry tests remain open.


## Application compatibility update — 19 November 2026

Patient account mutation compatibility is now implemented in `services/Repositories/PatientManagementRepository.php`:
- Admin profile updates and password resets map to the v2 user schema and `patient_profiles`.
- Patient archive retains clinical records, cancels only bookings with no clinical evidence, closes active reschedule child requests, and records appointment events.
- Patient self-cancellation uses normalized appointment/encounter/reschedule tables, audit events, appointment events, and transactional outbox writes.

The rollback-only patient-management contract passed against both approved disposable v1/v2 fixtures. PHP lint passed for the repository, the three connected controllers, and the test harness.

The HTTP controllers remain pinned to `SCHEMA_V1`; this is not a runtime cutover. The patient update controller also retains a v1-specific pre-read/duplicate-check path. HTTP-level request regression and outbox worker delivery/retry are still open.


## Application compatibility update — 19 November 2026

Doctor settings V2 mapping is now represented by `DoctorSettingsRepository`: `accept_new_patients` and `profile_visible` live on `doctor_profiles`; consultation duration maps to `doctor_schedule_settings.appointment_duration_min`; operational `is_available` remains a separate schedule setting. The legacy global working-hours pair cannot be represented by V2's per-weekday rows and is rejected by the V2 adapter.

`DashboardAnalyticsService` now includes a V2 implementation for the existing admin dashboard DTO. It maps patient demographics, doctor profile/rating summaries, normalized appointments/encounters/reschedules, department metrics and audit activity to V2 tables. The admin HTTP controller remains explicitly V1.

Both doctor settings and dashboard analytics fixture contracts passed on the approved v1 and v2 disposable databases. Settings contract writes were rolled back; analytics tests were read-only. The V2 settings GET/frontend still needs a per-weekday schedule adaptation before any runtime settings cutover.


## Application compatibility update — 19 November 2026

Self-deactivation is now implemented in `AccountLifecycleRepository`. V2 supports schema-specific password verification, session revocation, last-active-admin locking, unfinished-doctor-clinical-work protection, patient/doctor untouched-booking cancellation, child reschedule request closure, audit events, appointment events, and transactional cancellation-notification outbox entries.

The repository contract passed against the two approved disposable v1/v2 fixtures and rolled all test mutations back. The HTTP settings controller remains pinned to V1. Settings GET/frontend weekday-schedule compatibility and notification worker delivery/retry tests remain open.


## Application compatibility update — 19 November 2026

Auth/registration V2 compatibility is now represented by:
- Credential reads from `users.password_hash`.
- Password-reset token storage in `password_reset_tokens` with non-null hashed token values and no legacy plaintext token column.
- Registration intake that creates `users`, normalized `patient_profiles` and `patient_medical_profiles`, plus `audit_events`.
- Registration email challenge confirmation against the shared encrypted queue/challenge tables.
- Session revocation through `session_version`.

The auth/registration contracts and notification outbox delivery/retry contract passed against the approved disposable v1 and v2 fixtures. All synthetic state and generated test artifacts were cleaned up/restored. No HTTP cutover or SMTP send took place; routes and the current CLI worker remain explicitly V1.


## Application compatibility update — 19 November 2026

Doctor settings now include a normalized read DTO and weekly write path:
- Acceptance/profile visibility from `doctor_profiles`.
- Consultation duration and operational availability from `doctor_schedule_settings`.
- Weekly hours from all seven `doctor_weekly_schedules` rows.
- V2 rejects the V1 single global working-hours pair; weekly updates must contain all weekdays and at least one working day, and pass hospital operating-hour validation.

Settings GET returns a `weekly_schedule` field, and the modal/standalone settings UI includes a conditional weekly editor. Both HTTP settings controllers remain explicitly V1, so this UI remains dormant until V2 is deliberately enabled. The doctor settings fixture contract passed on both approved fixtures including read DTO, weekly persistence, invalid-schedule rejection, availability separation and rollback.


### Rating visibility migration - 2.0.6

Apply 006_rating_visibility.sql after the existing V2 schema migrations and before migrate_synthetic_clone.php in a clean rebuild. Run apply_rating_visibility.php against the named disposable V1 source and V2 prototype connection: it can apply the DDL to an empty V2 target before ETL, or backfill an already populated target only when source/target rating counts and rating IDs match exactly. It refuses to replay a recorded migration.

The ETL now requires 2.0.6-rating-visibility and copies is_public by inverting source ratings.is_hidden when that source column exists. Older V1 sources with no visibility column are treated as all-public. The public summary view excludes non-public rows; private doctor/admin and patient-owned reads retain the rows. The new ETL mapping contract is 2.1.1-synthetic-etl.


## 20 November 2026 — Phase 8 audit-signature ETL acceptance

The V1-to-V2 ETL mapping contract is now 2.1.2. It introspects the source patient/schedule audit-table column signatures instead of assuming every source has field_name. For the tested Phase 8 signature:

- Patient audit rows map entity_type and entity_id into the corresponding V2 legacy archive columns; field_name remains NULL because Phase 8 has no field_name fact.
- Schedule change_type is retained in a structured JSON legacy_payload together with source-column metadata; an existing source legacy_payload is retained as well.
- The 2.0.7 migration makes V2 legacy_schedule_audit_log.description nullable, matching the Phase 8 source and preserving NULL values.
- The ETL pins its session time_zone to +00:00. TIMESTAMP source values are fetched as UTC instants before insertion into V2 DATETIME; source DATETIME values remain literal wall-time values.
- Textual source/target equivalence is byte-compared so different table-default collations do not mask or invalidate the data check.

Runtime evidence: hb-v2-phase8-etl-rehearsal-20261120/RESULT.md. A fresh source clone and a fresh empty V2 schema clone were used; the source clone began as the approved modern synthetic dataset with only the two audit tables recreated to the exact Phase 8 DDL. The test therefore accepts this historical audit-table variant, not every table shape in a raw early Phase 8 installation. It migrated 3,468 source-to-target lineage rows, retained one patient and one schedule audit sentinel, reported zero blocking exceptions, passed mysqlcheck, and verified an identical deterministic before/after source dump fingerprint. Both clones were dropped and verified absent.

The ETL runner requires DB_NAME to match HEALTHBRIDGE_ETL_SOURCE_SCHEMA and requires explicit prefixed source/target names plus a loopback host. The other schema-preparation wrappers still contain fixed constants and are not generic target-clone tools. V1 remains the application default; this does not authorize a V2 cutover or migration of a protected/hosted database.



### Cross-cutting V1 migration-ledger checkpoint (20 November 2026)

The migration-ledger prototype and Phase 13 rehearsal were completed on disposable V1 historical clones only. This did not alter the V2 schema, its nine existing schema receipts, the retained V2 prototype or the application's V1-default mode. V2 remains opt-in and has not been approved for cutover. Evidence: `C:\xampp85\migration-backups\healthbridge\hb-db-ledger-phase13-20261120\`.


## Local V2 website preview — historical record (retired 20 November 2026)

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

### 20 November 2026 — V2 preview browser QA follow-up

The isolated V2 preview successfully rendered the authenticated doctor portal after the preview router was narrowed to allow only the two required public layout fragments. Preview logs show login/current-user success and HTTP 200 for doctor availability, appointment, rating, notification, hospital-hours, and schedule reads. Patient dashboard API smoke testing with a synthetic session passed earlier; admin dashboard read endpoints also returned HTTP 200. These checks are not full end-to-end acceptance.

The shared doctor dashboard queue logic was corrected to show only today's pending/active visits and exclude completed workflow items; its timeline now uses local date formatting rather than UTC ISO slicing. Node syntax and git diff whitespace checks passed. The change affects shared V1/V2 frontend code; no database was modified. The doctor preferences form still shows a success toast without persistence and remains unaccepted. V1 remains the default; no cutover is authorized.


### Project scope decision — Milestone 3 owns the frontend overhaul (20 November 2026)

The owner has directed that incremental frontend fixes, UI/UX polish and broad performance work be deferred to Milestone 3, which will deliver a cohesive new website look and usability redesign. The prior note in this README describing a queue/timeline frontend fix is superseded: that change was reverted and is not part of the current implementation. Queue/timeline presentation is Milestone 3 backlog, not a database V2 blocker.

V2 validation should focus on database and application compatibility, service/repository behavior, route authorization, data integrity and core workflow correctness. Only make a minimal frontend change before Milestone 3 when a core workflow or security/data-integrity requirement cannot otherwise be validated or used.


## 20 November 2026 — Expanded authenticated V2 mutation acceptance

The expanded authenticated V2 HTTP contract passed **130 assertions** against a unique disposable full clone. It added route-level coverage for admin doctor create/update/status transitions, patient create/update/demographics and profile aggregates, patient settings/password change/session version/self-deactivation and access denial, and doctor prescription create/duplicate guard/update/cancel with role-denial checks. Existing reschedule, booking, schedule, ratings, doctor/admin settings, hospital settings, department CRUD/assignment and account status scenarios also passed.

Evidence: `C:\xampp85\migration-backups\healthbridge\hb-v2-application-http-mutation-20261120\RESULT.md`. The clone and temporary app/server/session artifacts were removed; the retained V2 prototype row-count/AUTO_INCREMENT fingerprint remained unchanged. No mail, SMTP or workers were invoked. V1 remains default; V2 cutover is not authorized. Remaining non-cutover gates are listed in the application compatibility document.


### Support/contact routes — authenticated V2 HTTP acceptance (20 November 2026)

The latest expanded HTTP mutation suite now passes **142 assertions**. In addition to the previously documented doctor/patient/prescription lifecycle routes, it tests V2 contact submission, patient-owned message listing, admin message listing/reply, non-admin denial, reply timestamp persistence, and the resulting in-app support notification. It runs only on the unique disposable V2 clone and removes that clone and temporary application/session artifacts afterward; the retained source fingerprint remains unchanged. No mail or worker is invoked. Full evidence is in `C:\xampp85\migration-backups\healthbridge\hb-v2-application-http-mutation-20261120\RESULT.md`.


### 20 November 2026 — Prescription completion and archive acceptance update

The latest expanded authenticated V2 HTTP suite now passes **161 assertions** (superseding the 142-assertion result). It adds prescription completion with patient/admin role denials and doctor/patient archive routes. The doctor archive retained its profile, seven-day schedule, prescriptions and audit history; patient archive retained patient and medical profiles and recorded session revocation/audit. Evidence: `C:\xampp85\migration-backups\healthbridge\hb-v2-application-http-mutation-20261120\RESULT.md`. Testing used and dropped a unique disposable clone; the retained V2 prototype fingerprint remained unchanged. V1 remains the runtime default.


### 20 November 2026 — Clinical EMR route-level HTTP acceptance update

The expanded V2 authenticated HTTP suite now passes **204 assertions** (superseding the 161-assertion result). In addition to the previously listed appointment, account, prescription, support, settings and archive routes, it now covers medical-note creation/admin correction, draft save/read/delete and role denial, patient/assigned-doctor/admin EMR read projections, unassigned-doctor access denial, workflow transitions, completion validation, atomic completion/draft deletion, completed-visit edit lockout and retention of the confirmed booking status. Testing uses a unique disposable clone; clone and temporary application/session artifacts were removed and retained prototype fingerprint stayed unchanged. Evidence: `C:\xampp85\migration-backups\healthbridge\hb-v2-application-http-mutation-20261120\RESULT.md`.


### 20 November 2026 — Full clinical access matrix and EMR read parity

Read-only V1/V2 comparisons passed across 32 doctors and 133 patients (**4,256 access decisions**, 366 allowed and 3,890 denied, zero mismatches). Both lock-path checks passed with transactions rolled back. The EMR read-model contract passed for all 133 patients: appointment DTOs, ratings/notification counts, audit/history, workflow/prescription/note parity and current-visit DTO. No fixture writes were made. This complements the 204-assertion V2 authenticated HTTP route suite.


### 20 November 2026 — Registration/login/password-reset HTTP acceptance

The latest V2 authenticated HTTP suite passes **225 assertions**, superseding 204. It now tests registration validation and encrypted intake without account creation, single-use email challenge confirmation and queue release, the unverified-login gate, verified login, reset-token verify/consume, password/session-version update, old-password rejection and new-password login. No SMTP, email, registration/notification worker or resend route was invoked. A no-op autoloader and synthetic encryption key existed only in the temporary test app/runtime. User credentials were restored, the disposable database clone was dropped, and the retained V2 prototype fingerprint remained unchanged. Evidence: `C:\xampp85\migration-backups\healthbridge\hb-v2-application-http-mutation-20261120\RESULT.md`.

Real email delivery and operational registration/notification workers are still unaccepted; these HTTP tests do not imply those operational paths are ready.


### 20 November 2026 — Medical-profile mutation HTTP acceptance update

The current V2 authenticated HTTP suite passes **241 assertions** (superseding 225). It additionally covers medical-profile API writes and role denials, sensitive admin correction reason enforcement, split V2 profile table mappings, admin audit-history reason projection, doctor-authored medical-history events and duplicate suppression by the legacy history follow-up endpoint. The patient EMR read model returns the latest corrected value. The full test uses and drops a unique disposable clone; the retained V2 prototype fingerprint remains unchanged. Evidence: `C:\xampp85\migration-backups\healthbridge\hb-v2-application-http-mutation-20261120\RESULT.md`.


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
