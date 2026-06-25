<?php

namespace App\Services\Pass1;

/**
 * One criterion's validated Pass 1 finding: surviving (verified) evidence, the
 * hedged assessment, and the model's reasoning.
 */
class ParsedCriterion
{
    /**
     * @param  list<array{file: string, line: int, note: string}>  $evidence
     */
    public function __construct(
        public readonly int $criterionId,
        public readonly array $evidence,
        public readonly string $assessment,
        public readonly string $reasoning,
    ) {}
}
