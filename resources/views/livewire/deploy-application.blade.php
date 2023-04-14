<div>
    @if ($application->status === 'running')
        <button wire:click='stop'>Stop</button>
    @else
        <button wire:click='start'>Start</button>
    @endif
    <span wire:poll='pollingStatus'>status: {{ $application->status }}</span>
</div>
