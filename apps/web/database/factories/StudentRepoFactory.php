<?php

namespace Database\Factories;

use App\Models\StudentRepo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudentRepo>
 */
class StudentRepoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'clone_path' => fake()->filePath(),
            'operator_persona' => null,
        ];
    }
}
