## ADDED Requirements

### Requirement: Runs list screen
The operator panel SHALL present a list of all runs as the landing screen,
showing for each run the student repo, the brief, the run status, the graded
timestamp, and the finalization progress (count of finalized competences over
total technical competences). Each row SHALL link to that run's detail screen,
and the screen SHALL provide an action to start a new run.

#### Scenario: Runs are listed with status and progress
- **WHEN** the operator opens the panel landing screen and runs exist
- **THEN** each run appears as a row with its student repo, brief, status badge,
  graded-at, and an `N/M finalized` progress indicator
- **AND** each row links to that run's detail screen
- **AND** a "new run" action is visible

#### Scenario: Empty state
- **WHEN** the operator opens the landing screen and no runs exist
- **THEN** an empty-state message and the "new run" action are shown, with no
  error

### Requirement: Launch a run from the panel
The operator panel SHALL let the operator launch a run by selecting an existing
brief, supplying the repo source (local path or URL) and the operator's private
persona tag, then triggering the existing intake and grading path via a queued job. The
submit SHALL return immediately without blocking on the runner subprocess or the
LLM calls; the run SHALL appear immediately in a `processing` status that
resolves to its terminal status (`pass1_done` / `pass1_partial`) once the worker
completes the job.

#### Scenario: Operator launches a run
- **WHEN** the operator submits the new-run form with a valid brief, repo
  source, and persona
- **THEN** a run is created via the existing `RepoIntakeService` and Pass 1
  grading path
- **AND** the request returns without blocking on the runner subprocess or the
  LLM calls
- **AND** the new run is visible in the runs list in a `processing` status that
  later resolves to its terminal status when the worker finishes

#### Scenario: Invalid brief selection
- **WHEN** the operator submits the form referencing a brief that does not exist
- **THEN** the form shows a validation error and no run is created

### Requirement: Run detail shows evidence-backed Pass 1 results
The run detail screen SHALL display, per technical competence of the run's
brief: the hedged AI rollup status, the confidence value, and the probe
questions; and per criterion: the hedged AI draft status, the AI reasoning, and
each verified evidence citation. An evidence citation SHALL be rendered as the
verified `file:line` anchor; its excerpt SHALL be the actual source line read
read-only from the run's repository at that line (NOT the model's claim), and
the model's note SHALL be shown separately and clearly attributed as an AI note.
When the source is not available on disk (e.g. a URL intake whose clone was
removed), the citation SHALL still render as `file:line` with the AI note, and
the excerpt SHALL be omitted rather than fabricated. The runner's structural
report SHALL be shown in a collapsed summary. Transversal competences SHALL NOT
appear as gradable rows (R3 — only technical competences are Pass 1 graded).

#### Scenario: Competence and criterion detail render
- **WHEN** the operator opens a graded run's detail screen
- **THEN** each technical competence shows its hedged AI rollup, confidence, and
  probe questions
- **AND** each criterion shows its hedged AI draft, reasoning, and evidence
  citations rendered as a verified `file:line` anchor whose excerpt is the
  actual source line read from the run's repository, with the model's note shown
  separately as an attributed AI note
- **AND** the runner structural report is present as a collapsed summary

#### Scenario: Excerpt is the verified source, not the model's claim
- **WHEN** an evidence citation is rendered
- **THEN** the excerpt shown is the source line at the cited `file:line` read
  read-only from the run's repository
- **AND** the model's note is shown separately, labeled as an AI note
- **AND** if the source is unavailable on disk, the excerpt is omitted (never
  fabricated from the model's claim)

#### Scenario: Transversal competences are not graded rows
- **WHEN** the run's brief includes transversal competences
- **THEN** they do not appear as gradable competence cards on the detail screen

### Requirement: Operator finalizes per competence
The run detail screen SHALL provide, for each technical competence, a single
finalization control that records the operator's verdict (`valide` or
`non valide`) and an optional note, writing `operator_status`, `operator_note`,
and `finalized_at` on that competence's `pass1_competence_results` row. The
operator SHALL be able to reopen a finalized competence to change the verdict.
Finalization SHALL be an explicit operator action; the panel SHALL NOT
auto-finalize or pre-select a verdict from the AI rollup.

#### Scenario: Operator finalizes a competence
- **WHEN** the operator selects `valide` (or `non valide`), optionally adds a
  note, and confirms finalization for a competence
- **THEN** `operator_status`, `operator_note`, and `finalized_at` are persisted
  on that competence's `pass1_competence_results` row
- **AND** the competence renders as finalized with the operator's verdict

#### Scenario: Reopen a finalized competence
- **WHEN** the operator reopens a previously finalized competence
- **THEN** the verdict becomes editable again and a new verdict can be saved

#### Scenario: No verdict is pre-selected
- **WHEN** the operator opens a graded but not-yet-finalized competence
- **THEN** no verdict radio is pre-selected and `finalVerdict()` for that
  competence is null

### Requirement: AI advice is visually non-authoritative
The panel SHALL render AI-produced statuses (`semble valide`,
`semble non valide`, `à vérifier`) in a visually distinct, non-authoritative
style (outline/muted), always using the hedged wording, and SHALL render a
solid, filled verdict badge ONLY for the operator's finalized `operator_status`
and ONLY when `finalized_at` is set. Before finalization the verdict slot SHALL
be visibly empty. The panel SHALL NOT render any AI status as a solid verdict
badge and SHALL NOT soften a `semble non valide` into a neutral or positive
presentation.

#### Scenario: Hedged AI status is never a verdict badge
- **WHEN** a competence or criterion shows an AI status
- **THEN** it renders in the hedged, outline/muted style with the `semble…` /
  `à vérifier` wording
- **AND** it is not rendered as a solid filled verdict badge

#### Scenario: Solid verdict badge is operator-only
- **WHEN** a competence has `finalized_at` set
- **THEN** a solid filled badge shows the operator's `operator_status`
- **WHEN** a competence has no `finalized_at`
- **THEN** no solid verdict badge is shown for it

### Requirement: Persona stays operator-private
The panel SHALL display the operator's persona tag only within the
operator-only panel and SHALL NOT include it in any student-facing output or in
any data sent to the runner or to a Pass 1 prompt. (Upstream services already
exclude persona from intake and grading; the panel SHALL NOT reintroduce it.)

#### Scenario: Persona shown only in the operator panel
- **WHEN** the operator views a run's detail screen
- **THEN** the persona tag is visible as an operator-private field
- **AND** it does not appear in any student-facing export or in the data passed
  to intake/grading
