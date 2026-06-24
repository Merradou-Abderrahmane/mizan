<?php

namespace Database\Factories;

use App\Models\Referentiel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Referentiel>
 */
class ReferentielFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3),
            'description' => fake()->paragraph(),
        ];
    }
}
