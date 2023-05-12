<nav class="flex gap-2 ">
    @auth
        <div class="fixed left-0 top-2">
            <a href="/">Home</a>
            <a href="/command-center">Command Center</a>
            <a href="/profile">Profile</a>
            @if (auth()->user()->isRoot())
                <a href="/settings">Settings</a>
            @endif
        </div>
        <div class="flex-1"></div>
        <x-magic-bar />
        <div class="flex-1"></div>
        <div class="fixed right-0 flex gap-2 top-2">
            <form action="/logout" method="POST">
                @csrf
                <x-inputs.button type="submit">Logout</x-inputs.button>
            </form>
            <livewire:check-update />
            <livewire:force-upgrade />
        </div>
    @endauth
</nav>
