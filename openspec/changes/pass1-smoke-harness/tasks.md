## 1. Branch + scaffolding

- [ ] 1.1 Create feature branch `feat/pass1-smoke-harness` off `main` (never apply on `main`).
- [ ] 1.2 Confirm `openspec validate pass1-smoke-harness --strict` is green before any code is written.

## 2. `repo:intake` artisan command

- [ ] 2.1 Create `apps/web/app/Console/Commands/RepoIntakeCommand.php` with signature `repo:intake {source} {brief}` and description `Clone (if URL) or accept a local path, run the runner's structural checks against it, and persist a Run for the given brief. For pass1:grade to read a digest, pass a LOCAL PATH — URL clones are deleted by the service after the runner finishes.`.
- [ ] 2.2 In `handle()`: cast `{brief}` to int (invalid → print `Brief id must be an integer.` to stderr, return `Symfony\Console\Command\Command::FAILURE`), resolve `RepoIntakeService` from the container, call `->intake($source, $briefId)` inside try/catch.
- [ ] 2.3 Catch `Illuminate\Database\Eloquent\ModelNotFoundException` (Brief not found) → print `Brief not found: {id}` to stderr, return `FAILURE`. Catch `Symfony\Process\Exception\ProcessFailedException` (clone failed) and `App\Services\RunnerCrashException` (runner crashed) → print `Intake failed: {class}: {message}` to stderr, return `FAILURE`. On success → print `Run {$run->id} created (status: {$run->status}).` to stdout, return `SUCCESS`.
- [ ] 2.4 Add the command to the kernel auto-discovery (Laravel discovers `app/Console/Commands` by default — verify by running `php artisan list | grep repo:intake` and asserting it appears).

## 3. `SystemSeeder` — référentiel graph

- [ ] 3.1 Create `apps/web/database/seeders/SystemSeeder.php` with `run()` building a single `Référentiel` via `firstOrCreate(['title' => 'CDA 2023 — Concepteur⋅rice développeur⋅se d\'applications'], ['description' => <short blurb>])` — note: `referentiels` has no `code` column, match by `title`.
- [ ] 3.2 Create the three `Level` rows via `firstOrCreate(['referentiel_id' => $r->id, 'code' => 'N1'|'N2'|'N3'], ['label' => 'imiter'|'adapter'|'transposer', 'sort_order' => 1|2|3])`.
- [ ] 3.3 Create the eleven `Competence` rows via `firstOrCreate(['referentiel_id' => $r->id, 'code' => …], ['label' => …, 'kind' => 'technique'|'transversale'])` using the exact codes/labels from the spec (5 technique + 6 transversale).
- [ ] 3.4 Create the 33 `Criterion` rows via `firstOrCreate(['competence_id' => …, 'level_id' => …, 'code' => "{competence_code}-{level_code}"], ['label' => …, 'description' => …, 'sort_order' => …])` (matches the `unique(competence_id, level_id, code)` index). Criterion descriptions SHALL be non-empty and inspired by the ThreadForge brief's performance families (Sanctum / API Resources / N+1 / Queue / 202 Accepted / structured output / Eloquent cast / function-calling tool / conversation memory / atomic commits / Scribe). N1 criteria are presence/imitation; N2 adapter to brief context; N3 transposer/generalize.
- [ ] 3.5 Create the one `Brief` via `firstOrCreate(['referentiel_id' => $r->id, 'title' => 'ThreadForge API — Assistant IA de Repurposing et Distribution pour Créateurs Tech'], ['description' => <full brief text from the proposal>])` — note: `briefs` has no `code` column, match by `(referentiel_id, title)`.
- [ ] 3.6 Attach the five technique competences to the brief with target `level_id` per the spec (T-C7→N2, T-C6→N2, T-C3→N2, T-C5→N3, T-C9→N1) using `$brief->competences()->syncWithoutDetaching([<competence_id> => ['level_id' => <level_id>], …])`. No transversale competence is attached.

## 4. `DatabaseSeeder` rewrite

- [ ] 4.1 Rewrite `apps/web/database/seeders/DatabaseSeeder.php` `run()` to call `(new SystemSeeder())->run()` then `\App\Models\User::factory()->create()` (preserve the stock dev user). Wrap both in `Model::unguarded()` to bypass mass-assignment guards inside the seeder.

## 5. `.gitignore` update

- [ ] 5.1 Add `storage/test-repos/` to `apps/web/.gitignore` (a single new line, scoped — no other rule changes).

## 6. Tests

- [ ] 6.1 Create `apps/web/tests/Feature/RepoIntakeCommandTest.php` with `test_intake_creates_run_for_local_path` (uses a committed lightweight fixture dir under `tests/fixtures`, asserts `Run::count()` increments by 1, asserts artisan output contains `Run ` and `created (status:`, asserts exit 0). NO live network, NO live runner download — use a committed small repo fixture OR mock the runner subprocess if no fixture is available (prefer a real fixture path so the integration is honest, falling back to `Process::fromShellCommandline` mock only if fixture download is required).
- [ ] 6.2 Same test class: `test_intake_fails_when_brief_not_found` (asserts `Brief not found: 9999` on stderr, exit 1, `Run::count()` unchanged).
- [ ] 6.3 Same test class: `test_intake_fails_with_non_numeric_brief` (asserts `Brief id must be an integer.` on stderr, exit 1, no service invocation).
- [ ] 6.4 Create `apps/web/tests/Feature/SystemSeederTest.php` with `test_seeder_creates_full_graph` (runs `migrate:fresh --seed` against in-memory SQLite, asserts row counts: Referentiel=1, Level=3, Competence=11, technique=5, transversale=6, Criterion=33, Brief=1, brief_competence=5, StudentRepo=0, Run=0, Evidence=0, Draft=0, Pass1CompetenceResult=0).
- [ ] 6.5 Same test: `test_seeder_is_idempotent` (runs the seeder twice by calling `(new SystemSeeder())->run()` two times in a row inside the test, asserts row counts unchanged after second run; no exceptions raised on the unique `brief_competence` constraint).
- [ ] 6.6 Same test: `test_seeder_does_not_seed_persona` (asserts `StudentRepo::count() === 0`, and asserts no row in any of `competences`, `brief_competence`, `criteria` exposes an `operator_persona` column with a non-null value — schema-level guard).
- [ ] 6.7 Same test: `test_seeder_attaches_target_levels_only_to_technique_competences` (for each technique competence asserts exactly one `brief_competence` row with non-null `level_id` resolving to a Level in {N1,N2,N3}; for each transversale competence asserts zero `brief_competence` rows).
- [ ] 6.8 Same test: `test_criterion_descriptions_reference_brief_performance_families` (collects descriptions from {T-C5,T-C6,T-C3,T-C7,T-C9} × {N1,N2,N3} = 15 criterion descriptions, asserts at least one description across that set matches a regex of ThreadForge term: `Sanctum|API Resource|Form Request|N\+1|Queue|202 Accepted|structured output|JSON cast|function[- ]calling|tool|conversation|atomic commit|Scribe`).

## 7. Validate + verify

- [ ] 7.1 Run `php artisan test` and require all green (full non-runner suite; the slow `RepoIntakeServiceTest` may be excluded from this run if it would block on environment timing — note in commit body).
- [ ] 7.2 Run `php artisan migrate:fresh --seed` against the local MySQL DB and visually confirm the seeded rows; no rollback needed (the seeder is additive).
- [ ] 7.3 Run `php artisan list | grep repo:intake` and confirm the command is registered with its description.
- [ ] 7.4 Run `openspec validate pass1-smoke-harness --strict` and require green.
- [ ] 7.5 Confirm `git status -- apps/runner` is empty after running the new command (R2 — runner untouched).

## 8. Commit + PR

- [ ] 8.1 Commit all changes (4 file changes + 1 test class + 1 seeder) on `feat/pass1-smoke-harness` with a conventional-commit message; reference the change name in the body.
- [ ] 8.2 Push the branch and open a GitHub PR with `gh pr create --base main --title "feat: pass1 smoke harness (repo:intake command + idempotent domain seeder)" --body "<summary referencing openspec change>"`.
- [ ] 8.3 After PR merge: pull `main`, run `openspec archive pass1-smoke-harness -y`, fix the canonical `pass1-smoke-harness/spec.md` `## Purpose` by hand (replace `TBD` with a real Purpose tying the capability to R2 + R4 + R5), run `openspec validate --specs`, commit + push the closeout.

## 9. Handoff note (operator smoke-test recipe)

- [ ] 9.1 Append a new dated section to `docs/handoff-log.md` documenting: (a) what shipped, (b) the smoke-test recipe (`git clone --depth 1 https://github.com/IBamou/ForgeCoreApi.git apps/web/storage/test-repos/ForgeCoreApi` → `php artisan migrate:fresh --seed` → `php artisan repo:intake storage/test-repos/ForgeCoreApi 1` → `php artisan pass1:grade <run-id>`), (c) the residual egress gate (operator confirms glm-5.2 zero-retention before the first LIVE `pass1:grade`), (d) the explicit warning that the seeder's criteria texts are smoke-test fixtures inspired by the ThreadForge brief and must be replaced by the operator's real référentiel texts before any real evaluation, (e) one-liner to resume.