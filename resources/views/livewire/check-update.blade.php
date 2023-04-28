<div>
    <button wire:click='checkUpdate'>Updates</button>
    <div wire:loading wire:target="checkUpdate">
        Checking for updates...
    </div>
    @env('production')
    @if (auth()->user()->teams->contains(0))
        <button wire:click='forceUpgrade'>Force Upgrade</button>
        <div wire:loading wire:target="forceUpgrade">
            Updating Coolify...
        </div>
    @endif
    @endenv
    @if ($updateAvailable)
        Update available
    @endif
</div>
