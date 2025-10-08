<div class="flex flex-col items-start gap-2 min-w-fit">
    <a class="{{ request()->routeIs('server.security.patches') ? 'menu-item menu-item-active' : 'menu-item' }}"
        href="{{ route('server.security.patches', $parameters) }}">
        Server Patching
    </a>
    <a class="{{ request()->routeIs('server.security.terminal-access') ? 'menu-item menu-item-active' : 'menu-item' }}"
        href="{{ route('server.security.terminal-access', $parameters) }}">
        Terminal Access
    </a>
</div>
