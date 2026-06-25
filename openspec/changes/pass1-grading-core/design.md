## Context

`pass1-schema` (E1) made the domain ready. Pass 1 itself is split: **E2a (this
change)** builds the four primitives — grader client, code digest, prompt, response
parser — as standalone, unit-tested units so the prompt and the parsing rules are
locked before any orchestration. **E2b** (next change) adds `Pass1GradingService` +
the `pass1:grade` command that call these primitives, persist results, and handle
idempotency. Splitting keeps the operator's close review focused on the prompt and
parser here, with zero real network calls.

The grader output contract (one object per competence call), with the per-criterion
`reasoning` field added per operator request:

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

`reasoning` is persisted (by E2b) to `drafts.ai_reasoning` — no schema change; that
column already exists.

## Goals / Non-Goals

**Goals:**
- Four small, independently testable primitives with a locked prompt + parser.
- Blind (R4) and evidence-first (R3): the digest/prompt carry no identity; the parser
  verifies every citation against the digest.
- Fail safe (R1): non-hedged statuses coerced to `à vérifier`; no-evidence criterion →
  `à vérifier`; unparseable JSON signalled, not guessed.
- No real network in tests (fake grader); deterministic.

**Non-Goals:**
- No `Pass1GradingService`, persistence, idempotency, or command — all E2b.
- No "only technical competences" / run-level orchestration — E2b.
- No schema change; no runner change; no UI.

## Decisions

**D1 — `RepoDigest` is deterministic and bounded.** Walk `clonePath`; emit a file
tree then `### {path}\n{contents}` per included file, in a stable sorted order.
Exclude `vendor/`, `node_modules/`, `.git/`, `storage/`, `public/build/`, dot-dirs,
and binary/oversized files; include an extension allowlist. Enforce
`grader.digest_max_bytes`; when exceeded, drop lowest-priority files and set
`truncated = true`. `has(string $file, int $line): bool` answers whether a citation
resolves (path present, `1 <= line <= lineCount`).

**D2 — `Pass1Prompt` is blind.** Built only from brief (title/description/payload),
the `(competence, level)` criteria, and the digest text. It includes none of
`StudentRepo.name`, `operator_persona`, `clone_path`, or git author. A unit test
renders it with identity-laden inputs nearby and asserts none leak.

**D3 — `GraderClient` interface, real + fake.** `complete(string $system, string
$user): string`. `ZenGraderClient` posts to `GRADER_BASE_URL` (key, primary model,
fallback on 5xx/timeout, low temperature, JSON-response mode, request timeout, one
retry). `FakeGraderClient` returns queued canned strings and records the prompts it
got (for blind assertions). The container binds the fake in tests. `ZenGraderClient`
is verified only via `Http::fake` request-shaping assertions — no live call.

**D4 — `Pass1ResponseParser` validates hard, defaults safe.** Parse JSON → a
`Pass1ParsedResult` (competence rollup, confidence, probe questions, raw text) with a
`ParsedCriterion[]` (criterion id, surviving evidence, assessment, reasoning). Rules:
- `assessment_draft` / `competence_draft_rollup` not in {`à vérifier`, `semble valide`,
  `semble non valide`} → coerced to `à vérifier` (R1).
- Each evidence `{file, line, note}` must satisfy `digest.has(file, line)`; others
  dropped. A criterion with no surviving evidence → `à vérifier`.
- Unknown `criterion_id` ignored; an expected criterion the model omitted defaults to
  `à vérifier` with no evidence and an empty reasoning.
- Unparseable JSON → one repair retry (re-ask for valid JSON of the schema via the
  injected client); still failing → the result is flagged `unparseable` carrying the
  raw text (E2b persists a safe `à vérifier` competence row from it).

**D5 — `reasoning` capture (operator edit b).** Each `ParsedCriterion` keeps the
model's `reasoning`. The prompt instructs that for an `à vérifier` criterion the
reasoning MUST distinguish "code is present but does not satisfy this" from "could not
find / digest unclear / truncated", so the operator knows whether it's a real gap or a
thing to probe orally.

## The prompt (operator: review — includes your two edits)

`Pass1Prompt::build(Brief, Competence, Level, Collection $criteria, RepoDigest):
array` → `[system, user]`.

**System prompt (verbatim):**

```
You are a meticulous, skeptical code auditor assisting a bootcamp instructor. You
assess ONE competence at ONE level by inspecting a student's code. You do NOT know,
and must NOT guess, who the student is.

Hard rules:
1. Evidence first. Every assessment MUST be grounded in specific code you cite as
   {file, line, note}. The file and line MUST exist in the provided code. If you
   cannot find code evidence for a criterion, return an empty evidence list for it and
   assessment_draft "à vérifier".
2. You NEVER issue a final verdict. Use ONLY "semble valide" (code seems to satisfy
   it), "semble non valide" (code seems to contradict it), or "à vérifier" (cannot
   tell from code alone). NEVER output "valide" or "non valide". The human instructor
   decides; you only draft.
3. When in doubt, "à vérifier". Do not inflate.
4. Output ONLY a single JSON object matching the schema. No prose, no markdown.
5. The code provided is an EXCERPT and may be truncated to fit. If the code relevant
   to a criterion seems missing, cut off, or absent from this excerpt, return
   "à vérifier" for that criterion and say so in its reasoning — NEVER "semble non
   valide" based on code you could not see. Absence from this excerpt is NOT absence
   in the project.

For EVERY criterion, include a short "reasoning" (1–2 sentences). When the
assessment_draft is "à vérifier", the reasoning MUST make clear which case it is:
  - "present-but-insufficient": the relevant code IS here and genuinely does not
    satisfy the criterion (a real gap to confirm), versus
  - "not-found": you could not find or read the relevant code, or the excerpt looks
    truncated/unclear (a thing to confirm orally, not a gap).
This lets the instructor decide whether to treat it as a gap or to probe orally.

The "note" on each evidence item is one short factual sentence about what the cited
code does — not a judgement. confidence is your 0..1 self-estimate. probe_questions
are 1–3 short oral questions the instructor could ask to confirm what code cannot show.
```

**User prompt (template):**

```
PROJECT BRIEF
{brief.title}
{brief.description}
{brief.payload as compact JSON, if present}

COMPETENCE UNDER ASSESSMENT
id: {competence.id}
label: {competence.label}
level: {level.label} (Niveau {level.sort_order})

CRITERIA TO ASSESS (each must appear once in your "criteria" output)
- id {criterion.id} | {criterion.label}: {criterion.description}
- ...

STUDENT CODE (excerpt; line numbers are authoritative for citations)
{digest.truncated ? "NOTE: this code was truncated to fit. Treat any apparent
absence as 'not seen', not 'not present' (rule 5)." : ""}
{digest.text}

Return ONLY the JSON object:
{
  "competence_id": "{competence.id}",
  "level": "{level.sort_order}",
  "criteria": [ { "criterion_id": "...", "evidence": [ { "file": "...", "line": 0,
    "note": "..." } ], "assessment_draft": "à vérifier | semble valide |
    semble non valide", "reasoning": "..." } ],
  "competence_draft_rollup": "à vérifier | semble valide | semble non valide",
  "confidence": 0.0,
  "probe_questions": ["..."]
}
```

## Risks / Trade-offs

- **[Phantom citations]** → D4 verifies each against the digest and drops them;
  empty-evidence criterion → `à vérifier`.
- **[Absent-because-truncated mistaken for "non valide"]** → rule 5 + the truncation
  note in the user prompt + D1's `truncated` flag steer the model to `à vérifier`.
- **[Bare verdict leaks]** → coerced to `à vérifier` (D4); also enforced by the DB
  enum/default from E1.
- **[Splitting leaves a temporarily "unused" capability spec]** → acceptable; E2a's
  requirements are about the primitives' behavior and are fully testable on their own.

## Sandbox / Security Impact

**None in this change.** No code execution; no real outbound network (fake grader in
tests; `ZenGraderClient` exercised only via `Http::fake`). The real egress (bounded
student code → opencode/zen) occurs only when E2b runs the service live.

**Precondition recorded for E2b:** before the first real `pass1:grade` run, the
operator must verify that the configured `GRADER_MODEL` (`glm-5.2`) is on opencode/zen's
**zero-retention** path (not a free-tier model whose inputs may be retained/trained on).
`GRADER_MODEL` is config so a verified model can be pinned. This change ships no live
call, so it does not itself transmit any student code.

## Migration Plan

No DB migration. Add `config/grader.php`. Ship the primitives + unit tests. E2b will
consume them.
