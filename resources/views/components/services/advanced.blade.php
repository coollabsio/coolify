<x-dropdown>
    <x-slot:title>
        Advanced
    </x-slot>
    @if (str($service->status)->contains('running'))
        <div class="dropdown-item" @click="$wire.dispatch('pullAndRestartEvent')">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 24 24" stroke-width="1.5"
                stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                <path
                    d="M12.983 8.978c3.955 -.182 7.017 -1.446 7.017 -2.978c0 -1.657 -3.582 -3 -8 -3c-1.661 0 -3.204 .19 -4.483 .515m-2.783 1.228c-.471 .382 -.734 .808 -.734 1.257c0 1.22 1.944 2.271 4.734 2.74" />
                <path
                    d="M4 6v6c0 1.657 3.582 3 8 3c.986 0 1.93 -.067 2.802 -.19m3.187 -.82c1.251 -.53 2.011 -1.228 2.011 -1.99v-6" />
                <path d="M4 12v6c0 1.657 3.582 3 8 3c3.217 0 5.991 -.712 7.261 -1.74m.739 -3.26v-4" />
                <path d="M3 3l18 18" />
            </svg>
            Pull Latest Images & Restart
        </div>
    @elseif (str($service->status)->contains('degraded'))
        <div class="dropdown-item" @click="$wire.dispatch('forceDeployEvent')">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                stroke-linecap="round" stroke-linejoin="round" data-darkreader-inline-stroke=""
                style="--darkreader-inline-stroke: currentColor;" class="w-6 h-6" stroke-width="2">
                <path d="M7 7l5 5l-5 5"></path>
                <path d="M13 7l5 5l-5 5"></path>
            </svg>
            Force Restart
        </div>
    @else
        <div class="dropdown-item" @click="$wire.dispatch('forceDeployEvent')">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                stroke-linecap="round" stroke-linejoin="round" data-darkreader-inline-stroke=""
                style="--darkreader-inline-stroke: currentColor;" class="w-6 h-6" stroke-width="2">
                <path d="M7 7l5 5l-5 5"></path>
                <path d="M13 7l5 5l-5 5"></path>
            </svg>
            Force Deploy
        </div>
        <div class="dropdown-item" wire:click='stop(true)''>
            <svg class="w-6 h-6" viewBox="0 0 32 32" xmlns="http://www.w3.org/2000/svg">
                <path fill="currentColor" d="M26 20h-6v-2h6zm4 8h-6v-2h6zm-2-4h-6v-2h6z" />
                <path fill="currentColor"
                    d="M17.003 20a4.895 4.895 0 0 0-2.404-4.173L22 3l-1.73-1l-7.577 13.126a5.699 5.699 0 0 0-5.243 1.503C3.706 20.24 3.996 28.682 4.01 29.04a1 1 0 0 0 1 .96h14.991a1 1 0 0 0 .6-1.8c-3.54-2.656-3.598-8.146-3.598-8.2Zm-5.073-3.003A3.11 3.11 0 0 1 15.004 20c0 .038.002.208.017.469l-5.9-2.624a3.8 3.8 0 0 1 2.809-.848ZM15.45 28A5.2 5.2 0 0 1 14 25h-2a6.5 6.5 0 0 0 .968 3h-2.223A16.617 16.617 0 0 1 10 24H8a17.342 17.342 0 0 0 .665 4H6c.031-1.836.29-5.892 1.803-8.553l7.533 3.35A13.025 13.025 0 0 0 17.596 28Z" />
            </svg>
            Force Cleanup Containers
        </div>
    @endif
</x-dropdown>
