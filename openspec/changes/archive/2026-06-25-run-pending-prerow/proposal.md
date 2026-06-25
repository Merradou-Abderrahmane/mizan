## Why

`operator-panel-ui` launches a run by dispatching a job that calls
`RepoIntakeService::intake()` (which creates the `Run` itself, after the ~20s
runner step) then grades it. So a freshly launched run is invisible until intake
finishes — the operator submits and sees nothing for ~20s, then the row appears
already `processing`. This small fix makes a launched run show **immediately** as
`pending`, improving the launch UX (a known follow-up flagged in the
operator-panel-ui PR).

## What Changes

- **ADD** `RepoIntakeService::intakeIntoRun(Run $run)`: run the structural runner
  against an ALREADY-persisted run's repo and fill in its `runner_report_json` /
  `started_at` / `ended_at`. It does NOT set `run.status` — the caller (the job)
  owns the `pending → processing → terminal` lifecycle. Extracts the shared
  clone+runner+cleanup into a `protected runRunnerOnSource()` reused by the
  existing `intake()`, whose behavior is unchanged.
- **MODIFY** the new-run flow: `Runs/Create` now creates the `StudentRepo`
  (+persona, R4) and the `Run` (status `pending`) **synchronously** in the
  request, then dispatches `IntakeAndGradeRun($run->id)` and redirects to the run
  detail — so the run is visible as `pending` at once. The job advances it
  `pending → processing → pass1_done / pass1_partial / error`.
- **No new dependency, no schema change, no runner/grading-logic change.**
  `intake()` (and the `repo:intake` command) keep working exactly as before.

## Capabilities

### New Capabilities
<!-- none -->

### Modified Capabilities
- `operator-panel`: the "Launch a run" requirement now guarantees the run appears
  **immediately** as `pending` (not only once intake registers it), then
  transitions to `processing` and its terminal status. The non-blocking-submit
  guarantee is unchanged.

## Impact

- **Code (`apps/web`)**: `app/Services/RepoIntakeService.php` (+`intakeIntoRun`,
  +`runRunnerOnSource`, +`crashReport`; `intake()` refactored to reuse them with
  identical behavior); `app/Jobs/IntakeAndGradeRun.php` (constructor now takes
  `int $runId`; uses `intakeIntoRun`); `app/Livewire/Runs/Create.php` (pre-creates
  the run, redirects to detail).
- **Hard rules**: **R4** — persona is set on `StudentRepo` in `Create` (the only
  place it lands) and is never passed to the job, runner, or a Pass 1 prompt
  (the job now receives only a run id). **R1/R3** unchanged. **R5** — the new
  method mirrors `intake()` via a shared helper; the component does two plain
  inserts. **R2** untouched.
- **Security / egress**: unchanged. Same trigger surface; the egress go-live gate
  still applies to the first live grade. Nothing auto-runs.
- **Tests**: `intakeIntoRun` populates an existing run without setting status
  (overridden-runner subclass, no subprocess); crash path persists the error
  report and rethrows; the job drives `pending → pass1_done` / `pass1_partial` /
  `error` (mocked services, no network/subprocess); `Create` creates a `pending`
  run + dispatches + redirects to detail (Bus::fake); unknown brief creates no run.
