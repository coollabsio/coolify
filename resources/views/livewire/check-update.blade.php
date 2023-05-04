<div>
    <x-inputs.button class="w-32 text-white bg-neutral-800 hover:bg-violet-600" wire:click='checkUpdate' type="submit">
        Check for updates</x-inputs.button>
    @if ($updateAvailable)
        Update available
    @endif
</div>
