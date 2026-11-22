# HealthBridge — Local V2 Cutover Record

**Date:** 20 November 2026  
**Scope:** Local XAMPP/Apache web application only  
**Status:** Apache web/API cutover completed; CLI workers and hosted deployment remain separate gates.

## Active local runtime

The normal local website at http://127.0.0.1/optimized/ now uses:

- **Database:** healthbridge_v2
- **Schema selectors:** all 15 explicit HEALTHBRIDGE_*_SCHEMA=v2
- **Configuration:** a local-only block in the untracked project .htaccess, bounded by:
  - HEALTHBRIDGE LOCAL V2 CUTOVER BEGIN
  - HEALTHBRIDGE LOCAL V2 CUTOVER END

The .env file was not read, edited, or replaced. No database credentials were changed. The Apache environment mechanism was tested in an isolated child directory before the main switch, then the main site's selected database and all 15 selectors were verified after cutover.

## Data migration provenance

The source was the actual local V1 database healthbridge, not the earlier synthetic prototype fixture.

1. A disposable source snapshot healthbridge_hb_etl_source_cutover_20261120c was created. All 27 existing source-table business-column row fingerprints matched the active V1 database. The legacy generated phone_normalized derivative and its generated-column unique index were intentionally not copied into the source snapshot; V2 recreates its own normalized generated column from the preserved raw phone values.
2. Phase 17 email-verification schema was applied only to that disposable source snapshot. It now has 28 base tables, 164 users and 131 medical records; the active V1 schema was not migrated.
3. The existing synthetic ETL runner was adapted in a temporary file outside the repository to replace its synthetic hard-coded row counts with dynamic counts from the actual disposable V1 source. The repository's migrate_synthetic_clone.php was not changed.
4. The ETL committed to disposable candidate healthbridge_v2_etl_target_cutover_20261120d: 3,433 lineage mappings, 98 accepted legacy exceptions and zero blocking exceptions.
5. The candidate was copied into the canonical healthbridge_v2 schema and independently verified. The intermediate candidate, source snapshot and restore rehearsal remain available as evidence.

## Canonical V2 dataset

Selected row counts at cutover:

| Table / entity | Rows |
|---|---:|
| Users | 164 |
| Patient profiles | 131 |
| Patient medical profiles | 131 |
| Doctor profiles | 32 |
| Departments | 9 |
| Appointments | 486 |
| Reschedule requests | 12 |
| Clinical encounters | 451 |
| Prescriptions | 99 |
| Prescription items | 186 |
| Notifications | 556 |
| Notification outbox | 11 |
| Registration intake queue | 14 |
| Registration email challenges | 0 |
| Migration row mappings | 3,433 |
| Migration exceptions | 98 |

The 98 migration exceptions are accepted legacy differences; there are zero open blocking exceptions.

## Verification evidence

- V2 structure: 37 base tables, one view, 42 foreign keys and 24 check constraints.
- Canonical clone: 37/37 table data fingerprints match the migrated candidate; 37/37 CHECK TABLE checks pass.
- Foreign-key orphan scan: zero orphaned references across all 42 constraints.
- Strict backup/restore verifier: PASS for canonical V2 vs. healthbridge_v2_restore_cutover_20261120e; 37/37 row hashes, 380 column definitions, 181 index rows, 48 foreign-key column mappings, 24 check constraints, view definition/security, storage engines and AUTO_INCREMENT metadata match.
- V2 service reads: admin analytics, all 12 patient-portal sections, doctor schedule/availability (seven weekly schedule rows), and department projections all pass.
- V2 credential repository reads: admin, doctor and patient demo records resolve with the expected role, active state, password-hash presence, positive session version and email-verification fields.
- Apache after cutover: all 15 schema selectors resolve to V2; home, doctors, services and login pages return HTTP 200; doctor directory returns 32 entries; public availability returns 32 entries; department API returns nine entries; governorate API returns 27 entries.
- Anonymous protected dashboard request returns HTTP 401. Direct requests to .env, database documentation and PHP includes remain blocked.
- The temporary test routes and the candidate canary server on port 18556 were removed/stopped.

The earlier 241-assertion authenticated HTTP suite and 4,256-decision clinical authorization parity run were accepted against the retained synthetic V2 fixture. They are supplemental evidence, not a substitute for signing in against the newly migrated real-data database. On 20 November, the owner reported that the local website and roles appear to be working after manual review; this is owner-observed UI acceptance, not a separately captured automated Playwright role-login run.

## V1 preservation and rollback

The original local V1 database healthbridge remains present and unchanged. CLI PHP still resolves to V1 because .env was not changed; this is intentional until the worker configuration is addressed separately.

The original local .htaccess was backed up before the switch:

C:\xampp85\migration-backups\healthbridge\hb-v2-cutover-canary-20261120\root-htaccess-before-v2-cutover.bak

To roll the Apache web runtime back to V1, restore that backup to:

C:\xampp85\htdocs\optimized\.htaccess

or remove only the block between the two cutover markers. This changes Apache configuration only; it does not delete either database.

**Important:** rollback returns the website to the pre-cutover V1 data. Any writes made to V2 after cutover are not automatically replicated back to V1. Preserve/export those writes before rolling back.

## Remaining gates - do not treat these as accepted

1. Owner manually reviewed the local site and reported that everything appears to be working. A separate automated Playwright login/session matrix against canonical V2 was not captured; treat that as a remaining test-quality limitation, not a reported functional blocker.
2. CLI/background workers are not switched: a CLI process using the normal .env still connects to V1. Do not start or schedule registration-intake or notification/outbox workers until their V2 database selection, retry behavior and mail side effects receive a separate review/approval.
3. Registration verification, resend and password-reset email delivery were not tested here. No SMTP, registration worker, notification worker, or scheduled task was invoked.
4. The hosted database and deployment were not changed. The hosted P0*/P1 release gates remain open and high priority for the later deployment phase.
5. Do not deploy the local-only .htaccess V2 block to a hosted environment.

No application source PHP files were changed for this cutover. No Git staging, commit, push, or deployment occurred.

## V1 artifact retirement — 20 November 2026

The former V1 file-based setup/migration bundle was moved outside the Apache document root to:

C:\xampp85\migration-backups\healthbridge\retired-v1-artifacts-20261120\

- Three V1 fresh-install SQL entrypoints were moved.
- The old database/archive SQL and Phase 10–17 forward-migration/preflight/ledger bundle was moved.
- Four V1-only CLI PHP helpers were moved: migration manifest generation, prototype migration ledger, ledgered V1 migration runner and demo hospital seeder.
- Total moved files: 30. All 30 destination SHA-256 hashes match the source hashes recorded before the move.
- An archive README and MANIFEST.sha256.txt explain contents and provenance.
- Current V2 tooling under database/v2/ remains in place. Shared runtime PHP under api/, includes/ and services/ remains in place.
- Original V1 MariaDB database healthbridge was not changed, renamed or dropped.


## QA preview runtime cleanup — 20 November 2026

The separate loopback V2 preview server formerly listening on `127.0.0.1:18555` (PHP PID 25544) was stopped and the listener was verified closed. Its temporary PHP session directory (10 files) and local auth-limit folder (attempt-state file plus temporary HMAC key) were removed. The older cutover-canary session directory (10 files) and empty temporary directory were also removed. The preview router, stdout/stderr logs, preview handoff and pre-cutover `.htaccess` rollback backup were retained. The synthetic preview schema `healthbridge_v2_local_preview_20261120` remains present but disconnected; it was not dropped. No PHP worker, SMTP flow, scheduled task, hosted resource or deployment was run or changed.


## Local database cleanup and retirement — 20 November 2026

Fourteen unused HealthBridge QA/ETL/restore schemas were exported before removal. The archive is outside the Apache document root at `C:\xampp85\migration-backups\healthbridge\db-retirement-20261120\` and contains fourteen full `.sql` dumps, `MANIFEST.csv` with per-schema SHA-256/size/object counts, restore instructions and `RESULT.md`. All 14 hashes and byte sizes verified; total SQL payload 7,372,100 bytes. The export set includes the previous synthetic preview DB, V1 historical QA/restore copies, the actual-data ETL source/target candidate, and older V2 restore duplicates.

The archived `healthbridge_v2_restore_cutover_20261120e` dump was restored into an isolated scratch schema before retirement. It recreated 37 base tables and one view; `CHECK TABLE` passed 37/37. The scratch schema was dropped afterward. All fourteen archived schemas were then dropped, and every name was independently confirmed absent. No target had an active connection at the pre-drop check.

The six online HealthBridge schemas are intentionally limited to: `healthbridge` (unchanged V1 rollback), `healthbridge_v2` (canonical active local Apache database), `healthbridge_hb_auth_email_verify_20261114` (source fixture used by QA), `healthbridge_v2_prototype_20261118` (synthetic V2 QA prototype), `healthbridge_v2_restore5_20261118` (fixture used by the backup/restore verifier), and `healthbridge_repair_test` (dashboard API test fixture). These four test fixtures remain because active scripts reference them. The archive `RESULT.md` contains the exact drop list and retention rationale.

Post-retirement HTTP smoke: homepage 200, doctors page 200, doctor API 200 (32 public doctors), department API 200 (9 departments), and anonymous patient dashboard 401 as expected. All 15 retired/scratch schemas were confirmed absent and all six retained schemas present. The original V1 and canonical V2 DBs were not dropped. No `.env` access/change, worker/SMTP/scheduled-task invocation, non-HealthBridge database action, hosted change, or deployment occurred.


## 20 November 2026 — canonical V2 role-login and worker readiness decision

The three standard demo identities (admin, doctor and patient) were confirmed present and active in canonical `healthbridge_v2`, with no email-verification requirement. A fresh automated login/session matrix was not executed: the installed BrowserAct catalog has two available browsers, but both are described for InfinityFree/NOVA work rather than HealthBridge. The existing admin Chrome tab displays an admin session and populated V2 data, but its page extraction also contains a stale dashboard fallback message, so this is not accepted as a fresh login test. Owner browser selection is required before operating either BrowserAct browser. Exact state and next acceptance matrix: `docs/HEALTHBRIDGE_ROLE_LOGIN_AND_WORKER_READINESS_20261120.md`.

The CLI database runtime probe selected `healthbridge` (V1). `process_registration_intake.php` explicitly constructs `RegistrationIntakeService::SCHEMA_V1`; the notification worker uses `getDB()` and therefore inherits the V1 CLI target. The two scheduled worker tasks were enabled on a 1-minute registration / 5-minute notification schedule. Their observed automatic runs returned exit code 0 but reported zero registration jobs claimed, zero verification emails sent, zero notification deliveries and zero pending outbox events. Both tasks were then disabled after the registration run finished; final state verified `Disabled` for both. The notification service includes a 30-day cleanup for delivered/skipped outbox rows; those automatic runs may have performed this retention maintenance, and its effect was not separately fingerprinted. No worker was manually invoked and no SMTP delivery was triggered by this review.

Worker decision: keep both tasks disabled until V2-specific worker target/schema guards, keyring handling, retry/retention acceptance and separately authorized mail-delivery checks are completed. The operational worker gate is explicitly deferred; it must not be bypassed by changing only `DB_NAME`.

## Final canonical V2 role-login acceptance — 20 November 2026

The admin/doctor/patient login and session matrix is now **PASS** against the canonical local Apache database healthbridge_v2. Each demo quick-login completed, current_user.php returned HTTP 200 with the expected identity/role, each role's own dashboard/read endpoint returned HTTP 200, five cross-role probes returned HTTP 403, and logout/session invalidation was confirmed by a subsequent HTTP 401 for each role. The independent BrowserAct Chrome session was closed after the test. No business data, appointments, clinical records, queue rows, mail or worker execution was changed or triggered.

HealthBridge is cleared to begin Milestone 3 UI/UX work. This is not a production/deployment approval. CLI workers still resolve V1 and both scheduled worker tasks remain disabled; V2-specific worker wiring/guard tests and separately approved mail delivery are deferred. Hosted P0*/P1 findings remain open high-priority release gates. Full test details: docs/HEALTHBRIDGE_ROLE_LOGIN_AND_WORKER_READINESS_20261120.md.