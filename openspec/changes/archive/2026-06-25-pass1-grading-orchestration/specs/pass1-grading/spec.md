## ADDED Requirements

### Requirement: Pass1GradingService orchestrates a Run into persisted Pass 1 results (R2, R3, R4)
The system SHALL provide `Pass1GradingService::grade(Run)` that resolves the
run's brief's technical competences — `run.brief->competences()` filtered to
`kind = 'technique'` — and for each competence grades at the level recorded on
the `brief_competence` pivot (`pivot.level_id`). It SHALL build the `RepoDigest`
**once** per run from `run.studentRepo.clone_path` and reuse it for every
competence. For each competence it SHALL load the criteria at the pivot level
(`competence->criteria()->where('level_id', pivot.level_id)`), render the blind
`Pass1Prompt`, call `GraderClient::complete`, and `parseWithRepair` (with a
`$reAsk` closure that re-calls the client with the repair hint). It SHALL then
persist, per criterion: one `evidence` row per surviving `{file, line, note}`
citation (with `file_path`, `line_number`, `message`, `check_id`/`kind`/`status`
null) and one `drafts` row (`ai_status` from the parser, `ai_reasoning` from the
parser's `reasoning`, `ai_raw_json` carrying that criterion's parsed entry); and
exactly one `pass1_competence_results` row keyed `(run_id, competence_id)` with
`ai_rollup_status` from the parser's rollup, `confidence`, `probe_questions` as
JSON, `raw_json` carrying the full parsed result, and `level_id` =
`pivot.level_id`. It SHALL NOT write `operator_status`, `operator_note`, or
`finalized_at` (R1 — operator finalizes). The service SHALL return a list of
`Pass1CompetenceOutcome` DTOs (`competence_id`, `competence_label`, `level_id`,
`status: 'graded'|'failed'`, `reason: ?string`, `criterion_count`); the DTO SHALL
carry no student identity and no verdict.

#### Scenario: Technical competences are graded at their brief target level
- **GIVEN** a `Run` whose brief links competence A (`kind = 'technique'`) at
  level N2 and competence B (`kind = 'transversale'`) at level N1, with criteria
  defined for A at N2
- **WHEN** `Pass1GradingService::grade($run)` is called with a `FakeGraderClient`
  queued with a well-formed response for A
- **THEN** competence A SHALL be graded at N2 (its criteria at N2 are the ones
  sent in the prompt)
- **AND** competence B SHALL NOT be graded (no grader call, no
  `pass1_competence_results` row for B)
- **AND** a `pass1_competence_results` row for `(run, A)` SHALL exist with
  `level_id = N2`

#### Scenario: Per-criterion evidence, drafts (with reasoning), and rollup are persisted
- **GIVEN** a `Run` with one technical competence at level N1 having two
  criteria (C1, C2), and a `FakeGraderClient` queued with a well-formed response
  where C1 is `semble valide` with one in-digest citation and `reasoning = "r1"`,
  and C2 is `à vérifier` with no evidence and `reasoning = "not-found: ..."`
- **WHEN** `grade($run)` is called
- **THEN** one `evidence` row SHALL exist for C1 with the cited `file_path` +
  `line_number` + `message`, and `check_id`/`kind`/`status` all null
- **AND** no `evidence` row SHALL exist for C2
- **AND** one `drafts` row SHALL exist for each of C1 and C2, with `ai_status`
  matching the parsed assessment and `ai_reasoning` matching `"r1"` and
  `"not-found: ..."` respectively
- **AND** exactly one `pass1_competence_results` row SHALL exist for
  `(run, competence)` with the parsed rollup, `confidence`, `probe_questions`,
  and `level_id = N1`
- **AND** `operator_status`, `operator_note`, and `finalized_at` SHALL all be
  null on that rollup row (R1)

#### Scenario: The digest is built once per run and reused
- **GIVEN** a `Run` whose brief has two technical competences
- **WHEN** `grade($run)` is called
- **THEN** the digest SHALL be constructed exactly once from
  `run.studentRepo.clone_path`
- **AND** the prompt for each competence SHALL cite the same digest instance
  (no per-competence re-read of the repo)

#### Scenario: No student identity reaches the prompt (R4)
- **GIVEN** a `Run` whose `studentRepo` has `name = "alice-project"` and
  `operator_persona = "advanced"`
- **WHEN** `grade($run)` is called with a `FakeGraderClient` that records prompts
- **THEN** none of the recorded `[system, user]` prompts SHALL contain
  `"alice-project"`, `"advanced"`, the `clone_path`, or a git author string
- **AND** the service SHALL read `clone_path` only to build the digest, and
  SHALL NOT pass it into the prompt

### Requirement: Idempotent re-grade replaces cleanly with no duplicates
The service SHALL make `Pass1GradingService::grade(Run)` idempotent: re-running
it on a run that already has Pass 1 results SHALL replace/update the existing
rows cleanly so that after the re-grade there is exactly one `drafts` row per
graded criterion, exactly one `evidence` row per surviving citation per graded
criterion, and exactly one `pass1_competence_results` row per graded competence —
never duplicates. The service SHALL NOT touch `operator_status`, `operator_note`,
or `finalized_at` on an existing `pass1_competence_results` row during a re-grade,
so an operator's prior finalization on a *different* competence is preserved and
a re-grade of an already-finalized competence leaves the operator's verdict
intact.

#### Scenario: Re-grade does not duplicate rows
- **GIVEN** a `Run` that has already been graded once, with one `evidence` row,
  one `drafts` row, and one `pass1_competence_results` row for competence A
- **WHEN** `grade($run)` is called again with a fresh well-formed response for A
- **THEN** after the re-grade there SHALL still be exactly one
  `pass1_competence_results` row for `(run, A)` (no duplicate)
- **AND** exactly one `drafts` row per graded criterion of A (no duplicates)
- **AND** exactly one `evidence` row per surviving citation per graded criterion
  of A (no duplicates; citations dropped by the re-grade are gone)

#### Scenario: Re-grade preserves an operator's prior finalization (R1)
- **GIVEN** a `Run` already graded, where competence A's
  `pass1_competence_results` row has `operator_status = 'valide'` and
  `finalized_at` set by the operator
- **WHEN** `grade($run)` is called again for that run
- **THEN** the re-grade SHALL overwrite `ai_rollup_status`, `confidence`,
  `probe_questions`, and `raw_json` on A's row
- **AND** `operator_status`, `operator_note`, and `finalized_at` SHALL remain
  `'valide'`, unchanged, and set respectively
- **AND** `$result->finalVerdict()` SHALL still return `'valide'`

### Requirement: Failure isolation — one competence's failure never aborts the run (R1)
The service SHALL isolate each competence's failure: if a competence's grader
output is unparseable (after E2a's one repair retry) or the grader call throws,
the service SHALL persist for that competence exactly one
`pass1_competence_results` row with `ai_rollup_status = 'à vérifier'`,
`confidence = null`, `probe_questions = []`, `raw_json` recording the failure
(`{unparseable: true, raw: <text>}` for unparseable, `{error: <class>: <message>}`
for a thrown exception), and `level_id = pivot.level_id`; it SHALL persist no
`evidence` and no `drafts` rows for that competence's criteria. All OTHER
competences in the run SHALL still grade normally. A single failure SHALL NEVER
abort the whole run and SHALL NEVER corrupt a partial write. The run's outcome
SHALL mark the failed competence's `Pass1CompetenceOutcome->status = 'failed'`
with a short reason. `finalVerdict()` on the failed competence's row SHALL
return null (the operator still finalizes — R1).

#### Scenario: Unparseable output yields a safe à vérifier row and others still grade
- **GIVEN** a `Run` whose brief has two technical competences A and B, and a
  `FakeGraderClient` queued with `"not json"` then `"still not json"` for A and a
  well-formed response for B
- **WHEN** `grade($run)` is called
- **THEN** a `pass1_competence_results` row for `(run, A)` SHALL exist with
  `ai_rollup_status = 'à vérifier'` and `raw_json` containing the unparseable raw
  text
- **AND** no `evidence` and no `drafts` rows SHALL exist for A's criteria
- **AND** competence B SHALL be graded normally (its evidence/drafts/rollup
  persisted)
- **AND** the returned outcome list SHALL have A with `status = 'failed'` and B
  with `status = 'graded'`
- **AND** `$aResult->finalVerdict()` SHALL return null

#### Scenario: Grader exception yields a safe à vérifier row and others still grade
- **GIVEN** a `Run` whose brief has two technical competences A and B, and a
  `FakeGraderClient` configured to throw a `RuntimeException` for A and return a
  well-formed response for B
- **WHEN** `grade($run)` is called
- **THEN** a `pass1_competence_results` row for `(run, A)` SHALL exist with
  `ai_rollup_status = 'à vérifier'` and `raw_json` recording the exception class
  and message
- **AND** competence B SHALL be graded normally
- **AND** the run SHALL not abort; the outcome list SHALL contain both A
  (`failed`) and B (`graded`)

### Requirement: Per-competence transaction integrity
The service SHALL persist each competence's rows inside their own DB transaction
(`DB::transaction`). It SHALL NOT wrap the whole run in a single transaction. A
mid-competence failure (exception during persistence) SHALL roll back that
competence's transaction leaving no partial rows for it; competences already
committed in earlier transactions SHALL remain intact. A crash that kills the
process mid-run SHALL leave committed competences fully written and the
in-flight competence with no rows (no half-written competence).

#### Scenario: A persistence exception rolls back only that competence
- **GIVEN** a `Run` whose brief has two technical competences A and B, where A
  grades and persists successfully, and a persistence step for B throws inside
  its transaction (e.g., a simulated DB error after A has committed)
- **WHEN** `grade($run)` is called
- **THEN** competence A's rows (evidence/drafts/rollup) SHALL remain persisted
- **AND** competence B SHALL have no `evidence`, no `drafts`, and no
  `pass1_competence_results` row (its transaction rolled back)
- **AND** competence B's outcome SHALL be `status = 'failed'` with the exception
  reason

#### Scenario: The whole run is not wrapped in one transaction
- **GIVEN** the `Pass1GradingService` implementation
- **WHEN** its source is inspected
- **THEN** there SHALL be one `DB::transaction` call per competence
- **AND** there SHALL NOT be a single `DB::transaction` wrapping the iteration
  over all competences

### Requirement: pass1:grade artisan command
The system SHALL provide `php artisan pass1:grade {run}` that resolves the
`Run`, sets `run.started_at` before grading and `run.ended_at` after, calls
`Pass1GradingService::grade($run)`, sets `run.status` to `pass1_done` when every
technical competence graded successfully or `pass1_partial` when any competence
failed, and prints a per-competence summary (one line per competence: label —
`graded` or `failed: <short reason>`) plus a final line `Run <id>:
pass1_done|pass1_partial (N graded, M failed)`. The command SHALL exit 0 on both
`pass1_done` and `pass1_partial` (so a cron/operator sees partial results), and
exit 1 only on hard precondition failure (run not found, no technical
competences on the brief, or digest build threw). The command SHALL perform no
UI work and SHALL NOT finalize anything (R1).

#### Scenario: Successful run prints a summary and sets pass1_done
- **GIVEN** a persisted `Run` whose brief has two technical competences and a
  fake grader queued with well-formed responses for both
- **WHEN** `php artisan pass1:grade <run_id>` is invoked
- **THEN** the command output SHALL list both competences as `graded`
- **THEN** the final line SHALL read `Run <id>: pass1_done (2 graded, 0 failed)`
- **AND** the run's `status` SHALL be `pass1_done` with `started_at` and
  `ended_at` set
- **AND** the exit code SHALL be 0

#### Scenario: Partial run sets pass1_partial and still exits 0
- **GIVEN** a persisted `Run` whose brief has two technical competences and a
  fake grader returning unparseable output for one and a well-formed response for
  the other
- **WHEN** `php artisan pass1:grade <run_id>` is invoked
- **THEN** the final line SHALL read `Run <id>: pass1_partial (1 graded, 1 failed)`
- **AND** the run's `status` SHALL be `pass1_partial`
- **AND** the exit code SHALL be 0

#### Scenario: Missing run exits 1
- **GIVEN** no `Run` with id 9999
- **WHEN** `php artisan pass1:grade 9999` is invoked
- **THEN** the command SHALL exit 1
- **AND** SHALL print a clear "Run not found" message

#### Scenario: Brief with no technical competences exits 1
- **GIVEN** a persisted `Run` whose brief links only `transversale` competences
- **WHEN** `php artisan pass1:grade <run_id>` is invoked
- **THEN** the command SHALL exit 1
- **AND** SHALL print a clear "no technical competences" message
- **AND** SHALL NOT call the grader
