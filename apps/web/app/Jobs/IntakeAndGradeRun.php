<?php

namespace App\Jobs;

use App\Models\Run;
use App\Services\Pass1\Pass1GradingService;
use App\Services\RepoIntakeService;
use App\Services\RunnerCrashException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Advances a pre-created `pending` run off the web request (design D2/D3): runs
 * the structural runner into the existing run, then grades it. Reuses
 * RepoIntakeService and Pass1GradingService verbatim — no grading/runner logic
 * here. Owns the run lifecycle: pending → processing → pass1_done / pass1_partial
 * / error. Persona never reaches this job (it carries only a run id) — R4.
 */
class IntakeAndGradeRun implements ShouldQueue
{
    use Queueable;

    /** Runner subprocess (~20s) + ~5 live LLM calls — well past the 60s default. */
    public int $timeout = 600;

    /** Expensive live work; do not auto-retry. */
    public int $tries = 1;

    public function __construct(private readonly int $runId) {}

    public function handle(RepoIntakeService $intake, Pass1GradingService $grading): void
    {
        $run = Run::find($this->runId);
        if ($run === null) {
            return;
        }

        $run->update(['status' => 'processing']);

        try {
            $intake->intakeIntoRun($run);
        } catch (RunnerCrashException) {
            // intakeIntoRun persisted the error report; mark the run errored.
            $run->update(['status' => 'error']);

            return;
        }

        try {
            $outcomes = $grading->grade($run);
        } catch (Throwable $e) {
            $run->update(['status' => 'error']);
            throw $e;
        }

        $anyFailed = collect($outcomes)->contains(fn ($o) => $o->status === 'failed');
        $run->update(['status' => $anyFailed ? 'pass1_partial' : 'pass1_done']);
    }
}
