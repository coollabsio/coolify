@auth
    <nav>
        <div class="flex px-2 py-1">
            <div class="flex gap-2 text-sm">
                <a href="/">
                    Home
                </a>
                <a href="/command-center">
                    Command Center
                </a>
                <a href="/profile">
                    Profile
                </a>
                <a href="/profile/team">
                    Team
                </a>
                @if (auth()->user()->isRoot())
                    <a href="/settings">
                        Settings
                    </a>
                @endif
            </div>
            <x-magic-bar />
            <div class="flex-1"></div>
            <div class="flex gap-2 text-sm">
                {{-- <livewire:check-update /> --}}
                <livewire:force-upgrade />
                <form action="/logout" method="POST">
                    @csrf
                    <button class="m-1 border-none hover:underline text-neutral-400" type="submit">Logout</button>
                </form>
            </div>
        </div>
    </nav>
@endauth
