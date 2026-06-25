<?php

namespace App\Console\Commands;

use App\Models\Run;
use App\Services\Pass1\Pass1GradingService;
use Illuminate\Console\Command;

class Pass1GradeCommand extends Command
{
    protected $signature = 'pass1:grade {run}';

    protected $description = 'Run Pass 1 blind grading for a Run (technical competences only).';

    public function handle(Pass1GradingService $service): int
    {
        $runId = (int) $this->argument('run');

        try {
            $run = Run::findOrFail($runId);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->error("Run not found: {$runId}");

            return 1;
        }

        $run->started_at = now();
        $run->save();

        $outcomes = $service->grade($run);

        if ($outcomes === []) {
            $this->error("Run {$run->id}: no technical competences on the brief.");
            $run->ended_at = now();
            $run->save();

            return 1;
        }

        $graded = 0;
        $failed = 0;

        foreach ($outcomes as $outcome) {
            if ($outcome->status === 'graded') {
                $graded++;
                $this->line("{$outcome->competenceLabel} — graded");
            } else {
                $failed++;
                $this->line("{$outcome->competenceLabel} — failed: {$outcome->reason}");
            }
        }

        $status = $failed > 0 ? 'pass1_partial' : 'pass1_done';

        $run->status = $status;
        $run->ended_at = now();
        $run->save();

        $this->line("Run {$run->id}: {$status} ({$graded} graded, {$failed} failed)");

        return 0;
    }
}
