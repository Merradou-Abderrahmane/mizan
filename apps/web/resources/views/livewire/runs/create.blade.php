<div class="max-w-2xl">
    <div class="flex items-center gap-2 mb-6">
        <a href="{{ route('runs.index') }}" class="btn btn-ghost btn-sm">← Runs</a>
        <h1 class="text-2xl font-semibold">New run</h1>
    </div>

    <form wire:submit="submit" class="card bg-base-100 border border-base-300">
        <div class="card-body gap-4">
            <label class="form-control w-full">
                <div class="label"><span class="label-text font-medium">Brief</span></div>
                <select wire:model="brief_id" class="select select-bordered w-full">
                    <option value="">Select a brief…</option>
                    @foreach ($briefs as $brief)
                        <option value="{{ $brief->id }}">{{ $brief->title }}</option>
                    @endforeach
                </select>
                @error('brief_id') <div class="label"><span class="label-text-alt text-error">{{ $message }}</span></div> @enderror
            </label>

            <label class="form-control w-full">
                <div class="label"><span class="label-text font-medium">Repository source</span></div>
                <input type="text" wire:model="source" class="input input-bordered w-full"
                       placeholder="storage/test-repos/ForgeCoreApi  or  https://github.com/org/repo.git">
                <div class="label">
                    <span class="label-text-alt text-base-content/60">
                        Use a <strong>local path</strong> for a gradable run. A URL is structural-only —
                        its clone is removed after intake, so Pass 1 would grade every criterion to “à vérifier”.
                    </span>
                </div>
                @error('source') <div class="label"><span class="label-text-alt text-error">{{ $message }}</span></div> @enderror
            </label>

            <label class="form-control w-full">
                <div class="label">
                    <span class="label-text font-medium">Persona</span>
                    <span class="label-text-alt text-base-content/50">operator-private (R4)</span>
                </div>
                <input type="text" wire:model="persona" class="input input-bordered w-full"
                       placeholder="e.g. N2 adapter">
                <div class="label">
                    <span class="label-text-alt text-base-content/60">
                        Your private tag. Never sent to the runner or into a Pass 1 prompt — stored only on the student repo.
                    </span>
                </div>
                @error('persona') <div class="label"><span class="label-text-alt text-error">{{ $message }}</span></div> @enderror
            </label>

            <div class="card-actions justify-end">
                <a href="{{ route('runs.index') }}" class="btn btn-ghost">Cancel</a>
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">
                    <span wire:loading wire:target="submit" class="loading loading-spinner loading-sm"></span>
                    Launch run
                </button>
            </div>
        </div>
    </form>
</div>
