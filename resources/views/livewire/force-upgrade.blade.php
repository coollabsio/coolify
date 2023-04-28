<div>
    @if (auth()->user()->teams->contains(0))
        <button wire:loading.class="text-black bg-green-500" wire:loading.attr="disabled" wire:click='upgrade'>Force
            Upgrade</button>
    @endif
</div>
