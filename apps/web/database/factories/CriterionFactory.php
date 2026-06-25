<?php

namespace Database\Factories;

use App\Models\Competence;
use App\Models\Criterion;
use App\Models\Level;
use App\Models\Referentiel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Criterion>
 */
class CriterionFactory extends Factory
{
    public function definition(): array
    {
        // Anchor competence and level to the same référentiel so the criterion
        // describes a coherent (competence, level) pair.
        $referentiel = Referentiel::factory();

        return [
            'competence_id' => Competence::factory()->state(['referentiel_id' => $referentiel]),
            'level_id' => Level::factory()->state(['referentiel_id' => $referentiel]),
            'code' => fake()->lexify('CR???'),
            'label' => fake()->words(3, true),
            'description' => fake()->optional()->paragraph(),
            'sort_order' => fake()->numberBetween(0, 10),
        ];
    }
}
