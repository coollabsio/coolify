@auth
    <nav class="fixed h-full overflow-hidden overflow-y-auto pt-14 scrollbar">
        <a href="/" class="fixed top-0 z-50 mx-3 mt-3 bg-transparent cursor-pointer"><img
                class="transition rounded w-11 h-11" src="{{ asset('coolify-transparent.png') }}"></a>
        <ul class="flex flex-col h-full gap-4 menu flex-nowrap">
            <li title="Dashboard">
                <a class="hover:bg-transparent" @if (!request()->is('/')) href="/" @endif>
                    <svg xmlns="http://www.w3.org/2000/svg" class="{{ request()->is('/') ? 'text-warning icon' : 'icon' }}"
                        fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                    </svg>
                </a>
            </li>
            <li title="Help us!">
                <a class="hover:bg-transparent" href="https://coolify.io/sponsorships" target="_blank">
                    <svg class="icon hover:text-pink-500" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                            stroke-width="2">
                            <path d="M19.5 12.572L12 20l-7.5-7.428A5 5 0 1 1 12 6.006a5 5 0 1 1 7.5 6.572" />
                            <path
                                d="M12 6L8.707 9.293a1 1 0 0 0 0 1.414l.543.543c.69.69 1.81.69 2.5 0l1-1a3.182 3.182 0 0 1 4.5 0l2.25 2.25m-7 3l2 2M15 13l2 2" />
                        </g>
                    </svg>
                </a>
            </li>
            <li title="Send us feedback or get help!" class="fixed top-0 right-0 p-2 px-4 pt-4 mt-auto text-xs">
                <div class="justify-center" wire:click="help" onclick="help.showModal()">
                    <svg class="icon" viewBox="0 0 256 256" xmlns="http://www.w3.org/2000/svg">
                        <path fill="currentColor"
                            d="M144 180a16 16 0 1 1-16-16a16 16 0 0 1 16 16m92-52A108 108 0 1 1 128 20a108.12 108.12 0 0 1 108 108m-24 0a84 84 0 1 0-84 84a84.09 84.09 0 0 0 84-84m-84-64c-24.26 0-44 17.94-44 40v4a12 12 0 0 0 24 0v-4c0-8.82 9-16 20-16s20 7.18 20 16s-9 16-20 16a12 12 0 0 0-12 12v8a12 12 0 0 0 23.73 2.56C158.31 137.88 172 122.37 172 104c0-22.06-19.74-40-44-40" />
                    </svg>
                </div>
            </li>
            <li class="pb-6" title="Logout">
                <form action="/logout" method="POST" class=" hover:bg-transparent">
                    @csrf
                    <button class="flex items-center gap-2 rounded-none hover:text-white hover:bg-transparent">
                        <svg xmlns="http://www.w3.org/2000/svg" class="icon" viewBox="0 0 24 24" stroke-width="1.5"
                            stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                            <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                            <path d="M13 12v.01" />
                            <path d="M3 21h18" />
                            <path d="M5 21v-16a2 2 0 0 1 2 -2h7.5m2.5 10.5v7.5" />
                            <path d="M14 7h7m-3 -3l3 3l-3 3" />
                        </svg>
                    </button>
                </form>
            </li>
        </ul>
    </nav>
@endauth
