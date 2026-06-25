<?php

namespace Database\Factories;

use App\Models\Competence;
use App\Models\Level;
use App\Models\Pass1CompetenceResult;
use App\Models\Run;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Pass1CompetenceResult>
 */
class Pass1CompetenceResultFactory extends Factory
{
    public function definition(): array
    {
        return [
            'run_id' => Run::factory(),
            'competence_id' => Competence::factory(),
            'level_id' => Level::factory(),
            'ai_rollup_status' => 'à vérifier',
            'confidence' => fake()->optional()->randomFloat(3, 0, 1),
            'probe_questions' => null,
            'raw_json' => null,
            'operator_status' => null,
            'operator_note' => null,
            'finalized_at' => null,
        ];
    }

    public function finalized(string $status = 'valide'): static
    {
        return $this->state(fn (array $attributes) => [
            'operator_status' => $status,
            'finalized_at' => now(),
        ]);
    }
}
