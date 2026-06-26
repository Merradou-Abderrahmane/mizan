## Why

Three bugs in the `operator-panel` detail screen, found in review. None changes
intended behavior — each makes the code match what the spec already requires:

1. **Finalize children had no stable identity.** `show.blade.php` mounted the
   per-competence finalize component with `@livewire(..., key('cf-'.$result->id))`.
   `key()` is PHP's built-in array-pointer function, and Livewire 4's `@livewire`
   directive silently **drops** a third positional argument — so the intended
   `wire:key` was never set. With N finalize children in a re-rendering parent,
   Livewire has no stable per-child identity, risking state bleeding between
   competence cards. On the finalize screen, wrong-card verdict state is a real
   hazard.
2. **Finalizing did not refresh the page summary.** `CompetenceFinalize` dispatches
   `competence-finalized`, but `Show` had no listener, and the detail screen only
   polls while `pending`/`processing`. After finalizing on a terminal run, the
   page-level `N finalized` summary stayed stale until a manual reload.
3. **A skipped runner check rendered as a red failure.** The structural-report
   badges treated anything not `pass` as a failure, so a legitimate `skip`
   (e.g. a check skipped for a missing extension) showed as a red ✗.

## What Changes

- **FIX** the finalize child mount to use the proper key — the
  `<livewire:runs.competence-finalize :result-id :key="'cf-'.$result->id" />`
  tag form — so each child renders with a stable `wire:key="cf-<id>"`.
- **FIX** `Runs/Show` to listen for `competence-finalized` (`#[On(...)]`) and
  re-render, so the `N finalized` summary reflects a finalize/reopen immediately
  without a manual reload.
- **FIX** the runner structural-report badges to render a `skip` status as a
  neutral badge (○), distinct from a `fail` (red ✗).
- **No schema change, no runner change, no grading-logic change, no design
  change.** Bugs only.

## Capabilities

### New Capabilities
<!-- none -->

### Modified Capabilities
- `operator-panel`: the "Operator finalizes per competence" requirement now also
  guarantees that finalizing one competence updates the page summary immediately
  and never disturbs another competence's finalize control; the "Run detail shows
  evidence-backed Pass 1 results" requirement now states that a skipped runner
  check is shown distinctly, not as a failure.

## Impact

- **Code (`apps/web`)**: `resources/views/livewire/runs/show.blade.php` (finalize
  child key; skip-vs-fail badge logic); `app/Livewire/Runs/Show.php`
  (`#[On('competence-finalized')]` listener).
- **Tests**: `tests/Feature/OperatorPanel/OperatorPanelTest.php` — three new tests
  (distinct stable keys per finalize child; parent summary refreshes on the event;
  skipped check not rendered as a failure).
- **Hard rules**: **R1** is reinforced — stable child identity prevents one
  competence's verdict state from bleeding onto another, and the finalize action
  reflects without a stale reload; finalization still writes only operator columns
  (unchanged). **R3/R4/R5** unchanged. **R2** untouched.
- **Security / egress**: none. No runner/sandbox/egress boundary touched; the
  egress go-live gate is unchanged.
