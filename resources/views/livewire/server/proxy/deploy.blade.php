<div>
    @if ($server->settings->is_validated)
        @if ($server->extra_attributes->proxy_status === 'running')
            <div class="dropdown dropdown-bottom">
                <x-forms.button isHighlighted tabindex="0">
                    Actions
                    <x-chevron-down />
                </x-forms.button>
                <ul tabindex="0"
                    class="mt-1 text-xs text-white normal-case rounded min-w-max dropdown-content menu bg-coolgray-200">
                    <li>
                        <div class="rounded-none hover:bg-coollabs" wire:click='deploy'><svg
                                xmlns="http://www.w3.org/2000/svg" class="icon" viewBox="0 0 24 24" stroke-width="1.5"
                                stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                                <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                <path d="M9 4.55a8 8 0 0 1 6 14.9m0 -4.45v5h5" />
                                <path d="M5.63 7.16l0 .01" />
                                <path d="M4.06 11l0 .01" />
                                <path d="M4.63 15.1l0 .01" />
                                <path d="M7.16 18.37l0 .01" />
                                <path d="M11 19.94l0 .01" />
                            </svg>Restart</div>
                    </li>
                    <li>
                        <div class="rounded-none hover:bg-red-500" wire:click='stop'> <svg
                                xmlns="http://www.w3.org/2000/svg" class="icon" viewBox="0 0 24 24" stroke-width="1.5"
                                stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                                <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                <path
                                    d="M5 5m0 2a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v10a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2z" />
                            </svg>Stop</div>
                    </li>
                </ul>
            </div>
        @else
            <x-forms.button isHighlighted wire:click='deploy'> <svg xmlns="http://www.w3.org/2000/svg" class="icon"
                    width="44" height="44" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"
                    fill="none" stroke-linecap="round" stroke-linejoin="round">
                    <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                    <path d="M7 4v16l13 -8z" />
                </svg>Start</x-forms.button>
        @endif
    @endif
</div>
