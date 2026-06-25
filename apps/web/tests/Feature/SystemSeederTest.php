<?php

namespace Tests\Feature;

use App\Models\Brief;
use App\Models\Competence;
use App\Models\Criterion;
use App\Models\Level;
use App\Models\Pass1CompetenceResult;
use App\Models\Referentiel;
use App\Models\Run;
use App\Models\StudentRepo;
use Database\Seeders\SystemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Exercises SystemSeeder — the idempotent domain-data seeder shipped by
 * the pass1-smoke-harness change. Verifies the full référentiel graph,
 * idempotency, R4 (no persona), R3-brief-scope (only technique competences
 * attached to the brief), and that the criterion descriptions reference the
 * ThreadForge brief's performance families.
 *
 * Uses in-memory SQLite via RefreshDatabase (the default phpunit.xml env).
 */
class SystemSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_full_graph(): void
    {
        (new SystemSeeder())->run();

        $this->assertSame(1, Referentiel::count(),            'Exactly one Référentiel seeded.');
        $this->assertSame(3, Level::count(),                 'Exactly three Levels seeded (N1/N2/N3).');
        $this->assertSame(11, Competence::count(),           'Exactly eleven Competences seeded.');
        $this->assertSame(5, Competence::where('kind', 'technique')->count(),     'Five technique competences.');
        $this->assertSame(6, Competence::where('kind', 'transversale')->count(),  'Six transversale competences.');
        $this->assertSame(33, Criterion::count(),           '33 Criteria = 11 competences × 3 levels.');
        $this->assertSame(1, Brief::count(),                'Exactly one Brief seeded (ThreadForge).');

        $brief = Brief::first();
        $this->assertSame(5, $brief->competences()->count(),         'Five brief_competence pivot rows.');
        $this->assertSame(5, $brief->competences()->technical()->count(), 'All attached competences are technique.');

        // R4 + R3 — nothing down-stream is seeded.
        $this->assertSame(0, StudentRepo::count(),              'Seeder must not seed StudentRepo (R4).');
        $this->assertSame(0, Run::count(),                      'Seeder must not seed Run.');
        $this->assertSame(0, Pass1CompetenceResult::count(),    'Seeder must not seed Pass1 rollups.');
        $this->assertSame(0, DB::table('evidence')->count(),    'No Evidence rows.');
        $this->assertSame(0, DB::table('drafts')->count(),      'No Draft rows.');
    }

    public function test_seeder_is_idempotent(): void
    {
        (new SystemSeeder())->run();
        $countsAfterFirst = $this->snapshotCounts();

        (new SystemSeeder())->run();
        $countsAfterSecond = $this->snapshotCounts();

        $this->assertSame($countsAfterFirst, $countsAfterSecond, 'Re-running the seeder must not duplicate rows or violate unique constraints.');

        // Pivot attaches also must not blow up on the unique(brief_id, competence_id) constraint.
        $brief = Brief::first();
        $this->assertSame(5, $brief->competences()->count(), 'Pivot row count stable after re-run.');
    }

    public function test_seeder_attaches_target_levels_only_to_technique_competences(): void
    {
        (new SystemSeeder())->run();

        $expected = SystemSeeder::targetLevels();
        $brief = Brief::first();

        foreach ($expected as $competenceCode => $levelCode) {
            $competence = Competence::where('code', $competenceCode)->first();
            $this->assertNotNull($competence, "Competence {$competenceCode} must be seeded.");
            $this->assertSame('technique', $competence->kind, "Competence {$competenceCode} must be kind=technique.");

            $attached = $brief->competences()->where('competences.id', $competence->id)->first();
            $this->assertNotNull($attached, "Competence {$competenceCode} must be attached to the brief.");
            $this->assertNotNull($attached->pivot->level_id, 'Pivot level_id must be set.');

            $level = Level::find($attached->pivot->level_id);
            $this->assertNotNull($level, 'Pivot level_id must resolve to a real Level.');
            $this->assertSame($levelCode, $level->code, "Competence {$competenceCode} target level must be {$levelCode}.");
        }

        // Transversales must NOT be attached to the brief.
        $transversalesAttached = $brief->competences()->where('kind', 'transversale')->count();
        $this->assertSame(0, $transversalesAttached, 'No transversale competence must be attached to the brief.');
    }

    public function test_seeder_does_not_seed_persona(): void
    {
        (new SystemSeeder())->run();

        $this->assertSame(0, StudentRepo::count(), 'No StudentRepo (and therefore no operator_persona row) seeded.');

        // Schema-level: the only table that has an operator_persona column is student_repos (Change B).
        // Since we seed zero student_repos, no row anywhere carries operator_persona data — R4 enforced.
        $personaColumns = DB::getSchemaBuilder()->getColumnListing('student_repos');
        $this->assertContains('operator_persona', $personaColumns, 'student_repos.operator_persona column must exist (Change B).');

        $this->assertSame(0, StudentRepo::whereNotNull('operator_persona')->count(), 'No non-null persona in the seeded graph.');
    }

    public function test_criterion_descriptions_reference_brief_performance_families(): void
    {
        (new SystemSeeder())->run();

        $techniqueCodes = ['T-C5', 'T-C6', 'T-C3', 'T-C7', 'T-C9'];
        $codes = [];
        foreach ($techniqueCodes as $c) {
            array_push($codes, "{$c}-N1", "{$c}-N2", "{$c}-N3");
        }

        $descriptions = Criterion::query()
            ->whereIn('code', $codes)
            ->orWhereHas('competence', fn ($q) => $q->where('code', 'TR-C5')) // Documentation transversale references Scribe.
            ->pluck('description')
            ->implode("\n");

        $familyRegex = '/(Sanctum|API Resources?|N\+1|Queue|202 Accepted|structured output|JSON cast|function[- ]calling|tool|conversation|atomic commit|Scribe)/i';

        $this->assertMatchesRegularExpression(
            $familyRegex,
            $descriptions,
            'Seeded criterion descriptions must reference at least one of the ThreadForge brief performance families.',
        );
    }

    /**
     * @return array<string, int>
     */
    private function snapshotCounts(): array
    {
        return [
            'referentiel'      => Referentiel::count(),
            'level'            => Level::count(),
            'competence'       => Competence::count(),
            'criterion'         => Criterion::count(),
            'brief'            => Brief::count(),
            'brief_competence' => DB::table('brief_competence')->count(),
            'student_repo'     => StudentRepo::count(),
            'run'              => Run::count(),
        ];
    }
}