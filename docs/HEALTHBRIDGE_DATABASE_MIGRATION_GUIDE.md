> **Superseded — 24 November 2026.** The V1 → V2 migration this guide covers was completed in November 2026. It is retained as the migration's historical record; the final schema lives in `database/v2/`. Kept as a historical record of the V1 → V2 build. For the current system, see `README.md` and `docs/ARCHITECTURE.md`.

# HealthBridge — Database setup and forward-migration guide

**Reviewed:** 18 November 2026  
**Additional review:** 18 November 2026 — whole-schema architecture audit and read-only Phase 10-17 signature preflight added; no protected database access or mutation.  
**Scope:** Fresh setup, existing-install upgrades, migration compatibility and the observed local synthetic baseline.  
**Safety:** This guide does not authorize a hosted/production migration. Always take and verify a separate backup, identify the exact target, and use a disposable clone first.

**Current runtime / archive notice (20 November 2026):** This document is retained as historical guidance for the former V1 database track. The local Apache web/API now uses canonical healthbridge_v2; the original V1 database remains preserved for rollback. All V1 fresh-install SQL, forward migrations, archived DDL, PHP ledger manifests and V1-only CLI migration/demo helpers were moved to C:\xampp85\migration-backups\healthbridge\retired-v1-artifacts-20261120\ and hash-verified. The old database/healthbridge*.sql, database/migrations/ and associated tool paths shown below are historical source paths and no longer exist in the active project tree. **Do not copy or run commands from the old V1 setup/migration examples against the active V2 database.** See docs/HEALTHBRIDGE_LOCAL_V2_CUTOVER_20261120.md for current status and rollback.
## 1. There are two different setup paths

### Fresh database

The consolidated `database/healthbridge.sql` is the current master schema. A static inventory of its CREATE TABLE statements returns **27 tables**. It includes the Phase 10-16 structures, including the encrypted registration-intake queue, but not the Phase 17 email-verification challenge table or the two user verification columns.

- `database/healthbridge_empty.sql` sources the master schema, then inserts essential hospital settings and lookup data.
- `database/healthbridge_demo_data.sql` sources the empty wrapper, then inserts synthetic Quick Login and demo records.
- Neither wrapper sources the forward migrations. The consolidated schema contains the Phase 10-16 structures, but Phase 17 is currently missing from the fresh-install path. The current registration code depends on Phase 17; apply and verify database/migrations/phase17_registration_email_verification.sql after either wrapper before using the current registration flow.
- The base schema uses `CREATE DATABASE healthbridge` without `IF NOT EXISTS`, and it has no `DROP DATABASE`. It stops if the target name already exists. This is a fresh-install guard, not an upgrade mechanism.

Use exactly one fresh-install wrapper against a new, empty local database. The MariaDB 10.4 client must be run with `--abort-source-on-error`; never use `--force`. MySQL 8.0 client handling of `SOURCE` errors differs, as described in README.

### Existing database

Never run `healthbridge.sql`, `healthbridge_empty.sql` or `healthbridge_demo_data.sql` against an existing populated database. Existing-install upgrades use the forward migrations in this order:

| Migration | Purpose | Safety characteristics |
|---|---|---|
| Phase 10 — `phase10_reschedule_workflow.sql` | Adds reschedule state/fields/indexes and backfills original request fields for legacy pending requests. | Uses MariaDB `ADD ... IF NOT EXISTS` subclauses; data backfill is conditional. |
| Phase 11 — `phase11_auth_session_and_reset_security.sql` | Adds session versioning, reset-token hashes and IP/email throttling fields/indexes; hashes any legacy raw tokens, nulls the raw token column, and drops deprecated indexes on the old plaintext token column. | Uses MariaDB-specific `IF NOT EXISTS` DDL syntax. The final hash index is canonical; legacy index cleanup is included. Inspect token counts and index names before applying. |
| Phase 12 — `phase12_visit_follow_up_instructions.sql` | Adds completed-visit follow-up instructions to `visit_notes`. | Uses MariaDB `ADD COLUMN IF NOT EXISTS`. |
| Phase 13 — `phase13_clinical_audit_retention.sql` | Replaces delete cascades/nullifiers on clinical and audit relationships with `RESTRICT`. | **One-time / non-idempotent.** Drops and recreates 28 named foreign keys. Apply only when the exact source constraints are present and after backup. |
| Phase 14 — `phase14_normalized_phone_uniqueness.sql` | Adds stored generated `users.phone_normalized` and a unique key. | **One-time.** Its duplicate-normalization preflight must return 0 before the ALTER. It does not rewrite existing `phone` values. |
| Phase 15 — `phase15_notification_outbox.sql` | Adds the durable notification retry/outbox table. | `CREATE TABLE IF NOT EXISTS`; verify the existing table definition before deciding it is already applied. |
| Phase 16 - phase16_registration_intake_queue.sql | Adds the encrypted pre-account registration-intake queue and worker state. | CREATE TABLE IF NOT EXISTS; verify columns, indexes, engine and encryption metadata before treating an existing table as current. The consolidated master already includes this table. |
| Phase 17 - phase17_registration_email_verification.sql | Adds email-verification policy/timestamps to users, extends queue states and adds one-time email challenge storage. | MariaDB IF NOT EXISTS DDL; validate the user columns, queue enum/index and challenge table/FK as a complete signature. Missing pieces must be reviewed individually; do not assume a partially applied phase can be replayed blindly. |

There is currently **no migration ledger table** (for example `schema_migrations`, `migration_history` or `db_migrations`). Do not infer migration history from a checklist or filename. Determine state from the actual columns, generated-column definitions, indexes, foreign-key delete rules and required table definitions.

Before any existing-install upgrade:
1. Identify and verify the exact local/host/database/server version; do not disclose credentials.
2. Make a logical backup and prove it can be restored to a separate disposable database.
3. Inspect the expected signature for each migration. Skip a fully applied migration; investigate a partial state rather than blindly rerunning a batch.
4. Resolve the Phase 14 duplicate-normalization preflight before its unique index is added.
5. Apply only the missing, compatible migration(s) to a disposable clone first; capture row counts, checksums, schema hash and AUTO_INCREMENT values before and after.
6. Verify clinical/audit row retention, all expected FK rules and indexes, then rehearse restore again.
7. Only proceed on a real existing installation after a separate explicit deployment approval.

## 2. Read-only Phase 10-17 signature preflight (18 November 2026)

A repeatable inspection script is available at `database/migrations/preflight_phase10_17_readonly.sql`. It reports the selected schema/server identity, conventional ledger-table candidates, Phase 10-12 column/index signatures, Phase 11 aggregate raw-token counts, all 28 named Phase 13 foreign-key mappings and delete/update rules, the legacy/current column and index signatures of `patient_audit_log` and `schedule_audit_log`, the Phase 14 generated-column/index signature and duplicate-normalization count, and the Phase 15 outbox table/column/index metadata, Phase 16 registration-queue metadata, and Phase 17 email-verification user/queue/challenge/FK signatures.

Run it only after selecting a newly restored disposable clone:

```sh
mysql --database=YOUR_DISPOSABLE_CLONE < database/migrations/preflight_phase10_17_readonly.sql
```

The script contains inspection queries only. It does not create a ledger, change data, apply migrations, or decide that a migration is safe to run. It returns schema metadata and aggregate counts only; it does not return phone numbers, reset tokens, or patient records. Stop if the first result does not identify the exact intended disposable clone and expected server version.

Interpret the output conservatively:

- Compare every listed column type/default/nullability/generated expression and every index definition with the actual migration and consolidated master schema. Presence by name alone is not proof that the definition is correct.
- Phase 11's raw-token and duplicate-hash results are aggregate checks only. Investigate any remaining raw token or duplicate group before proceeding; do not export or display token values.
- Phase 13 must show the full set of 28 named constraints with the intended table/column references and delete rules. A missing, duplicate, or differently mapped constraint is a manual stop; this migration is not safe to replay blindly.
- Compare the `patient_audit_log` and `schedule_audit_log` columns/indexes with the current master and historical Phase 8/10 migration definitions before Phase 13. Do not rely on the presence of the table name alone.
- Phase 14 must have zero duplicate normalized-phone groups. If either `phone_normalized` or `uq_users_phone_normalized` already exists, compare its generated expression and uniqueness definition; do not rerun the one-time ALTER merely because one signature is incomplete.
- If `notification_outbox` already exists, compare its complete columns, indexes, engine, and collation with Phase 15. `CREATE TABLE IF NOT EXISTS` does not repair an incorrect existing table.
- A missing or partial signature is not automatically safe to apply. Resolve drift and document the intended statement-level action before any migration execution.

### Confirmed legacy audit-table migration collision (18 November 2026)

A read-only review of the repository's historical SQL found a concrete existing-install upgrade gap:

- The historical Phase 8 migration (`database/archive/migrations/Database_migration_phase8.sql`) creates `patient_audit_log` and `schedule_audit_log` with legacy column/index definitions and **no foreign keys**.
- The archived Phase 10 audit-table migration (`database/archive/migrations/database_migration_phase10.sql`) creates newer definitions with FK names `fk_pal_actor`, `fk_pal_patient`, `fk_sal_actor`, and `fk_sal_doctor`, but uses `CREATE TABLE IF NOT EXISTS`. If Phase 8 has already created the tables, the Phase 10 script leaves the legacy definitions unchanged; it does not add the expected columns, indexes, or FKs.
- The current Phase 13 migration unconditionally drops those four FK names from the two audit tables. On the Phase 8 table shape, those names do not exist, so Phase 13 cannot complete as written.
- The Phase 8 and later Phase 10 table definitions are also semantically different (for example, patient audit `entity_type/entity_id` versus `field_name`, and different action/value/description/index definitions). Simply dropping and recreating the tables would risk losing or misrepresenting retained audit history. The current `api/patients/get-audit.php` reader returns `pal.*`, so legacy fields can be part of its response shape; no current application writer to `patient_audit_log` was found in the repository review. No current non-archive application reader/writer for `schedule_audit_log` was found. Neither fact proves that historical rows or external consumers do not exist. Any repair should classify the actual table definitions, preserve legacy columns/rows, define only unambiguous field mappings, check for orphan actor/patient/doctor references before adding restrictive FKs, and verify the `pal.*` compatibility response. Do not coerce JSON/TEXT or TIMESTAMP/DATETIME values, drop legacy columns, or discard a log table without a separately reviewed mapping and restore-tested clone.

This is a **path-specific static DDL finding**, not a claim that every existing database has the legacy shape. The old 6 November dump already had later Phase 10–15 signatures and is not a clean pre-Phase-10 fixture. A parent commit's original 21-table schema has no Phase 10 reschedule fields, but it also predates later tables and requires a carefully defined earlier migration/fixture sequence. No database was changed for this review. The later read-only Phase 10-17 signature run targeted only the named disposable clone and does not test the historical legacy table path. **Do not run Phase 13 against a database with the legacy Phase 8 audit-table signature.** The repair should first classify the actual table definitions, preserve legacy columns/rows, define only unambiguous field mappings, check for orphan actor/patient/doctor references before adding restrictive FKs, and verify the `pal.*` compatibility response. Do not coerce JSON/TEXT or TIMESTAMP/DATETIME values, drop legacy columns, or discard a log table without a separately reviewed mapping and restore-tested clone. An explicit earlier-migration fixture and data-preserving table-shape migration are required before this path can be accepted.

The Phase 10-17 helper was executed with **26/26 SELECT statements succeeding** against the exact disposable clone healthbridge_hb_auth_email_verify_20261114 on MariaDB 10.11.16. A runtime schema-name guard rejected any other target before the inspection queries ran. No write statement is present. This confirms the current clone's signatures only; it does not test the legacy Phase 8 audit-table shape or authorize any protected/hosted migration. It does not close HB-DB-007: there is still no migration ledger and no clean, fully specified pre-Phase-10 acceptance fixture. The existing full-chain rehearsal remains limited to its documented MariaDB 10.11.16 disposable baseline and did not exercise the legacy Phase 8 audit-table shape.

## 3. Engine compatibility: the existing-install migration chain is not fully MySQL 8 portable

The master schema and forward migrations must be reviewed separately; compatibility of `CREATE TABLE` statements does not prove compatibility of the upgrade scripts.

The Phase 10, Phase 11 and Phase 17 scripts use `ALTER TABLE ... ADD COLUMN/INDEX IF NOT EXISTS`. Phase 12 uses `ALTER TABLE ... ADD COLUMN IF NOT EXISTS`. Phase 11 additionally uses `CREATE UNIQUE INDEX IF NOT EXISTS`. Those subclause forms are documented by MariaDB, but they are absent from the Oracle MySQL 8.0 grammar for `ALTER TABLE` and `CREATE INDEX`. The current source migrations therefore must **not** be described as MySQL 8 compatible until they are ported and tested on an actual MySQL 8 server.

Official syntax references:
- MariaDB `ALTER TABLE`: https://mariadb.com/docs/server/reference/sql-statements/data-definition/alter/alter-table
- MySQL 8.0 `ALTER TABLE`: https://dev.mysql.com/doc/refman/8.0/en/alter-table.html
- MySQL 8.0 `CREATE INDEX`: https://dev.mysql.com/doc/refman/8.0/en/create-index.html

The MySQL 8 client test documented in README was a client-side `SOURCE` error-handling check against a local MariaDB server. It was **not** an end-to-end test of these migrations against an Oracle MySQL 8 server. Current local migration acceptance is MariaDB 10.11.16 only. Do not use a MySQL 8 server for the existing-install migration sequence until the syntax is ported and a real-server compatibility test is recorded.

## 4. Read-only drift found in the retained local synthetic baseline

On 11 November 2026, the retained synthetic restore baseline `healthbridge_restore_rehearsal_20261111` was first inspected read-only. It is the exact restored copy of the local `healthbridge_repair_test` state from the prior backup/restore rehearsal. The baseline remained untouched during the follow-up migration rehearsal; Phase 13 was applied only to a separate disposable clone, as recorded in Section 5.

| Signature | Observed baseline state |
|---|---|
| Tables | 26 tables; matches the current master schema's 26 CREATE TABLE definitions. |
| Phase 10 | Reschedule fields and required indexes are present. |
| Phase 11 | `users.session_version` is present, but `password_resets.token_hash`, `request_ip`, `idx_pr_email_created`, `idx_pr_ip_created` and `uq_password_resets_token_hash` are missing. Two deprecated token indexes remain (`idx_pr_token` and unique key `token`). This is a **partial signature**, not proof of which migration statements historically ran. The updated Phase 11 migration also drops those old indexes after nulling raw token values. |
| Phase 12 | `visit_notes.follow_up_instructions` is present. |
| Phase 13 | All 28 named FK constraints targeted by the migration are present, but **0** currently use `ON DELETE RESTRICT`. Current rules: 29 CASCADE and 7 SET NULL. Master-schema target: 28 RESTRICT, 3 CASCADE and 5 SET NULL. |
| Phase 14 | `users.phone_normalized` and `uq_users_phone_normalized` are missing. Read-only normalized-duplicate preflight returned **0 groups**. |
| Phase 15 | `notification_outbox` and its two worker indexes are present. |

Additional read-only preflight results on that baseline:
- Legacy non-empty raw password-reset token count: **0**.
- Duplicate SHA-256 groups among legacy raw reset tokens: **0**.
- Phase 13 expected named FK constraints: **28/28 present**.
- Migration ledger tables found: **0**.
- Engine: **MariaDB 10.11.16**.

These are observations about this retained synthetic local baseline only. They do not establish the schema state of any hosted installation or other database. The baseline remains retained and untouched. The complete Phase 10–15 rehearsal used a separate disposable clone and immutable pre-test dump; its final schema matched the fresh master and the clone was restored/dropped. The detailed result and limits are recorded in Section 6. Any future migration rehearsal must still use a new disposable clone and a fresh pre-test dump.

## 5. Current master-schema inventory

The 27 tables in `database/healthbridge.sql` are: users, departments, doctors, appointments, visit_workflow, visit_drafts, notifications, notification_outbox, contact_messages, ratings, password_resets, user_preferences, medical_history_updates, medical_records, visit_notes, prescriptions, prescription_items, hospital_settings, doctor_schedule_settings, doctor_schedule_weekly, admin_audit, medical_record_audit, patient_audit_log, schedule_audit_log, egypt_governorates, egypt_cities and registration_intake_queue.

The schema's key post-migration structures include the reschedule fields, `session_version`, reset-token hash/IP fields and indexes, visit follow-up instructions, the 28 restrictive clinical/audit foreign-key rules, generated normalized phone and unique index, and the notification outbox without a recipient foreign key, and the Phase 16 encrypted registration queue. Phase 17 remains a separate required forward step for the current email-verification flow.

## 6. Migration rehearsal and remaining acceptance

**Complete Phase 10–15 MariaDB migration rehearsal (11 November 2026): PASS.** All six forward migrations were applied in order to the fresh disposable clone `healthbridge_migration_upgrade_runtime_20261111`, created from retained synthetic baseline `healthbridge_restore_rehearsal_20261111`. The separate schema comparison database `healthbridge_migration_master_compare_20261111` was created from a copy of the consolidated master schema with only the executable database target renamed. Both targets were verified absent before creation.

- Each Phase 10–15 migration exited with code 0. Phase 14 printed zero duplicate normalized-phone groups.
- The first comparison exposed two redundant legacy indexes on deprecated plaintext `password_resets.token` (`idx_pr_token` and unique key `token`). Current reset-password handlers query `token_hash` only, and Phase 11 nulls raw token values. Phase 11 was updated to drop these indexes after nulling raw token values, then rerun successfully (exit code 0). The pre-cleanup Phase 11 source is backed up in the QA folder.
- Final semantic schema comparison with the fresh master: **26/26 tables, 273/273 column definitions, 129/129 index rows, 80/80 table constraints, 36/36 foreign-key mappings, and 36/36 FK update/delete rules** all matched. Physical column ordinal positions are excluded because ALTER-based upgrades append fields; every actual column definition property is still compared.
- Final FK delete-rule distribution exactly matches the master: **28 RESTRICT, 3 CASCADE, 5 SET NULL**.
- Existing pre-migration column data fingerprints: **26/26 tables matched, zero data mismatches**. All 26 AUTO_INCREMENT values remained unchanged.
- Final raw reset-token count: 0; final empty generated normalized-phone values: 0. `mysqlcheck` passed **26/26 tables**.
- The upgrade clone was restored from its original pre-test dump and exactly matched all 26 table row counts/extended checksums, schema hash and AUTO_INCREMENT values. `mysqlcheck` passed 26/26. The upgrade clone and separate master-comparison database were dropped; the retained baseline remained intact.

Evidence: `C:\xampp85\migration-backups\healthbridge\hb-db-migration-upgrade-rehearsal-20261111\RESULT.md` and its runtime log. The earlier read-only inventory/preflight result is in `hb-db-migration-drift-review-20261111/RESULT.md`.

**Remaining limits:**
- This full upgrade rehearsal covers the retained baseline's observed partial Phase 11 and missing Phase 13/14 signatures; it is not a clean pre-Phase-10 history with a migration ledger. There is currently no migration ledger.
- Phase 13/14 remain one-time/non-idempotent and require signature inspection before any real application. The successful clone rehearsal does not authorize running migrations on a retained or hosted database.
- No fresh-install SQL wrapper was executed during this review.
- No Oracle MySQL 8 server was available. Phase 10–12 MariaDB syntax remains non-portable to MySQL 8 until separately ported and tested.
- No hosted/production database or migration history was accessed.

### Additional V2 ETL compatibility boundary (20 November 2026)

A further static review of `database/v2/migrate_synthetic_clone.php` and `database/v2/001_schema.sql` found that the current V1-to-V2 ETL rehearsal is deliberately bound to one approved source clone (`healthbridge_hb_auth_email_verify_20261114`) and one V2 prototype target (`healthbridge_v2_prototype_20261118`). It is not a generic upgrade runner for arbitrary legacy installations.

- The patient-audit copy SELECT requires `patient_audit_log.field_name`. The Phase 8 table has no such column, so the current ETL would fail at this copy step and roll back its transaction rather than complete the migration. The Phase 8-only `entity_type` and `entity_id` columns are also absent from the current SELECT; the V2 `legacy_patient_audit_log` table has corresponding columns, but this ETL does not populate them.
- The schedule-audit copy SELECT does not include the Phase 8-only `change_type` column. The V2 `legacy_schedule_audit_log` table contains a `legacy_payload` field, but the current ETL does not insert into it. A source with `change_type` could therefore complete this copy while losing that legacy value.
- The existing copy also maps historical audit timestamps into V2 `DATETIME` columns. Phase 8 declares `TIMESTAMP`; that representation/time-zone conversion requires an explicit, tested rule rather than being assumed lossless.

This was a static source/schema review only. No database, source schema, target prototype, or application runtime was changed. Before claiming compatibility with Phase 8 or earlier sources, add schema-aware mapping for both audit tables and prove it with populated synthetic sentinel rows on a newly created disposable source/target pair. Verify every source field, JSON/TEXT payload, NULL value, timestamp instant, row count, and the `api/patients/get-audit.php` `pal.*` compatibility response. Do not use the retained V2 prototype as a write target for this test.
### Reproducible historical fixture recipe identified (20 November 2026)

The original 21-table pre-Phase-8 schema is available at Git commit `14b161e7366de82a69e7ed487a5d75c6bfd83d45`, file `database/database.sql`. Static inspection confirms that this snapshot already contains the Phase 6/7 structures (`hospital_settings` hospital fields, department references, and `admin_audit.actor_id/actor_role`), but does not contain the Phase 8 audit tables, Phase 9 patient identity/location additions and governorate/city tables, Phase 10 reschedule fields, or the Phase 12 `visit_notes.follow_up_instructions` column.

The fixture must therefore **not** replay Phases 6 or 7. Apply the historical Phase 8 audit-table creation steps 2 and 3 only; skip its optional Step 1 because the baseline already has the Phase 7 actor model. Then apply Phase 9, the current Phase 10 reschedule migration, the archived Phase 10 audit-table `CREATE TABLE IF NOT EXISTS` migration (to prove it leaves the Phase 8 tables unchanged), and Phases 11 and 12. Before testing Phase 13, seed only synthetic users and audit rows with valid actor/patient/doctor references, and record the exact table/column/index/FK signatures. The expected reproduction is that the two Phase 8 tables still lack `fk_pal_actor`, `fk_pal_patient`, `fk_sal_actor`, and `fk_sal_doctor`, so the unmodified Phase 13 fails at its unconditional DROP statements.

This is a **fixture recipe based on Git and DDL inspection**, not a completed runtime test. No historical fixture database has been created or modified in this checkpoint. The next test must use a uniquely named disposable database restored from the exact Git schema, plus a separate disposable V2 target clone; it must retain before/after dumps and fingerprints, prove every migration step, test the `pal.*` reader and audit data preservation, and drop only the newly created test databases after verification. The original Git schema, retained source fixtures, V2 prototype, main database and hosted resources remain untouched.

## 20 November 2026 - Phase 8 audit migration runtime acceptance

The legacy path is no longer only a static finding. A reproducible MariaDB 10.11.16 fixture was built from Git commit 14b161e7366de82a69e7ed487a5d75c6bfd83d45 (database/database.sql, 21 tables). Phase 6/7 were not replayed because those structures were already in the baseline. The test applied Phase 8 Steps 2-3, Phase 9, current Phase 10 rescheduling, archived Phase 10 audit-table CREATE TABLE IF NOT EXISTS, Phases 11-12, and synthetic audit sentinels.

**Negative case:** the unmodified phase13_clinical_audit_retention.sql failed at fk_pal_actor, exactly because Phase 8 has no audit foreign keys. The dump taken immediately after the failure contains only six unrelated FK constraints: 24 earlier Phase 13 target constraints had already been dropped. Phase 13 is therefore non-atomic; an error can leave a database partially stripped of constraints. Treat any failure as restore-required. Do not retry Phase 13 on that partially changed database.

**Accepted Phase 8 compatibility sequence on a separate disposable clone:**

1. Run preflight_phase13_phase8_audit_fk_bridge_readonly.sql and stop unless it reports the exact Phase 8 signature: 13 patient-audit columns, 12 schedule-audit columns, all 24 non-audit Phase 13 FK names, no named/alternate FK on the four audit columns, zero orphans, and four distinct supporting indexes.
2. Run phase13_legacy_phase8_audit_fk_bridge.sql. It adds the four expected audit FK names as ON DELETE RESTRICT, so interruption before Phase 13 does not temporarily weaken audit retention.
3. Run the existing Phase 13 migration once, immediately after the bridge. Do not infer history from migration filenames and do not rerun Phases 13/14 blindly.

The repo-owned preflight and bridge files were executed against the exact synthetic historical fixture. The bridge-plus-Phase-13 path passed: all 28 expected FK names were present; all four audit FKs were RESTRICT; populated synthetic patient/schedule audit rows retained entity_type, entity_id, change_type, valid JSON values and NULL descriptions; both audit created_at columns remained TIMESTAMP; mysqlcheck passed all 25 tables. Both disposable databases were dropped. Full output, logs and before/after SQL dumps are retained in C:\xampp85\migration-backups\healthbridge\hb-db-007-phase8-rehearsal-20261120\.

This bridge is narrowly scoped to the tested Phase 8 signature. It does not repair arbitrary mixed installations or replace the missing durable migration ledger. The V2 ETL remains separately incompatible with this exact source shape: it selects patient_audit_log.field_name, does not map Phase 8 patient entity_type/entity_id or schedule change_type, and has no explicit rule for TIMESTAMP-to-DATETIME timezone conversion. Do not claim Phase 8-to-V2 migration compatibility until that data path is corrected and tested on a separate disposable V2 target clone.


## 20 November 2026 — Phase 8 audit-table V2 ETL acceptance

This checkpoint supersedes the earlier static warning that the tested V2 ETL could not read the Phase 8 patient/schedule audit signature. The mapping fix was exercised against two newly created disposable database clones on MariaDB 10.11.16.

The source clone began as a copy of the approved modern synthetic V1 fixture. Only patient_audit_log and schedule_audit_log were dropped and recreated with the exact Phase 8 DDL from Database_migration_phase8.sql; one synthetic row was seeded in each table. This deliberately isolates the historic audit-table schema variant while retaining the rest of the modern source schema required by the ETL. It does not prove that a raw early Phase 8 database, before Phases 9–17, is directly ETL-ready.

The V2 target was separately cloned from the prepared V2 schema and emptied while preserving schema_migrations. New migration 2.0.7-legacy-audit-archive-nullability changed legacy_schedule_audit_log.description to nullable. ETL mapping contract 2.1.2 now introspects both source audit schemas, copies patient entity_type/entity_id, retains schedule change_type in legacy_payload, and checks the archived values byte-for-byte across differing table collations. It pins the source/target connection session to UTC for TIMESTAMP-to-DATETIME conversion; source DATETIME values remain unchanged.

Runtime acceptance passed: one patient-audit sentinel and one schedule-audit sentinel were preserved with valid JSON old/new values, NULL descriptions, IP/user-agent data and exact UTC timestamp instants; 3,468 lineage mappings; zero blocking exceptions; both new migration ledger entries present; source before/after dump fingerprint identical after suppressing only generated dump-date metadata; and mysqlcheck passed on the target. Both clones were dropped and verified absent. Evidence: C:\\xampp85\\migration-backups\\healthbridge\\hb-v2-phase8-etl-rehearsal-20261120\\RESULT.md.

The acceptance closes only the Phase 8 audit-table ETL mapping gap. It does not close HB-DB-007: durable migration ledger/state tracking, non-Phase-8 mixed-schema/orphan preflight, broader legacy source-schema acceptance, full backup/restore rehearsal, complete route regression and explicit cutover/rollback approval remain open. V1 remains the current application default; no protected or hosted database migration is authorized.


## Migration-ledger rehearsal update — 20 November 2026

A disposable-only migration ledger prototype now journals each DDL statement and requires a verified pre-migration dump reference. The exact Phase 8 bridge and Phase 13 source scripts were represented as 2 and 32 explicit steps, respectively, with source SHA-256 checks. On a replayed Phase 8 fixture, the full sequence passed 28/28 named RESTRICT-FK postflight and audit-sentinel preservation. A separate clone interrupted after step 16 was marked `recovery_required` and refused automatic replay. See `docs/HEALTHBRIDGE_MIGRATION_LEDGER_DESIGN.md` and the evidence folder `C:\xampp85\migration-backups\healthbridge\hb-db-ledger-phase13-20261120\`.

This is not permission to run the migrations on the main database. The preflight is signature-specific; mixed historical variants and Phases 14–17 still need separate acceptance.


## 20 November 2026 — Phase 14–17 historical migration acceptance and collation repair

The Phase 14–17 chain was rehearsed from the retained Phase 8/Phase 13-success dump on a fresh disposable clone. Phase 14 (stored generated phone normalizer + unique index), Phase 15 (notification outbox), and Phase 16 (encrypted registration queue) applied successfully. The first Phase 17 attempt then reproduced MariaDB error 1270 on the `ALTER TABLE users` statement: mixed collations in the Phase 14 stored generated `phone_normalized` expression were reevaluated during the later table alteration.

Historical files were preserved unchanged. Added `database/migrations/phase14b_phone_normalization_collation_compat.sql` as a forward compatibility migration to run after Phase 14 and before Phase 17. It redefines the same generated formula while explicitly converting and collating every text operand as `utf8mb4_unicode_ci`, matching the collation-deterministic strategy already used by V2 migration 2.0.5. SHA-256: `732A44BFC7FB39C2B03827386EF35076CE66BC5C8B20F538BD1EBFFDAC6B61E4` (see the file for the exact bytes; verify the checksum before applying).

A fresh repeat then passed in this order: Phase 14 → Phase 15 → Phase 16 → Phase 14b → Phase 17. Evidence (`C:\xampp85\migration-backups\healthbridge\hb-db007-phase14-17-20261120\COLLATION_FIX_RESULT.md`): Phase 14 generated column/index 1/1; duplicate normalized groups 0; Phase 15 13 columns/2 indexes/InnoDB; Phase 16 16 original columns/4 indexes/InnoDB; Phase 17 2 user verification columns, 3 queue columns, 11 challenge columns, 2 challenge indexes and the expected CASCADE FK. The normalized-phone output SHA-256 remained identical across the collation repair. All 13 pre-existing synthetic users remained `email_verification_required=0` with `email_verified_at IS NULL`; core user-data SHA-256 matched before/after; registration queue stayed empty; challenge orphans=0; both Phase 8 audit sentinels remained 1/1; 28/28 named Phase 13 FKs remained. `mysqlcheck` passed after the complete chain.

Both failed/accepted rehearsal clones were dropped and verified absent. No main `healthbridge`, NOVA, hosted DB, retained V2 prototype or production task was changed. Do not run Phase 17 on a Phase 14 installation in this historical lineage until Phase 14b has been applied and its pre/post signatures verified. This accepts only the tested Phase 8→13 lineage, not every mixed legacy schema variant; the V1 migration ledger runner also remains a prototype, not an approved main-database executor.
