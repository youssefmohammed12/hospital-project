> **Superseded — 24 November 2026.** The refactor scoped here (5 November 2026) has been completed; the application now runs entirely on the V2 codebase. Kept as a historical record of the V1 → V2 build. For the current system, see `README.md` and `docs/ARCHITECTURE.md`.

# HealthBridge — Portfolio Refactor Scope

**Decision date:** 5 November 2026  
**Project:** `C:\xampp85\htdocs\optimized`  
**Working branch:** `repair/healthbridge-staged-hardening`  
**Purpose:** Define the approved scope for repairing, refactoring, verifying, and presenting the existing HealthBridge application as a portfolio project.

## 1. Mission

Make the existing HealthBridge application reliable, secure, maintainable, testable, reproducible, and professionally presentable as a **portfolio demonstration**. The goal is to demonstrate the quality of the existing PHP/MySQL and vanilla-JavaScript application, not to expand its product scope or build a complete real-world hospital platform.

### Product boundary: a portfolio-grade demo, not a real hospital

The finished product should look and behave like a credible, polished hospital-management application demo that can be explained, explored with synthetic demo accounts, and showcased in a developer portfolio. It is not intended for real hospitals, real patients, real medical records, clinical decisions, emergency response, or day-to-day healthcare operations.

This is a deliberate scope limit, not an excuse for weak engineering. Security and reliability must be professional within the implemented demonstration scope: protect authentication and sessions, enforce role/resource ownership, validate inputs, safely render output, use prepared database access and correct transactions, protect state-changing requests, restrict secrets, avoid unnecessary exposure of sensitive data, and provide appropriate audit/error behavior. Close material P0/P1 risks in implemented workflows; document rather than silently ignore meaningful residual risks.

Do not attempt to reproduce every department, specialty, enterprise integration, billing/insurance process, inventory, laboratory, imaging, pharmacy, clinical governance mechanism, disaster-recovery system, or operational requirement of a real hospital. Add none of these merely to make the demo look larger. Any future addition must have a clear portfolio value, fit the existing scope, and receive separate approval.

All demo records and test identities must be synthetic. The README, login/demo experience, and portfolio case study must explain that HealthBridge is an educational portfolio demonstration and is not certified or approved for healthcare use. Never claim production readiness or HIPAA, Egyptian regulatory, or other formal healthcare compliance.



**This is a refactor-and-repair project, not a feature-development project.** Preserve the current product capabilities and improve how correctly, safely, efficiently, and consistently they work.

## 2. Work that is in scope

- Fix reproducible defects in existing pages, APIs, services, SQL interactions, and workflows.
- Improve authentication, authorization, resource ownership, input validation, session handling, privacy, and safe error behavior.
- Protect database and workflow integrity through correct transactions, row locks, constraints, idempotency, and rollback behavior.
- Improve performance only where profiling, query inspection, or repeatable measurements demonstrate a real issue.
- Refactor duplicated or oversized code when responsibilities are clear and regression coverage is sufficient.
- Remove obsolete code, routes, assets, or scripts only after static, dynamic, historical, and external-reference checks justify removal.
- Improve accessibility, responsive behavior, consistency, and usability of existing screens without adding new user journeys.
- Add repeatable automated tests and documentation for existing behavior.
- Improve setup instructions, architecture documentation, screenshots, and repository presentation. Keep all demo data synthetic and all claims evidence-based.

## 3. Explicitly out of scope

Do not add:
- New product modules, pages, endpoints, roles, or user-facing capabilities.
- New booking, clinical, administrative, notification, or account workflows.
- New external integrations or services that expand what the product does.
- New user-visible security steps such as CAPTCHA or contact-ownership verification without first documenting the risk, options, and obtaining approval.
- Features merely because a library makes them easy to implement.

A security or reliability gap that cannot be fully addressed without a user-visible behavior change must remain documented with a proposed decision. Do not silently weaken security, but do not silently expand product scope either.

## 4. Selective library strategy

Use existing PHP, PDO, Composer, and vanilla JavaScript patterns where they are sufficient. Do not migrate to Laravel, Symfony Framework, an ORM, or a frontend framework as part of this project.

A focused library may be adopted only to improve the implementation, safety, documentation, or verification of an existing capability. Candidates for evaluation—not automatic installation approvals—are:

| Candidate | Potential maintenance benefit | Adoption condition |
|---|---|---|
| `phpunit/phpunit` | Repeatable unit and service regression tests | First resolve the PHP runtime/support target; select a maintained PHPUnit line compatible with the selected runtime and existing extensions |
| `symfony/validator` | Consistent validation for existing API inputs | Adopt only if the current shared validation helpers cannot express existing rules cleanly; avoid running two competing validation systems |
| `zircote/swagger-php` | Maintainable OpenAPI documentation for existing routes | Document the current API contract; do not create or expose new endpoints |
| `monolog/monolog` | Structured, consistent operational logging | Adopt only if a real logging gap is demonstrated; redact identifiers, tokens, credentials, and clinical content |
| `doctrine/migrations` | Versioned, repeatable forward schema changes | Defer until a verified backup/restore rehearsal and current schema baseline exist; never replace or invoke destructive fresh-install scripts as an upgrade path |

### Mandatory library-adoption gate

Before adding any package:

1. Record the concrete existing defect or maintenance problem it solves and the affected files.
2. Check the project's actual PHP version, required extensions, package support lifecycle, transitive dependencies, security advisories, license, and Composer compatibility.
3. Confirm the candidate does not duplicate an existing helper or create competing patterns.
4. Add one focused dependency at a time; review both `composer.json` and `composer.lock`.
5. Add regression tests and compare behavior before/after adoption.
6. Confirm no new product behavior or user-facing workflow was introduced.
7. Document why the package was selected, its boundary, tests, and any operational implications.

If the existing implementation is adequate, do not add a package. A smaller dependency tree is preferable to a library without a measurable benefit.

## 5. Architecture and compatibility guardrails

- Keep PDO and prepared statements; do not introduce an ORM by default.
- Keep business rules in the current service/domain layer and HTTP concerns in endpoint handlers.
- Prefer small, reviewable refactors over mass file moves or broad formatting changes.
- Preserve existing endpoint paths, request/response contracts, permissions, and business rules unless a verified defect requires a correction.
- Avoid changing multiple layers in one step unless the end-to-end behavior requires it.
- Do not remove dormant maintenance code solely because it has no static caller; check operational intent and external contracts first.
- Treat the local PHP 8.2.12 environment as a compatibility constraint that must be revisited before choosing long-lived testing/developer dependencies.

## 6. Portfolio presentation acceptance criteria

HealthBridge is ready to be presented as a strong portfolio project when the evidence supports all of the following:

- README accurately explains the problem, existing capabilities, architecture, technology stack, setup, and limitations.
- Setup instructions distinguish fresh demo installation from non-destructive upgrades and explain database backup/restore requirements.
- Screenshots show real current screens with synthetic data, consistent cropping, readable detail, and no secrets or personal information.
- A concise architecture/data-flow diagram and selected workflow explanations reflect the actual implementation.
- Key existing journeys have repeatable regression evidence for intended roles, including important negative authorization cases.
- PHP/JavaScript syntax, Composer validation, focused automated tests, and repository hygiene checks pass.
- Performance and accessibility claims are backed by measurements or explicit test evidence.
- No unresolved critical issue is hidden; remaining high-risk gaps and unverified deployment assumptions are disclosed.
- No false production-readiness, HIPAA, Egyptian regulatory, or other formal compliance claim is made.

## 7. Working sequence

1. Complete the remaining route, authorization, workflow, schema, and deployment-boundary reviews.
2. Close high-priority defects with focused tests and safe local HTTP/service validation.
3. Standardize existing validation, response, transaction, authorization, and logging patterns.
4. Refactor large modules incrementally where tests and clear boundaries justify it.
5. Evaluate selective libraries against the gate above; install none merely as a precaution.
6. Complete existing-feature regression coverage and repeatable local setup/restore evidence.
7. Polish README, architecture material, screenshots, and the portfolio case-study evidence.
8. Review the complete diff and update the handoff before any separately authorized commit or release.

## 8. Safety and repository state

- Current repair work is uncommitted. Do not reset, clean, stash, overwrite, or switch branches.
- Never expose `.env` values, credentials, reset tokens, national IDs, or real clinical data.
- Never run `database/healthbridge.sql`, `healthbridge_empty.sql`, or `healthbridge_demo_data.sql` against a populated database.
- Test mutations only against disposable databases containing synthetic data; verify a backup and restore before schema writes.
- No hosted database, deployment, commit, or push is authorized by this scope decision.

## 9. Current checkpoint

At the 5 November 2026 checkpoint, branch `repair/healthbridge-staged-hardening` was at `d4dd731b7578d9309d8eca50ec57bb319459dade`, with 161 working-tree status entries. Latest checks after the legacy-controller cleanup: 113/113 PHP syntax checks passed, 32/32 JavaScript syntax checks passed, Apache configuration syntax passed, Composer validation was valid with only the missing-license metadata warning, and `git diff --check` exited 0. Local Apache path-boundary checks passed 12/12; the patient dashboard page and active dashboard script returned 200, while the removed controller returned 404. These are local checks, not proof of authenticated end-to-end behavior or deployed-host protection.

No dependency was added and no application behavior or database data was changed as part of recording this scope decision.


## 10. Initial library evaluation outcome — 5 November 2026

**Decision: defer all five candidates; add no package in this checkpoint.** The goal is a smaller, more maintainable implementation, not a larger dependency list.

| Candidate | Current decision | Repository-specific reason |
|---|---|---|
| PHPUnit | Defer until the PHP runtime target is decided | The local runtime is PHP 8.2.12, which does not meet the current maintained PHPUnit 12/13 runtime requirements. Installing an older unsupported line merely to get started would be poor portfolio hygiene. First establish which runtime the project will support and build a small regression suite around existing behavior. |
| Symfony Validator | Do not add yet | `includes/validation.php` already provides shared parsing/validation helpers and has 37 call sites in the current API/service scan. There is no demonstrated gap that justifies adding a second validation model. Revisit only if the shared rules become materially difficult to maintain. |
| swagger-php | Defer | The 89-route endpoint inventory documents method, authorization, service, table, and frontend caller evidence. There is no existing OpenAPI annotation convention. Annotating the entire API is a separate documentation investment; defer until a generated contract is demonstrably more useful than keeping the reviewed inventory accurate. |
| Monolog | Defer pending a logging/privacy audit | The code currently uses PHP `error_log()` in route/service error paths. A new library alone would not define retention, rotation, access control, structured context, or redaction of identifiers/clinical content. Establish those requirements before replacing the current logger calls. |
| Doctrine Migrations | Defer | The exact pre-demo-refresh backup has not had a proven restore rehearsal. No schema-migration tool should be introduced or used for writes until the active schema baseline and a restore on a disposable database are verified. |

This is an evaluation outcome, not a permanent ban. Reassess a candidate only when a concrete existing maintenance problem, runtime compatibility, operational boundary, and regression test plan are all documented. No Composer manifest/lock changes were made.
## Current recovery status - 6 November 2026

- The approved scope remains feature-free: repair existing journeys, improve security/data integrity, standardize architecture, remove only proven dead code, refactor only with clear responsibility boundaries, and establish repeatable tests/setup.
- Overall recovery progress is estimated at approximately 30% of the six-phase scope. This is a qualitative planning estimate, not an objective code metric.
- Notification reliability has advanced substantially (root handoff Sections 80-90), including transactional enqueue for the reviewed producers and a five-minute interactive-user Windows worker task.
- Next priorities are integration tests for recent notification routes, completion of the Phase 1 dependency map, closure of critical security/data-integrity findings, and only then decomposition of large modules with regression coverage.
- Full browser E2E, migration/restore proof, deployed-boundary verification, and release readiness remain incomplete. Do not describe the application as production-ready or formally healthcare-compliant.


## 11. Hosting freeze and final publication strategy — 8 November 2026

The hosted HealthBridge website is hands-off during the remaining maintenance and refactoring work. Do all source changes, security fixes, data/schema checks, cleanup, and acceptance testing locally at `C:\xampp85\htdocs\optimized`. Do not access the hosting File Manager, FTP, control panel, remote filesystem, or hosted DB for routine work. No hosted file edits, uploads, deletions, configuration changes, cleanup, or database actions are authorized by this scope.

Only a specifically justified, read-only, non-mutating public-site check may use the hosted website before final release. Do not submit real or synthetic live forms, log in to perform mutations, create bookings, invoke admin actions, or call state-changing API routes. Use local Apache and disposable synthetic-data databases for QA.

The current hosted copy is not a reliable portfolio demonstration of the repaired local code. Do not describe the existing live deployment as containing the latest local fixes. HB-SEC-007 remains P1 / partially mitigated / open; four dotfile probes and remote historical migration-executor presence are still unknown. Leave the host untouched.

When the local project passes its acceptance gates, create a clean runtime-only deployment artifact, excluding secrets, Git internals, backups, QA scripts, internal docs, archived migration executors, and raw schema dumps. Take a fresh backup of hosted files and define rollback. Perform a full clean replacement upload from the start only after explicit user approval. Any database migration or data movement is separate from the file upload and requires its own approval. Then run read-only public smoke/security checks before presenting the live project as updated.
