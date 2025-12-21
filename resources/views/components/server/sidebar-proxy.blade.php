<div class="flex flex-col items-start gap-2 min-w-fit">
    <a class="{{ request()->routeIs('server.proxy') ? 'menu-item menu-item-active' : 'menu-item' }}" {{ wireNavigate() }}
        href="{{ route('server.proxy', $parameters) }}">
        <button>{{ __('server.configuration') }}</button>
    </a>
    @if ($server->proxySet())
        <a class="{{ request()->routeIs('server.proxy.dynamic-confs') ? 'menu-item menu-item-active' : 'menu-item' }}" {{ wireNavigate() }}
            href="{{ route('server.proxy.dynamic-confs', $parameters) }}">
            <button>{{ __('server.dynamic_configurations') }}</button>
        </a>
        <a class="{{ request()->routeIs('server.proxy.logs') ? 'menu-item menu-item-active' : 'menu-item' }}"
            href="{{ route('server.proxy.logs', $parameters) }}">
            <button>{{ __('server.logs') }}</button>
        </a>
    @endif
</div>
