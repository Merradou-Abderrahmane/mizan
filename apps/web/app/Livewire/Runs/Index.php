<?php

namespace App\Livewire\Runs;

use App\Models\Run;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Runs · Mizan')]
class Index extends Component
{
    public function render()
    {
        $runs = Run::query()
            ->with([
                'studentRepo',
                'brief.competences' => fn ($q) => $q->technical(),
            ])
            ->withCount([
                'pass1CompetenceResults as finalized_count' => fn ($q) => $q->whereNotNull('finalized_at'),
            ])
            ->latest()
            ->get();

        return view('livewire.runs.index', ['runs' => $runs]);
    }
}
