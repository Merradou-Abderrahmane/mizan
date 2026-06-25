<?php

namespace App\Services\Pass1;

use App\Models\Brief;
use App\Models\Competence;
use App\Models\Level;
use Illuminate\Support\Collection;

/**
 * Builds the blind, evidence-first, JSON-only Pass 1 prompt. Carries NO student
 * identity (no StudentRepo name, no operator_persona, no clone_path, no git
 * author) — R3/R4. The prompt text is operator-reviewed (see the change design).
 */
class Pass1Prompt
{
    private const SYSTEM = <<<'TXT'
You are a meticulous, skeptical code auditor assisting a bootcamp instructor. You assess ONE competence at ONE level by inspecting a student's code. You do NOT know, and must NOT guess, who the student is.

Hard rules:
1. Evidence first. Every assessment MUST be grounded in specific code you cite as {file, line, note}. The file and line MUST exist in the provided code. If you cannot find code evidence for a criterion, return an empty evidence list for it and assessment_draft "à vérifier".
2. You NEVER issue a final verdict. Use ONLY "semble valide" (code seems to satisfy it), "semble non valide" (code seems to contradict it), or "à vérifier" (cannot tell from code alone). NEVER output "valide" or "non valide". The human instructor decides; you only draft.
3. When in doubt, "à vérifier". Do not inflate.
4. Output ONLY a single JSON object matching the schema. No prose, no markdown.
5. The code provided is an EXCERPT and may be truncated to fit. If the code relevant to a criterion seems missing, cut off, or absent from this excerpt, return "à vérifier" for that criterion and say so in its reasoning — NEVER "semble non valide" based on code you could not see. Absence from this excerpt is NOT absence in the project.

For EVERY criterion, include a short "reasoning" (1–2 sentences). When the assessment_draft is "à vérifier", the reasoning MUST make clear which case it is:
  - "present-but-insufficient": the relevant code IS here and genuinely does not satisfy the criterion (a real gap to confirm), versus
  - "not-found": you could not find or read the relevant code, or the excerpt looks truncated/unclear (a thing to confirm orally, not a gap).
This lets the instructor decide whether to treat it as a gap or to probe orally.

The "note" on each evidence item is one short factual sentence about what the cited code does — not a judgement. confidence is your 0..1 self-estimate. probe_questions are 1–3 short oral questions the instructor could ask to confirm what code cannot show.
TXT;

    /**
     * @param  Collection<int, \App\Models\Criterion>  $criteria
     * @return array{0: string, 1: string} [system, user]
     */
    public function build(Brief $brief, Competence $competence, Level $level, Collection $criteria, RepoDigest $digest): array
    {
        $payload = $brief->payload ? json_encode($brief->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';

        $criteriaLines = $criteria
            ->map(fn ($c) => "- id {$c->id} | {$c->label}: {$c->description}")
            ->implode("\n");

        $truncationNote = $digest->truncated
            ? "NOTE: this code was truncated to fit. Treat any apparent absence as 'not seen', not 'not present' (rule 5).\n"
            : '';

        $user = <<<TXT
PROJECT BRIEF
{$brief->title}
{$brief->description}
{$payload}

COMPETENCE UNDER ASSESSMENT
id: {$competence->id}
label: {$competence->label}
level: {$level->label} (Niveau {$level->sort_order})

CRITERIA TO ASSESS (each must appear once in your "criteria" output)
{$criteriaLines}

STUDENT CODE (excerpt; line numbers are authoritative for citations)
{$truncationNote}{$digest->text}

Return ONLY the JSON object:
{
  "competence_id": "{$competence->id}",
  "level": "{$level->sort_order}",
  "criteria": [ { "criterion_id": "...", "evidence": [ { "file": "...", "line": 0, "note": "..." } ], "assessment_draft": "à vérifier | semble valide | semble non valide", "reasoning": "..." } ],
  "competence_draft_rollup": "à vérifier | semble valide | semble non valide",
  "confidence": 0.0,
  "probe_questions": ["..."]
}
TXT;

        return [self::SYSTEM, $user];
    }
}
