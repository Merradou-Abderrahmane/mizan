<?php

namespace Tests\Feature\OperatorPanel;

use App\Models\Run;
use App\Models\StudentRepo;
use App\Services\RepoIntakeService;
use App\Services\RunnerCrashException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A RepoIntakeService whose runner is substituted, so intakeIntoRun's DB
 * behavior is tested without spawning the real runner subprocess.
 */
class SubstitutedRunnerRepoIntakeService extends RepoIntakeService
{
    /** @var array<string, mixed> */
    public array $report = [
        'status' => 'success',
        'checks' => [['id' => 'composer_install', 'status' => 'pass']],
        'started_at' => '2026-06-25T10:00:00+00:00',
        'ended_at' => '2026-06-25T10:00:05+00:00',
    ];

    public bool $crash = false;

    protected function runRunnerOnSource(string $source): array
    {
        if ($this->crash) {
            throw new RunnerCrashException('non-json output', $source);
        }

        return $this->report;
    }
}

class IntakeIntoRunTest extends TestCase
{
    use RefreshDatabase;

    private function pendingRun(): Run
    {
        $repo = StudentRepo::factory()->create(['clone_path' => '/some/local/path']);

        return Run::factory()->create(['student_repo_id' => $repo->id, 'status' => 'pending']);
    }

    public function test_populates_report_and_timestamps_without_setting_status(): void
    {
        $run = $this->pendingRun();
        $service = new SubstitutedRunnerRepoIntakeService;

        $service->intakeIntoRun($run);
        $run->refresh();

        $this->assertSame('success', $run->runner_report_json['status']);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->ended_at);
        // The lifecycle is the caller's (the job's) job — intakeIntoRun leaves it.
        $this->assertSame('pending', $run->status);
    }

    public function test_persists_error_report_and_rethrows_on_crash(): void
    {
        $run = $this->pendingRun();
        $service = new SubstitutedRunnerRepoIntakeService;
        $service->crash = true;

        try {
            $service->intakeIntoRun($run);
            $this->fail('expected RunnerCrashException');
        } catch (RunnerCrashException) {
            // expected
        }

        $run->refresh();
        $this->assertSame('error', $run->runner_report_json['status']);
        $this->assertNotNull($run->ended_at);
        // status is still set by the caller, not here.
        $this->assertSame('pending', $run->status);
    }
}
