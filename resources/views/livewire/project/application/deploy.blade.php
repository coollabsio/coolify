<div class="flex items-center gap-2">
    @if ($application->status === 'running')
        <div class="btn-group">
            <x-inputs.button isWarning wire:click='stop'>Stop</x-inputs.button>
            <div class="bg-transparent border-none dropdown dropdown-hover btn btn-xs no-animation">
                <button tabindex="0" class="flex items-center justify-center h-full">
                    <x-chevron-down />
                </button>
                <ul tabindex="0"
                    class="text-xs text-white normal-case rounded min-w-max dropdown-content menu bg-coolgray-200">
                    <li>
                        <div wire:click='forceRebuild'>Force deploy without cache</div>
                    </li>
                </ul>
            </div>
        </div>
    @else
        <div class="btn-group">
            <x-inputs.button isHighlighted wire:click='start'>Deploy</x-inputs.button>
            <div class="border-none dropdown dropdown-hover btn btn-xs bg-coollabs hover:bg-coollabs-100 no-animation">
                <button tabindex="0" class="flex items-center justify-center h-full">
                    <x-chevron-down />
                </button>
                <ul tabindex="0"
                    class="text-xs text-white normal-case rounded min-w-max dropdown-content menu bg-coolgray-200">
                    <li>
                        <div wire:click='forceRebuild'>Deploy without cache</div>
                    </li>
                </ul>
            </div>
        </div>
    @endif
    <span wire:poll.5000ms='pollingStatus'>
        {{-- @if ($application->status === 'running')
            <span class="text-xs text-pink-600" wire:loading.delay.longer>Loading current status...</span>
            <span class="text-green-500" wire:loading.remove.delay.longer>{{ $application->status }}</span>
        @else
            <span class="text-xs text-pink-600" wire:loading.delay.longer>Loading current status...</span>
            <span class="text-red-500" wire:loading.remove.delay.longer>{{ $application->status }}</span>
        @endif --}}
    </span>
</div>
