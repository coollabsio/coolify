<div class="flex items-center gap-2">
    @if ($application->status === 'running')
        <div class="dropdown dropdown-bottom">
            <button tabindex="0"
                class="flex items-center justify-center h-full text-white normal-case rounded-none bg-primary btn btn-xs hover:bg-primary no-animation">
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
    @else
        <div class="dropdown dropdown-bottom">
            <button tabindex="0"
                class="flex items-center justify-center h-full text-white normal-case rounded-none bg-primary btn btn-xs hover:bg-primary no-animation">
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
    @endif
</div>
