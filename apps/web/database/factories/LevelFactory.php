<?php

namespace Database\Factories;

use App\Models\Level;
use App\Models\Referentiel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Level>
 */
class LevelFactory extends Factory
{
    public function definition(): array
    {
        return [
            'referentiel_id' => Referentiel::factory(),
            'code' => fake()->lexify('L???'),
            'label' => fake()->words(2, true),
            'sort_order' => fake()->numberBetween(0, 10),
        ];
    }
}
