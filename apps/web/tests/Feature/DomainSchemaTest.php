<?php

namespace Tests\Feature;

use App\Models\Brief;
use App\Models\Competence;
use App\Models\Draft;
use App\Models\Evidence;
use App\Models\Level;
use App\Models\ProbeFlag;
use App\Models\Referentiel;
use App\Models\Run;
use App\Models\StudentRepo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DomainSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_evidence_table_has_no_student_identity_columns_r3_r4(): void
    {
        $this->assertFalse(
            Schema::hasColumn('evidence', 'student_repo_id'),
            'R3 violation: evidence table must not have a student_repo_id column (Pass 1 is blind).'
        );

        $this->assertFalse(
            Schema::hasColumn('evidence', 'operator_persona'),
            'R4 violation: evidence table must not have an operator_persona column (persona never enters Pass 1).'
        );
    }

    public function test_probe_flags_has_no_file_line_columns_and_evidence_has_no_pass2_columns_r3(): void
    {
        $this->assertFalse(
            Schema::hasColumn('probe_flags', 'file_path'),
            'R3 violation: probe_flags must not have file_path (Pass 2 flags are contextual, not file+line citations).'
        );

        $this->assertFalse(
            Schema::hasColumn('probe_flags', 'line_number'),
            'R3 violation: probe_flags must not have line_number (Pass 2 flags are contextual, not file+line citations).'
        );

        $this->assertFalse(
            Schema::hasColumn('evidence', 'context_payload'),
            'R3 violation: evidence must not have context_payload (Pass 1 is blind, no contextual data).'
        );
    }

    public function test_drafts_ai_status_defaults_to_a_verifier_on_raw_insert_r1(): void
    {
        $run = Run::factory()->create();
        $competence = Competence::factory()->create();

        DB::table('drafts')->insert([
            'run_id' => $run->id,
            'competence_id' => $competence->id,
            'updated_at' => now(),
            'created_at' => now(),
        ]);

        $draft = Draft::first();

        $this->assertSame('à vérifier', $draft->ai_status, 'R1: ai_status must default to "à vérifier", never "valide".');
        $this->assertNull($draft->operator_status, 'R1: operator_status must be null until the operator finalizes.');
        $this->assertNull($draft->finalized_at, 'R1: finalized_at must be null until the operator finalizes.');
    }

    public function test_student_repo_serialization_omits_operator_persona_r4(): void
    {
        $repo = StudentRepo::factory()->create(['operator_persona' => 'advanced']);

        $array = $repo->toArray();

        $this->assertArrayNotHasKey(
            'operator_persona',
            $array,
            'R4: operator_persona must be hidden from serialization (never in student-facing output).'
        );
    }

    public function test_draft_final_verdict_returns_null_when_un_finalized_r1(): void
    {
        $draft = Draft::factory()->create([
            'ai_status' => 'valide',
            'operator_status' => null,
            'finalized_at' => null,
        ]);

        $this->assertNull(
            $draft->finalVerdict(),
            'R1: an un-finalized draft must never be readable as a final verdict, even if ai_status is "valide".'
        );
    }

    public function test_draft_final_verdict_returns_operator_value_when_finalized_r1(): void
    {
        $draft = Draft::factory()->finalized('non valide')->create();

        $this->assertSame(
            'non valide',
            $draft->finalVerdict(),
            'R1: a finalized draft returns the operator\'s value, not the AI draft.'
        );
    }

    public function test_referentiel_has_many_levels_competences_briefs(): void
    {
        $referentiel = Referentiel::factory()->create();
        Level::factory()->count(2)->create(['referentiel_id' => $referentiel->id]);
        Competence::factory()->create(['referentiel_id' => $referentiel->id]);
        Brief::factory()->create(['referentiel_id' => $referentiel->id]);

        $this->assertCount(2, $referentiel->fresh()->levels);
        $this->assertCount(1, $referentiel->fresh()->competences);
        $this->assertCount(1, $referentiel->fresh()->briefs);
    }

    public function test_competence_belongs_to_referentiel_and_optionally_level(): void
    {
        $referentiel = Referentiel::factory()->create();
        $level = Level::factory()->create(['referentiel_id' => $referentiel->id]);
        $competence = Competence::factory()->create([
            'referentiel_id' => $referentiel->id,
            'level_id' => $level->id,
        ]);

        $this->assertSame($referentiel->id, $competence->referentiel->id);
        $this->assertSame($level->id, $competence->level->id);
    }

    public function test_competence_without_level_returns_null(): void
    {
        $competence = Competence::factory()->create(['level_id' => null]);

        $this->assertNull($competence->level);
    }

    public function test_brief_belongs_to_referentiel_and_casts_payload(): void
    {
        $referentiel = Referentiel::factory()->create();
        $brief = Brief::factory()->create([
            'referentiel_id' => $referentiel->id,
            'payload' => ['key' => 'value'],
        ]);

        $this->assertSame($referentiel->id, $brief->referentiel->id);
        $this->assertSame(['key' => 'value'], $brief->payload);
    }

    public function test_student_repo_has_many_runs(): void
    {
        $repo = StudentRepo::factory()->create();
        Run::factory()->count(2)->create(['student_repo_id' => $repo->id]);

        $this->assertCount(2, $repo->fresh()->runs);
    }

    public function test_run_belongs_to_student_repo_and_brief(): void
    {
        $repo = StudentRepo::factory()->create();
        $brief = Brief::factory()->create();
        $run = Run::factory()->create([
            'student_repo_id' => $repo->id,
            'brief_id' => $brief->id,
        ]);

        $this->assertSame($repo->id, $run->studentRepo->id);
        $this->assertSame($brief->id, $run->brief->id);
    }

    public function test_run_has_many_evidence_drafts_probe_flags(): void
    {
        $run = Run::factory()->create();
        Evidence::factory()->count(2)->create(['run_id' => $run->id]);
        Draft::factory()->create(['run_id' => $run->id]);
        ProbeFlag::factory()->create(['run_id' => $run->id]);

        $run = $run->fresh();
        $this->assertCount(2, $run->evidence);
        $this->assertCount(1, $run->drafts);
        $this->assertCount(1, $run->probeFlags);
    }

    public function test_evidence_belongs_to_run_and_competence(): void
    {
        $run = Run::factory()->create();
        $competence = Competence::factory()->create();
        $evidence = Evidence::factory()->create([
            'run_id' => $run->id,
            'competence_id' => $competence->id,
        ]);

        $this->assertSame($run->id, $evidence->run->id);
        $this->assertSame($competence->id, $evidence->competence->id);
    }

    public function test_draft_belongs_to_run_and_competence(): void
    {
        $run = Run::factory()->create();
        $competence = Competence::factory()->create();
        $draft = Draft::factory()->create([
            'run_id' => $run->id,
            'competence_id' => $competence->id,
        ]);

        $this->assertSame($run->id, $draft->run->id);
        $this->assertSame($competence->id, $draft->competence->id);
    }

    public function test_probe_flag_belongs_to_run_and_competence(): void
    {
        $run = Run::factory()->create();
        $competence = Competence::factory()->create();
        $flag = ProbeFlag::factory()->create([
            'run_id' => $run->id,
            'competence_id' => $competence->id,
        ]);

        $this->assertSame($run->id, $flag->run->id);
        $this->assertSame($competence->id, $flag->competence->id);
    }

    public function test_run_factory_auto_creates_parent_relations(): void
    {
        $run = Run::factory()->create();

        $this->assertNotNull($run->student_repo_id, 'Run factory should auto-create a StudentRepo.');
        $this->assertNotNull($run->brief_id, 'Run factory should auto-create a Brief.');
        $this->assertNotNull($run->studentRepo);
        $this->assertNotNull($run->brief);
    }

    public function test_draft_factory_defaults_to_safe_a_verifier_state_r1(): void
    {
        $draft = Draft::factory()->create();

        $this->assertSame('à vérifier', $draft->ai_status);
        $this->assertNull($draft->operator_status);
        $this->assertNull($draft->finalized_at);
    }

    public function test_student_repo_factory_defaults_persona_to_null_r4(): void
    {
        $repo = StudentRepo::factory()->create();

        $this->assertNull($repo->operator_persona, 'R4: persona is operator-set, never auto-filled.');
    }
}
