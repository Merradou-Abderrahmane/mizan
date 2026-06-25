<div wire:poll.5s>
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-semibold">Runs</h1>
            <p class="text-sm text-base-content/60">Pass 1 grading runs — review evidence and finalize verdicts.</p>
        </div>
        <a href="{{ route('runs.create') }}" class="btn btn-primary">New run</a>
    </div>

    @if ($runs->isEmpty())
        <div class="card bg-base-100 border border-base-300">
            <div class="card-body items-center text-center py-16">
                <h2 class="card-title">No runs yet</h2>
                <p class="text-base-content/60">Launch a run to grade a student repository against a brief.</p>
                <a href="{{ route('runs.create') }}" class="btn btn-primary mt-2">New run</a>
            </div>
        </div>
    @else
        <div class="card bg-base-100 border border-base-300">
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Student repo</th>
                            <th>Brief</th>
                            <th>Status</th>
                            <th>Graded</th>
                            <th>Finalized</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($runs as $run)
                            @php $total = $run->brief?->competences->count() ?? 0; @endphp
                            <tr class="hover">
                                <td class="font-medium">{{ $run->studentRepo?->name ?? '—' }}</td>
                                <td>{{ $run->brief?->title ?? '—' }}</td>
                                <td><x-run-status :status="$run->status" /></td>
                                <td class="text-sm text-base-content/60">
                                    {{ $run->ended_at?->diffForHumans() ?? '—' }}
                                </td>
                                <td>
                                    <span class="text-sm {{ $total > 0 && $run->finalized_count === $total ? 'text-success font-medium' : 'text-base-content/70' }}">
                                        {{ $run->finalized_count }}/{{ $total }}
                                    </span>
                                </td>
                                <td class="text-right">
                                    <a href="{{ route('runs.show', $run) }}" class="btn btn-sm btn-ghost">Open</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
