<div class="flex flex-col items-start gap-2 min-w-fit">
    <a wire:navigate class="menu-item {{ $activeMenu === 'general' ? 'menu-item-active' : '' }}"
        href="{{ route('server.show', ['server_uuid' => $server->uuid]) }}">General</a>
    <a wire:navigate class="menu-item {{ $activeMenu === 'private-key' ? 'menu-item-active' : '' }}"
        href="{{ route('server.private-key', ['server_uuid' => $server->uuid]) }}">Private Key
    </a>
    @if (!$server->isLocalhost())
        <a wire:navigate class="menu-item {{ $activeMenu === 'cloudflare-tunnels' ? 'menu-item-active' : '' }}"
            href="{{ route('server.cloudflare-tunnels', ['server_uuid' => $server->uuid]) }}">Cloudflare
            Tunnels</a>
    @endif
    @if ($server->isFunctional())
        <a wire:navigate class="menu-item {{ $activeMenu === 'docker-cleanup' ? 'menu-item-active' : '' }}"
            href="{{ route('server.docker-cleanup', ['server_uuid' => $server->uuid]) }}">Docker Cleanup
        </a>
        <a wire:navigate class="menu-item {{ $activeMenu === 'destinations' ? 'menu-item-active' : '' }}"
            href="{{ route('server.destinations', ['server_uuid' => $server->uuid]) }}">Destinations
        </a>
        <a wire:navigate class="menu-item {{ $activeMenu === 'advanced' ? 'menu-item-active' : '' }}"
            href="{{ route('server.advanced', ['server_uuid' => $server->uuid]) }}">Advanced
        </a>
        <a wire:navigate class="menu-item {{ $activeMenu === 'log-drains' ? 'menu-item-active' : '' }}"
            href="{{ route('server.log-drains', ['server_uuid' => $server->uuid]) }}">Log
            Drains</a>
        <a class="menu-item {{ $activeMenu === 'metrics' ? 'menu-item-active' : '' }}"
            href="{{ route('server.charts', ['server_uuid' => $server->uuid]) }}">Metrics</a>
    @endif
    @if (!$server->isLocalhost())
        <a wire:navigate class="menu-item {{ $activeMenu === 'danger' ? 'menu-item-active' : '' }}"
            href="{{ route('server.delete', ['server_uuid' => $server->uuid]) }}">Danger</a>
    @endif
</div>
