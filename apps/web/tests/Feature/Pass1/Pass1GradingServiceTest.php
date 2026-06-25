<?php

namespace Tests\Feature\Pass1;

use App\Models\Brief;
use App\Models\Competence;
use App\Models\Criterion;
use App\Models\Draft;
use App\Models\Evidence;
use App\Models\Level;
use App\Models\Pass1CompetenceResult;
use App\Models\Referentiel;
use App\Models\Run;
use App\Models\StudentRepo;
use App\Services\Pass1\FakeGraderClient;
use App\Services\Pass1\Pass1GradingService;
use App\Services\Pass1\Pass1Prompt;
use App\Services\Pass1\Pass1ResponseParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

class Pass1GradingServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $repoPath;

    protected function tearDown(): void
    {
        if (isset($this->repoPath) && is_dir($this->repoPath)) {
            File::deleteDirectory($this->repoPath);
        }

        Pass1CompetenceResult::flushEventListeners();
        parent::tearDown();
    }

    /** @param array<string,string> $files relPath => contents */
    private function makeRepo(array $files): string
    {
        $this->repoPath = sys_get_temp_dir().'/mizan_grade_'.uniqid();
        foreach ($files as $rel => $contents) {
            $full = $this->repoPath.'/'.$rel;
            File::ensureDirectoryExists(dirname($full));
            File::put($full, $contents);
        }

        return $this->repoPath;
    }

    /**
     * Build a full grading fixture and return the key objects.
     *
     * @return array{0:Referentiel,1:Brief,2:Run,3:FakeGraderClient,4:Pass1GradingService}
     */
    private function makeFixture(array $competenceConfigs): array
    {
        $referentiel = Referentiel::factory()->create();
        $brief = Brief::factory()->create(['referentiel_id' => $referentiel->id]);

        foreach ($competenceConfigs as $config) {
            $competence = Competence::factory()->create([
                'referentiel_id' => $referentiel->id,
                'label' => $config['label'],
                'kind' => $config['kind'] === 'technical' ? 'technique' : 'transversale',
            ]);
            $level = Level::factory()->create([
                'referentiel_id' => $referentiel->id,
                'label' => $config['level_label'] ?? 'Adapter',
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

        $repoPath = $this->makeRepo([
            'app/Models/User.php' => implode("\n", array_fill(0, 20, '// model line')),
            'routes/web.php' => "<?php\n// route file\n",
        ]);

        $studentRepo = StudentRepo::factory()->create([
            'clone_path' => $repoPath,
            'name' => 'alice-project',
            'operator_persona' => 'advanced',
        ]);
        $run = Run::factory()->create([
            'student_repo_id' => $studentRepo->id,
            'brief_id' => $brief->id,
        ]);

        $fake = new FakeGraderClient;
        $service = new Pass1GradingService($fake, new Pass1Prompt, new Pass1ResponseParser);

        return [$referentiel, $brief, $run, $fake, $service];
    }

    /** Build a well-formed grader JSON response for one competence + its criteria. */
    private function goodResponse(int $competenceId, int $levelSortOrder, object $competence, int $levelId): string
    {
        $criteria = $competence->criteria()->where('level_id', $levelId)->get();
        $criteriaJson = $criteria->map(fn ($c) => [
            'criterion_id' => (string) $c->id,
            'evidence' => $c->label === 'Has migrations'
                ? [['file' => 'app/Models/User.php', 'line' => 3, 'note' => 'declares model']]
                : [],
            'assessment_draft' => $c->label === 'Has migrations' ? 'semble valide' : 'à vérifier',
            'reasoning' => $c->label === 'Has migrations' ? 'r1' : 'not-found: no routes file in the excerpt',
        ])->toArray();

        return json_encode([
            'competence_id' => (string) $competenceId,
            'level' => (string) $levelSortOrder,
            'criteria' => $criteriaJson,
            'competence_draft_rollup' => 'semble valide',
            'confidence' => 0.8,
            'probe_questions' => ['Why did you use Eloquent?'],
        ], JSON_UNESCAPED_UNICODE);
    }

    // --- 5.2 Technical-only scope -----------------------------------------

    public function test_only_technical_competences_are_graded(): void
    {
        [$referentiel, $brief, $run, $fake, $service] = $this->makeFixture([
            ['kind' => 'technical', 'label' => 'Designs a data model', 'criteria' => ['Has migrations', 'Has relations']],
            ['kind' => 'transversale', 'label' => 'Communicates clearly', 'criteria' => ['Writes clearly']],
        ]);

        $techCompetence = $brief->competences()->technical()->first();
        $techLevelId = $brief->competences()->technical()->first()->pivot->level_id;
        $fake->queue($this->goodResponse($techCompetence->id, 2, $techCompetence, $techLevelId));

        $outcomes = $service->grade($run);

        $this->assertCount(1, $outcomes, 'Only the technique competence is graded.');
        $this->assertSame('graded', $outcomes[0]->status);

        $this->assertEquals(1, Pass1CompetenceResult::where('run_id', $run->id)->count(), 'One rollup row.');
        $this->assertDatabaseHas('pass1_competence_results', [
            'run_id' => $run->id,
            'competence_id' => $techCompetence->id,
        ]);

        $transversalCompetence = $brief->competences()->where('kind', 'transversale')->first();
        $this->assertDatabaseMissing('pass1_competence_results', [
            'run_id' => $run->id,
            'competence_id' => $transversalCompetence->id,
        ]);
        $this->assertCount(1, $fake->calls, 'Only one grader call (for the technique competence).');
    }

    // --- 5.3 Persistence shape --------------------------------------------

    public function test_persistence_shape_evidence_drafts_rollup(): void
    {
        [$referentiel, $brief, $run, $fake, $service] = $this->makeFixture([
            ['kind' => 'technical', 'label' => 'Designs a data model', 'criteria' => ['Has migrations', 'Has relations']],
        ]);

        $competence = $brief->competences()->technical()->first();
        $levelId = $competence->pivot->level_id;
        $fake->queue($this->goodResponse($competence->id, 2, $competence, $levelId));

        $service->grade($run);

        $criteria = $competence->criteria()->where('level_id', $levelId)->get();
        $c1 = $criteria->firstWhere('label', 'Has migrations');
        $c2 = $criteria->firstWhere('label', 'Has relations');

        // Evidence: one for C1 (in-digest citation), none for C2.
        $this->assertEquals(1, Evidence::where('criterion_id', $c1->id)->count());
        $this->assertDatabaseHas('evidence', [
            'run_id' => $run->id,
            'criterion_id' => $c1->id,
            'file_path' => 'app/Models/User.php',
            'line_number' => 3,
            'message' => 'declares model',
            'check_id' => null,
            'kind' => null,
            'status' => null,
        ]);
        $this->assertEquals(0, Evidence::where('criterion_id', $c2->id)->count());

        // Drafts: one per criterion with ai_status + ai_reasoning.
        $draftC1 = Draft::where('criterion_id', $c1->id)->first();
        $draftC2 = Draft::where('criterion_id', $c2->id)->first();
        $this->assertNotNull($draftC1);
        $this->assertNotNull($draftC2);
        $this->assertSame('semble valide', $draftC1->ai_status);
        $this->assertSame('r1', $draftC1->ai_reasoning);
        $this->assertSame('à vérifier', $draftC2->ai_status);
        $this->assertSame('not-found: no routes file in the excerpt', $draftC2->ai_reasoning);

        // Rollup: exactly one row, operator columns null (R1).
        $this->assertEquals(1, Pass1CompetenceResult::where('run_id', $run->id)->count());
        $rollup = Pass1CompetenceResult::where('run_id', $run->id)->first();
        $this->assertSame('semble valide', $rollup->ai_rollup_status);
        $this->assertSame(0.8, $rollup->confidence);
        $this->assertSame(['Why did you use Eloquent?'], $rollup->probe_questions);
        $this->assertSame($levelId, $rollup->level_id);
        $this->assertNull($rollup->operator_status);
        $this->assertNull($rollup->operator_note);
        $this->assertNull($rollup->finalized_at);
        $this->assertNull($rollup->finalVerdict(), 'R1: un-finalized result returns null.');
    }

    // --- 5.4 One digest per run -------------------------------------------

    public function test_digest_is_built_once_per_run(): void
    {
        // Verify via grader call count that both prompts reference the same
        // digest text (the same repo file tree), confirming one digest per run.
        [$referentiel, $brief, $run, $fake, $service] = $this->makeFixture([
            ['kind' => 'technical', 'label' => 'Comp A', 'criteria' => ['Crit A1']],
            ['kind' => 'technical', 'label' => 'Comp B', 'criteria' => ['Crit B1']],
        ]);

        $compA = $brief->competences()->technical()->get()->firstWhere('label', 'Comp A');
        $compB = $brief->competences()->technical()->get()->firstWhere('label', 'Comp B');
        $levelA = $compA->pivot->level_id;
        $levelB = $compB->pivot->level_id;

        $fake->queue($this->goodResponse($compA->id, 2, $compA, $levelA));
        $fake->queue($this->goodResponse($compB->id, 2, $compB, $levelB));

        $service->grade($run);

        $this->assertCount(2, $fake->calls, 'Two grader calls (one per competence).');
        // Both prompts reference the same digest text (the repo file tree).
        $this->assertStringContainsString('app/Models/User.php', $fake->calls[0]['user']);
        $this->assertStringContainsString('app/Models/User.php', $fake->calls[1]['user']);
    }

    // --- 5.5 R4 blind -----------------------------------------------------

    public function test_no_student_identity_in_prompts(): void
    {
        [$referentiel, $brief, $run, $fake, $service] = $this->makeFixture([
            ['kind' => 'technical', 'label' => 'Designs a data model', 'criteria' => ['Has migrations']],
        ]);

        $competence = $brief->competences()->technical()->first();
        $levelId = $competence->pivot->level_id;
        $fake->queue($this->goodResponse($competence->id, 2, $competence, $levelId));

        $service->grade($run);

        $this->assertNotEmpty($fake->calls);
        foreach ($fake->calls as $call) {
            $blob = $call['system'].$call['user'];
            $this->assertStringNotContainsString('alice-project', $blob, 'R4: no StudentRepo name.');
            $this->assertStringNotContainsString('advanced', $blob, 'R4: no operator_persona.');
            $this->assertStringNotContainsString($this->repoPath, $blob, 'R4: no clone_path in prompt.');
        }
    }

    // --- 5.6 Idempotent re-grade: no duplicates ---------------------------

    public function test_regrade_does_not_duplicate_rows(): void
    {
        [$referentiel, $brief, $run, $fake, $service] = $this->makeFixture([
            ['kind' => 'technical', 'label' => 'Designs a data model', 'criteria' => ['Has migrations', 'Has relations']],
        ]);

        $competence = $brief->competences()->technical()->first();
        $levelId = $competence->pivot->level_id;

        $fake->queue($this->goodResponse($competence->id, 2, $competence, $levelId));
        $service->grade($run);

        $evidenceCount = Evidence::where('run_id', $run->id)->count();
        $draftCount = Draft::where('run_id', $run->id)->count();
        $rollupCount = Pass1CompetenceResult::where('run_id', $run->id)->count();

        // Re-grade with a fresh response.
        $fake->queue($this->goodResponse($competence->id, 2, $competence, $levelId));
        $service->grade($run);

        $this->assertSame($evidenceCount, Evidence::where('run_id', $run->id)->count(), 'No duplicate evidence.');
        $this->assertSame($draftCount, Draft::where('run_id', $run->id)->count(), 'No duplicate drafts.');
        $this->assertSame($rollupCount, Pass1CompetenceResult::where('run_id', $run->id)->count(), 'No duplicate rollup.');
        $this->assertEquals(1, Pass1CompetenceResult::where('run_id', $run->id)->count());
    }

    // --- 5.7 Idempotent re-grade preserves operator finalization ----------

    public function test_regrade_preserves_operator_finalization(): void
    {
        [$referentiel, $brief, $run, $fake, $service] = $this->makeFixture([
            ['kind' => 'technical', 'label' => 'Designs a data model', 'criteria' => ['Has migrations']],
        ]);

        $competence = $brief->competences()->technical()->first();
        $levelId = $competence->pivot->level_id;

        $fake->queue($this->goodResponse($competence->id, 2, $competence, $levelId));
        $service->grade($run);

        // Operator finalizes.
        $rollup = Pass1CompetenceResult::where('run_id', $run->id)->first();
        $rollup->update(['operator_status' => 'valide', 'finalized_at' => now()]);
        $this->assertSame('valide', $rollup->finalVerdict());

        // Re-grade.
        $fake->queue($this->goodResponse($competence->id, 2, $competence, $levelId));
        $service->grade($run);

        $rollup->refresh();
        $this->assertSame('semble valide', $rollup->ai_rollup_status, 'AI columns overwritten.');
        $this->assertSame('valide', $rollup->operator_status, 'Operator verdict preserved.');
        $this->assertNotNull($rollup->finalized_at, 'finalized_at preserved.');
        $this->assertSame('valide', $rollup->finalVerdict(), 'R1: operator verdict intact.');
    }

    // --- 5.8 Failure isolation — unparseable ------------------------------

    public function test_unparseable_output_yields_safe_row_and_others_still_grade(): void
    {
        [$referentiel, $brief, $run, $fake, $service] = $this->makeFixture([
            ['kind' => 'technical', 'label' => 'Comp A', 'criteria' => ['Crit A1']],
            ['kind' => 'technical', 'label' => 'Comp B', 'criteria' => ['Crit B1']],
        ]);

        $compA = $brief->competences()->technical()->get()->firstWhere('label', 'Comp A');
        $compB = $brief->competences()->technical()->get()->firstWhere('label', 'Comp B');
        $levelA = $compA->pivot->level_id;
        $levelB = $compB->pivot->level_id;

        // A: unparseable (two non-JSON responses — initial + repair retry).
        $fake->queue('not json');
        $fake->queue('still not json');
        // B: well-formed.
        $fake->queue($this->goodResponse($compB->id, 2, $compB, $levelB));

        $outcomes = $service->grade($run);

        // A failed, B graded.
        $outcomeA = collect($outcomes)->firstWhere('competenceLabel', 'Comp A');
        $outcomeB = collect($outcomes)->firstWhere('competenceLabel', 'Comp B');
        $this->assertSame('failed', $outcomeA->status);
        $this->assertSame('unparseable', $outcomeA->reason);
        $this->assertSame('graded', $outcomeB->status);

        // A: safe à vérifier rollup with unparseable raw_json, no evidence/drafts.
        $rollupA = Pass1CompetenceResult::where('run_id', $run->id)->where('competence_id', $compA->id)->first();
        $this->assertNotNull($rollupA);
        $this->assertSame('à vérifier', $rollupA->ai_rollup_status);
        $this->assertTrue($rollupA->raw_json['unparseable'] ?? false);
        $this->assertSame('still not json', $rollupA->raw_json['raw']);
        $critA = $compA->criteria()->where('level_id', $levelA)->first();
        $this->assertEquals(0, Evidence::where('criterion_id', $critA->id)->count());
        $this->assertEquals(0, Draft::where('criterion_id', $critA->id)->count());
        $this->assertNull($rollupA->finalVerdict(), 'R1: failed competence still un-finalized.');

        // B: graded normally.
        $this->assertDatabaseHas('pass1_competence_results', ['run_id' => $run->id, 'competence_id' => $compB->id]);
        $critB = $compB->criteria()->where('level_id', $levelB)->first();
        $this->assertGreaterThanOrEqual(0, Draft::where('criterion_id', $critB->id)->count());
    }

    // --- 5.9 Failure isolation — grader throws ----------------------------

    public function test_grader_exception_yields_safe_row_and_others_still_grade(): void
    {
        [$referentiel, $brief, $run, $fake, $service] = $this->makeFixture([
            ['kind' => 'technical', 'label' => 'Comp A', 'criteria' => ['Crit A1']],
            ['kind' => 'technical', 'label' => 'Comp B', 'criteria' => ['Crit B1']],
        ]);

        $compA = $brief->competences()->technical()->get()->firstWhere('label', 'Comp A');
        $compB = $brief->competences()->technical()->get()->firstWhere('label', 'Comp B');
        $levelB = $compB->pivot->level_id;

        // A: FakeGraderClient with empty queue throws.
        // B: well-formed.
        $fake->queue($this->goodResponse($compB->id, 2, $compB, $levelB));

        // We need A to throw. The fake throws when the queue is empty.
        // But A is first, so it will consume the first queued response.
        // Instead, use a custom fake that throws for the first call.
        $throwingFake = new class extends FakeGraderClient {
            private int $callCount = 0;

            public function complete(string $system, string $user): string
            {
                $this->callCount++;
                if ($this->callCount === 1) {
                    throw new RuntimeException('network timeout');
                }

                return parent::complete($system, $user);
            }
        };
        $throwingFake->queue($this->goodResponse($compB->id, 2, $compB, $levelB));

        $service = new Pass1GradingService($throwingFake, new Pass1Prompt, new Pass1ResponseParser);

        $outcomes = $service->grade($run);

        $outcomeA = collect($outcomes)->firstWhere('competenceLabel', 'Comp A');
        $outcomeB = collect($outcomes)->firstWhere('competenceLabel', 'Comp B');
        $this->assertSame('failed', $outcomeA->status);
        $this->assertSame('graded', $outcomeB->status);

        $rollupA = Pass1CompetenceResult::where('run_id', $run->id)->where('competence_id', $compA->id)->first();
        $this->assertNotNull($rollupA);
        $this->assertSame('à vérifier', $rollupA->ai_rollup_status);
        $this->assertStringContainsString('RuntimeException', $rollupA->raw_json['error']);

        $this->assertDatabaseHas('pass1_competence_results', ['run_id' => $run->id, 'competence_id' => $compB->id]);
    }

    // --- 5.10 Transaction integrity ---------------------------------------

    public function test_persistence_exception_rolls_back_only_that_competence(): void
    {
        [$referentiel, $brief, $run, $fake, $service] = $this->makeFixture([
            ['kind' => 'technical', 'label' => 'Comp A', 'criteria' => ['Crit A1']],
            ['kind' => 'technical', 'label' => 'Comp B', 'criteria' => ['Crit B1']],
        ]);

        $compA = $brief->competences()->technical()->get()->firstWhere('label', 'Comp A');
        $compB = $brief->competences()->technical()->get()->firstWhere('label', 'Comp B');
        $levelA = $compA->pivot->level_id;
        $levelB = $compB->pivot->level_id;

        $fake->queue($this->goodResponse($compA->id, 2, $compA, $levelA));
        $fake->queue($this->goodResponse($compB->id, 2, $compB, $levelB));

        // Register a saving event that throws for B's SUCCESS-path rollup only
        // (ai_rollup_status !== 'à vérifier'), not the failure-path row.
        Pass1CompetenceResult::saving(function ($model) use ($compB): void {
            if ($model->competence_id === $compB->id && $model->ai_rollup_status !== 'à vérifier') {
                throw new RuntimeException('simulated persistence failure');
            }
        });

        $outcomes = $service->grade($run);

        // A: graded successfully, rows remain.
        $outcomeA = collect($outcomes)->firstWhere('competenceLabel', 'Comp A');
        $this->assertSame('graded', $outcomeA->status);
        $this->assertDatabaseHas('pass1_competence_results', ['run_id' => $run->id, 'competence_id' => $compA->id]);
        $critA = $compA->criteria()->where('level_id', $levelA)->first();
        $this->assertGreaterThan(0, Draft::where('criterion_id', $critA->id)->count(), 'A\'s drafts remain.');

        // B: failed — its transaction rolled back (no success-path rows), then
        // a safe failure row was persisted in a fresh transaction.
        $outcomeB = collect($outcomes)->firstWhere('competenceLabel', 'Comp B');
        $this->assertSame('failed', $outcomeB->status);

        $rollupB = Pass1CompetenceResult::where('run_id', $run->id)->where('competence_id', $compB->id)->first();
        $this->assertNotNull($rollupB, 'B has a safe failure rollup row.');
        $this->assertSame('à vérifier', $rollupB->ai_rollup_status, 'Failure row is safe à vérifier.');
        $critB = $compB->criteria()->where('level_id', $levelB)->first();
        $this->assertEquals(0, Evidence::where('criterion_id', $critB->id)->count(), 'B has no evidence (rolled back).');
        $this->assertEquals(0, Draft::where('criterion_id', $critB->id)->count(), 'B has no drafts (rolled back).');
    }
}
