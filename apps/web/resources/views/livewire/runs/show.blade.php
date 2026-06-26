<div @if (in_array($run->status, ['pending', 'processing'])) wire:poll.5s @endif>
    {{-- Header --}}
    <div class="flex items-center gap-2 mb-1">
        <a href="{{ route('runs.index') }}" class="btn btn-ghost btn-sm">← Runs</a>
    </div>
    <div class="flex flex-wrap items-start justify-between gap-4 mb-6">
        <div>
            <h1 class="text-2xl font-semibold">{{ $run->studentRepo?->name ?? 'Run #'.$run->id }}</h1>
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-base-content/60 mt-1">
                <span>Brief: <span class="text-base-content/80">{{ $run->brief?->title ?? '—' }}</span></span>
                <span>·</span>
                <span>Run #{{ $run->id }}</span>
                @if ($run->ended_at)
                    <span>·</span><span>graded {{ $run->ended_at->diffForHumans() }}</span>
                @endif
                @if ($run->studentRepo?->operator_persona)
                    <span>·</span>
                    <span class="badge badge-ghost badge-sm" title="Operator-private (R4) — never sent to the runner or a Pass 1 prompt">
                        persona: {{ $run->studentRepo->operator_persona }}
                    </span>
                @endif
            </div>
        </div>
        <x-run-status :status="$run->status" />
    </div>

    {{-- Runner structural report (collapsed) --}}
    @if (! empty($checks))
        <div class="collapse collapse-arrow bg-base-100 border border-base-300 mb-6">
            <input type="checkbox" />
            <div class="collapse-title font-medium">Structural checks (runner)</div>
            <div class="collapse-content">
                <div class="flex flex-wrap gap-2">
                    @foreach ($checks as $check)
                        @php
                            $raw = $check['status'] ?? $check['passed'] ?? null;
                            $norm = is_bool($raw) ? ($raw ? 'pass' : 'fail') : strtolower((string) $raw);
                            $passed = in_array($norm, ['pass', 'passed', 'ok', 'true', '1'], true);
                            $skipped = in_array($norm, ['skip', 'skipped'], true);
                            // A skip is not a failure (e.g. a check skipped for a missing
                            // extension) — render it neutral, never as a red ✗.
                            $cls = $passed ? 'badge-success badge-outline' : ($skipped ? 'badge-ghost' : 'badge-error badge-outline');
                            $glyph = $passed ? '✓' : ($skipped ? '○' : '✗');
                        @endphp
                        <span class="badge {{ $cls }} gap-1">
                            {{ $glyph }} {{ $check['id'] ?? $check['name'] ?? 'check' }}
                        </span>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    {{-- Competences --}}
    @if ($cards->isEmpty())
        <div class="card bg-base-100 border border-base-300">
            <div class="card-body items-center text-center py-12">
                @if (in_array($run->status, ['pending', 'processing']))
                    <span class="loading loading-spinner loading-lg text-base-content/40"></span>
                    <h2 class="card-title">Grading in progress…</h2>
                    <p class="text-base-content/60">This page refreshes automatically as Pass 1 completes.</p>
                @else
                    <h2 class="card-title">No graded competences</h2>
                    <p class="text-base-content/60">This run has no technical competences to grade.</p>
                @endif
            </div>
        </div>
    @else
        <div class="text-sm text-base-content/60 mb-3">
            {{ $cards->count() }} technical {{ \Illuminate\Support\Str::plural('competence', $cards->count()) }}
            · {{ $cards->filter(fn ($c) => $c['result']->finalized_at !== null)->count() }} finalized
        </div>

        <div class="flex flex-col gap-5">
            @foreach ($cards as $card)
                @php $result = $card['result']; @endphp
                <div class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        {{-- Competence header --}}
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h3 class="text-lg font-semibold">
                                    @if ($result->competence?->code)<span class="text-base-content/50">{{ $result->competence->code }}</span> @endif
                                    {{ $result->competence?->label ?? 'Competence' }}
                                </h3>
                                <span class="text-sm text-base-content/60">target: {{ $result->level?->label ?? '—' }}</span>
                            </div>
                            <div class="text-right">
                                <div class="text-xs text-base-content/50 mb-1">AI rollup</div>
                                <x-status-badge source="ai" :status="$result->ai_rollup_status" />
                            </div>
                        </div>

                        {{-- Confidence --}}
                        @if ($result->confidence !== null)
                            <div class="flex items-center gap-2 text-sm">
                                <span class="text-base-content/60">AI confidence</span>
                                <progress class="progress progress-info w-40" value="{{ $result->confidence }}" max="1"></progress>
                                <span class="text-base-content/60">{{ number_format($result->confidence, 2) }}</span>
                            </div>
                        @endif

                        {{-- Criteria --}}
                        <div class="divide-y divide-base-200">
                            @foreach ($card['criteria'] as $row)
                                @php $criterion = $row['criterion']; $draft = $row['draft']; @endphp
                                <div class="py-3">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <span class="font-medium">
                                            @if ($criterion->code)<span class="text-base-content/50">{{ $criterion->code }}</span> @endif
                                            {{ $criterion->label }}
                                        </span>
                                        <x-status-badge source="ai" :status="$draft?->ai_status ?? 'à vérifier'" />
                                    </div>

                                    @if ($draft?->ai_reasoning)
                                        <p class="text-sm text-base-content/70 mt-1">{{ $draft->ai_reasoning }}</p>
                                    @endif

                                    {{-- Evidence: verified citation + real source excerpt + attributed AI note --}}
                                    @forelse ($row['evidence'] as $ev)
                                        <div class="mt-2 text-sm">
                                            <div class="flex items-center gap-2">
                                                <span class="badge badge-ghost badge-sm font-mono">
                                                    {{ $ev['model']->file_path }}:{{ $ev['model']->line_number }}
                                                </span>
                                            </div>
                                            @if ($ev['excerpt'] !== null)
                                                <pre class="mt-1 bg-base-200 rounded px-3 py-2 overflow-x-auto text-xs"><code>{{ $ev['excerpt'] }}</code></pre>
                                            @endif
                                            @if ($ev['model']->message)
                                                <p class="text-xs text-base-content/60 mt-1">
                                                    <span class="badge badge-outline badge-xs italic mr-1">AI note</span>
                                                    {{ $ev['model']->message }}
                                                </p>
                                            @endif
                                        </div>
                                    @empty
                                        <p class="text-xs text-base-content/40 mt-1">no evidence cited</p>
                                    @endforelse
                                </div>
                            @endforeach
                        </div>

                        {{-- Probe questions --}}
                        @if (! empty($result->probe_questions))
                            <div>
                                <div class="text-xs font-semibold uppercase tracking-wide text-base-content/60 mb-1">Probe questions (oral)</div>
                                <ul class="list-disc list-inside text-sm text-base-content/80 space-y-0.5">
                                    @foreach ($result->probe_questions as $q)
                                        <li>{{ $q }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        {{-- Finalization (R1 surface). The :key gives each child a
                             stable identity so Livewire never bleeds one
                             competence's finalize state onto another on re-render. --}}
                        <livewire:runs.competence-finalize :result-id="$result->id" :key="'cf-'.$result->id" />
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
