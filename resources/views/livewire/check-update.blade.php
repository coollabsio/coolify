<div>
    <button wire:click='checkUpdate'>Updates</button>
    @if (auth()->user()->teams->contains(0))
        <button wire:click='forceUpgrade'>Force Upgrade</button>
    @endif
    {{ $updateAvailable ? 'Update available' : 'No updates' }}
</div>
