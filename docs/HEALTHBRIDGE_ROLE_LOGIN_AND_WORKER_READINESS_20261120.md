# HealthBridge V2 role-login and CLI worker readiness

**Date:** 20 November 2026  
**Scope:** Local Apache canonical V2 role/session acceptance and local scheduled worker disposition.

## Current outcome

**The canonical V2 admin/doctor/patient role-login matrix passed. HealthBridge is ready to begin Milestone 3 (the frontend/UI/UX overhaul).** This is a milestone-entry decision, not a production/deployment approval. Hosted release gates remain open, and both operational worker schedules remain disabled pending separate V2 worker acceptance.

## Canonical V2 environment

- Local site: http://127.0.0.1/optimized/
- Apache database: healthbridge_v2, selected by the local-only root .htaccess V2 selector block.
- Browser: independent BrowserAct Chrome profile chrome_local_128984470708551920. Session healthbridge-v2-role-qa was closed after testing.
- Test identities: admin@healthbridge.com, ahmed.hassan@healthbridge.com and patient@healthbridge.com. All three were confirmed active in healthbridge_v2; email verification was not required.

## Role-login acceptance — PASS

All three demo accounts completed the UI quick-login path. For each session, current_user.php returned HTTP 200 with the expected id/role, the expected own-role read endpoint returned HTTP 200, the other-role endpoint checks returned HTTP 403, and logout invalidated the session so a subsequent current_user.php returned HTTP 401.

| Role | Identity | Own-role read | Cross-role denials | Logout / invalidation |
|---|---|---|---|---|
| Admin | admin@healthbridge.com (id 1) | GET api/admin/dashboard-analytics.php → 200 | POST api/patient/dashboard.php → 403 | logout → 401 |
| Doctor | ahmed.hassan@healthbridge.com (id 3) | GET api/doctors/get-availability.php → 200 | GET api/admin/dashboard-analytics.php → 403; POST api/patient/dashboard.php → 403 | logout → 401 |
| Patient | patient@healthbridge.com (id 2) | POST api/patient/dashboard.php → 200 | GET api/admin/dashboard-analytics.php → 403; GET api/doctors/get-availability.php → 403 | logout → 401 |

Additional observations:
- Admin dashboard loaded V2 metrics; doctor dashboard loaded the doctor workspace and demo appointment summary; patient dashboard loaded the patient portal.
- Logout invalidation was verified by a fresh current_user.php request after each role. For doctor and patient, BrowserAct's indexed click did not clear the session on the first attempt; the identified logout controls were then activated through their DOM click handlers, and current_user.php returned 401. The server-side logout path and session invalidation passed.
- Three successful demo logins were made. No incorrect credentials were submitted. No business records, appointments, clinical records, queue rows, email delivery or worker execution were changed or triggered by this matrix.

## CLI workers — deliberately deferred

The CLI runtime selects healthbridge (V1), while Apache uses healthbridge_v2. The registration CLI entrypoint explicitly constructs RegistrationIntakeService::SCHEMA_V1; the notification worker inherits the CLI getDB() target without a V2 target guard. Changing DB_NAME alone is not a safe cutover.

Both Windows scheduled tasks remain Disabled:
- HealthBridge Registration Intake Worker (previous one-minute repetition).
- HealthBridge Notification Outbox Worker (previous five-minute repetition).

Their observed automatic runs before pause reported zero jobs claimed, zero verification emails sent, zero notification deliveries and zero pending work. The notification service has a 30-day delivered/skipped-row retention delete; its potential effect during scheduled auto-runs was not separately fingerprinted. No worker was manually launched and no SMTP delivery was initiated in this review.

Do not re-enable either schedule until V2-specific worker entrypoints, target/schema guards, keyring handling, retry/retention behavior and separately approved email delivery are tested.

## Milestone decision

- **Milestone 3 UI/UX:** Ready to begin. The canonical V2 role-login/session gate is now passed.
- **Operational workers/email:** Deferred and paused; not a blocker for starting the UI/UX redesign, but still a separate operational-readiness gate.
- **Hosted deployment/production:** Not approved by this record. Previously accepted hosted P0*/P1 items remain OPEN — HIGH PRIORITY and must be revisited as deployment release gates.

## Safety and change record

No .env contents or values were displayed. No V1/V2 business data was changed by the role matrix. No worker was launched, no SMTP delivery was triggered, and no hosted resource was inspected or changed. No Git stage, commit, push, reset, stash, branch switch or deployment occurred. The local-only .htaccess V2 selector block must not be deployed.