<nav class="flex space-x-4 p-2 fixed right-0">
    @env('local')
    <a href="/run-command">Run command</a>
    {{-- <livewire:create-token /> --}}
    <a href="/debug">Debug</a>
    <a href="/debug/logs" target="_blank">Debug Logs</a>
    {{-- <a href="/debug/inertia">Debug(inertia)</a> --}}
    @endenv
    <a href="/">Dashboard</a>
    <a href="/profile">Profile</a>
    <form action="/logout" method="POST">
        @csrf
        <button type="submit">Logout</button>
    </form>
</nav>
