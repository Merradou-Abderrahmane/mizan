<?php

namespace App\Livewire\Runs;

use App\Jobs\IntakeAndGradeRun;
use App\Models\Brief;
use App\Models\Run;
use App\Models\StudentRepo;
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

        $source = trim($this->source);

        // Pre-create the run synchronously so it shows immediately as `pending`
        // (design D3). Persona lands ONLY on StudentRepo here and is never passed
        // to the job/runner/prompt (R4) — the job carries just the run id.
        $studentRepo = StudentRepo::create([
            'name' => $this->deriveName($source),
            'clone_path' => $source,
            'operator_persona' => $this->persona !== '' ? $this->persona : null,
        ]);

        $run = Run::create([
            'student_repo_id' => $studentRepo->id,
            'brief_id' => (int) $this->brief_id,
            'status' => 'pending',
        ]);

        IntakeAndGradeRun::dispatch($run->id);

        session()->flash('status', 'Run launched — grading in progress.');

        return $this->redirect(route('runs.show', $run), navigate: true);
    }

    private function deriveName(string $source): string
    {
        $base = basename(rtrim(str_replace('\\', '/', $source), '/'));
        $base = preg_replace('/\.git$/', '', $base) ?? $base;

        return $base !== '' ? $base : 'run';
    }

    public function render()
    {
        return view('livewire.runs.create', [
            'briefs' => Brief::orderBy('title')->get(),
        ]);
    }
}
