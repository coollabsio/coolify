<nav class="flex gap-2 ">
    <div>v{{ config('coolify.version') }}</div>
    @guest
        <a href="/login">Login</a>
        @isset($isRegistrationEnabled)
            <a href="/register">Register</a>
        @else
            <div>Registration disabled</div>
        @endisset
    @endguest
    @auth
        <a href="/">Home</a>
        @env('local')
        <a href="/demo">Demo</a>
        @endenv
        <a href="/profile">Profile</a>
        @if (auth()->user()->isRoot())
            <a href="/settings">Settings</a>
        @endif
        <form action="/logout" method="POST">
            @csrf
            <button type="submit">Logout</button>
        </form>
        {{-- <livewire:check-update> --}}
    @endauth
</nav>
