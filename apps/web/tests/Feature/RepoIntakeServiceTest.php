<?php

namespace Tests\Feature;

use App\Models\Brief;
use App\Models\Evidence;
use App\Models\Referentiel;
use App\Models\Run;
use App\Models\StudentRepo;
use App\Services\RepoIntakeService;
use App\Services\RunnerCrashException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class RepoIntakeServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $fixtureRepo;

    private string $fixtureNonGit;

    protected function setUp(): void
    {
        parent::setUp();
        $monorepoRoot = dirname(base_path(), 2);
        $this->fixtureRepo = $monorepoRoot . '/apps/runner/tests/fixtures/valid_repo';
        $this->fixtureNonGit = $monorepoRoot . '/apps/runner/tests/fixtures/non_git_dir';
    }

    /**
     * Slow test: runs the full runner against valid_repo (composer install ~18s).
     * Consolidates all assertions that need a real runner invocation into ONE
     * test to avoid running the slow runner 10 times.
     *
     * @group slow
     */
    public function test_happy_path_local_path_full_assertions(): void
    {
        $brief = $this->createBrief();
        $clonesDir = storage_path('runner-clones');
        File::deleteDirectory($clonesDir);

        $service = new RepoIntakeService();
        $run = $service->intake($this->fixtureRepo, $brief->id, null, 'advanced');

        // Run persisted
        $this->assertInstanceOf(Run::class, $run);
        $this->assertDatabaseHas('runs', ['id' => $run->id]);
        $this->assertNotNull($run->student_repo_id);
        $this->assertSame($brief->id, $run->brief_id);
        $this->assertContains($run->status, ['pass', 'fail', 'error']);

        // Report blob
        $report = $run->runner_report_json;
        $this->assertIsArray($report);
        $this->assertArrayHasKey('checks', $report);

        // R3: zero Evidence rows
        $this->assertSame(0, Evidence::where('run_id', $run->id)->count(), 'R3: no Evidence rows from runner intake.');

        // StudentRepo created with derived name + persona
        $repo = $run->studentRepo;
        $this->assertInstanceOf(StudentRepo::class, $repo);
        $this->assertSame($this->fixtureRepo, $repo->clone_path);
        $this->assertSame('valid_repo', $repo->name);
        $this->assertSame('advanced', $repo->operator_persona);

        // R4: persona hidden from serialization
        $this->assertArrayNotHasKey('operator_persona', $repo->toArray());

        // R4: persona not leaked into runner report
        $this->assertStringNotContainsString('advanced', json_encode($report));

        // No clone dir created (local path)
        $this->assertFalse(
            is_dir($clonesDir) && count(scandir($clonesDir) ?: []) > 2,
            'No clone directory should be created for local-path sources.'
        );

        // Local path not deleted (operator-owned)
        $this->assertTrue(is_dir($this->fixtureRepo), 'Local path must not be deleted.');

        // R2: runner app files not modified (only check tracked files, not untracked fixtures)
        $monorepoRoot = dirname(base_path(), 2);
        $gitModified = shell_exec('cd ' . escapeshellarg($monorepoRoot) . ' && git diff --name-only -- apps/runner/');
        $gitStaged = shell_exec('cd ' . escapeshellarg($monorepoRoot) . ' && git diff --cached --name-only -- apps/runner/');
        $this->assertEmpty(trim(($gitModified ?: '') . ($gitStaged ?: '')), 'R2: no tracked apps/runner/ file should be modified by the intake service.');
    }

    /**
     * @group slow
     */
    public function test_reuse_existing_student_repo(): void
    {
        $brief = $this->createBrief();
        $existingRepo = StudentRepo::factory()->create(['operator_persona' => 'beginner']);

        $service = new RepoIntakeService();
        $run = $service->intake($this->fixtureRepo, $brief->id, $existingRepo->id);

        $this->assertSame($existingRepo->id, $run->student_repo_id);
        $this->assertSame(1, StudentRepo::count(), 'No new StudentRepo should be created when id is provided.');
        $this->assertSame('beginner', $existingRepo->fresh()->operator_persona, 'Persona should be ignored when reusing.');
        $this->assertSame(0, Evidence::where('run_id', $run->id)->count(), 'R3: no Evidence rows.');
    }

    /**
     * @group slow
     */
    public function test_explicit_name_overrides_derivation(): void
    {
        $brief = $this->createBrief();

        $service = new RepoIntakeService();
        $run = $service->intake($this->fixtureRepo, $brief->id, null, null, 'Custom Name');

        $this->assertSame('Custom Name', $run->studentRepo->name);
        $this->assertSame(0, Evidence::where('run_id', $run->id)->count(), 'R3: no Evidence rows.');
    }

    public function test_missing_brief_throws(): void
    {
        $service = new RepoIntakeService();

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $service->intake($this->fixtureRepo, 99999);
    }

    public function test_runner_error_status_persists_run_with_error(): void
    {
        $brief = $this->createBrief();
        $monorepoRoot = dirname(base_path(), 2);

        $service = new RepoIntakeService();

        try {
            $service->intake($monorepoRoot . '/apps/runner/tests/fixtures/no_such_dir', $brief->id);
        } catch (RunnerCrashException $e) {
        }

        $run = Run::latest('id')->first();

        $this->assertNotNull($run, 'A Run should be persisted even on runner error.');
        $this->assertSame('error', $run->status);
        $this->assertSame(0, Evidence::where('run_id', $run->id)->count());
    }

    private function createBrief(): Brief
    {
        $referentiel = Referentiel::factory()->create();

        return Brief::factory()->create(['referentiel_id' => $referentiel->id]);
    }
}