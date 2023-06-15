<div>
    @if ($server->settings->is_reachable)
        @if ($server->extra_attributes->proxy_status === 'running')
            <div class="flex gap-4">
                <div class="group">
                    <label tabindex="0" class="flex items-center gap-2 text-sm cursor-pointer hover:text-white"> Links
                        <x-chevron-down />
                    </label>
                    <div class="absolute hidden group-hover:block ">
                        <ul tabindex="0"
                            class="relative text-xs text-white normal-case rounded -ml-28 min-w-max menu bg-coolgray-200">
                            <li>
                                <a target="_blank"
                                    class="text-xs text-white rounded-none hover:no-underline hover:bg-coollabs"
                                    href="http://{{ request()->getHost() }}:8080">
                                    Traefik Dashboard
                                    <x-external-link />
                                </a>
                            </li>
                        </ul>
                    </div>
                </div>
                <div class="group">
                    <label tabindex="0" class="flex items-center gap-2 cursor-pointer hover:text-white"> Actions
                        <x-chevron-down />
                    </label>
                    <div class="absolute hidden group-hover:block ">
                        <ul tabindex="0"
                            class="relative text-xs text-white normal-case rounded min-w-max menu bg-coolgray-200 -ml-14">
                            <li>
                                <div class="rounded-none hover:bg-coollabs" wire:click='deploy'><svg
                                        xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 24 24"
                                        stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round"
                                        stroke-linejoin="round">
                                        <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                        <path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4" />
                                        <path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4" />
                                        <path d="M12 9l0 3" />
                                        <path d="M12 15l.01 0" />
                                    </svg>Restart</div>
                            </li>
                            <li>
                                <div class="rounded-none hover:bg-red-500" wire:click='stop'><svg
                                        xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 24 24"
                                        stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round"
                                        stroke-linejoin="round">
                                        <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                        <path d="M8 13v-7.5a1.5 1.5 0 0 1 3 0v6.5" />
                                        <path d="M11 5.5v-2a1.5 1.5 0 1 1 3 0v8.5" />
                                        <path d="M14 5.5a1.5 1.5 0 0 1 3 0v6.5" />
                                        <path
                                            d="M17 7.5a1.5 1.5 0 0 1 3 0v8.5a6 6 0 0 1 -6 6h-2h.208a6 6 0 0 1 -5.012 -2.7a69.74 69.74 0 0 1 -.196 -.3c-.312 -.479 -1.407 -2.388 -3.286 -5.728a1.5 1.5 0 0 1 .536 -2.022a1.867 1.867 0 0 1 2.28 .28l1.47 1.47" />
                                    </svg>Stop</div>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        @else
            <button wire:click='deploy' class="flex items-center gap-2 text-sm cursor-pointer hover:text-white"> <svg
                    xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 24 24" stroke-width="1.5"
                    stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                    <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                    <path d="M7 4v16l13 -8z" />
                </svg>Start Proxy
            </button>
        @endif
    @endif
</div>
