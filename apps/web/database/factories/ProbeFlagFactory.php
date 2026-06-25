<?php

namespace Database\Factories;

use App\Models\Criterion;
use App\Models\ProbeFlag;
use App\Models\Run;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProbeFlag>
 */
class ProbeFlagFactory extends Factory
{
    public function definition(): array
    {
        return [
            'run_id' => Run::factory(),
            'criterion_id' => Criterion::factory(),
            'kind' => fake()->randomElement(['divergence', 'regression']),
            'context_payload' => null,
            'message' => fake()->optional()->sentence(),
        ];
    }
}
