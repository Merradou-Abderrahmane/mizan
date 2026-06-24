## 1. Runner skeleton & contract

- [x] 1.1 Create `apps/runner/composer.json` (PSR-4 `Mizan\\Runner\\` → `src/`, php ≥8.2, dev dep `phpunit/phpunit`); run `composer install` inside `apps/runner`.
  - Check: `apps/runner/vendor/autoload.php` exists; `composer validate` exits 0.

- [x] 1.2 Create `apps/runner/bin/runner` (shebang `#!/usr/bin/env php`) that bootstraps autoload, parses argv (positional `<repoPath>`, optional `--composer=<path>`, optional `--workdir=<path>`), and exits `2` with a stderr usage message when repoPath is missing.
  - Check: `php apps/runner/bin/runner` with no args exits `2` and writes nothing to stdout; usage on stderr.

- [x] 1.3 Implement `Mizan\Runner\Input` value object: validated repoPath (must be a dir), optional composer binary path, optional workdir. Rejects env reads not on the allowlist (allowlist empty).
  - Check: PHPUnit `InputTest` — missing-arg throws, non-dir throws, env-read throws, valid path constructs.

- [x] 1.4 Implement `Mizan\Runner\Report` (the JSON shape from `specs/runner-cli/spec.md`): immutable, `toJson(): string`, `addCheck(CheckResult)`, sets top-level `status` per spec rules (pass/fail/error).
  - Check: PHPUnit `ReportTest` — all-pass → `status:"pass"`; one-fail → `status:"fail"`; error → `status:"error"` with empty checks array; JSON round-trips through `json_decode` and validates against the schema (key order in `checks` matches the fixed order).

- [x] 1.5 Implement `Mizan\Runner\Runner` orchestrator: receives `Input`, iterates the FIXED ordered list of six `Check` instances, catches exceptions per check into a `skip`/`error` result, calls `Report::toJson()`, writes to stdout, exits `0`/`1`.
  - Check: PHPUnit `RunnerTest` using stub `Check` implementations — exit code `0` when all pass, `1` when any fail; stdout parses as the Report schema; no non-JSON bytes on stdout.

## 2. Check interface & first three checks

- [x] 2.1 Define `Mizan\Runner\Checks\Check` interface: `id(): string`, `run(Input $in): CheckResult`. Define `CheckResult` value object matching the per-check JSON shape (`id`, `status`, `duration_ms`, `evidence[]`, `error_class`, `message`).
  - Check: PHPUnit `CheckResultTest` — `pass()`, `fail()`, `skip()` factories set the right status and error_class nullable rules.

- [x] 2.2 Implement `ComposerInstallCheck`; uses `--no-interaction --ignore-platform-reqs`, never `skip`. Captures stdout+stderr into `evidence` (`kind:"stdout"`, `kind:"stderr"` excerpts).
  - Check: PHPUnit `ComposerInstallCheckTest` against `tests/fixtures/valid_repo` (pass) and `tests/fixtures/broken_deps_repo` (fail with stderr evidence).

- [x] 2.3 Implement `AppBootsCheck`; runs `php artisan --version` after composer install (orchestrator orders it AFTER `composer_install`), greps stdout for `Laravel Framework`. `fail` cites stderr/stdout excerpt.
  - Check: PHPUnit `AppBootsCheckTest` against `valid_repo` (pass, evidence has the version line) and `tests/fixtures/broken_bootstrap_repo` (fail).

- [x] 2.4 Implement `MigrationsRunCheck`; creates SQLite at `<repoPath>/storage/runner-sqlite-<pid>.sqlite`, overrides Laravel's DB config at runtime (does NOT mutate the student's `.env`), runs `php artisan migrate --force`, deletes the SQLite file in `finally`. `skip` with `error_class:"env_missing_sqlite"` when `pdo_sqlite` not loaded.
  - Check: PHPUnit `MigrationsRunCheckTest` — `valid_repo` → pass and the SQLite file is gone afterward; `tests/fixtures/broken_migration_repo` → fail with the failing migration's `file` and `line`; skip test by faking the missing-extension branch via a subclass seam.

## 3. Filesystem & git checks

- [x] 3.1 Implement `ReadmeRealCheck`; checks README/README.md/README.txt (case-insensitive) ≥200 bytes and not byte-equal to `tests/fixtures/laravel_readme_stub.md`. `fail` cites `file` + length or stub-match.
  - Check: PHPUnit `ReadmeRealCheckTest` — `valid_repo` pass; `tests/fixtures/stub_readme_repo` fail with `file:"README.md"`; `tests/fixtures/no_readme_repo` fail with `error_class:"readme_missing"`.

- [x] 3.2 Implement `EnvNotTrackedCheck`; runs `git ls-files` and looks for `.env` (NOT `.env.example`). `skip` with `error_class:"not_a_git_repo"` on non-zero `git ls-files`.
  - Check: PHPUnit `EnvNotTrackedCheckTest` — `valid_repo` (pass), `tests/fixtures/env_committed_repo` (fail, `file:".env"`), and a non-git temp dir (skip).

- [x] 3.3 Implement `GitHistoryRealCheck`; `git rev-list --count HEAD` >1. `skip` on non-git repo with `error_class:"not_a_git_repo"`; `fail` with `error_class:"single_commit_history"` on count ≤1.
  - Check: PHPUnit `GitHistoryRealCheckTest` — 3-commit fixture pass, 1-commit fixture fail, non-git temp dir skip.

## 4. Fixtures & end-to-end test

- [x] 4.1 Create fixture repos under `apps/runner/tests/fixtures/`: `valid_repo`, `broken_deps_repo`, `broken_bootstrap_repo`, `broken_migration_repo`, `stub_readme_repo`, `no_readme_repo`, `env_committed_repo`, `single_commit_repo`; add the `laravel_readme_stub.md` byte-comparison fixture. Each non-valid fixture breaks exactly ONE check.
  - Check: `find apps/runner/tests/fixtures -name .git` lists git repos; each fixture has a documented single broken aspect in a `fixtures/README.md`.

- [x] 4.2 PHPUnit end-to-end `RunnerEndToEndTest`: run `bin/runner` against `valid_repo` (exit 0, Report status `pass`), against `stub_readme_repo` (exit 1, status `fail`, only `readme_real` fails), and against a non-existent path (exit 1, status `error`, empty checks). Valid using `Process` component (symfony/console or symfony/process via composer deps).
  - Check: 3 assertions pass; each captures stdout, validates it parses as Report JSON, and asserts the exit code.

## 5. Operator handoff

- [x] 5.1 Append an entry to `docs/handoff-log.md` recording this change name, the prompt that produced it, the sandbox-deferral decision, and the next planned step (`Change B — Domain model & migrations`).
  - Check: `docs/handoff-log.md` exists and is non-empty; the entry names `runner-foundation-v0` and explicitly references `change/runner-sandbox` as the deferred security-boundary change requiring human review.