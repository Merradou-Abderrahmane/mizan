## Why

Pass 1 is feature-complete end-to-end at the service/command layer
(`repo:intake` → runner → `pass1:grade`), but the operator can only reach it
through `php artisan` and `tinker`. There is no surface to **read the
evidence-backed draft and finalize a verdict** — and finalization is the whole
point of the product (R1: the operator finalizes, always). This change builds
the operator control panel: list runs, launch a run, and — the load-bearing
screen — review each competence's evidence + hedged AI draft and record the
final `valide` / `non valide`. The web app is currently a bare Laravel install
(no Livewire, no DaisyUI yet), so this also stands up the UI stack the rest of
the product will live on.

## What Changes

- **ADD** the UI stack to `apps/web`: install **Livewire 3** and **DaisyUI 5**
  (as a Tailwind v4 `@plugin`, theme `corporate`), plus a base app layout. No
  hand-rolled design system (UI rule) — DaisyUI *is* the system.
- **ADD** a **Runs list** screen (`/`): a table of runs (student repo, brief,
  status badge, graded-at, finalization progress `N/M finalized`) with a link to
  each run and an action to start a new one.
- **ADD** a **New run** screen: a form to launch a run for an existing/seeded
  `Brief` — choose the brief, give the repo source (local path or URL) and the
  operator's private persona tag (R4), then trigger `repo:intake` and, when the
  intake succeeds, `pass1:grade`. The work runs as a queued job on the
  `database` driver (a `queue:work` worker) so the submit returns immediately and
  the run shows `processing` until grading completes (design.md D2).
- **ADD** the **Run detail / finalize** screen — the **R1 finalization
  surface**. Per technical competence it shows: the hedged AI rollup
  (`semble valide` / `semble non valide` / `à vérifier`) + confidence + probe
  questions, and per criterion the AI draft (hedged) + `ai_reasoning` + verified
  `evidence` citations (`file:line` + excerpt). It collapses the runner's
  structural report. Each competence has one finalization control writing
  `operator_status` + `operator_note` + `finalized_at` on
  `pass1_competence_results` — the existing single finalization point.
- **ADD** a strict visual grammar separating advisory from authoritative: AI
  hedged statuses render as **outline / muted / italic `semble…` badges** that
  can NEVER look like a verdict; the operator's final verdict renders only as a
  **solid filled badge** and only once `finalized_at` is set (`finalVerdict()`).
  Until the operator acts, the verdict slot is visibly empty. No auto-accept; a
  real `non valide` is never softened (UI rule).
- **No schema change, no runner change, no LLM/grading-logic change.** The panel
  reads the existing E1 schema and triggers the existing `RepoIntakeService` /
  `pass1:grade` — it does not re-implement or weaken any grading rule.

## Capabilities

### New Capabilities
- `operator-panel`: the web control panel — runs list, run creation/triggering,
  and the run-detail finalization surface, including the R1 visual grammar
  (hedged-AI vs solid-operator-verdict) and the persona-stays-operator-private
  (R4) display rule.

### Modified Capabilities
<!-- none — the panel consumes the existing domain-model / pass1-grading /
     repo-intake capabilities without changing their requirements. -->

## Impact

- **New dependencies (`apps/web`)**: `livewire/livewire` (Composer);
  `daisyui` (npm, Tailwind v4 plugin). `resources/css/app.css` gains the DaisyUI
  plugin + `corporate` theme; a `resources/views/layouts/app.blade.php` base
  layout.
- **New code (`apps/web`)**: Livewire components under `app/Livewire/` —
  `Runs/Index`, `Runs/Create`, `Runs/Show` (+ a `CompetenceFinalize` child for
  the per-competence finalize control); their Blade views; routes in
  `routes/web.php`; one queued job wrapping intake+grade so the web request does
  not block on the runner subprocess / live LLM calls.
- **Reuses (no edit)**: `RepoIntakeService`, the `pass1:grade` orchestration
  (`Pass1GradingService`), and the E1 models (`Run`, `Brief`, `Competence`,
  `Criterion`, `Evidence`, `Draft`, `Pass1CompetenceResult`) with
  `finalVerdict()`.
- **Writes**: `pass1_competence_results.operator_status` / `operator_note` /
  `finalized_at` (the finalize action); `runs` rows + status (via the existing
  intake/grade path). **Reads**: runs, briefs, competences, criteria, evidence,
  drafts, rollups, `runner_report_json`.
- **Hard rules**: **R1** — this screen *is* the enforcement surface: AI output
  is always hedged and visually non-authoritative; the solid verdict badge only
  ever shows the operator's finalized `operator_status`; default is the empty
  "not finalized" state; no auto-accept; `non valide` never softened. **R4** —
  persona is shown only inside this operator-only panel, never in any
  student-facing output, and is never sent into intake's runner call or a Pass 1
  prompt (already enforced upstream). **R3** — the panel only displays Pass 1
  results and finalizes; it never re-grades or merges passes. **R5** — thin
  Livewire components over existing services; DaisyUI components, no clever
  logic. **R2** untouched.
- **Security / egress**: the panel adds no new runner/sandbox boundary, but it
  becomes a *trigger* for two existing privileged operations — the runner
  subprocess (`repo:intake`) and the live LLM call (`pass1:grade`). The existing
  **egress gate stands**: a UI-triggered `pass1:grade` is still a real
  `glm-5.2` call and still requires the operator's zero-retention sign-off
  before first live use. Triggering is gated behind the operator's explicit
  action; nothing auto-runs.
