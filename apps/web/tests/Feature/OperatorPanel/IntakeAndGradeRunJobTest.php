<?php

namespace Tests\Feature\OperatorPanel;

use App\Jobs\IntakeAndGradeRun;
use App\Models\Run;
use App\Models\StudentRepo;
use App\Services\Pass1\Pass1CompetenceOutcome;
use App\Services\Pass1\Pass1GradingService;
use App\Services\RepoIntakeService;
use App\Services\RunnerCrashException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Job orchestration: the run lifecycle pending → processing → terminal, with the
 * runner + grader mocked so there is no subprocess and no network.
 */
class IntakeAndGradeRunJobTest extends TestCase
{
    use RefreshDatabase;

    private function pendingRun(): Run
    {
        $repo = StudentRepo::factory()->create();

        return Run::factory()->create(['student_repo_id' => $repo->id, 'status' => 'pending']);
    }

    private function outcome(string $status): Pass1CompetenceOutcome
    {
        return new Pass1CompetenceOutcome(1, 'C', 1, $status, $status === 'failed' ? 'unparseable' : null, 2);
    }

    public function test_all_graded_drives_pending_to_done(): void
    {
        $run = $this->pendingRun();

        $intake = Mockery::mock(RepoIntakeService::class);
        $intake->shouldReceive('intakeIntoRun')->once()->andReturnUsing(fn (Run $r) => $r);
        $grading = Mockery::mock(Pass1GradingService::class);
        $grading->shouldReceive('grade')->once()->andReturn([$this->outcome('graded')]);

        (new IntakeAndGradeRun($run->id))->handle($intake, $grading);

        $this->assertSame('pass1_done', $run->fresh()->status);
    }

    public function test_a_failed_competence_drives_partial(): void
    {
        $run = $this->pendingRun();

        $intake = Mockery::mock(RepoIntakeService::class);
        $intake->shouldReceive('intakeIntoRun')->once()->andReturnUsing(fn (Run $r) => $r);
        $grading = Mockery::mock(Pass1GradingService::class);
        $grading->shouldReceive('grade')->once()->andReturn([$this->outcome('graded'), $this->outcome('failed')]);

        (new IntakeAndGradeRun($run->id))->handle($intake, $grading);

        $this->assertSame('pass1_partial', $run->fresh()->status);
    }

    public function test_runner_crash_marks_error_and_skips_grading(): void
    {
        $run = $this->pendingRun();

        $intake = Mockery::mock(RepoIntakeService::class);
        $intake->shouldReceive('intakeIntoRun')->once()->andThrow(new RunnerCrashException('x', '/p'));
        $grading = Mockery::mock(Pass1GradingService::class);
        $grading->shouldReceive('grade')->never();

        (new IntakeAndGradeRun($run->id))->handle($intake, $grading);

        $this->assertSame('error', $run->fresh()->status);
    }

    public function test_grading_throwing_marks_error_and_rethrows(): void
    {
        $run = $this->pendingRun();

        $intake = Mockery::mock(RepoIntakeService::class);
        $intake->shouldReceive('intakeIntoRun')->once()->andReturnUsing(fn (Run $r) => $r);
        $grading = Mockery::mock(Pass1GradingService::class);
        $grading->shouldReceive('grade')->once()->andThrow(new RuntimeException('boom'));

        try {
            (new IntakeAndGradeRun($run->id))->handle($intake, $grading);
            $this->fail('expected the grading exception to propagate');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame('error', $run->fresh()->status);
    }
}
