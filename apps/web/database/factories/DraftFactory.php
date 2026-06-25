<?php

namespace Database\Factories;

use App\Models\Criterion;
use App\Models\Draft;
use App\Models\Run;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Draft>
 */
class DraftFactory extends Factory
{
    public function definition(): array
    {
        return [
            'run_id' => Run::factory(),
            'criterion_id' => Criterion::factory(),
            'ai_status' => 'à vérifier',
            'ai_raw_json' => null,
            'ai_reasoning' => fake()->optional()->paragraph(),
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
