<div>
    <button wire:loading.class="text-black bg-green-500" wire:loading.attr="disabled" wire:click='checkUpdate'>Check for
        updates</button>
    @if ($updateAvailable)
        Update available
    @endif
</div>
