## 1. Service seam

- [x] 1.1 Refactor `RepoIntakeService::intake()` to use a new `protected runRunnerOnSource(string): array` (clone-if-URL + invoke runner + always clean up the temp clone) and a private `crashReport(RunnerCrashException): array`. Persistence/transaction logic unchanged (verified: `RepoIntakeServiceTest` 5/5 green in isolation).
- [x] 1.2 Add `RepoIntakeService::intakeIntoRun(Run $run): Run`: run `runRunnerOnSource($run->studentRepo->clone_path)`; on success update `runner_report_json`/`started_at`/`ended_at` (NOT status); on `RunnerCrashException` update the error report + `ended_at` and rethrow.

## 2. Job + create flow

- [x] 2.1 `IntakeAndGradeRun`: constructor takes `int $runId`. `handle()` loads the run, sets `processing`, calls `intakeIntoRun` (catch `RunnerCrashException` → `error`, return), then `grade()` (catch `Throwable` → `error`, rethrow), then terminal `pass1_done`/`pass1_partial`.
- [x] 2.2 `Runs/Create::submit()` creates the `StudentRepo` (+persona) and `Run` (`pending`) synchronously, dispatches `IntakeAndGradeRun($run->id)`, and redirects to `runs.show`. Persona is no longer passed to the job.

## 3. Tests

- [x] 3.1 `IntakeIntoRunTest` (2): populates an existing run's report without setting status (substituted-runner subclass — no subprocess); crash path persists the error report and rethrows.
- [x] 3.2 `IntakeAndGradeRunJobTest` (4): the job drives `pending → pass1_done`, `pass1_partial`, and `error` (runner crash skips grading; grading throw rethrows) — mocked services, no network/subprocess.
- [x] 3.3 `OperatorPanelTest` create tests updated: `Create` makes a `pending` run + `StudentRepo` with persona, dispatches, redirects to detail (Bus::fake); unknown brief creates no run and dispatches nothing.
- [x] 3.4 `php artisan test --exclude-group=slow` → **96 passed** (389 assertions); `RepoIntakeServiceTest` 5/5 green in isolation (no regression from the `intake()` refactor). Pint clean.
