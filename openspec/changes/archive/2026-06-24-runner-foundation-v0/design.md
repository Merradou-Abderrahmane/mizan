## Context

`apps/runner` does not exist yet. The autograder needs an evidence source
before any LLM Pass 1 / web UI is built: R3 requires every LLM claim to cite
file+line, and that evidence must originate from deterministic structural checks
that are fixed per stack (R2). This v0 stands up the smallest viable runner as a
PHP CLI so the evidence spine exists end-to-end.

`openspec/config.yaml` declares the runner executes UNTRUSTED student code and
"treat as hostile": ephemeral container, egress restricted during
`composer install`, no secrets mounted, hard CPU/mem/time limits. **None of
that hardening is in scope here** — see "Sandbox/security impact" below.

## Goals / Non-Goals

**Goals:**
- A PHP CLI entry point `apps/runner/bin/runner` that takes a student repo path
  and writes a fixed-schema JSON report to stdout.
- Six deterministic, **constant-per-stack** checks for PHP/Laravel (R2):
  composer install, app boots, migrations run, README real, `.env` not tracked,
  git history >1 commit.
- A pinned JSON report schema (in spec) that `apps/web` will later consume.
- PHPUnit-tested fixtures: a passing repo, a failing repo, and skip cases per
  check where applicable.
- A design that is **containerizable without API change**: the identical
  command works identically on the Laragon host now and inside an ephemeral
  container later.

**Non-Goals:**
- No Docker, no container, no egress gating, no secrets handling.
- No HTTP API, no queue job, no webhook, no `apps/web` wiring.
- No LLM calls, no grading, no verdict, no "à vérifier" decisions (R1).
- No Pass 1 / Pass 2 logic (R3). No student-identity awareness (R4).
- No per-brief logic. Checks are stack-specific and constant (R2, R5).
- No fancy heuristics beyond the six fixed checks (R5).
- No support for stacks other than PHP/Laravel in this change.

## Decisions

### D1. CLI entry point shape: `bin/runner <repoPath> [--composer=...]` → stdout
**Rationale:** Keeping the contract "argument in, JSON to stdout" makes the
runner trivially wrappable by `apps/web`'s future queue worker and by a future
container entrypoint without API change (R2 spirit: the runner shape stays
constant; only the execution environment changes later).
**Alternatives considered:**
- Write report to a file path arg: rejected — couples runner to host filesystem
  layout, breaks containerization.
- HTTP microservice: rejected — premature (YAGNI); adds a binding that a
  sandbox must later restrict.
- Return JSON via exit code only: rejected — too lossy for evidence.

### D2. Repo path is the ONLY positional arg; no host paths baked into code
**Rationale:** Satisfies the "containerizable without API change" constraint.
The repo root is passed in, never hardcoded. All writes (SQLite DB, temp files)
are scoped to `<repoPath>/storage/` or a `-w|--workdir` arg, never to host
global state.
**Alternatives considered:**
- Read repo path from env var: rejected — env is shared/global in containers,
  harder to make ephemeral per-run. Arg is per-invocation and stateless.

### D3. SQLite throwaway DB, created in `<repoPath>/storage/runner-sqlite-<pid>.sqlite`, deleted after the migration check
**Rationale:** Avoids touching operator's Laragon MySQL and survives
containerization (container has no MySQL by default). `sqlite::memory:` was
considered but Laravel migrations sometimes need file-backed DB for schema
reflection; a file under the repo workdir is container-safe and cleanable.
**Alternatives considered:**
- `sqlite::memory:`: may fail Laravel migration tooling; we keep the option open
  and note it as an Open Question.
- Operator's MySQL: rejected — host trust, breaks isolation.

### D4. `composer install --no-interaction --ignore-platform-reqs` via the `composer` binary on PATH (overridable by `--composer=`)
**Rationale:** Never assume a global Composer home; do not write to host
`~/.composer`. In container-future, we bind-mount a project-local Composer
cache or just let it download fresh. `--no-interaction` keeps the CLI
non-blocking. `--ignore-platform-reqs` avoids false failures on host extension
mismatches that are not the student's fault.
**Alternatives considered:**
- `composer install` with host defaults: rejected — prompts hang the CLI and
  pollute host state.

### D5. Checks run as discrete value objects implementing a `Check` interface; each returns a `CheckResult`
**Rationale:** Keeps the runner "boring" (R5): one class per check, fixed list,
no clever dispatcher. Future stacks (e.g., JS) get a sibling interface set
without touching the PHP/Laravel checks (R2). A `Runner` orchestrator iterates
the fixed list and emits the report.
**Alternatives considered:**
- Monolithic procedural script: rejected — harder to test in isolation and to
  extend per-stack later.

### D6. Strict JSON-only stdout; all logs go to stderr
**Rationale:** The contract with `apps/web` is the stdout JSON. Mixing logs into
stdout breaks parsing. `apps/web`'s future consumer reads stdout verbatim and
pipes stderr to the run log.

### D7. README "real" heuristic: exists AND non-empty AND not the verbatim Laravel stub AND `>= 200` bytes
**Rationale:** A "real README" check needs a deterministic threshold to stay
boring (R5) and testable. The 200-byte floor and stub-hash comparison are
constants the operator agreed on; configurable thresholds would invite per-brief
tweaking, violating R2.
**Alternatives considered:**
- Natural-language "is this README meaningful": rejected — would require an LLM,
  violating "no AI in this change" and R2.
- Only check file existence: rejected — too lenient; the Laravel default stub
  passes.

## Sandbox / Security Impact

**Stated explicitly per `openspec/config.yaml` rule ("design: Note the
sandbox/security impact explicitly, even if 'none'").**

This change's security impact: **deferred / not touched**, with a binding
constraint that keeps the deferral safe.

- **Temporary v0 constraint (MUST be revisited before `change/runner-sandbox`):**
  This v0 runner executes only **trusted operator-supplied sample repos** on the
  local Laragon host. It is NOT safe to point at arbitrary student repos until
  the sandbox change lands.
- **Containerizable-without-API-change constraint (binding on this change):**
  The v0 CLI must not bake in any design that assumes host trust:
  - No hardcoded absolute host paths (repo root passed as positional arg only).
  - No reliance on host global Composer (`~/.composer`) or host global Composer
    cache beyond what Composer itself manages for the project; `--no-interaction`
    and explicit binary override via `--composer=` are supported.
  - No reliance on host `.env` or host-secrets; the runner reads NO environment
    variables except an explicit allowlist (initially empty).
  - No writes outside `<repoPath>/storage/` (or an explicit `--workdir`).
  - Stateless between invocations: no temp files, lock files, or caches
    persisted outside the repo workdir.
  - The identical CLI command works identically whether run on host now or in
    an ephemeral container later — only the *environment* changes, not the API.
- **Hardening explicitly deferred to `change/runner-sandbox`** (which WILL touch
  the runner/sandbox/egress boundary and therefore **REQUIRES human review** per
  config): ephemeral container, network egress restriction during
  `composer install`, no secrets mounted into the student container, hard
  CPU/memory/time limits.

Any future change that relaxes the "no host trust" constraint before
`runner-sandbox` lands is a defect.

## Risks / Trade-offs

- **[Risk] Running student code on the operator host** (any `post-autoload-dump`
  Composer scripts, or service providers touched by `php artisan --version`)
  executes untrusted code outside a sandbox.
  → **Mitigation**: The "trusted repos only" v0 constraint. Until
  `runner-sandbox` lands, the operator only feeds it sample repos they vetted.
  The containerizable-without-API-change constraint guarantees the sandbox
  change is a clean wrap, not a rewrite.
- **[Risk] `composer install` makes network calls** in v0 (no egress gating).
  → **Mitigation**: Same trusted-repos v0 constraint. Explicitly enumerated as
  a deferred hardening task for `runner-sandbox`.
- **[Risk] SQLite extension missing on host**: migrations check fails for an
  environmental reason, not a student defect.
  → **Mitigation**: The migrations check returns status `skip` (not `fail`)
  with `error_class: "env_missing_sqlite"` when the extension is absent — the
  operator sees a skipped check, not a false fail.
- **[Risk] README stub detection drifts** if Laravel changes the default stub.
  → **Mitigation**: Store the stub contents as a fixture in `tests/fixtures/`;
  the comparator is byte-equality against that fixture, not a brittle substring
  match. If Laravel's stub changes, update the fixture in a dedicated task.
- **[Trade-off] No Docker now** = faster bootstrap now, **plus** a hard
  obligation to land `runner-sandbox` before any real student repo is graded.

## Migration Plan

- New, additive-only change; no existing behavior to migrate.
- Landing order: this change ships with tests green against the bundled fixture
  repos. The operator can invoke `bin/runner <fixture>` manually to sanity-check.
- Rollback: delete `apps/runner/`; nothing else depends on it yet.

## Open Questions

- OQ1. SQLite `:memory:` vs file-backed: should we try `:memory:` first and
  fall back to a file under the repo workdir? (Likely yes; to be decided in
  task implementation against Laravel's migration tooling.)
- OQ2. Should the runner expose a `--check=<id>` flag to run a single check
  (useful for `apps/web` re-run UI)? Probably yes but deferred to a follow-up
  to keep this change atomic and under the task budget.
- OQ3. Git-history check: require >1 commit only, or also >1 *author*?
  Current decision: >1 commit only — authorship is trivially spoofable and
  not the runner's concern (R5). Revisit if the operator wants a softer probe.