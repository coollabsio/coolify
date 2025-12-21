<div class="flex flex-col items-start gap-2 min-w-fit">
    <a class="{{ request()->routeIs('server.security.patches') ? 'menu-item menu-item-active' : 'menu-item' }}" {{ wireNavigate() }}
        href="{{ route('server.security.patches', $parameters) }}">
        {{ __('server.server_patching') }}
    </a>
    <a class="{{ request()->routeIs('server.security.terminal-access') ? 'menu-item menu-item-active' : 'menu-item' }}"
        href="{{ route('server.security.terminal-access', $parameters) }}">
        {{ __('server.terminal_access') }}
    </a>
</div>
