@auth
    <div class="navbar">
        <div class="navbar-start">
            <div class="dropdown">
                <label tabindex="0" class="btn btn-ghost xl:hidden">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24"
                        stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h8m-8 6h16" />
                    </svg>
                </label>
                <ul tabindex="0" class="p-2 mt-3 shadow menu menu-compact dropdown-content bg-base-100 rounded-box w-52">
                    <li>
                        <a href="/">
                            Dashboard
                        </a>
                    </li>
                    @if (auth()->user()->isPartOfRootTeam())
                        <li>
                            <a href="/settings">
                                Settings
                            </a>
                        </li>
                    @endif
                    {{-- <li>
                        <a href="/profile">
                            Profile
                        </a>
                    </li> --}}
                    <li>
                        <a href="/profile/team">
                            Team
                        </a>
                    </li>
                    <li>
                        <a href="/command-center">
                            Command Center
                        </a>
                    </li>
                    @if (auth()->user()->isPartOfRootTeam())
                        <li>
                            <livewire:force-upgrade />
                        </li>
                    @endif
                    <li>
                        <form action="/logout" method="POST">
                            @csrf
                            <button>Logout</button>
                        </form>
                    </li>
                </ul>
            </div>
            <div href="/" class="text-xl text-white normal-case btn btn-ghost hover:bg-transparent">Coolify</div>
            <div class="form-control">
                <x-magic-bar />
            </div>
        </div>
        <div class="hidden navbar-end xl:flex">
            <ul class="px-1 menu menu-horizontal text-neutral-400">
                <li>
                    <a href="/">
                        Dashboard
                    </a>
                </li>
                @if (auth()->user()->isPartOfRootTeam())
                    <li>
                        <a href="/settings">
                            Settings
                        </a>
                    </li>
                @endif
                {{-- <li>
                    <a href="/profile">
                        Profile
                    </a>
                </li> --}}
                <li>
                    <a href="/profile/team">
                        Team
                    </a>
                </li>
                <li>
                    <a href="/command-center">
                        Command Center
                    </a>
                </li>
                @if (auth()->user()->isPartOfRootTeam())
                    <li>
                        <livewire:force-upgrade />
                    </li>
                @endif
                <li>
                    <form action="/logout" method="POST" class="hover:bg-transparent">
                        @csrf
                        <button class="text-sm link link-hover hover:text-white">Logout</button>
                    </form>
                </li>
            </ul>
        </div>
    </div>
@endauth
