# HealthBridge v2 synthetic ETL and restore result

**Date:** 18 November 2026  
**Status:** PASS for the tested disposable synthetic baseline; not a production migration.  
**Engine:** MariaDB 10.11.16.

## Targets

- v1 read-only source: `healthbridge_hb_auth_email_verify_20261114`.
- v2 destination: `healthbridge_v2_prototype_20261118`.
- accepted logical-restore copy: `healthbridge_v2_restore5_20261118`.

## v2 schema verification

- 37 InnoDB base tables, one view.
- 42 foreign-key constraints, 48 FK column mappings.
- 23 CHECK constraints.
- 379 column definitions and 178 index rows.
- 8/8 negative/positive constraint tests passed: singleton ID rejection, doctor profile FK, registration challenge FK, outbox no-user-FK behavior, pre-account queue no-user-FK behavior, weekday check, rating range check, and composite encounter-owner mismatch rejection.

## Data ETL results

The ETL ran as a single target-side transaction after exact source/target guards and stopped on any count or mapping failure. All expected table row-count gates passed.

| v2 table | Rows |
|---|---:|
| users | 166 |
| departments | 9 |
| egypt_governorates | 27 |
| egypt_cities | 200 |
| patient_profiles | 133 |
| patient_medical_profiles | 133 |
| doctor_profiles | 32 |
| legacy_doctor_profile_state | 32 |
| user_preferences | 166 |
| legacy_user_preferences | 166 |
| admin_preferences | 1 |
| doctor_schedule_settings | 32 |
| doctor_weekly_schedules | 224 |
| appointments | 486 |
| appointment_reschedule_requests | 12 |
| clinical_encounters | 451 |
| encounter_drafts | 0 |
| medical_history_events | 0 |
| prescriptions | 99 |
| prescription_items | 186 |
| ratings | 79 |
| notifications | 558 |
| notification_outbox | 13 |
| legacy_admin_audit | 13 |
| legacy_medical_record_audit | 0 |
| legacy_patient_audit_log | 0 |
| legacy_schedule_audit_log | 0 |
| password_reset_tokens | 0 |
| registration_intake_queue | 20 |
| registration_email_challenges | 11 |
| hospital_settings | 1 |
| contact_messages | 0 |
| audit_events | 0 |
| appointment_events | 0 |

Additional ETL tracking: 3,466 row-lineage mappings; 100 accepted legacy warnings; zero open blocking exceptions.

The 100 warnings are precisely:
- 52 location labels retained as source text because no exact same-governorate lookup match was found.
- 30 stale doctor profile rating caches archived; v2 summary derives from ratings facts.
- 12 legacy `Reschedule Requested` booking statuses represented as confirmed bookings plus separate pending reschedule request records, based on the inspected Confirmed-only service path.
- Six 12-hour appointment strings converted to canonical timestamps with source time/range snapshots retained.

## Field equivalence and integrity

27 field-level equivalence checks returned zero differences across user identity/authentication values, doctor profiles and archived profile fields, user preferences and archive, patient demographics/clinical profiles, appointment timestamps/status/snapshots, reschedule request facts, encounter workflow/note content, prescription headers/items, ratings, notifications/outbox, registration ciphertext/challenge hashes, department/location lookups, schedules, hospital settings, legacy admin audit values and derived rating summaries.

All 42 foreign keys were checked; zero orphan rows. Composite ownership constraints ensure that encounter, prescription and rating patient/doctor IDs match their appointment. `CHECK TABLE` passed **37/37** tables.

## Source immutability evidence

A primary-key-ordered SHA-256 manifest was captured before the ETL and after all validation. The exact source clone remained the selected read-only connection. All **28/28 source tables** had identical row counts and hashes. No source clone writes occurred.

## Backup/restore rehearsal

A logical dump of v2 was restored using byte-preserving file redirection into `healthbridge_v2_restore5_20261118`. Exact comparison passed:

- 37/37 base table row hashes matched.
- All 379 column definitions matched.
- All 178 index rows matched.
- All 42 FK constraints / 48 FK column mappings matched.
- All 23 CHECK constraints matched.
- One view definition/security matched after normalizing only MariaDB's expected database-name qualification.
- Engine/collation and AUTO_INCREMENT metadata matched.
- Restored CHECK TABLE passed 37/37.

Failed restore attempts are retained in separately named disposable databases `healthbridge_v2_restore_20261118`, `healthbridge_v2_restore2_20261118`, `healthbridge_v2_restore3_20261118`, and `healthbridge_v2_restore4_20261118`. They are not accepted restore targets. The first exposed a generated-column collation issue; later copies exposed PowerShell text-pipeline character loss. V2.0.5 made generated phone normalization collation-deterministic; the final restore used direct byte-preserving file redirection. The final restore5 copy has exact row hashes.

## Limits

This rehearsal covers the recent, fully migrated Phase 17 synthetic clone only. It does not exercise the older Phase 8 patient/schedule audit-table shape. The tested clone had no rows in patient_audit_log, schedule_audit_log or medical_record_audit, and no rows in password_reset_tokens, encounter_drafts, medical_history_events or contact_messages. A non-empty older audit-table shape requires a separately approved mapping and must not be assumed compatible with this ETL.

No application code now uses v2. No protected main database, NOVA Home DB, hosted database, secret or deployment resource was accessed or modified. This does not authorize a production cutover.


Final source immutability rerun (18 November): the final primary-key-ordered fingerprint still matched 28/28 source tables, with zero row-count/hash differences after ETL, validation, backup and restore verification.


## Supplemental Phase 8 audit-signature ETL acceptance — 20 November 2026

This is a separate runtime acceptance from the 18 November baseline result above. It uses a disposable source clone copied from the approved synthetic V1 dataset, then recreates only patient_audit_log and schedule_audit_log using the exact Phase 8 DDL signature. The other V1 tables remain the fully migrated synthetic source shape required by the current V2 ETL. It is therefore a focused historical audit-table compatibility fixture, not a raw early Phase 8 full-database migration test.

The source clone was healthbridge_hb_etl_source_phase8_20261120. The target was a separately cloned, emptied V2 schema healthbridge_v2_etl_target_phase8_20261120; its schema_migrations ledger was retained. The approved source fixture and retained V2 prototype were read-only. A new V2 migration, 2.0.7-legacy-audit-archive-nullability, changed legacy_schedule_audit_log.description to NULLable before ETL so the historical NULL source value is not coerced or lost.

The updated ETL mapping contract 2.1.2 detected the Phase 8 patient entity_type/entity_id variant and preserved both values in legacy_patient_audit_log; field_name remained NULL rather than being invented. It retained schedule change_type in a structured legacy_payload. Both source rows retained their JSON old/new values, NULL descriptions, IP/user-agent data and created_at instant. The source TIMESTAMP columns were read with the session time_zone fixed to +00:00; the target DATETIME values matched the UTC instants. DATETIME source columns in other supported variants are not shifted by this rule.

Acceptance results:
- ETL exit code 0; 3,468 row-lineage mappings; 100 accepted legacy warnings; zero blocking exceptions.
- Patient sentinel exact comparison: 1 row, field_name NULL, entity_type/entity_id preserved, old/new JSON valid, description NULL, expected UTC timestamp.
- Schedule sentinel exact comparison: 1 row, change_type preserved in legacy_payload, old/new JSON valid, description NULL, expected UTC timestamp.
- Migration ledger contains both 2.0.7-legacy-audit-archive-nullability and 2.1.2-phase8-audit-etl.
- Source before/after logical dump fingerprint identical after excluding only the dump client's generated completion timestamp: BD05BDB81A3472DDBBAF7A4144A48888E982E8D9A47C140470E42A6BCD8F1181.
- MariaDB mysqlcheck passed on the disposable V2 target clone.
- Both disposable clones were dropped and independently verified absent. No retained fixture, main HealthBridge database, NOVA database, hosted database, SMTP or worker was used.

Evidence directory: C:\xampp85\migration-backups\healthbridge\hb-v2-phase8-etl-rehearsal-20261120\ (RESULT.md, runtime.log and byte-preserving dump files). This closes only the exact Phase 8 audit-table ETL mapping gate. It does not certify arbitrary historical schema drift, raw pre-Phase-9/17 installations, the remaining migration ledger/state-tracking work, full production restore/cutover or application route readiness.
