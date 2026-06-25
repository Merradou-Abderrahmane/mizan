<?php

namespace App\Jobs;

use App\Services\Pass1\Pass1GradingService;
use App\Services\RepoIntakeService;
use App\Services\RunnerCrashException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs the existing intake → grade path off the web request so the operator's
 * submit returns immediately (design D2). Reuses RepoIntakeService and
 * Pass1GradingService verbatim — no grading/runner logic here. The run's status
 * carries progress: (created by intake) → processing → pass1_done / pass1_partial
 * / error.
 */
class IntakeAndGradeRun implements ShouldQueue
{
    use Queueable;

    /** Runner subprocess (~20s) + ~5 live LLM calls — well past the 60s default. */
    public int $timeout = 600;

    /** Expensive live work; do not auto-retry. */
    public int $tries = 1;

    public function __construct(
        private readonly string $source,
        private readonly int $briefId,
        private readonly ?string $persona = null,
        private readonly ?string $name = null,
    ) {}

    public function handle(RepoIntakeService $intake, Pass1GradingService $grading): void
    {
        try {
            $run = $intake->intake($this->source, $this->briefId, null, $this->persona, $this->name);
        } catch (RunnerCrashException) {
            // intake already persisted an 'error' run for the crashed runner;
            // there is nothing to grade. The run is visible in the panel as error.
            return;
        }

        $run->update(['status' => 'processing']);

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
