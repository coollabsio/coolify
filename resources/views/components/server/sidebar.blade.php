<div class="sub-menu-wrapper">
    <a class="sub-menu-item {{ $activeMenu === 'general' ? 'menu-item-active' : '' }}" {{ wireNavigate() }}
        href="{{ route('server.show', ['server_uuid' => $server->uuid]) }}"><span class="menu-item-label">General</span></a>
    @if ($server->isFunctional())
        <a class="sub-menu-item {{ $activeMenu === 'advanced' ? 'menu-item-active' : '' }}" {{ wireNavigate() }}
            href="{{ route('server.advanced', ['server_uuid' => $server->uuid]) }}"><span class="menu-item-label">Advanced</span>
        </a>
    @endif
    @if ($server->isFunctional() && !$server->isSwarm() && !$server->isBuildServer())
        <a class="sub-menu-item {{ $activeMenu === 'sentinel' ? 'menu-item-active' : '' }}" {{ wireNavigate() }}
            href="{{ route('server.sentinel', ['server_uuid' => $server->uuid]) }}"><span class="menu-item-label">Sentinel</span>
        </a>
    @endif
    <a class="sub-menu-item {{ $activeMenu === 'private-key' ? 'menu-item-active' : '' }}" {{ wireNavigate() }}
        href="{{ route('server.private-key', ['server_uuid' => $server->uuid]) }}"><span class="menu-item-label">Private Key</span>
    </a>
    @if ($server->hetzner_server_id)
        <a class="sub-menu-item {{ $activeMenu === 'cloud-provider-token' ? 'menu-item-active' : '' }}"
            {{ wireNavigate() }}
            href="{{ route('server.cloud-provider-token', ['server_uuid' => $server->uuid]) }}"><span class="menu-item-label">Hetzner Token</span>
        </a>
    @endif
    <a class="sub-menu-item {{ $activeMenu === 'ca-certificate' ? 'menu-item-active' : '' }}" {{ wireNavigate() }}
        href="{{ route('server.ca-certificate', ['server_uuid' => $server->uuid]) }}"><span class="menu-item-label">CA Certificate</span>
    </a>
    @if (!$server->isLocalhost())
        <a class="sub-menu-item {{ $activeMenu === 'cloudflare-tunnel' ? 'menu-item-active' : '' }}" {{ wireNavigate() }}
            href="{{ route('server.cloudflare-tunnel', ['server_uuid' => $server->uuid]) }}"><span class="menu-item-label">Cloudflare Tunnel</span></a>
    @endif
    @if ($server->isFunctional())
        <a class="sub-menu-item {{ $activeMenu === 'docker-cleanup' ? 'menu-item-active' : '' }}" {{ wireNavigate() }}
            href="{{ route('server.docker-cleanup', ['server_uuid' => $server->uuid]) }}"><span class="menu-item-label">Docker Cleanup</span>
        </a>
        <a class="sub-menu-item {{ $activeMenu === 'destinations' ? 'menu-item-active' : '' }}" {{ wireNavigate() }}
            href="{{ route('server.destinations', ['server_uuid' => $server->uuid]) }}"><span class="menu-item-label">Destinations</span>
        </a>
        <a class="sub-menu-item {{ $activeMenu === 'log-drains' ? 'menu-item-active' : '' }}" {{ wireNavigate() }}
            href="{{ route('server.log-drains', ['server_uuid' => $server->uuid]) }}"><span class="menu-item-label">Log Drains</span></a>
        <a class="sub-menu-item {{ $activeMenu === 'metrics' ? 'menu-item-active' : '' }}" {{ wireNavigate() }}
            href="{{ route('server.charts', ['server_uuid' => $server->uuid]) }}"><span class="menu-item-label">Metrics</span></a>
    @endif
    @if (!$server->isBuildServer() && !$server->settings->is_cloudflare_tunnel)
        <a class="sub-menu-item {{ $activeMenu === 'swarm' ? 'menu-item-active' : '' }}" {{ wireNavigate() }}
            href="{{ route('server.swarm', ['server_uuid' => $server->uuid]) }}"><span class="menu-item-label">Swarm (experimental)</span>
        </a>
    @endif
    @if (!$server->isLocalhost())
        <a class="sub-menu-item {{ $activeMenu === 'danger' ? 'menu-item-active' : '' }}" {{ wireNavigate() }}
            href="{{ route('server.delete', ['server_uuid' => $server->uuid]) }}"><span class="menu-item-label">Danger</span></a>
    @endif
</div>
