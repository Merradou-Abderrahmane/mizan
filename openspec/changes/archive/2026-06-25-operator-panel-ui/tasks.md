## 1. UI stack setup

- [x] 1.1 Install Livewire (`composer require livewire/livewire` → 4.3) and DaisyUI 5 (`npm i -D daisyui` → 5.5); add `@plugin "daisyui"` with the `corporate` theme to `resources/css/app.css`. Cleaned the stale `.env` `QUEUE_CONNECTION=sync` duplicate.
- [x] 1.2 Add `resources/views/layouts/app.blade.php` — DaisyUI navbar shell + `@vite`, comfortable density (Livewire auto-injects its assets); route the default `/` to the runs list (done in group 2).
- [x] 1.3 Add the reusable `x-status-badge` Blade component implementing the R1 grammar: AI status → `badge badge-outline` italic with `semble…`/`à vérifier` wording + `i` marker (green/red reserved for the operator only); operator verdict → solid `badge-success`/`badge-error`, rendered only when finalized. Rendering is decided by the `source` prop, never the text — no code path renders an AI status as a solid badge.

## 2. Runs list + create

- [x] 2.1 `app/Livewire/Runs/Index` + view + route `/`: table of runs (student repo, brief, `x-run-status` badge, graded-at, `N/M finalized`), row links to detail, "new run" action, empty state, `wire:poll.5s` live refresh.
- [x] 2.2 `app/Livewire/Runs/Create` + view + route: form (brief select, repo source path/URL, persona); on submit dispatch the intake+grade job and **return immediately** (redirect to the list with a flash). `exists:briefs,id` validation error when the brief does not exist; local-path-vs-URL guidance shown.
- [x] 2.3 `app/Jobs/IntakeAndGradeRun` (queued, timeout 600, tries 1): runs `RepoIntakeService::intake()` then `Pass1GradingService::grade()`, driving `runs.status` (created by intake → `processing` → `pass1_done`/`pass1_partial`/`error`) — no service/grading-logic edits.
- [x] 2.4 `QUEUE_CONNECTION=database` already set in `.env`/`.env.example` (stale `sync` dup removed); `jobs`/`failed_jobs` tables already exist (`0001_01_01_000002`). `processing` badge has a spinner; index + detail `wire:poll`. `queue:work` documented in the handoff entry (4.3).

## 3. Run detail / finalize (R1 surface)

- [x] 3.1 `app/Livewire/Runs/Show` + view + route: header (repo, brief, status, persona as operator-private badge), collapsed runner structural report, per technical competence a card with hedged AI rollup + confidence + probe questions. Cards are driven by `pass1_competence_results` (which the service writes for technical competences only) → transversal competences never appear.
- [x] 3.2 Per-criterion rendering: hedged AI draft (via `x-status-badge`), `ai_reasoning`, and each evidence citation as a verified `file:line` anchor whose **excerpt is the actual source line read read-only from `clone_path`** (`App\Support\SourceExcerpt`, D6), with the model's `message` shown separately labeled "AI note". Excerpt omitted when the source is unavailable.
- [x] 3.3 `app/Livewire/Runs/CompetenceFinalize` child component: verdict radio (no pre-selection), optional note, finalize → writes `operator_status`/`operator_note`/`finalized_at`; reopen → nulls them. Never touches AI columns (D3).

## 4. Tests + verification

- [x] 4.1 `StatusBadgeR1Test` (5 tests): AI statuses never render `badge-success`/`badge-error` (data provider over all three hedged values); operator slot empty until finalized; solid badge only when finalized; not-finalized competence has `finalVerdict()` null and no pre-selected radio.
- [x] 4.2 `OperatorPanelTest` (11 tests): index listing + `N/M` progress; show renders competence/criterion/hedged-AI/probe; transversal excluded; persona shown in panel; evidence excerpt is the source line read from disk + AI note attributed; excerpt omitted when source unavailable; finalize writes operator cols & leaves AI untouched; reopen clears; create dispatches the job + redirects (Bus::fake); unknown brief rejected (not dispatched).
- [x] 4.3 `php artisan test --exclude-group=slow` → 88 passed; the 2 `RepoIntakeServiceTest` real-runner timeouts are the documented environmental flake (5/5 green in isolation, 65s — no regression). `npm run build` succeeds (DaisyUI corporate compiled, 117KB css). Grep-verified `badge-success`/`badge-error` never on an AI path (only operator verdict / runner checks / run-status). `queue:work` documented in the handoff entry below.
