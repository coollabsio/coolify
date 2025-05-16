<div class="flex flex-col items-start gap-2 min-w-fit">
    <a class="{{ request()->routeIs('server.security.patches') ? 'menu-item menu-item-active' : 'menu-item' }}"
        href="{{ route('server.security.patches', $parameters) }}">
        <button>Server Patching</button>
    </a>
</div>
