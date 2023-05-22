<div wire:poll.5000ms='pollingStatus'>
    @if ($application->status === 'running')
        <span class="text-xs text-pink-600" wire:loading.delay.longer>Loading current status...</span>
        <div class="flex items-center gap-2 text-sm" wire:loading.remove.delay.longer>
            <span class="flex w-3 h-3 rounded-full bg-success"></span>
            <span class="text-green-500">Running</span>
        </div>
    @else
        <span class="text-xs text-pink-600" wire:loading.delay.longer>Loading current status...</span>
        <div class="flex items-center gap-2 text-sm" wire:loading.remove.delay.longer>
            <span class="flex w-3 h-3 rounded-full bg-error"></span>
            <span class="text-error">Stopped</span>
        </div>
    @endif
</div>
