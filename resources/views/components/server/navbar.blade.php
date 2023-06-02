<div class="pb-6">
    <h1>Server</h1>
    <div class="text-sm breadcrumbs pb-11">
        <ul>
            <li>{{ data_get($server, 'name') }}</li>
        </ul>
    </div>
    <nav class="flex items-center gap-4 py-2 border-b-2 border-solid border-coolgray-200">
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
    </nav>
</div>
