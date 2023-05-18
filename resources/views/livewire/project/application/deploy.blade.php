<div class="flex items-center gap-2">
    @if ($application->status === 'running')
        <x-inputs.button wire:click='start'>Rebuild</x-inputs.button>
        <x-inputs.button wire:click='forceRebuild'>Force Rebuild</x-inputs.button>
        <x-inputs.button wire:click='stop'>Stop</x-inputs.button>
    @else
        <x-inputs.button wire:click='start'>Start</x-inputs.button>
        <x-inputs.button wire:click='forceRebuild'>Start (no cache)</x-inputs.button>
    @endif

    <span wire:poll.5000ms='pollingStatus'>
        @if ($application->status === 'running')
            <span class="text-xs text-pink-600" wire:loading.delay.longer>Loading current status...</span>
            <span class="text-green-500" wire:loading.remove.delay.longer>{{ $application->status }}</span>
        @else
            <span class="text-xs text-pink-600" wire:loading.delay.longer>Loading current status...</span>
            <span class="text-red-500" wire:loading.remove.delay.longer>{{ $application->status }}</span>
        @endif
    </span>
</div>
