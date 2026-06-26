## MODIFIED Requirements

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
report SHALL be shown in a collapsed summary; a check with a `skip` status SHALL
be shown distinctly as neutral (not as a failure). Transversal competences SHALL
NOT appear as gradable rows (R3 — only technical competences are Pass 1 graded).

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

#### Scenario: Skipped runner check is not shown as a failure
- **WHEN** the runner structural report includes a check with a `skip` status
- **THEN** that check is rendered distinctly as neutral (not a red failure badge)

### Requirement: Operator finalizes per competence
The run detail screen SHALL provide, for each technical competence, a single
finalization control that records the operator's verdict (`valide` or
`non valide`) and an optional note, writing `operator_status`, `operator_note`,
and `finalized_at` on that competence's `pass1_competence_results` row. The
operator SHALL be able to reopen a finalized competence to change the verdict.
Finalization SHALL be an explicit operator action; the panel SHALL NOT
auto-finalize or pre-select a verdict from the AI rollup. Each competence's
finalization control SHALL have a stable identity so that finalizing one
competence never alters another competence's control, and finalizing or
reopening a competence SHALL update the screen's finalization summary
immediately without requiring a manual reload.

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

#### Scenario: Finalizing one competence reflects immediately and leaves others untouched
- **WHEN** the operator finalizes (or reopens) one competence on a run with
  multiple technical competences
- **THEN** the screen's finalization summary updates immediately without a manual
  reload
- **AND** no other competence's finalization control changes state
