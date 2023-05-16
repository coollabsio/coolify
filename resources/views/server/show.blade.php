<x-layout>
    <div class="text-3xl font-bold">Server</div>
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
            <a href="{{ route('private-key.show', ['private_key_uuid' => data_get($server, 'privateKey.uuid')]) }}">
                <x-inputs.button>{{ data_get($server, 'privateKey.uuid') }}</x-inputs.button>
            </a>
        </div>
        <div x-cloak x-show="activeTab === 'destinations'">
            <div class="flex items-center gap-2">
                <h3>Destinations</h3>
                <a href="{{ route('destination.new', ['server_id' => $server->id]) }}">
                    <x-inputs.button>Add a new</x-inputs.button>
                </a>
            </div>
            @if ($server->standaloneDockers->count() > 0)
                @foreach ($server->standaloneDockers as $docker)
                    <a href="{{ route('destination.show', ['destination_uuid' => data_get($docker, 'uuid')]) }}">
                        <x-inputs.button>{{ data_get($docker, 'network') }}</x-inputs.button>
                    </a>
                @endforeach
            @else
                <p>No destinations found</p>
            @endif
        </div>
        <div x-cloak x-show="activeTab === 'proxy'">
            <livewire:server.proxy :server="$server" />
        </div>
    </div>
</x-layout>
