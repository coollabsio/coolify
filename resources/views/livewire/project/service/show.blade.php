<div x-data="{ activeTab: window.location.hash ? window.location.hash.substring(1) : 'general' }">
    <livewire:project.service.navbar :service="$service" :parameters="$parameters" :query="$query" />
    <div class="flex h-full pt-6">
        <div class="flex flex-col gap-4 min-w-fit">
            <a class="{{ request()->routeIs('project.service') ? 'text-white' : '' }}"
                href="{{ route('project.service', [...$parameters, 'service_name' => null]) }}">
                <button><- Back</button>
            </a>
            <a :class="activeTab === 'general' && 'text-white'"
                @click.prevent="activeTab = 'general'; window.location.hash = 'general'" href="#">General</a>
            <a :class="activeTab === 'storages' && 'text-white'"
                @click.prevent="activeTab = 'storages'; window.location.hash = 'storages'" href="#">Storages
            </a>
            @if (data_get($parameters, 'service_name'))
                <a class="{{ request()->routeIs('project.service.logs') ? 'text-white' : '' }}"
                    href="{{ route('project.service.logs', $parameters) }}">
                    <button>Logs</button>
                </a>
            @endif
        </div>
        <div class="w-full pl-8">
            @isset($serviceApplication)
                <div x-cloak x-show="activeTab === 'general'" class="h-full">
                    <livewire:project.service.application :application="$serviceApplication" />
                </div>
                <div x-cloak x-show="activeTab === 'storages'">
                    <div class="flex items-center gap-2">
                        <h2>Storages</h2>
                    </div>
                    <div class="pb-4">Persistent storage to preserve data between deployments.</div>
                    <span class="text-warning">Please modify storage layout in your Docker Compose file.</span>
                    <livewire:project.service.storage wire:key="application-{{ $serviceApplication->id }}"
                        :resource="$serviceApplication" />
                </div>
            @endisset
            @isset($serviceDatabase)
                <div x-cloak x-show="activeTab === 'general'" class="h-full">
                    <livewire:project.service.database :database="$serviceDatabase" />
                </div>
                <div x-cloak x-show="activeTab === 'storages'">
                    <div class="flex items-center gap-2">
                        <h2>Storages</h2>
                    </div>
                    <div class="pb-4">Persistent storage to preserve data between deployments.</div>
                    <span class="text-warning">Please modify storage layout in your Docker Compose file.</span>
                    <livewire:project.service.storage wire:key="application-{{ $serviceDatabase->id }}"
                        :resource="$serviceDatabase" />
                </div>
            @endisset
        </div>
    </div>
</div>
