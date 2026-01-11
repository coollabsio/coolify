<div class="sub-menu-wrapper">
    <a class="{{ request()->routeIs('server.proxy') ? 'menu-item menu-item-active' : 'menu-item' }}" {{ wireNavigate() }}
        href="{{ route('server.proxy', $parameters) }}">
        <span class="menu-item-label">Configuration</span>
    </a>
    @if ($server->proxySet())
        <a class="{{ request()->routeIs('server.proxy.dynamic-confs') ? 'menu-item menu-item-active' : 'menu-item' }}" {{ wireNavigate() }}
            href="{{ route('server.proxy.dynamic-confs', $parameters) }}">
            <span class="menu-item-label">Dynamic Configurations</span>
        </a>
        <a class="{{ request()->routeIs('server.proxy.logs') ? 'menu-item menu-item-active' : 'menu-item' }}"
            href="{{ route('server.proxy.logs', $parameters) }}">
            <span class="menu-item-label">Logs</span>
        </a>
    @endif
</div>
