@if ($server->proxySet())
    <div class="flex flex-col items-start gap-2 min-w-fit">
        <a wire:navigate class="{{ request()->routeIs('server.proxy') ? 'menu-item menu-item-active' : 'menu-item' }}"
            href="{{ route('server.proxy', $parameters) }}">
            <button>Configuration</button>
        </a>
        <a wire:navigate class="{{ request()->routeIs('server.proxy.dynamic-confs') ? 'menu-item menu-item-active' : 'menu-item' }}"
            href="{{ route('server.proxy.dynamic-confs', $parameters) }}">
            <button>Dynamic Configurations</button>
        </a>
        <a wire:navigate class="{{ request()->routeIs('server.proxy.logs') ? 'menu-item menu-item-active' : 'menu-item' }}"
            href="{{ route('server.proxy.logs', $parameters) }}">
            <button>Logs</button>
        </a>
    </div>
@endif
