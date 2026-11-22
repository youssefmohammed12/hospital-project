# HealthBridge migration ledger and recovery design

**Status:** Disposable-clone prototype implemented and tested; no V1 ledger schema or runner has been applied to the main database. The prototype is not a production-ready universal runner.  
**Date:** 20 November 2026.  
**Scope:** Local MariaDB migration safety for the existing V1 database and future V2 schema work.  
**Safety boundary:** All prototypes and fault-injection tests must use uniquely named disposable clones. The main HealthBridge database, NOVA, hosted databases and production resources remain out of scope.

## 1. Problem statement

HealthBridge's V1 Phase 10–17 migration chain has no durable migration-history table. The read-only Phase 10–17 preflight checks for conventional ledger names, but the retained synthetic baseline had no migration ledger. Migration filenames and the presence of later tables/columns do not establish which statements ran, in what order, or whether an earlier run stopped halfway.

The Phase 13 clinical/audit retention migration is an observed non-atomic failure path. Its script performs many independent ALTER TABLE statements. On the exact historical Phase 8 audit-table signature, the unmodified migration failed at the absent fk_pal_actor DROP after 24 earlier foreign-key constraints had already been removed. The resulting schema was partially changed. A retry against that same database would be unsafe without restore or an explicitly reviewed forward repair.

The V2 schema has a simple schema_migrations receipt table containing version, checksum, description, engine minimum and applied_at. V2's ETL transaction can atomically commit its target data, lineage, exceptions and completion receipt, but that table does not model DDL execution attempts, partial completion, interrupted runs or recovery. It must not be treated as sufficient for the V1 legacy DDL chain.

## 2. Required invariants

1. A migration is not considered applied because its filename exists, a version number is expected, or a later schema object happens to exist.
2. A successful migration receipt is written only after its postflight signature passes.
3. Every attempt is recorded separately from the canonical successful migration receipt.
4. Any failure, disconnect or interrupted DDL attempt blocks automatic replay until a fresh read-only preflight and an explicit recovery decision have been recorded.
5. A changed script checksum for an already-applied migration is a hard stop. Historical files are immutable; repairs are new forward migrations.
6. A backup is identified and verified before any non-transactional or destructive DDL begins. The ledger stores its reference and checksum, not the backup contents.
7. A per-database migration lock serializes runners. Lock lifetime is bound to the same database session executing the statements.
8. Existing installations are baseline-reconciled using observable schema signatures. Their unknown historical chronology is not fabricated.
9. No migration runner may silently switch database schemas, connect to a non-loopback host during local QA, or bypass the explicit target allowlist.

## 3. Proposed data model

Use three separate layers. The precise SQL types and indexes are to be finalized in a disposable prototype before implementation.

### A. schema_migrations — canonical successful receipts

One row per migration identity that has passed all postflight checks. Suggested fields:

- migration_key (stable primary key, such as phase13_clinical_audit_retention)
- script_sha256 (SHA-256 of exact source bytes)
- description and engine_minimum
- applied_at with fractional-second precision
- engine_version and runner_version
- preflight_signature_sha256 and postflight_signature_sha256
- successful_run_id and backup_reference

Only the applied/successful state belongs here. Failed or in-progress attempts do not.

### B. schema_migration_runs — append-only attempt history

One row per execution attempt, including blocked attempts. Suggested fields:

- run_id (monotonic primary key)
- migration_key and script_sha256
- status: running, succeeded, blocked_preflight, failed, or recovery_required
- target_schema, server_flavor, server_version and runner_version
- started_at, heartbeat_at and finished_at
- backup_reference and backup_sha256
- preflight_signature (JSON) and postflight_signature (JSON)
- failed_step_key, SQLSTATE/error code and bounded error summary
- operator/approval reference

Do not store passwords, tokens, patient data or raw SQL parameters in the ledger. Error summaries must be bounded and scrubbed.

### C. schema_migration_events — per-step execution journal

One append-only event per named step. Suggested fields:

- event_id, run_id, sequence_no and step_key
- event_type: step_started, step_succeeded, step_failed, postflight_passed, postflight_failed, recovery_reviewed
- statement_sha256 (never the SQL text itself)
- event_at and bounded metadata JSON
- observed_signature_sha256 where relevant

The Phase 13 DDL must be represented as explicit named steps. Do not split SQL files with a naive semicolon parser; semicolons can occur in literals/comments and SQL client directives complicate parsing. A reviewed PHP migration manifest or one-file-per-step representation should define the exact statements and order.

### D. Legacy baseline observations

Baseline reconciliation must be a separate record, not a successful migration receipt. It should record the reviewed migration key, observed state (verified_present, verified_absent, partial, ambiguous), exact signature hash, evidence reference, reviewer and timestamp. This allows a pre-existing installation to be classified without claiming that a particular historical script ran.

## 4. Runner lifecycle

1. Resolve the target schema and host from explicit inputs; validate the target against a strict allowlist. Read the exact migration manifest and SHA-256 checksum.
2. Acquire a database-scoped advisory lock using GET_LOCK on the same PDO/database session that will execute the migration. The lock name must be deterministic, collision-resistant and within the engine's name-length limit.
3. Check the canonical receipt. If the same migration key and checksum already succeeded, verify its postflight signature and skip. If the key exists with a different checksum, refuse.
4. Check for any prior running, failed or recovery_required attempt. Refuse to proceed until an explicit recovery review clears the block; never auto-replay a partially applied DDL chain.
5. Run read-only preflight checks. Compare the live schema with the exact expected precondition signature. If it is mixed, unexpected or ambiguous, record blocked_preflight and stop before DDL.
6. Require a verified backup reference for any non-transactional/destructive migration. Record the backup checksum and target schema.
7. Commit a running attempt record before the first DDL statement. Record step_started, execute one named statement, then record step_succeeded and its observed signature before moving to the next step.
8. Run postflight checks against all expected columns, indexes, constraints, delete/update rules and relevant aggregate data checks. Object existence alone is not enough; compare definitions and rules.
9. Only after postflight passes, insert the canonical schema_migrations receipt and mark the run succeeded. The successful receipt and terminal run transition should commit together.
10. Release the advisory lock in a finally path. A lost connection leaves an unresolved attempt that blocks further automatic runs.

For a fully transactional data-only migration, its data changes and successful receipt may be committed in one transaction. Do not apply that assumption to DDL scripts that auto-commit.

## 5. Failure and recovery rules

- SQL failure after one or more DDL steps: set the run to recovery_required and preserve the completed-step journal. Capture a fresh read-only signature snapshot. Do not rerun the original file.
- Process/connection loss while status is running: once the session lock is gone, classify the attempt as interrupted/recovery_required after inspecting the live schema. Do not infer which statement completed from the last log event alone; a DDL statement may have committed immediately before the disconnect.
- Recovery choices are explicit: restore the verified pre-migration backup, or run a separately reviewed forward-repair migration after proving the exact partial state. Record the decision, evidence, operator and new run ID.
- Do not edit an applied historical SQL file. A correction receives a new migration identity and checksum.
- A migration can be marked successful only after a complete postflight. A partially matching schema is not success, even if application pages appear to work.

## 6. Legacy installation reconciliation

The first ledger deployment cannot invent historical migration receipts. It should:

1. Run the read-only Phase 10–17 preflight against a named disposable clone.
2. Classify each migration's required schema signature independently. Later phases may exist while earlier artifacts are incomplete.
3. Record each result as verified_present, verified_absent, partial or ambiguous in the baseline-observation table, with the signature hash and evidence reference.
4. Stop on partial/ambiguous results. Resolve them through a separate clone rehearsal and explicit backup/repair plan.
5. Insert canonical applied receipts only for migrations executed by the new runner, not for historical phases inferred from filenames or timestamps.

The Phase 8 RESTRICT bridge is a distinct compatibility pre-step for the exact tested Phase 8 signature. It must have its own manifest identity, preflight and postflight. It is not a generic repair for other audit-table shapes.

## 7. Migration-specific implications

- Phase 13: break the FK drop and re-add sequence into named, observable steps. Require the exact preflight signature, zero orphans, all supporting indexes and a verified backup. Any missing expected constraint blocks the unmodified legacy migration; use the tested Phase 8 bridge only when its entire signature matches.
- Phase 14: require the duplicate-normalized-phone preflight to return zero, and compare the generated-column expression and unique-index definition after DDL. A table/column existence check is insufficient.
- Phase 15 and Phase 16: CREATE TABLE IF NOT EXISTS is not schema validation. If an existing table has a partial or incompatible definition, stop and classify it rather than treating statement success as acceptance.
- Phase 17: it contains multiple ALTER/CREATE operations. Record each step and verify the final users, queue, challenge table, indexes, enum values and FK definitions.
- V2 schema wrappers: the existing schema_migrations table remains useful for successful version/checksum receipts. If future V2 DDL becomes multi-step or non-atomic, add attempt/event tracking rather than stretching the current table into an execution journal.

## 8. Disposable-clone acceptance plan

Before any use against a retained or live database, build a complete runner prototype and verify:

1. Fresh-schema path: migrations execute in declared order, checksums and successful receipts are correct, and rerunning an already-applied matching migration is a no-op.
2. Checksum path: a modified file under an applied migration key is rejected.
3. Preflight path: wrong schema, non-loopback host, duplicate phone group, orphan FK or mismatched table signature blocks before writes.
4. Phase 13 fault injection: force failure after selected named DDL steps. Confirm that the attempt is recovery_required, partial state is accurately inventoried, and a retry is blocked.
5. Disconnect path: interrupt the process after a DDL commit but before its success event. Confirm that the stale running attempt is not blindly replayed.
6. Concurrency path: a second runner cannot enter while the database-scoped advisory lock is held.
7. Recovery path: restore from the pre-run dump or apply a separately reviewed forward repair, then prove exact postflight and record the recovery decision.
8. Baseline path: classify a clone with Phase 8/10/11/12/13/14/15/16/17 mixed signatures without fabricating applied history.
9. Backup path: backup checksum and target identity are recorded and checked before irreversible DDL.
10. Final regression: PHP lint, migration signature tests, source/target fingerprint checks, mysqlcheck and the current V1 application test suite all pass.

## 9. Explicit non-goals and current state

- Prototype code and tests are listed in Section 10; they were applied only to newly created disposable synthetic clones. No main or hosted database was changed.
- It does not authorize applying Phase 10–17, Phase 13, or any V2 migration to the main HealthBridge database or hosted resources.
- It does not make the current SQL migrations portable to Oracle MySQL 8; MariaDB 10.11.16 remains the runtime-tested local engine.
- The V1 application remains on its current schema. V2 remains opt-in and the current application defaults to V1.
- Remaining implementation gates: optionally test a network-level TCP severance in addition to the accepted abrupt CLI process-loss test; broaden mixed-schema/orphan preflight; extend the prototype beyond the exact Phase 8/Phase 13 signature; and rehearse Phase 14–17 plus the remaining V1 rollback/recovery path.


## 10. Prototype implementation and acceptance evidence (20 November 2026)

### Implemented prototype files

- `tools/prototype_migration_ledger.php` and `database/migrations/ledger/prototype_add_status.php`: synthetic success, idempotency, failure and replay-block tests.
- `tools/generate_migration_ledger_manifests.php`: creates explicit manifests from reviewed ALTER-only source files using a quote/comment-aware statement scanner and expected statement counts.
- `database/migrations/ledger/phase13_bridge_manifest.php` (2 steps) and `phase13_clinical_manifest.php` (32 steps): each carries the SHA-256 of its exact original SQL source.
- `tools/run_ledgered_migration.php`: Phase 8 bridge / Phase 13 disposable-clone integration runner, with loopback and target allowlists, required verified dump path/hash, same-session GET_LOCK, preflight/postflight signatures, per-step started/succeeded events, successful receipts and unresolved-attempt blocking.
- `tools/qa/hold_migration_lock.php`: independent-process lock test helper.

### Acceptance results

1. Synthetic runner: applied a two-step migration; recorded checksum and receipt; identical rerun was a no-op. Fault injection after the first committed DDL step recorded `recovery_required`; automatic replay was refused.
2. Concurrency: a separate process held the database-scoped advisory lock; a second runner was refused. The holder then released the lock normally.
3. Checksum tampering: appending a harmless comment to an already-applied manifest was rejected because its checksum changed. The file was restored byte-for-byte and its original SHA-256 reverified.
4. Phase 13 integration on two fresh disposable clones rebuilt from the historical 21-table baseline (commit `14b161e7366de82a69e7ed487a5d75c6bfd83d45`) plus Phase 8 steps 2–3 and Phases 9–12. Both clones passed `mysqlcheck` before migration and matched the exact Phase 8 audit-table signature with 24 non-audit FKs, four missing audit FKs, four required indexes and zero orphans.
5. Success clone: the bridge completed 2/2 steps and unmodified Phase 13 completed 32/32 steps. Postflight verified 28/28 named foreign keys with `ON DELETE RESTRICT`, zero orphans, both synthetic audit sentinels and their JSON/NULL legacy fields intact. `mysqlcheck` passed after migration. Two successful receipts and 70 journal events were recorded.
6. Fault clone: deliberate failure after Phase 13 step 16 left the DDL state partial, recorded `recovery_required`, and blocked replay. The run had one successful bridge receipt and 38 events; no false Phase 13 success receipt was written.

Evidence and verified pre-migration / post-migration dumps are retained under `C:\xampp85\migration-backups\healthbridge\hb-db-ledger-phase13-20261120\`, especially `PHASE13_LEDGER_RESULT.md`, `PREPARE_RESULT.md`, the `*-before-*.sql` backup files, the completed Phase 13 dump and the injected-failure dump. The four temporary ledger test schemas and the temporary Phase 8 success/fault clones were removed after evidence capture.

### Boundaries still in force

- This validates the runner concept only for the exact tested Phase 8/Phase 13 lineage. It is not a general Phase 10–17 migration orchestrator and must not be copied directly to an unverified legacy schema.
- The intentional partial-failure clone is evidence only; it was not repaired in place. Its dump records the partial state. Restore or a separately reviewed forward repair is required after such a failure.
- The connection-loss window between a committed DDL statement and its success event has not yet been fault-injected. Lock contention and checksum tampering were tested.
- No migration ledger was applied to `healthbridge`, NOVA or hosted resources. V1 remains unchanged and V2 remains opt-in.


## Phase 10–17 schema-state classification — 20 November 2026

Added `tools/qa/inspect_phase10_17_schema_state.php`, a CLI-only, local-loopback, read-only classifier restricted to the approved synthetic V1 fixture `healthbridge_hb_auth_email_verify_20261114`. It records independent phase states and aggregate safety counts only; it does not reveal phone/token values, execute migrations or claim migration chronology.

Observed on the fixture (MariaDB 10.11.16): Phases 10 through 17 all `verified_present`. Signatures: Phase 10 11/11 reschedule columns and 5/5 indexes; Phase 11 session/reset fields and 3/3 expected indexes; Phase 12 follow-up instructions; Phase 13 28/28 RESTRICT FKs; Phase 14 stored generated normalized phone column + unique index; Phase 15 outbox 13/13 columns, 2/2 indexes, expected ENUM and InnoDB collation; Phase 16 encrypted queue 16/16 columns and 4/4 indexes; Phase 17 verification fields, queue enum extensions, 11/11 challenge columns, both challenge indexes and CASCADE FK.

Aggregate checks: zero nonempty legacy raw reset tokens, zero duplicate raw-token hash groups, zero Phase 13 audit orphans, zero duplicate normalized-phone groups, and zero orphan email challenge queue rows.

Phase 16 checks allow the documented Phase 17 status-enum superset (`awaiting_verification`, `expired`) while requiring all original Phase 16 values. Report: `C:\xampp85\migration-backups\healthbridge\hb-db-ledger-phase13-20261120\phase10-17-state-classification.json`.

This closes only the read-only state-classification gate for that one synthetic fixture. It does not prove Phase 10–17 chronology, all mixed legacy variants, Phase 14–17 migration replay, V2 restore, application regression or cutover readiness.


## 20 November 2026 — Phase 14–17 historical migration acceptance and collation repair

The Phase 14–17 chain was rehearsed from the retained Phase 8/Phase 13-success dump on a fresh disposable clone. Phase 14 (stored generated phone normalizer + unique index), Phase 15 (notification outbox), and Phase 16 (encrypted registration queue) applied successfully. The first Phase 17 attempt then reproduced MariaDB error 1270 on the `ALTER TABLE users` statement: mixed collations in the Phase 14 stored generated `phone_normalized` expression were reevaluated during the later table alteration.

Historical files were preserved unchanged. Added `database/migrations/phase14b_phone_normalization_collation_compat.sql` as a forward compatibility migration to run after Phase 14 and before Phase 17. It redefines the same generated formula while explicitly converting and collating every text operand as `utf8mb4_unicode_ci`, matching the collation-deterministic strategy already used by V2 migration 2.0.5. SHA-256: `732A44BFC7FB39C2B03827386EF35076CE66BC5C8B20F538BD1EBFFDAC6B61E4` (see the file for the exact bytes; verify the checksum before applying).

A fresh repeat then passed in this order: Phase 14 → Phase 15 → Phase 16 → Phase 14b → Phase 17. Evidence (`C:\xampp85\migration-backups\healthbridge\hb-db007-phase14-17-20261120\COLLATION_FIX_RESULT.md`): Phase 14 generated column/index 1/1; duplicate normalized groups 0; Phase 15 13 columns/2 indexes/InnoDB; Phase 16 16 original columns/4 indexes/InnoDB; Phase 17 2 user verification columns, 3 queue columns, 11 challenge columns, 2 challenge indexes and the expected CASCADE FK. The normalized-phone output SHA-256 remained identical across the collation repair. All 13 pre-existing synthetic users remained `email_verification_required=0` with `email_verified_at IS NULL`; core user-data SHA-256 matched before/after; registration queue stayed empty; challenge orphans=0; both Phase 8 audit sentinels remained 1/1; 28/28 named Phase 13 FKs remained. `mysqlcheck` passed after the complete chain.

Both failed/accepted rehearsal clones were dropped and verified absent. No main `healthbridge`, NOVA, hosted DB, retained V2 prototype or production task was changed. Do not run Phase 17 on a Phase 14 installation in this historical lineage until Phase 14b has been applied and its pre/post signatures verified. This accepts only the tested Phase 8→13 lineage, not every mixed legacy schema variant; the V1 migration ledger runner also remains a prototype, not an approved main-database executor.


## Abrupt DDL process-loss acceptance — 20 November 2026

A separate disposable ledger target tested the window after MariaDB commits DDL but before the runner writes `step_succeeded`. A PHP process acquired the per-database advisory lock, inserted a `running` attempt and `step_started` event, executed the synthetic `ALTER TABLE`, then exited immediately with code 77 before recording success. After the connection closed, a new PDO process confirmed the column existed, the attempt remained `running`, exactly one start event existed and no successful receipt existed. The standard runner then acquired the released lock but refused to replay the unresolved attempt. The schema was dropped and independently verified absent.

Evidence: `C:\xampp85\migration-backups\healthbridge\hb-db-ledger-phase13-20261120\CONNECTION_LOSS_RESULT.md`. This validates abrupt CLI process loss after committed DDL on local MariaDB; a network-level TCP severance was not separately fault-injected. No main database or protected fixture was changed.
