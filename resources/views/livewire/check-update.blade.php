<div>
    <button wire:click='checkUpdate'>Updates</button>
    @env('production')
    <button wire:click='forceUpgrade'>Force Upgrade</button>
    @endenv
    {{ $updateAvailable ? 'Update available' : 'No updates' }}
</div>
