## Context

`operator-panel-ui` deliberately kept `RepoIntakeService` untouched, so the
launch job calls `intake()` (which creates the `Run` after the runner step) then
`grade()`. The cost is UX: a launched run is invisible for ~20s. This change adds
the minimal seam to pre-create the run and have the service populate it.

## Goals / Non-Goals

**Goals:** a launched run is visible as `pending` immediately; the job advances it
`pending → processing → terminal`; `intake()` and `repo:intake` behavior unchanged.

**Non-Goals:** no schema change, no runner/grading change, no real-time push
(the detail page already polls), no "reuse existing StudentRepo" UI.

## Decisions

### D1 — Add `intakeIntoRun(Run)`, share `runRunnerOnSource()` with `intake()`
`intake()` is refactored to call a new `protected runRunnerOnSource(string)`
(clone-if-URL + invoke runner + always clean up the temp clone) and a private
`crashReport()`; its persistence/transaction logic is byte-for-byte the same, so
the existing `RepoIntakeServiceTest` is the regression guard. `intakeIntoRun()`
reuses `runRunnerOnSource()` to fill an existing run's report.
**Why:** one runner-invocation path (DRY, R5), zero behavior change to the legacy
path. **Alternative considered:** thread an optional `?Run` through `intake()` —
rejected; it tangles two responsibilities (create vs. populate) in one method.

### D2 — `intakeIntoRun()` does NOT set `run.status`; the job owns the lifecycle
On success it writes `runner_report_json` / `started_at` / `ended_at` only; on a
`RunnerCrashException` it writes the error report + `ended_at` and rethrows. The
job sets `processing` before calling it, `error` if it throws, and the terminal
status after grading.
**Why:** a single owner of the `pending → processing → terminal` sequence keeps
the displayed lifecycle clean and avoids a transient runner-status flicker.

### D3 — `Create` pre-creates `StudentRepo` + `Run` synchronously
Two plain inserts in the request (fast), then dispatch `IntakeAndGradeRun(runId)`
and redirect to the detail screen. Persona lands on `StudentRepo` here — the only
place it is written — and is no longer passed to the job at all (the job takes a
run id), tightening R4.

## Risks / Trade-offs

- **[Run pre-created but the job never runs (no worker)]** → the run sits at
  `pending` instead of `processing`; same recoverable "stuck" state already
  documented for the queue worker, now just visible one stage earlier. No data
  corruption.
- **[`intake()` refactor could change legacy behavior]** → mitigated by keeping
  the DB/transaction code identical and running the existing
  `RepoIntakeServiceTest` (real runner) in isolation as the guard.

## Sandbox / security impact

None. No runner/sandbox/egress boundary touched; the panel's trigger surface and
the egress go-live gate are unchanged.
