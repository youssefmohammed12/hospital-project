# HB-AUTH-001 — Multi-host limiter and worker coordination review

**Review date:** 14 November 2026  
**Scope:** Local source and schema-definition review only  
**Project:** `C:\xampp85\htdocs\optimized`  
**Branch/HEAD observed in the existing handoff:** `repair/healthbridge-staged-hardening` / `d4dd731b7578d9309d8eca50ec57bb319459dade`

## Executive conclusion

The current registration and notification workers are not coordinated solely by host-local file locks. Both consumers use transactional `SELECT ... FOR UPDATE` against InnoDB queue rows, so workers on separate hosts can coordinate through one shared primary database. Registration additionally uses a random per-claim worker token and rechecks ownership under a row lock before creating the patient aggregate. The notification worker inserts the in-app notification and changes the outbox row to delivered in the same transaction.

That is a viable foundation for multiple worker processes **only when every worker uses the same writable transactional database and compatible schema**. It is not a completed multi-host acceptance test. The current authentication limiter remains explicitly single-host because both its HMAC secret and attempt counters live in the host temporary directory. Multi-web-node rate limiting is therefore not supported yet.

No Redis or database-backed limiter was added. No schema, application code, environment setting, task, database, key, Git state, or hosted resource was changed during this review.

## 1. Authentication limiter — host-local

`includes/AuthAbuseRateLimiter.php` stores `hmac-secret.key` and `attempts.json` below `sys_get_temp_dir()`. The directory name includes a fingerprint of the absolute application root. It protects key creation and counter-file updates with `flock(LOCK_EX)`, stores HMAC digests rather than raw email/phone/National-ID values, prunes expired entries, and bounds the file to 10,000 entries.

- This protects concurrent PHP processes that use the same directory on the same host/filesystem and cooperate with the same lock.
- A separate host normally has its own temporary directory and HMAC secret. Its counters are independent, so a client can receive a fresh per-IP/per-identifier allowance on each node.
- Different application-root paths also produce different limiter directories, even if code is otherwise identical.
- Restart/redeploy or temporary-directory cleanup may reset the limiter. This is an abuse-control counter, not durable account data.
- Corrupt or unreadable state fails closed: login/registration return HTTP 503. The update rewrites the JSON file in place while holding the lock; an interruption during a rewrite can therefore cause an availability failure until the state is repaired.
- Login and registration deliberately use `REMOTE_ADDR` and ignore forwarding headers. This avoids blindly trusting spoofable `X-Forwarded-For`, but a reverse-proxy deployment must configure the web server/PHP runtime to expose the real client address through a trusted proxy configuration. Otherwise many unrelated clients may be counted under the proxy's address.

**Disposition:** Keep the file-backed limiter for a verified single web instance with shared persistent local storage. Before enabling multiple web instances, use one shared atomic limiter store. A database-backed counter table is a plausible zero-new-service option; Redis is not a prerequisite and should not be introduced without a separate cost/operations decision. Any shared design must use one consistently provisioned, domain-separated HMAC secret across nodes, atomic increments, bounded retention, and fail-closed behavior when the limiter store is unavailable.

## 2. Registration worker — local lock plus database claim fencing

`RegistrationIntakeService::processBatch()` acquires a non-blocking `flock` file beneath the host temporary directory. Its filename also includes an absolute application-root fingerprint. This prevents overlap for the same project copy on the same host; it is not a distributed lock and does not coordinate separate machines or differently rooted copies.

The database claim is the important cross-host primitive:

1. `claimNextJob()` opens a transaction and selects one eligible pending or expired-processing row with `ORDER BY id LIMIT 1 FOR UPDATE`.
2. While that row is locked, it changes the row to `processing`, increments `attempt_count`, sets a five-minute lease, and writes a cryptographically random worker token. It commits the claim before payload processing.
3. Before account creation, `processClaimedJob()` begins a transaction and locks the queue row using `id + status='processing' + worker_token`. A worker whose lease was reclaimed and token replaced cannot create the patient aggregate.
4. The patient user, preferences, medical record, audit/outbox event and terminal queue update are committed in one transaction. A crash before commit rolls them all back; a crash after commit leaves durable outbox work.

The queue DDL declares `ENGINE=InnoDB` and indexes ready/lease/processed status. Database `NOW()` is used for claim availability and lease timestamps, so workers rely on the same database clock.

The claim query does not use `SKIP LOCKED`. Competing claimers may briefly wait behind a locked first candidate before the next eligible row is selected. This is a throughput/contention limitation, not by itself a duplicate-claim defect. The same transactional semantics must be verified on the actual shared database engine/version before enabling multi-host workers.

The lease is fixed at five minutes and has no heartbeat. The random token fences stale workers. If a worker is still doing pre-transaction work when its lease expires, a second worker may replace the token; the first worker then fails the ownership recheck. Once the first worker has locked the queue row inside the aggregate transaction, a competing claimer waits on that row and re-evaluates after commit/rollback.

## 3. Notification outbox worker — database transaction is the lock

`NotificationService::processOutbox()` has no local worker file lock. For each event it:

- begins a database transaction;
- selects one eligible pending outbox row with `ORDER BY id LIMIT 1 FOR UPDATE`;
- checks that the recipient exists and has enabled the event type;
- inserts the in-app `notifications` row;
- changes the same outbox row from pending to delivered; and
- commits both writes together.

The DDL also declares InnoDB and a ready-event index. With workers connected to the same writable transactional database, the row lock and atomic insert/completion transaction are the coordination mechanism. Concurrent workers may wait rather than skip locked rows. The current method performs only database-backed in-app delivery; this evidence must not be generalized to future email/SMS/network side effects. Those would need their own idempotent delivery contract.

## 4. Queue encryption keyring — every registration node must agree

`RegistrationIntakeService::loadKeyRing()` reads `HB_REGISTRATION_QUEUE_KEYS_JSON` and `HB_REGISTRATION_QUEUE_ACTIVE_KEY_VERSION`. Every web process that enqueues work and every worker that may claim it must have compatible key material and version identifiers. Workers must retain all versions still referenced by pending/processing jobs. A safe rotation rollout expands the accepted keyring on all workers first, changes the active writer version only after that, and retires an old version only after its queued work is drained.

The local Windows launcher obtains the keyring through a same-user/same-machine DPAPI loader. The 14 November backup/restore test proved same-user/same-machine recovery only. A DPAPI-protected blob is not a cross-host key-distribution mechanism. A second worker host needs a separately approved secure secret-provisioning method; do not copy plaintext key values into source, SQL, logs, or this document.

## 5. Additional source finding — hard-crash recovery bypasses the attempt ceiling

**Historical finding: HB-AUTH-006 (P2), resolved locally on 14 November 2026.**

Before the fix, `RegistrationIntakeService` defined `MAX_ATTEMPTS = 5`, but enforced it only in `retryOrFail()` after caught exceptions. `claimNextJob()` reclaimed expired `processing` rows without checking existing `attempt_count`; abrupt worker exits bypassed `retryOrFail()`, allowing repeated crashes to exceed the advertised ceiling. This did not bypass the worker-token/transaction protection against partial patient aggregates, but could keep an encrypted job retrying indefinitely.

The implementation and five-hard-crash acceptance results are recorded below.

**Implementation status (14 November 2026): resolved locally.** `claimNextJob()` now checks an expired processing row's attempt count while holding its `FOR UPDATE` lock. An expired attempt at or above `MAX_ATTEMPTS` is atomically marked failed, nonce/tag/ciphertext and worker token/lease are cleared, processed time is set and the safe error code `crash_recovery_attempt_limit_exceeded` is recorded. Attempt five is still allowed to run; only its expired lease is terminalized. `processBatch()` counts this as a failed job and counts it against the per-run batch limit. Fresh 27-table clone QA injected abrupt exits after committed claims on attempts 1–5; the next invocation terminalized the row without a sixth claim (failed=1, claimed=0), and a later replay claimed nothing. No patient aggregate was created. All 27 clone table counts matched baseline except the one intentional queue row; mysqlcheck passed 27/27. The protected main source dump SHA-256, all row counts and all AUTO_INCREMENT values were unchanged. The clone was dropped. Full result: `C:\\xampp85\\migration-backups\\healthbridge\\hb-auth-006-crash-retry-cap-20261114\\RESULT.md`.

## 6. Deployment-readiness matrix

| Topology | Source-level assessment | Required before relying on it |
|---|---|---|
| One web instance + one worker host + persistent local storage | Matches the current limiter and local lock assumptions. | Confirm the host actually provides one shared filesystem to all PHP web processes and scheduled workers, and that `REMOTE_ADDR` is correctly populated. |
| One web instance + multiple worker processes on one host | Queue row locking/token fencing supports overlap; the registration file lock also prevents same-copy local overlap. | Keep workers on the same primary DB and same registration keyring. Run a simultaneous-worker acceptance test on a disposable clone. |
| Multiple web instances + one shared DB | Worker queue transactions can coordinate through the DB; the file-backed authentication limiter splits its counters by host. | Replace limiter with a shared atomic store, share compatible queue key versions, validate trusted proxy IP handling, and test concurrency. |
| Multiple web/worker hosts | Conditionally possible for the workers, not ready as a complete topology. | All prior controls, a common primary transactional DB, same schema, identical compatible keyrings, cross-node concurrency/lease tests, and documented secret rotation/recovery. |

The current Alwaysdata staging environment was **not inspected** as part of this local review. Its successful scheduled-task runs prove that the configured task can execute its worker command and reach its configured database; they do not prove multi-host storage sharing, proxy address semantics, or cross-node concurrency. Do not infer a multi-instance topology from the existence of two scheduled workers.

## 7. Verification boundary and next work

The initial multi-host assessment was source/schema-definition only. Its follow-up retry-ceiling acceptance used a fresh disposable clone and the QA-only crash hook; no main-source DB mutation, hosted access, environment-file inspection, key-material display, scheduled-task change or Git operation occurred. The follow-up did exercise five consecutive hard exits and the terminal/replay path. It did not run cross-host concurrency or test a second machine; those deployment gates remain open.

Recommended order:

1. HB-AUTH-006 is resolved locally; retain the regression result and move on to the separate multi-host acceptance gates.
2. Before any multi-web-node deployment, select and implement a shared atomic limiter (prefer evaluating the existing shared database before adding Redis).
3. Verify the production web server's trusted-proxy/client-address behavior without trusting arbitrary request headers.
4. Run two-registration-worker and two-outbox-worker concurrency/lease recovery acceptance against disposable copies of the actual target database engine.
5. Document a secure queue-keyring provisioning and rotation procedure for every worker/web node.

**Initial source review:** no code/schema/deployment change. **Follow-up:** local `services/RegistrationIntakeService.php` was fixed and tested as recorded above; no schema or deployment change.  
**Hosted resources accessed:** none.  
**Git operations:** none.
