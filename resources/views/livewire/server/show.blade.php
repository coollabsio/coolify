<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Server Configurations | Coolify
    </x-slot>
    <x-server.navbar :server="$server" :parameters="$parameters" />
    <div x-data="{ activeTab: window.location.hash ? window.location.hash.substring(1) : 'general' }" class="flex flex-col h-full gap-8 sm:flex-row">
        <div class="flex flex-col items-start gap-2 min-w-fit">
            <a class="menu-item" :class="activeTab === 'general' && 'menu-item-active'"
                @click.prevent="activeTab = 'general'; window.location.hash = 'general'" href="#">General</a>
            @if ($server->isFunctional())
                <a class="menu-item" :class="activeTab === 'advanced' && 'menu-item-active'"
                    @click.prevent="activeTab = 'advanced'; window.location.hash = 'advanced'" href="#">Advanced
                </a>
            @endif
            <a class="menu-item" :class="activeTab === 'private-key' && 'menu-item-active'"
                @click.prevent="activeTab = 'private-key'; window.location.hash = 'private-key'" href="#">Private
                Key</a>
            @if ($server->isFunctional())
                <a class="menu-item" :class="activeTab === 'cloudflare-tunnels' && 'menu-item-active'"
                    @click.prevent="activeTab = 'cloudflare-tunnels'; window.location.hash = 'cloudflare-tunnels'"
                    href="#">Cloudflare Tunnels</a>
                <a class="menu-item" :class="activeTab === 'destinations' && 'menu-item-active'"
                    @click.prevent="activeTab = 'destinations'; window.location.hash = 'destinations'"
                    href="#">Destinations</a>
                <a class="menu-item" :class="activeTab === 'log-drains' && 'menu-item-active'"
                    @click.prevent="activeTab = 'log-drains'; window.location.hash = 'log-drains'" href="#">Log
                    Drains</a>
                {{-- <a class="menu-item" :class="activeTab === 'metrics' && 'menu-item-active'"
                    @click.prevent="activeTab = 'metrics'; window.location.hash = 'metrics'" href="#">Metrics</a> --}}
            @endif
            @if (!$server->isLocalhost())
                <a class="menu-item" :class="activeTab === 'danger' && 'menu-item-active'"
                    @click.prevent="activeTab = 'danger'; window.location.hash = 'danger'" href="#">Danger</a>
            @endif
        </div>
        <div class="w-full">
            <div x-cloak x-show="activeTab === 'general'" class="h-full">
                <livewire:server.form :server="$server" />
            </div>
            <div x-cloak x-show="activeTab === 'advanced'" class="h-full">
                <livewire:server.advanced :server="$server" />
            </div>
            <div x-cloak x-show="activeTab === 'private-key'" class="h-full">
                <livewire:server.private-key.show :server="$server" />
            </div>
            <div x-cloak x-show="activeTab === 'cloudflare-tunnels'" class="h-full">
                <livewire:server.cloudflare-tunnels :server="$server" />
            </div>
            <div x-cloak x-show="activeTab === 'destinations'" class="h-full">
                <livewire:server.destination.show :server="$server" />
            </div>
            <div x-cloak x-show="activeTab === 'log-drains'" class="h-full">
                <livewire:server.log-drains :server="$server" />
            </div>
            {{-- <div x-cloak x-show="activeTab === 'metrics'" class="h-full">
                @if ($server->isFunctional() && $server->isMetricsEnabled())
                    <h2>Metrics</h2>
                    <div class="pb-4">Basic metrics for your container.</div>
                    <div>
                        <livewire:server.charts :server="$server" />
                    </div>
                @else
                    No metrics available.
                @endif
            </div> --}}
            @if (!$server->isLocalhost())
                <div x-cloak x-show="activeTab === 'danger'" class="h-full">
                    <livewire:server.delete :server="$server" />
                </div>
            @endif
        </div>
    </div>
</div>
