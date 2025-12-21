<div class="flex flex-col items-start gap-2 min-w-fit">
    <a class="menu-item {{ $activeMenu === 'general' ? 'menu-item-active' : '' }}" {{ wireNavigate() }}
        href="{{ route('server.show', ['server_uuid' => $server->uuid]) }}">{{ __('menu.general') }}</a>
    @if ($server->isFunctional())
        <a class="menu-item {{ $activeMenu === 'advanced' ? 'menu-item-active' : '' }}" {{ wireNavigate() }}
            href="{{ route('server.advanced', ['server_uuid' => $server->uuid]) }}">{{ __('menu.advanced') }}
        </a>
    @endif
    @if (!$server->isBuildServer() && !$server->settings->is_cloudflare_tunnel)
        <a class="menu-item {{ $activeMenu === 'swarm' ? 'menu-item-active' : '' }}" {{ wireNavigate() }}
            href="{{ route('server.swarm', ['server_uuid' => $server->uuid]) }}">{{ __('menu.swarm') }}
        </a>
    @endif
    @if ($server->isFunctional() && !$server->isSwarm() && !$server->isBuildServer())
        <a class="menu-item {{ $activeMenu === 'sentinel' ? 'menu-item-active' : '' }}" {{ wireNavigate() }}
            href="{{ route('server.sentinel', ['server_uuid' => $server->uuid]) }}">{{ __('menu.sentinel') }}
        </a>
    @endif
    <a class="menu-item {{ $activeMenu === 'private-key' ? 'menu-item-active' : '' }}" {{ wireNavigate() }}
        href="{{ route('server.private-key', ['server_uuid' => $server->uuid]) }}">{{ __('menu.private_key') }}
    </a>
    @if ($server->hetzner_server_id)
        <a class="menu-item {{ $activeMenu === 'cloud-provider-token' ? 'menu-item-active' : '' }}" {{ wireNavigate() }}
            href="{{ route('server.cloud-provider-token', ['server_uuid' => $server->uuid]) }}">{{ __('modal.add_hetzner_token') }}
        </a>
    @endif
    <a class="menu-item {{ $activeMenu === 'ca-certificate' ? 'menu-item-active' : '' }}" {{ wireNavigate() }}
        href="{{ route('server.ca-certificate', ['server_uuid' => $server->uuid]) }}">{{ __('menu.ca_certificate') }}
    </a>
    @if (!$server->isLocalhost())
        <a class="menu-item {{ $activeMenu === 'cloudflare-tunnel' ? 'menu-item-active' : '' }}" {{ wireNavigate() }}
            href="{{ route('server.cloudflare-tunnel', ['server_uuid' => $server->uuid]) }}">{{ __('server.cloudflare_tunnel') ?? 'Cloudflare Tunnel' }}
        </a>
    @endif
    @if ($server->isFunctional())
        <a class="menu-item {{ $activeMenu === 'docker-cleanup' ? 'menu-item-active' : '' }}" {{ wireNavigate() }}
            href="{{ route('server.docker-cleanup', ['server_uuid' => $server->uuid]) }}">{{ __('menu.docker_cleanup') }}
        </a>
        <a class="menu-item {{ $activeMenu === 'destinations' ? 'menu-item-active' : '' }}" {{ wireNavigate() }}
            href="{{ route('server.destinations', ['server_uuid' => $server->uuid]) }}">{{ __('menu.destinations') }}
        </a>
        <a class="menu-item {{ $activeMenu === 'log-drains' ? 'menu-item-active' : '' }}" {{ wireNavigate() }}
            href="{{ route('server.log-drains', ['server_uuid' => $server->uuid]) }}">{{ __('menu.log_drains') }}
        </a>
        <a class="menu-item {{ $activeMenu === 'metrics' ? 'menu-item-active' : '' }}" {{ wireNavigate() }}
            href="{{ route('server.charts', ['server_uuid' => $server->uuid]) }}">{{ __('menu.metrics') }}</a>
    @endif
    @if (!$server->isLocalhost())
        <a class="menu-item {{ $activeMenu === 'danger' ? 'menu-item-active' : '' }}" {{ wireNavigate() }}
            href="{{ route('server.delete', ['server_uuid' => $server->uuid]) }}">{{ __('menu.danger_zone') }}</a>
    @endif
</div>
