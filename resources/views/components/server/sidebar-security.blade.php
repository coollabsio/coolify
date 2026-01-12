<div class="sub-menu-wrapper">
    <a class="{{ request()->routeIs('server.security.patches') ? 'sub-menu-item menu-item-active' : 'sub-menu-item' }}" {{ wireNavigate() }}
        href="{{ route('server.security.patches', $parameters) }}">
        <span class="menu-item-label">Server Patching</span>
    </a>
    <a class="{{ request()->routeIs('server.security.terminal-access') ? 'sub-menu-item menu-item-active' : 'sub-menu-item' }}"
        href="{{ route('server.security.terminal-access', $parameters) }}">
        <span class="menu-item-label">Terminal Access</span>
    </a>
</div>
