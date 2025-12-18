<div class="flex flex-col items-start gap-2 min-w-fit">
    <a class="menu-item {{ $activeMenu === 'general' ? 'menu-item-active' : '' }}" {{ wireNavigate() }}
        href="{{ route('server.show', ['server_uuid' => $server->uuid]) }}">General</a>
    @if ($server->isFunctional())
        <a class="menu-item {{ $activeMenu === 'advanced' ? 'menu-item-active' : '' }}" {{ wireNavigate() }}
            href="{{ route('server.advanced', ['server_uuid' => $server->uuid]) }}">Advanced
        </a>
    @endif
    <a class="menu-item {{ $activeMenu === 'private-key' ? 'menu-item-active' : '' }}" {{ wireNavigate() }}
        href="{{ route('server.private-key', ['server_uuid' => $server->uuid]) }}">Private Key
    </a>
    @if ($server->hetzner_server_id)
        <a class="menu-item {{ $activeMenu === 'cloud-provider-token' ? 'menu-item-active' : '' }}" {{ wireNavigate() }}
            href="{{ route('server.cloud-provider-token', ['server_uuid' => $server->uuid]) }}">Hetzner Token
        </a>
    @endif
    <a class="menu-item {{ $activeMenu === 'ca-certificate' ? 'menu-item-active' : '' }}" {{ wireNavigate() }}
        href="{{ route('server.ca-certificate', ['server_uuid' => $server->uuid]) }}">CA Certificate
    </a>
    @if (!$server->isLocalhost())
        <a class="menu-item {{ $activeMenu === 'cloudflare-tunnel' ? 'menu-item-active' : '' }}" {{ wireNavigate() }}
            href="{{ route('server.cloudflare-tunnel', ['server_uuid' => $server->uuid]) }}">Cloudflare
            Tunnel</a>
    @endif
    @if ($server->isFunctional())
        <a class="menu-item {{ $activeMenu === 'docker-cleanup' ? 'menu-item-active' : '' }}" {{ wireNavigate() }}
            href="{{ route('server.docker-cleanup', ['server_uuid' => $server->uuid]) }}">Docker Cleanup
        </a>
        <a class="menu-item {{ $activeMenu === 'destinations' ? 'menu-item-active' : '' }}" {{ wireNavigate() }}
            href="{{ route('server.destinations', ['server_uuid' => $server->uuid]) }}">Destinations
        </a>
        <a class="menu-item {{ $activeMenu === 'log-drains' ? 'menu-item-active' : '' }}" {{ wireNavigate() }}
            href="{{ route('server.log-drains', ['server_uuid' => $server->uuid]) }}">Log
            Drains</a>
        <a class="menu-item {{ $activeMenu === 'metrics' ? 'menu-item-active' : '' }}" {{ wireNavigate() }}
            href="{{ route('server.charts', ['server_uuid' => $server->uuid]) }}">Metrics</a>
    @endif
    @if (!$server->isLocalhost())
        <a class="menu-item {{ $activeMenu === 'danger' ? 'menu-item-active' : '' }}" {{ wireNavigate() }}
            href="{{ route('server.delete', ['server_uuid' => $server->uuid]) }}">Danger</a>
    @endif
</div>
