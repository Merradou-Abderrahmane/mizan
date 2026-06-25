@props(['status' => null, 'source' => 'ai', 'finalized' => false])

{{--
    R1 visual grammar (design D4). Rendering is decided by $source, NOT by the
    text — so an AI status can NEVER render as a solid verdict badge.
      - source="ai": hedged advice → outline + italic + a small "i" marker.
        Semantic success/error colours are RESERVED for the operator verdict and
        are never applied here (so green/red always means "the operator decided").
      - source="operator": the operator's verdict → solid filled badge, shown
        ONLY when finalized; otherwise a visibly-empty "not finalized" slot.
--}}

@if ($source === 'operator')
    @if ($finalized && $status)
        @php $cls = $status === 'valide' ? 'badge-success' : ($status === 'non valide' ? 'badge-error' : 'badge-neutral'); @endphp
        <span class="badge {{ $cls }} badge-lg font-bold gap-1">
            🔒 {{ \Illuminate\Support\Str::upper($status) }}
        </span>
    @else
        <span class="badge badge-outline border-dashed text-base-content/40 gap-1">not finalized</span>
    @endif
@else
    @php $aiTint = $status === 'à vérifier' ? 'badge-warning' : ''; @endphp
    <span class="badge badge-outline {{ $aiTint }} italic gap-0.5">
        {{ $status ?: 'à vérifier' }}<sup class="not-italic text-[0.6rem]">i</sup>
    </span>
@endif
