<x-layout>
    <div class="text-3xl font-bold">Server</div>
    <div class="flex flex-col pb-4">
        @if ($server->settings->is_validated)
            <div class="text-green-400">Validated</div>
        @else
            <div class="text-red-400">Not validated</div>
        @endif
    </div>
    <div x-data="{ activeTab: window.location.hash ? window.location.hash.substring(1) : 'general' }">
        <div class="flex gap-4">
            <a :class="activeTab === 'general' && 'text-purple-500'"
                @click.prevent="activeTab = 'general'; window.location.hash = 'general'" href="#">General</a>
            <a :class="activeTab === 'private-key' && 'text-purple-500'"
                @click.prevent="activeTab = 'private-key'; window.location.hash = 'private-key'" href="#">Private
                Key</a>
            <a :class="activeTab === 'destinations' && 'text-purple-500'"
                @click.prevent="activeTab = 'destinations'; window.location.hash = 'destinations'"
                href="#">Destinations
            </a>
            <a :class="activeTab === 'proxy' && 'text-purple-500'"
                @click.prevent="activeTab = 'proxy'; window.location.hash = 'proxy'" href="#">Proxy
            </a>
        </div>
        <div x-cloak x-show="activeTab === 'general'">
            <h3>General Configurations</h3>
            <livewire:server.form :server_id="$server->id" />
        </div>
        <div x-cloak x-show="activeTab === 'private-key'">
            <div class="flex items-center gap-2">
                <h3>Private Keys</h3>
                <a href="{{ route('server.private-key', ['server_uuid' => $server->uuid]) }}">
                    <x-inputs.button>Change</x-inputs.button>
                </a>
            </div>
            <p>{{ $server->privateKey->name }}</p>
        </div>
        <div x-cloak x-show="activeTab === 'destinations'">
            <div class="flex items-center gap-2">
                <h3>Destinations</h3>
                <a href="{{ route('destination.new', ['server_id' => $server->id]) }}">
                    <x-inputs.button isBold>New</x-inputs.button>
                </a>
            </div>
            @if ($server->standaloneDockers->count() > 0)
                @foreach ($server->standaloneDockers as $docker)
                    <p>Network: {{ data_get($docker, 'network') }}</p>
                @endforeach
            @else
                <p>No destinations found</p>
            @endif
        </div>
        <div x-cloak x-show="activeTab === 'proxy'">
            <div class="flex items-center gap-2">
                <h3>Proxy</h3>
                @if ($server->settings->is_validated)
                    <div>{{ $server->extra_attributes->proxy_status }}</div>
                @endif
            </div>
            <livewire:server.proxy :server="$server" />
        </div>

    </div>



</x-layout>
