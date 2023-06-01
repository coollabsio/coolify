<div class="flex items-center gap-2">
    @if ($application->status === 'running')
        <div class="dropdown dropdown-bottom">
            <x-forms.button isHighlighted tabindex="0" class="">
                Actions
                <x-chevron-down />
            </x-forms.button>
            <ul tabindex="0"
                class="mt-1 text-xs text-white normal-case rounded min-w-max dropdown-content menu bg-coolgray-200">
                <li>
                    <div class="hover:bg-coollabs" wire:click='deploy'>Restart</div>
                </li>
                <li>
                    <div class="hover:bg-coollabs" wire:click='deploy(true)'>Force deploy without cache</div>
                </li>
                <li>
                    <div class="hover:bg-red-500" wire:click='stop'>Stop</div>
                </li>
            </ul>
        </div>
    @else
        <div class="dropdown dropdown-bottom">
            <label tabindex="0">
                <x-forms.button isHighlighted>
                    Actions
                    <x-chevron-down />
                </x-forms.button>
            </label>
            <ul tabindex="0"
                class="mt-1 text-xs text-white normal-case rounded min-w-max dropdown-content menu bg-coolgray-200">
                <li>
                    <div class="hover:bg-coollabs" wire:click='deploy'>Deploy</div>
                </li>
                <li>
                    <div class="hover:bg-coollabs" wire:click='deploy(true)'>Deploy without cache</div>
                </li>
            </ul>
        </div>
    @endif
</div>
