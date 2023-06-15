<div class="pb-6">
    <h1>Server</h1>
    <div class="pt-2 pb-10 text-sm">{{ data_get($server, 'name') }}</div>
    <nav class="flex items-end gap-4 py-2 border-b-2 border-solid border-coolgray-200">
        <a class="{{ request()->routeIs('server.show') ? 'text-white' : '' }}"
            href="{{ route('server.show', [
                'server_uuid' => Route::current()->parameters()['server_uuid'],
            ]) }}">
            <button>General</button>
        </a>
        <a class="{{ request()->routeIs('server.proxy') ? 'text-white' : '' }}"
            href="{{ route('server.proxy', [
                'server_uuid' => Route::current()->parameters()['server_uuid'],
            ]) }}">
            <button>Proxy</button>
        </a>
        <a class="{{ request()->routeIs('server.private-key') ? 'text-white' : '' }}"
            href="{{ route('server.private-key', [
                'server_uuid' => Route::current()->parameters()['server_uuid'],
            ]) }}">
            <button>Private Key</button>
        </a>
        <a class="{{ request()->routeIs('server.destinations') ? 'text-white' : '' }}"
            href="{{ route('server.destinations', [
                'server_uuid' => Route::current()->parameters()['server_uuid'],
            ]) }}">
            <button>Destinations</button>
        </a>
        @if (request()->routeIs('server.proxy'))
            <div class="flex-1"></div>
            <livewire:server.proxy.deploy :server="$server" />
        @endif
    </nav>
</div>
