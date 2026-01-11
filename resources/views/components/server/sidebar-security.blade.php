<div class="sub-menu-wrapper">
    <a class="{{ request()->routeIs('server.security.patches') ? 'menu-item menu-item-active' : 'menu-item' }}" {{ wireNavigate() }}
        href="{{ route('server.security.patches', $parameters) }}">
        <span class="menu-item-label">Server Patching</span>
    </a>
    <a class="{{ request()->routeIs('server.security.terminal-access') ? 'menu-item menu-item-active' : 'menu-item' }}"
        href="{{ route('server.security.terminal-access', $parameters) }}">
        <span class="menu-item-label">Terminal Access</span>
    </a>
</div>
