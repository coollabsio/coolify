@if ($server->proxySet())
    <div class="flex h-full pr-4">
        <div class="flex flex-col w-48 gap-4 min-w-fit">
            <a class="{{ request()->routeIs('server.proxy') ? 'dark:text-white' : '' }}"
                href="{{ route('server.proxy', $parameters) }}">
                <button>Configuration</button>
            </a>
            <a class="{{ request()->routeIs('server.proxy.dynamic-confs') ? 'dark:text-white' : '' }}"
                href="{{ route('server.proxy.dynamic-confs', $parameters) }}">
                <button>Dynamic Configurations</button>
            </a>
            <a class="{{ request()->routeIs('server.proxy.logs') ? 'dark:text-white' : '' }}"
                href="{{ route('server.proxy.logs', $parameters) }}">
                <button>Logs</button>
            </a>
        </div>
    </div>
@endif
