<nav class="flex gap-2">
    @auth
        <div class="fixed flex gap-2 left-2 top-2">
            <a href="/">
                <x-inputs.button>Home</x-inputs.button>
            </a>
            <a href="/command-center">
                <x-inputs.button>Command Center</x-inputs.button>
            </a>
            <a href="/profile">
                <x-inputs.button>Profile</x-inputs.button>
            </a>
            @if (auth()->user()->isRoot())
                <a href="/settings">
                    <x-inputs.button>Settings</x-inputs.button>
                </a>
            @endif
        </div>
        <div class="flex-1"></div>
        <x-magic-bar />
        <div class="flex-1"></div>
        <div class="fixed flex gap-2 right-2 top-2">
            {{-- <livewire:check-update /> --}}
            <livewire:force-upgrade />
            <form action="/logout" method="POST">
                @csrf
                <x-inputs.button type="submit">Logout</x-inputs.button>
            </form>
        </div>
    @endauth
</nav>
