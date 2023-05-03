<div>
    <x-inputs.button wire:click='checkUpdate' type="submit">Check for updates</x-inputs.button>
    @if ($updateAvailable)
        Update available
    @endif
</div>
