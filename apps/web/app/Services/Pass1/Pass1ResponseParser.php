<?php

namespace App\Services\Pass1;

use Illuminate\Support\Collection;

/**
 * Parses and HARD-validates a grader response (R1/R3): hedged-only statuses,
 * every evidence item verified against the digest (phantoms dropped), a criterion
 * with no surviving evidence defaulted to "à vérifier", per-criterion reasoning
 * captured, and unparseable output flagged (after one repair retry).
 */
class Pass1ResponseParser
{
    public const HEDGED = ['à vérifier', 'semble valide', 'semble non valide'];

    private const REPAIR_HINT = "\n\nYour previous reply was not valid JSON. Reply with ONLY the single JSON object described above, nothing else.";

    /**
     * Pure parse of one raw response.
     *
     * @param  Collection<int, \App\Models\Criterion>  $criteria  the criteria that were asked
     */
    public function parse(string $raw, RepoDigest $digest, Collection $criteria): Pass1ParsedResult
    {
        $data = json_decode($raw, true);

        if (! is_array($data) || ! isset($data['criteria']) || ! is_array($data['criteria'])) {
            return Pass1ParsedResult::unparseable($raw);
        }

        // Index the model's criteria by id.
        $byId = [];
        foreach ($data['criteria'] as $entry) {
            if (is_array($entry) && isset($entry['criterion_id'])) {
                $byId[(string) $entry['criterion_id']] = $entry;
            }
        }

        $parsed = [];
        foreach ($criteria as $criterion) {
            $entry = $byId[(string) $criterion->id] ?? null;
            $parsed[] = $this->parseCriterion((int) $criterion->id, $entry, $digest);
        }

        return new Pass1ParsedResult(
            rollup: $this->hedge($data['competence_draft_rollup'] ?? null),
            confidence: isset($data['confidence']) && is_numeric($data['confidence']) ? (float) $data['confidence'] : null,
            probeQuestions: $this->stringList($data['probe_questions'] ?? []),
            criteria: $parsed,
            raw: $raw,
        );
    }

    /**
     * Parse with one repair retry. $reAsk receives a repair hint to append to the
     * user prompt and returns the model's new raw response. Keeps the parser
     * decoupled from the concrete GraderClient.
     *
     * @param  Collection<int, \App\Models\Criterion>  $criteria
     * @param  callable(string): string  $reAsk
     */
    public function parseWithRepair(string $raw, RepoDigest $digest, Collection $criteria, callable $reAsk): Pass1ParsedResult
    {
        $result = $this->parse($raw, $digest, $criteria);

        if (! $result->unparseable) {
            return $result;
        }

        $retryRaw = $reAsk(self::REPAIR_HINT);

        return $this->parse($retryRaw, $digest, $criteria);
    }

    /**
     * @param  array<string,mixed>|null  $entry
     */
    private function parseCriterion(int $criterionId, ?array $entry, RepoDigest $digest): ParsedCriterion
    {
        if ($entry === null) {
            return new ParsedCriterion($criterionId, [], 'à vérifier', '');
        }

        $evidence = [];
        foreach ((array) ($entry['evidence'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $file = (string) ($item['file'] ?? '');
            $line = (int) ($item['line'] ?? 0);
            if ($file === '' || ! $digest->has($file, $line)) {
                continue; // phantom citation — dropped
            }
            $evidence[] = [
                'file' => $file,
                'line' => $line,
                'note' => (string) ($item['note'] ?? ''),
            ];
        }

        $assessment = $this->hedge($entry['assessment_draft'] ?? null);

        // No surviving evidence → cannot stand on "semble valide/non valide".
        if ($evidence === []) {
            $assessment = 'à vérifier';
        }

        return new ParsedCriterion($criterionId, $evidence, $assessment, (string) ($entry['reasoning'] ?? ''));
    }

    /** Coerce any non-hedged value (incl. bare valide/non valide) to "à vérifier" (R1). */
    private function hedge(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : '';

        return in_array($value, self::HEDGED, true) ? $value : 'à vérifier';
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map('strval', array_filter($value, 'is_scalar')));
    }
}
