## Why

The operator needs a deterministic, stack-specific structural checker that emits
flat evidence JSON before any LLM pass runs. Without it there is no evidence to
cite (R3 requires file+line citations) and no way to keep the runner "dumb and
constant" (R2). This change stands up the smallest possible runner — a PHP CLI
script that takes a cloned student repo path and returns a JSON report — so the
evidence spine exists before any grading, Pass 1, or web UI is built.

## What Changes

- Add `apps/runner/` to the monorepo (PHP CLI entry point, `bin/runner` or `runner` script).
- Implement six fixed structural checks for the PHP/Laravel stack:
  1. `composer install --no-interaction` succeeds.
  2. Application boots: `php artisan --version` exits 0.
  3. Migrations run against a throwaway SQLite DB created inside the repo
     `storage/` path and deleted afterward (never host MySQL).
  4. A real `README` exists (non-empty, above a minimum length, not the Laravel
     default stub).
  5. `.env` is not committed to git (verified via `git ls-files`).
  6. Git history is real (>1 commit).
- Emit a single JSON report to stdout with exact schema (see spec).
- Add PHPUnit test fixtures: a passing repo, a failing repo, and a skipped-check
  repo per check where applicable.
- **No** AI, **no** web wiring, **no** Docker, **no** queue, **no** LLM calls
  in this change.

## Capabilities

### New Capabilities
- `runner-cli`: PHP CLI entry point that takes a student repo path and emits a
  fixed-schema JSON report of structural-check evidence for the PHP/Laravel
  stack. Stateless, containerizable, no host trust assumed.

### Modified Capabilities
<!-- None — no existing specs in openspec/specs/ yet. -->

## Impact

- **Code**: New `apps/runner/` directory with `bin/runner`, check implementations
  under `src/Checks/`, `composer.json` for the runner, and `phpunit.xml` + tests
  under `tests/` with fixture repos under `tests/fixtures/`.
- **APIs**: A new in-process contract — the JSON report schema consumed later by
  `apps/web` (Change C). No HTTP API, no shared library yet.
- **Dependencies**: Runner needs PHP 8.2+ and the `composer` binary available on
  PATH (or via a `--composer=` flag). SQLite PHP extension enabled.
- **Hard rules touched**:
  - **R2** — runner stays dumb and constant. Checks are stack-specific
    (PHP/Laravel) and do not change per brief. Respected: only the fixed six
    checks exist; no per-brief logic, no LLM, no grading.
  - **R5** — boring app, rich operations. Respected: the runner does only what
    is asked; no clever heuristics, no extra checks beyond the fixed list.
  - **R1, R3, R4** — NOT touched. No verdict, no Pass 1/2, no identity handled.
    Stated explicitly for traceability.
- **Sandbox/security boundary**: **Not touched** by this change. This v0 runs
  only trusted operator-supplied sample repos on the local Laragon host.
  Sandbox hardening (ephemeral container, egress gating, no secrets, hard
  CPU/mem/time limits) is deferred to `change/runner-sandbox`, which WILL touch
  the security boundary and therefore REQUIRES human review. See `design.md` for
  the containerizable-without-API-change constraint that keeps this handoff clean.