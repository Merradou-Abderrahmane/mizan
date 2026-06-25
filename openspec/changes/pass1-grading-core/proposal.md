## Why

Pass 1 (the blind LLM grading) is split into E2a (these primitives) and E2b (the
orchestration). This change builds and unit-tests the four primitives Pass 1 needs —
the grader client, the code digest, the prompt, and the response parser — so the
prompt text and the parsing/validation rules can be reviewed and locked in isolation,
before any service wires them to a Run. **No real network call happens in this change:**
tests use a fake grader; the HTTP client is built but only exercised for request
shaping.

## What Changes

- **ADD** `config/grader.php` reading the `GRADER_*` env (base URL, key, `glm-5.2`
  primary, `qwen3.6-plus` fallback, temperature, request timeout, `digest_max_bytes`).
- **ADD** a `GraderClient` interface + `ZenGraderClient` (opencode/zen HTTP impl:
  primary model, fallback on 5xx/timeout, low temperature, JSON output, one retry) +
  `FakeGraderClient` (queued canned responses, records prompts) for tests.
- **ADD** `RepoDigest`: a deterministic, bounded text bundle of the cloned repo —
  file tree + contents, excluding `vendor/`, `node_modules/`, `.git/`, build/binary
  files, by an extension allowlist and a byte cap (with a `truncated` flag). Exposes
  `has(file, line)` for citation verification. Carries no identity.
- **ADD** `Pass1Prompt`: builds the blind, evidence-first, JSON-only `[system, user]`
  prompt from a brief, a competence's `(level)` criteria, and the digest. Contains no
  student identity (R4). The exact, operator-reviewed prompt text — including the
  truncation/absence rule and the per-criterion reasoning instruction — is in
  `design.md`.
- **ADD** `Pass1ResponseParser`: parses the grader's JSON to the contract; coerces any
  non-hedged status (incl. bare `valide`/`non valide`) to `à vérifier` (R1); verifies
  each evidence item against the digest and drops phantoms; defaults a criterion with
  no surviving evidence to `à vérifier`; captures the per-criterion `reasoning`; and on
  unparseable JSON signals "unparseable" after one repair retry. Returns a structured
  result the service (E2b) will persist.

## Capabilities

### New Capabilities
- `pass1-grading`: this change creates the capability with its primitive contracts —
  the code digest, the prompt, the grader output JSON contract, and the response
  parser/validator. E2b adds the orchestration requirements (service + command).

### Modified Capabilities
<!-- none -->

## Impact

- **New code (`apps/web`)**: `config/grader.php`; `app/Services/Pass1/{GraderClient,
  ZenGraderClient,FakeGraderClient,RepoDigest,Pass1Prompt,Pass1ResponseParser}.php`;
  a small parsed-result DTO (`Pass1ParsedResult`/`ParsedCriterion`).
- **No persistence, no service orchestration, no command** — those are E2b.
- **Reads**: file contents under a given repo path (no execution). **No writes.**
- **Tests (unit, no network)**: digest excludes deps/VCS and is identity-free; prompt
  is blind and contains the criteria + truncation note; parser coercion, phantom-drop,
  empty→`à vérifier`, reasoning capture, unparseable signaling; `ZenGraderClient`
  request shaping via a mocked HTTP layer (Laravel `Http::fake`).
- **Hard rules**: R1 (hedged-only; coercion; no-evidence → `à vérifier`), R3 (blind,
  evidence-first, citations verified), R4 (no identity in digest/prompt), R5 (small,
  boring primitives). R2 untouched.
- **Security / egress**: **none in this change** — no real outbound call (fake grader
  in tests). The real egress to opencode/zen happens only when E2b runs the service
  against the live API; the zero-retention verification for `glm-5.2` is a precondition
  recorded in `design.md` and gated to E2b's first real run.
