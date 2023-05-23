@auth
    <nav class="main-navbar">
        <ul class="gap-2 p-1 pt-2 menu">
            <li class="{{ request()->is('/') ? 'text-warning' : '' }}">
                <a @if (!request()->is('/')) href="/" @endif>
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" fill="none" viewBox="0 0 24 24"
                        stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                    </svg>
                </a>
            </li>
            <li class="{{ request()->is('server/*') || request()->is('servers') ? 'text-warning' : '' }}">
                <a @if (!request()->is('server/*')) href="/servers" @endif>
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" viewBox="0 0 24 24" stroke-width="1.5"
                        stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                        <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                        <path d="M3 4m0 3a3 3 0 0 1 3 -3h12a3 3 0 0 1 3 3v2a3 3 0 0 1 -3 3h-12a3 3 0 0 1 -3 -3z" />
                        <path d="M3 12m0 3a3 3 0 0 1 3 -3h12a3 3 0 0 1 3 3v2a3 3 0 0 1 -3 3h-12a3 3 0 0 1 -3 -3z" />
                        <path d="M7 8l0 .01" />
                        <path d="M7 16l0 .01" />
                    </svg>
                </a>
            </li>

            <li class="{{ request()->is('project/*') || request()->is('projects') ? 'text-warning' : '' }}">
                <a @if (!request()->is('project/*')) href="/projects" @endif>
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" viewBox="0 0 24 24" stroke-width="1.5"
                        stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                        <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                        <path d="M12 4l-8 4l8 4l8 -4l-8 -4" />
                        <path d="M4 12l8 4l8 -4" />
                        <path d="M4 16l8 4l8 -4" />
                    </svg>
                </a>
            </li>

            @if (auth()->user()->isPartOfRootTeam())
                <li class="{{ request()->is('command-center') ? 'text-warning' : '' }}">
                    <a @if (!request()->is('command-center')) href="/command-center" @endif>
                        <svg xmlns="http://www.w3.org/2000/svg" class="icon" viewBox="0 0 24 24" stroke-width="1.5"
                            stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                            <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                            <path d="M5 7l5 5l-5 5" />
                            <path d="M12 19l7 0" />
                        </svg>
                    </a>
                </li>
                <li
                    class="{{ request()->is('settings') ? 'absolute bottom-0 pb-4 text-warning' : 'absolute bottom-0 pb-4' }}">
                    <a @if (!request()->is('settings')) href="/settings" @endif>
                        <svg xmlns="http://www.w3.org/2000/svg" class="icon" viewBox="0 0 24 24" stroke-width="1.5"
                            stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                            <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                            <path
                                d="M10.325 4.317c.426 -1.756 2.924 -1.756 3.35 0a1.724 1.724 0 0 0 2.573 1.066c1.543 -.94 3.31 .826 2.37 2.37a1.724 1.724 0 0 0 1.065 2.572c1.756 .426 1.756 2.924 0 3.35a1.724 1.724 0 0 0 -1.066 2.573c.94 1.543 -.826 3.31 -2.37 2.37a1.724 1.724 0 0 0 -2.572 1.065c-.426 1.756 -2.924 1.756 -3.35 0a1.724 1.724 0 0 0 -2.573 -1.066c-1.543 .94 -3.31 -.826 -2.37 -2.37a1.724 1.724 0 0 0 -1.065 -2.572c-1.756 -.426 -1.756 -2.924 0 -3.35a1.724 1.724 0 0 0 1.066 -2.573c-.94 -1.543 .826 -3.31 2.37 -2.37c1 .608 2.296 .07 2.572 -1.065z" />
                            <path d="M9 12a3 3 0 1 0 6 0a3 3 0 0 0 -6 0" />
                        </svg>
                    </a>
                </li>
            @endif
        </ul>
    </nav>
    <div class="absolute top-0 right-0 pt-2">
        <div class="dropdown dropdown-left">
            <label tabindex="0" class="btn btn-ghost no-animation hover:bg-transparent">
                <div class="flex items-center justify-center gap-2 avatar placeholder">
                    <div class="w-10 border rounded-full border-neutral-600 text-warning">
                        <span class="text-xs">{{ Str::of(auth()->user()->name)->substr(0, 2)->upper() }}</span>
                    </div>
                    <x-chevron-down />
                </div>
            </label>
            <ul tabindex="0"
                class="p-2 mt-3 text-white rounded shadow menu menu-compact dropdown-content bg-coolgray-200 w-52">
                <li>
                    <a href="/profile">
                        Profile
                    </a>
                </li>
                <li>
                    <a href="/profile/team">
                        Team
                    </a>
                </li>
                @if (auth()->user()->isPartOfRootTeam())
                    <li>
                        <livewire:force-upgrade />
                    </li>
                @endif
                <form action="/logout" method="POST">
                    <li>
                        @csrf
                        <button>Logout</button>
                    </li>
                </form>
            </ul>
        </div>
    </div>
@endauth
