## 1. Branch & service skeleton

- [x] 1.1 Create feature branch `feat/change-c-repo-intake` off `main`. Work only on this branch; never commit to `main`.
  - Check: `git branch --show-current` outputs `feat/change-c-repo-intake`; `git log --oneline -1` shows the same base as `origin/main`.

- [x] 1.2 Create `app/Services/RepoIntakeService.php` with the `intake(string $source, int $briefId, ?int $studentRepoId = null, ?string $operatorPersona = null, ?string $name = null): Run` method signature and the URL-vs-path detection (URL if starts with `http://`, `https://`, or `git@`; else local path). Resolve the `Brief` (throw `ModelNotFoundException` if not found). No migrations this change — `evidence` table and `domain-model` spec are untouched.
  - Check: PHPUnit test — `intake()` with a non-existent brief id throws; URL detection is unit-testable (delegate to a private `isUrl()`).

## 2. Clone + runner invocation

- [x] 2.1 Implement the clone step: for URL sources, create a UUID subdirectory under `storage/runner-clones/`, run `git clone --depth 1 <url> <dir>` via Symfony Process; on failure, throw with the clone stderr. For local-path sources, skip cloning and use the path as-is.
  - Check: PHPUnit test — invoking with a local temp fixture path does NOT create anything under `storage/runner-clones/`; invoking with a URL (a local `file://` test repo or a mock) creates a clone dir.

- [x] 2.2 Implement the runner subprocess call: `php <base_path>/apps/runner/bin/runner <repoPath>` via Symfony Process, capturing stdout + stderr. No custom env vars passed (R2). No `--composer`/`--workdir` flags.
  - Check: PHPUnit test — the service calls the runner and captures stdout; no `apps/runner/` file is modified (assert via `git status --porcelain apps/runner/` is empty after the test run).

## 3. Report parsing + Run persistence (no Evidence rows)

- [x] 3.1 Implement report parsing: decode stdout JSON; on valid report, map `status` → Run status, `started_at`/`ended_at` → Run timestamps, full report → `runner_report_json`. On invalid JSON, create a `Run` with `status = "error"`, `runner_report_json = ["raw_stdout" => <raw>]`, and throw.
  - Check: PHPUnit test — feeding a valid runner report fixture (from the runner's own `tests/fixtures/valid_repo` or a stub JSON) persists a Run with the correct status and full blob; feeding invalid JSON persists an error Run and throws.

- [x] 3.2 Implement StudentRepo creation/reuse: if `$studentRepoId` provided, reuse it and ignore `$operatorPersona` (R4 — existing persona stands); else create a new `StudentRepo` with `name` (explicit or derived from source basename — strip `.git`, take trailing dir for paths), `clone_path` = source, `operator_persona` = `$operatorPersona` (nullable). Wrap the Run + StudentRepo persistence in a DB transaction. NO `Evidence` rows are created (D5 — runner results live in the blob only).
  - Check: PHPUnit test — new StudentRepo created with derived name + persona stored + hidden from serialization (R4); existing StudentRepo reused, no new row, persona unchanged; 0 `Evidence` rows for the Run even on a 6-check report.

## 4. Cleanup + persona guard

- [x] 4.1 Implement cleanup in a `finally` block: delete the clone directory if it was created (URL sources only). Local-path sources are never deleted. Deletion is recursive and idempotent (no error if the dir is already gone).
  - Check: PHPUnit test — after `intake()` with a URL source, the clone dir does not exist on disk even when the runner fails or throws; after `intake()` with a local path, the local path still exists.

- [x] 4.2 Add a test asserting the operator persona NEVER reaches the runner subprocess: the service stores it on `StudentRepo` (R4, hidden) but does not pass it as an env var, arg, or flag to the runner. Assert the subprocess env does not contain `operator_persona` and no `Evidence` row contains the persona value (and no Evidence rows exist at all).
  - Check: PHPUnit green; test name references R2 + R4.

## 5. R3 guarantee test

- [x] 5.1 Add a test asserting that a successful `intake()` creating a `Run` with a 6-check report produces ZERO `Evidence` rows — the runner structural results live in `runner_report_json` only, `evidence` stays strictly per-competence (R3). Assert `Run.runner_report_json` contains the full report.
  - Check: PHPUnit green; test name references R3; `Evidence::where('run_id', $run->id)->count() === 0`.

## 6. Final tests, validate, PR, handoff

- [x] 6.1 Run `php artisan test` in `apps/web` and confirm all tests green — existing domain tests + new intake tests.
  - Check: 0 failures, 0 errors.

- [x] 6.2 Run `php artisan migrate:fresh --force` then `php artisan test` to confirm the suite passes from a clean schema (no new migrations this change, but confirms idempotency).
  - Check: `migrate:fresh` completes; all tests green on the fresh schema.

- [x] 6.3 Run `openspec validate repo-intake` and confirm the change is valid.
  - Check: outputs `Change 'repo-intake' is valid`.

- [x] 6.4 Commit all new files on `feat/change-c-repo-intake`, push the branch, and open a PR against `main`. Do NOT merge — the operator reviews and merges.
  - Check: `gh pr create --base main --head feat/change-c-repo-intake` returns a PR URL; `git status` is clean; `git log --oneline -1` shows the commit on the feature branch.

- [x] 6.5 Append an entry to `docs/handoff-log.md` recording this change name, the branch + PR URL, how R2/R3/R4 are respected (runner called as-is, no Evidence rows — R3 intact, persona never in subprocess — R4), the Option X decision (runner results in blob only, `evidence` untouched), the sandbox-deferral standing, and the next planned step.
  - Check: `docs/handoff-log.md` is non-empty; the entry names `repo-intake`, the PR URL, R2/R3/R4, and the Option X decision.