<?php

namespace App\Services\Pass1;

/**
 * The outcome of grading one competence in a run. Carries no student identity
 * and no verdict — only the status (graded/failed) the command and a future UI
 * use to render a summary. The rollup itself lives in the DB
 * (Pass1CompetenceResult), accessed via the model.
 */
class Pass1CompetenceOutcome
{
    public function __construct(
        public readonly int $competenceId,
        public readonly string $competenceLabel,
        public readonly int $levelId,
        public readonly string $status, // 'graded' | 'failed'
        public readonly ?string $reason,
        public readonly int $criterionCount,
    ) {}
}
