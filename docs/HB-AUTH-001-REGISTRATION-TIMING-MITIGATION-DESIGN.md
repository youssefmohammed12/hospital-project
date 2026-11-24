> **Status update — 24 November 2026.** The mitigations designed here shipped in the final build: duplicate email/phone/national-ID registrations are refused at submission with a specific message, and the worker re-checks identity as a backstop. The dated record below is unchanged.

# HB-AUTH-001 — Registration timing mitigation design

**Status:** Local application integration implemented on `repair/healthbridge-staged-hardening`; main fictional DB HTTP/worker, transactional failure/retry, unique-key collision, and one-minute scheduler checks pass. P1/release gates remain open.
**Date:** 13 November 2026
**Scope:** Remove the account-existence-dependent work from the synchronous registration response path. This design does not close the separate email/phone ownership-verification or multi-host rate-limiter gaps.

## 1. Evidence and root cause

The 13 November isolated comparison exercised the current Step 2 registration endpoint on a fresh Phase 14-aligned disposable clone:

- 40 unique new-account submissions and 40 single-field duplicate conflicts, randomized and interleaved.
- All 80 responses returned HTTP 200 with one identical parsed status/body signature.
- New registration median: 378.852 ms; duplicate median: 363.847 ms; median difference: +15.005 ms.
- Bootstrap 95% interval for the median difference: +8.187 to +22.784 ms (10,000 resamples).
- Two-sided Mann–Whitney p=1.3952e-6; Cliff's delta 0.6275.
- Email, phone and National-ID conflict subgroups showed a similar median differential.
- Database controls confirmed all 40 intended accounts and notification bundles persisted, duplicate alternative identifiers did not persist, the disposable clone passed mysqlcheck 26/26, and the protected source checksums were unchanged.

The current handler validates and password-hashes both branches. It then returns early for a duplicate. A new registration additionally generates a patient number, writes user/preferences/medical-record/audit/outbox rows, commits, and synchronously processes the notification outbox. The generic response equalizes the HTTP status/body, not the amount of work.

Evidence: migration-backups/healthbridge/hb-auth-001-timing-qa-20261113/RESULT.md and timing-results.json.

## 2. Design objective

For a valid Step 2 request, make the public request path perform the same bounded categories of work regardless of whether email, phone or National ID already belongs to an account.

The public request path must not:
- Query whether submitted identifiers already exist.
- Insert user, medical-record, preference, audit or notification rows conditionally.
- Wait for post-commit notification delivery.
- Return a patient ID, patient number, conflict field, or branch-specific status/body.
- Log submitted identifiers, passwords, medical data, encrypted payloads, or encryption keys.

It may still reject malformed input, rate-limited attempts, unavailable storage, and cryptographic/configuration failures. Those outcomes are not identity-availability answers.

## 3. Options considered

### A. Fixed sleep or random jitter — reject

A time floor can hide a small gap in one environment but does not remove branch-dependent work. Requests that exceed the selected floor still leak; sufficiently many observations average out independent jitter. It also adds latency to every request and makes service degradation harder to diagnose.

### B. Dummy writes on duplicate paths — reject

Running artificial inserts/deletes on duplicate submissions to imitate the successful transaction is fragile. It couples timing behavior to schema, indexes, triggers, and storage state; adds unnecessary write load; and creates a second synthetic transaction path that must be kept equivalent to real registration. It is not a maintainable security boundary.

### C. Move all account-specific work to a durable asynchronous intake queue — recommended

Every valid Step 2 submission performs one common synchronous pipeline: validate, compute the password hash, encrypt the canonical payload, insert one queue record, commit, and return the same generic receipt. The worker later decides whether an account can be created.

New and duplicate identities therefore take the same synchronous application path. The branch-specific database work happens after the HTTP response has completed.

## 4. Proposed request/worker flow

### Public Step 2 request

1. Preserve the existing method, JSON type checks, field validation, password policy, and AuthAbuseRateLimiter call.
2. Remove Step 2 uniqueness reads from the HTTP handler. Do not add replacement probes or duplicate-specific branching in the request process.
3. Compute password_hash exactly once for every otherwise-valid submission. Never queue or persist the plaintext password or confirmation value.
4. Build one canonical, versioned payload containing the password hash and required registration fields, including sensitive patient/emergency-contact information.
5. Encrypt the complete payload using AES-256-GCM with a fresh random 96-bit nonce and 128-bit authentication tag. Bind a fixed purpose/version string as authenticated additional data. Reject missing, malformed, or unavailable key configuration; do not fall back to plaintext.
6. Insert exactly one registration-intake queue row containing only the encrypted payload, nonce, tag, format/key version, random internal request identifier, and bounded queue metadata. Do not store email, phone, National ID, password hash, clinical values, or raw IP in plaintext queue columns.
7. Commit the queue row and return the existing generic request-received response. The response body and status must be the same for new and duplicate identities.

The request should not return a receipt that can be polled for branch-specific completion. A status endpoint that exposes the moment a particular job was processed could recreate a timing oracle.

### Registration worker

1. Run from CLI only. Process a bounded batch of pending intake rows in stable order. Prevent overlapping local runs and lock each queue row before processing so multiple worker instances cannot process one job concurrently.
2. Decrypt and authenticate the payload. An invalid tag, unknown payload version, or missing key must fail closed; never attempt a best-effort plaintext fallback.
3. Re-run authoritative format/date/profile validation in the worker before persistence. The queue is not a trust boundary.
4. Perform email, generated normalized-phone, and National-ID uniqueness checks in the worker. Database unique indexes remain authoritative under concurrency.
5. If any identifier conflicts, discard/clear the encrypted payload and mark the intake job processed using the same externally invisible terminal state used for successful registration. Do not record which identifier conflicted in public or routine logs.
6. If no conflict exists, create the patient user, default preferences, medical record, audit event, and admin notification outbox events in one transaction. Mark the intake job terminal and clear its ciphertext in that same transaction, so account creation and job completion commit or roll back together.
7. A duplicate identity is marked terminal and its ciphertext cleared in a short transaction with no account writes. A concurrent unique-index conflict must roll back the account transaction, then be classified as a duplicate only for the email, normalized-phone, or National-ID constraints; unrelated unique-key errors must be retried or treated as operational failures. Do not call processOutbox() from the HTTP registration request. The CLI worker processes committed notification events after the registration transaction.
8. On transient worker failure, roll back the registration transaction and retry the intake job with bounded backoff. Keep only sanitized error classifications in logs. After the retry ceiling, retain a payload-free terminal record for operator review; never log decrypted content.
9. Clear ciphertext and authentication material as soon as the job reaches a terminal outcome. Keep minimal, payload-free operational metadata for a bounded retention period, then delete it.

The new-account and duplicate outcomes must be terminally indistinguishable to the caller. This timing design does not claim to solve every account-enumeration method; ownership verification remains a separate open security requirement.

## 5. Queue data and key management

Proposed additive Phase 16 migration, phase16_registration_intake_queue.sql, with an InnoDB table containing:

- Internal request ID (random, unique; not exposed to the requester).
- Payload format/key version.
- Nonce, authentication tag, ciphertext.
- State, attempt count, availability time, created/started/processed timestamps.
- Sanitized last error code.
- Index supporting pending-job ordering and lease recovery.

No foreign key to users is needed because the job is created before the user exists. No registration payload fields should be indexed or stored in plaintext.

The local PHP runtime has OpenSSL enabled. AES-256-GCM was validated using synthetic data: encryption/decryption round-trip passed, a modified tag was rejected, and a mismatched authenticated context was rejected. The Sodium extension DLL exists but the extension is disabled; enabling it is unnecessary for this design.

Key requirements:
- Use a dedicated 32-byte random key configured outside source control and outside the database.
- Load it from a deployment environment variable/configuration value; never derive it from DB credentials or the application path.
- Fail closed if the key is absent or invalid.
- Include a key/version identifier in queue metadata. Rotate only after draining or explicitly re-encrypting outstanding jobs.
- Restrict access to the key to the web/worker runtime identity; do not write it to logs, QA evidence, or XMemo.
- Document backup/restore handling: ciphertext is useless without the matching key, and key backups must be handled separately with stronger access controls.

## 6. Worker cadence and user experience

The current Windows Notification Outbox task is configured for every five minutes. A new-account job could therefore wait almost five minutes before it can be used. That cadence is too slow for this workflow.

Recommended local cadence: run the CLI intake worker at least once per minute, with overlap prevention and a bounded batch size. The existing notification worker can be extended carefully or a separate registration worker can be invoked by the same scheduled task. The choice must preserve notification retry behavior and must not make a public HTTP endpoint invoke the worker.

The registration success screen already uses neutral wording. It should explain that a new account may take up to one minute to become available, without exposing whether an existing identity was found. Do not expose worker state or conflict results in the public UI. Existing accounts should continue using the ordinary login/password-recovery flow.

Any deployment that cannot guarantee a worker/cron cadence must not enable the asynchronous registration design. A stopped or unavailable worker must be visible operationally through queue-age monitoring, not by changing the public response based on identity.

## 7. Required failure and integrity guarantees

- HTTP acceptance and queue insertion are atomic: no success response before the encrypted job is committed.
- A worker crash before the registration transaction commits leaves no partial account.
- A worker crash after commit but before job finalization must not create a second account; unique constraints and an explicit job state/lease must make replay safe.
- Concurrent submissions of the same identifiers may create multiple encrypted intake jobs, but at most one complete patient identity and associated data bundle may commit.
- Duplicate conflicts are handled identically whether they concern email, phone, National ID, or multiple identifiers.
- No duplicate job may update an existing account, password, patient profile, or medical record.
- Notification outbox events are created in the same transaction as the patient account and are delivered only after commit.
- Queue encryption failure, missing key, DB outage, and worker backlog must fail closed without persisting plaintext or falsely claiming an account exists.

## 8. Prototype and acceptance plan

Do not change the active registration endpoint until a disposable-clone prototype is ready.

1. Start local MariaDB deliberately and verify the protected source is reachable read-only. Create a fresh disposable clone, align Phase 14 only on that clone, and prove all 26 table baselines match.
2. Add the queue migration and worker to a QA copy/router only. Use a temporary test encryption key injected into the isolated process environment; never read or reuse a real project key.
3. Prove request-path uniformity with at least 40 new and 40 duplicate cases randomized/interleaved. All valid submissions must return one identical HTTP status/body signature. Compare medians, spread, and a bootstrap interval; require the interval for the branch median difference to include zero and the effect size to be practically negligible in this controlled environment. Do not call this proof of remote exploitability resistance.
4. Verify ciphertext at rest: no submitted email, phone, National ID, name, password hash, or medical text is searchable in plaintext; wrong key, wrong tag, altered AAD, and unknown version all fail closed.
5. Verify 40/40 new registrations produce exactly one user/preferences/medical-record/audit bundle and exactly the expected delivered outbox/notification events after worker execution.
6. Verify 40/40 duplicate cases create no user, preference, medical record, audit, or registration notification. Cover email-only, normalized phone-only, National-ID-only, and multi-field conflicts.
7. Test concurrent duplicate submissions, worker overlap, transient failures, retry exhaustion, worker restart, and replay after each transaction boundary. Confirm no duplicate account and no lost committed notification.
8. Verify queue payloads are cleared on all terminal paths, age monitoring works, worker schedule meets the one-minute target, and the generic UI handles delayed activation.
9. Compare all 26 clone tables, run mysqlcheck 26/26, and prove all protected-source checksums remain unchanged. Drop only the disposable clone and isolated runtime resources.
10. Run the registration browser regression and login/duplicate behavior checks after the isolated prototype is accepted.

## 9. QA prototype results — 13 November 2026

The encrypted asynchronous-intake prototype was implemented only under the external QA backup folder and exercised against disposable database `healthbridge_hbauth001_async_qa_20261113`. The active registration endpoint, application migrations, protected databases, scheduled tasks and deployment were not changed.

### Request-path uniformity and persistence

- 40 new submissions and 40 duplicate submissions were randomized/interleaved across ten test rate-limit buckets. Duplicate cases comprised 14 email, 13 normalized-phone and 13 National-ID conflicts.
- All 80 requests returned HTTP 200 and the exact same response-body SHA-256 (`67996f6b83c6346098dad8edd0838f3f59c854dbb28c661393448ee10cd6b0f3`).
- New median: 349.035 ms; duplicate median: 349.633 ms; difference: −0.598 ms. Bootstrap 95% interval: −2.986 to +1.669 ms; Mann–Whitney p=0.662; Cliff's delta −0.0575. This sample did not detect a timing difference, but loopback evidence is not proof of remote exploitability resistance.
- Before worker execution, all 80 jobs were pending with nonce, tag and ciphertext populated. A synthetic identifier marker was not found in ciphertext. The worker completed 40 and classified 40 as duplicates; it recorded zero failures/retries and delivered all 40 expected notification events.
- Persistence verification found exactly 40 new users, 40 preference rows, 40 medical records, 40 audit events, 40 delivered outbox events and 40 matching notifications. All 80 terminal queue jobs had nonce/tag/ciphertext cleared.

### Failure, concurrency and recovery controls

- Five negative queue jobs—tampered GCM tag, wrong key, wrong AAD, unsupported payload version and unsupported key version—were all rejected terminally and had their encrypted payload material cleared.
- A request with the queue encryption key absent returned HTTP 503 with the generic unavailable message and did not insert a queue row.
- A multi-field conflict using an existing synthetic email, normalized phone and National ID simultaneously was classified as a duplicate; the existing account's preferences, medical record, audit and outbox counts were unchanged.
- Bounded retry exhaustion was exercised with an injected user-insert failure: the worker attempted the job five times, then marked it failed, cleared all encrypted fields, and left zero partial account rows.
- The first concurrent-worker probe exposed an InnoDB gap-lock deadlock: concurrent `SELECT ... FOR UPDATE` existence checks on absent identity keys conflicted when both transactions then inserted into the unique email index. The QA prototype now uses non-locking existence reads; the database unique indexes remain authoritative and duplicate-key exceptions classify the losing concurrent insert. No active application source was changed.
- The repeat two-worker race processed 20 jobs for 10 repeated identities: exactly 10 accounts completed and 10 jobs became duplicates, with zero failed/retried jobs, ten notification deliveries and all 20 payloads cleared. The local overlap guard also passed.
- Expired processing-lease recovery passed. An injected transient failure before medical-record insertion rolled back all partial user/preferences/record/outbox rows; the next worker pass succeeded exactly once and delivered its notification.
- A first, pre-fix concurrency probe left five pending jobs encrypted under an ephemeral key that had been cleared when that test process ended. Those five synthetic jobs were explicitly marked terminal failed and their ciphertext cleared in the disposable clone; they were not retained as recoverable work.

### Integrity and evidence

- Initial clone matched the protected source's 26 table names, exact row counts and table checksums before Phase 14 alignment and queue installation.
- Phase 14 and the QA-only queue migration were applied to the disposable clone only; the clone passed `mysqlcheck` 27/27 after testing.
- Final protected-source checksums matched the saved 26/26 baseline and the protected source passed `mysqlcheck` 26/26. The clone had zero remaining QA triggers. Loopback test servers were stopped and ports 18513, 19929 and 18514 were closed.
- Evidence and disposable scripts: `C:\\xampp85\\migration-backups\\healthbridge\\hb-auth-001-async-prototype-20261113\\`. The detailed `http-run` artifacts include timing results, queue/persistence checks, crypto rejection evidence and worker resilience evidence.

At the end of the isolated prototype phase, the implementation had not yet been promoted to application code. Section 11 records the subsequent local application integration and its current acceptance evidence; neither phase closes the full finding.

## 10. Prototype-era constraints before local integration (historical snapshot)

- No active email transport or email-verification flow is present in the HealthBridge repository/documentation. This timing change must not silently claim email ownership verification.
- The rate limiter remains host-local; multi-host storage remains a separate release gap.
- The current worker schedule is five minutes and must be deliberately revised/tested before asynchronous account creation can be enabled.
- At review time, PHP 8.5.12 has OpenSSL enabled; Sodium is present but disabled. The OpenSSL AEAD primitive test passed.
- MariaDB was initially stopped during the design review; XAMPP was subsequently started and used for the disposable-clone prototype. No protected source database was written.
- The existing notification outbox scheduled task still runs every five minutes. The registration-intake worker must be separately configured and verified at a maximum one-minute interval before asynchronous registration can be enabled; no scheduled task was changed.
- Queue-age monitoring, delayed-activation UI/browser behavior, production key provisioning/rotation/backup, and post-commit crash/replay recovery still need explicit release acceptance.
- Email/phone ownership verification is still absent, and the abuse limiter remains host-local; neither gap is addressed by this timing prototype.
- The active registration endpoint, application migrations, protected source database, scheduled tasks, Git staging, commits, pushes and deployment remain unchanged. Only the disposable QA clone and external QA evidence/scripts were created or modified.

**Prototype-phase recommendation (historical, superseded by Section 11):** Treat the prototype as positive local evidence, not as a production fix. Keep HB-AUTH-001 P1/open. The local application integration and one-minute task have since been completed, but delayed-registration browser acceptance, key recovery/rotation, crash/replay, queue-age alerting and multi-host acceptance remain open. Address email/phone ownership proof and shared limiter storage separately.

## 11. Local application integration and acceptance — 13 November 2026

The encrypted asynchronous intake has now been promoted from the disposable prototype into the local application source on the isolated working branch `repair/healthbridge-staged-hardening`. This is local development evidence only; no hosted deployment or production readiness is claimed.

### Implementation

- `database/migrations/phase16_registration_intake_queue.sql` adds the InnoDB `registration_intake_queue` table additively. Phase 10–15 migrations were not replayed because their final schema signatures were already present.
- `api/auth/register.php` now validates and hashes Step 2 once, encrypts and queues the payload, then returns the generic receipt. It no longer performs identifier availability reads, patient creation, or synchronous notification dispatch.
- `RegistrationProfileValidator` is shared by HTTP and worker paths. `RegistrationIntakeService` performs AES-256-GCM encryption/decryption, queue claims/leases, non-locking identity existence checks, authoritative database-conflict handling, complete patient aggregate creation and transactional queue completion.
- The account, default preference, medical record, audit row, and admin notification-outbox events are committed with the terminal queue state in one transaction. Notifications are dispatched only after commit. Terminal jobs have nonce, tag and ciphertext cleared.
- The dedicated 32-byte key is protected outside the repository/database in a Windows DPAPI CurrentUser file. The worker loads it into process-scoped environment variables and clears those variables afterward. No key value was printed or stored in source, the database, logs, or evidence.
- The registration success UI already explains the approximate one-minute activation delay and does not auto-login or reveal duplicate status.

### Local main-database evidence

The user authorized use of the main fictional `healthbridge` database for these integration tests. A full data and schema backup was made before Phase 16; the backup hashes and manifest are retained in `C:\\xampp85\\migration-backups\\healthbridge\\hb-auth-001-async-app-implementation-20261113\\`.

- Two HTTP integration rounds each exercised Step 1 and four Step 2 cases (one new identity plus email-, phone-, and National-ID-conflict submissions). Each round's four Step 2 responses were HTTP 200 with identical response-body hashes. New-path timing samples were 360.704 ms and 349.150 ms respectively; each set was small and is not a statistical claim. All accepted queue rows were encrypted before worker execution.
- Worker results across the two rounds: two completed patient accounts and six duplicates; each new account received its preference, medical record, audit event, and delivered notification. All eight terminal payloads were cleared.
- A failure-injection test forced the registration notification-outbox insert to fail. The first worker attempt left the queue job pending and encrypted and left zero user, preference, medical-record or audit rows. After removing the temporary trigger, retry attempt two completed the aggregate exactly once, cleared the payload and delivered the outbox event. The temporary trigger was removed.
- A second synthetic SQLSTATE 23000 / driver 1062 conflict injection verified that the worker classifies a unique-index race as a duplicate, clears the payload and creates no partial aggregate. The temporary trigger was removed.
- Missing-key behavior: the CLI service-constructor negative remains passed; a follow-on HTTP/Chromium negative passed 1/1 using an isolated loopback PHP server with queue-key settings forced empty, returning the generic HTTP 503. The main DB remained at 13 queue rows / 163 users before and after; no missing-key account or queue row was created.
- Current local DB checkpoint after these tests: 27 tables; five completed and seven duplicate queue jobs; no encrypted payload remains; no pending/processing registration jobs; no temporary `hb_test_*` triggers remain. Five synthetic QA patient accounts remain in the main fictional database solely for the planned final reset/reseed. Do not mistake these for demo users.
- `mysqlcheck` passed all 27/27 tables. PHP lint passed 119/119 files; validator tests, Node syntax and PowerShell parser checks passed.

### Worker scheduling and browser regression

- A separate `HealthBridge Registration Intake Worker` Windows Scheduled Task is configured to run every minute under the DPAPI key-owning interactive Windows user, with Limited privileges, `IgnoreNew` overlap behavior, a four-minute execution limit, and battery operation allowed. Its hidden PowerShell action runs `tools/run_registration_intake_worker.ps1`; daily logs are written under `C:\\xampp85\\logs\\healthbridge\\registration-intake\\`.
- Manual Task Scheduler execution and a subsequent automatic scheduled tick both completed with LastTaskResult 0; the automatic run logged zero failed/retried jobs and a healthy empty queue. The existing `HealthBridge Notification Outbox Worker` task was not modified.
- The safe shared-toolkit Chromium suite completed **15 passed, 17 skipped, 0 failed**. Public pages, authentication page shells, registration wizard shell and no-token reset state passed. All 17 authenticated role/mutation scenarios remained skipped because their explicit QA enable flags were absent; this suite did not submit a registration or mutate database rows.

### Integrated asynchronous endpoint timing characterization — 14 November 2026

The current integrated `api/auth/register.php` Step 2 was exercised on a fresh disposable 27-table clone with 40 randomized/interleaved new-identity requests and 40 duplicate-identity requests (email 14, normalized phone 13, National ID 13). All 80 returned HTTP 200 with one byte-identical response signature. New median was 349.918 ms; duplicate median was 349.755 ms; median difference +0.164 ms. The bootstrap 95% interval was -1.665 to +0.940 ms; Mann–Whitney two-sided p=0.468; Cliff's delta -0.095. The test therefore detected no statistically distinguishable timing gap on the current local single-host loopback path. The worker completed 40 new accounts, classified 40 duplicates, delivered 40 notices, and cleared all terminal payloads; 80 unused duplicate identifiers were verified absent. Clone mysqlcheck passed 27/27. A fresh before/after logical dump of the main `healthbridge` database had the exact same SHA-256; all 27 row counts and AUTO_INCREMENT values matched. The named clone and loopback server were removed. Full evidence: `C:\\xampp85\\migration-backups\\healthbridge\\hb-auth-001-integrated-timing-20261114\\RESULT.md`.

This closes the integrated local timing-characterization gate; no additional local timing mitigation is justified by this sample. The earlier 13 Nov +15.005 ms differential remains valid historical evidence for the pre-integration path and is superseded for the current integrated endpoint only. Loopback results are not proof of public-network timing resistance, a hosted runtime, or multi-host behavior.

### Remaining P1 and release gates

HB-AUTH-001 remains P1/open. Local queued-registration Chromium acceptance and the HTTP missing-key negative pass. Four real worker crash/replay boundaries passed on a fresh disposable clone: pre-claim-commit rollback; post-claim-commit recovery through an expired lease; account/terminal-queue transaction rollback before commit; and post-commit notification dispatch recovery with exact-once delivery. A same-user DPAPI backup copy was restored successfully and processed a pre-existing encrypted v1 job. A temporary v1+v2 keyring with v2 active also decrypted/completed jobs encrypted under both versions; the original application DPAPI file was unchanged. This proves same-machine/same-Windows-user DPAPI recovery only, not off-device or different-user recovery. The clone was removed after mysqlcheck 27/27, zero pending queue/outbox work, and zero terminal ciphertext; the main healthbridge dump hash, row counts and AUTO_INCREMENT values were unchanged. Queue-age monitoring now has a local operator signal: the wrapper writes `queue-age-alert.json`, records OPEN/REMINDER/RESOLVED transitions in `queue-age-alerts.log`, reminds every 30 minutes while stale, and returns Task Scheduler exit code 2 for a stale queue (0 for healthy, 1 for worker failure). The 16/16 helper tests, isolated wrapper stale/recovery test, hidden VBScript exit-code propagation test and live empty-queue scheduled-task run passed. This is not an email/push notification; an operator must inspect the marker/log or Task Scheduler result. A seeded Patient Quick Demo browser login also passed 1/1 on a fresh disposable clone: the login POST returned 200, patient role persisted in the response, the browser redirected to the patient dashboard, an HttpOnly PHP session cookie was present, and there were zero page errors. The clone was dropped and main-source dump, row counts and AUTO_INCREMENT metadata remained unchanged. This smoke validates only the pre-seeded demo account; it does not prove that a newly registered patient can authenticate with their chosen password. The local frontend contains role-specific demo identities and a fixed demo password; keep these accounts strictly synthetic and disable or protect this shortcut before any non-demo/production use. Hosted source/runtime was not inspected. The local post-registration login gate is closed by the 14 Nov 2026 browser acceptance: the newly created synthetic patient logged in with the registration password, received an HttpOnly/SameSite=Lax session cookie, reached the patient dashboard and passed the protected current-user check (1/1). Evidence: `C:\\xampp85\\migration-backups\\healthbridge\\hb-auth-001-post-registration-login-20261114\\RESULT.md`. Remaining gates: out-of-band queue-age notification/operator ownership; email/phone ownership verification (design-only proposal at docs/HB-AUTH-001-CONTACT-OWNERSHIP-VERIFICATION-DESIGN.md; implementation blocked on mail/SMS transport and policy approval); and shared multi-host limiter/worker coordination. The limiter and worker locking remain host-local; local single-host acceptance does not establish a distributed design. Hosted/production verification and SMTP delivery remain separate and were not attempted.

The main fictional DB has not yet been reset and reseeded. Perform that only after the remaining tests, after taking a fresh current backup and verifying the restore path; preserve the pre-Phase-16 backup and all QA evidence. Do not drop pending jobs, and do not reset or replay other databases.

### Canonical schema and final demo-data refresh

The Phase 16 queue table is now also part of the canonical fresh-install source: `database/healthbridge.sql` defines all 27 master tables and `database/healthbridge_empty.sql` describes 27. The additive `database/migrations/phase16_registration_intake_queue.sql` remains the upgrade path for existing installs.

The final authorized fictional-data refresh is planned with the existing canonical scripts: `database/healthbridge_demo_data.sql` for core quick-login seed data, followed by the CLI-only guarded `tools/seed_demo_hospital.php` generator (24 generated doctors, 120 generated patients and 480 generated appointments). High-level expected totals are 157 users (one admin, 32 doctors and 124 patients), 486 appointments and 124 medical records. All records are synthetic. Before dropping/recreating only `healthbridge`, a fresh full backup must be restored into a new, uniquely named validation schema and checked for exact row counts, checksums, schema and AUTO_INCREMENT values, followed by mysqlcheck. See the external `RESULT.md` for the exact runbook and evidence locations.

### Registration profile-validation follow-on

The shared HTTP/worker validator was hardened after comparing it with the canonical schema and current form constraints. It now enforces server-side maximum lengths, prevents the combined display name from exceeding the users.name column, limits the selected gender to Male/Female, and checks that it matches the gender encoded in the validated Egyptian National ID. Address and optional free-text fields are capped at 4,000 characters to keep encrypted payloads bounded. Focused tests passed for valid HTTP/queued profiles, invalid ID, gender mismatch, oversized first name/address and invalid password hash; PHP lint and Node syntax passed. These tests did not write to the database. Detailed result: external RESULT.md.

### Scheduled worker visibility

The user requested that the recurring registration worker not show a PowerShell window. Both visibility settings are now enabled: the scheduled task XML Hidden flag is true, and the task action uses `-WindowStyle Hidden`. Post-change run completed with LastTaskResult 0; the task returned to Ready and its latest log line reports an empty queue with zero failures/retries. Battery operation, Limited privileges, no-overlap behavior and the four-minute execution bound remain configured.


## Local queue-age email transport integration — 14 November 2026

The previously local-only queue-age marker now has an email delivery path in the local registration worker wrapper. It uses the shared PHPMailer/Gmail transport in `services/HealthBridgeMailService.php`, sender/operations mailbox `llaunchboard@gmail.com`, Gmail SMTP implicit TLS on port 465, and an externally stored Windows DPAPI app password.

- OPEN email is due on the first observed queue age above 120 seconds.
- REMINDER is due 30 minutes after the most recent successful alert/reminder delivery.
- RESOLVED is due as soon as the queue returns healthy.
- A failed send is retried after five minutes. Attempt time/event and successful delivery time are tracked separately; no event is marked sent until the mail process succeeds. A pending RESOLVED marker is retained until its email succeeds.
- Message content is restricted to alert type, oldest queue age and first-seen timestamp. It does not contain patient identifiers, decrypted registration content or tokens.
- 29/29 isolated helper tests and fake-worker/fake-mailer wrapper integration passed. The wrapper harness covered OPEN send failure, cooldown and retry, reminder, RESOLVED send failure/cooldown/retry and successful marker cleanup. No database connection/write or real email was used.

The DPAPI reader passed a disposable dummy-value round-trip. The actual app-password file has not been configured, so no SMTP delivery has been verified and the live scheduled task is not yet accepted for email alerts. The local worker/task configuration was not changed. Evidence is under `C:\\xampp85\\migration-backups\\healthbridge\\gmail-mail-transport-20261114\\`. This is local delivery engineering only, not remote/multi-host alerting or hosted deployment verification.
