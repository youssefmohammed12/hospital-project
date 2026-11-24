> **Superseded — 24 November 2026.** This was a design proposal written before implementation. The V2 schema was implemented and is the only schema the application uses; the implemented schema lives in `database/v2/`. Kept as a historical record of the V1 → V2 build. For the current system, see `README.md` and `docs/ARCHITECTURE.md`.

# HealthBridge Database Architecture Audit and Redesign

**Review date:** 18 November 2026  
**Status:** Architecture assessment and target design proposal; implementation not yet promoted  
**Scope:** Local source schema, migration history, application SQL contracts, and one current disposable synthetic clone  
**Safety boundary:** The protected healthbridge database, NOVA Home, hosted databases, and deployment resources were not queried or changed.

## 1. Executive assessment

The database is not generally broken: the representative clone has valid foreign-key references and the tested patient/doctor/appointment/clinical ownership relationships are consistent. The larger problem is that the schema has evolved additively without a single fully current versioned source of truth. Some domain identities are confusing, some business rules exist only in PHP, multiple columns represent the same state, some stored values drift from their source records, and the migration chain does not reliably describe the current application.

The recommendation is a versioned HealthBridge schema v2, built alongside the current schema and migrated through a tested ETL/compatibility process. Do not attempt a mass in-place rewrite of the existing tables.

## 2. Evidence and review method

### Sources examined

- Consolidated schema: database/healthbridge.sql.
- Fresh setup wrappers: database/healthbridge_empty.sql and database/healthbridge_demo_data.sql.
- Forward migrations: Phases 10 through 17.
- Application SQL contracts in API routes, services, and shared database helpers.
- Doctor booking, schedule, ratings, admin profile and dashboard callers.
- Existing database migration, module-map, endpoint-inventory, QA, and completion documents.

### Representative disposable clone

Read-only checks used healthbridge_hb_auth_email_verify_20261114, a recent disposable synthetic clone. It contained 28 InnoDB tables, 166 users, 32 doctor profiles, 486 appointments, 79 ratings, 216 visit notes, 451 visit-workflow rows, 99 prescriptions, 186 prescription items, 133 medical records, 558 notifications, 13 outbox rows, 20 registration-intake jobs, and 11 email challenges.

The consolidated source currently defines 27 tables, 289 columns and 36 foreign keys. The clone has 28 tables and 37 foreign keys because it includes the Phase 17 registration-email challenge table and relationship.

These counts describe the inspected source and clone only. They do not establish the state of the protected local database or any hosted database.

## 3. Findings

### DBR-001 — Doctor identity is represented twice

The doctors table has its own surrogate id plus a nullable user_id. The application uses users.id as the doctor identity in appointments, ratings, schedules, visits, prescriptions, and authorization. The profile-table id is therefore a second identifier that most workflows do not use.

In the clone, all 32 doctor profile IDs differed from their linked user IDs. A dashboard analytics query compares appointments.doctor_id and ratings.doctor_id against doctors.id, although those facts store users.id. As a result, appointment and review counts were different from the correct counts for 31/32 and 29/32 profiles respectively.

**Target:** make doctor_profiles.user_id the profile primary key and foreign key to users.id. Keep doctor-specific foreign keys consistently tied to that shared user key. Remove the second profile identity only after all API and UI contracts have a compatibility mapping.

### DBR-002 — Doctor state is duplicated and can diverge

Doctor availability exists in doctors.available and doctor_schedule_settings.is_available. The public doctor API prefers schedule settings, while dashboards and some portal queries still read doctors.available. The toggle endpoint writes both values, but ScheduleService.updateSchedule updates the schedule setting only.

The inspected clone happened to have matching values for all 32 profiles. That is not a guarantee: two writable fields create a divergence path.

**Target:** retain one canonical availability field in doctor schedule settings or doctor_profiles, with all readers and writers using it. Remove the legacy duplicate only after callers are migrated.

### DBR-003 — Doctor rating cache is inconsistent with rating facts

The clone had 30 of 32 doctor profile rows whose stored doctors.rating value differed from the calculated average of the associated ratings. The public doctor list calculates from rating rows, but other dashboards and portal views read the stored value.

**Target:** treat ratings as the source of truth and calculate the average/count in a shared query or database view. If a cached aggregate is retained for performance, update it transactionally and run reconciliation as a first-class maintenance job. Do not silently overwrite existing rating values during migration; compare and record the derived result.

### DBR-004 — Appointment time and rescheduling mix facts, state and display values

appointments.time is VARCHAR(20), and appointment_time_range is a display string. In the clone, 480 appointment times used HH:MM while six used 12-hour text. The application has a normalization helper that accepts both, but storage should have one canonical representation.

The appointment status enum includes Reschedule Requested while reschedule_status independently includes pending. Reschedule request, proposed slot, response and reason fields are all stored on the appointment row; prior request history is not represented as a separate entity.

**Target:** use a canonical starts_at DATETIME (or a DATE plus TIME pair) with duration_minutes. Calculate display ranges in the application. Keep the booking status limited to booking lifecycle states. Add appointment_reschedule_requests for each request/offer/decision, preserving multiple attempts and actor/reason/timestamps. Record appointment status changes in an append-only event history.

### DBR-005 — Role-specific preferences are mixed together

user_preferences contains general theme and notification settings, doctor controls such as accept_new_patients and working hours, and an admin_default_tab value. Doctor working hours/duration also exist in doctor_schedule_settings and doctor_schedule_weekly.

**Target:** keep account-wide preferences in user_preferences; move doctor-specific booking and profile settings into doctor_profiles or doctor_schedule_settings; move admin UI preferences into an admin preference structure. Decide the fate of each existing field from observed UI/API use before moving it.

### DBR-006 — Audit history has overlapping schemas and a legacy migration collision

admin_audit is the active general audit path, medical_record_audit stores specific EMR corrections, patient_audit_log has a legacy read route, and schedule_audit_log has no current application reader/writer found in the checked source. medical_history_updates is a clinical timeline and is not the same thing as an audit event.

Historical Phase 8 can create patient_audit_log and schedule_audit_log in older shapes without foreign keys. The archived Phase 10 script uses CREATE TABLE IF NOT EXISTS and therefore does not upgrade those existing shapes. Current Phase 13 unconditionally drops four expected FK names and can fail on that legacy path.

**Target:** design one versioned audit-event model for new writes, but preserve the old tables and API contract during transition. Do not merge, drop, truncate, or convert legacy values until every column mapping, reader and external dependency is proven. Keep the clinical timeline separate from security/audit events.

### DBR-007 — Fresh schema and migration history are out of sync

The master schema contains Phase 16 registration intake but does not contain Phase 17 users.email_verification_required, users.email_verified_at, registration_email_challenges, or the expanded queue status values. The recent clone includes all of those structures. Before this review, the README and migration guide described Phase 10-15 as the ordered upgrade set and contained outdated email-verification statements; they were corrected on 18 November to document Phases 10-17 and the required fresh-install Phase 17 step. The canonical master still omits those Phase 17 structures. There is no migration ledger.

**Target:** make the v2 schema the exact current state, add a schema migration ledger with version, checksum, applied timestamp and engine compatibility, and make release/setup documentation derive from the same versioned migration list.

### DBR-008 — Duplicate and overlapping indexes need rationalization

The clone contains 15 clear duplicate or prefix-covered non-unique indexes, including non-unique indexes duplicating unique keys on users, doctors' schedules, medical_records, prescriptions, ratings, preferences and one-to-one visit tables. There are also prefix candidates on appointments, weekly schedules and password resets.

These are candidates, not automatic drop instructions. Each must be checked against actual query plans and migration compatibility before removal.

**Target:** define indexes from query patterns and constraints, retain only those justified by EXPLAIN/runtime evidence, and include index review in migration tests.

### DBR-009 — Business rules are often enforced only in application code

The schema has almost no CHECK constraints: the clone exposed only JSON-validity checks on schedule_audit_log. Important rules such as rating range, weekday range, duration bounds and boolean/status consistency are mainly PHP validations.

**Target:** add enforceable domain constraints where supported by the chosen MariaDB baseline, while retaining application validation for useful error messages. Candidate checks include weekday 1-7, rating 1-5, positive appointment duration, valid schedule intervals, and boolean values.

### DBR-010 — Singleton and lookup integrity are only partly expressed

hospital_settings is intended to be a single-row table but has no database-enforced singleton key. egypt_cities has a governorate relationship but lacks a unique key on (governorate_id, name). The inspected clone had one settings row and no duplicate city/governorate pairs, but the schema does not prevent future duplicates.

**Target:** enforce the hospital settings singleton pattern and unique governorate/city names. Add indexed governorate/city foreign keys to patient location records after validating free-text values and defining a non-lossy mapping.

### DBR-011 — Several legacy display snapshots and free-text values need explicit meaning

appointments stores patient_name, doctor and department alongside IDs. doctors.name duplicates users.name; doctors.specialty duplicates the department concept in some flows. medical_records stores governorate and city as text even though lookup tables exist.

Not every snapshot is bad normalization: appointment-time names/departments may be deliberately retained to describe a historical booking. The current schema does not consistently distinguish a deliberate snapshot from an accidental duplicate.

**Target:** name snapshot fields explicitly (for example, *_snapshot) and keep them only where they preserve booking-time meaning. Use canonical profile names for current display. Store structured geographic IDs while preserving address/free-text fields and the original text for unmappable legacy records.

## 4. Existing-table disposition

This map accounts for all 27 tables in the consolidated master plus the Phase 17 challenge table present in the inspected clone.

| Current v1 table | Proposed v2 disposition |
|---|---|
| users | Retain as the canonical account/authentication identity. Move patient-only demographic fields to patient_profiles when caller mapping is complete. |
| departments | Retain as the canonical department catalog. Keep the department ID authoritative; name snapshots are for historical display only. |
| doctors | Replace with doctor_profiles using user_id as its primary key. Migrate specialty/experience/department and selected profile fields; remove duplicate identity/name/rating/availability only after compatibility mapping. |
| appointments | Rebuild around patient_user_id, doctor_user_id and canonical starts_at/duration. Preserve intentional booking-time snapshots. Move reschedule attempts to a child table. |
| visit_workflow | Merge its lifecycle state/timestamps into clinical_encounters after proving the existing state transition graph. |
| visit_drafts | Keep as a transient encounter-draft child only if autosave requires an independent lifecycle; otherwise merge draft state into the encounter. Define expiry/cleanup. |
| notifications | Retain as the user-facing notification history. Make polymorphic ref_type/ref_id semantics explicit or replace with typed links. |
| notification_outbox | Retain as the durable delivery queue, including its intentional lack of a recipient FK. Preserve retry, idempotency and retention semantics. |
| contact_messages | Retain as the support/contact inbox with explicit owner, status and retention policy. |
| ratings | Retain rating/review facts linked to an appointment and canonical doctor/patient keys. Derive aggregate rating values instead of trusting a stale duplicate. |
| password_resets | Replace with hash-only password_reset_tokens. Remove the legacy raw-token column only after all readers/writers and historical retention are confirmed. |
| user_preferences | Keep account-wide preferences only. Split admin UI defaults and doctor booking/schedule preferences to their owning domains. |
| medical_history_updates | Rename/model as medical_history_events. Keep this clinical timeline separate from technical audit logs. |
| medical_records | Move to patient_medical_profiles (one-to-one); preserve current narrative values and original location text. Normalize clinical lists only when structured use cases and mappings are defined. |
| visit_notes | Consolidate with visit_workflow into clinical_encounters when one encounter per appointment and current API compatibility are proven. |
| prescriptions | Retain prescription headers; link to the clinical encounter and canonical patient/doctor identity. Preserve the one-per-appointment rule unless requirements change. |
| prescription_items | Retain as prescription child items with ordering and medication constraints. |
| hospital_settings | Retain with a database-enforced singleton key and TIME-typed operating-hour fields. |
| doctor_schedule_settings | Retain as the canonical one-row-per-doctor settings record; use one canonical availability field. |
| doctor_schedule_weekly | Retain with primary key (doctor_user_id, day_of_week), TIME columns and weekday checks 1-7. |
| admin_audit | Migrate new audit writes to audit_events after defining actor/entity snapshots and immutable event semantics. Keep old table readable during transition. |
| medical_record_audit | Preserve detailed EMR correction history; map into audit_events only with explicit clinical-field and reason semantics. Keep legacy rows/reader compatibility. |
| patient_audit_log | Preserve legacy columns and rows; maintain the pal.* compatibility response until all consumers are ruled out. No destructive consolidation. |
| schedule_audit_log | Preserve as a legacy archive until data retention and external consumers are ruled out; there is no current app reader/writer in the checked source. |
| egypt_governorates | Retain as the canonical governorate lookup with unique normalized names. |
| egypt_cities | Retain with unique (governorate_id, name), FK and lookup indexes. |
| registration_intake_queue | Retain as the encrypted pre-account queue, with no users FK. Preserve lease/retry, key-version and ciphertext-deletion behavior. |
| registration_email_challenges (Phase 17) | Retain as the one-time challenge child of registration_intake_queue with hash-only token storage, expiry, consumption/revocation and queue FK. |

No v1 table is authorized for deletion by this mapping. The table disposition is a target contract; any rename, merge or retirement requires an explicit source-to-target row mapping, reader/writer compatibility and restore-tested ETL.

## 5. Proposed v2 domain model

The following is the design direction, not an already-created production schema.

### Identity and profiles

- users: authentication, role, account lifecycle, verified email, contact details and shared account identity.
- patient_profiles: patient-specific demographic/contact fields that do not belong on the authentication row.
- doctor_profiles: shared primary key user_id, department, specialty, experience, public profile data and patient-acceptance preferences.
- departments: canonical department catalog and activation state.
- user_preferences: theme and account-wide notification preferences.
- admin_preferences: administrator UI defaults, if still required.

### Scheduling and booking

- doctor_schedule_settings: one row per doctor user ID, availability, duration, daily capacity and break rules.
- doctor_weekly_schedules: composite key (doctor_user_id, day_of_week), TIME columns, one row per weekday.
- appointments: canonical patient/doctor keys, department, starts_at, duration snapshot, booking status, created/updated timestamps and intentionally named display snapshots.
- appointment_reschedule_requests: child rows for original request, offered slot, actor, response, reason and decision timestamps.
- appointment_events: append-only state and actor history where needed for support and traceability.

### Clinical records

- clinical_encounters: one encounter per appointment, with visit workflow state and clinical note fields, avoiding a separate status row, draft row and note row where one coherent record is sufficient.
- encounter_drafts or encounter revisions: retain only if autosave/revision semantics need a separate lifecycle; define explicit expiry and promotion rules.
- prescriptions and prescription_items: preserve the one-prescription-per-appointment rule unless product requirements explicitly change.
- patient_medical_profiles: one-to-one current clinical summary.
- structured allergies, conditions, medications and surgeries: add only where the application needs reliable searchable data. Keep the current free-text content during the first migration and do not guess how to split prose into clinical facts.
- medical_history_events: keep clinical timeline facts distinct from technical/admin audit records.
- ratings: immutable patient review facts linked to the completed appointment.

### Messaging, audit and registration

- notifications: user-facing delivered notifications.
- notification_outbox: durable retry queue with its intentional no-recipient-FK design preserved to avoid reintroducing proven lock-order problems.
- contact_messages: support inquiries and replies, with a documented retention policy.
- audit_events: common append-only event envelope for new audit writes; old specialized audit tables remain readable during transition.
- password_reset_tokens: hash-only tokens, expiry, consumption and request metadata.
- registration_intake_queue and registration_email_challenges: preserve the pre-account encrypted queue and its challenge lifecycle; the queue intentionally has no users FK.
- migration_ledger: explicit schema version, source checksum, apply timestamp and compatible engine range.

### Location and configuration

- egypt_governorates and egypt_cities: canonical lookup data with uniqueness constraints.
- hospital_settings: enforce one configured row, with time values stored as TIME.
- Patient addresses reference location IDs when known, while retaining free-text/address snapshots for compatibility and unmappable legacy values.

## 6. Migration strategy

A parallel v2 migration is safer than altering the live/legacy schema in place.

1. Freeze the current schema as v1 and generate a signed-off table/column/FK/index inventory. Confirm all v1 API and UI contracts, including external/legacy consumers.
2. Create a new versioned v2 master schema and migration ledger. Do not replace healthbridge.sql yet.
3. Build an ETL mapper from each v1 table to the v2 target. Every source row must map to a target row, a documented preserved legacy/archive row, or a quarantined exception with an explicit reason. No silent drops.
4. Normalize legacy times and role/ID relationships only when an unambiguous mapping exists. Keep original source values in the migration archive for traceability.
5. Reconcile stored doctor ratings with rating facts and report differences; do not silently choose one value.
6. Preserve all audit tables and legacy columns until compatibility readers and external consumers are ruled out.
7. Run migration against a newly restored disposable clone. Compare table-level counts, primary-key coverage, foreign-key orphans, row hashes, enum/state mappings, time conversions, audit row counts and AUTO_INCREMENT behavior.
8. Run the existing focused PHP/Playwright workflows against the v2 clone: registration/email verification, login/reset, doctor management, scheduling, booking/rescheduling, clinical encounter, EMR, prescriptions, ratings, audit, notifications and worker replay.
9. Run EXPLAIN on major application queries and retain only the necessary indexes. Run mysqlcheck and a full backup/restore comparison.
10. Only after compatibility and restore gates pass, plan a separate explicitly approved cutover. Keep the original schema/backup as rollback source until the new system is accepted.

## 7. Non-negotiable preservation rules

- No destructive ALTER, DROP, TRUNCATE, broad rewrite or reset on the protected database.
- No deletion or fabricated interpretation of clinical, appointment, rating, registration, notification or audit history.
- Keep notification_outbox and registration_intake_queue free of recipient/user foreign keys for their documented transactional/lock-safety reasons.
- Do not infer missing patient, doctor or department IDs from names unless the match is unique and reviewed.
- Do not treat the current synthetic clone as proof of every historical schema path.
- Do not run Phase 13 against the legacy Phase 8 audit-table shape.
- Do not claim MySQL 8 migration compatibility until the current MariaDB migrations are ported and tested on a real Oracle MySQL 8 server.

## 8. Current disposition

This audit establishes the target architecture and defects to address first. It does not yet implement schema v2, change any database, or close Milestone 2. The immediate next implementation slice should be a versioned v2 schema prototype and a deterministic migration/compatibility test harness on a newly created disposable database derived from the recent synthetic clone. Fix the clearly incorrect dashboard doctor-key comparisons as a separate application defect only after the dashboard/API contract tests are prepared.


## 9. v2 prototype and synthetic ETL execution ? 18 November 2026

The architecture review moved from proposal to an isolated schema/ETL rehearsal in database/v2/. This does not replace the current application schema and does not authorize cutover.

### Implemented prototype

- 001_schema.sql creates the MariaDB 10.11+ v2 base schema; versioned incremental SQL files extend it.
- users.id is the canonical account identity. doctor_profiles.doctor_user_id is both profile PK and FK to users.id.
- Doctor profile/rating cache and duplicate preference fields are retained in explicit legacy archive tables rather than silently discarded.
- Appointment starts_at and duration_minutes replace the mixed time/string model; source time/range values are retained in snapshot fields.
- Reschedule requests are separate child rows. Clinical workflow and note content are consolidated into one encounter while independent drafts remain a separate entity.
- Composite foreign keys ensure an encounter, prescription and rating match the patient and doctor on their appointment.
- Phone normalization uses explicit UTF-8 collation in generated-column expressions, verified on logical dump/restore.
- The schema includes migration checksums, row lineage and explicit migration-exception tracking.

### Disposable data migration result

Source healthbridge_hb_auth_email_verify_20261114 (MariaDB 10.11.16) was treated as SELECT-only. Target healthbridge_v2_prototype_20261118 received the ETL in one transaction. The final v2 state contains 37 InnoDB base tables, one view, 42 FK constraints, 23 CHECK constraints, 166 users, 32 doctor profiles, 133 patient profiles, 133 clinical profiles, 486 appointments, 12 reschedule-request rows, 451 clinical encounters, 99 prescriptions, 186 prescription items, 79 ratings, 558 notifications, 13 outbox rows, 20 encrypted registration jobs and 11 email challenges.

- 3,466 source-to-target row lineage mappings.
- 100 accepted, non-blocking legacy warnings: 52 unmatched location labels, 30 stale doctor-rating cache values, 12 legacy reschedule statuses represented as separate request rows, and 6 normalized 12-hour appointment time values.
- 0 open blocking exceptions.
- 27 field-level equivalence checks returned zero differences, including account credentials/session/verification state, profile fields, legacy archives, medical data, appointment timestamps/snapshots, reschedule requests, encounter workflow/notes, prescription facts, rating facts, notification/outbox state, registration ciphertext and challenge hashes, phone normalization, location lookups, schedules, hospital settings, legacy admin audit and derived rating aggregates.
- All 42 foreign keys were checked with zero orphan rows. Eight positive/negative constraint tests passed, including the composite appointment-owner guard.
- CHECK TABLE passed 37/37 tables.
- Before/after SHA-256 row manifests show the v1 source clone unchanged: 28/28 table row counts and hashes matched.

### Backup/restore acceptance

A logical backup of the v2 prototype was restored byte-preservingly into separate disposable target healthbridge_v2_restore5_20261118. Final comparison passed: 37/37 table row hashes, 379 column definitions, 178 index rows, 48 FK column mappings across 42 constraints, 23 CHECK definitions, one view definition/security (after normalizing MariaDB's expected schema-name qualification), engine/collation and AUTO_INCREMENT high-water marks. Restored CHECK TABLE passed 37/37. Rolled-back negative tests had consumed auto-increment sequence values in the source v2 prototype; the restore's counters were explicitly advanced to match before final comparison. This changed only the restore test database.

Detailed result: database/v2/ETL_RESULT.md. Reproducible scripts and sequence: database/v2/README.md.

### Remaining boundaries

This confirms a schema and data-mapping prototype only. The current PHP application, APIs, frontend, workers and database bootstrap still use v1; no application path points to v2. The tested source clone has empty patient/schedule audit, medical-record audit, drafts, medical-history-update, contact and password-reset tables; those non-empty cases still need a populated fixture. The older Phase 8 audit-table shape remains an explicit untested mapping blocker. No protected main DB, NOVA Home database, hosted resource, secret or deployment was accessed or changed. No staging, commit, push or deployment occurred.

**Disposition:** v2 schema/ETL prototype and restore-tested synthetic migration are complete for this fixture. HB-DB-008 remains open for the historical Phase 8 fixture, non-empty zero-row-table mappings, application compatibility layer, end-to-end workflow acceptance and separate cutover/rollback plan. Milestone 2 remains active.


## 10. Application compatibility discovery — 18 November 2026

The next stage began with a static caller inventory rather than a blind schema switch. The detailed findings and field-level API contract are recorded in `docs/HEALTHBRIDGE_V2_APPLICATION_COMPATIBILITY.md`.

The application is coupled to the v1 model in doctor, schedule, appointment, reschedule, rating, clinical, preferences, auth and audit callers. The safe first vertical slice combines doctor directory/profile, operational availability, schedule and slot reservations: slot generation depends on legacy appointment and embedded reschedule columns, while v2 separates requests into child rows. A schedule-only SQL rename is not sufficient and must not be enabled for public availability or booking.

The inventory also re-confirmed a current analytics defect: doctor_id values refer to users.id, but DashboardAnalyticsService compares them to doctors.id. In the inspected 32-doctor fixture this affected appointment counts for 31 profiles and review counts for 29. The active availability KPI also reads the duplicate doctors.available column rather than the schedule setting used by public availability. These are tracked as separate source-correctness fixes, not silently mixed into the v2 prototype.

**Disposition:** v2 schema/ETL and restore are complete for the tested synthetic fixture; application compatibility is now in discovery/design, not implementation. Keep v1 as the runtime default. The next implementation step is explicit v1/v2 doctor-profile and schedule repository contracts plus rollback-safe dual-schema tests; appointment/reschedule slot reservation semantics are a prerequisite before v2 booking can be enabled.


## 11. First opt-in application repositories — 18 November 2026

The application compatibility discovery now has a first tested implementation slice. Three explicit-mode classes in `services/Repositories/` adapt doctor profile reads, schedule settings/weekly rows, and booked-slot reservations for v1/v2. They preserve `users.id` as the account identity, the current doctor API DTO aliases, seven-day schedule shape and original/requested/suggested slot holds. The v2 public-profile row ID comes from the retained legacy archive; it is not used as the booking identity.

PHP lint passed for the repositories and test scripts. The read-only contract suite passed 11/11 assertions and compared 448 doctor/date reservation combinations with zero differences. Transactional rollback tests passed for v1 schedule settings/availability and v2 schedule settings/weekly upsert. A dedicated temporary-state test passed suggested, approved, rejected and cancelled reservation transitions and rolled both test databases back.

These repositories are not wired into `ScheduleService` or existing endpoints. The current application remains v1. Remaining integration work includes all ScheduleService query paths (including hospital TIME formatting, daily capacity, slot generation/validation, batch availability and reset), doctor create/update/archive and department assignment, then booking concurrency plus API/UI checks. The actual fixture had no suggested reschedule rows; those transitions were covered only by synthetic rollback scenarios. Two zero-review doctors expose stale legacy cached ratings that v2 intentionally does not reuse.

No protected database or hosted resource was accessed, and no application DB configuration was changed. No commits, push or deployment. HB-DB-008 remains open until full workflow compatibility, historical/empty-table ETL fixture coverage and a separate cutover/rollback plan are accepted.


## 12. ScheduleService explicit schema mode — 18 November 2026

`ScheduleService` now supports an explicit v1/v2 mode while preserving v1 as its one-argument default. Schedule reads/listing, settings/weekly writes, availability toggles, reset-to-default and booked-range queries delegate to the schema-aware repositories. Hospital TIME values normalize to the existing HH:MM API contract. Shared PHP slot-generation/capacity/validation logic is used for both modes. Caller-owned transactions are not committed or rolled back by the repository/service unless that layer started them.

The read-only service contract passed 9/9 assertions across all 32 doctors and 448 doctor/date pairs: schedule and working schedule DTOs, all-schedule listing, hospital hours, public availability/next slot, reservation ranges, slot generation and booking validation matched. Service-level rollback tests verified v1 duplicate availability restoration, v2 settings/weekly writes and v2 seven-day reset-to-default. Profile-preference rollback tests confirmed profile acceptance remains a separate field from operational schedule availability.

A remaining semantics issue is recorded: `accept_new_patients` is saved in the doctor preference model, but the inspected booking/reschedule services currently enforce schedule availability and do not consume that preference. The user-facing policy must be decided separately and, if required, enforced server-side under the existing doctor lock. The compatibility adapter preserves the schedule-first availability DTO rather than silently changing this behavior.

Existing API endpoints still instantiate ScheduleService with one argument and remain v1. No endpoint, route, JS contract or DB selection has moved to v2. HB-DB-008 remains open for doctor/profile mutation, booking/reschedule locking/concurrency, other domain adapters, historical/empty-table ETL fixtures, API/browser acceptance and cutover/rollback.


## 13. Doctor management mutation compatibility — 18 November 2026

The explicit-schema application adapter now includes `DoctorManagementRepository` for create, account update, department assignment and status/archive lifecycle. Doctor identity is `users.id` in both schemas. It creates the v2 `doctor_profiles` row and corresponding schedule/preferences, maps v1 `admin_audit` writes to v2 `audit_events`, validates active departments, syncs specialty, revokes sessions on status changes, and preserves clinical evidence during archive. v2 cancellation closes active reschedule request rows and creates appointment events; notifications are enqueued transactionally.

The rollback-only doctor-management contract passed on both disposable schema fixtures. It verified new account/profile/schedule creation, identity updates, department reassignment, audit writes, reactivation and session-version behavior. Doctor 9 was blocked from deactivation by unfinished clinical work in both schemas. Doctor 3 was successfully archived/re-enabled, with four untouched appointments cancelled in each. All mutation transactions were rolled back and touched AUTO_INCREMENT counters restored.

No live API endpoint or DepartmentService method was opted into v2. Existing application runtime remains v1. Booking/reschedule concurrency and locking are the next compatibility boundary; no cutover is permitted before that and the remaining clinical/auth/notification worker routes pass.


## 14. Booking and reschedule transaction model — 18 November 2026

The opt-in application layer now has `AppointmentBookingRepository` and `RescheduleTransactionRepository` for v1/v2 transactional operations. Booking uses a common doctor-first row lock, locks profile and department, validates the slot while holding the serialization lock, then locks the patient. Audit and notifications are committed atomically with the booking; v2 adds the appointment event and stores snapshots plus starts_at/duration fields.

Reschedule transitions map the legacy embedded state into v2 request child rows. The v2 parent booking remains confirmed while a request is active; the original/current, patient-requested and doctor-proposed slots are independently reserved as appropriate. Accepted/approved requests move the appointment and close the child as approved; rejected/cancelled requests leave the original/current appointment untouched. A guard prevents a reschedule from progressing if the parent booking is cancelled. V2 audit and appointment events plus notification outbox rows are written transactionally.

Read DTO methods flatten child request data back into the current pending-reschedule API shape. The read-only comparison passed 5/5 checks for the 12 active synthetic requests across 11 doctors and for no-history defaults.

The full booking/reschedule lifecycle passed under caller-owned rollback transactions on both fixtures; a two-independent-connection v2 test confirmed doctor-row serialization prevents two bookings on the same slot. Synthetic committed test rows and all affected auto-increment counters were cleaned/restored. Department reassignment's lock order was corrected to match booking (doctor → profile → department).

No current API endpoint, JS caller or DB config uses these repositories yet. Existing routes remain v1. Clinical workflow/encounter, doctor/patient appointment read screens and other dependent domains must be adapted before route integration or any cutover.


## 15. Clinical encounter and patient timeline compatibility — 18 November 2026

The opt-in application layer now maps legacy workflow, note and draft tables into v2 clinical encounters and separate encounter drafts. `ClinicalEncounterRepository` has explicit schema mode; `VisitWorkflowService` and `VisitDraftService` default to v1. Workflow DTOs preserve title-case API states. V2 note-only records use `uninitialized` and remain hidden as a workflow until explicit creation.

Clinical note, workflow, draft, EMR, print, workspace and rating-eligibility routes now use shared services; none opted into v2. Clinical access and patient timeline services accept explicit v1/v2. Evidence: 451 workflow rows and 216 note rows match; 4,256 access pairs match; 486 patient appointment timeline DTOs match; 1,079 medical timeline events match. Seeded notes/workflows/drafts and transitions passed rollback tests on both disposable fixtures. All touched clinical PHP files lint.

Next: prescription data access and lifecycle compatibility. Reinspect exact prescription DDL, service and endpoint contracts before changing anything. Runtime remains v1; no cutover.

## 16. Prescription application compatibility - 18 November 2026

The opt-in application layer now has schema-aware prescription access and lifecycle operations. v2 `patient_user_id`/`doctor_user_id` keys and lowercase statuses are translated into the existing DTO contract. Create uses canonical ownership checks and rejects prescriptions while a child reschedule request is active. Updates, completion and cancellation are transactional; v2 audit records go to `audit_events`, and notification outbox writes are kept with the business transaction.

The same service now supplies prescription details, role-scoped lists, search/status filters, item hydration, patient lookups and doctor active-prescription guards. The caller-owned completion method allows the visit-completion transaction to finish prescriptions without nested transactions. Selected patient portal prescription cards also support the v2 schema.

Read/mutation evidence passed against the disposable v1 and v2 fixtures: 99 headers, 186 medication items, list/filter/portal DTO parity, create/update/complete/cancel/ownership rejection and outer-transaction rollback. Synthetic rows, audit entries, notification preferences and counters were cleaned/restored. Target endpoints use the shared service but stay on v1 by default.

The remaining patient portal aggregate, alert, download and search queries plus settings/account lifecycle consumers are still not fully v2-compatible. No route, runtime database configuration or deployment was switched.

### Patient portal read compatibility checkpoint - 18 November 2026

Expanded the schema-aware PatientPortalService read layer while retaining the v1 default:

- Added v2 dashboard overview using normalized appointments, latest reschedule child state, doctor profiles/rating summary, schedule availability, shared notification counts and hospital settings.
- Added v2 medical-profile read by joining patient_profiles and patient_medical_profiles, preserving the existing portal DTO.
- Added v2 favorite/recommended doctor aggregates using doctor_profiles, doctor_schedule_settings and v_doctor_rating_summary. The schedule's is_available remains distinct from accepts_new_patients; live v2 rating aggregates are used instead of known-stale legacy rating cache values.
- The v2 favorites aggregates exclude appointments with active reschedule children to preserve the legacy confirmed-only semantics. Unspecified tie order in legacy top-three/primary-doctor queries is treated as equivalent only when the fixture proves a shared cutoff/top-count tie.
- getNotifications continues to use the shared NotificationService because both schemas expose the same notifications and user_preferences read contract; grouped notifications were verified for parity.

tools/test_v2_patient_portal_snapshot.php now exercises all 133 synthetic patients across profile completion, medical profile, dashboard overview, notification groups, favorites compatibility, the 14-field health snapshot, health alerts, insights, downloads and selected unified-search terms. The suite passed on the exact disposable v1/v2 fixtures. PHP lint passed for the service and test. The fixture-only checks did not opt in any endpoint or change the runtime database selection.

**Still open:** broader direct-SQL/API compatibility audit; authentication and account lifecycle flows; notification outbox/worker behavior and downstream consumers; settings and deletion flows; complete end-to-end regression. Keep v1 as runtime default until all workflows and workers have evidence-backed v2 coverage. No main/protected/hosted/NOVA database, .env or secrets were accessed. No Git staging/commit/push or deployment occurred.