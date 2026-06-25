## 1. Config + grader client

- [x] 1.1 Add `config/grader.php`: `base_url`, `api_key`, `model`, `fallback_model`
  (from `GRADER_*`), plus `temperature`, `timeout`, `digest_max_bytes` defaults.
- [x] 1.2 Add `app/Services/Pass1/GraderClient.php` interface:
  `complete(string $system, string $user): string`.
- [x] 1.3 Add `app/Services/Pass1/ZenGraderClient.php`: POST via Laravel `Http` to
  `config('grader.base_url')` with the key, primary `model`, low temperature,
  JSON-response mode, request timeout, one retry, and `fallback_model` on 5xx/timeout.
- [x] 1.4 Add `app/Services/Pass1/FakeGraderClient.php`: queue of canned responses;
  records received `[system, user]` prompts. Bind `GraderClient` → fake in tests.

## 2. Repo digest

- [x] 2.1 Add `app/Services/Pass1/RepoDigest.php`: `build(string $path): self` walking
  the repo deterministically (sorted); file tree + `### {relPath}` + contents;
  exclude `vendor/`, `node_modules/`, `.git/`, `storage/`, `public/build/`, dot-dirs,
  binary/oversized; extension allowlist; byte cap → drop + `truncated` flag.
- [x] 2.2 Add `has(string $file, int $line): bool` (path present and
  `1 <= line <= lineCount`) and a `text`/`truncated` accessor.

## 3. Prompt

- [x] 3.1 Add `app/Services/Pass1/Pass1Prompt.php`: `build(Brief, Competence, Level,
  Collection $criteria, RepoDigest): array` returning `[system, user]` using the exact
  text in `design.md` (incl. rule 5 truncation/absence and the per-criterion reasoning
  instruction). Include no identity (`name`/`persona`/`clone_path`/git author).

## 4. Parser

- [x] 4.1 Add a parsed-result DTO: `Pass1ParsedResult` (rollup, confidence,
  probe_questions, raw, `unparseable` flag) + `ParsedCriterion` (criterion_id,
  evidence[], assessment, reasoning).
- [x] 4.2 Add `app/Services/Pass1/Pass1ResponseParser.php`: parse JSON → DTO; coerce
  non-hedged statuses to `à vérifier`; verify evidence via `RepoDigest::has` and drop
  phantoms; empty-evidence criterion → `à vérifier`; ignore unknown `criterion_id`,
  default omitted expected criteria to `à vérifier`; capture `reasoning`.
- [x] 4.3 Invalid JSON: one repair retry (re-ask via the injected client), then return
  a result flagged `unparseable` carrying the raw text.

## 5. Tests (unit, no real network)

- [x] 5.1 Digest: excludes `vendor/` + `.git/`, includes allowlisted source; `has()`
  true/false for in-range/out-of-range/missing; identity-free.
- [x] 5.2 Digest: byte cap triggers `truncated = true` and stays within the cap.
- [x] 5.3 Prompt: blind (no `name`/`persona`/`clone_path`/git author); references each
  criterion id; forbids `valide`/`non valide`; truncated digest → à vérifier guidance.
- [x] 5.4 Parser: bare `valide`/`non valide` coerced to `à vérifier`; hedged pass through.
- [x] 5.5 Parser: phantom citation dropped; criterion then `à vérifier`; real citation
  survives with file+line+note; reasoning captured.
- [x] 5.6 Parser: unparseable response → `unparseable` flag + raw text after one retry.
- [x] 5.7 `ZenGraderClient` via `Http::fake`: request carries key + low temp + JSON
  mode; 503 on primary → retries with fallback model and returns its completion.
- [x] 5.8 `FakeGraderClient` returns queued output and records prompts.

## 6. Verify

- [x] 6.1 `php artisan test` — all green (no real HTTP; `Http::fake`/fake grader only).
- [x] 6.2 `openspec validate pass1-grading-core --strict` — passes.
- [x] 6.3 Confirm no `apps/runner/` change; confirm no live network in the suite.

## 7. Archive closeout (after PR merge)

- [ ] 7.1 `openspec archive pass1-grading-core -y` — creates canonical
  `openspec/specs/pass1-grading/spec.md`.
- [ ] 7.2 Replace the archived `## Purpose` TBD with a real Purpose (Pass 1 primitives:
  blind digest + prompt + grader contract + safe parser; R1/R3/R4; E2b adds the service).
- [ ] 7.3 `openspec validate pass1-grading --specs` — passes.
