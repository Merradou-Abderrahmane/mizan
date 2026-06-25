# pass1-grading Specification

## Purpose

`pass1-grading` is the blind, evidence-first LLM Pass 1: it turns a Run + Brief
into per-criterion evidence-backed hedged drafts and a per-competence rollup, at the
criterion/competence grain the domain model defines, and never a final verdict (the
operator finalizes). This capability is built in two changes. **E2a (done)** ships
the primitives specified below — the `RepoDigest` (a bounded, identity-free source
bundle with citation verification), the blind `Pass1Prompt`, the grader output JSON
contract, the `Pass1ResponseParser` (which coerces non-hedged statuses to
`à vérifier`, drops phantom citations, defaults no-evidence criteria to `à vérifier`,
captures per-criterion reasoning, and flags unparseable output), and the
`GraderClient` interface with its opencode/zen and fake implementations. **E2b**
adds the orchestration: `Pass1GradingService::grade(Run)` (technical competences
only, at their brief target level, one call per competence, persisted idempotently)
and the `pass1:grade` command.

The hard rules are enforced in the primitives: R1 (model emits only `semble…`/
`à vérifier`; no-evidence → `à vérifier`; the operator finalizes), R3 (blind,
evidence-first; every citation verified against the digest), and R4 (no student
identity or persona in the digest or prompt). **Security:** E2a makes no live call;
the real egress (bounded student code → opencode/zen) occurs only in E2b, gated on
verifying the configured `GRADER_MODEL` is on a zero-retention path.

## Requirements
### Requirement: Bounded, identity-free source digest with citation verification
The system SHALL provide a `RepoDigest` built from a repo path that produces a
deterministic text bundle of the repository's source: a file tree followed by each
included file's contents with 1-based line numbers that callers cite against. The
digest SHALL exclude `vendor/`, `node_modules/`, `.git/`, build output, and
binary/oversized files, and SHALL include files by an extension allowlist. The digest
SHALL be byte-capped; when the cap is exceeded it SHALL drop lower-priority files and
expose a `truncated` flag. The digest SHALL carry no student identity. It SHALL expose
`has(file, line)` answering whether a citation resolves (path present and line within
range).

#### Scenario: Digest excludes dependencies and VCS metadata
- **GIVEN** a repo path containing `vendor/autoload.php`, `.git/config`, and
  `app/Models/User.php`
- **WHEN** the digest is built
- **THEN** it SHALL include `app/Models/User.php`
- **AND** it SHALL NOT include any `vendor/` or `.git/` path

#### Scenario: Citation verification
- **GIVEN** a digest of a repo where `app/Models/User.php` has 20 lines
- **WHEN** `has("app/Models/User.php", 12)` and `has("app/Models/User.php", 99)` and
  `has("nope.php", 1)` are called
- **THEN** they SHALL return true, false, and false respectively

#### Scenario: Truncation is flagged
- **GIVEN** a repo whose allowlisted source exceeds the byte cap
- **WHEN** the digest is built
- **THEN** `truncated` SHALL be true
- **AND** the bundle SHALL still be within the cap

### Requirement: Blind, evidence-first prompt (R3, R4)
The system SHALL provide `Pass1Prompt` that builds a `[system, user]` prompt from a
brief, a competence and its assessed level, that level's criteria, and a `RepoDigest`.
The prompt SHALL instruct: evidence-first with `{file, line, note}` citations that must
exist in the provided code; hedged assessments only (`semble valide` / `semble non
valide` / `à vérifier`), never `valide`/`non valide`; JSON-only output; that the code
is an excerpt that may be truncated, so apparent absence MUST yield `à vérifier` and
never `semble non valide`; and that every criterion include a short `reasoning` which,
for `à vérifier`, distinguishes "present but insufficient" from "not found / unclear /
truncated".

The rendered prompt SHALL contain no student identity: not `StudentRepo.name`, not
`operator_persona`, not `clone_path`, and not git author metadata.

#### Scenario: Prompt carries no identity (R4)
- **GIVEN** a `StudentRepo` with `name = "alice-project"` and `operator_persona =
  "advanced"`, and a brief/competence/criteria/digest
- **WHEN** the prompt is rendered
- **THEN** neither the system nor user prompt SHALL contain `"alice-project"`,
  `"advanced"`, the `clone_path`, or a git author string

#### Scenario: Prompt lists the criteria to assess and forbids final verdicts
- **GIVEN** a competence with two criteria at the assessed level
- **WHEN** the prompt is rendered
- **THEN** the user prompt SHALL reference both criteria by id
- **AND** the system prompt SHALL forbid `valide`/`non valide` and require hedged values

#### Scenario: Truncated digest steers to à vérifier, not semble non valide
- **GIVEN** a digest with `truncated = true`
- **WHEN** the prompt is rendered
- **THEN** the prompt SHALL instruct that apparent absence yields `à vérifier`, never
  `semble non valide`

### Requirement: Grader output contract (Pass 1 JSON)
The grader response SHALL be a single JSON object of this exact shape:

```json
{
  "competence_id": "string",
  "level": "1 | 2 | 3",
  "criteria": [
    { "criterion_id": "string",
      "evidence": [{ "file": "string", "line": 0, "note": "string" }],
      "assessment_draft": "à vérifier | semble valide | semble non valide",
      "reasoning": "string" }
  ],
  "competence_draft_rollup": "à vérifier | semble valide | semble non valide",
  "confidence": 0.0,
  "probe_questions": ["string"]
}
```

`assessment_draft` and `competence_draft_rollup` SHALL be one of `à vérifier`,
`semble valide`, `semble non valide`. Each criterion SHALL carry a `reasoning` string.

#### Scenario: Well-formed response is accepted
- **GIVEN** a JSON object with two criteria (one `semble valide` with evidence and a
  reasoning, one `à vérifier` with empty evidence and a reasoning), a rollup, a
  confidence, and probe questions
- **WHEN** it is parsed
- **THEN** parsing SHALL succeed and expose both criteria with their reasoning, the
  rollup, the confidence, and the probe questions

### Requirement: Response parser validates hard and defaults safe (R1, R3)
The system SHALL provide `Pass1ResponseParser` that parses a grader response against
the contract and returns a structured result. It SHALL coerce any `assessment_draft`
or `competence_draft_rollup` outside the hedged set (including bare `valide`/`non
valide`) to `à vérifier`. It SHALL drop any evidence item that does not resolve via
`RepoDigest::has(file, line)`, and SHALL set a criterion's assessment to `à vérifier`
when no evidence survives. It SHALL ignore unknown `criterion_id`s and default any
expected-but-omitted criterion to `à vérifier` with no evidence. It SHALL capture each
criterion's `reasoning`. On JSON that cannot be parsed to the contract, it SHALL
perform one repair retry and, if still failing, return a result flagged `unparseable`
that carries the raw response text.

#### Scenario: Bare verdict is coerced to à vérifier (R1)
- **GIVEN** a response whose `competence_draft_rollup` is `"valide"`
- **WHEN** it is parsed
- **THEN** the result's rollup SHALL be `à vérifier`

#### Scenario: Phantom citation is dropped and criterion falls to à vérifier
- **GIVEN** a criterion marked `semble valide` with one evidence item citing
  `does/not/exist.php:10` against a digest lacking that file
- **WHEN** it is parsed
- **THEN** that evidence item SHALL be dropped
- **AND** the criterion's assessment SHALL be `à vérifier`

#### Scenario: Reasoning is captured per criterion (operator edit)
- **GIVEN** a criterion with `assessment_draft = "à vérifier"` and `reasoning =
  "not-found: no routes file in the excerpt"`
- **WHEN** it is parsed
- **THEN** the parsed criterion's reasoning SHALL equal that string

#### Scenario: Unparseable response is flagged after one repair retry
- **GIVEN** a grader that returns `"not json"` then again non-JSON on the repair retry
- **WHEN** the parser runs
- **THEN** the result SHALL be flagged `unparseable`
- **AND** SHALL carry the raw response text

### Requirement: Grader client is an interface with a real and a fake implementation
The system SHALL define a `GraderClient` interface `complete(system, user): string`.
`ZenGraderClient` SHALL POST to the configured opencode/zen endpoint using
`GRADER_API_KEY`, `GRADER_MODEL` as the primary model and `GRADER_FALLBACK_MODEL` on a
5xx or timeout, with a low temperature, JSON-response mode, a request timeout, and one
retry. `FakeGraderClient` SHALL return queued canned responses and record the prompts
it received. Consumers SHALL depend on the interface; the testing container SHALL bind
the fake.

#### Scenario: Zen client shapes the request and falls back on error
- **GIVEN** `Http::fake` configured so the primary model returns 503 and the fallback
  returns a valid completion
- **WHEN** `ZenGraderClient::complete($system, $user)` is called
- **THEN** the request SHALL carry the API key, low temperature, and JSON output mode
- **AND** the client SHALL retry with `GRADER_FALLBACK_MODEL` and return its completion

#### Scenario: Fake client returns canned output and records prompts
- **GIVEN** a `FakeGraderClient` queued with one response
- **WHEN** `complete($system, $user)` is called
- **THEN** it SHALL return the queued response
- **AND** the recorded prompts SHALL include `$system` and `$user`

