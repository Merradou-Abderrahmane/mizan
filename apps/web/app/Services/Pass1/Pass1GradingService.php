<?php

namespace App\Services\Pass1;

use App\Models\Evidence;
use App\Models\Draft;
use App\Models\Level;
use App\Models\Pass1CompetenceResult;
use App\Models\Run;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Orchestrates Pass 1 for a Run: resolves the brief's technical competences at
 * their pivot target level, builds the digest ONCE, calls the grader once per
 * competence, parses, and persists per-criterion evidence/drafts + one
 * pass1_competence_results rollup — idempotently, with per-competence
 * transaction + failure isolation (R1/R3/R4).
 */
class Pass1GradingService
{
    public function __construct(
        private readonly GraderClient $grader,
        private readonly Pass1Prompt $prompt,
        private readonly Pass1ResponseParser $parser,
    ) {}

    /**
     * @return list<Pass1CompetenceOutcome>
     */
    public function grade(Run $run): array
    {
        $brief = $run->brief;
        $competences = $brief->competences()
            ->technical()
            ->withPivot('level_id')
            ->get();

        if ($competences->isEmpty()) {
            return [];
        }

        // One digest per run — R4: clone_path feeds the digest only, never a prompt.
        $digest = RepoDigest::build($run->studentRepo->clone_path);

        $outcomes = [];

        foreach ($competences as $competence) {
            $levelId = (int) $competence->pivot->level_id;
            $level = Level::find($levelId);
            $criteria = $competence->criteria()->where('level_id', $levelId)->get();

            try {
                [$system, $user] = $this->prompt->build($brief, $competence, $level, $criteria, $digest);
                $raw = $this->grader->complete($system, $user);

                $result = $this->parser->parseWithRepair(
                    $raw,
                    $digest,
                    $criteria,
                    fn (string $hint): string => $this->grader->complete($system, $user.$hint),
                );

                if ($result->unparseable) {
                    $this->persistFailure(
                        $run->id,
                        $competence->id,
                        $levelId,
                        ['unparseable' => true, 'raw' => $result->raw],
                    );

                    $outcomes[] = new Pass1CompetenceOutcome(
                        $competence->id, $competence->label, $levelId, 'failed', 'unparseable', $criteria->count(),
                    );

                    continue;
                }

                DB::transaction(fn () => $this->persistSuccess($run->id, $competence->id, $levelId, $result, $criteria));

                $outcomes[] = new Pass1CompetenceOutcome(
                    $competence->id, $competence->label, $levelId, 'graded', null, $criteria->count(),
                );
            } catch (Throwable $e) {
                // The competence's transaction (if any was open) already rolled back.
                // Persist a safe à vérifier row in a fresh transaction.
                try {
                    $this->persistFailure(
                        $run->id,
                        $competence->id,
                        $levelId,
                        ['error' => $e::class.': '.$e->getMessage()],
                    );
                } catch (Throwable) {
                    // If even the failure-row persist fails, the competence simply
                    // has no row — the run continues. The operator will see the
                    // 'failed' outcome and can re-grade.
                }

                $outcomes[] = new Pass1CompetenceOutcome(
                    $competence->id, $competence->label, $levelId, 'failed', $e::class, $criteria->count(),
                );
            }
        }

        return $outcomes;
    }

    /**
     * Persist a successful parse: delete-then-reinsert evidence/drafts scoped to
     * this competence's criteria, and updateOrCreate the rollup (AI columns only
     * — operator columns are never touched, preserving prior finalization).
     */
    private function persistSuccess(int $runId, int $competenceId, int $levelId, Pass1ParsedResult $result, Collection $criteria): void
    {
        $criterionIds = $criteria->pluck('id')->all();

        // Idempotent: clear prior rows for this competence's criteria.
        Evidence::where('run_id', $runId)->whereIn('criterion_id', $criterionIds)->delete();
        Draft::where('run_id', $runId)->whereIn('criterion_id', $criterionIds)->delete();

        foreach ($result->criteria as $parsed) {
            foreach ($parsed->evidence as $item) {
                Evidence::create([
                    'run_id' => $runId,
                    'criterion_id' => $parsed->criterionId,
                    'file_path' => $item['file'],
                    'line_number' => $item['line'],
                    'message' => $item['note'],
                    'check_id' => null,
                    'kind' => null,
                    'status' => null,
                ]);
            }

            Draft::create([
                'run_id' => $runId,
                'criterion_id' => $parsed->criterionId,
                'ai_status' => $parsed->assessment,
                'ai_reasoning' => $parsed->reasoning,
                'ai_raw_json' => ['evidence' => $parsed->evidence, 'assessment' => $parsed->assessment, 'reasoning' => $parsed->reasoning],
            ]);
        }

        Pass1CompetenceResult::updateOrCreate(
            ['run_id' => $runId, 'competence_id' => $competenceId],
            [
                'level_id' => $levelId,
                'ai_rollup_status' => $result->rollup,
                'confidence' => $result->confidence,
                'probe_questions' => $result->probeQuestions,
                'raw_json' => ['raw_response' => $result->raw],
            ],
        );
    }

    /**
     * Persist a safe à vérifier rollup with the failure recorded in raw_json.
     * No evidence/drafts rows — failure is visibly distinct from graded-empty.
     */
    private function persistFailure(int $runId, int $competenceId, int $levelId, array $rawJson): void
    {
        DB::transaction(function () use ($runId, $competenceId, $levelId, $rawJson): void {
            Pass1CompetenceResult::updateOrCreate(
                ['run_id' => $runId, 'competence_id' => $competenceId],
                [
                    'level_id' => $levelId,
                    'ai_rollup_status' => 'à vérifier',
                    'confidence' => null,
                    'probe_questions' => [],
                    'raw_json' => $rawJson,
                ],
            );
        });
    }
}
