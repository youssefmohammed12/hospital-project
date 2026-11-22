# HealthBridge ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â Shared Testing Toolkit

**Default test environment:** `D:\dev-toolkit`  
**HealthBridge project:** `C:\xampp85\htdocs\optimized`  
**Local browser URL verified on 11 November 2026:** `http://127.0.0.1/optimized/`  
**Policy:** use the shared toolkit as the primary runner for new reusable unit, browser, accessibility and performance checks. Do not install duplicate test dependencies into HealthBridge.

## Toolkit inventory (verified 11 November 2026)

| Tool | Installed version / role |
|---|---|
| Node.js / npm | 26.8.2 / 11.19.1 |
| Playwright Test | 1.65.0; Chromium 1254 installed and launched successfully |
| axe-core / Playwright | 4.14.0; WCAG 2.0/2.1 A/AA automated rules |
| Vitest / jsdom | 5.1.0 / 31.0.0 |
| Testing Library | React 16.3.3; DOM helpers available |
| Lighthouse | 13.6.0 |
| PHPUnit | 13.0.0 via the toolkit wrapper |
| PHPStan | 2.1.0 via the toolkit wrapper |
| PHP CLI | XAMPP PHP 8.5.12; `zip`, `pdo_mysql`, `mysqli`, `mbstring` enabled |

Firefox/WebKit are **not verified for the toolkit's Playwright 1.65 install**. Use Chromium only unless the matching browser builds are installed and confirmed. Old Firefox/WebKit folders belonging to other Playwright versions do not count as a supported matrix.

The toolkit is external to the HealthBridge repository. Its npm dependencies are installed under `D:\dev-toolkit\node_modules`; they do not modify `composer.json`, `composer.lock`, or any application package manifest.

## What is available now

- Playwright config: `D:\dev-toolkit\playwright.config.ts`; base URL comes from `BASE_URL` (default is `http://127.0.0.1:8080`). Browser traces, screenshots and HTML reports go under the ignored toolkit `reports/` folder.
- Generic Playwright starter specs: `tests\e2e\smoke.spec.ts` and `tests\e2e\accessibility.spec.ts`. These test a generic home page; they are **not yet HealthBridge role/workflow tests**.
- Vitest config: `vitest.config.ts`; place future HealthBridge unit specs under `tests\unit\healthbridge`.
- Place future HealthBridge Playwright specs under `tests\e2e\healthbridge`. In those specs, navigate explicitly to `/optimized/` (the app is a sub-path of the local Apache document root).
- First HealthBridge-specific browser suite: tests/e2e/healthbridge/landing-page.spec.ts (5 tests). It covers the public landing page smoke, main landmark and heading/social accessible names, hours-region keyboard focus in both themes, desktop dark-mode axe, and mobile light/dark axe WCAG 2.0/2.1 A/AA checks. It does not cover patient/doctor/admin workflows yet.
- Expanded HealthBridge public/auth browser suite: tests/e2e/healthbridge/public-auth-pages.spec.ts (10 tests). It covers about/contact/doctors/services page shells, contact form schema, safe local doctor search, service filters/FAQ state, login/recovery panel navigation, registration wizard shell, and the no-token reset screen. All application API requests are intercepted and aborted; this is not real auth or account-persistence acceptance.
- Mobile axe runner: `scripts\mobile-accessibility.mjs`; it uses the exact URL from `BASE_URL` and emulates 390ÃƒÆ’Ã¢â‚¬â€844 with touch.
- Lighthouse CLI: run against the explicit local HealthBridge URL; save HTML/JSON reports under `reports\healthbridge`.
- PHPUnit/PHPStan wrappers: `bin\phpunit.cmd` and `bin\phpstan.cmd`. Configure PHPStan per project; do not apply the example blindly to this legacy PHP codebase.

## Default HealthBridge runbook

1. Generate/read the HealthBridge session snapshot and confirm the current branch, worktree, local services and selected synthetic database. Start the local XAMPP/Apache site only when needed; verify `http://127.0.0.1/optimized/` before browser tests.
2. Use a disposable synthetic database for any test that writes. Never point a test at `healthbridge`, hosted databases, or the retained `healthbridge_restore_rehearsal_20261111` baseline. Capture a full pre-test dump/fingerprint before mutations and restore/compare before cleanup.
3. Run only the test layer needed for the change. Prefer the shared toolkit for repeatable unit, browser, axe and Lighthouse checks; use the established PHP/PowerShell QA runners for transaction, migration, concurrency and restore assertions.
4. Record the exact URL, browser, PHP/DB versions, test command, output, mocked versus real HTTP behavior, database target, writes, cleanup and uncovered paths in the dated QA evidence folder.

### Commands

Run these from PowerShell. For project-specific browser specs, set the origin as `BASE_URL` and have the HealthBridge spec navigate to `/optimized/` explicitly.

```powershell
Set-Location 'D:\dev-toolkit'
$env:BASE_URL = 'http://127.0.0.1'

# HealthBridge-specific unit tests (add specs under tests/unit/healthbridge)
npm.cmd run test:unit -- tests/unit/healthbridge

# HealthBridge-specific Chromium E2E (add specs under tests/e2e/healthbridge)
npm.cmd run test:e2e -- tests/e2e/healthbridge

# Mobile accessibility: this script takes the complete URL, including /optimized/
$env:BASE_URL = 'http://127.0.0.1/optimized/'
npm.cmd run audit:accessibility:mobile

# Desktop Lighthouse audit; reports remain outside the application repository
npx.cmd lighthouse 'http://127.0.0.1/optimized/' --only-categories=performance,accessibility,best-practices,seo --output=html --output-path='reports/healthbridge/lighthouse.html' --chrome-flags='--headless'

# View the Playwright HTML report after E2E tests
npm.cmd run test:report
```

The shared starter E2E specs currently use `page.goto('/')`. They target the origin root, not the HealthBridge `/optimized/` sub-path. Do not report their result as a HealthBridge test. Add and run explicit project specs in the HealthBridge test folders above.

### PHP test and static-analysis use

Use the toolkit wrappers so the Composer-global versions are selected rather than the older PHPUnit executable earlier in XAMPP PATH:

```powershell
& 'D:\dev-toolkit\bin\phpunit.cmd' --version
& 'D:\dev-toolkit\bin\phpstan.cmd' --version
```

PHPUnit 13 and PHPStan 2.1 are installed, but **no HealthBridge-specific PHPUnit suite or PHPStan configuration has been accepted yet**. Before creating one, inspect the current PHP bootstrap/autoload path and PHP version compatibility. Adapt `D:\dev-toolkit\php\phpunit.xml.example` and `phpstan.neon.example` deliberately; do not copy the example bootstrap or analyze the entire legacy tree at an arbitrary level. PHPStan is static analysis, not runtime proof.

## Boundary between toolkit and specialist QA

The shared toolkit is the default for new repeatable JavaScript/TypeScript unit tests, browser workflows, axe accessibility checks, mobile checks and Lighthouse audits. It does not provision or reset databases, prove transaction locking, execute migration chains safely, or replace an exact backup/restore comparison.

Continue using the existing reviewed, dated PHP/PowerShell QA runners under `C:\xampp85\migration-backups\healthbridge` for:
- real local HTTP behavior with synthetic sessions;
- database mutation, transaction ordering and concurrency;
- migration preflight/upgrade/restore and table fingerprints;
- notification outbox, prescription and clinical lifecycle persistence.

Read each specialist runner before reusing it. Confirm the exact database target, fixture mutation, cleanup path and source compatibility. Keep bespoke HTTP/DB assertions when Playwright alone cannot prove persisted state.

## Report and test-source retention

Toolkit `reports/` is ignored by its project and is a working-output area. For evidence that must survive report rotation, copy the relevant HTML/JSON/log, exact test spec, and a short `RESULT.md` into a dated folder under `C:\xampp85\migration-backups\healthbridge`; index it in `docs/HEALTHBRIDGE_QA_EVIDENCE_INDEX.md`. The test source lives outside the HealthBridge Git repository, so preserve the exact spec used for any accepted finding.

Do not commit toolkit output into HealthBridge, do not add npm/Composer dependencies to the application for tests that the shared toolkit can run, and do not treat a toolkit self-check as application acceptance.

## Initial toolkit verification and current accessibility baseline (11 November 2026)

- Vitest self-check: **1/1** passed.
- PHPUnit self-check: **1 test / 1 assertion** passed.
- Playwright 1.65 Chromium launch: passed; browser opened and closed a page successfully.
- Lighthouse 13.6, PHPUnit 13.0 and PHPStan 2.1 CLIs were available; PHP extension checks passed.
- HealthBridge local landing page returned HTTP 200.
- The real HealthBridge landing page mobile axe scan (390ÃƒÆ’Ã¢â‚¬â€844) ran and found three rule categories: color contrast (two nodes at 3.68:1 for #0891b2 against white; expected 4.5:1), accessible-name/visible-label mismatch on Twitter and LinkedIn links, and a non-keyboard-focusable scrollable `.hours-table-wrapper`. This is a recorded baseline, not a fix. See the dated QA result and `HB-ACC-001`.


## Latest HealthBridge landing-page verification (11 November 2026)

The first project-specific suite at tests/e2e/healthbridge/landing-page.spec.ts passes 5/5. The standalone mobile axe runner reports zero violations at 390x844. Lighthouse 13.6.0 scores Accessibility 100 (previous baseline 96), Best Practices 100 and SEO 100; Performance is 77 and remains a separate LCP optimization item (about 5.8 seconds). HB-ACC-001 and HB-ACC-002 are closed for the tested landing page. Full run, source backups, exact test and raw outputs are retained in C:\xampp85\migration-backups\healthbridge\hb-accessibility-fix-20261111\RESULT.md.
## Latest public and authentication route coverage (11 November 2026)

The combined tests/e2e/healthbridge Playwright run passes **15/15** (5 landing-page tests and 10 public/auth tests). The additional suite covers four public pages and the login, registration and no-token reset-password screens. It blocks all HealthBridge /api/ requests and intentionally does not submit forms or touch database-backed behavior. The Services FAQ expanded-state defect discovered by the suite was fixed and verified; HB-ACC-003 is closed. Full evidence and source backups: C:\xampp85\migration-backups\healthbridge\hb-public-auth-coverage-20261111\RESULT.md. Patient/doctor/admin journeys remain blocked on selecting a disposable synthetic fixture and test-account cleanup/restore process.
## Dedicated synthetic browser-QA fixture and authenticated role smoke (11 November 2026)

A separate, disposable clone and isolated PHP server are now available for explicit authenticated browser tests:
- Database: healthbridge_browser_qa_20261111 (source baseline: healthbridge_restore_rehearsal_20261111; never mutate the source).
- Dedicated server: http://127.0.0.1:18511/optimized/; mirror at C:\xampp85\qa-webroot\optimized with .env and .git excluded. Its PHP process receives DB_NAME for the clone only.
- QA admin/patient/doctor accounts: IDs 73/74/75. Generated passwords are in C:\xampp85\migration-backups\healthbridge\hb-browser-qa-fixture-20261111\qa-credentials.json, restricted to the current Windows account. Never print, commit or copy that file.
- Role suite: tests/e2e/healthbridge/qa-role-login.spec.ts. It passes 3/3 for admin, patient and doctor and also passed 3/3 after a complete clone restore, reseed and server restart.
- Safety: role tests skip by default. Enable only while the dedicated clone-bound server is running and supply both HB_QA_ENABLE_ROLE_TESTS=1 and HB_QA_CREDENTIALS_FILE. The request guard permits the login POST, GET/HEAD/OPTIONS, and one source-reviewed read-only patient-dashboard POST; all other non-read API calls are aborted.
- The restore procedure was exercised after real authenticated sessions: the PHP server stopped, 13 isolated session/rate-limit temp files were removed, no target DB connections remained, immutable dump was reimported, exact 26-table/checksum/schema/AUTO_INCREMENT comparisons passed, mysqlcheck was 26/26, and the same accounts were reseeded before a final 3/3 role login pass.

See C:\xampp85\migration-backups\healthbridge\hb-browser-qa-fixture-20261111\README.md for start, explicit test, stop, restore, reseed and guarded teardown commands. Full evidence: hb-browser-qa-fixture-20261111/RESULT.md. The fixture remains active for the next QA step; the final database drop has not been performed. Do not run these tests against normal Apache or protected databases.
### Role fixture and workstation concurrency note

The dedicated authenticated role-login suite is skipped by default and requires both HB_QA_ENABLE_ROLE_TESTS=1 and HB_QA_CREDENTIALS_FILE. It is only for the dedicated clone-bound server at 127.0.0.1:18511; use the separate README under hb-browser-qa-fixture-20261111. The full HealthBridge folder should run serially on this workstation because the six-worker run caused two mobile axe timeouts while the same landing suite passed 5/5 serially. Recommended: npm.cmd run test:e2e -- tests/e2e/healthbridge --workers=1 --timeout=60000.
## Core authenticated browser workflows (11 November 2026)

D:\dev-toolkit\tests\e2e\healthbridge\qa-role-workflows.spec.ts now contains three explicitly gated browser journeys against the disposable clone:
- Admin analytics read-only review: 1/1 passed.
- Patient four-step booking: 1/1 passed; synthetic Cardiology appointment booked with the QA doctor and checked in the patient appointment list.
- Doctor clinical encounter: 1/1 passed; start visit, autosave draft, create a synthetic prescription, review and complete. Persisted note/workflow/prescription/audit/outbox state was queried and verified in the disposable clone.
- Post-scenario restores passed exact 26-table row/checksum/schema/AUTO_INCREMENT comparison and mysqlcheck 26/26.
- Full HealthBridge folder with all QA enable flags absent: 15 passed, 6 skipped, 0 failed in serial mode.

Enable the workflow suite only with HB_QA_ENABLE_WORKFLOW_TESTS=1, HB_QA_CREDENTIALS_FILE pointing to the restricted local QA credential file, and BASE_URL=http://127.0.0.1:18511. Run one scenario at a time with --workers=1; restore the clone after any mutating scenario. Exact allowlists and lifecycle commands are documented in the hb-browser-qa-fixture-20261111 README.md and RESULT.md. Test credentials must never be printed or committed.

## Appointment lifecycle authenticated browser coverage (11 November 2026)

The existing seven-scenario workflow spec is guarded by `HB_QA_ENABLE_WORKFLOW_TESTS=1` and `HB_QA_CREDENTIALS_FILE`, and must use `BASE_URL=http://127.0.0.1:18511` only.
- Patient cancellation: 1/1 pass through the appointments card and confirmation modal; API list returned Cancelled.
- Patient reschedule request: 1/1 pass through the booking wizard; the original appointment date remained unchanged while the new date/time became a pending request.
- Admin approval endpoint: 1/1 pass after a genuine patient-created Pending booking; subsequent appointment read returned Confirmed.
- Admin decline endpoint: 1/1 pass after a separate patient-created Pending booking; subsequent read returned Cancelled. A direct clone-only check verified the audit reason and delivered patient notification.
- Each scenario was followed by stop/restore/reseed; all four cycles matched the immutable 26-table baseline, schema hash and AUTO_INCREMENT values, with mysqlcheck 26/26.
- Full shared suite with all QA enable variables absent: **15 passed, 10 skipped, 0 failed** (25 total).

**Known UI gap:** current `pages/admin/admin.html` contains neither `#admin-appointments` nor approve/decline record controls. The legacy action renderer in `assets/js/pages/admin.js` points at a missing table; the doctor profile appointment tab is read-only. Approval/decline were therefore tested as backend endpoints only. HB-UI-001 is open P2; add an active Admin appointment table and verify both controls through the real UI before considering Admin appointment management complete.

Evidence: `C:\xampp85\migration-backups\healthbridge\hb-browser-qa-fixture-20261111\RESULT.md` and its `evidence\` folder. The application PHP/HTML/JS/CSS files were not modified.

## Admin appointment-management UI acceptance (11 November 2026)

The Admin appointment-management gap HB-UI-001 is resolved in local source and tested through actual browser controls. The gated workflow spec now has two Admin UI acceptance cases (approval and decline), each preceded by a patient-created Pending booking through the real wizard. Both passed (2/2) in Chromium with a separate authenticated admin session; confirmation dialogs were accepted, successful POST responses observed, and persisted appointment state verified as Confirmed/Cancelled. Pending-only controls disappear after refresh.

The test must use HB_QA_ENABLE_WORKFLOW_TESTS=1, the ACL-restricted HB_QA_CREDENTIALS_FILE, and BASE_URL=http://127.0.0.1:18511; the hidden runner command and cleanup are in C:\xampp85\migration-backups\healthbridge\hb-browser-qa-fixture-20261111\README.md. The runner copies only the three updated Admin files into the isolated mirror, restores and verifies the disposable clone, reseeds synthetic users, then runs one worker. After completion the PHP process is stopped and the clone restored/reseeded. Never run against normal Apache or protected databases. The ordinary suite remains safe with workflow flags absent (gated scenarios skip by default).

Evidence: admin-appointment-ui-run.log, qa-role-workflows.spec.ts and the fixture RESULT.md. No deployment or production acceptance is implied.
