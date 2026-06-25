## Context

Pass 1 grading is feature-complete in code (Changes E2a + E2b, canonical `pass1-grading` spec at 10 requirements) but has never run against a real LLM: every test uses `Http::fake` and `FakeGraderClient`. Two operational gaps block a live smoke test:

1. `RepoIntakeService::intake($source, $briefId)` is callable only via `tinker` — there is no artisan command, so intake isn't repeatable or auditable from the shell.
2. The DB ships empty: `DatabaseSeeder` creates only a `User`. To run `pass1:grade` you need a `Référentiel` → `Levels` → `Competences` (`kind='technique'`) → `Criteria` at `(competence, level)` → a `Brief` → `brief_competence` pivot rows with `level_id` → a `StudentRepo` with a readable `clone_path` → a `Run`. The operator would otherwise build this by hand in `tinker`, which is disposable and won't be re-run after the smoke test.

Canonical specs on `main`, all validated: `domain-model` (12), `pass1-grading` (10), `repo-intake` (5), `runner-cli` (8). The previous session confirmed `GRADER_API_KEY` is set in `.env`, `GRADER_BASE_URL`/`GRADER_MODEL`/`GRADER_FALLBACK_MODEL` configured. opencode/zen docs (fetched 2026-06-25) confirm paid `glm-5.2` is zero-retention — the operator's live-run sign-off is the residual gate, recorded in the handoff log (not a code change).

Architecture reminder: the runner is currently a **plain PHP CLI** (`php bin/runner <repoPath>`), invoked via Symfony `Process(['php', $runnerBin, $repoPath])` in `RepoIntakeService.php:91`. Docker is **not** used today; container hardening is a deferred `change/runner-sandbox`. Egress for the LLM is opencode/zen over HTTPS — the egress gate is the operator confirming `glm-5.2` zero-retention before the first real `pass1:grade`.

## Goals / Non-Goals

**Goals:**
- Make the full `intake → runner → pass1:grade` pipeline exercisable from the shell with no UI and no `tinker`, end-to-end, against a real `glm-5.2` call, repeatable on `migrate:fresh --seed`.
- Add the smallest possible surface needed: one new artisan command (`repo:intake`), one rewritten seeder (`DatabaseSeeder`), one new seeder (`SystemSeeder`), one `.gitignore` line (`storage/test-repos/`).
- Confirm on live output that the R1 hedging (`semble…`/`à vérifier`), R3 citation enforcement (drop phantoms), and R4 absence of identity all hold against a real LLM response — not just against faked fixtures.

**Non-Goals:**
- No UI (Livewire/DaisyUI) — the operator control panel is the next real change.
- No `apps/runner` modification of any kind (R2) — the command calls the existing runner binary as-is.
- No new domain-model migration, no new table, no `Evidence` rows at intake (Change C's Option X — Pass 1 only).
- No `pass1-grading` code/spec change (`Pass1GradingService::grade()` and the prompt/parser are unchanged).
- No artificial persona data — the seeder does NOT seed `operator_persona`; identity stays operator-private (R4).
- No live `glm-5.2` call inside this change's test suite — tests use fixture paths and `Http::fake`-free assertions on the intake command only, never the grader.
- No new dependency; no queue; no Docker; no `.env` secret change.

## Decisions

### D1 — `repo:intake {source} {brief}` is a thin artisan wrapper, not a new service.

**Choice:** The command is a 1-method artisan command (`handle()` ~10 lines) that calls `RepoIntakeService::intake($source, (int) $briefId)` and prints `"Run {id} created (status: {status})."` to stdout. It traps `BriefNotFoundException` / `ProcessFailedException` (clone fails) / `RunnerCrashException` (runner crashed) and returns exit 1 with a stderr message.

**Rationale (R5 — boring):** `RepoIntakeService` already encapsulates clone → runner → persist; re-implementing inside the command would violate R5 and split the audit trail. The command is the missing entry point, not new logic.

**Alternative considered:** put the entry on a future Livewire page — rejected; the UI is the next change, this change unblocks a smoke test *now* without waiting for the full panel.

### D2 — `{source}` accepts both a local path and a URL; the smoke test uses a local path.

**Choice:** The command forwards `{source}` verbatim to `RepoIntakeService::intake()`, which already distinguishes URL vs path (`isUrl()` at line 63). No new behavior is added in the command itself.

**Rationale — critical:** the smoke test must feed Pas1 a **readable `clone_path`** so that `RepoDigest::build($run->studentRepo->clone_path)` can read it. The service's `finally` block (`RepoIntakeService.php:57`) deletes the temp clone when the source is a URL — leaving `clone_path` as the original URL string, which `RepoDigest::build()` cannot readdir. If the smoke test used a GitHub URL, every Pass 1 competence would grade to `à vérifier` because the digest build would throw, and the smoke test would falsely read as "passed".

**Recommendation to operator (documentation, not code):** clone the test repo to a local path first (`git clone --depth 1 <url> <local-path>`) then pass `<local-path>` to `repo:intake`. The clone stays on disk for `pass1:grade` to digest. This is captured in the change's tasks (handoff note) and in the handoff log, not in code — the command accepts either form by design (URL intake is valid for runs that never call Pass 1).

### D3 — Seeder split: `DatabaseSeeder` calls `SystemSeeder` then `User::factory()`.

**Choice:** `DatabaseSeeder::run()` calls `(new SystemSeeder())->run()` then `User::factory()->create()` (preserve the stock dev user that some Laravel facades expect). `SystemSeeder` is one method, ordered: referentiel → levels → competences (each with `kind`) → criteria at 3 levels per competence → brief → `brief_competence` pivot rows for the 5 technique competences with target `level_id`.

**Rationale:** keeping the User seeder separate preserves the default Laravel dev convenience; isolating domain rows in `SystemSeeder` makes the seed auditable (it's the only place that builds domain state) and independently re-runnable.

### D4 — Idempotency via `firstOrCreate` keyed on stable codes.

**Choice:** every seeder row is matched by a stable code (`referentiels.code = 'CDA-2023'`, `levels.code = 'N1|N2|N3'`, `competences.code = 'T-C5|...|TR-C1b'`, `criteria.code = '<competence_code>-<level_code>'`, `briefs.code = 'threadforge-api-2026'`) and built with `firstOrCreate(['code' => …], […])`. `brief_competence` is upserted via `syncWithoutDetaching` keyed on `(brief_id, competence_id)` so re-running won't blow up on the unique constraint.

**Rationale (R5):** an idempotent seeder means `migrate:fresh --seed` is repeatable without surprises; the operator can iterate on the smoke test without hand-cleaning rows.

### D5 — `operator_persona` is intentionally NOT seeded.

**Choice:** `student_repos` rows are NOT built by the seeder. They are created by `repo:intake` at runtime via `RepoIntakeService::resolveStudentRepo` (which inserts `clone_path = $source`, `operator_persona = null`).

**Rationale (R4):** persona is the operator's private tag. Seeding fake personas would couple test data to identity and risk blurring the "persona never in Pass 1" guarantee. The smoke test exercises the real R4 path: a `StudentRepo` created at intake with `operator_persona = null`, never entering the digest or prompt.

### D6 — Criteria texts are inspired by the ThreadForge brief's "Critères de performance", not invented from nothing.

**Choice:** the operator (in the previous session) declined to supply the real référentiel's critères d'évaluation texts and instructed: "write them and use brief criteres de performances for inspiration." The seeder will write plausible criteria at each `(competence, level)` pair, drawn from the four performance families in the ThreadForge brief: (1) API architecture & sécurité (Sanctum, API Resources, N+1, Form Requests), (2) IA & asynchrone (Queues, 202 Accepted, structured-output contract, Eloquent casts), (3) couche agentic (function-calling tools, memory continuity, no hallucination), (4) code & livraison (commits atomiques, no massive commits, Scribe docs, README real). Each criterion at N1 is "presence/imitation" (does the thing exist), N2 is "adapter" (the thing fits the brief's context), N3 is "transposer" (the thing is generalized/extended).

**Trade-off — explicit:** these criteria are plausible smoke-test fixtures, NOT the operator's authoritative référentiel. After the smoke test, the operator should swap `SystemSeeder` with the real criteria texts before any real evaluation. This is recorded in the handoff/log.

### D7 — `storage/test-repos/` gitignored.

**Choice:** add a single line to `apps/web/.gitignore` (or the repo root `.gitignore` if more appropriate — confirmed during implementation). The clone lives outside git history and is never accidentally committed by an over-eager `git add .`.

**Rationale:** R5 + cleanup discipline; a test repo clone is disposable.

## Risks / Trade-offs

- **[Risk] Criteria texts are invented, so the live grade is meaningful as plumbing proof but not as a real evaluation.** → Mitigation: explicit non-goal + handoff-log note instructing the operator to replace with real criteria texts before any real evaluation.
- **[Risk] Operator runs `repo:intake` with a URL and the smoke test silently produces all-`à vérifier` Pass 1 rows because the digest can't read a URL.** → Mitigation: D2 — document the local-path recommendation in the command's `--help` description, the change's tasks handoff note, and the handoff log; the behavior itself is correct (URL intake is valid for runs that never call Pass 1).
- **[Risk] Live glm-5.2 call costs money.** → Mitigation: ~5 calls × 5–15k input tokens + small output ≈ $0.10–0.30 per run (paid zen rate $1.40/M in / $4.40/M out). Documented in handoff log; operator runs it once.
- **[Risk] Live call leaks student identity.** → Mitigation: R3/R4 are enforced in `Pass1Prompt` (blind) + `RepoDigest` (identity-free bundle) + the seeder does NOT seed persona — this change exercises the existing guarantee rather than weakening it.
- **[Trade-off] The seeder is opinionated domain data.** → Mitigation: it lives in `database/seeders/` (Laravel's reserved dev-only location), not `migrations/`, so it never runs in production; the operator can delete `SystemSeeder` without schema consequences once real data exists.

## Sandbox / Security Impact

**None.** No `apps/runner/` change is made (R2 untouched). No Docker, no container, no egress boundary change. No secret is committed (`GRADER_API_KEY` lives in `.env`, gitignored). The first LIVE `glm-5.2` call happens **only when the operator runs `php artisan pass1:grade <run>` after this change is merged AND after the operator confirms the glm-5.2 zero-retention gate (the go-live sign-off recorded in the handoff log, not a code change) — none of which is in this change's code path. The v0 "trusted repos only on local Laragon host" deferral stands; full sandbox hardening remains deferred to a future `change/runner-sandbox`.

## Migration Plan

1. Branch `feat/pass1-smoke-harness` off `main`.
2. Implement the 4 file changes + 1 test class per `tasks.md`.
3. Run `php artisan test` (must remain green; new tests included).
4. Commit + push + open PR.
5. After merge to `main`: archive via `openspec archive pass1-smoke-harness -y`, write the canonical `pass1-smoke-harness` spec Purpose by hand if needed, push closeout.
6. Operator's smoke test (post-merge, post-gate-sign-off): `git clone --depth 1 <repo-url> storage/test-repos/<name>` → `php artisan migrate:fresh --seed` → `php artisan repo:intake storage/test-repos/<name> 1` → `php artisan pass1:grade <run-id>` → `php artisan tinker` → inspect `evidence`/`drafts`/`pass1_competence_results`.
7. No rollback code required — the change is additive; to revert, drop the command file + restore the original `DatabaseSeeder.php` (the new tables/migrations are unchanged).

## Open Questions

None remaining. The two open questions from the planning conversation — (1) target level per competence, (2) OpenSpec change vs direct-to-main — were answered by the operator: target levels confirmed as proposed; an OpenSpec change is the chosen route.