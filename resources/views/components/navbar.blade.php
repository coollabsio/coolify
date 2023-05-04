<nav class="flex gap-2 ">
    <div>v{{ config('coolify.version') }}</div>
    @auth
        <a href="/">Home</a>
        <a href="/command-center">Command Center</a>
        <a href="/profile">Profile</a>
        @if (auth()->user()->isRoot())
            <a href="/settings">Settings</a>
        @endif
        <form action="/logout" method="POST">
            @csrf
            <x-inputs.button type="submit">Logout</x-inputs.button>
        </form>
        <livewire:check-update />
        <livewire:force-upgrade />
    @endauth
</nav>
