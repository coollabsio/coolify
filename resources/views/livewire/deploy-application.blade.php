<div>
    <button wire:click='deploy'>Deploy</button>
    <button wire:click='stop'>Stop</button>
    <button wire:click='checkStatus'>CheckStatus</button>
    <span wire:poll='pollingStatus'>status: {{ $application->status }}</span>
</div>
