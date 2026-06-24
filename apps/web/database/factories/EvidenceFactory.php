<?php

namespace Database\Factories;

use App\Models\Competence;
use App\Models\Evidence;
use App\Models\Run;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Evidence>
 */
class EvidenceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'run_id' => Run::factory(),
            'competence_id' => Competence::factory(),
            'check_id' => fake()->randomElement([
                'composer_install', 'app_boots', 'migrations_run',
                'readme_real', 'env_not_tracked', 'git_history_real',
            ]),
            'file_path' => fake()->optional()->filePath(),
            'line_number' => fake()->optional()->numberBetween(1, 200),
            'excerpt' => fake()->optional()->text(200),
            'kind' => fake()->randomElement(['stdout', 'stderr', 'git', 'filesystem', 'command']),
            'status' => fake()->randomElement(['pass', 'fail', 'skip']),
            'message' => fake()->optional()->sentence(),
        ];
    }
}
