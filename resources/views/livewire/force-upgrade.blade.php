<div>
    @if (auth()->user()->teams->contains(0))
        <button wire:click='upgrade' class="m-1 border-none hover:underline text-neutral-400">Force Upgrade</button>
    @endif
</div>
