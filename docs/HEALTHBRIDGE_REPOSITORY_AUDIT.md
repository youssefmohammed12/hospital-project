# HealthBridge - Repository-Wide Maintenance Audit

**Audit date:** 5 November 2026  
**Project root:** C:\xampp85\htdocs\optimized  
**Branch:** repair/healthbridge-staged-hardening  
**HEAD:** d4dd731b7578d9309d8eca50ec57bb319459dade  
**Status:** Repository-wide static audit with targeted local hardening checkpoints; Phase 1 remains In Progress.

This audit extends the endpoint inventory and module map to the rest of the repository: assets, page entry points, scripts outside api/, database setup/migrations, project configuration, documentation, and large maintenance hotspots. It is a static review. No URL access test was performed against the live demo host, and no archived migration was executed.

---

## Current database-artifact disposition — 20 November 2026

This 5 November repository audit records the source tree before the local V2 cutover. Its V1 database paths and file counts below are historical. On 20 November 2026, all V1 fresh-install SQL, historical migration SQL, V1 forward migrations/preflights, PHP ledger manifests and four V1-only CLI helpers were moved to C:\xampp85\migration-backups\healthbridge\retired-v1-artifacts-20261120\, outside the Apache document root. Their SHA-256 values are recorded in that folder's manifest. The active project retains database/v2/; shared API, include, service and page PHP was not moved. The original V1 database itself remains intact for rollback.
## 1. Repository size and composition

The current 5 November 2026 inventory contains **237 files** when excluding Git internals and Composer vendor contents, while including the local .env file and working documents.

| Category | Current count | Notes |
|---|---:|---|
| PHP files | 113 | 89 API routes, 13 services, 9 includes/bootstrap files, 2 CLI tools; six obsolete archived PHP migration executors have been removed locally |
| JavaScript files | 32 | Shared core, components, modules, and page controllers |
| CSS files | 22 | Global, page, component, and module styles |
| HTML files | 24 | 22 root/page templates plus 2 reusable HTML fragments |
| SQL files | 16 | 3 current setup/seed scripts, 6 active migrations, 7 archived schema/migration files |
| Screenshot images | 11 | 10 currently linked from README |
| Markdown files | 9 | README, workflow documentation, handoff, roadmap, endpoint inventory, module map, findings register, repository-wide audit, portfolio refactor scope |

The application is a same-origin, traditional PHP + vanilla JavaScript application. It does not currently have a frontend package manifest/build pipeline or a separate public document-root directory in the repository.

## 2. Asset/reference audit

### JavaScript

- After the 5 November cleanup, **32 of 32** remaining JavaScript files have a current HTML entry/load path; the obsolete legacy controller is removed and recorded below.
- The former **assets/js/pages/patient-portal.js** (2,006 lines / 80,012 bytes) was removed locally on 5 November after confirming that the current dashboard loads assets/js/pages/patient/dashboard.js and that no current application source or dynamic loader referenced the old controller.
- Git history shows the controller was already present in the July 2026 baseline, while that baseline's dashboard template loaded dashboard.js rather than patient-portal.js. Current source and dynamic-loader scans found no caller. It was removed locally; Git history preserves the original.
- Its related patient-portal.css remains referenced by current patient pages and was deliberately retained.

### CSS

- All 22 CSS files have a current usage path.
- assets/css/modules/print-prescription.css is not linked from HTML, but it is linked by the PHP-generated print pages: api/prescriptions/print.php, api/appointments/print.php, and api/medical/print-patient-emr.php. It is not unused.
- assets/css/pages/patient-portal.css is loaded by the current patient-facing pages. Its name does not prove the old patient-portal.js controller is active.
- assets/css/pages/dashboard.css, settings.css, and style.css are shared stylesheets. Their broad usage is intentional, but they create cross-page regression risk when edited.

### Screenshots

- There are 11 files in docs/screenshots/.
- 10 screenshot images are linked by README.md.
- docs/screenshots/06_patient_prescriptions.png has no reference in current source or documentation. The numbering gap suggests it may be an omitted README showcase image rather than a file that should automatically be deleted. Review and either link it to the prescription showcase or retire it deliberately.

### Asset conclusion

The first static pass does **not** support a broad deletion of CSS/JS assets. The verified high-confidence JS orphan candidate is the legacy patient-portal controller; one screenshot is unreferenced. Print assets and shared patient CSS must be preserved.

The prescription read path has since been improved locally: list medication items are batch-hydrated instead of queried once per prescription; see the 4 November prescription-maintenance checkpoint below.

---

## 3. PHP scripts outside the API directory

This is the most urgent discovery in this sweep.

At initial discovery, the six PHP files under database/archive/scripts/ were tracked in Git and sat under the project directory described by the README as the web-server document root. At that point there was no project-level .htaccess, web.config, or Nginx/Apache access-control file in the working tree. Local Apache containment was applied later in this audit; see the dated containment section below.

| Archived script | Current source pattern | Risk / behavior |
|---|---|---|
| apply_migration.php | Includes auth bootstrap, but never calls requireAuth()/requireRole() and is not CLI-only | Performs ALTER TABLE on admin_audit |
| apply_migration_phase9.php | Includes auth bootstrap, but never calls requireAuth()/requireRole() and is not CLI-only | Alters schema, backfills names/IDs, changes duplicate phone values, and attempts a phone uniqueness constraint |
| apply_phase9_migration.php | Includes only db.php; no auth or CLI guard | Alters user/medical-record schema and backfills patient numbers/name fields |
| apply_reschedule_migration.php | Includes only db.php; no auth or CLI guard | Alters appointment schema and status ENUM |
| migrate_medical_history_updates.php | Includes auth bootstrap, but never calls requireAuth()/requireRole() and is not CLI-only | Creates clinical-history table and foreign keys |
| migrate_workflow.php | Calls requireAuth() and checks admin role, but has no explicit method guard or CLI restriction | Runs a database migration through a state-changing GET-capable script |

### Why this matters

Including includes/auth.php starts common session/response infrastructure; it does **not** automatically authenticate a visitor. The five scripts above with no route-level guard can therefore perform database-changing work if their PHP URLs are reachable by the web server.

The admin-only workflow migration is also a poor web endpoint: a state-changing migration is exposed through a normal request without an explicit POST contract/CSRF design.

The repository scan cannot prove whether the current hosting provider has an out-of-repository server rule denying these paths. However, the documented installation instructions clone the full project into the web-server root, and the repository itself does not deny access to these scripts. Treat this as a **critical exposure candidate until deployment routing is proven safe**.

### Immediate remediation direction for Phase 2

1. Remove archived PHP migration executors from the public document tree, or move them to an operations directory outside the web root.
2. Retire duplicate, obsolete scripts only after checking Git history and confirming no operational need.
3. If any migration executor must remain, make it CLI-only and require an explicit local/approved environment guard. Do not make schema migrations normal web endpoints.
4. Add deployment-level deny rules for database archives, SQL files, .git, .env, tools, and internal PHP directories as defense in depth.
5. Verify the effective web-server configuration on the actual deployment host before calling this fixed.

Do not run any archived migration script to test it.

### Local containment applied — 4 November 2026

After the discovery, the following changes were made to the local working copy:

- Removed all six unreferenced PHP migration executors from `database/archive/scripts/`. The seven historical SQL snapshot files remain in `database/archive/` as reference only; no SQL was executed.
- Added a root `.htaccess` to disable directory indexes, deny requests for local environment files, Composer metadata and root Markdown documents, and reject `.git` paths.
- Added directory-level `.htaccess` rules denying direct requests to `database/`, `docs/`, `tools/`, `services/`, and `vendor/`.
- Added an `includes/.htaccess` rule denying direct requests for PHP files while leaving `includes/fragments/*.html` accessible to the existing same-origin layout loader.
- Verified the local XAMPP Apache configuration has `AllowOverride All`, the authorization and rewrite modules loaded, and `httpd.exe -t` returns `Syntax OK` (exit 0).
- Re-ran PHP syntax checks across 111 current project PHP files: **0 failures**. Re-ran Node syntax checks across 33 JS files: **0 failures**. `git diff --check` passed.

**Limitations:** These are local Apache rules, not a verified production deployment. No HTTP request test was performed against the live demo host. Nginx or hosting-provider routing needs equivalent rules. A syntax check of Apache's main configuration does not prove every URL path is denied in the running server. The PHP migration executors are removed from the working tree but their historical contents remain recoverable from Git history.

---

## 4. Web-root and deployment-boundary review

The project currently places application pages, API routes, PHP internals, database scripts, documentation, Composer files, .git, and a local .env file in the same project root.

The .gitignore correctly ignores .env, local environment overrides, vendor, logs, and editor files for Git. The local .env is ignored and not tracked. **Git ignore rules do not prevent web requests.**

The initial repository scan found no access-control files. A local Apache protection layer has since been added: root and directory-level .htaccess rules deny repository metadata, local configuration, database/docs/tools/services/vendor paths, and direct PHP requests under includes/. These rules are Apache-specific; there is still no checked-in Nginx/web.config equivalent, and the effective deployed-host policy has not been verified.

Potentially sensitive/internal paths in the tree include:

- .env and environment files.
- .git/ and its repository metadata/objects.
- database/, including SQL setup files, archive snapshots, and migration scripts.
- includes/ and services/, which should be application internals rather than public assets.
- tools/, which contains CLI diagnostic/seeding scripts.
- vendor/ and Composer metadata.
- docs/, which contains internal repair notes and screenshots.

The SQL content inspected in this pass is schema and synthetic demo/setup data; I did not find a user-table data dump in database/archive/database.sql. The demo seed contains known demo credentials and password hashes, which are intentionally documented for evaluation, not production user secrets. Nevertheless, SQL files should not be served as public static downloads.

### Deployment architecture recommendation

For a professionally hardened deployment, make a dedicated public document root that contains only the required HTML entry points, public assets, and explicitly exposed API routes. Keep database scripts, docs, tools, internal includes/services, Composer files, .git, and .env outside that public root. If the current hosting environment cannot support that separation, explicit server deny rules must be configured and verified.

This remains an architecture/release-readiness requirement. Local Apache deny rules partially address the current project layout, but a dedicated public document root and deployed-host verification are still outstanding.

---

## 5. Database setup and migration map

### Current setup chain

| File | Actual role | Important behavior |
|---|---|---|
| database/healthbridge.sql | Canonical master schema for fresh installs | Uses CREATE DATABASE without IF NOT EXISTS and creates the current 26-table schema; it never drops an existing database. Fresh-install only, not an upgrade migration. Older destructive snapshots are historical and must remain inert. |
| database/healthbridge_empty.sql | Fresh production-like setup wrapper | Uses MySQL client SOURCE healthbridge.sql, then seeds hospital settings, departments, and Egyptian governorate/city lookup data. Does not create tables directly; it delegates schema creation. |
| database/healthbridge_demo_data.sql | Full demo setup wrapper | Uses SOURCE healthbridge_empty.sql, then inserts Quick Login demo users and interconnected demo records. It creates a new demo database only when the guarded base schema succeeds; it does not reset an existing database. |
| tools/seed_demo_hospital.php | Additional synthetic demo generator | CLI-only; hardcodes a loopback DB host and the intended healthbridge DB, refuses existing synthetic demo data, and runs inside a transaction. |
| database/migrations/phase10_...sql through phase15_...sql | Six current forward migrations | The ordered upgrade path for existing installations; Phases 13 and 14 are one-time. Phase 10–12 require MariaDB syntax unless ported for MySQL 8. |

### Important setup risk

The current master schema is guarded: CREATE DATABASE fails when healthbridge already exists and the active file contains no DROP DATABASE. Older destructive schema snapshots remain under database/archive and must stay inert. Fresh setup is still not an upgrade path.

README Step 2 now distinguishes guarded fresh setup from an existing-database upgrade and explains that the base schema has no lookup/demo inserts. The active master schema has 26 table definitions and does not drop an existing database. Before upgrading an existing database, follow docs/HEALTHBRIDGE_DATABASE_MIGRATION_GUIDE.md: the retained synthetic baseline showed partial Phase 11 and missing Phase 13/14 signatures, and the Phase 10–12 scripts are not Oracle MySQL 8.0-portable as currently written.

The two SOURCE commands are MySQL client commands. The README's phpMyAdmin mention should not imply that importing the same wrapper file through phpMyAdmin is equivalent to executing it with the MySQL CLI.

### Archive and migration drift

- database/archive/ contains historical schemas/migrations from earlier phases. Keep this history separate from the active forward migration path.
- The archived phase10_reschedule_workflow.sql is older than the active Phase 10 migration: it lacks the original requested-reschedule fields and the suggested status needed by the current workflow.
- The archived Phase 10 PHP executor is also an older implementation and lacks the full current state model.
- The archived medical-history migration creates cascade foreign keys, whereas the current Phase 13 migration changes clinical/audit history relationships to RESTRICT. Do not reuse old executors against the current schema.
- Six archived PHP executors have no current repository caller reference; the active migration source of truth is database/migrations/, not database/archive/.
- **11 Nov 2026 schema/migration drift review:** the current master schema has 26 table definitions and contains final Phase 10–15 structures. The retained synthetic restore baseline has complete Phase 10/12/15 signatures, a partial Phase 11, and lacks Phase 13 RESTRICT delete rules and Phase 14 normalized-phone uniqueness. A follow-up Phase 13-only MariaDB 10.11.16 disposable-clone rehearsal passed: all 26 tables retained matching row counts, extended checksums and AUTO_INCREMENT values; all 28 intended FKs changed to RESTRICT; mysqlcheck passed 26/26; the retained baseline remained unchanged and the clone was dropped. The full Phase 10–15 upgrade remains unverified because no pre-Phase-10 fixture or migration ledger is available. See docs/HEALTHBRIDGE_DATABASE_MIGRATION_GUIDE.md and hb-db-migration-drift-review-20261111/RESULT.md.
- The forward migration set now contains six files (Phases 10–15), but Phase 10–12 use MariaDB-specific IF NOT EXISTS DDL syntax. The full existing-install chain must not be described as MySQL 8 compatible until ported and tested against an actual MySQL 8 server.

No schema migration was applied during the original repository audit; the later Phase 13-only follow-up is recorded above.

---

## 6. Project configuration and dependency review

### Composer

- composer.json requires only vlucas/phpdotenv, with a wildcard version constraint (*).
- composer.lock exists and pins the currently resolved dependency tree.
- composer validate --no-check-publish exits successfully, with warnings: no license declared in Composer metadata and the wildcard dependency constraint should be avoided.
- The README says Composer is optional because the DB loader has fallback defaults, but deployment still needs the dependency installed if .env parsing is required.

Recommendation: pin a deliberate supported phpdotenv version range, regenerate the lock file, and document a reproducible composer install deployment step.

### Environment variables

- .env exists locally, is ignored by Git, and is not tracked.
- .env.example documents DB connection keys, APP_ENV, and APP_DEBUG.
- APP_ENV is consumed by the password-recovery environment gate.
- APP_DEBUG is present in the sample but is not referenced by application source in the current scan; either implement a safe, environment-aware debug policy or remove the unused setting.
- The fallback DB defaults in includes/db.php are local-development defaults. Production deployment must set DB credentials explicitly and must not rely on a default root/no-password account.

Do not copy local .env values into committed documentation or chat output.

### Git ignore and vendor

The .gitignore has sensible entries for .env, vendor, local overrides, logs, temporary files, editor metadata, and OS-generated metadata. This controls Git tracking, not web serving. The current working tree contains vendor locally, but vendor is ignored and should be recreated from composer.lock in a deployment/build step.

---

## 7. Documentation/API contract mismatches

The README API summary has at least these source-verifiable mismatches:

1. It lists api/appointments/get-available-slots.php as **GET**, but the endpoint documentation and frontend send **POST JSON**.
2. It describes a standard response wrapper with a nested data object. The shared jsonResponse() helper merges endpoint-specific fields at the top level (for example success, slots, message), not under a universal data property.
3. It describes database/healthbridge.sql as schema plus lookup data, but it drops/recreates the DB and creates the tables; database/healthbridge_empty.sql sources it and then seeds the lookup data.
4. The README should distinguish the destructive fresh/demo reset chain from the non-destructive forward migration procedure for existing installations.
5. The API summary only covers a subset of 89 routes and should be labelled as a quick reference, with the endpoint inventory as the authoritative route matrix.

### Booking doctor/department integrity — initial finding, repaired locally in follow-up

The initial route/caller trace found a data-integrity gap:

- `api/appointments/book.php` initially validated the selected department and active doctor independently without comparing the doctor profile's authoritative `doctors.department_id`.
- The original four frontend callers sent display names and omitted a stable ID. Name-only lookup used `users.name ... LIMIT 1`, allowing ambiguous resolution.
- The booking wizard already retained `BookingStateManager.doctorId` for slot lookup but dropped it from the POST. Admin and EMR selectors also used name-valued options.

**Local repair completed on 4 November 2026:** the server now resolves canonical `doctors.user_id` (`users.id`), verifies the authoritative department relationship, permits specialty fallback only for a legacy profile with NULL `department_id`, and rejects ambiguous legacy name-only lookups. It repeats doctor profile and active department checks under row locks inside the booking transaction. All four frontends now use stable user IDs for options, selected state, profile preselection, slot lookup, and booking payloads. The patient EMR selector now filters using `department_name` with the legacy specialty fallback.

Local data inspection found 32 active doctors, all linked to an active department, with no NULL/orphan department assignments and no duplicate active names.

Verification was source-level plus read-only local DB checks: **9/9** relationship/legacy/locking cases passed; PHP lint passed for `book.php`; Node syntax checks passed for all four changed frontend files; static scan found exactly four booking callers and all carry `doctor_id`. No booking or patient data was inserted/changed. No HTTP/browser booking journey was executed, so runtime UI/network acceptance remains pending.

HB-DB-002 remains P1 by impact but is now marked locally fixed with end-to-end verification pending.

### Public registration and identifier enumeration

- `api/auth/check-availability.php` publicly reports whether an email, phone number, or National ID is already registered. It accepts POST but has no application-level rate limiter in the route.
- `api/auth/register.php` repeats uniqueness responses during both Step 1 validation and Step 2 atomic account creation. The route creates patient accounts after format/uniqueness validation; no email/phone ownership verification, CAPTCHA, or application-level signup throttle was found in the inspected code.
- This creates an identifier-enumeration and automated fake-account-abuse risk. A deployment-level WAF/gateway may have protections, but that configuration is outside this repository and has not been inspected.
- Password recovery is different: `forgot_password.php` is disabled outside strict loopback development and applies per-email/IP request limits in the local development flow. Its generic response is a stronger pattern worth retaining.
- Recorded as HB-AUTH-001 (P1). Define whether patient accounts require verified email or phone, add server-side abuse controls, and reduce public uniqueness detail while preserving usable registration feedback.

---

## 8. Large-file / maintainability hotspots

These are candidates for carefully staged decomposition, not automatic split targets. Line count alone does not justify a refactor; tests and domain ownership must come first.

| File | Approx. lines | Review direction |
|---|---:|---|
| assets/js/pages/admin.js | 2,418 | Separate admin domains/controllers after mapping event wiring and shared state |
| services/PatientPortalService.php | 1,540 | Consider smaller query/read-model components with stable response contracts |
| services/ScheduleService.php | 1,369 | Keep scheduling rules centralized; split by well-defined schedule policy/queries only after tests |
| services/RescheduleService.php | 1,047 | Keep lifecycle/state transitions coherent; avoid splitting the concurrency invariants |
| assets/js/pages/patient-emr.js | 1,843 | Separate role-specific EMR presentation/actions from shared record workflows |
| assets/js/pages/doctor-workspace.js | 1,767 | Separate workflow, clinical note, prescription, and UI concerns behind tests |
| assets/js/pages/admin-dashboard.js | 1,378 | Separate dashboard data/rendering if ownership is currently mixed |
| assets/css/pages/patient-portal.css | 2,372 | Consolidate duplicate selectors/tokens only after visual regression coverage |
| assets/css/modules/schedule.css | 2,274 | Identify repeated rules and page overrides before reducing size |
| assets/css/style.css | 2,226 | Treat as global shared CSS; broad regression risk |

### Static cleanliness

The source scan found no TODO/FIXME markers, no console.log calls, no debugger statements, and no var_dump/print_r calls in application source. This is a static marker scan; it does not prove every path is well tested or maintainable.

---

## 9. Cleanup decision register

| Candidate | Evidence | Decision |
|---|---|---|
| assets/js/pages/patient-portal.js | 2,006-line legacy controller; no current HTML inclusion, application caller, or dynamic loader; Git baseline dashboard already loaded dashboard.js | Removed locally on 5 November after reference/history checks; original preserved in Git history |
| database/archive/scripts/*.php | Six historical PHP executors had no current callers; five lacked central auth/CLI guards and one had no explicit method guard | All six executors removed from local working tree; seven archived SQL snapshots retained. Git history preserves the original PHP files. |
| database/archive/migrations/* | Historical snapshots; some schema/state definitions conflict with active migrations | Preserve as inert historical reference only; block web access |
| docs/screenshots/06_patient_prescriptions.png | No current README or source reference; adjacent screenshot numbering skips 06 | Likely omitted showcase image; link or retire deliberately |
| assets/css/modules/print-prescription.css | Referenced by three PHP print views | Keep; not unused |
| assets/css/pages/patient-portal.css | Loaded by current patient pages | Keep; not unused |
| Other CSS/JS files | Static HTML/script references found | Keep unless a deeper dynamic/runtime audit proves otherwise |
| services/VisitWorkflowService.php::migrateExisting() | Repository-wide exact reference scan found only the method declaration after migrate_workflow.php was removed; method bulk-creates workflow rows for confirmed appointments | Review as an orphan maintenance method. Do not run against current data; remove or wrap in a future explicit CLI repair tool only after confirming no legitimate operational use |

---

## 10. Phase 1 status

**Status: In Progress.** The repository-wide static sweep expanded the initial inventory and identified important security/deployment findings. Phase 1 is not complete.

### Containment already applied to the local working tree
- [x] Removed all six unreferenced archived PHP migration executors from `database/archive/scripts/`; retained seven historical SQL snapshots.
- [x] Added root `.htaccess` protections for hidden Git metadata, local environment files, Composer metadata, and root Markdown documents; directory indexing disabled.
- [x] Added `.htaccess` deny rules for `database/`, `docs/`, `tools/`, `services/`, and `vendor/`.
- [x] Added an `includes/` rule denying direct PHP requests while allowing the required HTML fragments.
- [x] Confirmed local XAMPP Apache config has `AllowOverride All`, required modules are loaded, and `httpd.exe -t` returns `Syntax OK`.
- [x] PHP lint: 111 PHP files, 0 failures; JavaScript syntax: 33 files, 0 failures; `git diff --check`: exit 0.

### Remaining
- [ ] Confirm the effective deployed-host access policy for database, .git, .env and other internal paths. Local Apache rules are not proof of production protection; Nginx/hosting-provider rules need separate configuration.
- [ ] Verify the legacy patient portal JS through Git history and browser/runtime evidence before deletion.
- [ ] Decide whether to include `docs/screenshots/06_patient_prescriptions.png` in the README visual walkthrough.
- [ ] Correct README setup/API statements after the request/response contracts and setup chain are cross-checked.
- [ ] Reconcile archived SQL snapshots against canonical schema and active migrations.
- [ ] Review the now-unreferenced `VisitWorkflowService::migrateExisting()` method, formerly called only by the deleted archived `migrate_workflow.php`; decide whether it is safe to remove or should be retained as an explicit CLI maintenance operation.
- [ ] Continue the endpoint-by-endpoint auth/ownership/contract review from the endpoint inventory.

**Local containment changed only archived PHP executors and Apache access-control files. No application route/service behavior, SQL schema, or database rows were changed. No migration or SQL was executed. No HTTP/browser tests against the live host were performed. No commit, push, deployment, or hosted database operation was performed.**

### Service public-method reference audit

A repository-wide method-name scan was run against PHP, JS, HTML, SQL, Markdown and JSON files, excluding .git and vendor. A candidate below means no method invocation was found outside its declaration (and, where noted, no internal invocation). Reflection, plugin consumers, out-of-repository integrations, and external tests cannot be excluded by this scan.

| Service / method | Source reference evidence | Initial interpretation |
|---|---|---|
| DepartmentService::removeDoctorFromDepartment | Declaration only; no source caller | Likely orphan. Department reassignment uses assignDoctorToDepartment and the API currently has no remove action. |
| DepartmentService::searchDepartments | Declaration only; no source caller | Likely unused search helper; no current search UI/API flow found. |
| HospitalSettingsService::getAppointmentOpenTime / getAppointmentCloseTime / getDefaultDuration | Each declaration only; no caller or internal invocation | Thin legacy accessors; current consumers use getSettings()/ScheduleService. Candidate for removal after external contract review. |
| NotificationService::getAllTypes / getTypesByRole | Each declaration only; no source caller. Comments now accurately state that the current Settings UI maintains its own presentation map. | Retain as server-side metadata helpers pending external-contract review. |
| PrescriptionService::getByPatient / getByDoctor / countByAppointment / isEditable | Each declaration only; no source call. The current frontend has its own isEditable() helper. | Likely old convenience methods; current paths use get/search/getAll/getByAppointment. |
| AuditService::getEntityAuditLog | Removed in 5 November follow-up after declaration-only reference scan; it only wrapped getAuditLog() filters. | Locally resolved; no active call site was changed. |
| RescheduleService::getDB | Removed in 5 November follow-up; it exposed the service's internal PDO and had no repository caller. | Locally resolved; no active call site was changed. |
| RescheduleService::getRescheduleHistory | Declaration plus a documentation signature in RESCHEDULE_WORKFLOW_DOCUMENTATION.md; no route or JS caller. | Documented but unintegrated endpoint/service capability. Decide whether to expose/use or retire and correct docs. |
| ScheduleService::getWorkingSchedule / isTimeSlotAvailable | Declaration only; no source caller or internal invocation. | Likely older schedule API alternatives. Confirm no external integration before deletion. |
| VisitDraftService::cleanupOld | Declaration only; no scheduled task or source caller found. | Dormant housekeeping, not ordinary dead code: it encodes a 7-day draft retention policy but is never run. Decide retention policy and schedule safe cleanup or remove the misleading method. |
| VisitWorkflowService::migrateExisting | Declaration only after deletion of archived migrate_workflow.php; already tracked as HB-CLEAN-005. | Potential controlled historical repair operation; preserve until migration intent is resolved. |

**Not classified as unused:** DashboardAnalyticsService and PatientPortalService methods with no external callers are invoked internally by their public aggregation methods. ScheduleService helpers such as getBookedRanges/getNextAvailableSlot/validateDay have internal callers. The static scan correctly excludes these from the orphan list.

Do not mass-delete this list. For each item, check Git history, intended external contract, legacy docs, runtime coverage and whether it represents an unimplemented workflow. cleanupOld() and migrateExisting() need an explicit operational decision, not automatic removal.

---

## 11. Service-method reference audit

A static public-method scan was run across all project PHP/JS/HTML source, docs, SQL and JSON, excluding .git and vendor. It looked for method invocation syntax (`->method()` and `Class::method()`) and separately counted same-class `$this->` usage. This is a useful cleanup filter, not proof that a method is dead: reflection, external integrations, test harnesses outside the repository, and dynamic dispatch may not appear in this scan.

### Candidates with declaration-only source references

| Class / method | Current evidence | Interpretation |
|---|---|---|
| DepartmentService::removeDoctorFromDepartment | Declaration only | Likely unused; current department UI supports reassignment but has no remove action |
| DepartmentService::searchDepartments | Declaration only | Likely unused; no search UI/API flow currently found |
| HospitalSettingsService::getAppointmentOpenTime / getAppointmentCloseTime / getDefaultDuration | Declarations only; no callers or internal invocation | Thin accessors; current callers use getSettings() / ScheduleService |
| NotificationService::getAllTypes / getTypesByRole | Declarations only; no source caller; comments corrected to say the current Settings UI does not call these helpers | Retain as potential server-side metadata APIs pending external-contract review |
| PrescriptionService::getByPatient / getByDoctor / countByAppointment / isEditable | Declarations only; current application paths use get/search/getAll/getByAppointment and the frontend has its own isEditable helper | Likely legacy convenience methods |
| AuditService::getEntityAuditLog | Removed in 5 November follow-up; redundant wrapper over getAuditLog() filters | Locally resolved |
| RescheduleService::getDB | Removed in 5 November follow-up; no repository caller and exposed the private PDO connection | Locally resolved |
| RescheduleService::getRescheduleHistory | Declaration + signature in RESCHEDULE_WORKFLOW_DOCUMENTATION.md; no route or JS caller | Documented capability without an active UI/API contract; decide whether to expose or retire |
| ScheduleService::getWorkingSchedule / isTimeSlotAvailable | Declaration only; no source caller or internal invocation | Likely previous schedule helpers that were superseded by current slot-validation paths |
| VisitDraftService::cleanupOld | Declaration only; no caller/cron/CLI task found | Dormant housekeeping. It describes 7-day draft retention but is never executed; retention policy and safe scheduling need a deliberate decision |
| VisitWorkflowService::migrateExisting | Declaration only after archived migration runner removal; tracked as HB-CLEAN-005 | Possible controlled backfill/repair; preserve pending an explicit decision |

### False positives deliberately excluded

- DashboardAnalyticsService's no-external-call helper methods are called from its public getAll().
- PatientPortalService's no-external-call methods are internal sections of getAll() and other same-class aggregate methods.
- ScheduleService's getBookedRanges/getNextAvailableSlot/validateDay helpers have internal call sites.
- PrescriptionService validation helpers have internal callers.
- Do not split or delete a method solely because it is not called from an API route.

### Recommended maintenance workflow for these candidates

1. Check Git history and project documentation for the intended feature/contract.
2. Confirm whether the current frontend has a replacement or intentionally omitted UI.
3. Decide whether it should be removed, called by an explicit supported workflow, or retained/documented as an extension API.
4. If removed, run service-level regression tests and the owning feature's API/browser checks.
5. For housekeeping methods, decide retention and schedule requirements before either wiring or deleting.
6. Update handoff/findings register after the decision, not just after changing code.

The current candidate group is recorded as HB-CLEAN-006. Nothing in this list was removed during this static scan.


## 12. Local session-guard hardening — 4 November 2026

The initial route review found four settings/department endpoints making privileged decisions from raw session fields. They have now been hardened in the local working copy:

| Endpoint | Local change | Preserved behavior |
|---|---|---|
| `api/settings/get-contact-messages.php` | Added explicit GET guard; replaced raw session role check with `requireRole('admin')` | Admin-only contact inbox |
| `api/settings/get-user-messages.php` | Added explicit GET guard; uses `requireAuth()` and the returned validated user ID | User-scoped support-message list; endpoint still has no exact frontend caller |
| `api/settings/get-hospital.php` | Added explicit GET guard; if a session identity exists, calls `requireAuth()` before deciding whether to return the full admin response | Public callers still receive the limited public settings model; admin receives full settings only after fresh identity validation |
| `api/departments/get.php` | Added explicit GET guard; if a session identity exists, calls `requireAuth()` before deciding whether inactive departments are visible | Public callers still receive active departments only; inactive list remains admin-only |

Verification:
- PHP lint passed for all four files (0 syntax failures).
- Static rescan: `requireRole=44`, `requireAuth-only=36`, manual session/helper=1 (login only), no route auth=8.
- Static method scan: **75/89** files contain `REQUEST_METHOD` logic; **14/89** have no explicit method check.
- Four prior session/method review items are now marked locally fixed in the findings register: HB-SEC-002 through HB-SEC-005.
- **Runtime tests remain pending:** verify revoked/deactivated sessions, admin-to-patient role changes, public response shape when no session exists, and 405 behavior. The route methods were linted but not exercised through HTTP in this pass.
- `api/settings/get-user-messages.php` remains a no-exact-frontend-caller API cleanup candidate; hardening it does not prove the endpoint is required.
- No DB/schema/data changes were made. No commit/push/deployment/hosted DB operation occurred.


## 13. Follow-up local session-guard hardening

Four endpoints identified by the initial static audit have now been updated in the local working copy:

- `api/settings/get-contact-messages.php`: GET-only and central `requireRole('admin')`.
- `api/settings/get-user-messages.php`: GET-only and uses `requireAuth()`'s validated user ID.
- `api/settings/get-hospital.php`: GET-only; public limited response remains; any existing session identity is freshly validated before full admin settings are returned.
- `api/departments/get.php`: GET-only; public active department response remains; any existing session identity is freshly validated before inactive departments are returned to an admin.

PHP lint passed for all four. Static route census now reports:
- **75/89** API PHP files have explicit `REQUEST_METHOD` logic; 14 do not.
- **44** use `requireRole()`, **36** use `requireAuth()` without a direct `requireRole()`, **1** uses manual session/helper logic without a central guard (login), and **8** have no route-level auth/helper call (mostly intentional public/pre-auth paths).
- Four earlier findings are marked locally fixed in `docs/HEALTHBRIDGE_FINDINGS_REGISTER.md`: HB-SEC-002 (P1), HB-SEC-003/004/005 (P2).
- Runtime testing of revoked/deactivated sessions, role changes, public payload shape and 405 behavior remains pending. The endpoint `api/settings/get-user-messages.php` still has no exact frontend caller and remains an API cleanup candidate.


## 13. Prescription workflow maintenance review

### Access-control and lifecycle trace

Inspected:
- `api/prescriptions/create.php`, `update.php`, `cancel.php`, `complete.php`, `get.php`, `print.php`
- `services/PrescriptionService.php`
- `assets/js/modules/prescriptions.js`, `assets/js/pages/patient/prescriptions.js`, the admin prescription list in `assets/js/pages/admin.js`, and doctor print integration in `assets/js/pages/doctor-workspace.js`
- Canonical schema and foreign keys for `prescriptions` and `prescription_items`

Positive controls confirmed:
- Create/update/cancel/complete routes require the doctor role and POST.
- Create validates that the appointment belongs to the authenticated doctor, is Confirmed, and links the requested patient; the service repeats these checks while holding an appointment row lock.
- One prescription per appointment is enforced by a unique appointment_id key in the canonical schema, in addition to the service-level duplicate check.
- Update/cancel/complete lock the prescription row and restrict mutations to Active prescriptions created by the authenticated doctor.
- Prescription changes and mandatory audit events are committed atomically; failed audit writes roll back the medication mutation.
- Single-record API reads and print views scope doctors to prescriptions they created and patients to their own patient_id. Admin read is explicitly documented as audit access.
- Print responses set private/no-store cache controls.

### Local improvements made

1. **Batch medication item hydration**
   - `PrescriptionService::search()` previously called `withItems()`, which executed one item query for every prescription in the returned list.
   - Replaced the N+1 loop with a bounded batch query using `WHERE prescription_id IN (...)`, grouped by prescription ID.
   - Preserves every existing row field, `items` array, `items_count`, and per-prescription `sort_order`.
   - Uses batches of 500 IDs to bound SQL placeholders. Empty result sets return immediately.
2. **Prescription list filter validation**
   - Only permits the known statuses Active / Completed / Cancelled.
   - Search input must be a string no longer than 100 characters.
   - Date filters must be valid calendar dates in YYYY-MM-DD format, and date_from cannot be later than date_to.
   - Invalid filters return deliberate 400 responses rather than malformed SQL-date behavior.
3. **Read-route error handling and identifier contract**
   - `api/prescriptions/get.php` and `api/prescriptions/print.php` now catch `Throwable` so runtime errors are handled as well as Exceptions.
   - Both endpoints validate a single prescription `id` as a positive integer. The JSON read route rejects malformed IDs with 400 before opening the database connection, rather than casting an invalid value to zero and silently returning a list.
4. **Print document accuracy**
   - Prescription print page now reads hospital name/address/phone/email from `HospitalSettingsService` instead of hardcoding a fictitious US address and contact details.
   - `PrescriptionService::get()` now includes the patient's email for the print view.
   - Removed the decorative QR icon that had no generated QR payload, and replaced the misleading “Electronic Signature” wording with “Prescriber recorded on this prescription.” The document reference is still displayed.

### Verification
- Read-only local DB test against `healthbridge`: **99 prescriptions / 186 medication items**. The batch loader returned matching overall count, matching per-prescription item counts, and preserved each prescription's medication sort order.
- Prescription service/filter QA: **6/6 passed**:
  - Unfiltered prescription count matches DB.
  - Active filter returns exactly active records.
  - Wide date range returns expected records.
  - Patient filter returns only that patient.
  - Doctor filter returns only that doctor.
  - Bound name search returns matching prescriptions.
- PHP lint: `PrescriptionService.php`, `api/prescriptions/get.php`, and `api/prescriptions/print.php` all pass.
- Node syntax: `assets/js/modules/prescriptions.js` passes (unchanged; checked as part of the touched module review).
- `git diff --check` returns exit 0; the warnings are the existing Windows LF-to-CRLF normalization notices, not whitespace errors.
- No prescription, item, appointment, user, or audit rows were changed by the read-only QA.

### Remaining prescription scalability item
- `api/prescriptions/get.php` still returns every matching prescription. The admin and patient pages currently expect the full array, so introducing pagination requires a coordinated API response/controls change. Track as HB-PERF-001: N+1 query fixed locally; pagination remains open. Do not silently truncate the list or break the existing UI contract.

### Limits
- No live HTTP/browser print test was completed. The print route and service passed syntax checks and the data layer passed read-only DB checks, but the actual browser print output still needs visual review.
- No change to prescription lifecycle permissions, status transitions, or medication-writing behavior.
- No migration, commit, push, deployment, or hosted database operation.


## 14. Booking doctor/department integrity repair — 4 November 2026

The P1 booking data-integrity gap (HB-DB-002) has been repaired in the local working tree.

- `api/appointments/book.php` now resolves the doctor using canonical `users.id` / `doctors.user_id`, validates the linked profile, and verifies its `department_id` matches the resolved active department.
- Legacy profiles with NULL `department_id` are permitted only when `specialty` matches the selected department name after trim/case normalization.
- Name-only compatibility lookup is constrained to the selected department/specialty fallback, queries up to two matches, and rejects ambiguity with 409 rather than choosing an arbitrary doctor.
- The booking transaction now locks the doctor user row, doctor profile row, and department row, revalidating doctor assignment and department activity before schedule revalidation/insertion.
- All four booking forms use doctor user ID as the actual select value and send `doctor_id`: patient booking wizard, main admin booking modal, doctor-profile booking modal, and patient EMR booking modal. They keep the display name only for labels/API compatibility. The wizard persists IDs in drafts, resolves slot data by user ID, and preselects reschedule appointments by appointment doctor_id. Admin profile shortcuts preselect by profile user ID. Patient EMR filtering now uses department_name (specialty fallback for NULL department_id). The relevant dropdowns fail closed when the doctor list response is not an array.
- Read-only local data inspection: 32 active doctors; zero NULL, orphan, or inactive department links; zero duplicate active names; all nine departments active.
- Read-only relation/legacy/locking test: **9/9 pass**. Tested current profile mapping, mismatched department rejection, NULL-department specialty fallback match/mismatch, name-only department scoping, and the transaction row-lock query sequence with a rollback.
- Static scan found exactly four `api/appointments/book.php` callers and every one now sends doctor_id.
- PHP syntax check passed for `api/appointments/book.php`; Node syntax checks passed for all four changed JS files.
- No appointment/doctor/department/patient/audit rows were modified. No browser/HTTP booking acceptance test was performed; runtime E2E validation remains outstanding.
- HB-DB-002 is recorded as **locally fixed; runtime verification pending**. It remains P1 by business impact.

## 15. Clinical encounter authorization — verification checkpoint, 5 November 2026

The clinical access policy introduced in the local working tree has now passed syntax and static integration verification.

- services/ClinicalAccessService.php is included by all six affected routes: api/medical/get-emr-data.php, get-record.php, print-patient-emr.php, update-record.php, add-history-update.php, and api/patients/get-audit.php.
- Confirmed bookings preserve pre-visit review. Cancelled appointments qualify only when the same appointment has a workflow state of In Progress / Ready to Complete / Completed, a visit note, or a prescription. A cancelled appointment with only a Waiting workflow does not establish a clinical relationship.
- Write routes authorize and lock inside their transaction. update-record.php locks the qualifying appointment before the patient row; add-history-update.php inserts its event inside the transaction after the same relationship check.
- PHP lint: 7/7 passed (service plus six routes). Apache main configuration check: Syntax OK.
- The earlier read-only database regression remains recorded as 6/6 across 453 doctor/patient pairs; it was not rerun during this checkpoint.
- A static search found no remaining broad Confirmed OR Cancelled authorization predicate in the medical routes. Remaining Cancelled references are documentation or appointment display/count logic.
- Runtime HTTP/session authorization tests, print rendering, stale-session behavior, and role-change cases remain pending. No clinical data was changed.

## 16. API method and public-registration hardening — 5 November 2026

### Available appointment slots

api/appointments/get-available-slots.php now checks the request method and returns 405 with Allow: POST unless the method is POST. Its current booking-wizard caller sends POST JSON; PHP lint passed. The endpoint inventory now records 13 remaining API routes without an explicit REQUEST_METHOD check (down from 14). HTTP 405 verification remains pending.

### Registration identifier privacy

- api/auth/check-availability.php validates field formats but no longer queries the users table or reports whether the submitted email, phone, or National ID exists. Valid requests receive available: null and a neutral message.
- assets/js/core/auth.js no longer sends per-field public uniqueness probes. It clears old availability hints and leaves uniqueness validation to registration submission.
- api/auth/register.php retains server-side uniqueness checks in both steps but emits one generic duplicate-conflict message rather than naming the conflicting identifier.
- PHP lint passed for both auth routes and the available-slots route; Node syntax passed for the auth controller.
- This is a partial enumeration mitigation, not a complete signup-abuse fix. Signup has no dedicated server-side rate limiter and still creates active accounts without email/phone ownership verification. The rate-limit and contact-verification design remains open under HB-AUTH-001. Do not reuse password-reset request rows as a signup attempt ledger.

### Dormant patient-audit candidate

A repository-wide search found patient_audit_log only in its schema/migration references and api/patients/get-audit.php; no application writer or trigger was found. The route has no exact frontend caller. This is not enough evidence to delete the route/table: existing installations may contain records and an external consumer may exist. Keep it as an investigation candidate under HB-CLEAN-001.

No schema or data was changed for these checkpoints. No commit, push, deployment, or hosted database operation occurred.
## 17. API method-gate completion and admin doctor-profile contract repair — 5 November 2026

### Method-gate census
- Completed the prior no-method review queue. All **89/89 API PHP files** now contain an explicit request-method gate in the reviewed source; the previous queue of 8 has been reduced to zero.
- The final eight routes now enforce GET: doctors/get-admin-ratings.php, doctors/get-availability.php, doctors/get-doctor-ratings.php, doctors/get-public-availability.php, doctors/get-rating.php, doctors/get_all.php, doctors/profile.php, and notifications/get-patient.php.
- Additional scope/validation hardening in that group: appointment rating read is patient-only with a strict positive appointmentId; legacy patient notification read now enforces GET while preserving its existing authenticated-user-only query scope; public doctor availability validates optional doctor_id as a positive integer and filters inactive accounts.
- PHP lint passed for the final eight routes (**8/8**). The earlier five appointment/admin/public-directory routes in this pass also passed **5/5**, and available-slots passed separately after its POST guard.
- Runtime HTTP 405/Allow checks for unsupported methods and broader per-route integration behavior remain pending. A static method-gate census is not proof that every branch or role contract is correct.

### Admin doctor-profile appointments and ratings
- admin.js calls appointments/get-doctor.php?doctor_id=<selected user ID> to load the selected doctor's appointment list, but the route previously required a doctor session and ignored the query parameter. The admin view therefore could not reliably load the selected doctor's data.
- The same mismatch existed in doctors/get-doctor-ratings.php: the admin panel passed doctor_id, while the endpoint required a doctor session and used the session user ID.
- Both routes now permit an admin to request a strictly validated doctor_id; doctor accounts remain limited to their own ID, and other roles are denied. Both verify the target belongs to a doctor account.
- appointments/get-doctor.php now also returns patient_id as an alias of appointment user_id because the admin profile UI expects patient_id to build the Patient EMR link.
- PHP lint passed for both modified routes as part of the **5/5 appointment route** check. admin.js syntax passed. Admin browser acceptance remains pending.
- Added HB-API-003; it is locally fixed, not runtime-closed.

### Appointment listing/print and other read-route contracts
- api/appointments/get.php is now GET-only and explicitly permits only admin and patient roles; patients see their own records, admins see the full list, and doctors use their dedicated route.
- api/appointments/print.php is now GET-only, validates a positive integer appointment ID, and emits private/no-store cache headers while preserving the existing row-level patient/doctor checks and admin access.
- api/admin/dashboard-analytics.php now enforces GET before admin analytics; api/doctors/get.php now enforces GET for its public directory response.
- No schema or database rows were changed. No commit, push, deployment, or hosted database operation occurred.

## 18. Fresh database setup documentation and destructive-operation warnings — 5 November 2026

### Verified SQL chain
- `database/healthbridge.sql` starts with `DROP DATABASE IF EXISTS healthbridge`, recreates `healthbridge`, and creates the 25-table schema. A source search found no INSERT statements in this file; lookup/demo rows are not seeded here.
- `database/healthbridge_empty.sql` executes `SOURCE healthbridge.sql`, then seeds hospital settings, 9 departments, 27 governorates, and city lookup data. It creates no user accounts or appointments.
- `database/healthbridge_demo_data.sql` executes `SOURCE healthbridge_empty.sql` and then inserts Quick Login accounts and interconnected demo accounts/appointments/clinical records.
- Both wrappers depend on MySQL/MariaDB client `SOURCE` semantics and must be run from the `database/` directory with the CLI; they are not phpMyAdmin-importable wrappers as written.

### Documentation and warning changes
- README Step 2 now prominently explains the destructive reset behavior, differentiates the base schema, empty/reference setup, and demo seed, provides separate fresh local setup commands, and separates existing-install forward migrations from destructive setup.
- README now says the available-slots endpoint is POST and describes endpoint-specific JSON fields as top-level rather than universally nested under `data`, matching `jsonResponse()`.
- `database/healthbridge.sql` header now correctly describes schema-only contents and warns that it drops/recreates the database.
- `database/healthbridge_empty.sql` and `healthbridge_demo_data.sql` headers now warn that their SOURCE chain rebuilds the database.
- No SQL file was executed and no database rows/schema were changed.
- HB-DB-001 is partially clarified but remains P1 open until backup/restore proof and a non-destructive operational safeguard are established. HB-DOC-002 documentation corrections are locally applied; final docs cross-check remains.

## 19. Read-only local HTTP smoke tests — 5 November 2026

Local Apache at `http://127.0.0.1/optimized` was reachable. A nine-case smoke suite was run through HTTP without authentication or data mutation:
- Public doctor directory GET returned HTTP 200 and 32 records; POST returned HTTP 405 with `Allow: GET`.
- Public batch doctor availability GET returned HTTP 200 for 32 doctors; `doctor_id=0` returned 400; POST returned 405 with `Allow: GET`.
- Registration compatibility endpoint returned HTTP 200 with `success=true` and `available=null` for both a known existing email and a synthetic nonexistent email; GET returned 405. The two probes did not expose account existence, and no account was created.
- Protected `current_user.php` returned HTTP 401 without a session.
- Result: **9 passed, 0 failed**. Tests were read-only; no database data or session/account records were changed.

### Authenticated route regression limitation
- The project database and target clinical fixtures were independently inspected read-only: doctor 3 has confirmed clinical evidence with patient 2; doctor 3's only appointment with patient 122 is Cancelled with no workflow row, visit note, or prescription.
- A combined authenticated HTTP attempt did not pass session validation. The browser tab currently shows an admin identity, but the connected browser interface is read-only and cannot run authenticated requests. Temporary synthetic session artifacts created during diagnosis were removed. No session forgery or bypass was retained.
- Therefore, admin-selected doctor appointments/ratings, doctor self-only and cross-doctor denial, and clinical positive/negative authorization remain pending a normal authenticated browser/API regression. Do not mark these as HTTP-tested based solely on the static or database QA.
- No clinical or account data was changed.

## 20. Public location lookup hardening and registration reference-data caching — 5 November 2026

- `api/auth/locations.php` now enforces GET with `Allow: GET`, accepts only a string `type`, and validates `governorate_id` as a strict positive integer instead of integer-casting arbitrary query text.
- `assets/js/core/auth.js` now memoizes the governorate lookup promise for the page lifetime and reuses the same list when resolving a selected governorate to its ID. Removed an unused selected-option lookup and the redundant second fetch of governorates for each city selection.
- PHP lint passed for `locations.php`; `node --check` passed for `auth.js`.
- Local HTTP regression: **7/7 passed**. Governorates returned 27 rows; valid governorate ID 1 returned 12 cities; partial numeric ID (`12abc`), array ID, array type, and unknown type returned 400; POST returned 405 with `Allow: GET`.
- No database rows were changed. The route is a public reference lookup and the tests were read-only.

## 21. Public authentication endpoint review — source checkpoint

- `forgot_password.php` is POST-only, uses a generic response, only exposes a reset URL on strict loopback development, limits requests to 3/email/hour and 5/IP/hour, invalidates prior unused tokens, and stores new tokens as SHA-256 hashes. Unknown/inactive addresses create tokenless attempt rows to keep the response uniform.
- `reset_password.php` is POST-only, verifies token hashes, locks the token and active user during consumption, updates the password and increments `session_version` in one transaction, and consumes all outstanding tokens for the email. No token is returned by verification-only mode.
- `logout.php` is POST-only and destroys the session; `locations.php` is now GET-only and strict-input validated; `register.php` is POST-only and retains duplicate checks with generic conflict messaging.
- The local `password_resets` table currently has **0 rows** (including 0 legacy plaintext-token rows); this was checked using counts only, without reading token values.
- Forgot/reset flows were not exercised over HTTP because they can create/reset token rows or change account credentials. Rate limiting for registration itself and email/phone ownership verification remain open under HB-AUTH-001.

## 22. Registration form cleanup after availability-probe removal — 5 November 2026

- Removed the obsolete `email-success`, `phone-success`, and `national-id-success` spans from `pages/auth/register.html`; after per-field availability probing was disabled, those elements were never populated.
- Removed their dead `clearFieldSuccess()` calls from the corresponding `auth.js` live validators. The emergency-contact phone success element and formatter remain because the active input handler still populates them.
- Static search found no remaining `checkAvailability` helper or `check-availability.php` URL in the JavaScript tree and no removed uniqueness-success IDs outside the intended emergency-phone feedback.
- `node --check assets/js/core/auth.js` passed after the cleanup. Full registration browser journey remains pending a normal authenticated/test flow; no account was created.
- HB-API-004 now also records this dead-UI cleanup. No database writes.

## 23. Patient audit compatibility route hardening — 5 November 2026

- `api/patients/get-audit.php` had no exact frontend API-path literal caller and no current application writer to `patient_audit_log`; the local table count is **0**. The route and table are retained because external/deployed installations may have legacy data or consumers.
- The route now rejects malformed/non-string `patient_id` and optional `limit` values using strict positive-integer parsing (limit remains capped at 100).
- Moved database acquisition and the doctor `ClinicalAccessService` check into the same `try` block, avoiding an uncaught DB failure and a duplicate connection for doctor reads. Preserved patient self-only and admin access scopes. Catch now handles `Throwable`.
- Updated the route header to describe its legacy compatibility status rather than claiming current profile-page callers.
- PHP lint passed. No route HTTP test was run because authentication is required and the browser connector cannot issue calls through the active session. No data was changed.

## 24. Registration city-selector stale-response protection — 5 November 2026

- `assets/js/core/auth.js` now assigns a monotonically increasing request ID each time `loadCities()` runs. After asynchronous governorate lookup and city fetch completion, the function ignores results whose request ID is no longer current. The catch/fallback path also checks the ID before replacing the selector with a text input.
- Clearing the governorate also increments the sequence and invalidates any prior in-flight request. This prevents an earlier, slower response from appending cities for a previously selected governorate after the user has selected a newer one.
- Node syntax check passed after the change. No live browser race test was run; the browser connector is read-only. Treat this as a static/concurrency guard improvement pending UI regression.

## 25. Admin profile phone validation consistency — 5 November 2026

- Static comparison found that public registration and admin patient creation use `validatePhone()` and `isPhoneTaken()`, while the admin patient and doctor update routes previously wrote phone values without the same checks.
- `api/patients/update.php` and `api/doctors/update.php` now validate a non-empty phone using the shared Egyptian phone validator and check normalized duplicates with the current account excluded. Malformed phone inputs return 400; normalized duplicates return 409. Non-string phone inputs are rejected.
- Existing local data was checked using aggregate counts only: 156 accounts have a non-empty phone; 152 fail the current strict validator, mainly synthetic `+2000...` placeholders and shortened `+2012...` demo values. There are **zero normalized duplicate groups**. No phone values/IDs were emitted and no records were changed.
- To avoid blocking unrelated edits on this legacy/demo data, an unchanged phone value is grandfathered. Validation and duplicate detection run only when a non-empty value is changed. A blank phone remains permitted.
- Pure helper QA confirms `01012345678` and `+201012345678` validate and normalize identically; a known valid row is detected in local and international form; excluding its own user ID avoids a self-conflict.
- PHP lint passed for both update routes. The update remains an application-level check; the schema has no normalized unique phone constraint, so concurrent duplicate edits are not fully race-proof. Authenticated admin edit journey remains pending.

## 26. Admin patient password-reset contract repair — 5 November 2026

- The Patient EMR reset modal posted only `patient_id` and `password` to `api/patients/update.php`, but that endpoint required `name` and `email` for every request. The reset action therefore failed validation before it could change the password.
- The endpoint now recognizes a password-only request (patient_id/password/confirm_password with no profile fields) and processes it separately under the existing admin role guard. It validates both strings and shared password strength, checks confirmation equality, locks the patient row, updates the password and increments `session_version` with an optimistic version condition, writes an admin audit event in the same transaction, commits, and then attempts a best-effort password-changed notification.
- `assets/js/pages/patient-emr.js` now reads/validates the modal's hidden target patient ID and sends confirmation along with the password. Success messaging explicitly states existing sessions were revoked.
- Removed the inert “Force password change on next login” checkbox and its JavaScript reset because no schema field or backend behavior existed. Updated modal minimum length/autocomplete hints to match the server-side password policy.
- `api/patients/update.php` now validates `patient_id` as a positive integer rather than loose integer-casting. PHP lint passed for the endpoint; Node syntax passed for patient-emr.js, admin.js, and auth.js.
- **No actual password reset was executed** because that would modify a real local patient credential and session version. Authenticated non-production HTTP/browser acceptance remains pending. No account credentials or DB rows were changed in this repair.

## 27. Strict identifier parsing for admin doctor updates — 5 November 2026

- `api/doctors/update.php` previously integer-cast `id` and `department_id`, so partially numeric strings could be coerced to valid identifiers.
- Doctor `id` now accepts only an integer or digit-only string within PHP integer range and must be positive. Optional `department_id` accepts only an integer/digit-only string within range; zero/omitted preserves the prior no-reassignment behavior. Malformed, partial, negative, and overflowing inputs return HTTP 400.
- PHP lint passed for `api/doctors/update.php`. A small read-only parser suite passed **11/11** valid/invalid type, partial-string, zero, and overflow cases.
- Authenticated admin edit workflow is not yet run in browser. No database changes.

## 28. Legacy admin profile and pending-reschedule endpoint cleanup — 5 November 2026

- `api/patients/profile.php` remains retained despite no exact frontend API-path caller. It is admin-only, GET-only, and now rejects non-string, partial numeric, zero, negative, and overflowing patient IDs with HTTP 400. Its error handler now catches `Throwable`.
- `api/appointments/get-pending-reschedules.php` is also retained despite no exact frontend caller. Its source/service contract is legitimate: doctors receive requests filtered by their own `doctor_id`; admins receive all pending/suggested requests; other roles are denied. The response now uses `jsonResponse()` consistently for success, forbidden and error branches and catches `Throwable`.
- PHP lint passed for both routes. No endpoint was deleted and no database data was modified.

## 29. Composer dependency constraint and visit-draft cleanup lifecycle — 5 November 2026

### Composer dependency reproducibility
- `composer.json` previously required `vlucas/phpdotenv` with an unbounded `*` constraint. The current lockfile and installed package are both `v5.6.4`.
- Changed the requirement to `^5.6.4`. Ran `composer update --lock --no-install --no-scripts --no-plugins`; Composer reported “Nothing to modify in lock file” and wrote the updated lock metadata hash. No package versions were upgraded or installed.
- `composer validate --no-check-publish` now validates the manifest and lock without the wildcard warning. The only warning remaining is that the project does not declare a license.
- HB-DEP-001 is locally fixed; an install/build smoke check remains pending. No dependency code was replaced.

### VisitDraftService cleanup policy
- `VisitDraftService::cleanupOld()` deletes rows in `visit_drafts` whose `updated_at` is more than seven days old. Repository search found no runtime/scheduler caller outside the declaration and audit/handoff documentation.
- The method is retained because the seven-day retention behavior may be deliberate and useful, but it is currently not scheduled. Do not invoke it against a live DB without a product-approved retention policy, backup/restore plan, and explicit CLI/scheduler execution path. No maintenance script was created and no draft rows were deleted.

## 30. Service-method cleanup and full source syntax validation — 5 November 2026

### Reference scan and removals
- Re-ran a whole-repository method invocation scan across PHP, JS, HTML, SQL, Markdown and JSON, excluding `.git` and `vendor`. The initial scan found 18 public methods with declaration-only references; it also distinguished same-class helpers and aggregate internals from real candidates.
- Removed exactly two low-value wrappers: `AuditService::getEntityAuditLog()` (a one-line delegation to `getAuditLog()` with filters) and `RescheduleService::getDB()` (an unused public accessor exposing the service's PDO connection). Both had no repository callers; neither had a documented external endpoint contract. No active call site required modification.
- Kept the remaining 16 declaration-only methods. They include useful service queries/metadata helpers, a documented reschedule-history capability, and maintenance routines (`VisitDraftService::cleanupOld()` and `VisitWorkflowService::migrateExisting()`). They remain candidates pending external-contract, product-retention, or explicit maintenance-tool decisions. No bulk deletion was performed.
- Corrected `NotificationService` docblocks: `getAllTypes()` and `getTypesByRole()` are available server-side metadata helpers; the current Settings UI has its own presentation map and does not call them.

### Validation
- Whole application PHP syntax sweep: **112 PHP files checked, 0 failures** (excluding `vendor` and `.git`).
- Whole application JavaScript syntax sweep: **33 JS files checked, 0 failures** (excluding `vendor` and `.git`).
- Both changed services also passed individual `php -l` immediately after wrapper removal.
- `composer.json` now constrains `vlucas/phpdotenv` to `^5.6.4`; `composer validate --no-check-publish` reports no wildcard warning, and `composer install --dry-run --no-scripts --no-plugins` confirms **Nothing to install, update or remove**. The only Composer validation warning is the missing project license declaration.
- No database writes, schema changes, clinical/account mutations, commits, pushes, or deployments. Branch/HEAD remain `repair/healthbridge-staged-hardening` / `d4dd731b7578d9309d8eca50ec57bb319459dade`.

### Findings/status
- HB-CLEAN-007: two redundant service wrappers removed locally.
- HB-DEP-001: bounded dotenv constraint fixed locally; lock/install dry-run verified.
- HB-CLEAN-006 remains open for the 16 preserved methods and retention/migration decisions.
- P1: 3 open / 3 locally fixed. P2: 7 open/investigate / 12 locally fixed / 2 locally resolved.

## 31. Local Apache document-root boundary HTTP verification — 5 November 2026

- Tested local Apache at `http://127.0.0.1/optimized` without authentication. The following sensitive paths all returned HTTP 403: `/.env`, `/.env.example`, `/.git/HEAD`, `/database/healthbridge.sql`, `/docs/HEALTHBRIDGE_REPOSITORY_AUDIT.md`, `/tools/test_dashboard_api.php`, `/services/ClinicalAccessService.php`, `/vendor/autoload.php`, and `/includes/auth.php`.
- Positive controls also passed: `/api/auth/locations.php?type=governorates` and `/index.html` returned HTTP 200.
- Result: **11/11 passed** (9 protected paths denied, 2 intended public paths allowed). No file contents/secrets were fetched; status codes only were recorded.
- This verifies the local Apache `.htaccess` containment. It does not verify production hosting, Nginx/managed-host rules, or whether the live deployment uses the same document-root layout. HB-SEC-007 remains open for deployed-host verification.

## 32. PatientPortalService CLI diagnostic on isolated restore database — 5 November 2026

- Reviewed `tools/test_dashboard_api.php` before running it. The script requires explicit opt-in, loopback `DB_HOST`, and a database name ending in `_test` or `_qa`; the PatientPortalService source path itself contains no SQL DML. Its hospital-status helper performs SELECT only and falls back in memory.
- Preflight against `healthbridge_restore_test` confirmed 25 base tables, `users.id=2` has the patient role, and the hospital-settings row already exists. The test ran with `DB_HOST=127.0.0.1`, `DB_NAME=healthbridge_restore_test`, and `HB_ALLOW_LOCAL_DB_DIAGNOSTICS=1`; it did not use the main `healthbridge` DB.
- All **12 individual methods** passed: getOverview, getHealthSnapshot, getAppointmentTimeline, getMedicalTimeline, getPrescriptions, getMedicalProfile, getNotifications, getFavorites, getHealthInsights, getDownloads, getHealthAlerts, getProfileCompletion.
- Full `getAll()` also passed and returned the expected 12 top-level dashboard sections. Result: **0 failures**.
- The diagnostic script and service were checked for writes; no account, appointment, clinical, audit, notification, or preference rows were changed. This is service-level CLI verification, not an HTTP or browser E2E suite.

## 33. Clinical EMR read boundary and prescription item batching — 5 November 2026

### Strict staff-supplied patient identifiers
- `api/medical/get-emr-data.php`, `api/medical/get-record.php`, and `api/medical/print-patient-emr.php` previously integer-cast the `patient_id` query parameter. They now require a digit-only string accepted by `FILTER_VALIDATE_INT` with a positive minimum. Partial numeric strings, arrays, zero, signed/whitespace values, leading-zero forms, and integer overflow are rejected with HTTP 400.
- Patient-role callers remain forcibly scoped to the authenticated user's own ID, regardless of any supplied `patient_id`. Doctor authorization still uses `ClinicalAccessService`; admin remains able to select any patient.
- All three read routes now catch `Throwable` for their generic error response. Existing GET-only and no-store behavior remains intact.
- The EMR GET readers were checked for missing-record behavior: `get-emr-data.php` and `get-record.php` return empty view models when a medical record is absent; they do not create a clinical row. `print-patient-emr.php` also only reads.

### Prescription-item N+1 removal in EMR aggregate
- `api/medical/get-emr-data.php` previously issued one `prescription_items` query per prescription. It now initializes item arrays and loads all items in bounded batches of up to 500 prescription IDs, grouping rows back by `prescription_id` and retaining `sort_order`.
- Read-only equivalence test against the local `healthbridge` database compared the previous per-prescription query result to the batched result for **99 prescriptions / 186 item rows**. There were **0 mismatched groups** and item row totals matched exactly. Item-query count fell from 99 to 1 for this dataset.
- Strict patient-ID parser regression passed **12/12** cases (valid positive values plus array, partial, signed, whitespace, zero, leading-zero, and overflow values).
- PHP lint passed for all three EMR read endpoints. No authenticated HTTP test was run because the browser connector cannot issue requests using the active user session; do not mark the protected routes as E2E-tested. The database comparisons were read-only and no clinical/account data changed.

## 34. Request-scoped appointment-duration lookup caching — 5 November 2026

- `includes/helpers.php::getAppointmentDuration()` previously called `getDB()` and `AppointmentTime::duration()` for every invocation. EMR, print, and appointment-history loops can call it repeatedly for appointments belonging to the same doctor.
- Added request-local static caching keyed by doctor ID and a single request-local PDO connection. Invalid/non-positive doctor IDs still return 30; database errors still cache the existing 30-minute fallback for that doctor during the request.
- Read-only QA compared the helper with direct `doctor_schedule_settings` values for eight distinct local doctor IDs: **8/8 matched**, repeated calls were stable **8/8**, and invalid ID retained the 30-minute fallback.
- `php -l includes/helpers.php` passed. No DB rows were changed. Cache lifetime is one PHP request; schedule changes are visible on the next request.

## 35. Clinical write input hardening, transactional drafts, and cancellation state guard — 5 November 2026

### Shared positive-ID parser
- Added `parsePositiveInt()` to `includes/validation.php`. It accepts a native positive integer or digit-only string in range; it rejects booleans, floats, arrays, signed/partial/whitespace strings, zero, and overflow. It is shared across three EMR read routes and the clinical write routes.
- Applied the parser to `api/medical/add-history-update.php`, `add-visit-note.php`, `complete-visit.php`, `delete-visit-draft.php`, `save-visit-draft.php`, `transition-workflow.php`, `update-record.php`, and `api/patient/cancel-appointment.php`.

### Clinical payload validation
- `update-record.php` now validates that correction reason is text and at most 1,000 characters; medical text fields are strings within the 10,000-character limit; height/weight are numeric; and `date_of_birth` is a real `YYYY-MM-DD` calendar date. Empty values retain the existing NULL/clear behavior.
- `transition-workflow.php` rejects non-string status input and only accepts the two allowed transition statuses (strict comparison). Draft deletion catches `Throwable`; save/delete failures roll their transaction back.
- Shared parser QA passed **17/17** valid/invalid cases. Calendar-date validation passed **5/5** cases. A source scan found **zero loose request `_id` integer casts** in the medical API directory.

### Draft write concurrency
- `save-visit-draft.php` and `delete-visit-draft.php` now begin a transaction, lock the qualifying appointment row, recheck doctor ownership/status, then perform the draft operation and commit. This serializes with the patient cancellation route, which already locks the same appointment first.
- Rollback-only service test on `healthbridge_restore_test` selected an eligible confirmed test appointment, acquired the same appointment lock, saved/read a synthetic draft, deleted it, then rolled back. The pre-existing draft row/hash was restored exactly. No test row was persisted.

### Cancellation after encounter start
- `api/patient/cancel-appointment.php` now uses the shared strict ID parser and continues to lock appointment/doctor/workflow state in one transaction. It rejects cancellation once workflow status is `In Progress` or `Ready to Complete`, in addition to the existing Completed guard. This avoids cancelling an active encounter that doctor note/completion routes still require to be Confirmed.
- Read-only local status audit found 387 Confirmed appointments, including 113 Waiting, 1 Ready to Complete, and 273 Completed; there are no In Progress rows. The sole Confirmed/Ready to Complete row is already at/past its scheduled datetime, so the old future-only cancellation condition also prevented cancellation for that fixture. The new guard protects the state generally; no appointment was changed.

### Validation state
- Parser/date QA: **17/17 + 5/5**. Draft service transaction test: save/read/delete inside transaction, rollback restores original state. Full route PHP lint and project-wide syntax sweep are being rerun after these final edits.
- No database writes were committed, no clinical record/account/appointment/draft was changed, and no browser-authenticated workflow was run. HB-API-008 is locally fixed; authenticated clinical mutation and cancellation regression remains pending.

## 36. Public registration rate limiting and step-1 enumeration reduction — 5 November 2026

### Registration flow changes
- `api/auth/register.php` now strictly parses the `step` input with shared `parsePositiveInt()` and rejects array/object values for all submitted registration fields before string operations.
- Step 1 (`handleStep1(array $input)`) now validates field formats, required values, and password rules only. It no longer checks whether email or phone exists, and it no longer opens a database connection. This removes the simple step-1 existence probe.
- Step 2 retains the existing generic duplicate response and atomic account/profile/preferences/audit transaction. It still performs uniqueness queries for email, phone, and National ID; successful creation versus duplicate failure therefore remains distinguishable at this final step.

### Host-local rate limiter
- Added `includes/AuthAbuseRateLimiter.php`, now shared by registration and sign-in. It uses an exclusive `flock()` around a state file stored in the host system temporary directory (outside the web root), plus a separately locked random HMAC key. Application-specific directory names avoid collisions with other local projects.
- Only HMACs of source IP, email, phone, and National ID are stored; raw identifiers are not written. IP is taken from `REMOTE_ADDR` only; untrusted `X-Forwarded-For` is ignored.
- Registration limits: Step 1 20 attempts per source IP per 10 minutes and 5 attempts per identifier per hour; Step 2 10 attempts per source IP per hour and 3 attempts per identifier per hour. Login limits: 10 attempts per normalized email and 60 per source IP per 15 minutes. Exceeded requests receive HTTP 429 with `Retry-After`; an unavailable/corrupt limiter store fails closed with HTTP 503.
- The initial registration-only limiter QA passed 6/6. After generalizing the class for login, combined limiter QA passed **8/8**: registration IP/identifier thresholds, login email/IP thresholds, raw-identifier non-storage, case-normalization, and malformed-identifier type handling. New/changed PHP lint and the final repository sweep are recorded below.
- This file-based implementation is host-local. Multi-server deployments need a shared Redis/database limiter; reverse-proxy deployments need a trusted client-IP policy. No migration or database schema change was made.

### Remaining P1 risk
- `check-availability.php` still returns `available: null` and does not query users. Step 1 is no longer an account-existence oracle. Step 2's duplicate result remains distinguishable from a successful registration, so final-step enumeration is reduced by per-identifier throttling, not eliminated. Email/phone ownership verification is still unavailable because no mail/SMS transport is configured.
- Read-only `SHOW INDEX FROM users` confirmed unique indexes for email and National ID but no unique index for phone. Phone uniqueness remains application-level and can race under concurrent submissions. Do not add a unique phone constraint until legacy values are normalized/audited and a forward migration is prepared.
- A live step-1 HTTP comparison was not run because the environment blocked the test request; do not describe the route as HTTP-tested. No account, user, medical record, or other DB row was created or modified.

## 37. Final syntax and local Apache boundary verification — 5 November 2026

- Final repository-wide syntax sweep after the clinical, registration, and login changes: **113 PHP files checked / 0 failures** (excluding vendor/.git), **33 JavaScript files checked / 0 failures**.
- Apache configuration check: `httpd.exe -t` returned `Syntax OK`.
- Composer validation: manifest and lock are valid; only the existing missing-license warning remains. `composer install --dry-run --no-scripts --no-plugins` reported **Nothing to install, update or remove**.
- `git diff --check` completed without whitespace errors; Git emitted only LF-to-CRLF normalization notices for existing working-copy attributes.
- Final local Apache document-root test passed **12/12**: ten protected internal paths returned 403, including `includes/AuthAbuseRateLimiter.php`; the public governorates API and `index.html` returned 200.
- Branch remains `repair/healthbridge-staged-hardening`; HEAD remains `d4dd731b7578d9309d8eca50ec57bb319459dade`. No commit, push, deployment, database migration, account mutation, or clinical-data mutation was performed.

## 38. Login brute-force throttling and disabled-account response privacy — 5 November 2026

- `api/auth/login.php` now uses shared `includes/AuthAbuseRateLimiter.php`, validates email/password as strings before database work, and throttles sign-in attempts by normalized email and source IP.
- Thresholds: **10 attempts per email and 60 attempts per IP in 15 minutes**. The shared limiter uses HMAC identifiers, exclusive file locks, host temp storage outside the web root, and `REMOTE_ADDR` only. A blocked request returns HTTP 429 and `Retry-After`; storage errors fail closed with HTTP 503.
- Authentication failure response is now uniform: unknown email, wrong password, and inactive/disabled account all return HTTP 401 with `Invalid email or password.` The previous disabled-account-specific HTTP 403 response has been removed.
- Combined registration/login limiter QA passed **8/8**, including login email and IP thresholds, normalized email case, registration IP/identity thresholds, raw-identifier non-storage, and malformed-input handling. No test account credentials were used and no DB rows were modified.
- This closes the direct disabled-account status/message disclosure and adds a local brute-force throttle. Authenticated HTTP/browser login acceptance remains pending. The limiter is a single-host store; multi-host deployment requires shared storage, and reverse-proxy deployment requires a trusted client-IP strategy.

## 39. Post-login integration validation — 5 November 2026

- After adding the login throttle and generic inactive-account response, the complete source sweeps passed again: **113 PHP files / 0 lint failures** and **33 JavaScript files / 0 syntax failures**.
- Apache configuration returned `Syntax OK`. Composer validation retained only the missing-license warning; the lockfile install dry-run reported nothing to install, update, or remove.
- Local Apache document-root checks passed **12/12** with the renamed `includes/AuthAbuseRateLimiter.php` returning 403; nine other internal paths were also denied, while the public governorates API and index returned 200.
- `git diff --check` returned no whitespace errors (only Git LF-to-CRLF normalization notices). Branch and HEAD are unchanged; no commit, push, deployment, migration, or DB/account/clinical data changes were made.

## 40. Login timing-side-channel mitigation — 5 November 2026

- The login route previously skipped `password_verify()` for an unknown email because its failure condition short-circuited. A dummy bcrypt/cost-12 hash is now verified for that branch before returning the same generic HTTP 401 used for wrong passwords and inactive accounts.
- Read-only metadata analysis found 157 local account hashes, all bcrypt/cost 12. A synthetic address with zero database matches was submitted through local Apache and returned 401 with the exact generic body; GET returned 405. The dummy hash passed positive/negative verification.
- This reduces the obvious password-hash-work timing signal but does not prove constant-time behavior. Comparative timing tests and authenticated browser login acceptance remain pending. Revalidate the dummy cost after password-hash policy changes.
- `HB-AUTH-003` is locally mitigated; P2 locally fixed count is 15. No database/account/clinical writes, commits, pushes, or deployments occurred.


## 41. Final post-fix verification — 5 November 2026

- After the login dummy-hash cost-12 update, full source syntax checks passed: **113 PHP / 0 failures**, **33 JavaScript / 0 failures**. Apache config returned `Syntax OK`; Composer validation passed with only the missing-license metadata warning; `git diff --check` returned exit 0.
- Local HTTP test reached the verified-nonexistent-email branch and returned HTTP 401 with the same generic login failure body; GET returned 405. Read-only local password-hash metadata count was 157/157 bcrypt cost 12. No hashes or account identifiers were printed.
- Removed all temporary test scripts/request files from `C:\Temp` and verified none remain. No database/account/clinical writes, commits, pushes, or deployments occurred. Branch/HEAD remain unchanged; P2 locally fixed count is 15.


## 42. Registration Step 1 HTTP smoke test — 5 November 2026

- Local Apache accepted a valid synthetic Step 1 registration payload with HTTP 200 (`success:true`, `next_step:2`). A read-only query confirmed zero `users` rows for that synthetic email afterwards.
- This verifies only the validation-only Step 1 success path. Step 2 was not submitted to avoid account/profile/clinical-record creation; duplicate-response enumeration, verification transport, and browser progression remain open under HB-AUTH-001.
- No database rows changed, and temporary request files were removed.


## 5 November 2026 — Portfolio refactor scope and dependency governance

The approved objective is to make the existing application reliable and professionally presentable as a portfolio project. **No new product features or user-facing workflows are in scope.** Refactor existing behavior, fix defects, harden security/data handling, optimize only evidenced bottlenecks, improve accessibility of current screens, remove only proven obsolete code, strengthen tests, and improve documentation/demo presentation.

See `docs/HEALTHBRIDGE_PORTFOLIO_REFACTOR_SCOPE.md` for the full in-scope/out-of-scope matrix, portfolio acceptance criteria, and selective-library gate. Candidate libraries are PHPUnit (existing-behavior tests), Symfony Validator (existing-input validation), swagger-php (documentation of current routes), Monolog (redacted structured logs if needed), and Doctrine Migrations (forward schema changes only after verified backup/restore). These are candidates, not an instruction to install all of them. No package or product capability was added at this checkpoint.

Keep PDO and the current application architecture unless a measured maintenance problem justifies a small component. A full framework/ORM migration is not planned. Before any dependency change, verify PHP/runtime support, extensions, transitive dependencies, advisories, licensing, Composer lock impact, and regression behavior. New user-visible security workflows require a separate scope decision.

**Portfolio readiness is evidence-based:** accurate README and installation instructions, architecture/data-flow documentation, polished but truthful screenshots using synthetic data, reproducible test/setup evidence, and clearly disclosed limitations. No compliance or production-readiness claim is implied.


## 43. Doctor workspace access-policy coverage correction — 5 November 2026

The previous HB-SEC-001 scan listed six endpoints, all of which used `ClinicalAccessService`. A wider cross-repo search found that `api/doctors/workspace-data.php` was a seventh patient-data route and retained the older `status IN ('Confirmed','Cancelled')` gate. Because it returns the broad patient workspace, it was a material coverage gap. The route now includes the shared policy and applies the same encounter-evidence decision; its patient and optional appointment query IDs are strictly parsed. The doctor-workspace JS caller was checked and already sends compatible numeric IDs.

PHP lint passed. On the isolated QA database the shared service matched the independent policy SQL for all four existing linked doctor/patient pairs. A session-scoped temporary-table fixture covered the cancelled-only negative path and all accepted evidence states. All **23 assertions passed**, including transactional lock-mode behavior. Temporary tables vanished with the test session, the temporary PHP test was removed, and no persistent application data or schema was changed. Authenticated browser/API regression remains open.


## 44. Notification API input and target validation — 5 November 2026

The unified notifications route used loose integer casts for target patient, page, and notification IDs. Its service queries were already properly scoped by `user_id`, but admin target selection lacked a role check. The endpoint now restricts methods to GET/POST with an Allow header; validates admin target as a strict positive integer and existing patient account; binds all other roles to the authenticated account; validates page/action/notification types; and rejects page-offset overflow. Invalid identifiers return 400.

A disposable session-scoped notifications table was used to test service behavior, not persistent application data. **23/23 assertions passed**, including own-user list/count, cross-user mark/delete attempts leaving foreign rows unchanged, own-user update/delete behavior, target validation, parser checks, and overflow guard. The temporary harness was removed. No persistent rows or schema objects changed.

Also verified role/resource scoping for `api/prescriptions/get.php` and `api/prescriptions/print.php`: patient and doctor lists are bound to the authenticated identity; single prescription reads/print are owner-checked; admin access is explicit. No code change was required. Authenticated browser regression remains open.


## 45. Schedule overview caller, strict IDs, and update payload shape — 5 November 2026

A second schedule source trace found a frontend caller missed by the earlier review. `assets/js/modules/schedule.js` used GET for a doctor's schedule, but the separate admin overview in `assets/js/pages/admin.js::loadAdminSchedules()` still posted JSON to the GET-only route. Local HTTP verified POST → 405 with `Allow: GET`; anonymous GET → 401. The overview now calls GET without a body and therefore uses the already-existing admin all-schedules path.

Schedule get/update/reset now strictly parse admin target IDs. Schedule update checks weekly/settings container types and nested field shapes before calling typed service validators; integer inputs are strict and the pre-existing UI maximum of 100 appointments/day is now enforced server-side as well. Focused QA passed 17/17, three PHP routes linted, and the changed admin JS passed Node syntax validation. No schedule data was changed. The authenticated admin browser journey remains to be verified.


## 46. Self-service profile and hospital settings validation — 5 November 2026

The self-service profile settings route now validates JSON field types and uses the same changed-phone format/normalized-uniqueness rules already used by admin profile edits. Unchanged legacy phone values remain grandfathered, and the optional phone is still clearable. Fifteen isolated helper/route assertions passed using a temporary users table; no persistent user rows changed.

The admin-only hospital update route now enforces typed text inputs, strict duration allowlisting, HH:MM time format, existing name/phone/email constraints, and a boolean-only confirmation value. It calculates the effective hours from supplied and stored values, catches schedule conflicts if either opening or closing time is changed, and no longer returns raw exception text to clients. Seventeen temporary-table service/route/UI assertions passed, including a single-sided hours conflict. No persistent settings/schedule records changed. Findings `HB-API-012` and `HB-API-013` are locally fixed; authenticated profile/admin-settings browser regression remains open.


## 47. Settings preference type/range correctness — 5 November 2026

The account settings route previously used loose truthiness for notification and doctor toggles; arbitrary non-empty strings could enable them. Doctor duration was integer-cast and not constrained to the existing number input's 15–120 range/5-minute step, malformed work-time values were silently ignored, and admin default-tab values were not limited to the current select. The route now validates these payloads consistently with the UI and verifies one-sided working-hour edits against the stored counterpart inside the current row-lock transaction. Focused regression passed **54/54**, PHP lint passed, and the temporary harness was removed. No persistent settings were modified. Authenticated browser acceptance remains open.


## 48. Contact form/reply input and post-save notification handling — 5 November 2026

The public contact form now validates JSON types before sanitization while retaining anonymous submissions and optional signed-in account association. The admin reply endpoint now strictly validates the contact message ID and text reply length, matching the existing 5000-character form control. Notification delivery is isolated after the reply is written so a failed notification does not falsely report the persisted reply as failed. Message list and reply are still admin-only. Focused QA passed **22/22**, both PHP files linted, and the temporary harness was removed. No persistent data changed. Authenticated admin/public browser acceptance remains open; a reliable notification outbox is still a separate open finding.


## 49. Legacy availability endpoint and unmatched read-route scope review — 5 November 2026

The retained registration availability compatibility route now rejects non-string `field`/`value` before calling trim, returns 400 for malformed values, and advertises its POST-only contract. Its response continues to report `available: null` after format validation and never checks account existence. Focused QA passed **21/21** and PHP lint passed. The remaining unmatched read endpoints were reviewed for their intended scopes (user-self messages/notifications, doctor-self/admin-all pending reschedules, admin-only ratings/contact inbox); they remain retained because a missing current frontend literal does not rule out external or old-client use. No persistent data changed.

## 6 November 2026 — Registration privacy and database-enforced phone uniqueness follow-up

- Replaced the final Step 2 duplicate-vs-success HTTP/body distinction with a shared neutral confirmation. The response no longer returns patient_number or user_id; the registration page displays neutral confirmation copy and routes to login. The patient number remains available in the authenticated patient profile/dashboard.
- Duplicate-key races during registration use the same neutral response. Password hashing is performed before the duplicate-result branch to reduce the obvious hashing-time difference; a residual timing side channel is still possible because successful registration performs additional transactional work.
- Added generated users.phone_normalized and uq_users_phone_normalized to the fresh schema and a forward-only Phase 14 migration. The normalization strips common whitespace/display separators and maps +20 to 0. The source phone value is not rewritten. isPhoneTaken() now queries the indexed generated column.
- Tested Phase 14 on a disposable copy. All 157 user/phone rows copied; preflight found zero normalized duplicates; the unique key rejected a second alternate-format insert with error 1062; PHP helper checks passed 3/3; the disposable schema was removed.
- Before applying Phase 14 to local healthbridge, created C:\xampp85\migration-backups\healthbridge_pre_phase14_20261106.sql (527,953 bytes; SHA-256 046AFE9971B41FC623959CA2CF74F823E27C9D52974739B86DFC011EFB720D26). The preflight reported zero normalized duplicate groups. The migration succeeded on MariaDB 10.11.16.
- Post-migration read-only verification: 157 users, 486 appointments, 25 tables, 1 NULL phone, 1 NULL generated key, zero normalized duplicates; SHA-256 of the ordered existing user-id/phone values is identical to the pre-migration hash (14c75b1213365522c1691ebd89a461d3c7e232643e04e7623c605a28deb1558f). The unique index is present. Root/register/login pages returned HTTP 200 and unauthenticated current-user returned expected 401.
- PHP lint passed for seven changed PHP files; Node syntax check passed for assets/js/core/auth.js. No authenticated Step 2 browser/API completion test was run because it would create a persistent patient record. No hosted database was touched; no commit, push, or deployment occurred.
- HB-AUTH-001 remains P1 open for contact ownership verification, residual timing-side-channel review, authenticated registration regression, and shared limiter storage before a multi-host deployment.

## 6 November 2026 — Notification outbox and bounded retry

- Centralized notification delivery now uses the Phase 15 notification_outbox table. NotificationService::create() checks the existing preference rule, records the event, and attempts immediate delivery when it is not called inside an open business transaction.
- When called inside a business transaction, the outbox insert uses the same PDO connection and is committed or rolled back with that business operation. The outbox has no recipient foreign key by design, avoiding the recipient-account FK lock that previously participated in appointment/account archival deadlocks.
- The dispatcher selects one pending event under a row lock, checks that the recipient still exists and still permits the notification type, then inserts into notifications and marks the outbox row delivered in the same transaction. A crash before commit rolls back both changes, so the next worker run does not duplicate the notification.
- Delivery errors are recorded using a non-sensitive error code; retry delays are 30 seconds, 60 seconds, 2, 4, 8, 16, 32, then 60 minutes. The eighth failure moves the event to failed for operator review. Missing recipients and disabled preferences are marked skipped. Delivered/skipped payloads are pruned after 30 days; failed rows are retained.
- Added CLI-only tools/process_notification_outbox.php (default batch 50, maximum 100). Existing tools/.htaccess denies HTTP access. A periodic host task must run this worker so failed events and events queued during a transaction are retried/delivered without waiting for a new notification event.
- Phase 15 migration rehearsal passed 15/15 across immediate delivery, transaction rollback/commit visibility, recovery after injected notification-insert failure, idempotent reprocessing, preference opt-out, and missing-recipient skip. QA schema/files were removed.
- Before applying the additive migration to local healthbridge, created C:\xampp85\migration-backups\healthbridge_pre_phase15_20261106.sql (530,603 bytes; SHA-256 5597F64F5D8CAC8B8E07CC28E248F78029AFDA0EDB4740D6D4B6A73F6C0C97D4). Migration succeeded. Post-check: 157 users, 486 appointments, 545 notifications unchanged; base table count 25 to 26; outbox empty; outbox has no foreign keys.
- Focused PHP lint passed for NotificationService and the worker. CLI worker smoke returned zero pending events; invalid limit was rejected; HTTP request to the tools worker returned 403. Root/register/login HTTP returned 200; unauthenticated current-user returned 401.
- HB-REL-001 remains P2 open because several existing call sites enqueue after the primary business transaction has already committed. A process crash in that narrow interval can still lose the event before the outbox row exists. Move those enqueue calls into the relevant business transactions in a separate bounded pass, and schedule/monitor the CLI worker before production use. No commit, push, or deployment performed.


## 50. Transactional outbox integration for appointment lifecycle — 6 November 2026

Five high-value appointment mutation handlers now persist their notification outbox event before committing the primary mutation: booking, admin approval, admin decline, doctor appointment-status update, and patient cancellation. An outbox insert failure therefore aborts the same business transaction instead of leaving a successful appointment change without its notification event. Each handler attempts a best-effort post-commit batch dispatch; pending rows remain recoverable through the CLI worker. Booking reuses its already-computed stored time range for the doctor/patient notification copy.

Focused verification: PHP lint 5/5; static enqueue-before-commit and dispatch-after-commit assertions 5/5; git diff --check passed; manual/local CLI worker returned all counters zero and exit 0. No authenticated appointment mutation or persistent DB write was performed. HB-REL-001 remains partially mitigated: reschedule, prescription, clinical, account lifecycle/archive, registration, rating, settings and support notification call sites still need individual transaction/lock-order review, and worker scheduling/monitoring remains an operational requirement. No commit, push, or deployment occurred.


## 51. Prescription and clinical visit notification transaction integration — 6 November 2026

PrescriptionService now enqueues the existing patient notices for create/update/complete/cancel inside each method's transaction, after its mandatory audit and before commit. The corresponding prescription API routes no longer attempt a second post-commit enqueue. The clinical complete-visit endpoint now queues its existing appointment-completed notice inside the same transaction as note, prescription status, workflow, draft and audit changes, then dispatches after commit. The outbox has no recipient FK, and no new user-facing notification type or workflow was added.

Verification: six PHP files linted; five static enqueue/commit/dispatch ordering checks passed; isolated synthetic-database service QA passed 18/18 for the four prescription operations and injected enqueue failure; visit-completion transaction QA passed 6/6 including full rollback on injected enqueue failure. The QA clone and temporary harnesses were removed. Actual local database counts remained 157 users, 486 appointments, 99 prescriptions and 545 notifications; outbox remained empty. A generated phone-normalization expression was simplified only in the disposable clone to work around its MariaDB import collation mismatch; phone normalization was not tested here. No actual local/hosted business rows, schema, or deployment were changed.


## 80. HB-REL-001 — transactional reschedule notifications (6 November 2026)

### Implementation
- Moved the existing notification enqueue calls into the seven transaction-owning `RescheduleService` mutations: `requestReschedule`, `suggestReschedule`, `acceptRescheduleSuggestion`, `declineRescheduleSuggestion`, `cancelRescheduleRequest`, `approveReschedule`, and `rejectReschedule`.
- Each transition now records its existing audit event, enqueues the existing recipient notifications through `NotificationService::create()` on the same PDO transaction, and only then commits. A notification preference read or outbox insert failure therefore reaches the existing service catch/rollback path and prevents a state change from committing without its required event.
- After commit, a shared best-effort dispatcher calls `NotificationService::processOutbox(50)`. Delivery failure is logged and leaves committed events for the existing CLI worker; it does not convert a successful reschedule into a false failure.
- Preserved notification types, titles, message text, recipients, reference IDs, route payloads, authorization checks, state transitions, slot revalidation, and audit calls. No duplicate notification logic was added to API routes.
- The `approveReschedule` unavailable-slot path still rolls back before sending its explanatory notification. That branch commits no appointment mutation to couple to an outbox event, so its independent notification remains best-effort after rollback.
- Preserved the existing lock order: pre-transaction doctor ID lookup, doctor `users` row lock, then appointment row lock. `getAppointment(..., true)` continues to lock only `appointments` and read patient display name without a locking join. Notification preferences use a normal non-locking SELECT, and `notification_outbox` intentionally has no recipient FK.

### Verification
- PHP syntax: `services/RescheduleService.php` passed.
- Transaction-order assertions: **7/7** notification enqueue calls now precede the corresponding commit; all seven dispatch calls follow commit.
- Disposable MariaDB integration: **64/64 assertions passed** across all seven service operations. Each operation was tested both normally and with a temporary `BEFORE INSERT` trigger forcing an outbox failure. In every forced-failure case the service operation failed, appointment state rolled back, and no outbox row remained. In every normal case the expected transition committed and all newly queued events reached a delivered/skipped terminal state with no pending events.
- Two-connection lock-order regression passed: an archive-style transaction locked the patient row first and then waited on the appointment; a concurrent reschedule-style transaction locked doctor then appointment, performed the non-locking patient/preferences reads, and inserted to the no-FK outbox. Both transactions committed; no deadlock was reported.
- QA source was an isolated copy of the local synthetic `healthbridge` database (157 users, 486 appointments, 99 prescriptions, 545 notifications). A generated `phone_normalized` collation mismatch was corrected only in the disposable SQL dump using a simplified expression; the real schema and generated column were not changed.
- Disposable schema and test harnesses were removed after testing. The SQL dump and test logs remain under `C:\xampp85\migration-backups\healthbridge`.
- Read-only post-test counts from the real local database remained **157 users / 486 appointments / 99 prescriptions / 545 notifications / 0 outbox rows**. No real appointment, audit, notification, preference, or schema row was changed.
- No commit, push, deployment, hosted database operation, or branch change was performed. Branch and HEAD remain unchanged.

### Remaining under HB-REL-001
- HB-REL-001 remains **P2 open / partially mitigated**. Transactional enqueue now covers the five appointment routes, all seven reschedule workflow mutations, four prescription mutations, and clinical visit completion.
- Remaining producers still require individual transaction and lock-order reviews: other clinical writers, account lifecycle/archive/status paths, registration/doctor creation, ratings, settings, and support replies. Do not bulk-move them or undo prior archive/deadlock protections.
- Periodic CLI worker scheduling and monitoring remain operational requirements. No Windows scheduled task or hosted scheduler was configured in this local pass.
## 81. HB-REL-001 — additional clinical notification writers (6 November 2026)

### Implementation
- `api/medical/add-visit-note.php` now enqueues the existing `TYPE_VISIT_NOTE_ADDED` event after its visit-note write and required audit, but before the existing transaction commits. The event therefore commits or rolls back with note creation/update and audit. Prompt delivery is attempted through `processOutbox(50)` after commit; dispatch errors are logged and left for the CLI worker.
- `api/medical/update-record.php` now enqueues the existing `TYPE_MEDICAL_RECORD_UPDATED` event inside the same transaction as the record update and universal audit, then attempts delivery after commit. Doctor/admin message wording, type, recipient, and `medical_record` reference are unchanged.
- Preserved existing authorization, validation, account lock ordering, appointment locking, and audit semantics. Doctor clinical paths continue to lock doctor then patient then appointment where applicable; administrator medical-record updates lock the patient before the medical-record row. The outbox still has no recipient FK and preference reads remain non-locking.

### Verification
- PHP syntax passed **2/2** for the changed endpoints; targeted `git diff --check` passed (only existing LF/CRLF working-copy warnings).
- Disposable integration on the existing `healthbridge_repair_test` schema passed **23/23 assertions** across visit-note create, visit-note update, and medical-record update. Each operation was tested with a forced `BEFORE INSERT` outbox failure and with normal delivery. Forced failures rolled back the associated clinical write and audit with no outbox event; successful operations committed and delivered their existing notice.
- The older repair-test schema was missing Phase 15's outbox table and the current `visit_notes.follow_up_instructions` column. The Phase 15 outbox migration and the missing visit-note column were applied only to `healthbridge_repair_test` so the fixture matches the current app schema. Test data was restored afterward: appointment 1 has no QA note, patient 2's medical_notes value was restored, and QA admin_audit/notifications/outbox rows were removed. The test schema remains aligned with the current application.
- Read-only verification of the populated local `healthbridge` database remained **157 users / 486 appointments / 99 prescriptions / 545 notifications / 0 outbox rows / 216 visit notes / 124 medical records**. No populated local clinical, audit, or notification rows were changed.
- No commit, push, deployment, hosted database operation, or branch change occurred.

### Remaining under HB-REL-001
- Transactional enqueue now also covers the remaining identified clinical API notification producers (`add-visit-note.php` and `update-record.php`). The completed clinical group is appointment lifecycle, seven reschedule transitions, four prescription mutations, visit completion, visit-note create/update, and medical-record update.
- Next review group: account lifecycle/archive/status and registration/doctor creation notification producers. Preserve the account-first lock order and the prior archive/reschedule deadlock fixes; do not move notifications in bulk.
- Ratings, settings, and support replies still require transaction ownership review. Periodic CLI worker scheduling and monitoring remain outstanding; no Windows scheduled task was created.
## 82. HB-REL-001 — patient/doctor status and doctor archive notifications (6 November 2026)

### Implementation
- `api/patients/toggle-status.php`: the existing `TYPE_ACCOUNT_STATUS_CHANGED` event is now enqueued after the status/session-version update and audit event but before commit. If enqueue fails, the patient status and session-version change roll back with the audit. Delivery runs only after commit.
- `api/doctors/toggle-status.php`: appointment-cancellation events and the doctor's account-status event are now enqueued in the same transaction as not-started appointment cancellation, doctor status/session-version change, and admin audit. Delivery is attempted only after commit.
- `api/doctors/delete.php` (reversible archive): patient appointment-cancellation events are enqueued in the same transaction as appointment cancellations, doctor sign-in disablement, and archive audit. Delivery is attempted only after commit.
- Preserved existing message text, recipients, notification types, reference IDs, response payloads, authorization, active-visit guards, appointment eligibility, and clinical-history retention.
- Lock safety: doctor status/archive still locks the doctor row before appointment/workflow rows. The outbox intentionally has no recipient FK and `NotificationService` preference reads are non-locking; enqueuing patient events while appointment rows are held therefore does not reacquire patient account rows. This preserves the previous fix for the patient-archive/doctor-archive deadlock. No account lock order was changed.

### Verification
- PHP syntax passed **3/3** for patient status toggle, doctor status toggle, and doctor archive endpoints.
- Disposable `healthbridge_repair_test` integration passed **25/25 assertions**. It exercised forced `BEFORE INSERT` outbox failure and normal operation for all three lifecycle paths. Forced failures rolled back patient/doctor `is_active` and `session_version`, appointment cancellations, audit rows, and notification-outbox rows. Successful runs committed state changes and delivered the expected account/cancellation notices.
- Doctor fixtures included two confirmed not-started appointments with `Waiting` workflows. The active-visit protection was preserved; the test did not alter the workflow state.
- The older disposable repair schema lacked `users.session_version`; it was added to `healthbridge_repair_test` only, with the same unsigned integer/default contract as the current application. No schema change was made to the populated local `healthbridge` database.
- After testing, QA patient/doctor `is_active` and `session_version` values and both appointment statuses were restored. QA admin-audit/outbox rows created by the test were deleted. The two pre-existing QA notifications were preserved. Final QA check: admin_audit=0, notifications=2, notification_outbox=0; users 1/2/3 active with session_version=1; appointments 1 and 3 returned to Confirmed.
- Migration backup copies of all three original endpoint files are under `C:\xampp85\migration-backups\healthbridge`.
- No commit, push, deployment, hosted database operation, branch change, or modification to the populated local `healthbridge` data was performed.

### Remaining under HB-REL-001
- Account-creation notification producers reviewed in this group: patient profile/password updates, doctor creation, and patient registration (Sections 83–85).
- Ratings (`api/doctors/submit-rating.php`), settings (`api/settings/update.php`), support replies (`api/settings/reply-contact-message.php`), and other support/contact event producers still need transaction-ownership review.
- Periodic `tools/process_notification_outbox.php` scheduling and monitoring remain outstanding. No Windows scheduled task or hosted scheduler was configured.

### HB-REL-001 — patient update notification transaction (Section 83, 2026-11-06)

- Updated api/patients/update.php. The password-only reset now enqueues TYPE_PASSWORD_CHANGED inside the credential/session-version/audit transaction. The general profile path now enqueues its existing account-status and profile-updated events inside the profile/medical-record/status/audit transaction.
- Both paths dispatch up to 50 committed outbox events after commit. Dispatch exceptions are logged and leave durable events available to the CLI worker. Enqueue errors are intentionally not swallowed inside the transaction, so the business mutation and its outbox events roll back together.
- Preserved notification recipients, types, titles, message text, reference type/ID, response payloads, permissions, validation, existing audit behavior, and patient-row-first locking. No additional row locks or recipient foreign-key checks were introduced.
- Backup: C:\xampp85\migration-backups\healthbridge\patients-update_pre_HB-REL-001_20261106.php.
- Verification: PHP syntax passed; source-order assertions passed for the password-only and profile/status paths; legacy post-commit create blocks are absent; git diff --check passed. Integration failure-injection against this route was not run in this checkpoint, so rollback behavior is not claimed as route-level integration-tested here.
- No database operation was performed during this code change. The populated local healthbridge database and the disposable QA database were not changed by this checkpoint. No commit, push, deployment, hosted database operation, or branch change.

### Remaining under HB-REL-001

- Account-creation notification producers reviewed in this group: patient profile/password updates, doctor creation, and patient registration (Sections 83–85).
- Ratings (api/doctors/submit-rating.php), settings (api/settings/update.php), support replies (api/settings/reply-contact-message.php), and other support/contact event producers still need transaction-ownership review.
- Periodic tools/process_notification_outbox.php scheduling and monitoring remain outstanding. No Windows scheduled task or hosted scheduler is configured.

### HB-REL-001 — doctor creation notification transaction (Section 84, 2026-11-06)

- Updated api/doctors/add.php. The active-admin recipient query and existing TYPE_NEW_DOCTOR enqueue calls now run before the doctor-creation transaction commits, after the user, doctor profile, user preferences, and required admin audit row have been written.
- The transaction now rolls back the doctor account, profile, preferences, audit, and outbox events together if a notification event cannot be enqueued. Up to 50 committed events are dispatched after commit; dispatch failures are logged and left for the CLI worker.
- Preserved active-admin recipient selection, notification type/title/message/reference, doctor fields/defaults, department validation, audit payload, authorization, and success response. Recipient and preference reads remain non-locking; no additional row locks were introduced.
- Backup: C:\xampp85\migration-backups\healthbridge\doctors-add_pre_HB-REL-001_20261106.php.
- Verification: PHP syntax passed; source-order assertions passed; the legacy post-commit create block is absent; git diff --check passed. Route-level database failure-injection testing was not run in this checkpoint.
- No database operation was performed during this code change. The populated local healthbridge database and disposable QA database were not changed. No commit, push, deployment, hosted database operation, or branch change.

### Remaining under HB-REL-001

- Account-creation notification producers reviewed in this group: patient profile/password updates, doctor creation, and patient registration (Sections 83–85).
- Ratings (api/doctors/submit-rating.php), settings (api/settings/update.php), support replies (api/settings/reply-contact-message.php), and other support/contact event producers still need transaction-ownership review.
- Periodic tools/process_notification_outbox.php scheduling and monitoring remain outstanding. No Windows scheduled task or hosted scheduler is configured.

### HB-REL-001 — patient registration notification transaction (Section 85, 2026-11-06)

- Updated api/auth/register.php. Step 2 now queries active admin recipients and enqueues the existing TYPE_NEW_PATIENT events before committing the patient account, default preferences, full medical record, and required patient audit entry.
- NotificationService is now required at the route header because its class is used inside the registration transaction. If any admin event cannot be enqueued, the inner transaction handler rolls back the complete registration. Up to 50 committed events are dispatched after commit; dispatch failures are logged and left for the CLI worker.
- Preserved Step 1 validation-only behavior, Step 2 validation, duplicate-identity response behavior, patient-number generation, patient fields, audit payload, admin recipient selection, notification content/type/reference, and public registration response. Admin recipient and preference reads remain non-locking; no new row locks were introduced.
- Backup: C:\xampp85\migration-backups\healthbridge\register_pre_HB-REL-001_20261106.php.
- Verification: PHP syntax passed; source-order assertions passed for the service include, notification enqueue before commit, and dispatch after commit; the legacy post-commit create block is absent; git diff --check passed. Route-level database failure-injection testing was not run in this checkpoint.
- No database operation was performed during this code change. The populated local healthbridge database and disposable QA database were not changed. No commit, push, deployment, hosted database operation, or branch change.

### Remaining under HB-REL-001

- Account creation notification producers reviewed in this sub-group: patient profile/password updates, doctor creation, and patient registration.
- Review any additional support/contact event producers not yet inventoried. api/settings/contact.php currently stores contact submissions without creating notifications.
- Periodic tools/process_notification_outbox.php scheduling and monitoring remain outstanding. No Windows scheduled task or hosted scheduler is configured.

### HB-REL-001 — doctor rating notification transaction (Section 86, 2026-11-06)

- Updated api/doctors/submit-rating.php. The rating insert, doctor average-rating recalculation, existing doctor notification, and existing first-admin notification now share one transaction.
- Duplicate-rating, missing-appointment, and non-completed-visit exits explicitly roll back the read transaction. Any exception rolls back the rating, aggregate update, and queued events together. The existing recipient selection, notification types/content/references, and success/error responses are preserved.
- Notification delivery is attempted only after commit. Dispatch failures are logged while committed events remain available to the CLI worker. No additional row locks or recipient foreign-key checks were introduced in this checkpoint.
- Backup: C:\xampp85\migration-backups\healthbridge\doctors-submit-rating_pre_HB-REL-001_20261106.php.
- Verification: PHP syntax passed; source inspection/static assertions passed for enqueue-before-commit, dispatch-after-commit, explicit early rollback, and exception rollback; git diff --check passed. Route-level database failure-injection testing was not run.
- No database operation was performed during this code change. The populated local healthbridge database and disposable QA database were not changed. No commit, push, deployment, hosted database operation, or branch change.

### Remaining under HB-REL-001

- Settings profile and doctor self-deactivation notifications still need transaction-ownership review in api/settings/update.php.
- Support reply notifications still need review in api/settings/reply-contact-message.php, along with other support/contact event producers.
- Periodic tools/process_notification_outbox.php scheduling and monitoring remain outstanding. No Windows scheduled task or hosted scheduler is configured.

### HB-REL-001 — user settings notification transaction (Section 87, 2026-11-06)

- Updated api/settings/update.php. The existing TYPE_PROFILE_UPDATED event is now enqueued inside the profile update, user-row lock, and audit transaction. The existing doctor self-deactivation appointment-cancellation events are also enqueued inside the same transaction as appointment cancellations, account deactivation/session-version change, and audit.
- Each branch dispatches committed events after commit. An enqueue failure now rolls back the related profile/deactivation mutation; dispatch failures are logged and left for the CLI worker.
- Preserved profile event wording/reference, doctor-only cancellation recipients/content/type/reference, response payloads, password and preference-only settings behavior, and existing account/appointment/admin lock ordering. Notification recipient and preference checks remain non-locking; the outbox has no recipient FK.
- Backup: C:\xampp85\migration-backups\healthbridge\settings-update_pre_HB-REL-001_20261106.php.
- Verification: PHP syntax passed; source-order assertions passed for profile and doctor self-deactivation; legacy post-commit create blocks are absent; git diff --check passed. Route-level database failure-injection testing was not run.
- No database operation was performed during this code change. The populated local healthbridge database and disposable QA database were not changed. No commit, push, deployment, hosted database operation, or branch change.

### HB-REL-001 — support reply notification transaction (Section 88, 2026-11-06)

- Updated api/settings/reply-contact-message.php. The route now starts a transaction, locks the selected contact_messages row with FOR UPDATE, updates reply/replied_at, and queues the existing TYPE_SUPPORT_REPLY event for a linked user before commit.
- A failed event insert rolls back the support reply. The event is dispatched after commit; dispatch failures are logged and left for the CLI worker. Anonymous contact messages still save replies without a user notification.
- Preserved reply validation, recipient, message content, event type/reference, and success/error responses. The contact-message row is the only newly introduced lock; notification preference reads are non-locking and the outbox has no recipient FK.
- Backup: C:\xampp85\migration-backups\healthbridge\reply-contact-message_pre_HB-REL-001_20261106.php.
- Verification: PHP syntax passed; source-order and rollback assertions passed; git diff --check passed. Route-level database failure-injection testing was not run.
- Reviewed api/settings/contact.php: it persists inbound contact messages but does not currently create notification events, so it was not changed or represented as an outbox producer.
- No database operation was performed during this code change. The populated local healthbridge database and disposable QA database were not changed. No commit, push, deployment, hosted database operation, or branch change.

### Remaining under HB-REL-001

- Review any additional support/contact event producers not yet inventoried. api/settings/contact.php currently stores contact submissions without creating notifications.
- Periodic tools/process_notification_outbox.php scheduling and monitoring remain outstanding. No Windows scheduled task or hosted scheduler is configured.

### HB-REL-001 — notification producer inventory and worker operations (Section 89, 2026-11-06)

- Completed a repository-wide search of NotificationService event creation and direct notifications/notification_outbox writes in api, services, and tools.
- Confirmed api/patient/cancel-appointment.php was already transactional: doctor account lock precedes appointment/workflow locks, the patient cancellation event is enqueued before commit, and dispatch occurs after commit.
- Confirmed api/settings/contact.php currently stores inbound contact messages without creating notification events. The only direct notifications-table insert outside NotificationService is in tools/seed_demo_hospital.php, the demo-data seeder; it is not a runtime event producer.
- Remaining runtime producer work is limited to any additional support/contact route not yet found by the source search. Appointment, reschedule, prescription, clinical, account creation/status, rating, settings profile/deactivation, and support-reply producers have been reviewed in the preceding checkpoints.
- Worker behavior verified by source inspection: CLI-only (HTTP requests return 404); default batch size 50; accepts --limit=1..100; prints delivered/skipped/retried/terminal_failed/pending counts; exits nonzero when terminal failures occur or the worker throws.
- No Windows Scheduled Task or hosted scheduler has been registered. Automatic delivery therefore still depends on post-commit dispatch attempts and manually running the CLI worker. Scheduler cadence, run identity, log retention, and failure alerting remain an operational decision.
- Suggested local invocation from the project root: C:\xampp85\php\php.exe C:\xampp85\htdocs\optimized\tools\process_notification_outbox.php --limit=100.
- No database operation was performed for this inventory checkpoint. No commit, push, deployment, hosted database operation, or branch change.

### HB-REL-001 — local notification outbox scheduled worker (Section 90, 2026-11-06)

- Created tools/run_notification_outbox_worker.ps1, a PowerShell wrapper that invokes C:\xampp85\php\php.exe tools/process_notification_outbox.php --limit=100, records start/output/exit-code lines, and removes daily worker log files older than 30 days.
- Registered Windows Scheduled Task HealthBridge Notification Outbox Worker with a five-minute repetition interval. Its first manual test run completed successfully (Last Result 0) and logged delivered=0 skipped=0 retried=0 terminal_failed=0 pending=0.
- Task settings: overlap policy IgnoreNew; four-minute execution limit; starts missed runs when available; allowed to start and continue on battery.
- Logs are written outside the web project at C:\xampp85\logs\healthbridge\notification-outbox\worker-YYYY-MM-DD.log.
- Permission constraint: the Desktop Commander session is not running with an elevated administrator token. Registration as NT AUTHORITY\SYSTEM was rejected with Access is denied, so the working task was registered under the configured local Windows account with Interactive logon. It runs every five minutes while that Windows user is logged in; it does not run while the user is signed out. A machine-wide/background task would require an elevated administrator registration.
- Monitoring currently consists of daily log output, task Last Run Time/Last Result, and worker exit codes. No email/push alerting is configured. Inspect the latest daily log and Task Scheduler result when investigating failures.
- The worker itself is CLI-only. No application/schema changes and no database writes were made by the scheduler setup beyond whatever the worker processes; the initial run found the queue empty and processed zero events.
- No commit, push, deployment, hosted database operation, or branch change.

### HB-REL-001 operational follow-up

- If unattended processing while signed out is required, register the same task under SYSTEM from an elevated administrator session. Do not duplicate the current task; update its principal and retain its five-minute trigger/logging settings.
- Consider external alerting for repeated worker failures/terminal outbox failures after the local runtime is stable.
## Current status reconciliation - 6 November 2026

- This repository audit remains active. File census, route counts, and unused-file candidates are point-in-time snapshots; revalidate against the current working tree before deleting or moving anything.
- HB-REL-001 notification implementation and verification limits are recorded in root handoff Sections 80-90. No broad module rewrite has been approved; the scope remains maintenance/refactoring of existing behavior without new product features.
- The local five-minute Windows task runs the notification CLI while the configured local Windows account is logged in. Its wrapper lives under tools; logs are outside the repository at C:\xampp85\logs\healthbridge\notification-outbox. Registration as SYSTEM was unavailable because the session was not elevated.
- The working tree remains intentionally uncommitted. Do not reset, clean, stash, or overwrite existing repair work. No commit, push, deployment, or hosted database operation occurred during the 6 November notification/scheduler work.

## 8 November 2026 — Local Apache access-boundary verification

The local XAMPP Apache instance was active and the existing root/directory `.htaccess` rules were inspected without changing them. HTTP checks against `http://localhost/optimized/` returned:

| Request | Result |
|---|---:|
| Public homepage | 200 |
| Core JavaScript asset | 200 |
| `includes/fragments/navbar.html` | 200 |
| Public `api/auth/locations.php?type=governorates` GET | 200 |
| `.env` (HEAD only; response body not requested) | 403 |
| `.git/config` | 403 |
| `database/healthbridge.sql` | 403 |
| Internal roadmap document | 403 |
| `tools/process_notification_outbox.php` | 403 |
| `services/NotificationService.php` | 403 |
| `includes/db.php` | 403 |
| `vendor/autoload.php` | 403 |

This proves the tested local Apache paths are denied while the tested public entry points/fragments remain reachable. It does **not** prove every internal path is protected, nor does it verify the hosted Vercel/demo host or any non-Apache deployment. Keep deployed-host boundary verification open. No application source, Apache rule, SQL, or database row was modified during this check. The public location-lookup GET executed its normal read-only database SELECT path; its response body was discarded.

**Current source count correction:** the working tree now contains 114 PHP files (89 API, 13 service, 9 includes, 3 CLI tools), 32 JS files, 22 root/page HTML files plus 2 reusable fragments, and 22 CSS files. The 5 November repository census of 113 PHP files predates the current three-tool tree and is historical.

## 8 November 2026 — Doctor workspace route trace

The doctor clinical workspace is entered through pages/doctor/doctor-workspace.html, which loads core/main.js, modules/prescriptions.js, pages/doctor-workspace.js, and modules/notifications.js. The workspace controller uses GET api/doctors/workspace-data.php for the chart aggregate and calls the visit workflow, visit-note, draft, medical-history, medical-record, prescription-read, and prescription-create endpoints. Patient EMR get-emr-data.php/get-record.php are separate patient-EMR routes rather than direct workspace-controller calls.

The workspace aggregate applies the shared ClinicalAccessService policy before returning patient data. Its read model spans users, appointments, medical_records, visit_workflow, visit_notes, prescriptions, prescription_items, and medical_history_updates. Writes are routed to focused APIs/services; visit completion is designed to commit the note, prescription finalization, workflow status, draft deletion, audit, and notification-outbox event atomically, then dispatch after commit.

Two draft API issues were corrected locally: delete-visit-draft.php now derives doctorId from the authenticated doctor before the typed account-lock call; get-visit-draft.php now strictly parses appointment_id and returns 400 for malformed values. PHP lint passed for both; strict parser QA passed 9/9; the isolated PHP 8.5.12 undefined-variable probe reproduced the original TypeError. Authenticated HTTP/database mutation regression remains open.

Performance follow-up: doctors/workspace-data.php still hydrates prescription items with one query per prescription. The existing bounded batch implementation in medical/get-emr-data.php does not cover this endpoint. Tracked as HB-PERF-002; convert only with response-equivalence verification. The workspace-data GET also includes NotificationService and VisitWorkflowService files without directly invoking either class; review as a harmless include-cleanup candidate after behavior tests.

## 8 November 2026 — Clinical draft HTTP regression and shared prescription hydrator

A standalone QA harness was created outside the application tree at `C:\xampp85\migration-backups\healthbridge\test_clinical_draft_and_workspace.php`. It connects only to loopback `healthbridge_repair_test`, starts a temporary PHP built-in HTTP server, creates synthetic users/appointments/workflows/prescriptions/drafts, exercises real API endpoints with synthetic PHP sessions, and removes its fixture records in `finally`. It does not read `.env` and does not target `healthbridge`.

The final run passed **41/41 assertions**. Coverage includes draft get/save/delete authentication, strict ID parsing, method/Allow behavior, doctor ownership, inactive account rejection, completed workflow access rules, the owner lifecycle, and patient/doctor read-path prescription equivalence. All fixture table counts returned to their pre-test baseline: users 13, doctors 8, appointments 6, visit_workflow 6, visit_drafts 0, prescriptions 1, prescription_items 1. The temporary HTTP server was no longer listening after completion.

### Duplicate query logic consolidated

`PrescriptionService` already had a private batch hydrator used by its `search()` method. It is now exposed as the focused reusable `attachItems()` read-model helper, with an optional count-key argument; the original private `withItems()` wrapper preserves the service's `items_count` contract. Both `api/doctors/workspace-data.php` and `api/medical/get-emr-data.php` now use this one helper. This removes the doctor-workspace N+1 item query, the EMR's duplicate batch implementation, and both per-prescription item-count subqueries while retaining the `items` arrays, item ordering, and `item_count` values. Exact equality against independent ordered SQL passed in the HTTP suite.

Unused `NotificationService.php` and `VisitWorkflowService.php` includes were removed from the doctor workspace aggregate; an unused `NotificationService.php` include was removed from the patient EMR aggregate. No file/service/API was deleted.

### Legacy file retirement review

`assets/js/pages/patient-portal.js` (2,006 lines) still has no current HTML/script reference in the repository source scan. Current patient pages load nested route-specific `assets/js/pages/patient/*.js` controllers. Git history for the legacy file shows an earlier July 2026 commit; no browser/runtime/external-entry-point proof has been gathered. Keep it as a candidate and do not delete until every patient page and dynamic/external loader is verified. `assets/css/pages/patient-portal.css` is actively referenced by multiple current patient pages and must be retained.

### Verification

- PHP lint: **114/114 application PHP files passed** (`api`, `services`, `includes`, `tools`; vendor/.git excluded).
- Targeted PHP lint: PrescriptionService, doctor workspace data, patient EMR data, and the external integration harness all passed.
- `git diff --check` returned no whitespace errors; Git emitted existing LF-to-CRLF working-copy warnings across dirty paths.
- No production-like `healthbridge` database connection or migration was used. The only persistent DB writes were synthetic fixture setup/cleanup in `healthbridge_repair_test`; final table counts matched baseline.
- No commit, push, branch switch, deployment, or staging operation occurred.

## Patient portal asset and dependency audit — 8 November 2026

- Resolved the contradictory legacy-controller notes: `assets/js/pages/patient-portal.js` was already absent from the working tree as an unstaged deletion before this audit. The July 2026 Git baseline dashboard page loaded `assets/js/pages/patient/dashboard.js`, not the legacy 2,006-line controller. No current app-source or dynamic-loader references were found.
- Local Apache returned **200** for all 10 `pages/patient/*.html` files and all linked first-party JavaScript/CSS assets, and **404** for the removed script. This was an HTTP/static-asset smoke check, not authenticated browser acceptance.
- All nine standard patient portal pages still load `assets/css/pages/patient-portal.css`; retain it. All 32 remaining JavaScript assets had a source filename reference; no additional unreferenced JS candidate was found.
- Mapped the page controllers and their API dependencies in the handoff/module map/endpoint inventory. Seven page controllers call the shared `api/patient/dashboard.php` endpoint.
- New finding HB-PERF-003: the dashboard endpoint invokes all 12 `PatientPortalService::getAll()` sections on each normal request, even where a page controller consumes only a subset. This may cause unnecessary query work and payload transfer. Impact is unmeasured; preserve the contract until exact page requirements and disposable-DB response equivalence are tested.
- No app code, CSS, API, database, or schema changed. No file was newly deleted; the existing legacy-script deletion was not staged or committed.


## Patient dashboard aggregate query/payload reduction — 8 November 2026

- Mapped every current caller of `api/patient/dashboard.php`: one dashboard full-response caller and six section-page callers. The admin analytics `getAll()` reference belongs to a different service and was excluded.
- Added the single ordered section-to-existing-method allowlist in `PatientPortalService`; `getSelected()` validates the complete list before querying, while `getAll()` retains the full 12-section contract by delegating to the same map.
- Updated downloads/history/insights/notifications/prescriptions-fallback/profile controllers to request only their consumed sections. Dashboard, search, authentication, method contract, and default full response are unchanged.
- Reproducible read-only PDO measurement on `healthbridge_repair_test`, active patient ID 2: full aggregate 35 statements / 19,959 JSON bytes; selected requests: downloads 3/1,757, history 4/2,946, insights 15/5,112, notifications 3/1,321, prescriptions 2/648, profile 5/2,199. Across the six individual page requests: 210 → 32 SQL statements (84.8% fewer), 119,754 → 13,983 bytes (88.3% fewer).
- HTTP regression 17/17. It used the actual API via an isolated local PHP server and a synthetic session; it verified 401, 405/Allow, full response compatibility, each selection, invalid list 400s, and search precedence. Existing service regression passed all 12 methods plus getAll/timeline checks with zero failures.
- Full syntax checks: PHP 114/114 and JS 32/32. `git diff --check` exit 0. Database counts unchanged (13 users, 8 doctors, 6 appointments, 6 workflows, 0 drafts, 1 prescription, 1 item); HTTP server port 19877 was closed after tests.
- Harnesses are stored outside the application under `C:\\xampp85\\migration-backups\\healthbridge`; no new app file, endpoint, package, database schema, or user-facing feature was added. Larger-data performance and actual logged-in browser walkthrough remain open.


## Authenticated browser acceptance and schedule caller repair — 8 November 2026

- Browser-tested seven patient pages with synthetic patient ID 2: dashboard (full 18-key legacy response), downloads, history, insights, notifications, prescriptions, and profile. The focused prescriptions GET succeeded; a forced failure verified the selective `prescriptions` fallback. All six selective endpoints returned only requested keys; no runtime/console/failed-local-request errors or horizontal overflow were observed.
- Browser-tested doctor dashboard and doctor workspace with synthetic doctor ID 3, and admin dashboard/admin-doctor-profile with synthetic admin ID 1. The workspace read patient ID 2 / appointment 1. Final staff test blocked state-changing requests and recorded zero attempted writes.
- Reproduced an admin doctor-profile Schedule-tab defect: the UI used POST against `api/schedule/get.php`, a GET-only route, and received 405. Corrected `assets/js/pages/admin-doctor-profile.js` to use GET with the selected `doctor_id` in the query string. Re-test: schedule summary loaded with HTTP 200; all four staff pages passed without API/page/console errors.
- Full PHP syntax: 114/114; full JavaScript syntax: 32/32; diff check 0. Test database counts stayed at baseline (users 13, doctors 8, appointments 6, workflow 6, drafts 0, prescriptions 1, items 1, notifications 2, medical records 4, visit notes 1, ratings 1, doctor schedules 8, weekly schedules 56). Temporary QA servers were shut down and ports closed.
- All testing targeted `healthbridge_repair_test`; no populated `healthbridge` operation, database write, migration, new application test harness, commit, push, or deployment occurred. Remaining browser acceptance: patient appointments, settings, and Patient EMR.


## Patient appointments, settings, and Patient EMR browser acceptance - 8 November 2026

- Completed authenticated read-only Chrome acceptance for the three remaining page entries in the Section 98 handoff. Patient ID 2 loaded pages/patient/appointments.html and pages/patient/settings.html; admin ID 1 loaded pages/patient/patient-emr.html?patient_id=2.
- Appointments passed initial list load, confirmed/all filter changes, and a search for Cardiology. The focused api/patient/appointments.php GET returned HTTP 200 for all four list states. The test did not trigger booking, cancellation, rescheduling, rating, or other mutation controls.
- Settings passed api/settings/get.php (200); Account, Password, Appearance, and Notifications sections were shown, while Practice and Admin tabs were hidden for a patient. No password/profile/preference/theme/deactivation submission occurred.
- The Patient EMR controller was verified as admin-only by its requireServerRole('admin') initialization. The data aggregate and lazy Audit-tab GET returned 200, and all nine EMR panels rendered content. No clinical, appointment, account, prescription, rating, notification, or archive actions were executed.
- The browser blocked non-read HTTP methods before server arrival and reported zero attempted/blocked writes. All observed API responses returned 200; no page errors, console errors, failed local requests, or horizontal overflow were observed.
- Only healthbridge_repair_test was queried. Counts matched the Section 98 baseline (13 users, 8 doctors, 6 appointments, 6 workflows, 0 drafts, 4 medical records, 1 prescription, 1 prescription item, 1 visit note, 2 notifications, 1 rating, 8 doctor schedule settings, 56 weekly schedule rows). No database mutation or migration was performed.
- Temporary PHP servers were closed (no listening sockets on 19880/19881); the dedicated Chrome profile was removed. Harness remains outside the application under C:\xampp85\migration-backups\healthbridge.
- No application code/schema change, commit, push, or deployment. The Section 98 remaining authenticated page acceptance item is superseded.


## Section 100 - Public entry-point browser and data-contract review - 8 November 2026

Completed an anonymous browser pass for all five root public pages against loopback healthbridge_restore_test. Home rendered nine departments and the 8:00 AM–10:00 PM hospital-hours summary. Doctors rendered eight active profiles, nine department chips, and eight availability records; its Cardiology search returned one result, and the guest doctor-detail modal opened/closed without navigating or writing. Services category filtering and FAQ accordion passed; its optional current_user GET returned expected 401 anonymously. Contact form fields and the map container rendered, but the form was not submitted. About content rendered normally.

No page exceptions, failed local requests, horizontal overflow, or browser write attempts were recorded. One generic Chromium network-console 401 message corresponded to the expected anonymous current_user check from Services; there were no JavaScript runtime errors. All pages returned HTTP 200.

The Contact form's implementation was traced and a data-flow concern recorded: contact.js posts the full name/email/phone/department/subject/message payload to the local contact endpoint and then unconditionally sends the same fields to Formspree. The on-page notice does not identify this external recipient. No message was sent during QA. Open finding HB-SEC-008 (P1): confirm whether external mirroring is intended and disclose/obtain appropriate consent before modifying this flow.

The doctor detail template includes unsupported static claims (100+ patient count, Medical Degree/Board Certified, English/Arabic) for every doctor; none are represented in the current doctor schema/API response. Open finding HB-DATA-001 (P2); no profile claims were changed.

Both public doctor GET endpoints now set HTTP 500 in their existing exception catch blocks. PHP lint passed. The availability route was fault-reproduced against healthbridge_repair_test, which lacks the requested reschedule columns despite those columns being present in the canonical schema/active migration; after the patch it returns 500 instead of a misleading 200 failure payload. The same endpoint succeeds against migrated healthbridge_restore_test for eight doctors, and the doctors directory GET returns eight doctor records. A POST to the GET-only availability route returned 405/Allow: GET. No database writes or migration were performed.

The two disposable fixtures differ: repair_test is missing requested_reschedule_date/requested_reschedule_time; restore_test contains both and was used for this browser pass. No populated healthbridge or unrelated DB was accessed, and .env contents were not inspected. The external QA router/runner remain under C:\xampp85\migration-backups\healthbridge.


## Section 101 - Authentication surface and API dependency audit - 8 November 2026

Audited login, registration and reset-password pages; Forgot Password is embedded in Login. No SMTP/PHPMailer/mailer implementation was found in app PHP/JS or Composer. forgot_password.php returns a reset URL only when APP_ENV is development and DB host, HTTP host and remote address are all loopback; non-loopback/deployed requests return 503. No recovery request or external email was triggered.

Login: empty submit gave client errors and no login API call; Forgot Password panel open/back worked. Registration: governorates GET returned 27 rows; empty Step 1 produced six field errors and did not POST. Step 1 is validation-only; Step 2 creates account/profile/preferences/audit/outbox in one transaction. No actual registration was attempted. Reset: no-token path showed invalid state without API; invalid synthetic verify_only call returned 400 and is SELECT-only. Synthetic token test mocked verification and final submit inside the browser; no actual credential change occurred.

HB-AUTH-004 was reproduced and resolved: reset page required all five password conditions while registration/shared validation accepts four of five; reset meter used the wrong second length test. Abcdefg1 was valid/Strong in the shared PHP helper but blocked/Fair in reset UI. The reset page's visible minimum and expiry copy were stale (six characters/30 minutes vs shared four-of-five and 60-minute expiry). reset-password.js and reset-password.html now align with the shared validator and API lifetime. Post-fix browser QA rejected three-of-five, accepted four-of-five and displayed Strong; final submit was mocked in-browser.

HTTP contracts: six POST-only auth routes returned 405 / Allow: POST on GET; locations GET returned 200 with 27 governorates and POST returned 405 / Allow: GET; current_user returned 401 anonymously; invalid synthetic verify_only token returned 400. Restore-test counts were unchanged: users 13, medical_records 4, user_preferences 13, admin_audit 0, notifications 2, password_resets 0.

Registration depends on notification_outbox via NotificationService. The canonical schema/Phase 15 migration define the table. repair_test has notification_outbox but lacks Phase 10 requested-reschedule columns; restore_test has those reschedule columns but lacks Phase 15 notification_outbox. This is disposable-fixture schema drift only; neither was migrated or written in this section. No populated healthbridge or unrelated DB was accessed. No .env contents were inspected.


Open finding HB-AUTH-005 (P2): production password recovery is deliberately disabled by the forgot-password endpoint until a mail transport and safe delivery flow exist. No recovery request or email was sent in this audit.


## Section 102 - API/service dependency and compatibility pass - 8 November 2026

A repository source scan enumerated 89 API PHP files: all 89 contain an explicit request-method check and all 89 contain an Allow header in the method-rejection paths. The combined notifications endpoint intentionally supports GET/POST. Nine routes do not call a central auth helper; they are the intended public login, registration, recovery, logout, location, public doctor list and public availability paths. This is a static contract census, not exhaustive HTTP testing of every route.

All 13 service classes were mapped to their API callers and core table domains. Caller counts overlap (for example, a business route may use ClinicalAccessService, AuditService and NotificationService together). A source extraction found direct SQL/table literals in 68 endpoints. No source-code refactor was performed.

The seven existing HB-CLEAN-001 route candidates were checked for exact path references across current JS/HTML and each returned zero references. They remain because the data contracts are not all exact duplicates:
- Pending reschedule list overlaps the doctor appointments response, but remains a separate admin-all/doctor-self collection.
- The legacy patient notification reader overlaps the unified NotificationService API but uses an older latest-20 response contract.
- Patient audit reads patient_audit_log; the EMR audit-history tab reads medical_record_audit. No application writer to patient_audit_log was identified; retain pending historical-data verification.
- The admin ratings route returns a system-wide dataset, unlike the current per-doctor review panel.
- The admin patient profile aggregate and the user's own support-message endpoint contain legacy support-message contracts that are not proven equivalent to the active EMR/admin contact inbox.
- Registration availability is now format-only and neutral, with no current caller.
No route/table was deleted, disabled or renamed.

Anonymous HTTP acceptance against the loopback disposable restore fixture returned 401 for the six protected candidate routes plus the current unified notifications route. GET to check-availability returned 405 / Allow: POST. This confirms anonymous denial for these routes, not authenticated resource/role correctness or external compatibility. The PHP requests terminate at auth before business data is selected.

HB-API-025 records three catch blocks that return success:false JSON without setting an HTTP error status: doctors/get-rating.php, patients/get-audit.php and settings/contact.php. The shared jsonResponse helper does not assign a status. This is a source-level error-contract concern; no runtime failure was injected and no code changed. Contact submission remains prohibited, including for QA.

No .env values were inspected. No SQL writes/migrations or populated database access occurred. The disposable fixture counts remained unchanged. All QA artifacts are outside the repository. Git branch/HEAD and pre-existing dirty/staged state were preserved.


## Section 103 - Reproduced read-route failure-status defects (8 November 2026)

The source-level concern HB-API-025 was reproduced without database access. An isolated PHP loopback HTTP router loaded the actual doctor-rating and patient-audit endpoint source with only bootstrap includes removed, a synthetic patient identity, a stub response function and a fake PDO object that throws before connecting or issuing SQL.

Before correction both actual endpoint catch paths returned HTTP 200 with success:false. Added one http_response_code(500) line to each existing catch block. The same fault-injection requests now return HTTP 500 / success:false with the existing generic JSON error messages. Method and input/ownership regressions passed: POST 405 / Allow: GET for both, invalid appointment ID 400, and patient cross-record audit request 403.

The contact endpoint was explicitly excluded. It was not executed, submitted, or fault-injected. Its portion of HB-API-025 remains open. No database connection or database operation occurred in this section.


### Section 103 follow-up - HB-API-025 Contact catch (8 November 2026)

The Contact catch body was independently tested without running the endpoint or submitting form data. A temporary loopback PHP harness tokenized contact.php and executed only its verbatim catch body under a synthetic Exception. It bypassed request parsing, validation, DB access, persistence, and delivery. The unmodified catch fragment returned HTTP 200 / success:false. Adding http_response_code(500) changed the same fragment to HTTP 500 / success:false; the original generic JSON message is unchanged.

All three HB-API-025 exception-response branches now assign HTTP 500. The finding is resolved at catch-branch level, not a full Contact workflow certification. No contact submission or DB access took place.


### HB-DATA-001 - public doctor-profile claims corrected (8 November 2026)

Removed unsupported patient-volume, education/board-certification and language claims from the public doctor card/modal. The modal's inferred specialization marketing paragraph is now a plain department value. The card retains experience and patient rating; the modal retains Experience, Department, Patient Reviews, and schedule-backed fields where available.

Real public page + actual JS/CSS browser test passed with all three API requests intercepted/mocked: two supported card stats only, no unsupported text, modal information titles verified, mobile 390px layout passed, zero page errors. No API/database access or doctor-record modification occurred. HB-DATA-001 is locally resolved; live production acceptance remains unperformed.


### HB-SEC-008 - contact-form third-party forwarding removed (8 November 2026)

The contact form now posts only to the same-origin HealthBridge contact API. Removed Formspree configuration, FormData copy, external fetch and external-success fallback. The form shows success only when the local API succeeds. Updated contact.html with a plain-language HealthBridge-only data-flow notice and removed unsupported "secure" heading language.

Mocked Chrome regression used intercepted local API responses and blocked all external requests: one local request per simulated submit; zero Formspree requests; success resets form; failure preserves values and shows an error; zero JS page errors. No request reached PHP/database and no real contact message was submitted. HB-SEC-008 is locally resolved; deployment verification remains pending.


## 8 November 2026 — Deployed web-root exposure: browser-confirmed static paths

A Chrome session loaded the public homepage (HTTP 200). Browser GET requests using Range bytes=0-0 and immediate cancellation after response headers returned HTTP 206 for /.gitignore (377 bytes), /README.md (18,343), /composer.json (59), and /database/healthbridge.sql (27,985). No response body was read. These results confirm those static internal files are publicly retrievable.

Local Apache remains protected (16/16 tested sensitive paths return 403), but the original hosted project has not been updated with local .htaccess files or post-repair removal of old migration executors. .env, .git/HEAD, and .htaccess returned no status; PHP paths and docs were ambiguous; no conclusion is drawn about those files. Historical migration executors were not probed or executed.

The current status page for InfinityFree's free subdomain service is marked Disrupted, while the linked unresolved incident is specifically about howto.rocks; this does not prove the cause of this freehosting.dev issue. Official references and full test detail are recorded in Section 106 of the handoff and the findings register.

HB-SEC-007 remains P1 and is now a confirmed partial deployed exposure. No hosted account/file manager/FTP, server configuration, production file, or hosted database was accessed or modified. Remediation needs explicit authorization for a controlled, backed-up hosting-file deployment and repeat browser checks.


## 8 November 2026 — Hosted web-root hardening follow-up (HB-SEC-007)

After explicit user authorization, the InfinityFree dashboard/File Manager confirmed the live site root as `/htdocs`. A complete pre-change File Manager ZIP was downloaded before editing: `C:\xampp85\migration-backups\healthbridge\healthbridge-htdocs-backup-2026-11-08.zip`, 4,192,860 bytes, SHA-256 `48575F48EB317B74263E3986EF2FFBFC92666493626655470B3E8E73AC3B4D51`, with 381 entries / 22 root-level names. The archive contains `.env` and SQL path entries, but their contents were not inspected. Its Windows ACL was explicitly restricted to `the configured local Windows account`, SYSTEM and Administrators.

Only the root `.htaccess` was written to hosting (724 bytes). Post-change Chrome tests returned 200 for the homepage and five public HTML pages, 403 for six internal directory roots, and 403 for 12/16 sensitive-path probes. The four old 206 exposures—`.gitignore`, README, Composer JSON and database SQL—now return 403. Four dotfile paths (`.env`, `.git/HEAD`, `.git/config`, `.htaccess`) still end with empty connection/no status; do not count them as blocked based on that result. No response bodies were read. The exact rule set passed a disposable local Apache test.

No application source, API implementation, historical migration executor, hosted DB, or DB record was changed. No SQL was executed. The hosting remediation is partial: keep HB-SEC-007 P1/open pending conclusive status for the remaining paths and separate non-executing verification of remote historical script presence.


## Hosting boundary and repository workflow update — 8 November 2026

The user has explicitly changed the deployment approach: the hosted HealthBridge website is now hands-off. The local repository at `C:\xampp85\htdocs\optimized` is the sole working target for the ongoing repair audit. Do not access hosting File Manager, FTP, control panel, remote filesystem, or hosted DB for routine work. Do not modify/upload/delete hosted files, change server configuration, remove remote scripts, synchronize local changes, or run any hosted DB operation.

A public-host check is allowed only when specifically required and must be read-only/non-mutating. Do not use live login, booking, forms, administrative actions, API mutations, or database access. Prefer local Apache and isolated synthetic-data databases for all QA.

The already-applied remote `/htdocs/.htaccess` remains untouched. HB-SEC-007 remains P1 / partially mitigated / open: four dotfile probes are inconclusive and the remote status of six historical migration executors is unknown. No local source status or Apache response should be used to infer the current remote file tree.

Preserve the existing branch and uncommitted work. Continue local implementation and tests, review the final diff, then prepare a sanitized runtime-only deployment package. After a fresh hosted-file backup and rollback plan, a full clean replacement upload from the start may occur only with explicit user approval. Database changes are separate and are not implied by deployment. This policy update is documentation-only.


## HB-SEC-001 authenticated-route regression and print privacy correction — 8 November 2026

A local authenticated loopback suite exercised seven clinical patient-data routes against only the synthetic `healthbridge_repair_test` fixture. It passed 22/22 after fixing a response side channel in `api/medical/print-patient-emr.php`: doctors are now authorized through ClinicalAccessService before the patient row is looked up, so an existing unauthorized patient and nonexistent ID both return 403. This closes the tested identifier-existence distinction while preserving admin and patient behavior.

The suite confirmed allowed doctor read paths, cross-doctor denials, patient self-scope, the doctor-only workspace guard, and rejection of unassigned-doctor medical-history/record writes. The monitored medical_records, medical_history_updates, visit_notes, admin_audit, notifications, and notification_outbox counts remained unchanged. The external test runner is `C:\xampp85\migration-backups\healthbridge\test_hb_sec_001_clinical_routes.php`. Existing clinical draft/workspace HTTP tests separately passed 41/41 and restored the disposable fixture. HB-SEC-001 remains open for broader clinical workflow and session-revocation coverage. No hosted account, file, or database was accessed.


## HB-SEC-002/003/004/005 role and session regression — 8 November 2026

Four read endpoints were exercised through a local loopback HTTP harness against the synthetic `healthbridge_repair_test` fixture. The corrected suite passed **18/18**: contact inbox role matrix and method guard; seven-field public/patient/doctor hospital settings versus full admin settings; user support-message session scoping despite a forged `user_id` query parameter; and admin-only inactive department visibility. A companion suite passed **8/8** for stale session-version rejection (401), disabled doctor hospital-settings denial (403), and disabled admin contact-inbox denial (403). Temporary synthetic account flags/version values were restored exactly in finally blocks and checked against snapshots. No application records changed. Runners are outside the repo in `C:\xampp85\migration-backups\healthbridge`.


## HB-SEC-009 local security-header hardening — 8 November 2026

The repository root `.htaccess` now applies X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Permissions-Policy, a narrow enforced CSP, and HTTPS-only HSTS to static resources and Apache responses, and removes X-Powered-By. `includes/response.php` mirrors the narrow CSP for PHP fallback. Apache module support and syntax were confirmed locally. HTML/CSS/JS and protected 403/API 405 responses carried the expected headers with no duplicates; HTTPS emitted HSTS while HTTP did not; API response no longer exposed X-Powered-By. Origin/Fetch Metadata tests passed 10/10. Five related suites passed 99/99 after the shared change. Full script/style CSP is deliberately not enabled while current code still depends on external font/icon/chart/map origins and has not completed inline-code migration. Host verification remains deferred by user instruction.


## HB-DB-002 authenticated booking-route acceptance — 8 November 2026

An authenticated local loopback HTTP suite ran the actual booking endpoint against the synthetic `healthbridge_repair_test` fixture and passed **28/28**. It confirmed invalid role/ID/department/doctor selections are rejected, inactive or ambiguous selections cannot be booked, and valid patient self-booking, admin-assisted booking and the unique legacy-name contract persist canonical doctor/patient identities and queue the expected events. Three generated appointments and all dependent audit/notification/child records were removed. Ten monitored table counts and temporary user/profile/department values matched their exact pre-test baseline. Before the suite could run, the disposable fixture alone was updated using the existing idempotent Phase 10 migration to add two missing reschedule columns and three canonical indexes; no application source or actual database changed. External runner is stored under `C:\xampp85\migration-backups\healthbridge`.


## HB-API-006 admin password reset HTTP evidence — 8 November 2026

An authenticated local loopback test of the actual admin patient-password reset route passed **18/18**. Anonymous and non-admin roles were denied; weak and mismatched inputs failed before mutation; admin reset changed the synthetic hash, incremented `session_version` once, wrote one audit event and queued/delivered the password-changed notification. The patient's prior PHP session was rejected with 401. The synthetic user's original password hash/version/timestamp were restored exactly, and all test-created audit/notification/outbox rows were deleted. The runner is outside the repository under `C:\xampp85\migration-backups\healthbridge`; no real credential or hosted system was involved.


## HB-API-001 available-slot request validation — 8 November 2026

A source review found that the available-slot route's POST guard was present but its input handling still coerced partial numeric doctor IDs and called trim() on untyped dates. The route now uses the shared strict positive-integer parser, requires a string date, and applies calendar-aware validation. The authenticated local HTTP regression passed **21/21** for malformed IDs/dates, method behavior, past/unknown doctor handling, valid slot response equivalence and off-day messages. Four monitored data-table counts remained unchanged. No write was made by the route or test. The actual booking wizard browser journey remains a separate acceptance task.


## HB-API-003 doctor-profile route acceptance — 8 November 2026

Both doctor-profile GET routes were exercised through authenticated local loopback HTTP against synthetic fixture roles. The 34 request assertions passed across admin target selection, doctor self-scope, patient/anonymous denial, ID shape/overflow handling, method guards, appointment patient_id alias and patient-identity anonymization in ratings. Shared `parsePositiveInt()` replaced weaker ctype/cast parsing in both routes. Five database counts were separately confirmed unchanged after the dedicated QA server was stopped; no app records were written. PHP lint, Apache syntax and diff checks passed. The actual admin profile tab in a browser remains a separate acceptance gate.


## HB-AUTH-002/003 login runtime verification — 8 November 2026

The local login endpoint was exercised through the actual PHP route using an isolated loopback server and the synthetic `healthbridge_repair_test` fixture. The **20/20** suite verified method/input contracts, generic 401 equivalence for wrong-password and nonexistent-email cases, 10-per-email throttling with HTTP 429 on attempt 11, Retry-After, and HMAC-only limiter persistence. It restored/confirmed no application-row or synthetic credential change and removed its separate limiter/session temp files. No source change was necessary.

The runner is external to the repository at `C:\xampp85\migration-backups\healthbridge\test_hb_auth_002_003_login_http.php`. Remaining boundaries: HB-AUTH-003 comparative timing measurements; HB-AUTH-002 shared limiter storage for multi-host deployment; HB-AUTH-001 ownership verification deferred to the final maintenance phase.


## HB-API-002 complete HTTP method-guard verification — 8 November 2026

A final route inventory and local batched loopback regression now covers every current `api/**/*.php` route: **89/89** rejected unsupported PUT with HTTP 405 and a valid Allow header; total suite was **90/90 assertions**, including a full table/view row-fingerprint, count, and AUTO_INCREMENT before/after equality check on the disposable repair database. Sessions were synthetic and role-matched; no request body was sent. The PHP test runner is outside the repository at `C:\xampp85\migration-backups\healthbridge\test_hb_api_002_method_contract_http.php`. No application source was changed.


## HB-SEC-001 cancelled clinical evidence and session runtime check — 8 November 2026

The actual EMR and doctor-workspace route pair was tested against synthetic cancelled appointment states. No evidence (Waiting workflow, no note/prescription) returned 403; an In Progress workflow and, separately, matching note/prescription documentation returned 200. Admin access, patient self-scope, stale session_version rejection (401) and inactive doctor rejection (403) were also verified. The **14/14** suite restored the exact appointment/workflow/user snapshots and full disposable database content/count/AUTO_INCREMENT fingerprints; isolated session files were removed. Runner is external to the repo at `C:\xampp85\migration-backups\healthbridge\test_hb_sec_001_cancelled_evidence_http.php`. No source change was needed.


## HB-API-008 clinical write route runtime verification — 8 November 2026

An authenticated synthetic-fixture loopback test passed **37/37** assertions across all eight clinical write/cancellation endpoints. It verified strict malformed input rejection, role/assignment boundaries, blocked premature completion, draft ownership, workflow transitions/rework, medical-record/admin correction validation, and cancellation prevention for In Progress/Completed encounters. The valid workflow write was restored; full disposable DB row hashes/counts/AUTO_INCREMENT values and medical-record values matched baseline. Runner is outside the repository at `C:\xampp85\migration-backups\healthbridge\test_hb_api_008_clinical_write_http.php`. No app source changed.


## HB-API-007 authenticated read/profile runtime check — 8 November 2026

The strict-ID/read-contract runner passed **32/32** local authenticated HTTP assertions covering admin patient profile, admin doctor update validation, three medical readers and the pending-reschedule route. It verified malformed ID/type rejection, patient self-scope, doctor assignment denial, role-specific route access and consistent JSON response envelopes. Complete disposable DB row hashes/counts/AUTO_INCREMENT values stayed unchanged; isolated session files were removed. External runner: `C:\xampp85\migration-backups\healthbridge\test_hb_api_007_read_contract_http.php`.


## HB-API-010 notifications route runtime verification — 8 November 2026

The actual notification endpoint passed **32/32** synthetic-role authenticated HTTP assertions across GET/POST contracts, role-targeting rules, self-scope, all four actions, and cross-user denial. The entire original notification table and AUTO_INCREMENT value were restored exactly; full disposable database row fingerprints/counts/AUTO_INCREMENT values matched baseline. Runner is external at `C:\xampp85\migration-backups\healthbridge\test_hb_api_010_notifications_http.php`. No application source changed.


## HB-API-009 patient pagination runtime fix — 8 November 2026

Actual HTTP testing found `$page` missing from the `withDB()` callback capture in `api/patient/appointments.php`, leaving `pagination.current_page` null. Added the missing closure capture. The repeat suite passed **22/22** and confirmed complete disposable DB fingerprint/count/AUTO_INCREMENT equality. Runner: `C:\xampp85\migration-backups\healthbridge\test_hb_api_009_patient_pagination_http.php`. Full browser pagination acceptance remains open.


## HB-API-011 schedule API runtime verification — 8 November 2026

Actual schedule overview/detail/update/reset routes passed **38/38** synthetic-role HTTP assertions covering allowed methods, role boundaries, doctor self-scope, malformed target IDs and nested schedule inputs, and reset constraints. No valid write was attempted; complete disposable DB row fingerprints/counts/AUTO_INCREMENT values remained identical. External runner: `C:\xampp85\migration-backups\healthbridge\test_hb_api_011_schedule_http.php`.


## HB-API-012/013 settings API regression and no-side-effect correction - 8 November 2026

The combined self-settings and hospital-settings HTTP suite passed 39/39. It exposed a side effect from eager INSERT IGNORE in the settings route: rejected requests advanced user_preferences.AUTO_INCREMENT. A lazy ensure-row helper now performs a read first and inserts only before a validated preference write. The missing-row success path was tested reversibly on a synthetic doctor and its exact original row/sequence restored. The post-fix suite confirmed unchanged DB fingerprints/counts/AUTO_INCREMENT and unchanged hospital row. External runner: C:\xampp85\migration-backups\healthbridge\test_hb_api_012_013_settings_http.php. No hosting resources were accessed.


## HB-API-020 prescription mutation runtime verification - 8 November 2026

The actual prescription create/update/complete/cancel routes passed **54/54** authenticated loopback assertions with synthetic fixture users. Input shape/ID/reason bounds, POST method, doctor-only roles, cross-doctor target denials, and the no-existence-leak 403 contract were checked. No successful write was attempted; the original prescription/item rows and full disposable database row hashes/counts/AUTO_INCREMENT values were unchanged. Runner: C:\xampp85\migration-backups\healthbridge\test_hb_api_020_prescription_http.php. Initial test-server lifecycle issues were isolated to the external harness and fixed through short batches; final run exited 0.


## HB-API-021 patient medical-profile runtime verification - 8 November 2026

The actual patient update endpoint passed **36/36** authenticated loopback assertions against synthetic admin/patient/doctor sessions. Gender, date-of-birth and account-status input constraints, strict patient IDs, method/role boundaries, and rejection of non-patient target IDs were verified. The final run preserved the complete database and patient profile/medical record snapshot. No valid write was performed. Runner: C:\xampp85\migration-backups\healthbridge\test_hb_api_021_patient_profile_http.php. See handoff Section 126.


## HB-API-017 reschedule route runtime verification - 8 November 2026

The seven reschedule mutation endpoints passed **63/63** synthetic-role local HTTP assertions for method, authentication/authorization, strict appointment identifiers, and non-text date/time/reason/note validation. No valid reschedule action was invoked. Full disposable DB row hashes/counts/AUTO_INCREMENT values and the synthetic appointment fixture remained unchanged. External runner: C:\xampp85\migration-backups\healthbridge\test_hb_api_017_reschedule_http.php.


## HB-API-018 booking/cancellation lifecycle HTTP verification - 8 November 2026

The actual patient cancellation, admin approve/decline, and doctor update routes passed **62/62** authenticated local HTTP assertions across method/role, malformed identifiers, reason/status types, direct date/time denial, ownership, and rollback-only account-first lock paths. Booking was not rerun because the separate HB-DB-002 suite already passed 28/28. All disposable DB row hashes/counts/AUTO_INCREMENT and exact appointment 1/2 rows stayed unchanged. Runner: C:\xampp85\migration-backups\healthbridge\test_hb_api_018_booking_cancel_http.php. True concurrent lock tests remain open.


## HB-API-019 archive/status endpoint runtime verification - 8 November 2026

The four admin account archive/status routes passed **58/58** local authenticated HTTP assertions for method, role, strict IDs, wrong-role/missing target handling and no-side-effect behavior. Complete disposable DB row hashes/counts/AUTO_INCREMENT and synthetic admin/patient/doctor identity/session snapshots matched baseline. Runner: C:\xampp85\migration-backups\healthbridge\test_hb_api_019_account_archive_http.php. No successful mutation was attempted.


## HB-API-018 genuine lifecycle lock-contention runtime verification - 8 November 2026

A real two-connection local HTTP concurrency suite passed **20/20** across patient cancellation, admin approval, admin decline and doctor update. The HTTP worker's doctor-account FOR UPDATE query was observed while a competing transaction held the doctor row; the contender then acquired patient and appointment locks in order and committed without deadlock or timeout. Routes resumed with 409 on the confirmed fixture; full disposable DB fingerprints/counts/AUTO_INCREMENT remained identical. Runner: C:\xampp85\migration-backups\healthbridge\test_hb_api_018_lock_order_concurrency.php. No app source or data changed.


## HB-API-025 exception status runtime verification - 8 November 2026

The rating and patient-audit routes passed **13/13** actual-route forced-query-failure assertions, returning HTTP 500 with failure JSON and recovering after exact table restoration. Original table DDL/rows/AUTO_INCREMENT and the entire disposable DB snapshot remained unchanged. Contact form was not submitted or fault-injected. External runner: C:\xampp85\migration-backups\healthbridge\test_hb_api_025_exception_status_http.php. Handoff Section 131 records the evidence.


## Phase 2 Origin and session-cookie regression - 8 November 2026

The extended security regression passed **27/27** across actual Origin/Fetch Metadata boundaries and the real auth.php session-cookie bootstrap. It confirmed HTTP/HTTPS Secure behavior, HttpOnly/SameSite/host-only scope, strict session mode, cookie-only transport, URL session ID rejection and secure expiration. All tests were read-only; the disposable database user/appointment counts were unchanged and temp sessions removed. See handoff Section 132 and external runner test_hb_origin_session_cookie_extended.php. The XSS sink inventory and strict CSP review remain open.


## Phase 2 targeted DOM XSS/output-context remediation - 8 November 2026

A targeted source pass remediated data-fed HTML error/timeline fields, status CSS tokens, numeric IDs and names in inline event handlers, URL navigation/download/reset sinks, CSS selector interpolation, and calendar text controls. Shared `escapeHTML`, `safeClassToken`, `safePositiveId`, and `safeSameOriginUrl` helpers now have **35/35** external hostile-input regression assertions. **22/22** JavaScript files passed Node syntax checks. Three PHP print templates were reviewed; remaining raw PHP echoes were numeric/boolean/calculated values, while free-text fields were escaped. The direct `git diff --check` exited 0 and no files were staged.

This is not a full XSS clearance: actual browser-rendered DOM payload testing, remaining lower-traffic HTML sinks, and strict CSP script/style dependency migration remain outstanding. See handoff Section 133.


## Phase 2 lower-traffic XSS sink continuation - 8 November 2026

Extended the targeted audit into booking-wizard summaries, patient medical-history timeline, notifications and admin dashboard analytics. Added strict CSS color and integer-count helpers; escaped timeline date fields; constrained icon tokens and notification IDs; bounded booking duration; normalized admin KPI/completion/availability values. Helper regression passed **50/50**, and five additional JS syntax checks passed. `git diff --check` returned 0; nothing staged. Actual browser DOM payload testing and strict CSP migration remain open. See handoff Section 134.

## Public directory XSS and unsupported trust-copy cleanup - 8 November 2026

The local change set continues the targeted frontend security and truthful-presentation audit:

- `assets/js/pages/doctors.js`: safe numeric experience/rating output, safe duration bounds, actual rating-count-aware display, and mocked-browser-verified card/modal event arguments.
- `api/doctors/get.php`: additive `rating_count` aggregate while preserving the original `rating` response field.
- `doctors.html`, `index.html`, `about.html`, `contact.html`, and `services.html`: remove unsupported verification/certification and hard-coded doctor-directory trust figures; retain only factual directory and service descriptions.
- External runner `C:\xampp85\migration-backups\healthbridge\test_hb_doctors_browser_xss.js`: **21/21** local mocked Chrome/Puppeteer assertions, including hostile output contexts and 390px mobile layout.

The browser runner intercepted APIs and blocked external requests; it did not call the app's PHP APIs or database. PHP lint, Node syntax and `git diff --check` passed. Review all other consumers of the legacy `rating` fallback and verify the aggregate SQL against the disposable database before treating that compatibility issue as closed. Broader browser XSS/CSP and hosted checks remain open. No staging, commit, push or deployment occurred. See handoff Section 135.


## Rating-display compatibility follow-up - 8 November 2026

Additional source changes in this follow-up:

- `assets/js/components/booking-wizard.js`: removes literal 4.5 fallback, uses `rating_count`, shows no star for doctors without review rows, and reads the API's `exp` property for experience.
- `assets/js/pages/admin.js` and `assets/js/pages/admin-doctor-profile.js`: calculate the visible doctor average from actual review rows already returned by `api/doctors/profile.php`; “No ratings yet” is shown when there are no valid review values.
- No additional API contract change for the admin profile endpoint. Its existing `doctor.rating` property remains intact.

Verification expanded the external mocked browser suite to **26/26**. A matching aggregate SELECT ran against the disposable `healthbridge_repair_test` only and returned 8/7/1 active/rating counts. Four changed JavaScript modules passed Node syntax checks, both affected API PHP files passed lint, and `git diff --check` passed. No DB rows changed and nothing was staged, committed or deployed. See handoff Section 136.
## Doctor dashboard/admin review and notification output hardening - 8 November 2026

Additional modified files and evidence:

- assets/js/pages/doctor-dashboard.js: removed the fallback 5.0 average for no patient reviews; normalizes/filter ratings to 1–5; updates summary stars; caps review-card stars. Doctor notification IDs are validated before entering attributes/actions; notification type/collection values are shape-checked and invalid times fall back safely.
- assets/js/pages/admin.js and assets/js/pages/admin-doctor-profile.js: cap review-star rendering and label invalid rating rows “Not rated”. Admin profile averages use valid returned review rows.
- C:\xampp85\migration-backups\healthbridge\run_staff_pages_browser_qa.cjs: expanded to mock hostile data through admin dashboard profile modal and its lazy Reviews tab.

Verification: 50/50 shared helper assertions; 26/26 public directory/booking wizard assertions; hostile tests for patient history/notifications, doctor dashboard ratings/notifications and dedicated admin doctor-profile ratings; staff page suite 4/4 including modal/lazy reviews; patient page suite 7/7 plus fallback. Five changed JS modules passed syntax, both doctor API files and both QA routers passed PHP lint, and git diff --check passed. No DB writes, staging, commit, push or deployment. Next browser targets: doctor workspace/EMR, patient EMR, then admin patient/schedule and remaining lower-traffic sinks. See handoff Section 137.


## Clinical EMR, admin patient and doctor schedule browser hardening evidence - 8 November 2026

The local frontend change set now includes targeted render/input hardening in `assets/js/pages/doctor-workspace.js` and `assets/js/pages/patient-emr.js`: strict workflow status type handling, safe age/aggregate count output, safe prescription ID presentation, numeric star validation, notification type/ID handling, and final-sink escaping for patient timeline and clinical text. Invalid appointment/rating/notification identifiers do not receive action buttons.

The external runner `C:\\xampp85\\migration-backups\\healthbridge\\test_hb_clinical_emr_browser_xss.js` exercised doctor workspace and patient EMR with mocked hostile API payloads. Both pages passed the 390px width checks with no injected image/SVG event nodes, no XSS marker execution, no page errors, and no writes.

The Section 139 runner `C:\\xampp85\\migration-backups\\healthbridge\\test_hb_admin_patients_schedule_xss.js` tested the admin patient table and the doctor Schedule & Availability summary/editor/history against hostile mocked patient, actor and audit text. It validates the positive patient EMR link and the malformed-ID fallback; cancels status/archive confirmation prompts; and blocks state-changing requests. Both views passed with no browser errors, XSS markers or mobile overflow.

Final focused checks: seven changed JavaScript source files passed `node --check`; both new external runners and the shared helper runner parse; four doctor API/QA router PHP files passed lint; `git diff --check` passed; zero staged paths. The wider consolidated browser evidence also retains staff 4/4 and patient 7/7 plus fallback. No database rows changed; no .env secret, hosted resource, commit, push or deployment was involved. Remaining page targets: patient appointments/download/print and residual low-traffic sinks. See handoff Sections 138-139.


## Phase 2 generated event-handler migration closure - 9 November 2026

The final 16 generated HTML event-handler source tokens identified after Section 177 were removed in three controlled passes: six Patient error-state Retry templates (Section 178), six public Doctor Directory controls (Section 179), and four Admin schedule/support/audit controls (Section 180). The changes use fixed data-action identifiers and delegate listeners; record IDs are validated and resolved against current in-memory data or rendered rows. Existing workflow functions, role gates and endpoint contracts remain in place.

Focused browser evidence passed:
- Patient Retry actions: 6/6, actual reload behavior, 390px layout, zero writes/errors/inline attributes.
- Existing seven-page Patient hostile-data regression and Patient Document Center: PASS.
- Public Doctor Directory: card mouse/keyboard actions, profile modal close, booking dispatch, malformed-ID rejection and hostile API rendering: PASS.
- Admin schedule/support/audit generated actions and the broader Admin patient/schedule/support/department/dashboard regressions: PASS.
- Admin audit detail hostile-data and invalid-ID regression: PASS.

The refreshed source inventory across 138 HTML/PHP files and 35 first-party JS files reports zero static inline event attributes, zero inline script/style blocks, zero generated HTML event-handler tokens and zero .onclick property assignments. Remaining inventory: 461 inline style attributes, 266 innerHTML assignments requiring source/context classification, and three programmatic script-loader onload callbacks. No report-only or enforcing CSP policy has been enabled. No hosted resource, .env secret, real patient data, database write, commit, push or deployment was involved.



## Shared layout and admin HTML sink review - 9 November 2026

Section 181 reconciled the JavaScript HTML-sink inventory as 266 direct `.innerHTML =` writes plus eight `.innerHTML +=` department-option appends. The first reviewed group included the shared Layout injector, auth/core helpers, department dropdown paths and all four generic Admin `renderTable()` call sites. API-fed display text was escaped or assigned via DOM `textContent`; class/status/action values were constrained and IDs validated.

The Layout navbar/sidebar fragment trust boundary was tightened to reject unsuccessful, redirected, non-`text/html`, cross-origin and unexpected-path responses before consuming the body. The dedicated browser runner passed actual local fragment loading, hostile navbar-name escaping and all four rejection cases. Seven Patient pages, Doctor Workspace/Patient EMR, Admin page suites and Admin audit regressions passed after the change. All state-changing requests were blocked. The remaining direct HTML sinks, inline styles and CSP dependencies remain open; no CSP policy, production operation or commit was introduced.


## Admin HTML sink review and audit renderer bounds - 9 November 2026

Section 182 reviewed all 47 Admin-page `innerHTML` assignment operations. The generated schedule/profile/appointment/review/audit surfaces use escaped user text, fixed allowlists/classes, safe positive IDs, DOM `Option` construction or constant markup. The audit-detail renderer was additionally bounded (depth 5; 25 array/object entries; 160-character keys; 4,000-character displayed strings) and malformed metadata types/dates now fall back safely.

The expanded Admin audit browser test passed hostile values, malformed field types and output-limit cases with zero XSS markers, page errors and state-changing requests. The full Admin browser regression passed on the disposable test database with blocked writes. Patient layout consumers and the focused fragment trust test also passed after their cache-bust. No CSP policy, production operation or commit/deploy was introduced.


## Patient EMR HTML sink review - 9 November 2026

Section 183 reviewed all 35 `innerHTML` assignment operations in the Patient EMR module. Clinical and identity fields are escaped; IDs, counts, status classes and icon/action tokens are constrained; doctor options use DOM APIs; and appointment filtering copies only already-sanitized rendered rows. Slot responses are validated for object shape, time format, label type and duration range before rendering.

The review exposed a stale `onclick`-attribute dependency in the delegated slot-tab active-state helper. It now reads `data-emr-slot-period`. The existing clinical browser suite was expanded to exercise default active state, actual delegated period switching, matching panels and ARIA/tabindex values. The suite passed for Doctor Workspace and Patient EMR with hostile data remaining inert and writes blocked. Patient EMR HTML script cache query was advanced to `v=4`.


## Admin Doctor Profile DOM sink reduction - 9 November 2026

Section 184 reviewed all 26 original HTML assignment operations in `admin-doctor-profile.js`. Fixed badges/empty states, bounded schedule summary values, allowlisted action/role/icon values and escaped schedule history, profile appointments, reviews and audit text were verified. Eight HTML-string operations were replaced by DOM APIs: Edit/Reassign/Booking department selects, doctor options and booking modal title. Department and doctor collections now reject malformed entries and non-positive IDs before option creation. The remaining 18 direct assignments are fixed or escaped/allowlisted output.

The updated doctor-profile browser regression passed hostile department/doctor labels and malformed entries, delegated modal and schedule actions, schedule/audit escaping and mobile viewport checks. No XSS marker, unexpected image/SVG nodes, page errors, failed local requests or state-changing requests were observed. No CSP policy or production/deployment action was introduced.


## Doctor Workspace clinical DOM sink review - 9 November 2026

Section 185 reviewed all 24 original HTML assignment operations in `doctor-workspace.js`. Patient/clinical/visit/prescription/timeline display fields are escaped or normalized, status classes and icon/title fragments are fixed or constrained, validation markup is fixed, and prescription form controls use static templates. Prescription/timeline collections and nested prescription item rows filter non-object entries; medical-history field labels use own-property lookup.

Fixed a raw `cv.id` attribute interpolation using a positive-ID guard with a fail-closed state, and corrected the escaped no-active-visit base-path expression. Expanded synthetic browser tests verify valid/hostile appointment IDs, same-origin fallback link, malformed collection entries and prototype-like field names. The combined Doctor Workspace + Patient EMR browser regression passed with `BLOCKED_WRITES=0`. The new static guard adds one direct HTML assignment; current count is 25 direct/zero append. No production access or deployment was performed.


## Doctor Dashboard HTML sink review and inventory update - 9 November 2026

Section 186 reviewed all 19 original Dashboard assignment operations covering appointments, queues, timelines, recent activity, notifications, reviews, header and rating summaries. API-fed display text is escaped; classes/icons and star markup are fixed; routes use validated numeric IDs and a same-origin helper; appointment/notification/rating collections filter malformed values. Three HTML-string assignments (welcome heading, hero date, average stars) were replaced with DOM nodes. Doctor identity fields are normalized, and appointment action handlers reject numeric-prefix IDs using `safePositiveId()`.

The expanded Doctor Dashboard action/XSS runner and separate rating runner both pass with blocked writes, no page errors/XSS markers and no mobile overflow. Global current inventory is 264 HTML assignment operations: 164 source-reviewed across 10 modules and 100 remaining in other modules. CSP remains disabled pending further sink/style/dependency review.


## Admin Dashboard analytics sink review - 9 November 2026

Section 187 reviewed all 14 HTML assignments in `admin-dashboard.js`. The analytics API output passes through `normalizeDashboardPayload()`, which filters non-record rows and normalizes strings, safe integer counts, ratings, completion percentages, boolean hospital status and Chart.js series before the renderer runs. Dashboard HTML templates escape API-derived text; classes/icons are fixed or allowlisted; charts receive string labels and numeric data. Three HTML templates' dynamic content remains bounded by the normalized values.

Rating/percentage parsing now rejects structured arrays and malformed numeric strings while preserving valid decimal scalar input. Existing Admin dashboard fixture was extended with array-coercion tests; the full Admin regression passed dashboard analytics, seven charts, actions, hostile data, admin modules and support with zero state-changing requests. Script query is `v=4`. Fixed Chart.js 4.4.1 jsDelivr loading without SRI is noted for external dependency review.

Global inventory: 264 current HTML assignment operations; 178 reviewed across 11 modules, 86 remain unreviewed. CSP is still disabled pending remaining DOM/style/dependency work.


## Booking Wizard sink review and state-validation hardening - 9 November 2026

Section 188 reviewed the 12 current `innerHTML` assignment operations in `assets/js/components/booking-wizard.js`. Doctor/department/slot/summary values are escaped or placed into DOM options/text; remaining assignments are fixed templates or bounded escaped output. Doctor API responses are normalized to valid records, safe positive IDs, bounded scalar fields, numeric counts/rating/duration and explicit available status. Current option membership, department association, available doctor, strict date/time and patient field constraints are checked again at form submission.

Draft restoration now validates all stored values against live option lists, requests the current available slots, and restores the saved time only if the server still offers it. Slot responses are normalized, malformed times/records are rejected, fallback labels are safe, and request IDs suppress late responses following selection changes. Reschedule references are validated and normal booking UI is restored on close. A shared initialization promise prevents duplicate concurrent init/open sequences.

The new focused browser runner passed hostile API/draft fixtures, invalid and valid reschedule flow, stale delayed slots, mobile layout and all validation checks with zero writes/page errors. Patient Appointments/Downloads, Doctor Directory and Doctor Workspace/Patient EMR regression suites passed. Global sink inventory now has 263 operations, 190 reviewed across 12 modules and 73 remaining; CSP remains disabled pending other modules, inline styles and external dependency review.


## Patient Dashboard HTML sink review - 9 November 2026

Section 189 reviewed all 12 original HTML assignment operations in `pages/patient/dashboard.js`. Care-team, activity, alert, prescription, notification, download, insight and appointment-modal sinks were checked for escaping, type checks, fixed/allowlisted classes and icon values, safe counts/IDs, same-origin URLs and API-only download destinations. Two fixed next-appointment heading writes were removed in favor of DOM icon/text nodes. Care-team rating parsing now rejects structured coercion values and accepts only scalar numeric ratings/decimal strings within range.

Expanded the seven-page Patient browser runner for a structured rating array and title DOM-node assertion. Full Patient hostile-data/browser suite passed with `PAGES=7`, `BLOCKED_WRITES=0`, no injected dashboard nodes/handlers, no page errors and no mobile overflow. Patient Dashboard script query was advanced to `v=5`.

Global HTML assignment inventory is now 261 operations: 200 reviewed across 13 modules and 61 remaining in other modules. CSP is still disabled pending the remaining module/style/dependency review.


## Shared Prescriptions module HTML sink review - 9 November 2026

Section 190 reviewed all 11 HTML assignment operations in `assets/js/modules/prescriptions.js`. Static modal shells, fixed medication form templates and empty-list markup were separated from data-driven sinks. Prescription detail/edit/list fields are escaped; issue context is textContent; item arrays are object-filtered; counts/status classes/IDs are normalized; view/edit response IDs are matched to the request; print links use a fixed same-origin API path; action tokens are fixed and dispatched through the existing delegated allowlist. Doctor lifecycle actions are only output for doctor role + Active status + positive ID.

The existing Admin prescription runner's stale inline-handler expectation was updated to the actual delegated action dataset. Expanded hostile detail fixture exercises patient/doctor/department/medication/notes, malformed medication entries, Admin/Doctor detail actions, edit inputs and issue modal text. Test passed with zero marker, images/SVG, handlers, page errors, failed local requests or state-changing requests. No source edit was necessary in the reviewed module.

Global HTML assignment inventory: 261 operations; 211 reviewed across 14 modules, 50 remain. CSP remains disabled pending the remaining modules, inline-style inventory and external dependencies.


## Patient Notifications DOM sink review - 9 November 2026

Section 191 reviewed all six HTML assignment operations in `pages/patient/notifications.js`. Dynamic title/message/time remain escaped; group headings use fixed labels; icon names pass through a fixed map and `safeClassToken()`; read/unread styles depend on a normalized boolean; mark-read uses a positive safe notification ID; Mark All is bound once.

Hardened notification normalization to 20 total displayed items, object-only group entries, safe positive ID, bounded string title/message/time/type and strict true/1/"1" read semantics. Malformed IDs cannot issue mark-read; unread_count uses safeCount. The seven-page Patient browser regression now covers hostile text/icon data, null/array records, >20 list data, text length limits, invalid-ID click no-request and malformed read state. It passed with PAGES=7, BLOCKED_WRITES=0, no page errors/XSS marker and no mobile overflow. Page script query is `v=4`.

Global HTML sink inventory: 261 operations; 217 reviewed across 15 modules and 44 remain in other modules. CSP remains off pending remaining modules, inline styles and external dependencies.


## Shared Medical Records DOM sink and capability review - 9 November 2026

Section 192 reviewed all five HTML assignment operations in `modules/medical-records.js`. API error, patient and clinical values are escaped; templates are fixed except escaped field values; record/visit structures are validated; allergy/medication data is split only from strings; modal doctor/date context uses textContent; form values use DOM value properties; patient/appointment IDs are validated.

The module now requires an actual boolean `can_edit === true` plus doctor/admin role before rendering Edit Record or opening/saving either clinical form. Removed duplicated tab data attributes. The shared clinical browser suite passes string capability and patient role rejection, Admin edit restoration, hostile medical data, invalid IDs, delegated tabs and modal close with blocked writes, zero page errors/XSS markers and mobile layout pass. Script references in Admin and Patient EMR advanced to `v=4`.

Global assignment inventory: 261 operations; 222 reviewed across 16 modules, 39 remain. CSP remains off pending the remaining modules, styles and dependencies.


## Patient Appointments DOM sink and ICS export review - 9 November 2026

Section 193 reviewed all five HTML assignment operations in `pages/patient/appointments.js`. API appointment objects are filtered and mapped to an explicit typed/bounded display record. Dynamic doctor/department/status/date/time/note fields are escaped, IDs are safe positive numeric values, status/category/reschedule state use constrained tokens, durations/counts use safe numeric normalizers, and boolean flags are strict. Invalid-ID rows remain visible but render no actions. Direct detail/rating/cancel/reschedule/workflow handlers independently validate IDs; pagination values are normalized and page navigation is bounded.

Calendar export rejects invalid dates/times (including impossible calendar dates), neutralizes ICS property line breaks and delimiters, and delays blob URL revocation. The expanded patient appointments/download browser runner passed hostile text, malformed object fields, invalid pagination/direct IDs and invalid calendar values; existing valid ICS UID/export and CR/LF injection checks remain passing. Delegated actions and mobile layout pass with BLOCKED_WRITES=0. Script cache query advanced to `v=6`.

Global HTML assignment inventory: 261 operations; 227 reviewed across 17 modules, 34 remain. CSP remains disabled pending remaining modules, inline styles and third-party dependencies.


## Patient Prescriptions sink review - 9 November 2026

Section 194 reviewed all five HTML assignment operations in `pages/patient/prescriptions.js`. The five sinks comprise fixed clear/empty/error templates and a data-driven prescription list. Direct and fallback API data now share `normalizePrescription()`: record-only collections, 20 prescriptions maximum, 20 medication rows per record, strict positive IDs, bounded scalar fields and a fixed status class map. Invalid IDs remain non-actionable; all doctor/department/date/note and medication text is escaped after normalization.

Expanded the seven-page Patient browser regression with hostile and object-valued prescription/medication fields, null/array/primitive entries, numeric-prefix invalid ID and 25 excess synthetic prescriptions. It verifies 20-card cap, safe fallback fields/status, valid versus invalid Print links, XSS suppression and mobile layout. Post-cache-bust run passed with PAGES=7, BLOCKED_WRITES=0 and no page errors. Script query advanced to `v=3`.

Global HTML assignment inventory: 261 operations; 232 reviewed across 18 modules, 29 remain. CSP remains disabled pending the other modules, inline styles and external dependencies.


## Patient Appointments reschedule contract follow-up — 9 November 2026

Follow-up validation found and corrected two compatibility omissions in the Patient Appointments allowlist: the API's positive doctor_id/department_id references had to be retained for BookingWizard.openReschedule(), and the existing Reschedule Requested / reschedule_requested state had to remain in the fixed status/category maps. These values are now validated and preserved. Rating modal display consistently consumes bounded normalized strings.

The updated browser regression verifies the positive ID handoff to the wizard, malformed identity fields becoming zero, preservation of the request state, rejection of object-valued rating stars, escaped valid review content, invalid direct ID behavior, and safe ICS generation in the presence of CR/LF input. Source and runner syntax checks passed. The complete Appointments/Downloads browser run passed with RESULT=PASS, BLOCKED_WRITES=0, no browser page errors/XSS markers and no 390px overflow. Page script version is v=7.

This is a follow-up to Section 193 after the already-recorded Section 194 entry; it does not change the historical Section 193/194 inventory sequence. Current inventory remains 261 assignments, 232 reviewed across 18 modules, 29 open. CSP remains disabled.


## Shared NotificationService DOM sink review — 9 November 2026

Section 195 reviewed all four HTML assignment operations in assets/js/modules/notifications.js. Three are fixed application-owned templates/clear operations. The notification item template contains a fixed own-property icon map result, escaped bounded title/message, and relative-time output from a normalized string timestamp. Records and primitive fields are normalized; notification/reference IDs use safePositiveId; read state accepts only true/1/"1"; invalid IDs receive no mark/delete actions; direct methods reject malformed IDs. Notification routing is selected through fixed known-type branches and a fixed role-aware destination map, while fallback sidebar navigation accepts only a constrained token.

The shared dropdown and Doctor notification Puppeteer runners were executed against isolated synthetic fixtures with state-changing HTTP requests blocked. They cover hostile text, prototype-like/malformed types, invalid IDs, null/array/primitive entries, long fields, invalid timestamps, safe default icons, ID action suppression, no-POST behavior, 50-item cap, and mobile width. Both returned RESULT=PASS with BLOCKED_WRITES=0, no XSS marker/page errors and no mobile overflow. Node syntax checks passed. The module required no product source change or cache-bust.

Global HTML sink inventory: 261 operations; 236 reviewed across 19 modules and 25 remaining. CSP remains disabled pending remaining modules, inline styles and third-party resources.


## NotificationService duplicate initialization and cleanup correction — 9 November 2026

The consumer audit confirmed core main.js and pages/doctor-dashboard.js both initialize the same NotificationService instance on the Doctor Dashboard. Prior initDropdown() registered another bell click listener every time; with two registrations the same click opened and then closed the dropdown. Repeated init() also issued another initial unread-count read. destroy() cleared polling but left the visibility listener and dropdown handlers active.

The module now has idempotent init() that allows a later caller to update the unread callback without duplicating the initial fetch, timer or listener. initDropdown() is guarded and owns bell/outside-click/Escape handlers through an AbortController. destroy() clears polling, removes the visibility listener, aborts dropdown listeners, removes the dropdown and item-level handlers, clears the callback, and resets the service for reinitialization. All explicit shared-module references and main.js dynamic loading now use v=5.

The expanded Doctor notification runner verifies duplicate-consumer initialization, exactly one unread-count GET, correct one-click toggle, destroy cleanup (including no visibilitychange GET) and successful reinit. Shared Patient dropdown and Doctor notification hostile-data suites pass; both have BLOCKED_WRITES=0, no page errors/XSS markers and mobile layout pass. Source/test syntax checks pass.

The four HTML sink review and inventory totals remain unchanged: 261 operations, 236 reviewed across 19 modules and 25 remaining. CSP remains disabled pending the rest of the sinks/styles/dependencies.


## Public Doctor Directory sink/data normalization review — 9 November 2026

Section 196 reviewed all four HTML assignment operations in assets/js/pages/doctors.js. Dynamic department chip labels and doctor names, specialties, badge/availability text, next-slot/working-day content and detail modal fields are escaped. Icon/badge fragments are fixed application constants; action targets are positive-safe numeric IDs and delegated handlers resolve them against the loaded doctor collection. Booking navigation uses local static routes and encoded query values.

The API doctor normalization no longer retains the raw response object via spread. It creates an explicit consumed-field allowlist and validates rating as a finite scalar number or bounded numeric string in the existing 0–5 range. Arrays/objects/booleans can no longer coerce into ratings. When the average is invalid, its review count is cleared so the page does not render a false 0.0 patient average. Experience and counts use safeCount. Cache query advanced to doctors.js?v=4.

The Public Doctor Directory browser runner passed hostile fields, malformed records, rating/experience normalization, availability data, delegated click/keyboard/modal/booking actions, invalid IDs, zero inline handlers and mobile viewport; BLOCKED_WRITES=0, PAGE_ERRORS=0 and XSS_MARKER=0. Related Doctor/Booking Wizard regression passed 26/26. Node syntax checks passed.

Global sink inventory remains 261 assignments; 240 reviewed across 20 modules; 21 remain. CSP remains disabled pending remaining work.


## Patient History timeline review and normalization — 9 November 2026

Section 197 reviewed the four HTML assignment operations in assets/js/pages/patient/history.js. The empty/error templates and populated wrapper/card markup are application-owned. The visible event title, description and metadata fields are escaped; dynamic color is restricted by safeCssColor() and icon classes by safeClassToken().

The old implementation trusted the timeline collection and nested event values, used direct slice/reverse/map operations, and rendered all events. This was tightened to accept only an array; filter out null, array and primitive rows; normalize into an explicit event allowlist; preserve only the newest 200 events since the service sorts oldest-first; validate/cap date to 40, title to 160, description to 1,200, icon/color to 40; and require metadata to be a non-array object. Only doctor, department, status and diagnosis are copied to the display model (160/160/100/500 characters). Invalid values fall back to safe neutral values rather than [object Object]. Script query advanced to history.js?v=3.

The existing seven-page Patient browser runner was expanded with more than 200 synthetic events, hostile content, malformed array/object/primitive rows, malformed nested metadata and overlong visible fields. Source and runner syntax passed. All Patient pages passed (History, Profile, Prescriptions, Notifications, Dashboard, Insights and Settings): PAGES=7, BLOCKED_WRITES=0, RESULT=PASS; no page errors/XSS marker/mobile overflow.

Global HTML assignment inventory remains 261; reviewed coverage is 244 across 21 modules with 17 remaining. CSP remains disabled pending remaining sinks, inline styles and external dependency review.


## Patient Profile display contract and DOM sink review — 9 November 2026

Section 198 reviewed all four HTML assignment operations in assets/js/pages/patient/profile.js: fixed clear, unavailable profile, populated profile and fixed error state. All dynamic values are escaped; icon parameters are fixed local literals and no API value becomes a CSS token or URL.

The old renderer consumed the raw profile/overview/health-snapshot objects. Added a narrow display allowlist and record checks for the API response, profile, overview, snapshot and nested location/emergency-contact/insurance/primary-doctor/next-appointment objects. Strings are type-checked and capped; dates are validated before formatting; summary counts use safeCount; age is a bounded positive integer; height/weight/BMI use positive finite numeric scalars or strict numeric strings under configured maxima. Arrays, objects, booleans and malformed numeric strings no longer coerce into vitals, and BMI categorization only receives normalized numeric data. Unknown API properties are excluded. Script reference advanced to profile.js?v=2.

The expanded seven-page Patient runner verifies hostile escaped text, malformed scalar metrics and nested records, correct valid scalar/numeric-string metrics and BMI category, unavailable state for a non-record top-level profile, blocked writes and responsive width. Node syntax checks passed; PAGES=7, BLOCKED_WRITES=0, RESULT=PASS with no page errors/XSS markers/mobile overflow.

Global HTML assignment inventory remains 261; reviewed coverage is 248 across 22 modules with 13 remaining. CSP remains disabled pending remaining sink/style/external dependency work.

## Patient Downloads DOM and URL authorization boundary — 9 November 2026

Section 199 reviewed all three HTML assignments in assets/js/pages/patient/downloads.js. Document labels, doctor names and dates are escaped; icon tokens are restricted and the populated template is fixed application markup. Record arrays and fields are normalized; invalid dates are hidden and each category renders no more than 50 newest items. The underlying service queries are not truncated, preserving history/count semantics.

The old link guard combined getBasePath() with the URL, resolved it as same-origin, and then required startsWith(/api/). This only works when the app is at the host root; patient pages return ../../ from getBasePath(), so a subfolder installation resolves legitimate links to /optimized/api/... and incorrectly disables them. This is fixed with an API prefix derived from the actual app base. Only the patient-prescription print route with one canonical positive ID, and the appointment print route with one canonical positive ID plus an optional exact summary type, are enabled. All other URL path/query shapes fail closed; external URLs, path traversal, fragments, malformed/duplicate IDs and extra query keys are rejected. Returned links are canonical paths and open with noopener/noreferrer.

The appointment and prescription print endpoints were reviewed without invoking them: each is GET-only, requires authentication and checks that a patient owns the requested row before rendering. No server route changes were needed. Script query advanced to downloads.js?v=3.

The eight-page Patient browser suite now includes Downloads route allowlist, valid link retention, malformed route rejection, hostile text/record cases, 50-row cap and mobile width. Result: PAGES=8, BLOCKED_WRITES=0, RESULT=PASS, no browser errors/XSS markers/mobile overflow. Source and runner syntax pass.

Global HTML assignment inventory remains 261; reviewed coverage is 251 across 23 modules and 10 remain. CSP remains disabled pending the rest of the sink/style/external-origin review.


## Section 200 — First-party JavaScript DOM sink inventory closeout (9 November 2026)

### Reconciled scan

A current source scan of the first-party JavaScript under `assets/js` confirms **261 HTML assignment operations across 27 modules**:
- `innerHTML =`: 256
- `innerHTML +=`: 5 (all five are in `pages/admin.js`, each uses a positive-safe ID and escaped department name)
- Total: 261

The five append assignments explain why a narrow first pass that counted only `innerHTML =` reported 256. The previous 251 reviewed / 10 remaining split was a stale checkpoint. Current source allocation: 254 operations across the 23 previously reviewed modules plus seven operations in Schedule (3), Home (2), Services (1) and Settings (1). The 27-module set is now fully reviewed.

The same `assets/js` scan found zero `outerHTML` assignments, zero `insertAdjacentHTML()`, zero `document.write()`, zero `createContextualFragment()` and zero jQuery `.html()` calls.

### Final Schedule hardening

The Schedule time-option generator previously split API strings and converted their parts with `Number()` without enforcing 24-hour time bounds or a valid close/open ordering. It could emit invalid options or spend excessive work on malformed ranges. It now accepts strict `HH:MM` and MySQL `HH:MM:00`, rejects invalid hour/minute values and reversed intervals, falls back to the existing 08:00–22:00 default and caps the generation loop at 49. Three consuming pages use `schedule.js?v=4`.

### Browser and syntax evidence

The Admin Doctor Profile/Schedule browser suite passed twice, once with valid MySQL-style values and once with malformed `99:99`; both runs reported `RESULT=PASS`, zero page errors, zero failed local requests, zero state-changing requests and no XSS marker. The Home department suite passed hostile/malformed API rows with `BLOCKED_WRITES=0`, `PAGE_ERRORS=0`, `XSS_MARKER=0` and no 390px overflow. The eight-page Patient suite passed with `PAGES=8`, `BLOCKED_WRITES=0`, including Settings static and generated modal open/close and password-toggle behavior. Node syntax checks passed.

**Disposition:** all 261/261 HTML assignment operations are reviewed. This does not close CSP readiness: 461 inline-style attributes, three script-loader `.onload` callbacks and third-party resource origin/SRI inventory remain. Next is the local CSP-readiness/report-only pass; do not activate enforcement until compatibility evidence is reviewed. Overall roadmap estimate remains 46%. No production/hosted resource, `.env`, real DB write, commit, push or deployment was used.


## Section 201 - CSP readiness source inventory and verification (9 November 2026)

### Inventory method and counts

The project CSP scan method was rerun against 138 HTML/PHP files (excluding vendor, node_modules, .git and migration-backups). It confirmed 461 original style attributes and zero style blocks or inline script blocks. The initial quoted single-line inspection undercounted multiline tag attributes; the 461 project scan count is authoritative.

A separate first-party assets/js scan of 35 JavaScript files found 559 style attributes in template strings across 24 files and 313 distinct normalized template values. Combined pre-migration source count: 1,020. Eighteen exact margin-top: var(--s4) declarations were moved into .mt-4 in five pages after backing up those files; current source count: 443 markup attributes plus 559 JS-template attributes (1,002 total).

### Loader and integrity review

The nested settings/notifications script loaders in assets/js/core/main.js now share a same-origin guarded Promise loader with explicit load and error handling. The Chart.js dynamic loader uses one-shot event listeners and a pinned SRI hash. Source scan: zero direct .onload assignments.

Integrity coverage was checked against the pinned source versions:
- Font Awesome 6.5.1: 22 references / 22 SRI attributes.
- Chart.js 4.4.1: two load paths / two SRI attributes.
- Leaflet 1.9.4: CSS and JS references / two SRI attributes.

Origins to consider during full policy design: fonts.googleapis.com, fonts.gstatic.com, cdnjs.cloudflare.com, cdn.jsdelivr.net, unpkg.com, images.unsplash.com and CARTO basemap tile hosts. Attribution links and XML namespace URLs are not CSP fetch sources.

### Report-only test evidence and limitations

A disposable test-only router served static HTML with a CSP-Report-Only header and was independently checked for header presence. The eight-page Patient browser runner then passed with BLOCKED_WRITES=0 and RESULT=PASS while recording 3,057 style-src-attr violations. All events were caused by inline style attributes; top runtime sources were Patient History, Prescriptions, Downloads, Profile, Notifications and Insights. Repeated rendering of synthetic data (including 200 History events) inflates event volume relative to unique source declarations. No other directive violation appeared in this Patient-only run. The QA harness intercepts third-party requests, so a separate online/dependency verification is still needed.

The existing application CSP is not wholly disabled: .htaccess and includes/response.php send the limited enforcing directives object-src 'none', base-uri 'self', frame-ancestors 'none' and form-action 'self'. That policy remains unchanged; no full resource policy or report-only header was applied to app configuration.

### Regression evidence

The eight-page Patient suite, shared notification dropdown, Doctor notifications, Admin audit log, Admin doctor/schedule, Doctor Workspace delegation and clinical EMR browser regressions all passed. Doctor Workspace showed three console errors from intentionally blocked external fonts/CSS only; no page errors, failed local requests or blocked writes. The .mt-4 migration passed affected page regressions. No commit, push, deployment or production access occurred.

Next repository audit step: continue controlled CSS migration for 443 markup and 559 JS-template style attributes, then expand report-only coverage to all page roles and print routes before preparing a full CSP policy. Overall six-phase roadmap estimate remains 46%.


## Section 202 - CSS utility migration and regression evidence (9 November 2026)

### Updated inventory

After 70 safe static conversions, the markup inventory is 391 style attributes across 138 HTML/PHP files. The first-party JavaScript template inventory remains 559 style attributes across 24/35 files. Total remaining source occurrences are 950, excluding the separately inventoried existing runtime property setters. Shared utilities added to assets/css/style.css: .mt-2, .mb-3, .mb-4, .mb-6, .mb-8 and .label-muted-sm; .mt-4 was already present. The 70 migrations comprised 18 mt-4, 41 spacing and 11 filter-label declarations.

### Regression evidence

The spacing batch passed Patient (8 pages), Admin audit, Admin Doctor/Schedule, Doctor Workspace, clinical EMR and Public Home. The filter-label batch passed Admin audit, Doctor Dashboard actions, Doctor Dashboard ratings and Doctor Notifications. All returned RESULT=PASS with zero blocked writes; no page errors or failed local requests. Doctor Workspace logs expected external Font Awesome/Google Fonts errors because those requests are intercepted by the test harness.

The Patient report-only run after the 59 spacing migrations recorded 3,049 style-src-attr events versus 3,057 before. These are runtime violations from inserted synthetic markup (including the 200-item timeline), not unique source attributes. The 11 filter-label changes are on Admin/Doctor pages and are not covered by that Patient run.

### Readiness

The existing partial enforcing CSP baseline remains unchanged; no full policy is enabled. DOM HTML assignment review remains 261/261. First-party JS .onload assignments remain zero; SRI counts: Font Awesome 22/22, Chart.js 2/2, Leaflet 2/2. Continue with JavaScript-generated templates and broader role/print report-only coverage before considering a full CSP.


## Section 203/204 - Patient style extraction and shared-state cleanup (9 November 2026)

### Completed Patient work

Patient History, Prescriptions, Downloads, Profile, Notifications, Insights and Dashboard have been refactored to CSS classes/native progress/hidden state. Their browser regressions were run repeatedly; the last confirmed Patient eight-page run after Dashboard completed with RESULT=PASS and BLOCKED_WRITES=0. Earlier Report-Only measurement after Insights was 116 events, and after Dashboard 74 events.

### Shared cleanup currently pending verification

Files backed up to migration-backups/healthbridge/section204-settings-and-shared-styles include layout.js, core/main.js, modules/notifications.js, settings.js, pages/patient/settings.html, style.css and settings.css. Changes move notification badges/dropdown state, notification deletion animation, shared empty-table and staggered states, Settings tab/modal visibility and password strength from inline styles to classes/hidden state.

The latest shared edits have not yet been confirmed by a clean syntax/browser/report-only run. The last measured Patient Report-Only baseline before these edits was 74 violations grouped into layout.js 20, main.js 20, settings.html 20 and settings.js 14. A fresh result is required before closing this checkpoint.

The existing partial CSP baseline remains unchanged, and the full resource policy remains unenforced. DOM HTML assignment review remains 261/261; overall roadmap estimate remains 46%. No commit, push or deployment.


## Section 205 — CSP Report-Only route coverage and print style remediation (9 November 2026)

### Corrected report-only harness

The first copied staff/public report-only router added the header for PHP requests but returned `false` for static content, which meant PHP's built-in server served static HTML without the header. Those early zero-violation static results are invalid as CSP evidence. The disposable router was corrected to safely resolve and serve static files itself; each copied public/staff page runner now asserts the navigation response contains the expected Report-Only policy. The original non-Report-Only browser runners and application routing were left unchanged.

### Validated routes and findings

- Patient: 8 pages, 0 violations.
- Doctor Dashboard, Doctor Workspace, Admin Dashboard and Admin Doctor Profile: 4 pages, 241 events.
- Public homepage: 29; Doctor Directory: 22.
- About, Services, Contact, Login, Register and Reset Password: 6 pages, 62.
- Prescription, Appointment and Patient EMR print routes: 3 pages, 0 after the print migration.
- Aggregate: 354 runtime violations across 23 distinct pages/routes; every violation is `style-src-attr`. There were no other CSP directive groups. Test harnesses blocked state-changing writes; no same-origin request failures or page errors were observed.

### Print CSS migration

A six-file backup is preserved in `migration-backups/healthbridge/section205-print-inline-style-extraction`. Thirteen inline-style attributes were extracted into three existing print CSS files. All three affected PHP templates pass `php -l`; print pages retain HTTP 200, their print/close hooks and mobile layout; the separate role/ownership suite passed 12 expected Admin/Patient/Doctor authorization cases.

### Current source inventories

The comment-stripped markup scanner reports 306 inline-style attributes across 138 HTML/PHP files, with zero real inline script blocks, zero inline style blocks and zero inline event attributes. A separate quoted-only matcher reports 612 `style="..."` occurrences across 27 HTML/PHP/JS files: 282 markup and 330 JavaScript-generated. This is a different matcher and must not be added to the 306 markup count. An independent JS scan reports 81 direct `.style.* =` assignments and two `.cssText =` assignments, with no `style.setProperty()` or `setAttribute("style")` calls. First-party JS contains zero `.onload =` assignments and zero `onload:` object properties.

The full CSP is not enabled. The existing limited enforced baseline remains unchanged. Next focus: migrate the public homepage/Doctor Directory, then the major Admin, Doctor, Patient Appointments and EMR clusters; inspect CSSOM setters and continue corrected report-only coverage before considering any enforcement.


## Section 206 — Public-page style extraction and updated CSP checkpoint (9 November 2026)

A six-file backup was created at `migration-backups/healthbridge/section206-public-style-extraction`. Migrated 34 inline-style attributes from `index.html`, `doctors.html`, `assets/js/pages/home.js` and `assets/js/pages/doctors.js`. Page-owned external CSS now controls the previous styling; the Doctor Directory stagger uses first-12 `:nth-child()` selectors. Both browser tests and both policy-header-asserting Report-Only tests pass with zero CSP events, no page errors, zero blocked writes and no XSS markers.

Updated Report-Only measurements: Doctor/Admin 4 pages = 241 events; six remaining public/auth pages = 62; Patient 8 pages = 0; homepage and Doctor Directory = 0; three print routes = 0. Total **303 events**, all style-src-attr. No other directive groups.

Current markup inventory is 276 inline-style attributes across 138 HTML/PHP files. Separate quoted HTML/PHP/JS scan is 589 occurrences over 23 files (263 markup +326 generated markup). These are different inventories. JS CSSOM inventory remains 81 direct style-property assignments and two cssText assignments. No style setProperty/setAttribute calls or first-party onload property callbacks were found; inline event attrs and real inline script/style blocks remain zero.

Remaining Report-Only concentration is the four staff pages (241 events) and six other public/auth pages (62). CSP enforcement remains deferred. Continue next with Doctor Dashboard/Workspace and Admin, then Patient Appointments/EMR.


## Section 207 — Doctor Dashboard inline-style migration (9 November 2026)

Created a three-file backup at `migration-backups/healthbridge/section207-doctor-dashboard-style-extraction`. Migrated 31 inline-style attributes from `pages/doctor/doctor-dashboard.html` and 17 from `assets/js/pages/doctor-dashboard.js` into `assets/css/pages/doctor-dashboard.css`. No changes were made to shared Schedule.js.

Post-change syntax and four-page staff regressions pass. Doctor Dashboard has zero Report-Only violations; staff group count decreased from 241 to 191. The Doctor notification test passed with hostile text escaped, malformed IDs inactive, 390px width retained and zero blocked writes.

Updated aggregate Report-Only count: 253 (staff 191 + other public/auth six routes 62; Patient 8, homepage, Directory and three print routes 0). All remaining findings are style-src-attr. Current comment-stripped markup count: 245 / 138 HTML-PHP files; separate quoted cross-source count: 541 / 21; CSSOM setter inventory: 81 `.style.* =` and 2 `.cssText =`; no first-party onload property callbacks. CSP enforcement remains deferred.


## Section 208 — Doctor Workspace style extraction (9 November 2026)

Backed up three files in `migration-backups/healthbridge/section208-doctor-workspace-style-extraction`. Removed 27 HTML and 51 JavaScript-generated inline-style attributes; 44 stable page-local rules now live in `doctor-workspace.css`. No shared Schedule.js changes.

Workspace regression passes fixed-action delegation, tab navigation, prescription/timeline guards, medical-history modal actions and row removal, with zero page errors, failed local requests and blocked writes. The isolated test logs three external resource failures because it deliberately blocks CDN/font requests; these are not local page failures. The corrected four-page staff suite passes without errors; Doctor Dashboard and Doctor Workspace each have zero Report-Only events.

Updated Report-Only total is 213: staff 151, other six public/auth routes 62; Patient, homepage, Directory and print routes 0. All remaining events are style-src-attr. Current markup count 218/138; separate quoted cross-source count 463/19; CSSOM direct style assignments 81 and cssText 2. Full CSP remains not enforced. Next: Admin Dashboard, Admin Doctor Profile, then shared Schedule.js.


## Sections 209–211 — Admin/Profile/Schedule CSP remediation and final 23-route checkpoint (9 November 2026)

### Admin Dashboard

Backed up three files under `migration-backups/healthbridge/section209-admin-dashboard-style-extraction`. Extracted 83 static HTML and 46 JS-generated style attributes from the Admin Dashboard. Presentation is owned by its existing page stylesheet using marker attributes scoped under the Admin page class. Audit-log nested indentation retains the original four bounded depth levels. The Admin audit-log XSS regression, patient/schedule suite, prescription renderer and reviews renderer all pass.

### Admin Doctor Profile

The three-file backup is under `section210-admin-doctor-profile-style-extraction`. Extracted 11 static and 16 generated attributes into the existing profile stylesheet. Loading/error/success visibility is class-based (`.is-hidden`) instead of CSSOM display writes. The full staff run confirms Profile at zero CSP events.

### Shared Schedule module

The two-file backup and QA test copies are under `section211-shared-schedule-style-extraction`. Removed four generated inline styles and six direct JS style-property writes. Expanded day bodies now follow parent `.expanded`; unsaved state toggles `.is-visible`; availability text toggles its available/unavailable classes. Doctor and Admin schedule regressions both pass; test assertions now inspect class state/computed styles, not obsolete inline display values.

### Corrected Report-Only suite results

All23 tested routes have verified response headers. Six remaining public/auth routes report62 events, all style-src-attr (About17, Services7, Contact11, Login6, Register7, Reset Password14). Patient8, Doctor/Admin4, homepage, Doctor Directory and three print routes are all zero. There are no other directive groups, page errors, failed local requests, horizontal overflow or blocked writes.

### Current inventory

Raw HTML/PHP scanner:214 matches across138 files, including HTML comments. Same-method baseline461; net reduction247. Comment-stripped live markup:124 attributes across138 files. Separate quoted HTML/PHP/JS matcher:303 matches across14 files (111 markup,192 generated). JS CSSOM:63 direct style-property assignments +2 cssText, with zero setProperty/setAttribute-style calls and zero first-party onload properties. Inline event attrs and real inline script/style blocks:0.

The full policy remains Report-Only in QA harnesses only. Application CSP has not been expanded/enforced. No reset, commit, push, deploy, production access, `.env` access or real DB writes occurred. Next work: remove the final62 style-src-attr events on the remaining public/auth pages, then review CSSOM setters and broaden route coverage.


## Sections 212–216 — Repository audit delta (9 November 2026)

### Verified CSP-related source state
Corrected scanners distinguish true style attributes from data-csp-style migration markers. The current source inventory is 138 HTML/PHP files with zero real static style attributes, zero inline event attributes and zero inline script/style blocks. The JavaScript-generated HTML inventory is 183 style attributes across four files: admin-departments.js 2, medical-records.js 26, prescriptions.js 48 and patient-emr.js 107. Direct CSSOM style-property assignments and cssText assignments are both zero; style.setProperty and setAttribute("style") calls are also zero. First-party onload property assignments are zero.

The broad all-source scanner covers 197 source files and reports 183 style-attribute tokens; the markup-only scanner reports zero actual static attributes. These are intentionally different scopes. The separate 256 innerHTML assignment count remains a source inventory, not a new security finding or proof of unsafe output.

### Regression and CSP coverage
- 25 unique routes/pages have valid Report-Only response headers and zero recorded style-src-attr violations.
- Mocked auth reset, Patient Appointments/Booking Wizard, Admin dashboard loading/error/recovery, Doctor rating and Doctor Workspace review-validation regressions pass.
- Four-page Doctor/Admin staff browser and Report-Only suite passes with zero page errors, console errors, failed local requests, HTTP errors or blocked writes.
- Patient Appointments and Patient EMR pass at a 390px viewport without horizontal overflow.
- All tests that exercised API interactions used disposable QA routers / mocked responses; the only configured DB for the relevant synthetic-role suite was healthbridge_repair_test.

The expanded Report-Only policy remains limited to isolated QA routers; no application-wide enforcing CSP change was made. Existing uncommitted user work remains preserved.

### Remaining audit scope
The 183 JavaScript-generated style attributes remain to be converted, in four modules. Continue source review and browser coverage for all content states, generated content, exports and less-used routes before considering enforcement. Overall roadmap remains at the previous 46% estimate; this incremental CSP work does not represent completion of the full application engineering roadmap.



## Section 217 — Admin Departments generated-style cleanup (9 November 2026)

Backup: migration-backups/healthbridge/section217-admin-departments-generated-style-extraction.

Removed both remaining generated style attributes from assets/js/modules/admin-departments.js. The empty-departments table cell now uses admin-departments-empty-cell, and the row action wrapper uses admin-department-actions. Their presentation belongs to the already-loaded assets/css/pages/admin-dashboard.css; no new stylesheet link or behavior change was required.

The targeted synthetic Admin browser regression passes both populated department rows and the empty state. It verifies the action wrapper computes to display:flex with a 4px gap, the empty state retains its padding/color presentation, and neither output contains a style attribute. Admin initial/skeleton/content/error/recovery checks also pass. CSP violations, page errors, failed local requests, HTTP errors and blocked writes: zero.

### Updated source inventory after Section 217
- Static markup: 138 HTML/PHP files; zero inline style attributes, inline event attributes, inline script blocks or inline style blocks.
- JavaScript-generated HTML: 181 style attributes remain: patient-emr.js 107, prescriptions.js 48 and medical-records.js 26. Admin Departments is now zero.
- CSSOM assignments: zero direct style-property assignments, zero cssText assignments, zero style.setProperty calls and zero setAttribute("style") calls.
- First-party onload property assignments: zero.
- innerHTML assignments: 256 as a separate inventory, not a fresh sink review.

Next generated-markup target: medical-records.js (26 style attributes), followed by prescriptions.js (48) and patient-emr.js (107). Full application CSP remains unenforced; continue corrected Report-Only and functional coverage.



## Section 218 — Medical Records generated-style extraction (9 November 2026)

Backup: migration-backups/healthbridge/section218-medical-records-generated-style-extraction.

Removed all 26 remaining generated inline-style attributes from assets/js/modules/medical-records.js and moved their presentation into the existing assets/css/modules/medical-records.css module stylesheet. Replaced inline styles with classes for loading/error messages, section headings, conditional full-width and spaced information cards, pre-wrapped medical notes, allergy/medication chip lists and variants, visit counts, edit/visit-note modal sizes, modal headers/actions, visit context blocks and large textareas.

Admin already loaded this shared stylesheet; its cache version was bumped to v3. Patient EMR also uses the MedicalRecords module but had not loaded the module stylesheet, so the same v3 stylesheet is now linked there. No duplicate page stylesheet was created.

The focused Admin browser regression renders Overview, Medical History, Allergies, Medications, Visit History and Emergency Contact using synthetic data. It verifies zero inline styles in rendered content, preserves Family History full-width behavior and note pre-wrapping, and checks both dynamic edit and visit-note modal sizing/actions. The Admin loading/skeleton/content/error/recovery checks also pass. CSP violations, page errors, failed local requests, HTTP errors and blocked writes: zero.

The Patient Appointments and Patient EMR Report-Only suite was rerun after the stylesheet link change. Both routes passed with the expected CSP response header, zero violations, zero page errors, failed local requests, HTTP errors or blocked writes, and no horizontal overflow at 390px. Patient EMR now loads the additional Medical Records module stylesheet. The corrected four-page staff Report-Only suite also passed with zero violations and no browser/network errors.

### Updated inventory after Section 218
- 138 HTML/PHP markup files: 0 real inline style attributes, 0 inline event attributes, 0 inline script blocks and 0 inline style blocks.
- 155 JavaScript-generated style attributes remain: prescriptions.js 48 and patient-emr.js 107.
- CSSOM: 0 direct style-property assignments, 0 cssText assignments, 0 style.setProperty calls and 0 setAttribute("style") calls.
- First-party onload property assignments: 0.
- innerHTML assignments: 256; this remains a separate inventory and is not a new DOM-sink review.

This supersedes the Section 217 total of 181 generated attributes: the 26 Medical Records attributes have now been removed. Next target: prescriptions.js (48 generated attributes), then patient-emr.js (107). Full application CSP enforcement remains deferred; continue the corrected Report-Only and functional regression coverage before considering enforcement.



## Section 219 — Shared Prescription module generated-style extraction (9 November 2026)

Backup: migration-backups/healthbridge/section219-prescriptions-generated-style-extraction.

Removed all 48 generated inline-style attributes from assets/js/modules/prescriptions.js and moved presentation into the already-shared assets/css/modules/prescription.css stylesheet. The class mapping covers issue/view/edit/cancel modal sizing, modal headers, appointment context, add-medication controls, textarea sizes, form action rows, dynamic medication-row headers/titles/remove buttons, medication field grids/spacing, prescription issue metadata, medication counts, signature reference, cancellation description, empty table cells and list item counts.

A specificity check found the shared .form-group textarea rule overrode the first class-based textarea height. The module stylesheet now uses .form-group textarea.rx-textarea--short and --medium selectors to preserve the original 80px and 100px heights.

### Verification
- node --check assets/js/modules/prescriptions.js: pass.
- Existing Admin prescription-list hostile-content regression: pass. Malformed IDs remain non-actionable; hostile labels/details remain escaped; no XSS marker or executable image/SVG nodes; state-changing requests 0; page errors 0.
- New synthetic Admin module browser test: pass for empty/list-count states, issue modal, dynamic add/remove medication rows, prescription detail modal, edit modal and cancel modal. All generated Prescription DOM checked with zero style attributes; CSS widths, spacing, textareas and flex action rows verified.
- CSP Report-Only header assertion: pass; violations 0; page errors 0; failed local requests 0; HTTP errors 0; blocked writes 0.
- Corrected 4-page Doctor/Admin staff suite: pass with zero violations and no browser/network errors.
- Corrected Patient Appointments and Patient EMR suite: both pass with zero violations, no errors/failed requests/blocked writes and no horizontal overflow at 390px.

### Updated inventory after Section 219
- 138 HTML/PHP markup files: zero real inline style attributes, inline event attributes, inline script blocks or inline style blocks.
- 107 JavaScript-generated style attributes remain, all in assets/js/pages/patient-emr.js.
- CSSOM: zero direct style-property assignments, zero cssText assignments, zero style.setProperty calls and zero setAttribute("style") calls.
- First-party onload property assignments: zero.
- innerHTML assignments: 256; a separate source inventory, not a fresh DOM-sink review.

This supersedes the Section 218 total of 155: all 48 Prescription module attributes have been removed. Next target: the remaining 107 generated styles in patient-emr.js. Continue with a backup, bounded class mapping and Patient EMR synthetic-data regression; this module renders extensive patient history, appointments, audit and timeline content, so preserve conditional layouts and hostile-value escaping. Full application CSP enforcement remains deferred.



## Section 220 — Patient EMR generated-style extraction (9 November 2026)

Backup: migration-backups/healthbridge/section220-patient-emr-generated-style-extraction.

Removed all 107 remaining generated style attributes from assets/js/pages/patient-emr.js and moved their presentation into the existing assets/css/pages/patient-emr.css stylesheet. The extraction covers the loading skeleton, compact status badge, stat icon tones, overview empty states and doctor chips, section headings/spacing, email wrapping, appointment action tones and table empty state, last clinical update, administrative versus clinical record cards, readonly presentation, prescription/visit-note/timeline sections, rating moderation controls, notification actions, booking-slot placeholders, all three timeline event markers, audit entries and role badges, audit history empty state and audit-table scrolling.

Timeline markers now use a closed set of explicit classes for appointment, visit-note and prescription events. The existing .emr-timeline::before pseudo-element owns the vertical rail, so the redundant inline-styled rail child was removed instead of duplicated.

### Verification
- node --check assets/js/pages/patient-emr.js: pass.
- Existing clinical EMR hostile-content regression: pass. Doctor Workspace and Patient EMR hostile payloads remain escaped; malformed IDs are rejected or have actions suppressed; timeline, ratings, appointments, notifications and audit handling pass; state-changing requests 0.
- Enhanced Report-Only browser regression: pass. The actual Content-Security-Policy-Report-Only response header is asserted on Doctor Workspace and Patient EMR; the Patient EMR test renders all nine tabs and the booking-slot flow using synthetic data. Generated content across all tab roots and booking slots has zero style attributes. All three timeline marker type classes and computed presentation are present. CSP violations 0, page errors 0, blocked writes 0.
- Corrected Patient Appointments and Patient EMR route suite: pass at 390px with zero Report-Only violations, page errors, local failures, HTTP errors or blocked writes.
- Corrected four-page Doctor/Admin suite: pass with zero Report-Only violations, page/console errors, failed local requests, HTTP errors or blocked writes.
- Reconciled markup-only scanner: 138 files, zero inline style attributes, zero inline script blocks and zero inline style blocks.
- Reconciled broad source scanner: 197 source files, zero inline style attributes, zero inline script/style blocks. The separate JS CSSOM inventory reports zero direct style-property assignments, zero cssText assignments, zero style.setProperty calls and zero setAttribute("style") calls.

### Final reconciled CSP source inventory after Section 220
- 138 HTML/PHP files: zero real static inline style attributes, zero inline event attributes, zero inline script blocks and zero inline style blocks.
- 35 JavaScript files scanned: zero JavaScript-generated HTML style attributes.
- CSSOM: zero direct style-property assignments, zero cssText assignments, zero setProperty calls and zero setAttribute("style") calls.
- First-party onload property assignments: zero.
- 256 innerHTML assignments remain as a separate source inventory; this does not reopen or replace the previously recorded 261/261 DOM-sink review.

The source-level inline-style cleanup is now at zero. This does not by itself authorize production CSP enforcement: route/content-state coverage is still bounded, external script/style origins and maps must be reviewed against real page dependencies, and the enforcing policy needs isolated QA validation before any deployment consideration. Expanded policy remains confined to disposable QA routers. No enforcing header was added to the application or production.

The corrected Report-Only route coverage remains 25 unique routes/pages with zero recorded violations: Patient 10, Doctor/Admin 4, homepage/Doctor Directory 2, other public/auth 6, and print 3. The overall engineering roadmap remains at its last documented estimate of 46%; this CSP work does not recalculate unrelated maintenance tasks.

Next: audit the current QA CSP directives and origin allowlist, compare them with the full route/dependency inventory, then create a disposable enforcing-QA router for controlled regression only. Keep the live application and production policy unchanged.



## Section 221 — Disposable enforcing-CSP validation and print-header correction (9 November 2026)

All work for this section is isolated under migration-backups/healthbridge/section221-csp-enforcement-qa. No application source header, live deployment, production environment or production database was changed.

### Test policy

The disposable routers use this enforcing policy:

default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self'; script-src 'self' https://cdn.jsdelivr.net https://unpkg.com; script-src-attr 'none'; style-src 'self' https://fonts.googleapis.com https://cdnjs.cloudflare.com https://unpkg.com; style-src-attr 'none'; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com https://unpkg.com data:; img-src 'self' data: blob: https://images.unsplash.com https://*.basemaps.cartocdn.com https://unpkg.com; connect-src 'self'

Every final enforcement runner now asserts the actual Content-Security-Policy response header contains style-src-attr 'none', and captures browser securitypolicyviolation events. All API-backed QA interactions use healthbridge_repair_test and all state-changing requests are blocked or stubbed.

### Enforcing route coverage

All 25 previously targeted unique route/page surfaces have now passed under the disposable enforcing policy with the response header asserted:

- Doctor/Admin: 4 routes — Doctor Dashboard, Doctor Workspace, Admin Dashboard and Admin Doctor Profile.
- Public/auth: 6 routes — About, Services, Contact, Login, Register and Password Reset.
- Patient: 10 routes — the 8-page Patient suite plus Appointments and Patient EMR.
- Public discovery: 2 routes — homepage/departments and Doctor Directory.
- Print: 3 routes — Prescription, Appointment and Patient EMR print.

The full synthetic Patient EMR test also passes under enforcement across all nine tabs, appointment-slot content, timeline marker variants and hostile/malformed values. The eight-page Patient suite passes its hostile-content, data normalization, downloads, modal delegation and password-toggle checks. Doctor/Admin suite passes all four routes. Public/auth and public discovery suites pass mobile, hostile-content and interaction assertions. Print tests pass external script/stylesheet expectations, auto-print hooks and mobile layout. The print authorization matrix passes 12 ownership/role cases with the expected 200/403 statuses and no state-changing requests.

Final enforcing test outcomes: zero recorded CSP policy events in every suite; zero page/console errors; zero failed same-origin requests; zero HTTP errors; zero blocked writes. Print role tests report zero state-changing requests. The two Patient route tests remain free of horizontal overflow at 390px.

### Important correction — print route response headers

The earlier Section 218–220 notes described 25 routes as Report-Only tested. That count was overstated. The 3 PHP print endpoints include includes/auth.php, which calls sendCorsHeaders() in includes/response.php. That helper sets its own narrower application CSP:

object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'

The helper therefore replaced the disposable router's Report-Only policy on those print responses. Their prior zero-event results did not prove the full Report-Only policy was applied. Treat the prior full-policy Report-Only coverage as 22 routes, not 25; the 3 print routes had the application's narrower CSP.

For Section 221 only, both disposable enforcing routers now buffer the three print endpoint responses and reapply the full QA enforcing policy in a shutdown handler, including early 403 authorization responses. The print header assertion now passes for Prescription, Appointment and EMR print success/denial cases. This workaround exists only in the copied QA routers. No change was made to includes/response.php, includes/auth.php or the print endpoint files.

This reveals a future application-level policy integration issue: the shared sendCorsHeaders() helper and standalone printable HTML responses need a single intentional CSP design before an enforcing header is considered for the application. Do not copy the QA shutdown workaround into production as-is.

### Current verified source inventory

The final reconciled source scan remains at zero:
- 138 HTML/PHP markup files: zero inline style attributes, inline event attributes, inline script blocks or inline style blocks.
- 35 JavaScript files: zero generated HTML style attributes.
- Zero direct CSSOM style assignments, cssText assignments, style.setProperty calls or setAttribute("style") calls.
- Zero first-party onload property assignments.
- 256 innerHTML assignments remain a separate inventory; the prior 261/261 DOM-sink review is unchanged.

### Limits and next work

The enforcing suite proves that the copied QA routers emit the tested policy and the tested routes render without recorded policy events. Some browser runners intentionally block external requests to keep synthetic QA deterministic; this means the allowlist still needs dedicated dependency-loading validation (Google Fonts, cdnjs, jsDelivr, unpkg, Unsplash and Carto map tiles) rather than assuming a zero-event run proves every third-party asset loaded.

Next work: audit actual external dependency loading for each relevant public/auth, contact/map and staff route under an allowlisted browser harness; reconcile the application shared CSP helper with print responses; then design a single application-level CSP and test it in an isolated copy before considering any live header change. The full enforcing policy remains limited to disposable QA routers. No commit, push or deployment was made. Overall engineering roadmap remains at the last documented 46% estimate; this CSP work does not recalculate unrelated maintenance tasks.



## Section 221B — External dependency loading under enforcing CSP (9 November 2026)

Additional enforcing-policy browser runs used allowlisted test copies under migration-backups/healthbridge/section221-csp-enforcement-qa. These copies permit only the QA CSP's declared external origins plus data/blob resources explicitly allowed for image rendering; they record actual HTTP responses, blocked origins and request failures.

### Seven external-dependency suites — all pass

- Doctor/Admin staff suite: Google Fonts and Font Awesome load; the Admin Dashboard also loads Chart.js 4.4.1 from jsDelivr.
- Patient Appointments/Patient EMR route suite: Google Fonts, Font Awesome, font files and data-URI SVG resources load.
- Full synthetic Patient EMR suite: all nine tabs and booking slots pass with external fonts/icons loaded; no style attrs, policy events, hostile-content execution or blocked writes.
- Patient eight-page suite: History, Downloads, Prescriptions, Notifications, Dashboard, Insights and Settings all pass; fonts/icons load. Insights' isolated data-validation test deliberately aborts the Chart.js request to preserve its chart-construction spy; the real Chart.js script is independently verified with HTTP 200 by the Admin suite.
- Public/auth suite: Google Fonts, Font Awesome, unpkg and all three Carto basemap hosts are requested successfully across About, Services, Contact, Login, Register and Password Reset.
- Public homepage/departments suite: Google Fonts, Font Awesome and Unsplash hero imagery load successfully.
- Doctor Directory suite: Google Fonts, Font Awesome and Unsplash imagery load successfully.

### External response summary

Across the seven suites, 147 approved external/data/blob responses returned HTTP 200:

| Host / resource | HTTP 200 responses |
|---|---:|
| cdnjs.cloudflare.com | 62 |
| fonts.googleapis.com | 30 |
| fonts.gstatic.com | 30 |
| data/blob image resources | 16 |
| unpkg.com | 2 |
| images.unsplash.com | 2 |
| a.basemaps.cartocdn.com | 1 |
| b.basemaps.cartocdn.com | 2 |
| c.basemaps.cartocdn.com | 1 |
| cdn.jsdelivr.net (Chart.js 4.4.1) | 1 |
| **Total** | **147** |

Across these external-validation runs: blocked/unapproved external origins 0, external request failures 0, and enforcing CSP policy events 0. The intentionally stubbed Patient Insights Chart.js request is a test-harness behavior, not a network failure; the real resource was verified separately on Admin.

This validates the current allowlist's known dependency families on the routes above. It does not prove every future or rarely used application route has no other external dependency. Keep the allowlist narrowly scoped and re-run dependency tests when pages/assets change.


## Section 222 — Repository and configuration audit update (9 November 2026)

The root `.htaccess` is now an active security-configuration dependency, not only a repository-protection file: its `mod_headers` fallback must remain identical to `HEALTHBRIDGE_CONTENT_SECURITY_POLICY` in `includes/response.php`. The fallback continues to apply only when a response has no CSP header. `tools/qa/verify-csp-policy-sync.php` was added as a read-only guard against configuration drift; it parses the Apache rule and verifies the exact value and seven required directives without opening a database connection.

The active local source passed PHP lint and a separate-Apache response-header sweep across 24 HTML files and 89 API endpoints. Browser acceptance covered four representative static pages and 12/12 print authorization cases with zero CSP events/errors/failed requests/non-GET requests. Exact pre-edit versions of the two CSP files are preserved in the Section 222 QA backup. Existing unrelated uncommitted repository changes were not reset or staged. This is not a commit or deployment; the hosted site remains unchanged.


## Section 223 — Authentication regression artifact update (9 November 2026)

Added a reusable, isolated Chrome/Puppeteer regression runner and execution log under `migration-backups/healthbridge/section222-csp-application-policy-qa/` for the reset-password strength meter. It verifies empty and levels 1–5, waits for the 300ms CSS transition before measuring computed styles, checks client-side weak-password rejection, and stubs both reset-password requests before the real endpoint. No application code or database mutation was needed. Result: 6/6 strength states and the mock submit boundaries pass; CSP violations, browser errors, failed local requests and inline style attributes are zero.


## Section 224 — Dynamic URL sink inventory update (9 November 2026)

The first-party static inventory identified URL assignment/use patterns in core auth/navigation, notifications, Patient Document Center, print actions, Services, Doctor Directory, dynamic stylesheet/script loading and Blob exports. No literal `javascript:` URL or dynamic template `src`, `action`, `formaction` or `srcset` was found. Source review confirmed same-origin redirects, exact print-route download allowlists, positive print IDs, static navigation maps, encoded query values, a versioned/SRI-pinned CDN script and same-origin checking for dynamic local scripts. A browser runner tested `safeSameOriginUrl` with 4 valid and 9 unsafe inputs plus fallback, under enforcing CSP with zero CSP events/errors/failed local requests. Runner/log remain in the Section 222 QA backup. Existing innerHTML sink review was not repeated.


## Section 225 — Document Center URL regression artifacts (9 November 2026)

Added a synthetic Chrome runner and log under `migration-backups/healthbridge/section222-csp-application-policy-qa/` for the Patient Document Center URL allowlist. The regression confirms four approved link shapes and blocks 13 hostile/invalid targets, verifies title/doctor escaping and `noopener noreferrer`, and checks 390px layout. Both API requests are intercepted; no real endpoint/database is reached. Zero CSP events, JS errors, failed local requests, injected nodes or inline style attributes. No application source change was necessary.


**Section 224 addendum:** Second-pass repository search found no document-write APIs, `<base target>`, or dynamic `srcdoc`, `srcset`, form-action, poster or manifest property assignments. The only `setAttribute("href")` is a role-mapped local Services destination. The only external CSS import points to the CSP-approved Google Fonts host.


## Section 226 - Roadmap progress recalibration (9 November 2026)

The qualitative six-phase estimate has been recalibrated after Sections 220-225. Phase 2 (Security audit) moves from 55% to 58% to recognize the reconciled inline-style/CSSOM cleanup, consistent enforcing CSP integration and 24-page/89-endpoint header validation, dynamic URL trust-boundary review, and hostile Document Center regression. Phase 6 (Regression/release readiness) moves from 36% to 38% for the added enforcing-CSP browser checks, print authorization matrix, password-strength states and Patient Document Center browser suite. Other phase estimates remain unchanged because these focused results do not complete their separate exit criteria.

| Phase | Updated estimate |
|---|---:|
| 1. Understand and map | 60% |
| 2. Security audit | 58% |
| 3. Database/workflow reliability | 60% |
| 4. Architecture standardization | 40% |
| 5. Cleanup/refactoring | 25% |
| 6. Regression/release readiness | 38% |

Equal-weight mean: (60 + 58 + 60 + 40 + 25 + 38) / 6 = 46.83%, rounded to **47% overall**. This is a qualitative planning estimate, not a test-coverage ratio, completion guarantee, or release-readiness claim. HB-SEC-010 and all six phase exit gates remain open. Continue prioritizing the remaining low-traffic dynamic sinks, clinical authorization journeys, data/restore reliability, architecture ownership, refactoring and full release verification.

