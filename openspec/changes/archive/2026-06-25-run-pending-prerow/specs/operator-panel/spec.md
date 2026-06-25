## MODIFIED Requirements

### Requirement: Launch a run from the panel
The operator panel SHALL let the operator launch a run by selecting an existing
brief, supplying the repo source (local path or URL) and the operator's private
persona tag. On submit the panel SHALL create the run synchronously in a
`pending` status (with its `StudentRepo` carrying the persona) and dispatch a
queued job that runs the existing intake and grading path. The submit SHALL
return immediately without blocking on the runner subprocess or the LLM calls;
the run SHALL appear immediately in a `pending` status and transition to
`processing` and then its terminal status (`pass1_done` / `pass1_partial` /
`error`) as the worker completes the job.

#### Scenario: Operator launches a run
- **WHEN** the operator submits the new-run form with a valid brief, repo
  source, and persona
- **THEN** a `Run` is created immediately in `pending` status and the operator is
  taken to its detail screen
- **AND** the request returns without blocking on the runner subprocess or the
  LLM calls
- **AND** a queued job advances the run `pending → processing → pass1_done /
  pass1_partial / error`

#### Scenario: Invalid brief selection
- **WHEN** the operator submits the form referencing a brief that does not exist
- **THEN** the form shows a validation error and no run is created
