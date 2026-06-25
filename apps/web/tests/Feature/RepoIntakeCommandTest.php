<?php

namespace Tests\Feature;

use App\Models\Brief;
use App\Models\Run;
use App\Models\StudentRepo;
use App\Services\RepoIntakeService;
use App\Services\RunnerCrashException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * `php artisan repo:intake {source} {brief}` — exercises the command wrapper
 * around RepoIntakeService::intake(). Tests are hermetic: the command test
 * binds a mocked RepoIntakeService for the success path so the real runner
 * subprocess is never invoked (no composer install, no network). The
 * failure paths use the real service because the service throws BEFORE
 * the runner subprocess is started (Brief::findOrFail on line 22 of
 * RepoIntakeService::intake).
 *
 * R5 — boring: the command is verified to forward both args verbatim and
 * exit with clear codes; it is verified NOT to modify apps/runner/ (R2).
 */
class RepoIntakeCommandTest extends TestCase
{
    use RefreshDatabase;

    private function makeRun(): Run
    {
        $studentRepo = StudentRepo::factory()->create(['clone_path' => '/tmp/anywhere']);
        $brief = Brief::factory()->create();

        return Run::factory()->create([
            'student_repo_id' => $studentRepo->id,
            'brief_id'        => $brief->id,
            'status'          => 'pass',
        ]);
    }

    // --- success path with mocked service (no real subprocess) --------------

    public function test_intake_creates_run_for_local_path(): void
    {
        $run = $this->makeRun();
        $runId = $run->id;
        $status = $run->status;
        $source = '/var/repos/forgecore';
        $briefId = $run->brief_id;

        $mock = Mockery::mock(RepoIntakeService::class);
        $mock->shouldReceive('intake')
            ->once()
            ->with($source, $briefId)
            ->andReturn($run);

        $this->app->instance(RepoIntakeService::class, $mock);

        $this->artisan('repo:intake', ['source' => $source, 'brief' => (string) $briefId])
            ->expectsOutputToContain("Run {$runId} created (status: {$status}).")
            ->assertSuccessful();

        // R5: command does NOT add domain logic — exactly one service call.
        $this->assertSame(1, Run::count(), 'Run is created by the service, not by the command.');
        $this->assertSame(1, StudentRepo::count(), 'StudentRepo is created by the service, not the command.');
    }

    // --- failure paths use the real service (no subprocess is invoked) -----

    public function test_intake_fails_when_brief_not_found(): void
    {
        // First run the success-style mock setup so we can ensure no Run persists on failure.
        $this->artisan('repo:intake', ['source' => '/no/where', 'brief' => '9999'])
            ->expectsOutputToContain('Brief not found: 9999')
            ->assertFailed();

        $this->assertSame(0, Run::count(), 'No Run row must be created when the brief is missing.');
    }

    public function test_intake_fails_with_non_numeric_brief(): void
    {
        $mock = Mockery::mock(RepoIntakeService::class);
        $mock->shouldNotReceive('intake');
        $this->app->instance(RepoIntakeService::class, $mock);

        $this->artisan('repo:intake', ['source' => '/var/repos/forgecore', 'brief' => 'abc'])
            ->expectsOutputToContain('Brief id must be an integer.')
            ->assertFailed();

        $this->assertSame(0, Run::count(), 'No Run row must be created when the brief id is non-numeric.');
    }

    public function test_intake_fails_when_runner_crashes(): void
    {
        $source = '/no/such/path/anywhere';
        $brief = Brief::factory()->create();

        $mock = Mockery::mock(RepoIntakeService::class);
        $mock->shouldReceive('intake')
            ->once()
            ->with($source, $brief->id)
            ->andThrow(new RunnerCrashException('garbage stdout', $source));

        $this->app->instance(RepoIntakeService::class, $mock);

        $this->artisan('repo:intake', ['source' => $source, 'brief' => (string) $brief->id])
            ->expectsOutputToContain('Intake failed:')
            ->assertFailed();
    }

    // --- R2: command does not modify apps/runner/ ---------------------------

    public function test_command_does_not_modify_runner_app_directory(): void
    {
        $mock = Mockery::mock(RepoIntakeService::class);
        $mock->shouldReceive('intake')->andReturn($this->makeRun());
        $this->app->instance(RepoIntakeService::class, $mock);

        $brief = Brief::factory()->create();
        $this->artisan('repo:intake', ['source' => '/tmp/anywhere', 'brief' => (string) $brief->id])
            ->assertSuccessful();

        $monorepoRoot = dirname(base_path(), 2);
        $gitModified = shell_exec('cd ' . escapeshellarg($monorepoRoot) . ' && git diff --name-only -- apps/runner/');
        $gitStaged   = shell_exec('cd ' . escapeshellarg($monorepoRoot) . ' && git diff --cached --name-only -- apps/runner/');

        $this->assertEmpty(
            trim(($gitModified ?: '') . ($gitStaged ?: '')),
            'R2: repo:intake must not modify any tracked apps/runner/ file.',
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}