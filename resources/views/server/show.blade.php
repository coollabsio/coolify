<x-layout>
    <div class="text-3xl font-bold">Server</div>
    <div x-data="{ activeTab: window.location.hash ? window.location.hash.substring(1) : 'general' }" class="flex pt-6">
        <div class="flex flex-col min-w-fit">
            <a :class="activeTab === 'general' && 'text-white'"
                @click.prevent="activeTab = 'general'; window.location.hash = 'general'" href="#">General</a>
            <a :class="activeTab === 'proxy' && 'text-white'"
                @click.prevent="activeTab = 'proxy'; window.location.hash = 'proxy'" href="#">Proxy
            </a>
        </div>
        <div class="w-full pl-8">
            <div x-cloak x-show="activeTab === 'general'">
                <livewire:server.form :server_id="$server->id" />
            </div>
            <div x-cloak x-show="activeTab === 'proxy'">
                <livewire:server.proxy :server="$server" />
            </div>
        </div>
    </div>
</x-layout>
