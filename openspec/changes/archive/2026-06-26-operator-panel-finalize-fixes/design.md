## Context

`operator-panel-ui` (Step 9) shipped the detail/finalize screen. Review found
three defects where the implementation did not match the spec's intent. This
change is bugs-only — no new behavior, no schema/runner/grading/design change.

## Goals / Non-Goals

**Goals:** each finalize child has a stable Livewire identity; finalizing a
competence reflects in the page summary without a manual reload; a skipped runner
check is visually distinct from a failure.

**Non-Goals:** no design/aesthetic change, no polling-cadence redesign, no schema
or grading-logic change, no change to what finalization persists.

## Decisions

### D1 — Use the `<livewire:>` tag with `:key`, not `@livewire(..., key(...))`
The previous `@livewire('runs.competence-finalize', [...], key('cf-'.$id))` was
doubly wrong: `key()` is PHP's built-in (it throws on a string in isolation), and
Livewire 4's `@livewire` directive drops a third positional argument at compile
time (verified: the compiled view omitted it). The tag form
`<livewire:runs.competence-finalize :result-id="$result->id" :key="'cf-'.$result->id" />`
compiles to a real `mount(..., $__key)` and renders `wire:key="cf-<id>"` on the
child root.
**Why:** stable per-child identity is what keeps Livewire from morphing one
finalize control's state onto another when the parent re-renders (poll or event).
**Alternative considered:** the `key:` named argument on `@livewire` — works, but
the tag form is the documented idiom and reads clearest.

### D2 — `Show` listens for `competence-finalized` and re-renders
`CompetenceFinalize` already dispatches `competence-finalized` on finalize/reopen.
Adding `#[On('competence-finalized')]` to `Show` (empty body) makes the parent
re-render; `render()` already rebuilds the summary from `->fresh(...)`, so the
`N finalized` count updates immediately.
**Why:** the detail screen does not poll once the run is terminal, so without a
listener the summary only updated on a manual reload. An event listener is cheaper
and more precise than forcing the page to poll forever.
**Alternative considered:** always poll the detail screen — rejected; needless
network churn on a finished run when a single event suffices.

### D3 — `skip` is a neutral badge, not a failure
The badge logic now normalizes the check status and treats `skip`/`skipped` as a
neutral `badge-ghost` (○), `pass`/`ok` as success (✓), everything else as a
failure (red ✗).
**Why:** a skip (e.g. a check skipped for a missing PHP extension) is not a
failed check; rendering it red misleads the operator.

## Risks / Trade-offs

- **[Event listener fires on every `competence-finalized`]** → a full re-render of
  the detail screen. Acceptable: it is one re-render per explicit operator action,
  not a loop.
- **[Tag-syntax param name]** → the child property is `$resultId`; the kebab
  attribute `:result-id` maps to it via Livewire's kebab→camel convention
  (verified: the existing finalize tests still pass).

## Sandbox / security impact

None. No runner/sandbox/egress boundary touched. The panel's trigger surface and
the egress go-live gate are unchanged.
