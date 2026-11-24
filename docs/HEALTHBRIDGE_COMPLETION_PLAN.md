> **Superseded — 24 November 2026.** The completion and refactoring plan in this document has been carried out: the V2 build (public site, patient, doctor and admin dashboards) is complete and live. Kept as a historical record of the V1 → V2 build. For the current system, see `README.md` and `docs/ARCHITECTURE.md`.

# HealthBridge Ã¢â‚¬â€ Completion and Refactoring Plan

**Created:** 11 November 2026  
**Project:** `C:\xampp85\htdocs\optimized`  
**Branch at planning:** `repair/healthbridge-staged-hardening`  
**Baseline HEAD at planning:** `d4dd731b7578d9309d8eca50ec57bb319459dade`  
**Status:** Milestones 1-2 complete; Milestone 2 formally closed for local scope on 20 November 2026. Canonical V2 admin/doctor/patient role-login acceptance passed; Milestone 3 started on 20 November 2026 and M3.0 discovery/baseline is complete. Hosted/external P0*/P1 findings remain open, high priority and deployment release gates; Milestone 4 remains queued.

## Purpose

This plan changes the working mode from continuous discovery to controlled completion. The goal is to finish the approved maintenance/refactoring effort, preserve existing product behavior, and produce a defensible local completion handoff without expanding the product or waiting for every hosted-only dependency to become available.

The project is not being rewritten. Previously verified work is accepted as evidence for the source/version it tested and must not be repeated without a new source change, failure, or clearly identified coverage gap.

## Product goal and boundary - portfolio demonstration, not a real hospital

HealthBridge's end goal is a **secure, polished, believable hospital-management demonstration for a developer portfolio**. It is not intended to become a complete hospital information system or to operate a real hospital. The project should demonstrate professional engineering, coherent role-based workflows, thoughtful UI/UX, maintainable PHP/MySQL architecture, and defensible security practices within a deliberately limited demo scope.

That boundary is intentional. A real hospital platform would require a much larger team, clinical governance, operational support, regulatory/legal review, formal privacy and security assessments, disaster recovery, service-level commitments, and integrations that are outside this two-person portfolio project. We will not chase an unrealistic promise that every hospital workflow is present or perfect.

**Security remains a core requirement even though this is a demo.** We will close material vulnerabilities in the implemented scope, protect demo accounts and synthetic records, preserve least-privilege role boundaries, validate and safely render untrusted input, protect sessions and state-changing requests, use safe database access and transactions, avoid leaking secrets or sensitive data, and document remaining limits. Do not use real patient data, real clinical decisions, or this project for actual care or hospital operations. Do not claim production readiness, healthcare regulatory compliance, or certification. Portfolio presentation and README/demo messaging must clearly state the demonstration-only purpose and use synthetic data.

The target is a coherent and high-quality slice of an application - not an exhaustive catalogue of every department, specialty, workflow, integration, or enterprise feature a real hospital might need. A feature is not required merely because a real hospital system could have it.

## Rules that control the finish

1. **No feature expansion.** Work is limited to defects, important workflow acceptance, security/reliability, maintainability, evidence-backed cleanup and release preparation.
2. **One milestone at a time.** Complete and record its exit criteria before broadening the work.
3. **Reuse existing evidence.** Do not rerun passed tests just to increase test counts. First check the QA Evidence Index and current source changes.
4. **Protect data.** Mutating tests normally use a fresh or restored disposable synthetic clone. Explicit HB-AUTH-001 exception: the user authorized the fictional local `healthbridge` database for this integration and its final clean/reseed only after taking a fresh full backup and successfully verifying a restore into a new disposable database. Do not mutate `healthbridge_repair_test`, `healthbridge_restore_test`, `healthbridge_restore_rehearsal_20261111`, `nova_home`, or any hosted database.
5. **No secrets.** Do not inspect, display or copy `.env` values or QA credentials. Credentials stay in the ACL-restricted file outside the webroot.
6. **No distracting processes.** Any required scheduled/background task, PHP/PowerShell orchestration or test server must run fully hidden and must never steal keyboard focus or interrupt the active app. For scheduled tasks, use a `wscript.exe //B //NoLogo` launcher, start child PowerShell with `-WindowStyle Hidden`, and set Task Scheduler `Hidden=true`; preserve the required execution identity/context. Stop only the exact process started and verify its port is closed afterward.
7. **Preserve the worktree.** It contains substantial uncommitted changes. Do not reset, clean, stash, switch branches or overwrite unrelated changes. The user's current authorization permits commits/pushes only to `origin/repair/healthbridge-staged-hardening`; never stage unrelated work, touch/merge/push `main`, or deploy.
8. **Evidence before refactoring.** Trace page Ã¢â€ â€™ JavaScript Ã¢â€ â€™ API Ã¢â€ â€™ service Ã¢â€ â€™ tables and establish regression coverage before splitting a large module or removing a candidate.
9. **Stop-rule for new findings.** Add work to this plan only for a reproducible P0/P1 security or data-integrity issue, a core workflow blocker, or a defect that invalidates an exit criterion. Lower-priority polish and speculative improvements go to the deferred list.

### Three controls that prevent open-ended testing

1. **Freeze the scope.** Every remaining task must map to an existing Milestone 2 finding or a defect that blocks an agreed exit criterion. Do not add features, test unrelated modules, or expand the milestone for speculative improvements. Record lower-priority items for later rather than pulling them into the active queue.
2. **Reuse evidence before testing.** Check the Findings Register and QA Evidence Index, then compare the tested source/version with the current worktree. Accept valid existing evidence. Rerun only when relevant code, data rules, security policy, environment assumptions, a previous failure, or a clearly identified coverage gap makes the old evidence insufficient.
3. **Define the stop condition before each test.** State the specific risk, the evidence already available, the one missing behavior to verify, and the pass/fail outcome that closes the gap. Once that outcome is met and no material defect remains, stop testing that area. Do not add cases just to increase test counts.

For every proposed test, the work note must answer: **What risk does this address? What evidence already exists? What exact result lets us stop?** If those questions do not have concrete answers, do not run the test.

## Milestone overview

| Milestone | Status | Outcome |
|---|---|---|
| 1. Critical role workflows | **Complete (11 Nov 2026)** | Admin Patient EMR and doctor-owned clinical-history/prescription journeys passed actual-interface acceptance with synthetic persisted state and role boundaries. |
| 2. Local security and reliability closure | **Closed for local scope (20 Nov 2026)** | Local exit criteria met; project owner accepted deferral of hosted/external P0*/P1 findings to final deployment readiness. Those findings remain open, high priority and release gates. |
| 3. Architecture, full UI/UX refactor and cleanup | **In progress — three public/auth page groups integrated and regression passed (20 Nov 2026)** | The approved strategy is a progressive page-by-page presentation-layer rebuild using PHP-rendered pages and vanilla JS/CSS—not a wholesale legacy refactor or full application rewrite. Five reference prototypes passed 62 automated axe scans. The provisional shared foundation remains open for owner review. Public Doctors Directory passes 19 interactions/10 zero-violation axe scans, 8 responsive/theme checks and three GET APIs HTTP 200. Homepage/public entry passes 16 interactions, 8 zero-violation axe scans, no overflow and 9 live department cards. Login/register/reset/verify passes 27 interactions, 44 zero-violation axe scans across four routes, and no overflow. No POST/booking/auth mutation was made. Auth/home page CSS/JS consolidation and Sign Up route repair are documented in HEALTHBRIDGE_MILESTONE_3_AUTH_PUBLIC_ENTRY_RESULT_20261120.md; Directory and architecture evidence remain in their dated reports. Backend/API/auth/role/data contracts remain protected. Worker schedules remain disabled/deferred; this is not production approval. See docs/HEALTHBRIDGE_MILESTONE_3_FRONTEND_MIGRATION_STRATEGY_20261120.md. |
| 4. Final regression and freeze | Queued | Run the agreed final suite, verify setup/upgrade/restore evidence, reconcile findings and produce the final local engineering handoff. |

---

### Milestone 3 entry-gate result — 20 November 2026

The final canonical V2 role/session gate has passed against the local Apache application using the independent BrowserAct Chrome profile. Admin, doctor and patient quick-login sessions returned the correct identity and role; own-role dashboard/read endpoints returned HTTP 200; all five cross-role checks returned HTTP 403; logout invalidation returned HTTP 401 for each role. Full evidence is in `docs/HEALTHBRIDGE_ROLE_LOGIN_AND_WORKER_READINESS_20261120.md` and the QA Evidence Index.

Milestone 3 began on 20 November 2026. **M3.0 discovery and baseline are complete:** the 23-page inventory, shared frontend structure, external dependencies, primary-role responsive/axe scan, public/auth E2E baseline, homepage Lighthouse desktop/mobile measurements and framework decision are documented in `docs/HEALTHBRIDGE_MILESTONE_3_AUDIT_20261120.md`; the UX and visual direction are in the M3.1/M3.2 documents. Five M3.3 prototypes are technically validated (62 zero-violation axe scans); manual keyboard/assistive-technology review and owner token approval remain open. The approved strategy is a progressive presentation-layer rebuild page by page, retaining PHP-rendered pages and vanilla JS/CSS, preserving backend/API/auth/role/data/business contracts, replacing superseded page-specific code, and retiring dead CSS/JS only after dependency mapping and regression. Details: `docs/HEALTHBRIDGE_MILESTONE_3_FRONTEND_MIGRATION_STRATEGY_20261120.md`. M3.4 remains a provisional live-site style foundation. M3.5 has three automated local page groups closed: Public Doctors Directory (19 interactions, 10 zero-violation axe scans, 32 cards and three GET APIs HTTP 200); Homepage/public entry (16 interactions, 8 zero-violation scans, 9 department cards, 8/8 layouts no overflow); and login/register/reset/verify (27 interactions, 44 zero-violation scans across four HTTP 200 routes, 32/32 layouts no overflow). Both new audit sets have zero browser page exceptions, zero non-CSP console errors and zero POST requests. Auth/home CSS and JS were consolidated, the mock clinic/emergency/insurance/testimonial claims removed, the 9 stale login.html#signup references repaired and 22 HTML consumers of main.js cache-bumped with verified backups. Full result: `docs/HEALTHBRIDGE_MILESTONE_3_AUTH_PUBLIC_ENTRY_RESULT_20261120.md`; Directory result: `C:\\xampp85\\migration-backups\\healthbridge\\milestone3-directory-20261120\\DOCTORS_PAGE_RESULT.md`; architecture review: `docs/HEALTHBRIDGE_MILESTONE_3_FRONTEND_ARCHITECTURE_REVIEW_20261120.md`. Next: review and migrate remaining public informational pages (About, Services, Contact), then Patient portal. CLI workers remain V1-targeted and both Windows schedules Disabled; worker reimplementation/acceptance and external email validation are separate deferred operations gates. Hosted P0*/P1 and deployment checks remain open high-priority release gates. This does not mean production-ready.

---

## Milestone 1 Ã¢â‚¬â€ Critical role workflows

**Status:** Complete Ã¢â‚¬â€ 11 November 2026  
**Verified scope:** Admin Patient EMR lifecycle and doctor-owned clinical-history/prescription lifecycle.  
**Why first:** These paths exercise account data, medical records, audit history, clinical encounter ownership and prescription lifecycle. They are the most consequential missing end-to-end evidence after the earlier appointment-management acceptance.

### Role and data ownership

- **Admin Patient EMR:** administers patient demographics/contact details, account status, administrative corrections, password reset, and appointment administration; reviews clinical information, visit notes, prescriptions, notifications and audit history.
- **Doctor workspace:** owns clinical-history updates, visit notes, and prescription creation/edit/completion/cancellation within a qualifying patient encounter.
- **Patient portal:** owns patient self-service/profile and patient-visible views; it must never gain access to another patient's data.
- Admin access to clinical history and prescriptions remains review-only unless a separately documented business rule already authorizes a specific administrative correction. Do not add Admin powers over diagnosis, treatment or prescription lifecycle for test convenience.

### Work package 1A Ã¢â‚¬â€ Admin Patient EMR

Active page/controller:
- `pages/patient/patient-emr.html` (Admin role-gated)
- `assets/js/pages/patient-emr.js`
- shared `assets/js/modules/medical-records.js` and `assets/js/modules/prescriptions.js`

Verify through the actual browser UI:

1. **Open and read EMR:** Admin navigation from the patient list to a valid patient ID; verify identity, overview, medical-record sections, visit notes, prescriptions, appointments, notifications and audit history load without page errors or cross-patient leakage.
2. **Patient profile edit:** update synthetic name/email/phone and demographic fields; verify persistence and fresh readback. Verify invalid/malformed profile values fail without partial writes.
3. **Account status:** exercise disable and re-enable through the active UI. Verify session-version invalidation, correct status on refresh, audit/outbox event behavior, and the established clinical-continuity rule (retain clinical records; do not cancel started/active clinical work).
4. **Password reset:** exercise the actual reset modal, matching and mismatching confirmation, password-strength rejection, successful synthetic reset, old-session revocation and successful authentication with the new synthetic password. Restore the original synthetic credential/fixture after the test.
5. **Administrative medical-record correction:** change only Admin-owned fields (blood type/emergency contact) with a required reason; verify updated values and the corresponding medical-record audit. Verify missing reason and no-op changes do not create misleading success.
6. **Archive path:** verify the existing archive confirmation and retention behavior on a disposable patient fixture only. The patient account must become unavailable for sign-in, untouched bookings follow the documented policy, and clinical/audit history remains retained. Do not hard-delete a patient or clinical history.
7. **Read-only policy:** verify the Admin EMR presents doctor-owned clinical fields and prescriptions as view-only and does not expose doctor-only prescription mutations.

### Work package 1B Ã¢â‚¬â€ Doctor clinical history and prescription lifecycle

Active page/controller:
- `pages/doctor/doctor-workspace.html`
- `assets/js/pages/doctor-workspace.js`
- `assets/js/modules/medical-records.js`
- `assets/js/modules/prescriptions.js`

Verify through the real doctor UI, using a qualifying synthetic patient/appointment:

1. Add a medical-history update and verify it is associated with the correct patient/doctor/encounter and appears in a fresh workspace/EMR read.
2. Create a prescription and verify header/items/doctor/patient/appointment ownership.
3. Edit an eligible active prescription and verify changed items/notes persist without duplication or loss.
4. Complete an eligible prescription and verify it becomes read-only.
5. Cancel a separate eligible prescription with a required reason and verify status, audit and notification/outbox behavior.
6. Verify completed/cancelled prescriptions cannot be edited or transitioned again, and cross-patient/cross-doctor IDs are rejected.
7. Confirm the Admin Patient EMR can review the resulting clinical history/prescriptions without obtaining doctor-only write controls.

### Existing evidence to reuse

- `HB-UI-001`: Admin appointment decisions are resolved locally; actual Admin buttons passed 2/2.
- `HB-API-006`: password-reset backend contract and the actual Admin EMR password-reset journey passed, including old-session revocation and new-password login.
- `HB-API-021`: strict patient-profile input/role contract passed 36/36; Admin browser profile edits and persistence/readback passed.
- `HB-API-008`: clinical-write input/authorization/workflow regression passed 37/37; doctor browser history update and corresponding history-event persistence passed.
- `HB-API-020`: prescription input/role/ownership regression passed 54/54; doctor browser create/edit/complete and separate reason-required cancellation journeys passed.
- `HB-DB-005` / `HB-DB-006`: account/clinical-preservation and prescription lifecycle/lock-order evidence reused; no duplicate execution was needed.
- The existing doctor encounter browser journey was extended only for the uncovered history-update and prescription-edit branches; a focused separate test covers cancellation.

### Milestone 1 exit criteria

- Actual Admin Patient EMR read-only review, profile, account-status, password-reset, administrative correction and archive/retention paths passed focused browser acceptance.
- Archive acceptance verified deactivation, cancellation of the one untouched synthetic appointment, retained EMR and audit history, stale-session rejection (403) and fresh-login rejection (401).
- Doctor clinical-history update/history-event persistence and prescription create/edit/complete/cancel passed real-browser and persisted-state verification.
- Admin read-only review and the doctor-only mutation boundary were verified.
- Every mutating workflow used the disposable clone with a restore/reseed after the case; the final 26-table row/checksum/schema/AUTO_INCREMENT comparison and mysqlcheck 26/26 passed.
- The focused test journeys reported no uncaught browser errors or blocked non-allowlisted writes.
- Milestone evidence and handoff are updated. Milestone 1 is closed; Milestone 2 has now started.

---

## Milestone 2 Ã¢â‚¬â€ Local security and reliability closure

**Status:** **CLOSED for local scope on 20 November 2026** after project-owner acceptance of documented external deferrals. Hosted/external P0*/P1 findings remain open and high priority for deployment readiness.
**Purpose:** Close the remaining locally addressable risks without treating hosted-only limitations as local code defects.

**Scope boundary:** Milestone 2 is local-only. Do not inspect or change Alwaysdata or other hosted resources, run hosted deployment checks, or attempt multi-node/remote validation during this milestone. Hosted, TLS, production SMTP/keyring, proxy and multi-node concerns are deferred to a later release/deployment stage. Passing local tests is valuable evidence but is not a guarantee of deployment success or production readiness.

**Current first priority:** HB-AUTH-001 remains the highest-priority local P1, but the integrated asynchronous Step 2 timing-characterization gate is now closed for the local single-host path. On 14 Nov, 40 new and 40 duplicate requests returned HTTP 200 with one identical response signature; medians were 349.918 ms and 349.755 ms (delta +0.164 ms; bootstrap 95% interval -1.665 to +0.940 ms; MannÃ¢â‚¬â€œWhitney p=0.468; Cliff's delta -0.095). The worker completed 40 new accounts, classified 40 duplicates and delivered 40 notices; duplicate-unused identifiers did not persist, clone mysqlcheck passed 27/27, and the main source dump/row-count/AUTO_INCREMENT manifests were unchanged. Evidence: C:\xampp85\migration-backups\healthbridge\hb-auth-001-integrated-timing-20261114\RESULT.md. This is local loopback evidence, not proof of remote-network or hosted timing resistance. Local SMTP receipt is now confirmed for redesigned verification and password-reset emails plus one synthetic OPEN queue-age alert (15 Nov 2026). On 17 Nov 2026, a synthetic stale-to-recovered email lifecycle passed through an isolated copy of the real registration worker wrapper with fake PHP worker statistics and the real queue-age helper/CLI SMTP sender: OPEN at 121 seconds and RESOLVED at healthy zero both recorded EMAIL_SENT=1 and EMAIL_DELIVERY=SUCCESS; wrapper exit codes were 2 then 0; marker remained open until successful RESOLVED delivery, then was removed. 17 assertions passed. No DB was connected and the production scheduled task was not invoked or changed. Evidence: `hb-auth-001-queue-age-live-lifecycle-20261117/RESULT.md`. SMTP acceptance was confirmed, but inbox arrival was not independently verified through the mailbox connector. Remaining gates: real scheduled-task observation of a genuinely stale database queue and operator ownership/acknowledgement; email/phone ownership scope after provider/policy decisions; hosted HTTPS APP_BASE_URL/SMTP/keyring/worker readiness; and shared limiter storage, trusted-proxy handling and cross-node worker concurrency before multi-host deployment.

### Workstreams

1. **Output/URL security:** hostile field-by-field browser coverage for appointment print, prescription print and patient EMR print passed 3/3 on 11 Nov 2026 using a disposable synthetic clone. Scoped wrapping fixes also brought all three pages within the 390px viewport. A bounded census covered 35 first-party JavaScript files (99 location/open/href/src matches) and server-rendered URL attributes; the optional requireServerRole() redirect parameter is now same-origin validated through safeSameOriginUrl(), with 12/12 URL cases plus external/internal/default redirect scenarios passing in Chromium. On 15 Nov 2026, the Admin Departments table/search received dedicated hostile-data browser acceptance. The test reproduced a malformed-API-field search crash, then passed after a local string-type guard was added without changing the shared filter helper; escaped output, malformed-ID/status/count fallbacks, edit-field literal handling and 390px layout also passed. Evidence: `hb-sec-010-admin-departments-20261115/RESULT.md`. The Patient Document Center URL allowlist then passed expanded hostile URL browser acceptance on 15 Nov: only three expected canonical same-origin print links rendered; duplicate IDs, extra parameters, fragments, non-canonical IDs, encoded path separators, alternate endpoints and unsupported/duplicate appointment types were rejected. Evidence: `hb-sec-010-download-url-edge-20261115/RESULT.md`. Additional 15 Nov browser acceptance: Admin Analytics hostile-data rendering passed against the actual Admin HTML/CSS with malformed collections, scalar types and HTML-looking text; zero injected nodes/event attributes/XSS markers/page errors/API requests and 390px passed. Evidence: `hb-sec-010-admin-analytics-hostile-20261115/RESULT.md`. Services role-based booking links passed six scenarios: patient/doctor/admin and malformed string/object/array roles all mapped to fixed same-origin dashboard routes; zero API calls/errors/XSS markers and 390px passed. Evidence: `hb-sec-010-services-role-url-20261115/RESULT.md`. The Forgot Password UI test then reproduced an object-valued API `reset_url` becoming an active `/pages/auth/[object%20Object]` link. `safeSameOriginUrl()` now rejects non-string input before parsing; the 14-case browser matrix passed (3 valid same-origin URLs accepted, 11 external/executable/malformed inputs rejected) and the shared helper regression passed 54/54. Evidence: `hb-sec-010-reset-url-type-guard-20261115/RESULT.md`. A Patient Dashboard health-alert test reproduced malformed object/array/numeric `action.url` fields becoming clickable same-origin paths because the caller coerced them to strings before validation. `assets/js/pages/patient/dashboard.js` now accepts only string URLs before trimming and passing through the shared validator. Six hostile browser scenarios passed: the valid internal URL remained clickable; object/array/number/javascript/external URLs did not. Hostile alert output remained escaped with zero injected nodes/event attributes/XSS markers/page errors. Evidence: `hb-sec-010-patient-alert-action-url-20261115/RESULT.md`. The 390px test recorded a separate page/alerts horizontal-scroll diagnostic; full Patient Dashboard responsive clearance is not claimed. The shared `assets/js/modules/departments-loader.js` dropdown then passed focused hostile-data browser acceptance on 15 Nov 2026: malformed records were filtered; HTML-looking IMG/SVG labels remained literal option text/value; no injected nodes/event attributes/XSS markers, page errors or external requests; empty-option and API-failure fallback behavior passed. The API was mocked; zero PHP handlers/database writes; no source change was needed. Evidence: `hb-sec-010-departments-loader-hostile-20261115/RESULT.md`. Do not repeat this helper test unless the source changes. The shared toast-message and table empty-state paths in `assets/js/core/main.js` then passed a focused hostile-output Chromium test on 15 Nov 2026: seven toast payloads (IMG/SVG/script/attribute-breakout strings, special characters, object and null) remained inert text; the empty-state message remained escaped; zero injected nodes/event attributes/XSS markers/page errors/network requests; no PHP/database access or source change. Evidence: `hb-sec-010-shared-output-helpers-hostile-20261115/RESULT.md`. Do not repeat unless the shared helper implementation changes. The shared navbar auth fragment in `core/main.js` then passed hostile-name/role-route browser acceptance on 15 Nov 2026: five synthetic localStorage scenarios kept hostile names literal and mapped admin/doctor/unknown/malformed roles only to fixed same-origin dashboard destinations; anonymous Login/Sign Up links also remained fixed. Zero injected nodes/events/XSS markers/page errors/unexpected requests; no PHP/API/database access or source changes. Evidence: `hb-sec-010-navbar-hostile-20261115/RESULT.md`. Do not repeat unless `initNavbar()` or `getDashboardUrl()` changes. The actual Doctor Directory `bookFromDoctorModal()` redirect paths then passed hostile query-encoding acceptance on 15 Nov 2026: guest return target remained the fixed same-origin login route with exactly one redirect parameter; patient booking remained the fixed same-origin dashboard `#book` fragment with exact doctor/department round-tripping; doctor/admin stayed on the directory with a fixed restriction notice. Hostile ampersands/question marks/hashes/quotes/HTML-looking values created no extra parameters; zero XSS markers/API or external requests/page errors. APIs were mocked in-page; no PHP/DB or source changes. Evidence: `hb-sec-010-doctor-directory-booking-redirect-20261115/RESULT.md`. Do not repeat unless these redirect paths/encoding change. Continue the remaining high-value low-traffic output/URL sinks under `HB-SEC-010`; retain the current CSP/header and role/ownership evidence and do not claim application-wide XSS clearance from partial coverage.
2. **Registration/authentication:** the two-step browser registration and persistence checks for `HB-AUTH-001` are complete on a disposable clone; the integrated asynchronous endpoint timing gate closed on 14 Nov 2026 after a randomized 40-new/40-duplicate loopback run: medians 349.918 ms vs 349.755 ms (delta +0.164 ms; bootstrap 95% interval -1.665 to +0.940 ms; MannÃ¢â‚¬â€œWhitney p=0.468; identical HTTP 200/body signature). No statistically detectable difference was observed locally; the 13 Nov +15.005 ms differential is historical pre-integration evidence and is superseded for the current endpoint. Do not infer remote/hosted timing resistance. Full result: hb-auth-001-integrated-timing-20261114/RESULT.md. Track email/phone ownership proof and shared multi-host limiter storage as deployment capabilities, not silently assumed features. The 14 Nov source review found registration and notification claims use InnoDB row locks/transactions, so multiple workers can coordinate through one shared primary DB, but no cross-node test has been run; each registration node also needs the same compatible queue keyring. The file-backed HMAC limiter is single-host and proxy-derived `REMOTE_ADDR` handling must be verified. P2 `HB-AUTH-006` is now resolved locally: under the claim row lock, an expired processing job at the five-attempt ceiling is marked failed and its encrypted payload, lease and worker token are cleared. Terminalizations increment the failed statistic and consume the worker batch limit. Five repeated post-claim hard crashes passed on a fresh 27-table clone; no sixth claim occurred, terminal state was safe, replay did nothing, no patient aggregate was written, and source DB dump/rows/AUTO_INCREMENT remained unchanged. Evidence: `C:\\xampp85\\migration-backups\\healthbridge\\hb-auth-006-crash-retry-cap-20261114\\RESULT.md`. Multi-host concurrency and limiter deployment gates remain separate and open. `HB-AUTH-003` local comparative login timing characterization passed on 15 Nov 2026 against the actual login route and a disposable clone: 25 wrong-password existing-account requests versus 25 nonexistent-account requests, randomized/interleaved; all 50 responses were byte-identical generic HTTP 401, with no 429 or transport failures. Medians were 367.735 ms and 368.226 ms (existing minus nonexistent delta -0.491 ms; bootstrap 95% CI [-10.434, 17.093] ms). The interval spans zero; this local sample did not resolve a timing difference, but it does not prove exact equality or remote behavior. Clone cleanup, mysqlcheck 26/26 and protected-source normalized dump comparison passed; clone dropped. Evidence: `hb-auth-003-login-timing-20261115/RESULT.md`. Do not repeat this timing suite unless the login implementation/hash policy changes or a concrete regression is found.
3. **Sessions and role boundaries:** finish only the remaining local current-role, active-account and stale-session lifecycle cases not already covered by existing 41/41 and 18/18 route/lifecycle evidence.
4. **Notification reliability:** reconcile `HB-REL-001` producers and the worker's actual current configuration. Runtime support-reply, self-service profile-update, admin-managed patient account-status, admin-managed patient password-reset, clinical visit-note, admin medical-record update, admin doctor-onboarding, doctor-rating, admin doctor-deactivation, standalone admin patient-status-toggle, admin doctor-archive, patient self-booking, admin-assisted appointment booking, admin appointment approval/decline, doctor appointment confirmation/cancellation, patient appointment cancellation, patient reschedule-request, doctor reschedule-suggestion, patient suggestion-acceptance/decline/cancellation, and doctor reschedule-approval/rejection producer failure injection and positive-delivery controls each pass 2/2 on disposable clones. Outbox insert failures rolled back the corresponding contact reply/profile-and-audit/account-status-and-session-version/password-hash-and-session-version/clinical-note-and-audit/medical-record-and-audit/doctor-identity-profile-preferences-and-audit transactions; the doctor-rating failure rolled back the rating/aggregate and its positive control delivered exactly two notices (doctor and admin), with duplicate submission rejected. The doctor-deactivation failure also rolled back the doctor/session version and untouched appointment; its positive control revoked the doctor session, cancelled one untouched booking, wrote one audit and delivered patient plus doctor notices. Source checksum matched and source mysqlcheck passed 26/26; the doctor-deactivation clone mysqlcheck result was not captured and is not counted as verified. Patient reschedule-request failure restored the appointment and audit/outbox/notification snapshots; its positive control delivered three notices (doctor, admin, patient), wrote one patient audit row, and its duplicate request was rejected without duplicates. A second synthetic clone then covered the remaining six RescheduleService branches (doctor suggestion, patient acceptance, patient decline, patient cancellation, doctor approval and doctor rejection): every failure rolled back exact appointment/audit/outbox/notification snapshots, every positive control applied the expected state/audit changes and delivered the notices to the expected recipients, and duplicate acceptance/decline did not duplicate rows. The seven RescheduleService transaction paths are now runtime-covered. Both reschedule clones passed mysqlcheck 26/26 with source checksums matching. A standalone admin patient-status-toggle endpoint also passed injected rollback and positive-control checks: the positive control incremented the synthetic patient session version, wrote one audit event and delivered one account-status notice. The dedicated admin doctor-archive endpoint likewise passed: failure rolled back the doctor/status/appointment/audit state; success cancelled only one synthetic untouched booking, preserved the completed encounter and doctor profile, wrote one archive audit and delivered one patient cancellation notice. Both clones passed mysqlcheck 26/26 and source checksums matched. Five additional appointment lifecycle route mutations were then exercised with injected failure and success controls: admin approval/decline, doctor confirmation/cancellation and patient cancellation. Each failed request rolled back appointment, workflow, audit, outbox and notification state exactly; each positive control applied the expected state and audit, delivered one correct recipient notice and rejected duplicate requests without extra rows. That clone passed mysqlcheck 26/26 and the full source checksum matched. The patient booking route was then failure-injected on a separate clone: the failed booking rolled back appointment/audit/outbox/notification exactly; success persisted one Pending appointment, one patient audit and one doctor appointment_request notice; duplicate slot booking was rejected without extra rows. The patient-booking clone also passed mysqlcheck 26/26 with source checksums matching. A separate admin-assisted booking clone then proved both-recipient enqueue behavior: injected failure rolled back exactly; success created one appointment, one admin audit and delivered one appointment_request notice each to doctor and patient; duplicate slot was rejected without extra rows. That clone passed mysqlcheck 26/26 and source checksums matched. Positive controls persisted exactly one matching delivery for each single-recipient operation. The current Phase 17 verification-first registration worker path passed a fresh 15 Nov notification-outbox failure test (12/12 rollback assertions) and retry/positive-delivery control (8/8); disposable-clone mysqlcheck passed 28/28 and the clone was dropped. This closes the current registration outbox rollback gap locally. Evidence: `hb-auth-email-polish-20261115/PASSWORD_RESET_AND_OUTBOX_RESULT.md`. The actual CLI worker passed a non-idle retry/delivery/idempotency test: injected notification insert failure scheduled one retry without a partial notification; after trigger removal the recovered event delivered exactly once, and a second run did not duplicate it. Continue failure/rollback coverage for other uncovered producer groups and investigate the historical 10 November worker PDOException/exit-code-1 records. The worker now logs sanitized SQLSTATE/driver codes for future PDO failures; the controlled HY000/1049 diagnostic test passed without exposing a connection string. Timestamp correlation suggests local MariaDB was unavailable/not ready: worker failures on 8 Nov and 11 Nov stopped around the logged MariaDB ready events, while the 10 Nov failure run continued overnight until the 11 Nov 01:57 ready event (first worker exit 0 at 02:01). No shutdown cause or historical SQLSTATE proves this, so classify as probable but unconfirmed DB unavailability. A further local worker crash-boundary acceptance passed on 17 Nov 2026: the actual CLI worker was killed after inserting its notification but before the outbox delivered-state update committed. Both changes rolled back, outbox remained pending/attempt_count 0 with no notification row, then recovery delivered exactly one and the idle rerun did not duplicate it. All 18 assertions passed, clone mysqlcheck 26/26, protected source normalized dump unchanged and test clone dropped. Evidence: `hb-rel-001-worker-crash-20261117/RESULT.md`. Do not repeat unless NotificationService::processOutbox() or its transaction boundary changes. The worker's two skip branches also passed on 17 Nov 2026: missing recipient was marked `skipped/recipient_missing`; a present recipient with its notification preference disabled was marked `skipped/preference_disabled`. Both left attempt_count 0, created no notification rows, and remained skipped on an idle rerun. All 16 assertions passed, clone mysqlcheck 26/26, source normalized dump unchanged and clone dropped. Evidence: `hb-rel-001-worker-skip-20261117/RESULT.md`. Do not repeat unless recipient/preference skip logic changes. The notification worker's terminal retry boundary also passed on 17 Nov 2026: an event seeded at attempt_count 7 hit a clone-only notification INSERT failure; the actual worker reported one terminal failure, exited 1, persisted status `failed` at attempt 8 with processed_at and sanitized driver code 1644, and created no notification. A second worker run was idle (exit 0) and did not change the terminal row. All 13 assertions passed, clone mysqlcheck 26/26, protected source normalized dump unchanged and clone dropped. Evidence: `hb-rel-001-worker-terminal-failure-20261117/RESULT.md`. Do not repeat unless the retry threshold/terminalization logic changes. The worker's 30-day outbox retention cleanup also passed on 17 Nov 2026: old delivered/skipped rows were pruned; an old failed row, a 29-day delivered row and a future-available pending row were retained; a second worker run made no further changes. All 18 assertions passed, clone mysqlcheck 26/26, source normalized dump unchanged and clone dropped. Evidence: `hb-rel-001-worker-retention-20261117/RESULT.md`. Do not repeat unless the retention interval or cleanup predicate changes. Do not bulk-move notification calls or undo account-first lock ordering.
5. **Database reliability:** finish remaining route failure/concurrency gaps, and document a safe upgrade gate for `HB-DB-007`. The migration rehearsal is not a migration ledger; never infer one-time migration state from filenames or blindly replay Phases 13/14. The 17 Nov sole-active-admin self-deactivation HTTP guard passed 30/30 local runtime assertions: a first admin may step down when a second active admin remains; the last admin is blocked with HTTP 409, stays active with unchanged session_version, keeps their session, and produces no audit row. The successful first deactivation revoked that session with HTTP 403 and wrote one audit event. Clone mysqlcheck 25/25, protected source unchanged, clone/server/session/temp removed. Evidence: `hb-db-006-sole-admin-guard-20261117/RESULT.md`. The separate HB-DB-003 two-connection InnoDB race was reproduced before the locking fix and passed after the fix, leaving one active admin. Authenticated simultaneous HTTP-request acceptance remains a P2 follow-up; hosted/multi-host behavior is not claimed.
6. **Local access boundary:** preserve existing 29/29 local Apache boundary evidence and recheck only after relevant source/config changes.

### Deferred beyond Milestone 2 â€” hosted/deployment-only gates

- `HB-SEC-006` historical migration-executor exposure and `HB-SEC-007` hosted internal-path exposure cannot be closed by local source checks. The hosted environment remains frozen unless deployment/hosting inspection is explicitly authorized.
- `HB-SEC-001`, `HB-SEC-002`, `HB-SEC-008` and `HB-DB-002` have substantial local acceptance; retain hosted/release verification as separate gates.
- `HB-AUTH-005` local password-reset email request/delivery was verified on 15 Nov. On 17 Nov, the actual reset redemption endpoint passed 31/31 assertions on a disposable clone: verify-only and invalid/expired/used-token handling; weak/mismatched password rejection; injected DB failure rolled back password/session/token changes; valid redemption changed the synthetic account password, incremented session_version once and consumed all outstanding tokens; re-use was rejected. Clone mysqlcheck 25/25, source unchanged, clone and QA server removed. Evidence: `hb-auth-005-reset-token-redemption-20261117/RESULT.md`. A separate session-runtime check also passed on 17 Nov 2026 (25 assertions): old synthetic session was accepted before reset, rejected by current_user after session_version 71â†’72, old password login rejected, and new-password login/current_user succeeded with a fresh session. Clone mysqlcheck 25/25, protected source unchanged, clone/server/session/temp cleanup passed. Evidence: `hb-auth-005-session-revocation-20261117/RESULT.md`. Do not repeat unless login/current_user session-version policy or reset transaction changes. Real inbox token/deep-link browser journey, hosted SMTP/HTTPS APP_BASE_URL and secret provisioning remain separate release gates. Under the zero-budget constraint, do not introduce paid services or weaken fail-closed behavior.
- Hosted/off-site backup recovery, custom-domain/TLS configuration and any production healthcare/legal review remain separate deployment responsibilities.

### Milestone 2 exit criteria

- No unresolved locally reproducible P0/P1 security or data-integrity issue.
- Remaining P1/P0* entries are explicitly categorized as hosted-only or blocked by an external dependency, with owner/action/impact recorded.
- Critical local security, session, notification transaction and database failure checks have repeatable evidence.
- No protected/hosted data was changed.

---

## Milestone 3 Ã¢â‚¬â€ Architecture, full UI/UX refactor and cleanup

**Status:** In progress — M3.0 discovery/baseline completed 20 November 2026. Next: M3.1 UX and information architecture.

**Current plan:** `docs/HEALTHBRIDGE_MILESTONE_3_PLAN.md`. **M3.0 audit and evidence:** `docs/HEALTHBRIDGE_MILESTONE_3_AUDIT_20261120.md` and `C:\\xampp85\\migration-backups\\healthbridge\\milestone3-audit-20261120\\RESULT.md`.  
**Purpose:** Make the system easier to understand, maintain and use. This milestone includes a deliberate, application-wide UI/UX refactor: a more coherent professional visual design, improved interaction patterns, responsive layouts and evidence-based performance improvements. It is not a feature-expansion milestone; preserve existing capabilities, role boundaries, routes and business behavior.

### Workstreams

1. Finish the high-value page Ã¢â€ â€™ JavaScript Ã¢â€ â€™ API Ã¢â€ â€™ service Ã¢â€ â€™ table caller maps under Phase 1. Reconcile dynamic/role-specific callers against the current source.
2. Review the largest confirmed hotspots (currently recorded in the roadmap: `admin.js`, `patient-emr.js`, `doctor-workspace.js`, `PatientPortalService` and `ScheduleService`). Recheck current sizes before acting.
3. Split only where there is a stable responsibility boundary and tests protect the behavior. Prefer extracting cohesive modules/services over mechanical line-count reduction.
4. Resolve cleanup candidates individually:
   - `HB-CLEAN-001`: seven routes without an obvious frontend caller; establish dynamic/external/compatibility usage before removal.
   - `HB-CLEAN-005`: decide whether `VisitWorkflowService::migrateExisting()` is removed or retained behind an explicit CLI maintenance path. Never invoke it on live data for discovery.
   - `HB-CLEAN-006`: review the 16 declaration-only methods for contracts/extension/maintenance use; do not bulk-delete.
5. Reconcile the README, module map, endpoint inventory and operational documentation where actual contracts/ownership changed.
6. **Full HealthBridge UI/UX refactor:**
   - Audit the existing public, authentication, patient, doctor and admin interfaces before redesigning them. Establish the page inventory, shared patterns, current usability issues and a representative desktop/tablet/mobile baseline.
   - Define and apply a consistent visual system (design tokens, typography, spacing, color, surfaces, navigation, responsive breakpoints and reusable UI patterns). Improve hierarchy, clarity and visual quality across the application rather than polishing isolated pages independently.
   - Refactor shared layouts and recurring interactions such as navigation, forms, tables, filters, cards, dialogs, alerts, loading/empty/error states and feedback. Preserve current routes, permissions, data contracts and user workflows; do not add product features as part of the redesign.
   - Audit every in-scope page at representative desktop, tablet and mobile widths. Fix horizontal overflow, clipping, unusable controls, poor stacking and touch-target/layout issues. The Patient Dashboard overflow observed on 15 Nov 2026 (document scrollWidth 804px at a 390px viewport; Health Alerts content 315px versus 310px available) is explicitly deferred to this workstream, not a Milestone 2 security blocker.
   - Measure representative page-load, rendering, asset and interaction performance before and after the redesign. Prioritize demonstrated bottlenecks; optimize shared CSS/JavaScript, images/fonts, redundant requests and expensive rendering only where profiling supports the change. Record comparable before/after results and avoid speculative optimization.
   - Include accessibility in the redesign: semantic structure, keyboard navigation, visible focus, contrast, labels, responsive zoom/reflow and accessible dialogs/feedback. Validate critical role workflows after each relevant shared-layout or component change.
   - Deliver the redesign incrementally by shared foundation and role area/page group, with focused visual, responsive, accessibility, performance and workflow regression evidence at each step.

### Milestone 3 exit criteria

- Critical caller/ownership maps are accurate for the workflows in scope.
- Every architectural extraction has a clear before/after responsibility and regression evidence.
- Cleanup candidates each have a keep/remove/CLI-maintenance decision and supporting evidence.
- The application-wide UI/UX redesign is consistently applied across the agreed public, authentication, patient, doctor and admin page inventory, with no major role area left on an incompatible legacy design without an explicit documented deferral.
- Representative desktop, tablet and mobile checks show no unintended horizontal page overflow, clipped primary content or unusable responsive controls; the previously recorded Patient Dashboard/Health Alerts overflow is fixed and verified here.
- Accessibility regressions introduced by the redesign are absent in the agreed critical journeys; keyboard, focus, contrast, form-label and dialog behavior have focused evidence.
- Before/after performance measurements are recorded for representative pages, and demonstrated bottlenecks have been improved without regressions in core workflows.
- Existing features, role permissions, routes and business behavior remain intact; no feature expansion is introduced.
- All visual, responsive, accessibility and performance changes have focused regression evidence, and remaining limitations are individually documented.

---

## Milestone 4 Ã¢â‚¬â€ Final regression, freeze and handoff

**Status:** Queued  
**Purpose:** Establish a stable local completion point and stop refactoring.

### Final verification

1. Re-run the complete HealthBridge Playwright suite serially with workflow flags absent; run gated synthetic role workflows separately with narrow allowlists.
2. Run JavaScript syntax checks, PHP syntax checks for changed PHP files, targeted unit/integration tests and the agreed API method/auth/ownership checks.
3. Run desktop/mobile accessibility checks on the critical public and authenticated workflows; record any cross-browser engines that are unavailable rather than claiming coverage.
4. Run focused performance checks only for measured regressions/hotspots; retain known baseline limitations (including landing-page LCP) rather than undertaking open-ended optimization.
5. Verify fresh install, upgrade and backup/restore on disposable databases. Record MariaDB/MySQL compatibility limits and migration-state requirements; no real database migration is authorized by this plan.
6. Perform a final diff/worktree review and verify no QA server/process is left running. Preserve the existing uncommitted worktree; report staged count and commit/branch state.

### Final freeze exit criteria

- All Milestones 1Ã¢â‚¬â€œ3 are complete or have individually approved, documented deferrals.
- Critical local user journeys and regression suite pass.
- Local database backup/restore and applicable schema checks are repeatable.
- Findings register clearly separates locally resolved, locally deferred, externally blocked and hosted-only entries.
- Documentation and handoff agree with the verified code/evidence.
- A final engineering completion report lists what is complete, what is not, known limits and the deployment gates.

Current authorization is narrowly scoped: commits/pushes may target only `origin/repair/healthbridge-staged-hardening`; `main` must remain untouched and there is no merge or deployment authorization. The final reset/reseed of only the fictional local `healthbridge` database is authorized after a fresh full backup has been successfully restored and verified in a new disposable database. Hosted operations remain unauthorized except for the separately authorized Alwaysdata Free demo/staging deployment recorded in the repair handoff. No production deployment or real patient data use is authorized.

---

## Explicit deferred / out-of-scope list

- New product features, payment processing, new role privileges, new notification channels or paid infrastructure.
- Rewriting the system in another framework/language or replacing all large controllers/services.
- Removing uncertain compatibility routes/methods/tables or declaration-only methods without contract evidence.
- Production or hosted security verification while hosting remains frozen.
- Formal healthcare regulatory certification, legal/privacy opinions or claims of production readiness.
- Speculative performance tuning without a measured issue.
- Ad hoc cosmetic changes during Milestones 1â€“2. The intentional application-wide visual and UI/UX redesign is explicitly included in Milestone 3 as described above.

## Current checkpoint

**As of 11 November 2026:** Milestone 1 is complete. The role-gated Admin EMR remains at `pages/patient/patient-emr.html` with `assets/js/pages/patient-emr.js` and shared medical-record/prescription modules. The doctor owns clinical-history and prescription lifecycle mutations through `pages/doctor/doctor-workspace.html` and the shared modules. The tested role boundary is preserved.

**Verified focused browser results:** Admin Patient EMR read-only review 1/1; profile edit, account disable/re-enable, password reset/session revocation and administrative correction 4/4; archive retention 1/1; doctor history/prescription edit/visit completion 1/1; separate reason-required prescription cancellation 1/1. All mutation scenarios used the dedicated synthetic QA clone and restored/reseeded after each test.

**Archive-specific defect corrected:** a global `openDeleteModal` name collision between Patient EMR and Settings caused the Admin Archive control to invoke the wrong function. The EMR helper is now `openEmrArchivePatientModal`, avoiding that shared global collision.

**Current position (14 November 2026):** Milestone 2 Ã¢â‚¬â€ local security and reliability closure Ã¢â‚¬â€ remains active. HB-AUTH-001's encrypted asynchronous registration intake is integrated in the local application on `repair/healthbridge-staged-hardening`, with additive Phase 16 applied to the fictional local `healthbridge` database. Two rounds of live HTTP integration, queue encryption/generic-response checks, transactional rollback/retry, unique-index collision classification, and expired-lease recovery have passed. Shared profile validation now enforces server-side field-size limits and National-ID/gender consistency. Focused quality checks passed: 119 PHP files linted, Node and PowerShell syntax checks passed, `mysqlcheck` passed 27/27, and the safe Chromium regression passed 15 tests with 17 gated skips and 0 failures. The registration worker runs every minute; the notification outbox worker runs every five minutes. Both tasks now use hidden `wscript.exe //B //NoLogo` launchers, hidden PowerShell children and Task Scheduler `Hidden=true`, and both test runs returned exit code 0. The local DB has 27 tables; after the 14 Nov post-registration login acceptance it has 14 registration queue rows and 164 users. The test added one further synthetic patient (user 165, queue item 14), which logged in successfully with the registration password. The queue is empty of pending/processing jobs; the account and completed row remain until the separately gated final refresh. The authorized final reset/reseed remains pending and still requires a fresh full backup plus a separately verified restore. Local Chromium Step 1/Step 2 queued-registration acceptance passed 1/1; the automatic one-minute worker completed one synthetic job, delivered one notification and cleared terminal ciphertext. A separate HTTP missing-key browser negative passed 1/1 with generic HTTP 503; queue/user totals remained unchanged at 13/163 and no missing-key account was created. The live post-registration login follow-through passed 1/1 on 14 November 2026: the new synthetic patient authenticated with the same password used at registration, received an HttpOnly/SameSite=Lax session cookie, reached the patient dashboard and passed the protected current-user identity check. Evidence: `C:\\xampp85\\migration-backups\\healthbridge\\hb-auth-001-post-registration-login-20261114\\RESULT.md`. A local queue-age operator signal has also been added: persistent `queue-age-alert.json`, OPEN/REMINDER/RESOLVED records in `queue-age-alerts.log`, 30-minute reminders and Task Scheduler exit code 2 when stale. Alert helper tests passed 16/16; isolated wrapper and hidden VBScript propagation tests passed; the real hidden worker task returned 0 on the healthy empty queue. It is not an email/push channel and requires an operator to inspect the marker/log/task result. A seeded Patient Quick Demo browser login passed 1/1 on a fresh disposable clone (login POST 200, patient role, dashboard redirect, HttpOnly session cookie, zero page errors); clone was dropped and the main-source dump/row counts/AUTO_INCREMENT values remained unchanged. This is not post-registration login acceptance. The local login frontend exposes role-specific demo identities and a fixed demo password, so keep associated accounts synthetic and disable/protect the shortcut before non-demo/production use; hosted behavior was not inspected. HB-AUTH-001 remains P1/open, but local post-registration login acceptance is closed. The integrated local asynchronous endpoint timing gate closed on 14 Nov 2026 after 40 new and 40 duplicate Step 2 requests returned one identical HTTP 200 response; medians were 349.918 ms and 349.755 ms (delta +0.164 ms; bootstrap 95% interval -1.665 to +0.940 ms; p=0.468). Worker and persistence checks passed, clone mysqlcheck passed 27/27, and the main database logical dump hash/27-table row-count/AUTO_INCREMENT manifests were unchanged; the clone was dropped. Evidence: hb-auth-001-integrated-timing-20261114/RESULT.md. This is not remote-network/hosted timing proof. Remaining gates are out-of-band queue-age alert delivery/operator ownership, email/phone ownership verification after provider/policy approval, and shared limiter/trusted-proxy/keyring/cross-node worker readiness before multi-host deployment. A design-only proposal recommends holding the encrypted registration job in awaiting_verification until a one-time email token is redeemed, then creating the patient aggregate. No code/schema change was made because no email/SMS transport exists in the repository; an email sender and email-only versus email+phone policy must be approved first. See docs/HB-AUTH-001-CONTACT-OWNERSHIP-VERIFICATION-DESIGN.md. Four worker crash/replay boundaries passed on a disposable 27-table clone; same-user DPAPI backup restore and v1/v2 key-rotation compatibility also passed. The original application key and main healthbridge DB were unchanged. Detailed evidence: `hb-auth-001-crash-replay-20261114/RESULT.md` and `C:\\xampp85\\migration-backups\\healthbridge\\queue-age-alert-implementation-20261114\\`. Before final reset/reseed, take a fresh current full backup and successfully restore/compare it in a newly named disposable database. Continue with the remaining Milestone 2 gates; do not repeat accepted Milestone 1 tests without a source change or concrete regression.

## Alwaysdata Free staging update (13 November 2026)

The isolated demo deployment is live at `https://youssef1.alwaysdata.net/` using `/home/youssef1/healthbridge-test/` and database `youssef1_healthbridge_test`. The 27-table schema, fictional seed data, PHP 8.4.27 syntax check (194/194), public database-backed APIs, Admin quick login/dashboard, patient/doctor/department lists and `.env`/private-file HTTP protections passed. The live PHP runtime connected to the test database successfully. The site is staging-only; do not use real patient data. The combined Alwaysdata scheduled task #38529 is active every minute from the corrected working directory, with its DB password configured privately and `&&` chaining the registration and notification workers. Two empty-queue executions returned exit code 0. These runs verify the staging worker/configuration path only; a non-empty Alwaysdata registration-to-worker browser journey has not been run. SMTP delivery is also unconfigured here. See the repair handoff for exact deployment and cleanup details.
### Alwaysdata scheduled-worker verification (13 November 2026)

Scheduled task #38529 is active every minute from `/home/youssef1/healthbridge-test`. Its command chains the registration and notification workers with `&&` and its private task environment contains the required database settings and password. Two post-fix runs (22:17:09 and 22:18:09 UTC+1) reported registration `claimed=0`, `failed=0`, no retries and no pending-age warning; notification `delivered=0`, `pending=0`, no retries or terminal failures; exit code 0 for both. This validates the empty-queue CLI/config/database/keyring path. A non-empty browser registration through queue-to-account completion has not yet been run. SMTP remains unconfigured. This is still demo-only staging; no real patient records.

## Mail transport and registration queue email alerts â€” 14 November 2026

The local source now includes a shared PHPMailer 7.2.0 Gmail SMTP service using `smtp.gmail.com:465` SMTPS and sender/operations mailbox `llaunchboard@gmail.com`. The password-recovery route now sends 60-minute reset links to the matched patient's registered email using a generic non-enumerating response and hash-only token persistence. Queue-age alert delivery is integrated into the local worker wrapper with OPEN, 30-minute REMINDER, RESOLVED and five-minute retry semantics. Delivery is not marked successful until the mail sender returns success.

Isolated verification: 29/29 queue-age helper tests; fake-worker/fake-mailer wrapper integration; disposable DPAPI reader round-trip; PHP lint; PowerShell parser; Composer validation; PHPMailer v7.2.0 lock. No main DB or disposable DB writes were needed. No actual Gmail email has been sent: the real app password remains unconfigured and must be entered via `tools/SetHealthBridgeMailPassword.ps1` in a masked local prompt. The fixed test sender must then be run and the email receipt confirmed before closing live SMTP delivery. Registration email verification and welcome email are not yet implemented; the separate verification state machine/policy remains open. Hosted deployment/resources remain untouched.


Portability note: the shared mailer supports local Windows DPAPI storage and a non-Windows hosting runtime's protected `SMTP_USER`/`SMTP_PASS` variables. The latter was verified only with a dummy in-memory value; no hosted configuration/deployment was changed.


## Current checkpoint â€” 17 November 2026

Milestone 2 remains active and local-only. The 17 Nov focused reliability/authentication evidence recorded in the QA index includes notification outbox crash/skip/terminal-retry/retention boundaries, queue-age alert OPEN/RESOLVED email lifecycle, reset-token redemption and reset-driven session revocation, and the sole-active-admin self-deactivation guard. These are accepted only for their exact tested source paths and disposable fixtures; hosted/multi-host readiness remains a separate release gate.

A further HB-SEC-010 Patient Notifications hostile-output browser test passed **17/17** on 17 Nov using Playwright 1.65.0 / Chromium with synthetic browser identity and mocked APIs. Hostile notification fields remained inert, malformed IDs did not trigger mutations, and only the expected positive-ID mark-read and fixed mark-all actions were sent. No PHP handler or database was reached; no application source changed. Evidence: `hb-db-004-history-lock-20261117/patient-notifications-xss-RESULT.md`.

The HB-DB-004 authenticated add-history-update runtime check passed **10/10 assertions** on a disposable 25-table clone. While patient 2's row lock was held, `SHOW FULL PROCESSLIST` identified the actual route's `SELECT id FROM users ... FOR UPDATE` query (thread 218) and MariaDB reported `Innodb_row_lock_current_waits=1`; the sole controlled blocker was thread 215. A separate connection acquired and committed appointment 1 before patient-lock release; the route then resumed, returned HTTP 200 and persisted exactly one synthetic history event. MariaDB's `information_schema.INNODB_LOCK_WAITS` graph returned no rows, so the accepted evidence uses the correlated active processlist query and global row-lock counter. Clone mysqlcheck passed 25/25, protected source normalized dump matched before/after, and clone/server/session/temp artifacts were removed. This closes the focused runtime gap for this route only; other affected clinical paths and broader load/multi-host verification remain separate gates. Evidence: `hb-db-004-history-lock-20261117/RESULT.md`.

Tooling check: the shared external toolkit at `D:\\dev-toolkit` is the supported runner for Playwright and PHP tools. Verified versions: Playwright 1.65.0 / Chromium, PHPUnit 13.0.0, PHPStan 2.1.0; project CLI PHP reports 8.5.12. PowerShell 7.6.10, PSScriptAnalyzer 1.25.0 and Pester 6.2.0 are available. PHP Intelephense and ESLint VS Code extensions were already installed; ESLint still requires per-project configuration before it is used as an accepted project check. No duplicate packages or speculative project config were added. Do not rely on the legacy XAMPP-global PHPUnit executable.

No application source, database, hosted resource, Git index, branch, commit, push or deployment was changed during this checkpoint. Preserve the existing working tree and continue with only a specific unresolved material risk per test; do not repeat accepted suites without relevant source changes.


### 17 November 2026 â€” HB-SEC-010 shared layout cached-user shape follow-on
A focused review of `assets/js/components/layout.js` found that `Layout.updateSidebarUser()` assumed cached `hb_user.name` was a string and could throw on object/array/numeric values during shared layout initialization. The function now accepts string-only name/email values, uses safe existing fallbacks, and derives initials only from a validated name. The isolated Node VM regression passed 6/6, including an HTML-looking literal string; `node --check` and `git diff --check` passed. This closes only the malformed cached-user shape case. It is not a full browser/layout acceptance or application-wide XSS clearance. Evidence: `hb-sec-010-layout-user-shape-20261117/RESULT.md`. Continue the remaining explicitly scoped Milestone 2 risks; do not expand into the Milestone 3 UI redesign.

## Current checkpoint â€” 18 November 2026

Milestone 2 remains active and local-only. HB-DB-007 received a bounded improvement: a new read-only Phase 10â€“15 schema-signature preflight at `database/migrations/preflight_phase10_15_readonly.sql`. The migration guide now documents how to run it only against a newly restored disposable clone and how to interpret its output conservatively. The findings register and QA evidence index record the helper and its limits.

Static validation found 18 SELECT-only SQL statements, balanced parentheses and all 28 expected Phase 13 FK names. No SQL dialect parser was available and the script was not executed against a database. A historical DDL review also confirmed a path-specific Phase 8/archived Phase 10 audit-table collision: Phase 8 creates legacy audit tables without FKs, archived Phase 10 uses `CREATE TABLE IF NOT EXISTS` and leaves those table shapes unchanged, while current Phase 13 unconditionally drops the four FKs and fails on that legacy shape. The preflight now lists both audit tables' column/index signatures. This remains a blocker; no table rewrite or guessed data migration was attempted. HB-DB-007 remains open for data-preserving audit-table upgrade, clean fixture, ledger and runtime acceptance.

The review did not connect to or modify any database, inspect secrets, touch hosted resources, or alter application source. The existing working tree remains preserved; no paths are staged, committed, pushed or deployed.


## Additional checkpoint - 18 November 2026 - whole-schema database architecture review

At the user's request, the review expanded from the Phase 8/10 audit-table collision to the complete database model. A read-only source/caller review covered the 27-table consolidated master, Phases 10-17, and a recent disposable 28-table synthetic clone. The protected healthbridge database was not queried or modified.

The clone had 37 declared foreign keys and zero orphan references; selected account-role and patient/doctor/appointment/clinical ownership checks all returned zero exceptions. The main defects found are semantic rather than general orphaning: duplicate doctor IDs, dashboard counts comparing user IDs to doctors.id, 30/32 stale doctor rating caches, 6/486 12-hour appointment time strings, dual availability columns with an unsynchronized schedule-update path, 15 duplicate/prefix-covered index candidates, and weak DB-level enforcement of business rules. The master schema has 27 tables and includes Phase 16 but omits Phase 17 structures present in the recent clone and required by current registration verification.

Created docs/HEALTHBRIDGE_DATABASE_REDESIGN.md with evidence, target domain model, and a parallel migration plan. The migration guide and README were corrected for Phase 16-17 drift and the fresh-install Phase 17 requirement. Added the 10-17 read-only migration signature preflight. No v2 schema has yet been executed or promoted; no table/index changes, ETL, application fixes, or cleanup have been performed. Milestone 2 remains active. Next: validate the v2 design against every legacy reader/writer, build a separate schema prototype and deterministic ETL on a disposable database, then run equivalence and restore acceptance before considering a cutover. No staging/commit/push/deployment.


### 18 November 2026 - Phase 10-17 preflight runtime follow-on

Expanded the earlier read-only helper to preflight Phases 16-17 and split the challenge metadata into separate table/column/index queries. Static validation found 26 SQL statements, all SELECT, with balanced parentheses. The final script passed 26/26 executions against healthbridge_hb_auth_email_verify_20261114 after an in-process DATABASE() exact-target guard; MariaDB 10.11.16 confirmed the current clone's Phase 16 queue and Phase 17 verification columns/status/challenge/FK. The earlier 24-query draft was superseded by this cleaner 26-query version. No protected or other app DB was accessed and no DB write occurred. The current master/fresh-setup mismatch remains: its 27 tables include Phase 16 but omit Phase 17.

The whole-schema redesign brief now includes a table-by-table disposition for all 27 master tables plus the Phase 17 challenge table. It remains a target design only; no v2 DDL or ETL has been implemented or executed. Milestone 2 remains active.


## 18 November 2026 ? v2 schema and synthetic migration prototype complete

Built an experimental MariaDB 10.11.16 schema under database/v2/. The base model and incremental SQL establish canonical doctor identity, separated patient/doctor/preferences data, canonical appointment timestamps, separate reschedule requests, consolidated clinical encounters, derived rating aggregates, legacy-value archives, migration lineage/exception tracking, and composite patient/doctor-to-appointment ownership constraints. Explicit phone collation was added after restore testing exposed a generated-expression collation issue.

The v1 synthetic source healthbridge_hb_auth_email_verify_20261114 was read-only. The one-transaction ETL created 37 target tables, one view, 42 FKs and 23 CHECK constraints. 3,466 row-lineage records and 100 accepted non-blocking warnings were recorded. All 27 field-level equivalence queries returned zero differences; all 42 FK relationships had zero orphan rows; eight constraint checks passed; CHECK TABLE passed 37/37. The source clone's 28/28 PK-ordered row-count/SHA-256 fingerprints matched before/after.

A separate byte-preserving logical backup restore into healthbridge_v2_restore5_20261118 passed exact checks: 37/37 row hashes, 379 column definitions, 178 index rows, 48 FK mappings, 23 CHECKs, one view, engine/collation/AUTO_INCREMENT, and CHECK TABLE 37/37. Sequence counters consumed by rolled-back negative tests were explicitly restored to source high-water marks in the restore test DB. Full details: database/v2/ETL_RESULT.md.

This is not application compatibility or cutover. The current application remains on v1. Older Phase 8 audit schema and non-empty mappings for zero-row tables still need fixtures; then build an API/service compatibility layer and run full auth, booking/reschedule, schedule, clinical encounter, EMR, prescription, rating, audit, notification, registration and worker journeys. Milestone 2 remains active. Protected main DB/NOVA/hosted resources/secrets untouched; no staging, commit, push or deploy.


## 18 November 2026 â€” v2 application compatibility discovery

Started the application compatibility stage without switching runtime schemas. A static scan covered PHP SQL callers under api/services/includes/pages (excluding vendor, tools and v2 migration scripts). The scan found 36 appointment callers, 16 doctor-profile callers, 11 rating callers, 14 visit-note callers, 13 workflow callers, 13 prescription callers, six preferences callers, five draft callers and four schedule-settings/two weekly-schedule callers.

The first vertical slice is defined as doctor directory/profile + operational availability + schedule + booking-slot reservation. The app consistently uses users.id as the public doctor identity in booking/schedule, but some services still use legacy doctors.id. In the inspected fixture, all 32 legacy doctors.id values differ from doctors.user_id. Schedule SQL also uses legacy doctor_schedule_weekly and old column names; v2 uses doctor_weekly_schedules with a composite key and doctor_schedule_settings.doctor_user_id/appointment_duration_min/TIME fields. Frontend contracts expect the existing doctor_id, appointment_duration, is_available and weekly day DTO fields, so adapters must preserve them.

A schedule-only port is unsafe because ScheduleService slot generation reads legacy appointment date/time plus embedded reschedule fields. v2 moves to starts_at/duration_minutes and separate appointment_reschedule_requests; original and active proposed/requested ranges must remain reserved. Clinical workflow/notes, ratings, mixed preferences, auth password column and audit archives also require semantic adapters rather than global table renames.

The known dashboard doctor-key bug is recorded for correction: DashboardAnalyticsService compares doctors.id against appointment/rating doctor IDs that reference users.id, producing wrong counts for 31/32 appointment and 29/32 review profiles in the inspected fixture. Availability reads also diverge because dashboard uses doctors.available while schedule settings are the operational source of truth. No fix was mixed into this discovery checkpoint.

Added docs/HEALTHBRIDGE_V2_APPLICATION_COMPATIBILITY.md with the caller inventory, v1/v2 field map, API DTO requirements, dependency hazards and acceptance suite. No application PHP/JS, database, DB selection, Git branch/index, hosted resource or secret was changed. No paths staged. Next: add explicit v1/v2 doctor-profile and schedule repositories with guarded dual-schema contract tests; do not enable v2 slot/booking paths until the reschedule reservation adapter passes.


### 18 November 2026 â€” opt-in v2 profile/schedule/reservation repositories

The first application compatibility building blocks are now implemented without switching any endpoint or runtime database:

- Added explicit v1/v2 repositories under services/Repositories for doctor profile reads, schedule settings/weekly rows, and appointment/reschedule slot reservations.
- Public doctor DTOs preserve the legacy profile row `id` separately from canonical `user_id`/`doctor_id`; admin DTO `id` remains the user ID.
- Weekly schedule DTOs keep the existing day/time fields; v2 emits a nullable weekly row `id`. Schedule availability writes in v1 synchronize both duplicate flags; v2 keeps operational availability separate from accepts_new_patients.
- Added guarded tests: 11/11 read-only cross-schema assertions; 448 doctor/date reservation comparisons; rollback checks for v1 settings+availability and v2 settings+weekly writes; synthetic suggested/approved/rejected/cancelled reservation-state tests. All passed. The suggested/terminal scenarios were only temporary transaction test states and both fixture DBs were rolled back.
- The real fixture's 12 reschedule rows are all `requested`; a dedicated rollback-only test supplied suggested and terminal states because those were absent from baseline data.
- Two zero-review doctors have stale legacy 4.5 rating caches; v2 deliberately derives 0 from rating facts. This remains a documented compatibility/data decision, not a test failure.

This is repository-level contract coverage, not API integration. Existing ScheduleService and endpoints still use v1 SQL. Next: map and adapt every remaining ScheduleService query and doctor create/update/department mutation path under explicit schema mode, then run booking concurrency and API/UI regressions. Clinical, authentication, prescription, audit, notification and worker integrations remain separate. No current runtime routing, DB selection, production resource, Git index, commit, push or deployment was changed.


### 18 November 2026 â€” ScheduleService dual-schema integration

Extended the opt-in compatibility layer into `ScheduleService` itself. It now accepts an explicit `v1`/`v2` constructor mode, defaults to `v1`, delegates all schedule CRUD and booked-range SQL to the repositories, formats v2 hospital TIME values as HH:MM, and preserves caller-owned transaction boundaries. Existing API endpoints still pass only PDO and therefore remain v1.

Evidence:
- `tools/test_v2_schedule_service_contract.php`: 9/9 read-only assertions passed for 32 doctors and 448 doctor/date pairs. Hospital hours, schedule reads/listing, working schedule, public availability/next slot, booked ranges, slot generation and validation matched between v1 and v2.
- `tools/test_v2_schedule_rollback.php`: service-level transaction tests passed; v1 availability flags and v2 schedule rows were restored on rollback. v2 reset-to-default generated all seven expected days and was rolled back.
- `tools/test_v2_profile_availability_semantics_rollback.php`: profile acceptance preference stayed separate from operational schedule status; temporary changes rolled back.
- The legacy setting `accept_new_patients` is not currently consumed by server-side booking/reschedule validation in the inspected code. Preserve its separate v2 field and treat enforcement as an open policy decision; do not equate it with schedule availability.

No API endpoint, route, frontend, app DB selection or deployment changed; no protected/main/hosted database or secret was accessed. Next: adapt and test doctor profile mutations/department assignment, then design the v2 booking/reschedule transaction layer including locking and concurrency before switching any endpoint.


### 18 November 2026 â€” doctor management mutation adapter

Added `services/Repositories/DoctorManagementRepository.php` with explicit v1/v2 modes and canonical doctor user IDs. It supports account/profile/schedule creation, name/email/phone updates, department assignment, activate/deactivate/archive. It writes schema-appropriate audit entries and transactionally queues notifications. Doctor creation stores only a supplied password hash and creates a seven-day default schedule. Assignment validates active departments and syncs specialty. Deactivation preserves clinical history, refuses active visits/prescriptions/drafts, cancels only untouched appointments, closes v2 active reschedule child rows and increments session_version.

`tools/test_v2_doctor_management_rollback.php` passed on both disposable fixtures. It covered create/update/assignment and audit, session revocation, clinical-work deactivation guard (doctor 9 blocked in both fixtures), and archive/reactivate (doctor 3, four untouched appointments cancelled in each schema). All writes were rolled back and AUTO_INCREMENT values restored to their initial high-water marks.

This is still an opt-in repository. Existing endpoints and DepartmentService remain v1 and have not been routed through it. Next is the booking/reschedule transaction adapter and concurrency/locking tests; only after that should route compatibility work start. No runtime DB selection, protected resource, Git staging, commit, push or deployment changed.


### 18 November 2026 â€” booking/reschedule transaction adapters

Added `AppointmentBookingRepository.php` and `RescheduleTransactionRepository.php` with explicit v1/v2 schema modes. Booking validates canonical doctor/profile/department/patient identities and schedule slots inside the doctor-first lock, then writes schema-correct appointment, audit, event and outbox rows. Rescheduling now covers request, suggestion, patient accept/decline, patient cancellation, doctor approval/rejection; v2 writes separate child request history and keeps requested/proposed reservations distinct. Read methods return the existing pending-request/history API fields, including no-history defaults and legacy `none` mapping for v2 cancelled requests.

Evidence:
- `test_v2_booking_reschedule_rollback.php`: complete lifecycle passed on both schema fixtures, including slot reservation checks, history-state reads, cancelled-parent guard and full transaction rollback/counter restoration.
- `test_v2_booking_concurrency.php`: two-connection v2 booking serialization passed; second connection waited on doctor lock and, after first commit, was rejected by in-lock slot revalidation. Synthetic rows/events/audit/outbox and AUTO_INCREMENT values cleaned/restored.
- `test_v2_reschedule_read_dto.php`: 5/5 read-only API DTO parity checks; all 12 pending requests across 11 doctors matched.
- Department reassignment lock order corrected to doctor account -> profile -> department.

Existing API booking/reschedule controllers remain on v1 and are not connected to these repositories. Remaining appointment UI/read work depends on clinical workflow/encounter and patient/doctor timeline adapters; no route or DB switch is made at this stage.


### 18 November 2026 â€” clinical encounter and appointment timeline compatibility

Implemented `ClinicalEncounterRepository` and schema-explicit `VisitWorkflowService`/`VisitDraftService` facades. v1 workflow, note and draft behavior maps to v2 clinical encounters and encounter drafts. The note-only/uninitialized encounter rule is preserved. Refactored clinical note/write/read, EMR, print, doctor workspace, rating eligibility and draft routes to use shared services; current endpoint constructors remain v1.

Added explicit v1/v2 clinical access and patient timeline modes. Clinical access passed 4,256 doctor/patient decisions (366 allowed/3,890 denied). Patient appointment timeline passed 486/486 appointments. Medical timeline passed 1,079 events across 133 patients. Seeded workflow/note/draft rollback tests passed on both clones, covering note-only creation, transitions, drafts and owner validation. All synthetic data writes rolled back and counters restored. All 20 touched clinical PHP files linted.

Next: prescription repository/service compatibility. Remote inspection of prescription DDL/service is currently needed before safe changes; no prescription code has been modified in this stage. Existing API remains v1; no database or deployment switch.

### 18 November 2026 - prescription service and consumer compatibility

Added v1/v2 schema support to `PrescriptionService` and adapted patient portal prescription cards. Prescription create/update/complete/cancel and read DTOs normalize v2 owner keys and lowercase status values. Audit writes map to `audit_events` in v2, notification events remain transactional in the outbox, and visit completion can finalize prescriptions within its existing transaction without nesting.

Refactored visit completion, EMR, doctor workspace, printed EMR, doctor archive and doctor disable consumers to use the shared prescription service. Routes remain on v1 by default.

Evidence: 99 headers and 186 medication items matched; patient/doctor/admin list IDs and three status filters matched; sampled patient portal cards matched. Create/update/item replacement/complete/cancel/owner rejection/caller-owned rollback passed in both disposable fixtures. Test data, audit rows, notification preferences and AUTO_INCREMENT counters were restored. All nine touched PHP files linted clean.

Next: patient portal summary/alerts/download/search sections and remaining account/settings consumers, followed by broader application journey validation. No v2 route opt-in or cutover.

### Patient portal read compatibility checkpoint - 18 November 2026

Expanded the schema-aware PatientPortalService read layer while retaining the v1 default:

- Added v2 dashboard overview using normalized appointments, latest reschedule child state, doctor profiles/rating summary, schedule availability, shared notification counts and hospital settings.
- Added v2 medical-profile read by joining patient_profiles and patient_medical_profiles, preserving the existing portal DTO.
- Added v2 favorite/recommended doctor aggregates using doctor_profiles, doctor_schedule_settings and v_doctor_rating_summary. The schedule's is_available remains distinct from accepts_new_patients; live v2 rating aggregates are used instead of known-stale legacy rating cache values.
- The v2 favorites aggregates exclude appointments with active reschedule children to preserve the legacy confirmed-only semantics. Unspecified tie order in legacy top-three/primary-doctor queries is treated as equivalent only when the fixture proves a shared cutoff/top-count tie.
- getNotifications continues to use the shared NotificationService because both schemas expose the same notifications and user_preferences read contract; grouped notifications were verified for parity.

tools/test_v2_patient_portal_snapshot.php now exercises all 133 synthetic patients across profile completion, medical profile, dashboard overview, notification groups, favorites compatibility, the 14-field health snapshot, health alerts, insights, downloads and selected unified-search terms. The suite passed on the exact disposable v1/v2 fixtures. PHP lint passed for the service and test. The fixture-only checks did not opt in any endpoint or change the runtime database selection.

**Still open:** broader direct-SQL/API compatibility audit; authentication and account lifecycle flows; notification outbox/worker behavior and downstream consumers; settings and deletion flows; complete end-to-end regression. Keep v1 as runtime default until all workflows and workers have evidence-backed v2 coverage. No main/protected/hosted/NOVA database, .env or secrets were accessed. No Git staging/commit/push or deployment occurred.

## 19 November 2026 â€” Doctor management API integration

Completed repository integration for:
- `api/doctors/toggle-status.php` via the new transaction-safe `toggleDoctorActive()` operation.
- `api/doctors/delete.php` via the new archive-specific `archiveDoctor()` operation.

The archive operation preserves the `archive` audit event (not `deactivate`), keeps patient cancellation notices in the outbox transaction, and avoids the separate doctor status notification. The toggle operation remains an atomic read/transition under a doctor-row lock.

Evidence: PHP lint passed for both controllers, the repository, and the rollback contract test. `php tools/test_v2_doctor_management_rollback.php` passed on both approved v1 and v2 disposable fixtures, including rollback and AUTO_INCREMENT restoration.

Boundary: both API controllers remain explicitly pinned to `SCHEMA_V1`; v2 runtime routing/cutover is not enabled. No HTTP-level end-to-end request test was run in this checkpoint.

Next: adapt patient update/archive/cancel endpoints, then settings/availability and dashboard analytics, followed by isolated outbox worker tests and the complete application regression/cutover review.


## 19 November 2026 â€” Patient account operations integration

Completed the schema-aware patient-management repository and connected:
- `api/patients/update.php` for admin identity/demographic/status/password changes.
- `api/patients/delete.php` for patient archive.
- `api/patient/cancel-appointment.php` for patient self-cancellation.

V2 demographic edits use `patient_profiles`; password changes use the schema's password column and increment `session_version`; archive preserves clinical evidence and closes v2 reschedule children; cancellation preserves doctor-first lock ordering and writes v2 appointment event history.

Evidence: all five touched PHP files passed `php -l`. The new `tools/test_v2_patient_management_rollback.php` contract passed on both approved v1/v2 fixtures. It verified profile updates, password hash/session revocation, patient cancellation, archive, audit/event writes, transaction rollback, and AUTO_INCREMENT restoration.

Boundary: the HTTP controllers remain explicitly v1-only. `patients/update.php` still uses a v1 read/duplicate-check query before calling the repository. No HTTP-level E2E harness or notification worker delivery/retry test was run. Runtime remains v1.

Next: settings/doctor availability, dashboard analytics, then worker isolation/retry verification and broad regression/cutover readiness.


## 19 November 2026 â€” Doctor settings and admin analytics compatibility

Added the schema-aware `DoctorSettingsRepository` and connected the doctor write path in `api/settings/update.php`. V1 preserves existing behavior and also propagates consultation-duration changes into the schedule settings used by booking. V2 maps acceptance/profile visibility to `doctor_profiles` and duration to `doctor_schedule_settings`; operational `is_available` is not conflated with accepting new patients. V2 rejects the legacy global working-hours fields because its weekly hours are stored per weekday.

`DashboardAnalyticsService` now has explicit V1/V2 query mappings while preserving the existing response DTO. The admin HTTP endpoint explicitly stays in V1 mode. V2 analytics account for active reschedule child requests so they are represented separately from confirmed bookings.

Evidence: PHP syntax checks passed. `test_v2_doctor_settings_rollback.php` and `test_v2_dashboard_analytics_contract.php` both passed against approved v1/v2 fixtures. Settings mutations were rolled back; analytics checks were read-only.

Not complete: settings GET and frontend still use global V1 working-hour preferences; HTTP-level request/session tests were not run; notification-worker delivery/retry remains open. Runtime remains V1.


## 19 November 2026 â€” Self-deactivation lifecycle compatibility

Added `AccountLifecycleRepository` and routed the settings `delete_account` action through it. The adapter supports schema-specific password validation, session revocation, patient/doctor cancellation policy, unfinished clinical-work protection, and last-active-admin serialization. V2 additionally closes reschedule child rows and records appointment events.

The controller remains pinned to V1. Evidence: all touched PHP files passed lint; `test_v2_account_lifecycle_rollback.php` passed on both approved v1/v2 fixtures, including patient/doctor deactivation, four untouched cancellations, admin guard, doctor clinical-work guard, rollback and counter restoration.

Remaining: settings GET/front-end weekly schedule mapping, HTTP-level request tests, and isolated notification outbox delivery/retry verification.


## 19 November 2026 â€” Authentication, registration and outbox compatibility

Added explicit schema adapters for login credentials and reset tokens. V1 login/reset HTTP controllers remain pinned to V1, while their V2 repository implementations are fixture-tested. V2 password reset uses `password_reset_tokens` and `users.password_hash`, enforces token rotation/consumption and session-version increments, and never writes raw reset tokens.

`RegistrationIntakeService` now has explicit v1/v2 mode. V2 patient creation writes the normalized user, patient-profile, and patient-medical-profile records plus `audit_events`; registration, confirmation, resend and CLI worker entry points remain explicitly v1 for runtime. Registration email challenge confirmation is verified on both fixtures without sending mail.

A fixture-isolated notification outbox contract now covers delivered, skipped (missing recipient/disabled preference), retry/backoff and terminal failure behavior. It passed on both fixtures with synthetic rows/preferences/triggers/counters cleaned up. The production CLI worker was not run.

Evidence: PHP lint and four auth/registration tests plus the notification outbox test passed on both approved fixtures. No HTTP-level E2E or SMTP test was run. V2 remains inactive at runtime.


## 19 November 2026 â€” Settings read path and weekly-hours UI compatibility

Completed `DoctorSettingsRepository::getDoctorSettings()`, updated settings GET to return a future `weekly_schedule` DTO, and routed doctor-settings writes through the repository. Added a weekday editor to both the settings modal and standalone settings page; it appears only when seven weekday records are supplied. V1 retains its current global start/end inputs.

The V2 write path validates all seven weekdays and hospital-hour bounds, persists through ScheduleService/DoctorScheduleRepository, and rejects the legacy single-range fields. The repository keeps `accept_new_patients` separate from `operational_available`.

Evidence: PHP lint, JS `node --check`, and the doctor settings fixture contract passed. The contract includes weekly write/read, invalid all-days-off rejection without mutation, operational availability separation, V1 global-hours compatibility, and rollback on both approved fixtures.

Boundary: settings GET/update HTTP controllers remain explicitly V1; no browser/HTTP integration test or cutover was performed.
## 19 November 2026 â€” Clinical workflow and read-model integration

Implemented the schema-neutral appointment context/locking API in `ClinicalEncounterRepository` and connected visit-note, visit-draft, workflow transition, visit-completion, draft-read, prescription and medical-history timeline routes. The affected HTTP controllers still select V1 explicitly; V2 behavior is covered at the repository/service layer, with no runtime cutover.

Prescription routes now pass the schema explicitly. Prescription creation resolves appointment identity using the shared context API, while `PrescriptionService` remains the authoritative locked transaction boundary for duplicate, ownership and eligibility checks.

The medical-record reader now maps V2 split patient-profile data through `PatientPortalService` and formats V2 appointment snapshots/durations consistently. The patient portal hospital-hours formatter was corrected to return `HH:MM` rather than MySQL's `HH:MM:SS`.

Evidence: PHP lint passed for the changed files. Clinical encounter rollback, 4,256-pair access parity, patient medical-profile/history, prescription service, 133-patient portal snapshot, and 1,079-event medical timeline contracts all passed against the approved disposable fixtures. Mutating contract tests rolled back and restored counters.

**Completed in the 19 November continuation:** V2 read projections for patient EMR/print, doctor workspace, public doctor directory, admin doctor list/profile, doctor availability and rating reads. The read-only EMR contract passed for 133 patients and 32 doctors; ScheduleService parity passed for 32 doctors and 448 doctor/date pairs; settings, schedule and rating rollback contracts also passed.

**Still open:** `api/doctors/toggle-rating-visibility.php` has no V2 equivalent because V2 ratings lacks the legacy `is_hidden` flag; decide whether moderation visibility belongs in the normalized model before porting that action. Other doctor-admin mutation routes remain V1-pinned. Add authenticated HTTP/session-level tests for V2 controller branches, run final full regression/cutover-readiness review, and obtain explicit approval before runtime activation. No protected database, Git staging/commit/push, or deployment was touched.


## Rating visibility compatibility checkpoint - 19 November 2026

Implemented the V2 rating visibility model using ratings.is_public, a public-only v_doctor_rating_summary, schema-aware repository mutation/read DTOs, and transactional admin audit. The admin toggle route remains explicitly V1; V2 runtime activation is not enabled.

The additive migration is recorded on the disposable V2 prototype as 2.0.6-rating-visibility. ETL mapping now carries the inverse of V1 is_hidden when the source column exists and defaults records to public for the older approved fixture that does not contain that column. The ETL mapping version is 2.1.1-synthetic-etl.

Targeted evidence is green: rating visibility rollback/idempotency/auth/audit contract (V2; V1 read fallback), existing rating submit/aggregate rollback (V1/V2), read-only EMR/workspace contract (133 patients), and read-only patient portal snapshot (133 patients). Changed PHP files passed lint and changed review JavaScript passed node --check.

Remaining before any cutover: exercise the V1 visibility mutation against a V1 disposable fixture that actually has ratings.is_hidden; add authenticated HTTP/session tests for the updated routes; include visibility migration in any clean V2 rebuild rehearsal; continue the wider full regression and obtain explicit approval before runtime activation.


### Rating visibility HTTP checkpoint - 19 November 2026

The V1 visibility mutation gap is closed at both repository and HTTP levels. tools/test_rating_visibility_http.php authenticated against a temporary admin credential on the approved V1 fixture and passed the real login/session/toggle/read endpoints plus origin, role, method, input, hide/restore, aggregate, private/admin retention and audit checks. Its finally cleanup restored the fixture admin password/session version, removed synthetic audit entries, restored the audit counter, removed the temporary visibility column, and stopped the local server.

The fixture was verified to return to its original V1 ratings schema without is_hidden. V2 continues to pass its repository rollback contract. No runtime cutover.

## 20 November 2026 â€” Authenticated V2 read-controller HTTP acceptance

Added tools/test_v2_authenticated_http_read_contract.php and passed 62/62 assertions against healthbridge_v2_prototype_20261118. The harness copies only api/, includes/ and services/ to a temporary app root; it switches the schema selector to V2 in ten copied GET controllers and leaves the repository's actual runtime routes untouched. The temporary PHP server uses loopback only, DB environment points only to the disposable V2 prototype, and synthetic PHP sessions exercise the real requireAuth middleware without changing fixture accounts.

Accepted over HTTP: anonymous rejection; admin/doctor/patient sessions; DB-backed role refresh; stale session-version and expired-session rejection; admin/doctor/patient EMR and medical-record scope; unrelated-doctor denial; malformed-ID and method guards; doctor workspace ownership and role denial; printable EMR admin/authorized-doctor/unauthorized-doctor/patient-self access; public doctor directory and availability; admin doctor list/profile; doctor own-rating and admin rating reads with role boundaries.

Evidence: C:\xampp85\migration-backups\healthbridge\hb-v2-authenticated-http-read-20261120\RESULT.md. The first runs exposed a test-harness issue: unconsumed PHP server stdout/stderr pipes could block after enough HTTP requests. Redirecting those streams fixed it; the final clean run completed in 3.09 seconds. Temporary server, copied app and sessions were removed.

Boundary: HTTP probes were GET-only, except one authenticated POST intentionally rejected by a GET-only route. No fixture rows were intentionally mutated; no .env or secrets were read; original API routes remain V1-pinned; no main/protected database, worker, SMTP, Git mutation, deployment or cutover was used. This closes the authenticated HTTP gap for these ten V2 read controllers only. V2 mutation controllers and remaining settings/outbox/worker flows still need separate acceptance.

Next: continue the V2 application compatibility audit, prioritizing remaining role-sensitive controllers and mutation paths; preserve the current dirty worktree and obtain explicit approval before any runtime activation.

## 20 November 2026 â€” V2 controller compatibility audit

Completed a read-only census of 91 API PHP controllers and traced remaining role-sensitive V1 selectors to their repositories/services. This confirmed that many V2 domain adapters are already implemented and fixture-tested, but their existing HTTP controllers remain V1-pinned.

New/reconfirmed evidence:
- tools/test_v2_compatibility_contract.php: 11/11, read-only, 448 doctor/date reservation pairs.
- tools/test_v2_booking_reschedule_rollback.php: PASS on both fixtures through booking, request/suggest/decline/approve/reject/cancel and parent-guard transitions; transactions and counters restored.
- tools/test_v2_reschedule_read_dto.php: 5/5, read-only, 12 active requests across 11 doctors.
- tools/test_v2_authenticated_http_read_contract.php: 62/62 authenticated HTTP assertions for ten V2-compatible read controllers.

Critical integration gaps identified:
- Eight reschedule HTTP controllers still instantiate the legacy V1-only RescheduleService. Although RescheduleTransactionRepository implements V1/V2 and has repository lifecycle contracts, no API controller uses it yet.
- Generic AuditService has no schema selector and writes only to legacy admin_audit; V2 routes must use a V2 audit adapter/domain repository.
- Appointment, doctor/patient management, prescription, schedule/settings, analytics and patient portal controllers still select V1 or default to V1 even where the V2 repository/service layer exists.
- Authentication/registration/password-reset V2 contracts remain below HTTP; controllers/workers are still V1-pinned.
- V2 rating visibility is implemented with ratings.is_public, but its HTTP toggle route remains V1-pinned.

Recommended next sequence: (1) integrate RescheduleTransactionRepository into all eight controllers through a schema-explicit compatibility layer; (2) add a schema-aware generic audit adapter and inventory remaining AuditService callers; (3) add isolated authenticated HTTP coverage for appointment, management, prescription, schedule/settings, portal and analytics controllers; (4) cover V2 authentication/registration HTTP and worker behavior separately; (5) run final full regression and cutover-readiness review.

Boundary: audit/source census was read-only; contract tests used only approved loopback disposable fixtures, and synthetic write scenarios rolled back and restored counters. Original API routes remain V1. No main/protected DB, .env, secrets, SMTP, production worker, Git mutation, deployment or cutover was used.

## 20 November 2026 - Reschedule bridge implementation update

Implemented a schema-explicit RescheduleService facade for the eight reschedule routes. Default/explicit V1 construction keeps the legacy branch; explicit V2 construction delegates seven transitions and three read methods to RescheduleTransactionRepository. The eight source controllers explicitly select SCHEMA_V1. No runtime V2 route selection or cutover occurred.

Verification: PHP lint passed for the service and eight controllers; the new V2 facade rollback contract passed; the V1/V2 booking-reschedule rollback contract passed; the reschedule DTO parity contract passed 5/5; the transition rollback contract passed; and authenticated V2 read HTTP regression passed 62/62. The new facade test rolled back the caller-owned V2 transaction and restored counters.

Next: add authenticated HTTP mutation coverage in a copied app against a separate disposable V2 database clone; then implement generic AuditService V2 compatibility and proceed through remaining V1-pinned controller families.

## 2026-11-20 â€” Reschedule route integration checkpoint

The eight reschedule controllers now resolve their schema through HEALTHBRIDGE_RESCHEDULE_SCHEMA, defaulting to V1. V2 opt-in is implemented through the existing tested RescheduleService facade. Syntax, selector contract (5/5), and V2 facade lifecycle rollback tests passed. Authenticated HTTP mutation coverage in a disposable V2 clone remains a release gate; no runtime cutover has occurred.
## 2026-11-20 â€” Reschedule HTTP mutation acceptance completed

The eight reschedule endpoints now support V2 selection through HEALTHBRIDGE_RESCHEDULE_SCHEMA while remaining V1 by default. Authenticated V2 HTTP mutation test tools/test_v2_reschedule_authenticated_http.php passed 35/35 assertions against a full disposable database clone. It verified the route role boundaries, pending-list reads, all reschedule lifecycle transitions, final schedule, audit and notification/outbox effects. The temporary clone was dropped and the retained source fixture fingerprint (selected row counts and AUTO_INCREMENT values) was unchanged. V2 runtime cutover remains out of scope and unapproved.
## 2026-11-20 â€” Application compatibility expansion checkpoint

Implemented the schema-aware generic audit adapter and added the shared explicit V1-default schema selector. Routed existing V2-capable controller/service paths for appointments, doctor/patient management, clinical/portal, schedules, prescriptions, ratings, analytics, authentication repositories, audit and rescheduling. Evidence: 101 PHP syntax checks, 25/25 dual-schema audit assertions, 82 authenticated V2 GET assertions, 13 V2 domain regression suites, and 54/54 authenticated HTTP mutation assertions against a unique disposable clone (booking/approval/decline, schedule update/read/slots, rating visibility and reschedule). Remaining blockers include mixed settings SQL, worker/SMTP integration, auth/registration HTTP acceptance and selected controller mutation acceptance. Main application remains V1 by default; no cutover is authorized or performed.
## 2026-11-20 â€” V2 API compatibility expansion complete for this slice

Added V2-aware department service and route branches, patient audit-history projection from audit_events, V2 settings/hospital settings selection, and V2 projections for general/doctor appointment reads, printable appointments and admin patient profiles. Latest evidence: 135 PHP files lint clean; git diff --check PASS; audit adapter 25/25; authenticated V2 read contract 115 assertions; authenticated V2 mutation contract 86 assertions against a unique disposable full clone. Department CRUD/assignment/counts and patient audit role/scope are now included. V1 remains the default. Open gates: full registration/email/password settings HTTP flows, background workers/SMTP, and remaining route-level mutations for doctor/patient/prescription domains.

## 20 November 2026 - HB-DB-007 Phase 8 historical migration acceptance

The Phase 8 audit-table path is now reproduced and has a tested, narrowly scoped bridge:

- The historical 21-table Git baseline at 14b161e7366de82a69e7ed487a5d75c6bfd83d45 already includes Phase 6/7 structures. A disposable fixture applied Phase 8 Steps 2-3 and Phases 9-12 with synthetic audit sentinels.
- Unmodified Phase 13 failed exactly at the absent fk_pal_actor. Its prior DDL had already removed 24 expected FK constraints before failure, leaving six unrelated FK constraints in the captured dump. Phase 13 is not atomic on this path; any error requires restore from the pre-migration backup rather than an in-place retry.
- Added repository-owned read-only preflight_phase13_phase8_audit_fk_bridge_readonly.sql and phase13_legacy_phase8_audit_fk_bridge.sql. The preflight executed successfully on the historical clone and verified the exact Phase 8 columns, all 24 non-audit FK names, no existing FK on the four audit columns, zero orphans and four distinct supporting indexes.
- The RESTRICT bridge then added the four missing audit FK names. The existing Phase 13 completed after it: all 28 expected FK names present, four audit FKs RESTRICT, legacy audit sentinel columns/JSON/NULL descriptions/TIMESTAMP preserved, mysqlcheck passed all 25 tables. Both disposable clones were dropped.
- Runtime evidence: C:\xampp85\migration-backups\healthbridge\hb-db-007-phase8-rehearsal-20261120\RESULT.md and the detailed entry in docs/HEALTHBRIDGE_QA_EVIDENCE_INDEX.md.

This closes only the exact Phase 8 audit-FK compatibility gap. HB-DB-007 remains open for the durable migration ledger/state tracker, wider mixed-schema/orphan preflight, Phase 8-to-V2 audit ETL mapping/timezone semantics, full backup/restore acceptance and explicit cutover gates. Do not run Phase 13 directly on the legacy Phase 8 shape. V1 stays the runtime default; no cutover or main-database migration is authorized.


## 20 November 2026 â€” Phase 8 audit ETL compatibility accepted

The earlier static HB-DB-007 note that the V2 ETL could not handle Phase 8 audit tables is now superseded for the exact tested audit signature. ETL mapping contract 2.1.2 and V2 migration 2.0.7 were exercised against separate disposable source/target clones.

- Patient audit: Phase 8 entity_type/entity_id mapped into the V2 legacy archive; field_name remains NULL.
- Schedule audit: Phase 8 change_type retained in legacy_payload.
- Nullable Phase 8 descriptions retained in both archives; JSON old/new values and IP/user-agent fields preserved.
- TIMESTAMP source columns read with session time_zone=+00:00; V2 DATETIME values matched the UTC instants.
- ETL PASS: 3,468 lineage mappings, 100 accepted legacy warnings, zero blocking exceptions, and both 2.0.7 and 2.1.2 migration entries present.
- Source before/after dump fingerprint matched; mysqlcheck passed; both clones were dropped and absence verified.

Evidence: C:\\xampp85\\migration-backups\\healthbridge\\hb-v2-phase8-etl-rehearsal-20261120\\RESULT.md.

Scope: this was a focused Phase 8 audit-signature fixture using the otherwise modern synthetic V1 source model. It is not a complete full-database migration from a raw Phase 8 installation. HB-DB-007 remains open for the durable migration ledger/state model, broader legacy signature/orphan preflight, full restore rehearsal, remaining route acceptance and explicit cutover/rollback approval. Current runtime remains V1.


## 20 November 2026 â€” V1 migration ledger design checkpoint

The V1 Phase 10â€“17 migration chain has no durable ledger in the inspected baseline. The existing V2 schema_migrations table is a successful-version/checksum receipt, not an attempt journal. Phase 13 runtime evidence confirms the need for explicit step tracking because DDL may be partially committed before failure.

A proposed recovery-aware design is documented in docs/HEALTHBRIDGE_MIGRATION_LEDGER_DESIGN.md. It separates canonical successful receipts, append-only runs and per-step events; adds baseline signature observations without inventing historic execution; and requires a DB-scoped lock, exact checksums, pre/post signatures, verified backup reference and explicit recovery review before replay.

No ledger DDL or runner has been implemented. The next gate is a disposable-clone prototype with injected mid-Phase-13 failures, interrupted-run and concurrent-run tests. HB-DB-007 remains open; V1 is unchanged and V2 remains uncutover.


## Milestone 2 checkpoint â€” 20 November 2026

The migration-ledger design now has a disposable-only implementation prototype. The synthetic runner passed success, idempotency, failure/replay blocking, advisory-lock contention and checksum-tampering checks. The Phase 8 bridge (2 steps) and Phase 13 migration (32 steps) were then journaled on two fresh historical clones: one completed all 34 steps and passed the 28/28 RESTRICT-FK postflight; the second was fault-injected after Phase 13 step 16 and automatic replay was blocked. Evidence is in `C:\xampp85\migration-backups\healthbridge\hb-db-ledger-phase13-20261120\`.

This closes the Phase 13 ledger-prototype gate for the exact tested schema lineage only. Still open for Milestone 2: network-level TCP severance test (abrupt CLI process-loss after committed DDL is now accepted), broader mixed historical schema/orphan preflight, broader mixed historical-schema variants beyond the accepted Phase 8â†’13â†’17 lineage, remaining local recovery/rollback rehearsal (the separate V2 logical restore had already passed), remaining route mutation coverage, complete registration/email/password-reset HTTP and worker checks, full regression, and V1 rollback rehearsal. No main or hosted database was changed; V1 remains the application default and V2 remains opt-in.


## Phase 10â€“17 schema-state classification â€” 20 November 2026

Added `tools/qa/inspect_phase10_17_schema_state.php`, a CLI-only, local-loopback, read-only classifier restricted to the approved synthetic V1 fixture `healthbridge_hb_auth_email_verify_20261114`. It records independent phase states and aggregate safety counts only; it does not reveal phone/token values, execute migrations or claim migration chronology.

Observed on the fixture (MariaDB 10.11.16): Phases 10 through 17 all `verified_present`. Signatures: Phase 10 11/11 reschedule columns and 5/5 indexes; Phase 11 session/reset fields and 3/3 expected indexes; Phase 12 follow-up instructions; Phase 13 28/28 RESTRICT FKs; Phase 14 stored generated normalized phone column + unique index; Phase 15 outbox 13/13 columns, 2/2 indexes, expected ENUM and InnoDB collation; Phase 16 encrypted queue 16/16 columns and 4/4 indexes; Phase 17 verification fields, queue enum extensions, 11/11 challenge columns, both challenge indexes and CASCADE FK.

Aggregate checks: zero nonempty legacy raw reset tokens, zero duplicate raw-token hash groups, zero Phase 13 audit orphans, zero duplicate normalized-phone groups, and zero orphan email challenge queue rows.

Phase 16 checks allow the documented Phase 17 status-enum superset (`awaiting_verification`, `expired`) while requiring all original Phase 16 values. Report: `C:\xampp85\migration-backups\healthbridge\hb-db-ledger-phase13-20261120\phase10-17-state-classification.json`.

This closes only the read-only state-classification gate for that one synthetic fixture. It does not prove Phase 10â€“17 chronology, all mixed legacy variants, Phase 14â€“17 migration replay, V2 restore, application regression or cutover readiness.


### V2 logical restore status clarification â€” 20 November 2026

The V2 logical backup/restore equivalence check was already accepted on 18 November 2026. The completed restore target `healthbridge_v2_restore5_20261118` matched all 37 table-data fingerprints, 379 column definitions, 178 index rows, 42 FK constraints/48 FK-column mappings, 23 CHECK constraints and one view; engine/collation and AUTO_INCREMENT values matched, and `CHECK TABLE` passed 37/37. This is recorded in `database/v2/README.md`. Do not count the V2 logical restore itself as an outstanding Milestone 2 task. Any remaining V1 rollback/recovery rehearsal is a separate gate; no cutover is authorized.


## Operator acknowledgement helper â€” 20 November 2026

Added `tools/acknowledge_registration_queue_age_alert.ps1`. The CLI is restricted to the exact local production queue-age log directory or the named QA evidence directory. It records the current Windows identity as owner/acknowledger, an ISO timestamp and a short operator note via atomic marker replacement, then appends an `ACKNOWLEDGED` audit entry. It leaves alert status OPEN; only the successful RESOLVED delivery lifecycle clears the marker. Repeating the acknowledgement by the same identity is an idempotent no-op. A different identity cannot silently take ownership; reassignment is deliberately not implemented.

QA passed against the synthetic scheduled-task marker: status remained open, owner/time/note persisted, one ACK audit entry was written, and a repeated same-identity invocation created no duplicate entry. No production marker or task was accessed. This records operator acknowledgement capability but does not prove a human checks alerts or provide multi-operator reassignment; reminders continue until resolution.


## Milestone 2 high-severity disposition review â€” 20 November 2026

This is a local milestone disposition only. Hosted resources were not inspected or changed during this review.

| Finding | Local disposition | Remaining category | Accountable owner | Next action | Residual impact |
|---|---|---|---|---|---|
| HB-SEC-001 | Local clinical-access/session controls have repeatable seven-route, browser and role-boundary evidence (41/41 HTTP, 16/16 browser, 8/8 supplemental). | Hosted/release verification only. | Deployment operator. | After an authorized deployment, repeat the same role/ownership/session checks against staging with synthetic accounts. | Production policy/configuration may differ from the verified local build; no application-wide privacy clearance is claimed. |
| HB-SEC-002 | Local admin contact-inbox role/current-account/session checks passed (18/18 route and 9/9 lifecycle). | Hosted/release verification only. | Deployment operator. | Verify the protected inbox route and stale-session behavior after deployment. | Hosted auth/session configuration remains unverified. |
| HB-SEC-006 (P0*) | Archived executors are removed from the local working tree and local Apache deny rules are present. | Hosted filesystem state is unverified; blocked by hosting-account access/authorization. | Hosting account operator. | Verify whether the historical PHP executors remain remotely reachable; if present, remove/disable and retest their paths. | A remotely reachable historical migration executor could permit unauthorized schema/data changes. This remains a P0* candidate until remote status is proven. |
| HB-SEC-007 (P1) | Local web-root boundary checks passed 29/29; hosted root protections were partially applied in the earlier authorized session. | Hosted-only verification; four dotfile/no-status paths and remote historical executor status remain unknown. | Hosting account operator. | Verify `.env`, `.git` internals, `.htaccess`, internal source/docs/tools and archived migration paths without exposing response bodies or secrets. | Potential source/configuration disclosure remains unexcluded on the hosted tree. |
| HB-DB-001 (P1) | Local destructive fresh-install guard and synthetic logical restore evidence passed (26/26 tables and checks). | Hosted/off-site backup and recovery are external release gates. | Database/deployment operator. | Before live use, take and restore-test an off-site backup under an approved recovery procedure. | A hosted failure may be unrecoverable until off-site recovery is demonstrated. |
| HB-DB-002 (P1) | Local doctor/department ownership and booking validation passed service, HTTP and browser acceptance (28/28 HTTP, 26/26 browser). | Hosted/release verification only. | Deployment operator. | Re-run the doctor/department mismatch and inactive-entity rejection cases on an authorized staging dataset. | Production data/configuration could expose different legacy mapping conditions. |
| HB-AUTH-001 (P1) | Local generic response/timing, transactional registration, queue encryption/retry and email verification contracts have repeatable synthetic evidence. The genuine stale-queue SQL observation ran through a temporary scheduled task against a disposable clone; the new acknowledgement command records local owner/time/note. | Main-schema activation is blocked by the separate backup/restore/migration approval; multi-host limiter/proxy/keyring coordination, production SMTP/TLS and phone-proof policy are deployment/provider dependencies. | Project maintainer for local schema approval; deployment operator for hosted readiness. | Obtain explicit approval before any main `healthbridge` Phase 17 migration; after approval, back up and restore-test first. Before multi-host deployment, validate shared limiter, trusted proxy, keyring and worker coordination; decide email/phone proof policy. | The main local database remains intentionally fail-closed for the Phase 17 registration flow until separately approved. Hosted account-abuse and verification behavior is not proven. |
| HB-SEC-008 (P1) | Third-party contact forwarding was removed locally and the privacy/data-flow browser regression passed. | Hosted/release verification only. | Deployment operator. | Confirm the deployed bundle sends contact submissions only to the same-origin HealthBridge endpoint. | A stale hosted bundle could preserve the previous third-party disclosure path. |

### Milestone 2 local exit review

- No locally reproducible P0/P1 defect is currently known from the accepted evidence; the remaining high-severity items above are explicitly hosted-only or blocked by a separate external/approval dependency.
- Critical local session/role, notification transaction/worker and database failure/recovery controls have repeatable test artifacts in the QA Evidence Index and handoff.
- The 20 November ledger/Phase 13 rehearsal adds a proven recovery block for partial DDL on the exact Phase 8 lineage. The temporary stale-queue task and acknowledgement tests add repeatable local operator-path evidence.
- P2 items remain open and are not represented as fixed: broader HB-SEC-010 low-traffic sink coverage, mixed historical-schema/Phase 14â€“17 variant rehearsals, and the unconfirmed historical worker/MariaDB availability explanation. These do not satisfy or invalidate the P0/P1 disposition criterion by themselves, but remain tracked for the later engineering/final-regression milestones.
- No protected or hosted data was changed. V1 remains the application default; no V2 cutover is authorized.

This is an exit-criteria review, not a final milestone-completion sign-off. The final local regression/freeze pass and explicit owner acceptance of the above external dispositions are still required before Milestone 2 is marked complete.


## 20 November 2026 â€” Phase 14â€“17 historical migration acceptance and collation repair

The Phase 14â€“17 chain was rehearsed from the retained Phase 8/Phase 13-success dump on a fresh disposable clone. Phase 14 (stored generated phone normalizer + unique index), Phase 15 (notification outbox), and Phase 16 (encrypted registration queue) applied successfully. The first Phase 17 attempt then reproduced MariaDB error 1270 on the `ALTER TABLE users` statement: mixed collations in the Phase 14 stored generated `phone_normalized` expression were reevaluated during the later table alteration.

Historical files were preserved unchanged. Added `database/migrations/phase14b_phone_normalization_collation_compat.sql` as a forward compatibility migration to run after Phase 14 and before Phase 17. It redefines the same generated formula while explicitly converting and collating every text operand as `utf8mb4_unicode_ci`, matching the collation-deterministic strategy already used by V2 migration 2.0.5. SHA-256: `732A44BFC7FB39C2B03827386EF35076CE66BC5C8B20F538BD1EBFFDAC6B61E4` (see the file for the exact bytes; verify the checksum before applying).

A fresh repeat then passed in this order: Phase 14 â†’ Phase 15 â†’ Phase 16 â†’ Phase 14b â†’ Phase 17. Evidence (`C:\xampp85\migration-backups\healthbridge\hb-db007-phase14-17-20261120\COLLATION_FIX_RESULT.md`): Phase 14 generated column/index 1/1; duplicate normalized groups 0; Phase 15 13 columns/2 indexes/InnoDB; Phase 16 16 original columns/4 indexes/InnoDB; Phase 17 2 user verification columns, 3 queue columns, 11 challenge columns, 2 challenge indexes and the expected CASCADE FK. The normalized-phone output SHA-256 remained identical across the collation repair. All 13 pre-existing synthetic users remained `email_verification_required=0` with `email_verified_at IS NULL`; core user-data SHA-256 matched before/after; registration queue stayed empty; challenge orphans=0; both Phase 8 audit sentinels remained 1/1; 28/28 named Phase 13 FKs remained. `mysqlcheck` passed after the complete chain.

Both failed/accepted rehearsal clones were dropped and verified absent. No main `healthbridge`, NOVA, hosted DB, retained V2 prototype or production task was changed. Do not run Phase 17 on a Phase 14 installation in this historical lineage until Phase 14b has been applied and its pre/post signatures verified. This accepts only the tested Phase 8â†’13 lineage, not every mixed legacy schema variant; the V1 migration ledger runner also remains a prototype, not an approved main-database executor.


## Abrupt DDL process-loss acceptance â€” 20 November 2026

A separate disposable ledger target tested the window after MariaDB commits DDL but before the runner writes `step_succeeded`. A PHP process acquired the per-database advisory lock, inserted a `running` attempt and `step_started` event, executed the synthetic `ALTER TABLE`, then exited immediately with code 77 before recording success. After the connection closed, a new PDO process confirmed the column existed, the attempt remained `running`, exactly one start event existed and no successful receipt existed. The standard runner then acquired the released lock but refused to replay the unresolved attempt. The schema was dropped and independently verified absent.

Evidence: `C:\xampp85\migration-backups\healthbridge\hb-db-ledger-phase13-20261120\CONNECTION_LOSS_RESULT.md`. This validates abrupt CLI process loss after committed DDL on local MariaDB; a network-level TCP severance was not separately fault-injected. No main database or protected fixture was changed.

## Milestone 2 local exit review â€” 20 November 2026

**Local disposition: exit criteria met on current evidence; formal owner acceptance of external dispositions is pending.** This decision uses the four exit criteria in this plan, not a claim of production readiness or a completed V2 cutover.

- **No unresolved locally reproducible P0/P1 is currently known.** The remaining P1/P0* findings are explicitly classified as hosted-only or externally blocked in the disposition table above, with accountable owner, action and residual impact. HB-SEC-006 remains a P0* candidate until remote historical executor status is verified; HB-SEC-007 remains P1 until the unknown hosted paths are checked. These remote checks were not attempted in this local milestone.
- **Repeatable local evidence exists for critical security/session/notification/database failure checks.** Existing authenticated authorization/session suites, notification producer rollback and worker crash/retry/skip/retention tests, local install/restore checks, the stale-queue scheduled-task and acknowledgement rehearsal, Phase 13 ledger success/fault recovery, abrupt process-loss after committed DDL, and the Phase 8â†’17 historical migration/Phase14b collation compatibility rehearsal are indexed in `docs/HEALTHBRIDGE_QA_EVIDENCE_INDEX.md` and the evidence folders under `C:\xampp85\migration-backups\healthbridge\`.
- **Final source hygiene check:** 199 non-vendor PHP files linted with zero failures; the new PowerShell acknowledgement tool parsed; `git diff --check` passed. All temporary databases and QA scheduled tasks created in this session were verified absent. The actual HealthBridge registration and notification scheduled tasks remained Ready and were not invoked or modified.
- **Protected/hosted data remained untouched.** No `.env` values were inspected, no production SMTP was used for the stale-queue QA run, no hosted database/resource changed, no main `healthbridge` or NOVA rows/schemas were written, and V1 remains the application default. No files were staged, committed, pushed or deployed.

**Explicit P2 follow-ups (not represented as closed):** broader low-traffic HB-SEC-010 output/URL-sink coverage; authenticated simultaneous HTTP contention for the HB-DB-003 sole-admin race (the two-connection InnoDB lock test passed after the fix); broader mixed historical-schema variants beyond the accepted Phase 8â†’13â†’17 lineage; and the historical worker/MariaDB unavailability explanation, which remains unconfirmed. These remain in the findings register/roadmap for later scoped work and final regression; they do not constitute a known local P0/P1 blocker under the current Milestone 2 exit criteria.

**Owner acceptance still needed:** acknowledge the hosted/external deferrals in the disposition table before treating the milestone as formally closed. This local review does not authorize deployment, hosted inspection, main-database migration, or V2 cutover.

## Milestone 2 owner acceptance and closure — 20 November 2026

**Owner decision:** The project owner accepts the documented external/hosted P0*/P1 dispositions for deferral to the final deployment-readiness stage. Milestone 2 is therefore **formally closed for its local security and reliability scope** on 20 November 2026.

**Important status distinction:** Acceptance of deferral is not remediation, verification, or closure of any individual finding. All hosted/external P0*/P1 findings retain their original severity and remain **OPEN — HIGH PRIORITY**. They must be revisited during deployment readiness and treated as release gates before production use. In particular:
- **HB-SEC-006 (P0*)** — verify the hosted server contains no accessible archived migration/executor files; remove any copies and prove they are inaccessible.
- **HB-SEC-007 (P1)** — verify hosted denial of access to environment files, Git metadata, source, internal tools and executor paths.
- **HB-SEC-001 / HB-SEC-002 / HB-SEC-008 (P1)** — verify deployed authentication/session boundaries, admin authorization and privacy/contact-forwarding behavior.
- **HB-DB-001 / HB-DB-002 (P1)** — verify off-site backup/recovery and deployed booking/department ownership integrity.
- **HB-AUTH-001 (P1)** — verify production HTTPS/APP_BASE_URL, SMTP/TLS, keyring, trusted-proxy and shared multi-host limiter/worker configuration; resolve the documented email/phone ownership policy before enabling the intended registration scope.

No hosted resources were inspected or changed as part of this closure. This decision does not authorize deployment, hosted checks, database migration, or V2 cutover. V1 remains the application default; all future hosted work requires the appropriate deployment-stage authorization.


## Milestone 3 update — five representative prototypes validated (20 November 2026)

M3.0 discovery/baseline is complete; the M3.1 UX architecture and M3.2 visual-system drafts are documented in their dedicated files. M3.3 now has five isolated reference prototypes outside Apache docroot: Patient Dashboard, Doctor Dashboard, Admin Overview, Public Doctors Directory and Authentication/Booking.

The first three dashboard prototypes passed 24 zero-violation axe WCAG 2.0/2.1 A/AA scans. The directory and auth/booking prototypes passed 38 additional scans, including their interactive states in both themes, eight responsive viewport checks, zero horizontal overflow, 18/18 scripted interactions and zero browser page errors. Combined result: 62 zero-violation scans across five prototypes. Detailed evidence is in `C:\\xampp85\\migration-backups\\healthbridge\\milestone3-prototype-20261120\\`, including `PUBLIC_AUTH_RESULT.md` and the structured JSON/test scripts.

M3.3 technical prototype validation is complete. Owner review remains open, so global token values are still provisional. **Next: M3.4 shared frontend foundation on the main local website** — first inspect shared PHP shells, CSS loading/ownership and affected page callers; back up exact files before changing them; apply the design in small increments and validate existing routes, role/session behavior, APIs, responsive layouts and accessibility after each increment. Preserve PHP-rendered HTML + vanilla JS/CSS. Do not copy prototype files wholesale, change backend/database/workers/email, inspect secrets, stage/commit/push or deploy. Existing workers remain disabled and hosted P0*/P1 findings remain high-priority deployment gates.

## 20 November 2026 — M3.5 About, Services and Contact redesign completed

This is the latest M3.5 checkpoint and supersedes the previous next-action note that listed About, Services and Contact as still outstanding. The three live public information pages have now been rebuilt as honest HealthBridge student-project demonstration pages:

- **About:** redesigned project story, student-project framing, design principles, synthetic role workspaces and project-navigation CTAs.
- **Services:** redesigned capability catalogue with six Patient/Clinician/Admin cards, accessible role filters, live result status, connected-workflow section and truthful project FAQ.
- **Contact:** redesigned project-feedback page. Removed fictional emergency phone, physical hospital address, clinic directory, unsupported medical-service claims, fabricated social links and the map to a fictional site. The existing contact form remains available for synthetic project feedback only.

The form retains its existing IDs/names and same-origin request contract. assets/js/pages/contact.js and api/settings/contact.php were not modified in this group. The API only stores contact messages; no email is sent by this endpoint. QA used a blank form submit solely to verify client-side required-field validation. **No POST was recorded.**

**Final automated evidence:**
- 3/3 routes HTTP 200.
- 24/24 axe WCAG 2.0/2.1 A/AA scans, zero violations.
- 24/24 responsive layouts at 320/390/768/1440 px in dark/light themes, zero horizontal overflow.
- Services: all six cards, four filters, filter status/aria states and FAQ state synchronized; four keyboard activation checks passed (Enter/Space for filters and FAQ).
- Contact: field contract preserved, empty validation passed, no submit; no map or Leaflet assets.
- Internal links: About 22, Services 25, Contact 18 anchors; seven unique internal targets checked per page, all HTTP 200.
- Zero browser exceptions, non-CSP console errors, local HTTP errors or POST requests. PHP syntax check for the existing contact endpoint passed.

Result: docs/HEALTHBRIDGE_MILESTONE_3_PUBLIC_INFORMATION_PAGES_RESULT_20261120.md.
Structured results: C:\xampp85\migration-backups\healthbridge\milestone3-public-info-20261120-1525\public-info-results.json.
Repeatable test scripts: D:\dev-toolkit\scripts\healthbridge-m3-public-info-audit.mjs, healthbridge-m3-public-info-link-audit.mjs, and healthbridge-m3-public-info-keyboard-audit.mjs.
Pre-edit rollback copies and SHA-256 manifest: C:\xampp85\migration-backups\healthbridge\milestone3-public-info-20261120-1525\.

No backend/API/auth/session/CSRF/RBAC/role/booking logic, database schema/data, SMTP, workers, secrets, hosted services or Git state was changed by this page group. No staging, commit, push or deployment occurred.

**Next M3.5 page group:** Patient portal. Re-map the page's routes, shared scripts, role checks and API contracts; back up each changed file; redesign the actual patient experience page by page and test synthetic/read-only flows only. Then continue with Doctor workspace and Admin area. M3.4 tokens remain provisional. Manual owner, keyboard-only and assistive-technology review remains open. Hosted P0*/P1 gates remain high-priority deployment blockers.

## Follow-up compatibility verification

A final contract review found that the previous Services controller also performed server-session synchronization when no local browser identity was present. That behavior was retained: the redesigned Services controller calls the existing getSessionUser helper when getUser returns no local identity, then changes the six capability links and bottom CTA to the role dashboard only when a user is returned.

- Isolated synthetic role-route audit passed for patient, doctor and admin. All six capability links and the bottom CTA point to the correct role dashboard; the patient route retains the existing #book fragment. These tests set only a synthetic hb_user value in disposable Playwright contexts and perform zero current-user requests and zero POSTs.
- In the unauthenticated public audit, the existing current_user.php GET was observed once and returned HTTP 401 as expected for a guest. The response and associated browser resource message are recorded separately in public-info-results.json; they are not classified as an unexpected application console/HTTP error.
- No authentication operation, sign-in or role-protected page access was performed.

Additional repeatable test: D:\dev-toolkit\scripts\healthbridge-m3-public-info-role-routing-audit.mjs
Keyboard test: D:\dev-toolkit\scripts\healthbridge-m3-public-info-keyboard-audit.mjs
