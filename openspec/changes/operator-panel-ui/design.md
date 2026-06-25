## Context

`apps/web` is a bare Laravel 13 install: Tailwind v4 is wired via Vite, but
there is **no Livewire and no DaisyUI**, and the only view is `welcome.blade.php`.
Pass 1 is complete at the service/command layer (`RepoIntakeService`,
`Pass1GradingService`, `php artisan repo:intake`, `php artisan pass1:grade`) and
the E1 schema already models the single finalization point
(`pass1_competence_results.operator_status` / `operator_note` / `finalized_at`,
guarded by `finalVerdict()`). What is missing is any human surface to read the
evidence-backed draft and finalize — the product's core act (R1).

The operator made the aesthetic decisions this change needs: DaisyUI theme
`corporate` (light), comfortable density, and a hedged-vs-final visual grammar
where AI advice is an outline/italic `semble…` badge and the operator's verdict
is a solid filled badge. Those are fixed inputs to this design.

## Goals / Non-Goals

**Goals:**
- Stand up the Livewire 3 + DaisyUI 5 (corporate theme) UI stack on `apps/web`.
- Three screens: runs list, new-run, and the run-detail/finalize surface.
- Make the run-detail screen a faithful R1 enforcement surface: AI output is
  always hedged and visually non-authoritative; the solid verdict badge is
  operator-only and only after explicit finalization.
- Trigger the existing intake + grading path without blocking the web request.

**Non-Goals:**
- No change to grading logic, prompts, parser, runner, or schema. The panel is a
  consumer/trigger only.
- No authentication (single-operator app per the architecture note).
- No Pass 2, no probe-flag UI, no student-facing export view (later changes).
- No real-time push/websockets; status refresh is poll/refresh-on-load for v0.
- No editing of AI drafts in place — the operator finalizes per competence; the
  AI text is read-only advice.

## Decisions

### D1 — Livewire 3 + DaisyUI 5 (corporate), no hand-rolled CSS
Install `livewire/livewire` (Composer) and `daisyui` (npm) as a Tailwind v4
`@plugin "daisyui"` in `resources/css/app.css` with the `corporate` theme; add a
single `layouts/app.blade.php` that pulls Vite + a DaisyUI navbar shell.
**Why:** the UI rule mandates DaisyUI as the design system and forbids
hand-rolling one. Livewire keeps it server-rendered PHP (R5, boring) with no SPA
build. **Alternative considered:** Blade + Alpine only — rejected; finalize
interactions (radio + note + save + reopen, per-competence) are stateful enough
that Livewire components are simpler than hand-wired Alpine + fetch.

### D2 — Long-running work runs as a queued job on the `database` driver (async)
The new-run action dispatches one job that runs `RepoIntakeService::intake()`
then `Pass1GradingService::grade()`; the run's `status` column carries progress
(`pending` → `processing` → `pass1_done` / `pass1_partial`). The submit returns
immediately and the run appears in a "processing" state; the operator sees the
terminal status on the next load/refresh once the worker finishes.
**Why:** intake runs the runner subprocess (~15–20s) and grading makes ~5 live
LLM calls — a multi-minute operation, far past any safe synchronous web-request
budget; blocking the browser on submit would time out.
**Decision:** run the **`database` queue driver** (`QUEUE_CONNECTION=database`)
with a `php artisan queue:work` worker — NOT `sync`. The job boundary already
exists, so this is an `.env` flip + the Laravel `jobs`/`failed_jobs` tables +
running a worker — **zero application-code change**. The UI is built assuming
async (immediate return, "processing" state). **Alternative considered:** stay on
`sync` and accept a blocking submit — rejected; a real run is minutes and would
exceed PHP's `max_execution_time` / browser timeout. **Alternative considered:**
call the services directly in the Livewire action — rejected; welds a
multi-minute operation to the HTTP request.

### D3 — Finalization writes only operator columns, through `finalVerdict()`
The per-competence finalize control updates exactly `operator_status`,
`operator_note`, `finalized_at` on the existing `pass1_competence_results` row;
it never touches `ai_rollup_status` or any AI column, and never writes a verdict
that the AI produced. "Reopen" nulls `finalized_at` (and clears
`operator_status`) so `finalVerdict()` returns null again until re-saved.
**Why:** R1 — the operator finalizes, always; the AI never becomes a verdict.
This reuses the schema's existing single finalization point rather than adding a
parallel one.

### D4 — The R1 visual grammar is a reusable Blade partial, not ad-hoc markup
A single status-badge partial decides rendering from the *source* of the status,
not its text: AI statuses → outline/muted/italic DaisyUI badge
(`badge badge-outline`) with the literal `semble…` / `à vérifier` wording and a
small `ⁱ` marker; operator verdict → solid DaisyUI badge
(`badge badge-success` for `valide`, `badge badge-error` for `non valide`) shown
only when `finalized_at` is set. There is no code path that can render an AI
status as a solid verdict badge.
**Why:** centralizing the mapping makes the R1 guarantee testable and prevents a
future careless `badge-success` on AI output. **Alternative considered:**
per-view inline ternaries — rejected; too easy to drift and violate R1 by
accident.

### D6 — Evidence excerpt is the verified source line, read at render time
What Pass 1 persists per evidence row is the **verified citation coordinates**
(`file_path`, `line_number` — the parser drops any item that fails
`RepoDigest::has()`) plus the model's `note` in `message`. The `excerpt` column
is left null and no source text is stored. So the detail screen reconstructs the
excerpt by reading the **actual cited line** from the run's repository
(`run.studentRepo.clone_path`) read-only, and renders the model's `note`
separately, labeled as an AI note. If the path is unavailable (URL intake whose
clone was removed — those runs grade to `à vérifier` with no evidence anyway),
the excerpt is omitted, never fabricated.
**Why:** R3 — the displayed evidence must be verifiable source, not the model's
unverified claim. The citation coordinates are already verified upstream; reading
the real line at display time is the truthful, in-scope way to show it without
touching the grading path.
**Alternative considered:** show `message` (the model's note) as the excerpt —
rejected; that presents the model's claim as verified evidence, the exact thing
R3 forbids. **Alternative considered:** backfill `evidence.excerpt` at grade time
— rejected for this change; it modifies the grading service (out of scope) and
needs a re-grade to populate existing rows. Can be done later as a grading-path
optimization; the render-time read keeps this change UI-only.

### D5 — Persona display is read-only and panel-scoped
Persona is shown on the run-detail header as an operator-private field (it is
already stored on `StudentRepo`, `$hidden`). The panel never serializes it into
any student-facing view (none exist yet) and never passes it to intake/grading
(those services already exclude it). The new-run form collects persona and hands
it to `RepoIntakeService` exactly as the `repo:intake` command does.
**Why:** R4 — persona is the operator's private tag.

## Risks / Trade-offs

- **[Worker not running → run sticks in "processing"]** → with the `database`
  driver the job only advances when `php artisan queue:work` is up. Mitigation:
  document the worker as a required local process; surface `failed_jobs` (a job
  that throws lands there and the run stays `processing`), and let the operator
  re-launch. A stuck "processing" is recoverable and never corrupts data (each
  competence persists in its own transaction, per E2b).
- **[UI-triggered `pass1:grade` is a real egress]** → the existing zero-retention
  go-live gate still applies; the first live `glm-5.2` call from the panel is the
  operator's explicit, gated action. Nothing auto-runs on page load.
- **[A careless `badge-success` on AI output would break R1 silently]** → D4
  centralizes badge rendering in one partial with a feature test asserting AI
  statuses never render the solid verdict class and the solid badge only appears
  when `finalized_at` is set.
- **[URL repo source can't be re-graded]** (known from the smoke harness: a URL
  intake deletes the temp clone, so Pass 1 grades to `à vérifier`) → the new-run
  form notes that a run intended for grading needs a local path; this is display
  guidance, not a logic change.
- **[Long runner/LLM work with no live progress]** → v0 uses status-on-load +
  manual refresh; real-time progress is a deliberate non-goal deferred until a
  real queue/worker is in place.

## Migration Plan

1. Install Livewire (Composer) + DaisyUI (npm); add the `@plugin "daisyui"` +
   `corporate` theme to `app.css`; add `layouts/app.blade.php`.
2. Build the three Livewire components + views + routes and the badge partial.
3. Add the intake+grade job; set `QUEUE_CONNECTION=database`, add the Laravel
   `jobs`/`failed_jobs` queue tables, and document running `php artisan
   queue:work`. The new-run submit returns immediately; the run shows
   "processing".
4. Feature-test the R1 grammar, finalize/reopen, transversal exclusion, and
   persona scoping. No DB migration; rollback is removing the routes/components
   and the two dependencies — no schema or data impact.

## Open Questions

- None blocking. (Queue driver settled: `database` + a `queue:work` worker, async
  — see D2.)
