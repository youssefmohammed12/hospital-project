# HB-AUTH-001 — Contact ownership verification design

**Status:** Registration email-verification workflow implemented in local source and verified on a disposable Phase 17 clone; Phase 17 is NOT applied to the main local database.  
**Date:** 14 November 2026  
**Scope:** Public patient self-registration. Later user/admin contact changes remain a separate follow-up.  
**Accepted policy:** A new public patient must prove control of the submitted email address before a patient account/medical record is created and before login is possible. Phone ownership is not verified in this phase.

## 1. Executive summary

HealthBridge validates email/phone formats and holds public registrations in the encrypted intake queue. The local Phase 17 implementation now proves control of the submitted email before creating a new patient account. It does not prove phone ownership. This workflow is applied and clone-tested only; the main local database remains on Phase 16 until a separately authorized migration.

The safest fit with the current asynchronous design is to keep the complete registration profile encrypted in the registration queue while the job waits for email verification. The worker must not create the patient user, EMR, preferences or registration audit until the email challenge has been redeemed. This prevents a person from reserving another person's email address by submitting a fake registration.

The Step 2 HTTP response remains a generic request-received response for every valid submission. It must not distinguish a new identity, existing identity, email-delivery result, pending verification or expiry. No public status/poll endpoint is introduced.

**Phone ownership remains unverified.** Phone-format validation and the existing normalized-phone uniqueness index do not prove control of the phone number. Do not use an unverified phone as an account-recovery factor or describe it as a verified emergency/contact route. Add SMS challenge support only after selecting and configuring a real SMS provider and agreeing on its ongoing cost/operational ownership.

## 2. Current source facts

- Step 1 (api/auth/register.php) is validation-only; it does not write an account or query uniqueness.
- Step 2 validates the profile, hashes the password, encrypts the full profile and inserts one row into registration_intake_queue. Its public response is generic.
- RegistrationIntakeService::claimNextJob() claims only pending jobs or processing jobs with expired leases. The worker decrypts, validates and performs email, normalized-phone and National-ID conflict checks before it issues a verification challenge. It claims a second time only after the queue has an email_verified_at timestamp.
- If no conflict exists, the local Phase 17 worker first creates an email challenge and holds the encrypted job in awaiting_verification. Only after confirmation does it insert users, preferences, medical_records, audit and internal notification-outbox rows in one transaction. The new patient receives email_verification_required=1 and a non-null email_verified_at.
- The main local database remains on Phase 16. The Phase 17 migration (including awaiting_verification/expired states and registration_email_challenges) has been applied and verified only on the disposable clone.
- Phase 17 adds email_verification_required and email_verified_at to users on the clone. Existing rows/admin-managed/demo accounts retain the default non-required behavior. The is_active field remains exclusively for patient/doctor archive and deactivation.
- Account creation has several distinct callers: public registration worker, admin-managed patient creation (api/patients/add.php), admin doctor onboarding (api/doctors/add.php), and synthetic demo seeders. Only public self-registration should be automatically forced through the new email proof. Admin-created and seeded demo accounts need an explicit non-required default so existing workflows and quick-demo accounts are not unexpectedly locked.
- Both self-service profile update (api/settings/update.php) and admin patient update (api/patients/update.php) can change email/phone. A future verified flag must be invalidated transactionally when the corresponding contact value changes.
- NotificationService::processOutbox() delivers internal in-app notifications rows only. It is not an email sender and must not be repurposed as one.
- A reusable local mail transport exists in `services/HealthBridgeMailService.php` using PHPMailer 7.2.0 and Gmail SMTP. The user confirmed receipt of the fixed SMTP test message in `llaunchboard@gmail.com`; synthetic verification messages were also accepted by SMTP during clone-backed tests.
- `api/auth/forgot_password.php` sends one-time 60-minute reset links through the shared Gmail transport. The protected local credential has been installed through the masked DPAPI setup flow and the user confirmed the test message arrived. No SMTP secret is stored in source, `.env`, or Git.
- AuthAbuseRateLimiter is a local file/HMAC limiter. Its current registration limits can inform resend limits, but it is not shared across hosts. password_resets must not be reused as a verification-token or signup throttle table.

## 3. Implemented registration state machine

Use the encrypted intake queue as the authoritative pre-account workflow. The proposed states are:

| State | Meaning | Encrypted profile |
|---|---|---|
| pending | Ready for worker, including verified registration awaiting account creation | Present |
| processing | Claimed by one worker under a lease | Present |
| awaiting_verification | Identity checks passed; email challenge issued; owner has not proved control | Present |
| completed | Patient aggregate committed after email proof | Cleared |
| duplicate | Email/phone/National-ID conflict was found | Cleared |
| failed | Retry ceiling or non-recoverable processing error | Cleared |
| expired | Email proof was not completed before retention expiry | Cleared |

Transitions:

1. HTTP Step 2 inserts a normal encrypted pending row.
2. Worker claims, decrypts, re-validates and performs non-locking conflict reads. Unique indexes remain authoritative.
3. If an identity conflict exists, mark duplicate and clear encrypted material; do not send a verification message.
4. If no conflict exists, create a one-time email challenge and change the queue state to awaiting_verification in one transaction. Do not create a users, preferences, medical-record or registration-audit row yet.
5. After the email owner explicitly confirms the challenge, atomically consume the challenge and move the queue row back to pending with available_at=NOW(). The normal worker then claims it and rechecks all uniqueness constraints before creating the full patient aggregate.
6. The patient aggregate, verified-email metadata, audit and internal notification outbox are committed together with queue completion. Ciphertext and authentication material are cleared in the same transaction.
7. Expired verification jobs are marked expired and their encrypted payload is cleared. Retain only bounded, payload-free operational metadata before cleanup.

The uniqueness check must run again when the verified job is finally processed. Another registration may have committed while this job was waiting for email confirmation. A late unique-index collision must roll back the aggregate and terminally classify the job as duplicate.

## 4. Phase 17 additive data model

### Registration queue

Phase 17 adds to registration_intake_queue:

- `email_lookup_hmac BINARY(32) NULL`: keyed digest of normalized email, used only to locate an awaiting-verification job for resend. No plaintext email is stored in the queue.
- `verification_expires_at DATETIME NULL`: expiry for the user-action wait state.
- The index is `(status, key_version, email_lookup_hmac, verification_expires_at)`. The existing versioned queue-encryption key identifier is reused for HKDF derivation, so no separate lookup-key-version column is required.

Extend the enum with awaiting_verification and expired. Do not include awaiting_verification in the worker's normal claim query or in the existing oldest_pending_age_seconds operational-backlog metric. Report it as a separate "awaiting user verification" count/age so user delay is not misreported as a stalled worker.

Derive the lookup-HMAC key from each registration encryption key using HKDF with a dedicated context string such as healthbridge.registration.email-lookup.v1. Do not reuse the raw AES key directly for HMAC. During key rotation, compute lookup digests with all retained key versions when resending; keep old versions until all jobs and challenges tied to them expire or are drained. A new deployment host must receive the same supported key versions before it can serve the resend/worker path.

### Verification challenge table

Phase 17 currently adds only `registration_email_challenges`: internal id, queue_id FK, unique token_hash CHAR(64), created/expires/consumed/revoked timestamps, send_attempt_count, last_send_attempt_at, last_sent_at and last_send_failure_at. An `account_email_challenges` table is not part of this first signup-verification release; it is a future requirement for verified contact changes.

The raw bearer token is returned only in the verification email URL, never stored in the database, application logs, outbox text or QA evidence. Use at least 32 random bytes; store only its SHA-256 hash. One active challenge per queue job/user: resend revokes the previous challenge and inserts a new hash. Consume challenges with a single conditional transaction so parallel clicks cannot verify twice.

### User contact metadata

Phase 17 adds only `email_verification_required TINYINT(1) NOT NULL DEFAULT 0` and `email_verified_at DATETIME NULL` to users. `pending_email` and `phone_verified_at` were intentionally deferred; email-change verification and phone verification require separate challenge flows and migrations.

- Existing accounts remain email_verification_required=0; no mass lockout or false claim that historic email addresses were proved.
- Admin-created users and seeded demo accounts use the default 0, unless a future administrative workflow explicitly requests verification.
- A public self-registration becomes a user only after the queue job has been verified; at aggregate creation the worker sets email_verification_required=1 and copies the verified timestamp from the consumed queue challenge.
- No phone-verification column or SMS challenge is included in Phase 17. Email proof does not imply phone ownership.
- Keep is_active exclusively for account disable/archive state.

The local implementation uses the recommended pre-account state machine. A defense-in-depth login guard also rejects any legacy/manual account explicitly flagged as requiring verification while email_verified_at is NULL.

## 5. Email send/resend workflow

The shared PHPMailer/Gmail SMTP transport is operational locally with sender `llaunchboard@gmail.com`, `smtp.gmail.com:465` implicit TLS, 15-second timeout and generic provider-error handling. The user confirmed receipt of the SMTP test email. The app password is stored outside the repository in a Windows DPAPI-protected file. The registration verification worker calls this shared sender; the end-to-end results are recorded below.

Implemented local flow:

- The registration worker commits the awaiting_verification row and token hash before attempting delivery.
- The raw token exists only in worker memory and is passed to the mail adapter after commit. The email contains only a generic verification link and expiry; no patient number, National ID, medical profile, emergency-contact details or registration conflict result.
- Delivery failure does not roll back the pending encrypted registration or mark it as a completed account. Log only sanitized provider/result categories. The row remains in awaiting_verification and can be resent.
- POST /api/auth/resend-registration-verification.php accepts an email address and rate-limits by source IP plus the keyed email digest. It returns the same success-shaped message regardless of whether there is a pending job, an existing account, a duplicate, an expired job, an already verified request or an unknown address. Internally it selects only the newest eligible awaiting_verification row, revokes the prior challenge and sends a fresh link.
- If the worker crashes after challenge commit but before SMTP delivery, the same resend path creates a new challenge. This avoids storing a reusable raw token for retry.
- Resend attempts use a dedicated limiter category; do not reuse password_resets rows. The existing limiter is file-backed and host-local, so a multi-host deployment remains unsupported until its store is replaced.
- The user-facing Step 2 acknowledgment remains generic. Registration success and login screens both expose a resend path; the login-page action is available even when no patient row exists yet. Do not add a status endpoint that discloses whether an email/identity was found.

## 6. Confirmation-link safety (implemented locally)

- Email link GET must not consume a token. Link scanners and mail-security products prefetch GET URLs. GET should serve a minimal same-origin confirmation screen; the explicit user action submits the token by POST.
- Use a dedicated confirmation page with Referrer-Policy: no-referrer, no third-party assets, no analytics, and a restrictive CSP. Remove the token from browser history after it has been transferred to the confirmation POST flow.
- For a registration challenge, confirmation POST hashes the token, locks the challenge and queue row, checks expiry/state, marks the challenge consumed and transitions the queue row to ready. All checks and state updates happen in one transaction.
- Account-email-change confirmation is not implemented in this release. A future contact-change flow must lock the challenge/user row, check address uniqueness and pending destination, atomically replace the email, set its verified timestamp and revoke old sessions.
- Invalid/expired/reused token responses are generic and disclose no account/email/phone/duplicate details. Rate-limit token attempts by IP and token hash; cap attempts.
- Confirmation does not create the account directly in the HTTP process. The CLI worker performs the final aggregate transaction, preserving one writer and the current failure/replay discipline.

## 7. Contact changes and account lifecycle

Email ownership becomes stale whenever the stored email changes.

- A future contact-change phase must update api/settings/update.php and api/patients/update.php so an email change is stored as pending_email and confirmed through a separate account_email_challenges flow. Do not replace users.email or claim the new address is verified before confirmation. This is not implemented in Phase 17.
- For that future contact-change flow, reject an address that becomes occupied before confirmation without modifying the current email. Do not reserve another user's address while it is only pending.
- A future phone-change workflow must clear any prior phone-proof state; do not use a new number for SMS recovery or claim it is owned until a separate OTP flow proves it. Phase 17 does not add phone verification.
- Any manual administrative override must be a separate, audited action with a reason and a policy owner; do not silently mark an edited address verified.
- The login route verifies the supplied password first. For a correct password on a legacy/manual account flagged as verification-required but lacking email_verified_at, it returns EMAIL_VERIFICATION_REQUIRED. Normal new registrations have no users row at all until email confirmation; the login page also exposes a generic resend action for that pending-registration case. Wrong-password and unknown-account behavior stays on the generic 401 path.
- requireAuth() now checks the verification-required flag and destroys a session if a flagged account has no verified timestamp. A future contact-change flow must increment session_version when a verified address is replaced.

## 8. Phone verification phase

Do not implement SMS by calling mail(), a public web gateway or an unapproved free SMS endpoint. No SMS SDK/provider exists in the repository. Before phone verification is built, choose the provider, budget, sender identity, country/number format policy, quota, delivery-failure behavior and operational owner.

A later phone-OTP flow should use short-lived numeric codes whose stored values are keyed HMACs (not raw code hashes vulnerable to a small search space), strict attempt limits, resend cooldowns, one active challenge per account, and no phone-verified state change until the provider send and user confirmation succeed. Phone ownership must never be inferred from a valid Egyptian mobile format or the normalized-phone uniqueness index.

## 9. Rollout gates and required tests

Do not alter the active route or main database until a fresh disposable clone has the current 27-table baseline and a current full backup/restore proof.

Required clone-backed tests:

1. Migration upgrade and canonical fresh schema: add columns, challenge table, queue states/indexes; preserve all existing account rows/roles/active states and AUTO_INCREMENT; seed demo and admin-managed accounts remain login-capable without verification.
2. Public Step 2: response status/body shape remains generic for all valid attempts; no account/EMR rows exist while state is awaiting_verification; no plaintext email/profile/password hash is stored in queue or challenge columns.
3. Conflict behavior: duplicate email/phone/National ID produces no verification email and clears payload; two concurrent pending jobs for the same email can both wait, but at most one complete patient aggregate commits after proof.
4. Challenge lifecycle: valid token once; expired token; revoked token; repeated token; malformed token; parallel redemption; resend rotates old token; GET scanner does not consume; resend/verify responses do not enumerate accounts.
5. Worker recovery: crash before challenge commit; after challenge commit before email delivery; after user confirmation before account commit; after account commit. Exactly one aggregate, exactly one registration audit bundle, and no duplicate admin notices.
6. Contact updates: email change invalidates prior proof and sessions; phone change clears only phone proof; admin update and self-service update paths both covered; no false verification timestamp.
7. Transport: local test SMTP capture or provider sandbox; successful delivery, TLS/identity failure, timeout, retry/resend, invalid recipient and provider outage. No real email containing PHI.
8. Abuse controls: per-IP/email-HMAC resend limits, token-attempt caps, one active challenge, and no rate-limit leakage in responses.
9. Cleanup/retention: expired jobs clear nonce/tag/ciphertext; challenge tokens are payload-free and retained only for a bounded audit window; no expired pending profile can be recreated.
10. Existing login, password reset, account archive/deactivation and registration-crash/replay suites remain green.

## 10. Current status, remaining gates and rollout boundary

**Implemented and verified on a disposable Phase 17 clone (14 November 2026):**
- Public registration Step 1 and Step 2 HTTP path. Step 1 created no queue row; Step 2 returned the generic HTTP 200 verification-first acknowledgment and created an encrypted pending row with a 32-byte email lookup HMAC. The worker moved it to awaiting_verification and Gmail accepted the message; no patient row existed before confirmation.
- Confirmation page GET returned HTTP 200 without changing state. Explicit confirmation POST succeeded; replay returned HTTP 400. After the worker ran, exactly one patient and one preferences row, medical record, registration audit row and notification outbox event existed. The account had email_verification_required=1 and email_verified_at; the challenge was consumed and terminal encrypted payload/HMAC were cleared.
- Login API: correct password on an unverified flagged account returned HTTP 403 / EMAIL_VERIFICATION_REQUIRED and did not create a session (current_user returned HTTP 401). The same synthetic account logged in after the verified timestamp was restored; current_user returned the matching patient identity and the session cookie was HttpOnly/SameSite=Lax.
- Resend: eligible and unknown addresses returned identical HTTP 200 response bodies; three attempts were accepted and the fourth returned HTTP 429. Each resend revoked the earlier challenge and the latest challenge retained the original 24-hour deadline.
- Expiry: unverified jobs were changed to expired by the worker; payload, nonce, tag, HMAC and deadline were cleared; challenges were revoked and no patient row was created. Extra synthetic API registrations were also expired and cleaned.
- The base source backup was restored into a disposable clone. The clone matched all 27 base table row counts and AUTO_INCREMENT counters; generated phone_normalized values matched; mysqlcheck passed 27/27.
- Final verification after all clone tests: the main local healthbridge DB still matches the saved 27-table row-count baseline exactly (164 users, 14 queue rows) and has zero Phase 17 user columns. Phase 17 has NOT been applied to main.

**Still open / not covered by this first release:**
- Concurrent duplicate-registration/verification stress and every worker crash boundary across challenge commit, SMTP send and post-confirmation account commit need dedicated additional tests.
- Changing a patient's email in self-service/admin profile flows does not yet use a separate pending-email challenge. This implementation covers new public self-registration only.
- Phone ownership proof is not implemented; no SMS provider was selected. A valid phone format/unique normalized phone is not proof of control.
- The file-backed rate limiter and local worker/key setup remain single-host. Shared multi-host coordination, trusted-proxy configuration and hosted SMTP secret provisioning are not accepted by these clone tests.
- The currently deployed Alwaysdata staging copy and the main local database have not received Phase 17 code/schema. Hosted deployment is not part of this task.

**Activation gate:** Take a fresh current full backup, restore and compare it in a newly named disposable database, then obtain explicit approval before applying Phase 17 to the main local `healthbridge` database. Do not claim the main application enforces verification until that migration is applied and a main-local registration/login acceptance passes.
