<div class="rounded-box border-2 border-base-300 bg-base-200/40 p-4">
    <div class="flex items-center justify-between mb-3">
        <span class="text-xs font-semibold uppercase tracking-wide text-base-content/60">Your finalization</span>
        <x-status-badge source="operator" :status="$result->operator_status" :finalized="$finalized" />
    </div>

    @if ($finalized)
        @if ($result->operator_note)
            <p class="text-sm text-base-content/80 mb-3">
                <span class="font-medium">Note:</span> {{ $result->operator_note }}
            </p>
        @endif
        <div class="flex justify-end">
            <button type="button" wire:click="reopen" class="btn btn-sm btn-ghost">Reopen</button>
        </div>
    @else
        <div class="flex flex-col gap-3">
            <div class="flex flex-wrap gap-4">
                <label class="label cursor-pointer gap-2">
                    <input type="radio" wire:model="status" value="valide" class="radio radio-success radio-sm">
                    <span class="label-text font-medium">valide</span>
                </label>
                <label class="label cursor-pointer gap-2">
                    <input type="radio" wire:model="status" value="non valide" class="radio radio-error radio-sm">
                    <span class="label-text font-medium">non valide</span>
                </label>
            </div>
            @error('status') <span class="text-error text-sm">{{ $message }}</span> @enderror

            <input type="text" wire:model="note" class="input input-bordered input-sm w-full"
                   placeholder="Optional note (e.g. confirmed à l'oral)…">

            <div class="flex justify-end">
                <button type="button" wire:click="finalize" class="btn btn-sm btn-primary">Finalize</button>
            </div>
        </div>
    @endif
</div>
