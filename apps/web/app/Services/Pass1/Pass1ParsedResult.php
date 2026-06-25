<?php

namespace App\Services\Pass1;

/**
 * The validated Pass 1 result for one competence call. When $unparseable is
 * true, only $raw is meaningful (E2b persists a safe à vérifier competence row).
 */
class Pass1ParsedResult
{
    /**
     * @param  list<ParsedCriterion>  $criteria
     * @param  list<string>  $probeQuestions
     */
    public function __construct(
        public readonly string $rollup,
        public readonly ?float $confidence,
        public readonly array $probeQuestions,
        public readonly array $criteria,
        public readonly string $raw,
        public readonly bool $unparseable = false,
    ) {}

    public static function unparseable(string $raw): self
    {
        return new self('à vérifier', null, [], [], $raw, true);
    }
}
