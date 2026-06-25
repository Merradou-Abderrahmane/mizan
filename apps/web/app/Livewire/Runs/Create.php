<?php

namespace App\Livewire\Runs;

use App\Jobs\IntakeAndGradeRun;
use App\Models\Brief;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('New run · Mizan')]
class Create extends Component
{
    public ?int $brief_id = null;

    public string $source = '';

    public string $persona = '';

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'brief_id' => ['required', 'integer', 'exists:briefs,id'],
            'source' => ['required', 'string', 'max:1024'],
            'persona' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function submit()
    {
        $this->validate();

        // Async (design D2): the heavy runner + LLM work runs on the queue; the
        // submit returns immediately. Persona is handed to intake exactly as the
        // repo:intake command does — it lands only on StudentRepo (R4).
        IntakeAndGradeRun::dispatch(
            source: trim($this->source),
            briefId: (int) $this->brief_id,
            persona: $this->persona !== '' ? $this->persona : null,
        );

        session()->flash('status', 'Run started — it will appear in the list once intake completes.');

        return $this->redirect(route('runs.index'), navigate: true);
    }

    public function render()
    {
        return view('livewire.runs.create', [
            'briefs' => Brief::orderBy('title')->get(),
        ]);
    }
}
