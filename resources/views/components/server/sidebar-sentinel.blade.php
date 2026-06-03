<div class="sub-menu-wrapper">
    <a class="{{ request()->routeIs('server.sentinel') ? 'sub-menu-item menu-item-active' : 'sub-menu-item' }}" {{ wireNavigate() }}
        href="{{ route('server.sentinel', $parameters) }}">
        <span class="menu-item-label">Configuration</span>
    </a>
    <a class="{{ request()->routeIs('server.sentinel.logs') ? 'sub-menu-item menu-item-active' : 'sub-menu-item' }}" {{ wireNavigate() }}
        href="{{ route('server.sentinel.logs', $parameters) }}">
        <span class="menu-item-label">Logs</span>
    </a>
</div>
