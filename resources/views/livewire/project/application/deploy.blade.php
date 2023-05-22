<div class="flex items-center gap-2">
    @if ($application->status === 'running')
        <div class="dropdown dropdown-bottom">
            <button tabindex="0"
                class="flex items-center justify-center h-full text-white normal-case rounded bg-primary btn btn-xs hover:bg-primary no-animation">
                Actions
                <x-chevron-down />
            </button>
            <ul tabindex="0"
                class="text-xs text-white normal-case rounded min-w-max dropdown-content menu bg-coolgray-200">
                <li>
                    <div wire:click='stop'>Stop</div>
                </li>
                <li>
                    <div wire:click='forceRebuild'>Force deploy without cache</div>
                </li>
            </ul>
        </div>
        running
    @else
        <div class="dropdown dropdown-bottom">
            <button tabindex="0"
                class="flex items-center justify-center h-full text-white normal-case rounded bg-primary btn btn-xs hover:bg-primary no-animation">
                Actions
                <x-chevron-down />
            </button>
            <ul tabindex="0"
                class="text-xs text-white normal-case rounded min-w-max dropdown-content menu bg-coolgray-200">
                <li>
                    <div wire:click='start'>Deploy</div>
                </li>
                <li>
                    <div wire:click='forceRebuild'>Deploy without cache</div>
                </li>
            </ul>
        </div>
        stopped
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
