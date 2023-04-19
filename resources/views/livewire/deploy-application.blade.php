<div>
    @if ($application->status === 'running')
        <button wire:click='stop'>Stop</button>
    @else
        <button wire:click='start'>Start</button>
    @endif
    <button wire:click='kill'>Kill</button>
    <span wire:poll='pollingStatus'>
        @if ($application->status === 'running')
            <span class="text-green-500">{{ $application->status }}</span>
        @else
            <span class="text-red-500">{{ $application->status }}</span>
        @endif
    </span>
</div>
