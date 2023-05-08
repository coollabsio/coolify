<div>
    <x-inputs.button wire:click='checkUpdate' type="submit">
        Check Update</x-inputs.button>
    @if ($updateAvailable)
        Update available
    @endif
</div>
