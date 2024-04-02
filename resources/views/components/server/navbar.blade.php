<div class="pb-6">
    <livewire:server.proxy.modal :server="$server" />
    <div class="flex items-center gap-2">
        <h1>Server</h1>
        @if (
            $server->proxyType() !== 'NONE' &&
                $server->isFunctional() &&
                !$server->isSwarmWorker() &&
                !$server->settings->is_build_server)
            <livewire:server.proxy.status :server="$server" />
        @endif
    </div>
    <div class="subtitle">{{ data_get($server, 'name') }}.</div>
    <nav class="navbar-main">
        <a class="{{ request()->routeIs('server.show') ? 'dark:text-white' : '' }}"
            href="{{ route('server.show', [
                'server_uuid' => data_get($parameters, 'server_uuid'),
            ]) }}">
            <button>General</button>
        </a>
        <a class="{{ request()->routeIs('server.private-key') ? 'dark:text-white' : '' }}"
            href="{{ route('server.private-key', [
                'server_uuid' => data_get($parameters, 'server_uuid'),
            ]) }}">
            <button>Private Key</button>
        </a>
        <a class="{{ request()->routeIs('server.resources') ? 'dark:text-white' : '' }}"
            href="{{ route('server.resources', [
                'server_uuid' => data_get($parameters, 'server_uuid'),
            ]) }}">
            <button>Resources</button>
        </a>
        @if (!$server->isSwarmWorker() && !$server->settings->is_build_server)
            <a class="{{ request()->routeIs('server.proxy') ? 'dark:text-white' : '' }}"
                href="{{ route('server.proxy', [
                    'server_uuid' => data_get($parameters, 'server_uuid'),
                ]) }}">
                <button>Proxy</button>
            </a>
            <a class="{{ request()->routeIs('server.destinations') ? 'dark:text-white' : '' }}"
                href="{{ route('server.destinations', [
                    'server_uuid' => data_get($parameters, 'server_uuid'),
                ]) }}">
                <button>Destinations</button>
            </a>
            <a class="{{ request()->routeIs('server.log-drains') ? 'dark:text-white' : '' }}"
                href="{{ route('server.log-drains', [
                    'server_uuid' => data_get($parameters, 'server_uuid'),
                ]) }}">
                <button>Log Drains</button>
            </a>
        @endif

        <div class="flex-1"></div>
        @if (
            $server->proxyType() !== 'NONE' &&
                $server->isFunctional() &&
                !$server->isSwarmWorker() &&
                !$server->settings->is_build_server)
            <livewire:server.proxy.deploy :server="$server" />
        @endif
    </nav>
</div>
