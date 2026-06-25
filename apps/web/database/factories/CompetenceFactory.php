<?php

namespace Database\Factories;

use App\Models\Competence;
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
            'code' => fake()->lexify('C???'),
            'label' => fake()->words(3, true),
            'description' => fake()->paragraph(),
            'kind' => 'transversale',
        ];
    }

    public function technical(): static
    {
        return $this->state(fn (array $attributes) => ['kind' => 'technique']);
    }
}
