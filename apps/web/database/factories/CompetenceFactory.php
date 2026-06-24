<?php

namespace Database\Factories;

use App\Models\Competence;
use App\Models\Level;
use App\Models\Referentiel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Competence>
 */
class CompetenceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'referentiel_id' => Referentiel::factory(),
            'level_id' => null,
            'code' => fake()->lexify('C???'),
            'label' => fake()->words(3, true),
            'description' => fake()->paragraph(),
        ];
    }

    public function withLevel(): static
    {
        return $this->state(fn (array $attributes) => [
            'level_id' => Level::factory()->state([
                'referentiel_id' => $attributes['referentiel_id'] ?? Referentiel::factory(),
            ]),
        ]);
    }
}
