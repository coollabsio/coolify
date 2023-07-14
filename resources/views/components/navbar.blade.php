@auth
    <nav class="fixed h-full overflow-hidden overflow-y-auto pt-14 scrollbar">
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
            <li title="Projects">
                <a class="hover:bg-transparent" @if (!request()->is('projects')) href="/projects" @endif>
                    <svg xmlns="http://www.w3.org/2000/svg"
                        class="{{ request()->is('project/*') || request()->is('projects') ? 'text-warning icon' : 'icon' }}"
                        viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round"
                        stroke-linejoin="round">
                        <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                        <path d="M12 4l-8 4l8 4l8 -4l-8 -4" />
                        <path d="M4 12l8 4l8 -4" />
                        <path d="M4 16l8 4l8 -4" />
                    </svg>
                </a>
            </li>

            <li title="Servers">
                <a class="hover:bg-transparent" @if (!request()->is('servers')) href="/servers" @endif>
                    <svg xmlns="http://www.w3.org/2000/svg"
                        class="{{ request()->is('server/*') || request()->is('servers') ? 'text-warning icon' : 'icon' }}"
                        viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round"
                        stroke-linejoin="round">
                        <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                        <path d="M3 4m0 3a3 3 0 0 1 3 -3h12a3 3 0 0 1 3 3v2a3 3 0 0 1 -3 3h-12a3 3 0 0 1 -3 -3z" />
                        <path d="M15 20h-9a3 3 0 0 1 -3 -3v-2a3 3 0 0 1 3 -3h12" />
                        <path d="M7 8v.01" />
                        <path d="M7 16v.01" />
                        <path d="M20 15l-2 3h3l-2 3" />
                    </svg>
                </a>
            </li>
            @if (auth()->user()->isInstanceAdmin())
                <li title="Command Center">
                    <a class="hover:bg-transparent" @if (!request()->is('command-center')) href="/command-center" @endif>
                        <svg xmlns="http://www.w3.org/2000/svg"
                            class="{{ request()->is('command-center') ? 'text-warning icon' : 'icon' }}" viewBox="0 0 24 24"
                            stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round"
                            stroke-linejoin="round">
                            <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                            <path d="M5 7l5 5l-5 5" />
                            <path d="M12 19l7 0" />
                        </svg>
                    </a>
                </li>

                <li title="Profile">
                    <a class="hover:bg-transparent" @if (!request()->is('profile')) href="/profile" @endif>
                        <svg xmlns="http://www.w3.org/2000/svg" class="icon" viewBox="0 0 24 24" stroke-width="1.5"
                            stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                            <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                            <path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" />
                            <path d="M12 10m-3 0a3 3 0 1 0 6 0a3 3 0 1 0 -6 0" />
                            <path d="M6.168 18.849a4 4 0 0 1 3.832 -2.849h4a4 4 0 0 1 3.834 2.855" />
                        </svg>
                    </a>
                </li>
                <li title="Teams">
                    <a class="hover:bg-transparent" href="{{ route('team.show') }}">
                        <svg xmlns="http://www.w3.org/2000/svg" class="icon" viewBox="0 0 24 24" stroke-width="1.5"
                            stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                            <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                            <path d="M10 13a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" />
                            <path d="M8 21v-1a2 2 0 0 1 2 -2h4a2 2 0 0 1 2 2v1" />
                            <path d="M15 5a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" />
                            <path d="M17 10h2a2 2 0 0 1 2 2v1" />
                            <path d="M5 5a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" />
                            <path d="M3 13v-1a2 2 0 0 1 2 -2h2" />
                        </svg>
                    </a>
                </li>
                <livewire:upgrade />
                <li title="Settings" class="mt-auto">
                    <a class="hover:bg-transparent" @if (!request()->is('settings')) href="/settings" @endif>
                        <svg xmlns="http://www.w3.org/2000/svg"
                            class="{{ request()->is('settings*') ? 'text-warning icon' : 'icon' }}" viewBox="0 0 24 24"
                            stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round"
                            stroke-linejoin="round">
                            <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                            <path
                                d="M10.325 4.317c.426 -1.756 2.924 -1.756 3.35 0a1.724 1.724 0 0 0 2.573 1.066c1.543 -.94 3.31 .826 2.37 2.37a1.724 1.724 0 0 0 1.065 2.572c1.756 .426 1.756 2.924 0 3.35a1.724 1.724 0 0 0 -1.066 2.573c.94 1.543 -.826 3.31 -2.37 2.37a1.724 1.724 0 0 0 -2.572 1.065c-.426 1.756 -2.924 1.756 -3.35 0a1.724 1.724 0 0 0 -2.573 -1.066c-1.543 .94 -3.31 -.826 -2.37 -2.37a1.724 1.724 0 0 0 -1.065 -2.572c-1.756 -.426 -1.756 -2.924 0 -3.35a1.724 1.724 0 0 0 1.066 -2.573c-.94 -1.543 .826 -3.31 2.37 -2.37c1 .608 2.296 .07 2.572 -1.065z" />
                            <path d="M9 12a3 3 0 1 0 6 0a3 3 0 0 0 -6 0" />
                        </svg>
                    </a>
                </li>
            @endif

            <li class="pb-6" title="Logout">
                <form action="/logout" method="POST" class=" hover:bg-transparent">
                    @csrf
                    <button class="rounded-none hover:text-white hover:bg-transparent"> <svg
                            xmlns="http://www.w3.org/2000/svg" class="icon" viewBox="0 0 24 24" stroke-width="1.5"
                            stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                            <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                            <path d="M13 12v.01" />
                            <path d="M3 21h18" />
                            <path d="M5 21v-16a2 2 0 0 1 2 -2h7.5m2.5 10.5v7.5" />
                            <path d="M14 7h7m-3 -3l3 3l-3 3" />
                        </svg></button>
                </form>
            </li>
        </ul>
    </nav>
@endauth
