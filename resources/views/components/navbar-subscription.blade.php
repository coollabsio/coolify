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
                            d="M140 180a12 12 0 1 1-12-12a12 12 0 0 1 12 12M128 72c-22.06 0-40 16.15-40 36v4a8 8 0 0 0 16 0v-4c0-11 10.77-20 24-20s24 9 24 20s-10.77 20-24 20a8 8 0 0 0-8 8v8a8 8 0 0 0 16 0v-.72c18.24-3.35 32-17.9 32-35.28c0-19.85-17.94-36-40-36m104 56A104 104 0 1 1 128 24a104.11 104.11 0 0 1 104 104m-16 0a88 88 0 1 0-88 88a88.1 88.1 0 0 0 88-88" />
                    </svg>
                </div>
            </li>
            <li class="pb-6" title="Logout">
                <form action="/logout" method="POST" class="hover:bg-transparent">
                    @csrf
                    <button class="flex items-center gap-2 rounded-none hover:text-white hover:bg-transparent">
                        <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path fill="currentColor" d="M12 22C6.477 22 2 17.523 2 12S6.477 2 12 2a9.985 9.985 0 0 1 8 4h-2.71a8 8 0 1 0 .001 12h2.71A9.985 9.985 0 0 1 12 22m7-6v-3h-8v-2h8V8l5 4z"/>
                        </svg>
                    </button>
                </form>
            </li>
        </ul>
    </nav>
@endauth
