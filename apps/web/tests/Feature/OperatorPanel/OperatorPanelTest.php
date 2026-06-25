<?php

namespace Tests\Feature\OperatorPanel;

use App\Jobs\IntakeAndGradeRun;
use App\Livewire\Runs\CompetenceFinalize;
use App\Livewire\Runs\Create;
use App\Livewire\Runs\Index;
use App\Livewire\Runs\Show;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

class OperatorPanelTest extends TestCase
{
    use RefreshDatabase;

    private ?string $repoPath = null;

    protected function tearDown(): void
    {
        if ($this->repoPath !== null && is_dir($this->repoPath)) {
            File::deleteDirectory($this->repoPath);
        }
        parent::tearDown();
    }

    /**
     * Build a graded run: one technical + one transversal competence, the
     * technical one with a criterion + AI draft + verified evidence and a
     * pass1_competence_results rollup. Returns key handles.
     *
     * @return array{run:Run, technical:Competence, transversal:Competence, criterion:Criterion, result:Pass1CompetenceResult}
     */
    private function makeGradedRun(array $opts = []): array
    {
        $referentiel = Referentiel::factory()->create();
        $level = Level::factory()->create(['referentiel_id' => $referentiel->id, 'label' => 'Adapter', 'sort_order' => 2]);
        $brief = Brief::factory()->create(['referentiel_id' => $referentiel->id, 'title' => 'ThreadForge']);

        $technical = Competence::factory()->create([
            'referentiel_id' => $referentiel->id, 'label' => 'Concevoir une API REST', 'kind' => 'technique',
        ]);
        $transversal = Competence::factory()->create([
            'referentiel_id' => $referentiel->id, 'label' => 'Travailler en équipe', 'kind' => 'transversale',
        ]);
        $brief->competences()->attach($technical->id, ['level_id' => $level->id]);
        $brief->competences()->attach($transversal->id, ['level_id' => $level->id]);

        $criterion = Criterion::factory()->create([
            'competence_id' => $technical->id, 'level_id' => $level->id,
            'code' => 'C7.1', 'label' => 'Routes resource', 'sort_order' => 1,
        ]);

        // A real repo on disk so the excerpt can be read from source (D6).
        $this->repoPath = sys_get_temp_dir().'/mizan_panel_'.uniqid();
        File::ensureDirectoryExists($this->repoPath.'/src');
        File::put(
            $this->repoPath.'/src/Api.php',
            "<?php\nRoute::apiResource('threads', ThreadController::class);\n// end\n"
        );

        $studentRepo = StudentRepo::factory()->create([
            'name' => 'alice-project',
            'clone_path' => $opts['clone_path'] ?? $this->repoPath,
            'operator_persona' => 'N2 adapter',
        ]);
        $run = Run::factory()->create([
            'student_repo_id' => $studentRepo->id,
            'brief_id' => $brief->id,
            'status' => 'pass1_done',
            'ended_at' => now(),
        ]);

        Draft::create([
            'run_id' => $run->id, 'criterion_id' => $criterion->id,
            'ai_status' => 'semble valide', 'ai_reasoning' => 'routes look correct',
        ]);
        Evidence::create([
            'run_id' => $run->id, 'criterion_id' => $criterion->id,
            'file_path' => 'src/Api.php', 'line_number' => 2,
            'message' => 'AI claims this declares the resource routes',
        ]);

        $result = Pass1CompetenceResult::create([
            'run_id' => $run->id, 'competence_id' => $technical->id, 'level_id' => $level->id,
            'ai_rollup_status' => 'semble valide', 'confidence' => 0.72,
            'probe_questions' => ['How do you scope Sanctum tokens?'],
            'raw_json' => ['raw_response' => '{}'],
        ]);

        return compact('run', 'technical', 'transversal', 'criterion', 'result');
    }

    public function test_index_lists_runs(): void
    {
        $f = $this->makeGradedRun();

        Livewire::test(Index::class)
            ->assertSee('alice-project')
            ->assertSee('ThreadForge')
            ->assertSee('0/1'); // one technical competence, none finalized yet
    }

    public function test_show_displays_competence_criterion_and_hedged_ai(): void
    {
        $f = $this->makeGradedRun();

        Livewire::test(Show::class, ['run' => $f['run']])
            ->assertSee('Concevoir une API REST')
            ->assertSee('Routes resource')
            ->assertSee('semble valide')
            ->assertSee('routes look correct')
            ->assertSee('How do you scope Sanctum tokens?');
    }

    public function test_transversal_competence_is_not_a_graded_card(): void
    {
        $f = $this->makeGradedRun();

        Livewire::test(Show::class, ['run' => $f['run']])
            ->assertSee('Concevoir une API REST')
            ->assertDontSee('Travailler en équipe');
    }

    public function test_persona_is_shown_in_the_panel(): void
    {
        $f = $this->makeGradedRun();

        Livewire::test(Show::class, ['run' => $f['run']])
            ->assertSee('N2 adapter');
    }

    public function test_evidence_excerpt_is_source_line_and_ai_note_is_attributed(): void
    {
        $f = $this->makeGradedRun();

        Livewire::test(Show::class, ['run' => $f['run']])
            ->assertSee('src/Api.php:2')
            // the excerpt is the actual source line read from disk, not the claim
            ->assertSee("Route::apiResource('threads', ThreadController::class);")
            // the model's note is shown, but attributed as an AI note
            ->assertSee('AI note')
            ->assertSee('AI claims this declares the resource routes');
    }

    public function test_excerpt_omitted_when_source_unavailable(): void
    {
        $f = $this->makeGradedRun(['clone_path' => '/no/such/path']);

        Livewire::test(Show::class, ['run' => $f['run']])
            ->assertSee('src/Api.php:2')                 // citation still shown
            ->assertDontSee("Route::apiResource('threads'") // no fabricated excerpt
            ->assertSee('AI claims this declares the resource routes');
    }

    public function test_finalize_writes_operator_columns_and_leaves_ai_untouched(): void
    {
        $f = $this->makeGradedRun();
        $result = $f['result'];

        Livewire::test(CompetenceFinalize::class, ['resultId' => $result->id])
            ->set('status', 'non valide')
            ->set('note', 'queue never dispatched — confirmed orally')
            ->call('finalize')
            ->assertSet('finalized', true);

        $result->refresh();
        $this->assertSame('non valide', $result->operator_status);
        $this->assertSame('queue never dispatched — confirmed orally', $result->operator_note);
        $this->assertNotNull($result->finalized_at);
        $this->assertSame('non valide', $result->finalVerdict());
        // AI columns untouched (D3).
        $this->assertSame('semble valide', $result->ai_rollup_status);
    }

    public function test_reopen_clears_the_verdict(): void
    {
        $f = $this->makeGradedRun();
        $result = $f['result'];
        $result->update(['operator_status' => 'valide', 'operator_note' => 'x', 'finalized_at' => now()]);

        Livewire::test(CompetenceFinalize::class, ['resultId' => $result->id])
            ->call('reopen')
            ->assertSet('finalized', false)
            ->assertSet('status', null);

        $result->refresh();
        $this->assertNull($result->operator_status);
        $this->assertNull($result->finalized_at);
        $this->assertNull($result->finalVerdict());
        $this->assertSame('semble valide', $result->ai_rollup_status);
    }

    public function test_create_makes_pending_run_dispatches_and_redirects_to_detail(): void
    {
        Bus::fake();
        $referentiel = Referentiel::factory()->create();
        $brief = Brief::factory()->create(['referentiel_id' => $referentiel->id]);

        Livewire::test(Create::class)
            ->set('brief_id', $brief->id)
            ->set('source', 'storage/test-repos/ForgeCoreApi')
            ->set('persona', 'N2 adapter')
            ->call('submit');

        $run = Run::firstOrFail();
        $this->assertSame('pending', $run->status);
        $this->assertSame($brief->id, $run->brief_id);
        // Persona lands only on StudentRepo (R4); never passed to the job.
        $this->assertSame('N2 adapter', $run->studentRepo->operator_persona);

        Bus::assertDispatched(IntakeAndGradeRun::class);
    }

    public function test_create_rejects_unknown_brief_and_creates_no_run(): void
    {
        Bus::fake();

        Livewire::test(Create::class)
            ->set('brief_id', 999999)
            ->set('source', 'storage/test-repos/ForgeCoreApi')
            ->call('submit')
            ->assertHasErrors(['brief_id']);

        $this->assertSame(0, Run::count());
        Bus::assertNotDispatched(IntakeAndGradeRun::class);
    }
}
