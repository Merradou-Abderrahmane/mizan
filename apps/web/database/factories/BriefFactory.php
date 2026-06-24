<?php

namespace Database\Factories;

use App\Models\Brief;
use App\Models\Referentiel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Brief>
 */
class BriefFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'referentiel_id' => Referentiel::factory(),
            'payload' => null,
        ];
    }
}
