<?php

namespace App\Livewire\Runs;

use App\Models\Pass1CompetenceResult;
use Livewire\Component;

/**
 * The R1 finalization control for one competence. Writes ONLY the operator
 * columns (operator_status / operator_note / finalized_at) on the existing
 * pass1_competence_results row — never any AI column (design D3). Reopen nulls
 * the verdict so finalVerdict() returns null again until re-saved.
 */
class CompetenceFinalize extends Component
{
    public int $resultId;

    /** 'valide' | 'non valide' | null — null means no verdict selected yet (R1). */
    public ?string $status = null;

    public string $note = '';

    public bool $finalized = false;

    public function mount(int $resultId): void
    {
        $result = Pass1CompetenceResult::findOrFail($resultId);

        $this->resultId = $resultId;
        $this->status = $result->operator_status;
        $this->note = $result->operator_note ?? '';
        $this->finalized = $result->finalized_at !== null;
    }

    public function finalize(): void
    {
        $this->validate([
            'status' => ['required', 'in:valide,non valide'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $result = Pass1CompetenceResult::findOrFail($this->resultId);
        $result->update([
            'operator_status' => $this->status,
            'operator_note' => $this->note !== '' ? $this->note : null,
            'finalized_at' => now(),
        ]);

        $this->finalized = true;
        $this->dispatch('competence-finalized');
    }

    public function reopen(): void
    {
        $result = Pass1CompetenceResult::findOrFail($this->resultId);
        $result->update([
            'operator_status' => null,
            'operator_note' => null,
            'finalized_at' => null,
        ]);

        $this->status = null;
        $this->note = '';
        $this->finalized = false;
        $this->dispatch('competence-finalized');
    }

    public function render()
    {
        return view('livewire.runs.competence-finalize', [
            'result' => Pass1CompetenceResult::findOrFail($this->resultId),
        ]);
    }
}
