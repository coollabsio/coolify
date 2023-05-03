<div>
    @if (auth()->user()->teams->contains(0))
        <x-inputs.button wire:click='upgrade'>Force Upgrade</x-inputs.button>
    @endif
</div>
