## 1. Outcome DTO

- [x] 1.1 Add `app/Services/Pass1/Pass1CompetenceOutcome.php`:
  `competence_id`, `competence_label`, `level_id`, `status` (`'graded'|'failed'`),
  `reason: ?string`, `criterion_count: int`. Readonly constructor; no identity, no
  verdict. Used by the service return + the command summary.

## 2. Pass1GradingService — orchestration

- [x] 2.1 Add `app/Services/Pass1/Pass1GradingService.php` with
  `grade(Run $run): array` (list of `Pass1CompetenceOutcome`). Inject
  `GraderClient`, `Pass1Prompt`, `Pass1ResponseParser` (constructor; the
  container resolves).
- [x] 2.2 Resolve technical scope: `$run->brief->competences()->technical()`
  with `withPivot('level_id')`. If empty, return `[]` (the command treats this
  as a hard precondition error; the service just returns empty).
- [x] 2.3 Build `RepoDigest` **once** from `$run->studentRepo->clone_path`
  (before the loop). If the build throws, let it propagate (command exits 1).
- [x] 2.4 Per competence: load `$competence->criteria()->where('level_id',
  $pivot->level_id)->get()`; render `Pass1Prompt::build($brief, $competence,
  $level, $criteria, $digest)`; call `GraderClient::complete($system, $user)`;
  `parseWithRepair($raw, $digest, $criteria, $reAsk)` where `$reAsk` appends
  `Pass1ResponseParser`'s repair hint to `$user` and re-calls the client.
- [x] 2.5 Persist each competence inside `DB::transaction(function () use (...) { … })`
  using delete-then-reinsert scoped to the competence: delete `evidence` and
  `drafts` rows whose `criterion_id ∈ this competence's criteria` and the
  `pass1_competence_results` row keyed `(run_id, competence_id)`, then insert
  fresh. Never write `operator_status`/`operator_note`/`finalized_at`. On an
  existing rollup row, update the AI columns only (do not touch operator
  columns) — use `updateOrCreate` scoped to `(run_id, competence_id)`.
- [x] 2.6 Persist per-criterion `evidence` rows (one per surviving `{file, line,
  note}`: `file_path`, `line_number`, `message`, `check_id`/`kind`/`status` =
  null) and `drafts` rows (`ai_status`, `ai_reasoning`, `ai_raw_json` = the
  parsed criterion entry as JSON).
- [x] 2.7 Persist the `pass1_competence_results` rollup: `ai_rollup_status`,
  `confidence`, `probe_questions` (JSON), `raw_json` (the full parsed result),
  `level_id = pivot.level_id`. On the failure path (unparseable/throw), persist
  ONLY the rollup with `ai_rollup_status = 'à vérifier'`, `confidence = null`,
  `probe_questions = []`, `raw_json = {unparseable: true, raw: <text>}` or
  `{error: <class>: <message>}`, and NO `evidence`/`drafts` rows.

## 3. Failure isolation + transaction integrity in the service

- [x] 3.1 Wrap each competence's call+parse in try/catch. On
  `Pass1ParsedResult->unparseable === true`: go to the failure-persist path
  (2.7) inside that competence's transaction; outcome `status = 'failed'`,
  `reason = 'unparseable'`. On any `Throwable` from the client or persistence:
  roll back that competence's transaction (rethrow inside `DB::transaction`),
  then open a NEW tiny transaction to persist the safe `à vérifier` rollup row
  with `{error: …}` `raw_json`; outcome `status = 'failed'`, `reason =
  <short>`. Continue to the next competence — never re-throw mid-run.
- [x] 3.2 Confirm by reading the source: there is exactly one
  `DB::transaction` call per competence and NO single `DB::transaction`
  wrapping the iteration over all competences.

## 4. pass1:grade command

- [x] 4.1 Add `app/Console/Commands/Pass1GradeCommand.php` with signature
  `pass1:grade {run}` and description "Run Pass 1 blind grading for a Run."
- [x] 4.2 Resolve `Run::findOrFail($run)`. On `ModelNotFoundException`, print
  "Run not found: {run}" and return 1.
- [x] 4.3 Set `$run->started_at = now(); $run->save()` before grading. After
  grading, set `$run->ended_at = now()` and `$run->status` =
  `pass1_done` (all outcomes `graded`) or `pass1_partial` (any `failed`); save.
- [x] 4.4 Call `Pass1GradingService::grade($run)`. If the outcome list is empty
  (no technical competences), print "Run {id}: no technical competences on the
  brief" and return 1 (do NOT call the grader — the service already returned
  empty without calling it).
- [x] 4.5 Print one line per outcome: `{competence_label} — graded` or
  `{competence_label} — failed: {reason}`. Then a final line
  `Run {id}: pass1_done|pass1_partial ({N} graded, {M} failed)`.
- [x] 4.6 Return 0 on `pass1_done` and on `pass1_partial`; return 1 only on
  run-not-found, no-technical-competences, or digest-build-threw.

## 5. Feature tests (FakeGraderClient, no network)

- [x] 5.1 `tests/Feature/Pass1/Pass1GradingServiceTest.php` — service tests
  inject `FakeGraderClient` directly (matching the E2a primitives test
  convention); command tests bind `GraderClient → FakeGraderClient` via
  `$this->app->bind()`. All tests queue canned JSON; no `Http::fake` needed
  and no live call.
- [x] 5.2 Technical-only scope: a brief with one `technique` + one
  `transversale` competence → only the technique competence is graded (one
  grader call recorded, one rollup row, none for transversale); the technique
  competence's `pass1_competence_results.level_id` equals its pivot level.
- [x] 5.3 Persistence shape: two criteria (one `semble valide` with an
  in-digest citation + reasoning, one `à vérifier` empty + reasoning) → one
  `evidence` row for C1 (file/line/message, check_id/kind/status null), none
  for C2; one `drafts` row per criterion with `ai_status` + `ai_reasoning`
  matching; exactly one `pass1_competence_results` row with rollup/confidence/
  probe_questions/level_id; `operator_status`/`operator_note`/`finalized_at`
  all null.
- [x] 5.4 One digest per run: with two technical competences, assert the
  digest is constructed once (spy/mock or count file reads) and both prompts
  share it.
- [x] 5.5 R4 blind: with `studentRepo.name = "alice-project"` and
  `operator_persona = "advanced"`, assert no recorded prompt contains those
  strings, the `clone_path`, or a git author string.
- [x] 5.6 Idempotent re-grade: grade once, capture row counts, grade again
  with a fresh response → counts unchanged (one rollup, one drafts per
  criterion, one evidence per surviving citation); no duplicate rows.
- [x] 5.7 Idempotent re-grade preserves operator finalization: pre-set
  `operator_status = 'valide'`, `finalized_at = now()` on A's rollup; re-grade
  → AI columns overwritten, `operator_status`/`operator_note`/`finalized_at`
  unchanged; `$result->finalVerdict()` still `'valide'`.
- [x] 5.8 Failure isolation — unparseable: queue `"not json"` then `"still not
  json"` for A and a good response for B → A's rollup is `à vérifier` with
  `raw_json.unparseable = true` and the raw text, NO A evidence/drafts rows; B
  grades normally; outcome list has A `failed` + B `graded`; run did not abort.
- [x] 5.9 Failure isolation — grader throws: fake client throws
  `RuntimeException` for A, good for B → A's rollup is `à vérifier` with
  `raw_json.error` recording class+message, no A evidence/drafts; B grades;
  run did not abort.
- [x] 5.10 Transaction integrity: simulate a persistence failure for B after A
  committed (e.g., force a duplicate insert to violate the unique
  `(run_id, competence_id)` constraint, or throw from a closure inside B's
  transaction) → A's rows remain; B has no rows; B's outcome is `failed` with
  the exception reason. Confirm via source read that there is one
  `DB::transaction` per competence and no whole-run transaction.

## 6. Command tests

- [x] 6.1 `tests/Feature/Pass1/Pass1GradeCommandTest.php` — successful run:
  two technical competences, both graded → final line
  `Run {id}: pass1_done (2 graded, 0 failed)`, `run.status = pass1_done`,
  `started_at`/`ended_at` set, exit 0.
- [x] 6.2 Partial run: one unparseable + one good → final line
  `Run {id}: pass1_partial (1 graded, 1 failed)`, `run.status = pass1_partial`,
  exit 0.
- [x] 6.3 Missing run: `pass1:grade 9999` → "Run not found", exit 1.
- [x] 6.4 No technical competences: brief with only `transversale` →
  "no technical competences" message, exit 1, grader NOT called (assert the
  fake client recorded zero calls).

## 7. Verify

- [x] 7.1 `php artisan test -- --exclude-group=runner` (or the non-runner
  subset) — all green. Run the full suite if green; the slow
  `RepoIntakeServiceTest` real-runner timeouts are a known environmental flake
  (Change C) and may be excluded.
- [x] 7.2 `openspec validate pass1-grading-orchestration --strict` — passes.
- [x] 7.3 Grep-verify zero live HTTP in the new tests (no `Http::get`/`Http::post`
  outside `Http::fake`; the `FakeGraderClient` is the only grader used).
- [x] 7.4 Confirm no `apps/runner/` change; confirm no schema migration added;
  confirm no UI/Livewire component added.
- [x] 7.5 Confirm the service never writes `operator_status`/`operator_note`/
  `finalized_at` (grep the service for those column names → only present in
  read contexts, never in `update`/`create` payloads).

## 8. Branch, PR, archive closeout (operator merges)

- [x] 8.1 Branch `feat/pass1-grading-orchestration` off `main` at apply time.
  Never commit to `main`.
- [x] 8.2 Push branch (commit `6ca7c82`), PR created + merged by operator
  (PR #9 at `5f54791`).
- [x] 8.3 After PR merge: `openspec archive pass1-grading-orchestration -y` —
  ADDs the 5 new requirements to the canonical `pass1-grading/spec.md`.
- [x] 8.4 Update the canonical `pass1-grading/spec.md` `## Purpose` to note E2b
  is done (orchestration + command shipped; the egress gate is the operator's
  go-live sign-off, not a code change).
- [x] 8.5 `openspec validate pass1-grading --specs` — passes (all 4 specs).
- [x] 8.6 Append a handoff-log entry (E2b done; egress gate still outstanding
  for the first real run; next is Pass 2 or UI).
