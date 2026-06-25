@props(['status'])

{{-- Run lifecycle badge (distinct from the R1 verdict badge). Kept in
     blue/grey/amber tones — solid green/red is reserved for the operator
     verdict (x-status-badge), never the run status. --}}
@php
    $map = [
        'pending'       => ['badge-ghost', 'pending', false],
        'processing'    => ['badge-info', 'processing', true],
        'pass1_done'    => ['badge-neutral', 'graded', false],
        'pass1_partial' => ['badge-warning', 'partial', false],
        'error'         => ['badge-error badge-outline', 'error', false],
    ];
    [$cls, $label, $spin] = $map[$status] ?? ['badge-ghost', $status, false];
@endphp
<span class="badge {{ $cls }} gap-1">
    @if ($spin)<span class="loading loading-spinner loading-xs"></span>@endif
    {{ $label }}
</span>
