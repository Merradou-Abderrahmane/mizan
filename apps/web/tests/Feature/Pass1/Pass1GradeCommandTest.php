<?php

namespace Tests\Feature\Pass1;

use App\Models\Brief;
use App\Models\Competence;
use App\Models\Criterion;
use App\Models\Level;
use App\Models\Referentiel;
use App\Models\Run;
use App\Models\StudentRepo;
use App\Services\Pass1\FakeGraderClient;
use App\Services\Pass1\GraderClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class Pass1GradeCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $repoPath;

    protected function tearDown(): void
    {
        if (isset($this->repoPath) && is_dir($this->repoPath)) {
            File::deleteDirectory($this->repoPath);
        }
        parent::tearDown();
    }

    /** @param array<string,string> $files relPath => contents */
    private function makeRepo(array $files): string
    {
        $this->repoPath = sys_get_temp_dir().'/mizan_cmd_'.uniqid();
        foreach ($files as $rel => $contents) {
            $full = $this->repoPath.'/'.$rel;
            File::ensureDirectoryExists(dirname($full));
            File::put($full, $contents);
        }

        return $this->repoPath;
    }

    /**
     * @return array{0:Run,1:FakeGraderClient}
     */
    private function makeFixture(array $competenceConfigs): array
    {
        $referentiel = Referentiel::factory()->create();
        $brief = Brief::factory()->create(['referentiel_id' => $referentiel->id]);

        foreach ($competenceConfigs as $config) {
            $kind = $config['kind'] === 'technical' ? 'technique' : 'transversale';
            $competence = Competence::factory()->create([
                'referentiel_id' => $referentiel->id,
                'label' => $config['label'],
                'kind' => $kind,
            ]);
            $level = Level::factory()->create([
                'referentiel_id' => $referentiel->id,
                'sort_order' => $config['sort_order'] ?? 2,
            ]);
            $brief->competences()->attach($competence->id, ['level_id' => $level->id]);

            foreach ($config['criteria'] as $cLabel) {
                Criterion::factory()->create([
                    'competence_id' => $competence->id,
                    'level_id' => $level->id,
                    'label' => $cLabel,
                ]);
            }
        }

        $repoPath = $this->makeRepo(['app/Models/User.php' => "<?php\n// model\n"]);
        $studentRepo = StudentRepo::factory()->create(['clone_path' => $repoPath]);
        $run = Run::factory()->create([
            'student_repo_id' => $studentRepo->id,
            'brief_id' => $brief->id,
        ]);

        $fake = new FakeGraderClient;
        $this->app->bind(GraderClient::class, fn () => $fake);

        return [$run, $fake];
    }

    private function goodResponse(int $competenceId, object $competence, int $levelId): string
    {
        $criteria = $competence->criteria()->where('level_id', $levelId)->get();
        $criteriaJson = $criteria->map(fn ($c) => [
            'criterion_id' => (string) $c->id,
            'evidence' => [['file' => 'app/Models/User.php', 'line' => 1, 'note' => 'model file']],
            'assessment_draft' => 'semble valide',
            'reasoning' => 'present',
        ])->toArray();

        return json_encode([
            'competence_id' => (string) $competenceId,
            'level' => '2',
            'criteria' => $criteriaJson,
            'competence_draft_rollup' => 'semble valide',
            'confidence' => 0.7,
            'probe_questions' => [],
        ], JSON_UNESCAPED_UNICODE);
    }

    // --- 6.1 Successful run -----------------------------------------------

    public function test_successful_run_sets_pass1_done_and_exits_0(): void
    {
        [$run, $fake] = $this->makeFixture([
            ['kind' => 'technical', 'label' => 'Comp A', 'criteria' => ['C1']],
            ['kind' => 'technical', 'label' => 'Comp B', 'criteria' => ['C2']],
        ]);

        foreach ($run->brief->competences()->technical()->get() as $comp) {
            $fake->queue($this->goodResponse($comp->id, $comp, $comp->pivot->level_id));
        }

        $this->artisan('pass1:grade', ['run' => $run->id])
            ->expectsOutputToContain('Comp A — graded')
            ->expectsOutputToContain('Comp B — graded')
            ->expectsOutputToContain("Run {$run->id}: pass1_done (2 graded, 0 failed)")
            ->assertSuccessful();

        $run->refresh();
        $this->assertSame('pass1_done', $run->status);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->ended_at);
    }

    // --- 6.2 Partial run --------------------------------------------------

    public function test_partial_run_sets_pass1_partial_and_exits_0(): void
    {
        [$run, $fake] = $this->makeFixture([
            ['kind' => 'technical', 'label' => 'Comp A', 'criteria' => ['C1']],
            ['kind' => 'technical', 'label' => 'Comp B', 'criteria' => ['C2']],
        ]);

        $comps = $run->brief->competences()->technical()->get()->values();
        // A: unparseable (initial + repair retry).
        $fake->queue('not json');
        $fake->queue('still not json');
        // B: well-formed.
        $fake->queue($this->goodResponse($comps[1]->id, $comps[1], $comps[1]->pivot->level_id));

        $this->artisan('pass1:grade', ['run' => $run->id])
            ->expectsOutputToContain('Comp A — failed: unparseable')
            ->expectsOutputToContain('Comp B — graded')
            ->expectsOutputToContain("Run {$run->id}: pass1_partial (1 graded, 1 failed)")
            ->assertSuccessful();

        $run->refresh();
        $this->assertSame('pass1_partial', $run->status);
    }

    // --- 6.3 Missing run --------------------------------------------------

    public function test_missing_run_exits_1(): void
    {
        $this->artisan('pass1:grade', ['run' => 9999])
            ->expectsOutputToContain('Run not found: 9999')
            ->assertFailed();
    }

    // --- 6.4 No technical competences -------------------------------------

    public function test_no_technical_competences_exits_1_and_does_not_call_grader(): void
    {
        $fake = new FakeGraderClient;
        $this->app->bind(GraderClient::class, fn () => $fake);

        $referentiel = Referentiel::factory()->create();
        $brief = Brief::factory()->create(['referentiel_id' => $referentiel->id]);
        $competence = Competence::factory()->create(['referentiel_id' => $referentiel->id, 'kind' => 'transversale']);
        $level = Level::factory()->create(['referentiel_id' => $referentiel->id]);
        $brief->competences()->attach($competence->id, ['level_id' => $level->id]);
        Criterion::factory()->create(['competence_id' => $competence->id, 'level_id' => $level->id]);

        $repoPath = $this->makeRepo(['app/X.php' => "<?php\n"]);
        $studentRepo = StudentRepo::factory()->create(['clone_path' => $repoPath]);
        $run = Run::factory()->create(['student_repo_id' => $studentRepo->id, 'brief_id' => $brief->id]);

        $this->artisan('pass1:grade', ['run' => $run->id])
            ->expectsOutputToContain('no technical competences')
            ->assertFailed();

        $this->assertCount(0, $fake->calls, 'Grader was NOT called.');
    }
}
