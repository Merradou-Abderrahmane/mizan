<?php

namespace App\Livewire\Runs;

use App\Models\Run;
use App\Support\SourceExcerpt;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Run · Mizan')]
class Show extends Component
{
    public Run $run;

    public function mount(Run $run): void
    {
        $this->run = $run;
    }

    public function render()
    {
        $run = $this->run->fresh([
            'studentRepo', 'brief',
            'pass1CompetenceResults.competence',
            'pass1CompetenceResults.level',
        ]);

        $clonePath = $run->studentRepo?->clone_path;

        // Per-criterion drafts/evidence for this run, indexed for assembly.
        $drafts = $run->drafts()->get()->keyBy('criterion_id');
        $evidenceByCriterion = $run->evidence()->get()->groupBy('criterion_id');

        $cards = $run->pass1CompetenceResults
            ->sortBy(fn ($r) => $r->competence?->code ?? '')
            ->map(function ($result) use ($drafts, $evidenceByCriterion, $clonePath) {
                $criteria = $result->competence
                    ? $result->competence->criteria()
                        ->where('level_id', $result->level_id)
                        ->orderBy('sort_order')
                        ->get()
                    : collect();

                return [
                    'result' => $result,
                    'criteria' => $criteria->map(fn ($criterion) => [
                        'criterion' => $criterion,
                        'draft' => $drafts->get($criterion->id),
                        'evidence' => $evidenceByCriterion->get($criterion->id, collect())->map(fn ($e) => [
                            'model' => $e,
                            // D6: verified source line read read-only from disk;
                            // null (omitted) when the source is unavailable.
                            'excerpt' => SourceExcerpt::line($clonePath, $e->file_path, $e->line_number),
                        ]),
                    ]),
                ];
            })
            ->values();

        return view('livewire.runs.show', [
            'run' => $run,
            'cards' => $cards,
            'checks' => $run->runner_report_json['checks'] ?? [],
        ]);
    }
}
