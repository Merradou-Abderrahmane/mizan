## 1. Stable finalize-child identity

- [x] 1.1 Replace `@livewire('runs.competence-finalize', [...], key('cf-'.$result->id))` in `show.blade.php` with the tag form `<livewire:runs.competence-finalize :result-id="$result->id" :key="'cf-'.$result->id" />` (verified: the compiled view now emits `$__key = 'cf-'.$result->id` and the child renders `wire:key="cf-<id>"`).

## 2. Parent summary refresh

- [x] 2.1 Add `#[On('competence-finalized')] public function onCompetenceFinalized()` (empty body) to `Runs/Show` so the parent re-renders and the `N finalized` summary recomputes from `->fresh(...)` after a finalize/reopen — without a manual reload.

## 3. Skip-vs-fail rendering

- [x] 3.1 In the runner structural-report badges in `show.blade.php`, normalize the check status and render `skip`/`skipped` as a neutral `badge-ghost` (○), `pass`/`ok` as success (✓), everything else as a failure (red ✗).

## 4. Tests

- [x] 4.1 `test_each_finalize_control_has_a_stable_distinct_key` — a run with two technical competences renders two finalize children, each with a distinct `wire:key="cf-<id>"`.
- [x] 4.2 `test_finalizing_refreshes_the_parent_summary_without_reload` — Show shows `0 finalized`; after the result is finalized and `competence-finalized` is dispatched, Show shows `1 finalized` (no remount).
- [x] 4.3 `test_skipped_runner_check_is_not_rendered_as_a_failure` — a `skip` check renders with the neutral ○ glyph and no `badge-error`.
- [x] 4.4 `php artisan test tests/Feature/OperatorPanel/OperatorPanelTest.php` → 13/13 green (10 existing + 3 new). Pint clean on the diff. The 2 `RepoIntakeServiceTest` errors in a combined run are the documented real-runner timeout flake (green in isolation), unrelated to this change.
