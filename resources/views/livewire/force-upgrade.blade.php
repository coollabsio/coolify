<div>
    @if (auth()->user()->teams->contains(0))
        <button wire:click='upgrade' class="m-1 hover:underline">Force Upgrade</button>
    @endif
</div>
