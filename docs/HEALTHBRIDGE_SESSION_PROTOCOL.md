# HealthBridge â€” New-Session and Work-Continuation Protocol

**Purpose:** Let a new ChatGPT session recover the current project state quickly without rereading thousands of historical lines or repeating completed work.

## Automatic session snapshot

**This is the first action in every new chat or resumed session. Run it before opening the handoff, roadmap, findings register, archive, or source files. Do not begin project review or implementation until the snapshot has been generated and read.**

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "C:\xampp85\htdocs\optimized\tools\healthbridge-session-snapshot.ps1"
```

Then read `C:\xampp85\migration-backups\healthbridge\session-snapshots\latest.md`. The complete current Git path list is in the adjacent `latest-changed-paths.txt`. This refreshes branch/HEAD/worktree data, phase priorities, finding candidates, documentation metadata and recent QA folder names.

Run the same command again at session closeout, after updating the canonical handoff/findings/roadmap records. Use `-Archive` only when a dated snapshot is worth retaining. By default, the two latest reports are overwritten; no growing journal is created. The generator is read-only with respect to source, Git and databases, and writes only its two snapshot outputs under the backup directory.


## The first 5â€“10 minutes of every new session

### 1. Read the compact context
Read in this order:
1. The compact operational handoff is maintained locally and intentionally excluded from version control. Use `docs/HEALTHBRIDGE_ENGINEERING_ROADMAP.md` and `docs/HEALTHBRIDGE_QA_EVIDENCE_INDEX.md` as repository entry points.
2. This file â€” how to revalidate state and navigate evidence.
3. The top dashboard and `Current next priorities` in `docs/HEALTHBRIDGE_ENGINEERING_ROADMAP.md` (do not read the entire historical roadmap by default).
4. The relevant current row(s) in `docs/HEALTHBRIDGE_FINDINGS_REGISTER.md`. Search by finding ID or feature area; do not assume old chronological notes are current.
5. The relevant route/module entries in the endpoint inventory or module map.
6. `docs/HEALTHBRIDGE_QA_EVIDENCE_INDEX.md` only when existing test evidence or backups are relevant.

The archived detailed handoff is a historical lookup source, **not** a startup reading assignment.

### 2. Revalidate the live machine state

Use the authorized Remote Desktop Commander connection to inspect, read-only:
- Confirm the project path exists.
- Record current Git branch and HEAD.
- Count current `git status --short` entries and inspect the changed-path names before touching files.
- Confirm the current handoff, roadmap and findings register exist.
- Check whether any QA runner/process relevant to the task is already active before starting another one.

Do not trust old branch, HEAD, worktree counts, process IDs, backup paths or timestamps as current. They are snapshots. The last known state on 9 November 2026 was branch `repair/healthbridge-staged-hardening`, HEAD `d4dd731`, and 233 worktree status entries; recheck before acting.

### 3. Resolve conflicting records correctly

Use this source precedence:
- **Actual source tree and runtime output:** what code/configuration currently does.
- **Findings register:** canonical finding ID, priority, current disposition, evidence and remaining acceptance criteria.
- **Engineering roadmap:** phase scope, exit gates and current priorities.
- **Active handoff:** concise current context, guardrails and next action.
- **Endpoint inventory / module map / repository audit:** structural reference for the relevant area.
- **QA evidence folders and archived handoff:** historical proof, raw logs, backups and decisions.

A dated note is not proof that a test still passes against today's tree. A local mock/intercepted browser test is not a real persistence or production test. When records disagree, inspect the latest source, test runner and log; correct the canonical record instead of adding another duplicate explanation.

## Work execution rules

- Keep the existing feature set. No new product features or broad rewrites.
- Work on the current branch and preserve all existing uncommitted changes.
- Never reset, clean, stash, switch branches, stage or commit unless the user explicitly asks.
- Do not inspect or print `.env` contents or credentials.
- Do not access hosted files, settings, services or databases. No commit, push or deployment without explicit authorization.
- Use synthetic, isolated local QA data. Before any mutation, capture the exact baseline and prove cleanup/restoration.
- Back up each affected source file before editing. Keep the backup in the relevant QA folder.
- Before a refactor, establish callers, input/output behavior, ownership, transaction boundaries and focused regression coverage.
- Avoid rerunning already-passed suites unless source changes affect them, a failure is suspected, or an explicit release regression run requires it.

## Shared testing toolkit — default for new automated checks

The shared toolkit is installed at D:\dev-toolkit. Treat it as the default test environment for new reusable HealthBridge unit, browser, accessibility and performance checks. Do not install duplicate npm/Composer testing dependencies into the application.

Before a browser run, verify the local app URL (currently http://127.0.0.1/optimized/) and set BASE_URL deliberately. Playwright's shared config defaults to port 8080 and its generic starter tests navigate to the origin root; HealthBridge-specific specs must explicitly navigate to /optimized/. Put unit specs under D:\dev-toolkit\tests\unit\healthbridge, E2E specs under D:\dev-toolkit\tests\e2e\healthbridge, and reports under D:\dev-toolkit\reports\healthbridge.

Use the installed tools as follows:
- **Vitest/jsdom + Testing Library:** pure helpers and JavaScript/TypeScript behavior.
- **Playwright Test + Chromium:** real browser workflows, page errors, requests and role journeys.
- **axe-core:** WCAG A/AA automated checks on desktop and mobile.
- **Mobile accessibility runner:** 390×844 viewport/touch emulation.
- **Lighthouse:** explicit local performance, accessibility, best-practices and SEO snapshots.
- **PHPUnit:** PHP unit/integration tests through D:\dev-toolkit\bin\phpunit.cmd.
- **PHPStan:** PHP static analysis through D:\dev-toolkit\bin\phpstan.cmd, with a reviewed project-specific config only.

The toolkit self-checks prove the runners work; they do not prove HealthBridge behavior. The current Playwright specs are generic starters, and HealthBridge-specific unit/E2E suites still need to be added. Chromium 1.65 is the verified browser; do not enable Firefox/WebKit without matching installed browser builds.

Keep specialist PHP/PowerShell runners for synthetic database migrations, transaction/concurrency, real HTTP persistence and exact backup/restore comparisons. The toolkit does not make destructive database tests safe. Preserve the existing safety rules: synthetic data only, disposable DB for writes, immutable baseline dumps, exact restore checks, and no hosted/protected database access. Copy accepted test specs and reports to a dated QA evidence folder because D:\dev-toolkit is outside the HealthBridge repository.

Canonical commands, environment details, current mobile baseline and limitations are in docs/HEALTHBRIDGE_TESTING_TOOLKIT.md.

## Evidence quality labels

Use precise language in findings and summaries:
- **Source reviewed:** static inspection only.
- **Syntax/lint passed:** parsing only; not behavioral proof.
- **Unit/helper test:** isolated function or service behavior.
- **Local HTTP verified:** actual local route behavior, with DB use and fixture boundaries stated.
- **Browser verified (mocked/intercepted):** UI behavior with API calls intercepted; no claim of persisted backend behavior.
- **Browser + local synthetic DB verified:** actual local route and synthetic persistence behavior; state whether fixtures were restored.
- **Restore verified:** backup restored into a separate database and compared with baseline.
- **Production verified:** only when explicitly authorized and actually performed. This status is currently not granted.

For every test, record the runner/log path, environment, whether requests were mocked, DB target, whether writes occurred, cleanup/restoration result, and uncovered cases.

## Finding and progress management

- Use stable finding IDs (for example `HB-SEC-010`, `HB-DB-001`, `HB-PERF-003`) as the work identifiers. A section number is not a task identity.
- Update an existing finding row when its implementation, evidence or disposition changes. Add a new finding only for a genuinely distinct issue.
- Keep each finding's status precise: distinguish locally changed, locally tested, fully verified against its stated acceptance criteria, and production-unverified.
- Update the roadmap only when evidence changes a phase's actual exit-gate progress. Do not derive progress from the number of tasks, files, assertions or section numbers.
- Do not append chronological numbered sections to the active handoff. The detailed archive is frozen.
- For a meaningful workstream milestone, write one short summary with: scope, files/findings affected, result, evidence location, limitations and next action.
- Keep the active handoff operational and under roughly 150 lines. Replace stale snapshot values rather than appending a new dated dashboard.

## End-of-session closeout

Before ending a session:
1. Run focused checks and inspect the final diff for the files changed.
2. Record exact test outcomes and QA artifact paths in the existing finding/workstream record.
3. Update the roadmap only if a phase gate changed.
4. Update the active handoff's **latest verified state**, **completed since last checkpoint**, and **single next action**.
5. Recheck branch, HEAD and worktree status count; state that the count is a snapshot.
6. State clearly what was not tested and what was not changed.
7. Do not claim the task is closed if any acceptance criterion remains.

## Suggested instruction to start a new chat

> Continue HealthBridge from the repository's active handoff. Follow `docs/HEALTHBRIDGE_SESSION_PROTOCOL.md`. First revalidate the live branch, HEAD and working-tree changes; then read only the relevant current roadmap priorities, finding rows and module/endpoint entries. Check existing QA artifacts before repeating tests. Preserve all uncommitted work, avoid hosted resources, and continue the highest-priority unfinished task. Do not create numbered sections for each subtask.

