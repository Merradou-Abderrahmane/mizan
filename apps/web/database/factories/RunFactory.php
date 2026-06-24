<?php

namespace Database\Factories;

use App\Models\Brief;
use App\Models\Run;
use App\Models\StudentRepo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Run>
 */
class RunFactory extends Factory
{
    public function definition(): array
    {
        return [
            'student_repo_id' => StudentRepo::factory(),
            'brief_id' => Brief::factory(),
            'status' => 'pending',
            'runner_report_json' => null,
            'started_at' => null,
            'ended_at' => null,
        ];
    }
}
