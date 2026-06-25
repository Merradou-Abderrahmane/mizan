<?php

namespace Tests\Feature\OperatorPanel;

use App\Livewire\Runs\CompetenceFinalize;
use App\Models\Pass1CompetenceResult;
use App\Models\Referentiel;
use App\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * R1 visual grammar (design D4): AI advice can never render as a solid verdict
 * badge; the solid green/red badge is operator-only and only after finalization.
 */
class StatusBadgeR1Test extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> */
    public static function aiStatuses(): array
    {
        return [['semble valide'], ['semble non valide'], ['à vérifier']];
    }

    #[DataProvider('aiStatuses')]
    public function test_ai_status_never_renders_a_solid_verdict_badge(string $status): void
    {
        $html = Blade::render('<x-status-badge source="ai" :status="$status" />', ['status' => $status]);

        $this->assertStringContainsString('badge-outline', $html);
        $this->assertStringContainsString('italic', $html);
        $this->assertStringContainsString($status, $html);
        // The solid verdict classes are reserved for the operator — never AI.
        $this->assertStringNotContainsString('badge-success', $html);
        $this->assertStringNotContainsString('badge-error', $html);
    }

    public function test_operator_slot_is_empty_until_finalized(): void
    {
        $html = Blade::render('<x-status-badge source="operator" :status="null" :finalized="false" />');

        $this->assertStringContainsString('not finalized', $html);
        $this->assertStringNotContainsString('badge-success', $html);
        $this->assertStringNotContainsString('badge-error', $html);
    }

    public function test_operator_verdict_renders_solid_only_when_finalized(): void
    {
        $valide = Blade::render('<x-status-badge source="operator" status="valide" :finalized="true" />');
        $this->assertStringContainsString('badge-success', $valide);
        $this->assertStringContainsString('VALIDE', $valide);

        $nonValide = Blade::render('<x-status-badge source="operator" status="non valide" :finalized="true" />');
        $this->assertStringContainsString('badge-error', $nonValide);
        $this->assertStringContainsString('NON VALIDE', $nonValide);
    }

    public function test_unfinalized_competence_has_null_verdict_and_no_preselection(): void
    {
        $referentiel = Referentiel::factory()->create();
        $run = Run::factory()->create();
        $result = Pass1CompetenceResult::factory()->create([
            'run_id' => $run->id,
            'ai_rollup_status' => 'semble valide',
            'operator_status' => null,
            'finalized_at' => null,
        ]);

        // R1 in the model: no final verdict until the operator finalizes.
        $this->assertNull($result->finalVerdict());

        // R1 in the UI: no verdict radio is pre-selected.
        Livewire::test(CompetenceFinalize::class, ['resultId' => $result->id])
            ->assertSet('status', null)
            ->assertSet('finalized', false);
    }
}
