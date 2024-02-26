<div class="flex h-full pr-4">
    <div class="flex flex-col w-48 gap-4 min-w-fit">
        <a class="{{ request()->routeIs('server.proxy') ? 'text-white' : '' }}"
            href="{{ route('server.proxy', $parameters) }}">
            <button>Configuration</button>
        </a>
        @if (data_get($server, 'proxy.type') !== 'NONE')
            <a class="{{ request()->routeIs('server.proxy.dynamic-confs') ? 'text-white' : '' }}"
                href="{{ route('server.proxy.dynamic-confs', $parameters) }}">
                <button>Dynamic Configurations</button>
            </a>
            <a class="{{ request()->routeIs('server.proxy.logs') ? 'text-white' : '' }}"
                href="{{ route('server.proxy.logs', $parameters) }}">
                <button>Logs</button>
            </a>
        @endif
    </div>
</div>
