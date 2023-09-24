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
        </div>
        <div class="w-full pl-8">
            @isset($serviceApplication)
                <div x-cloak x-show="activeTab === 'general'" class="h-full">
                    <livewire:project.service.application :application="$serviceApplication" />
                </div>
                <div x-cloak x-show="activeTab === 'storages'">
                    <livewire:project.shared.storages.all :resource="$serviceApplication" />
                </div>
            @endisset
            @isset($serviceDatabase)
                <div x-cloak x-show="activeTab === 'general'" class="h-full">
                    <livewire:project.service.database :database="$serviceDatabase" />
                </div>
                <div x-cloak x-show="activeTab === 'storages'">
                    <livewire:project.shared.storages.all :resource="$serviceDatabase" />
                </div>
            @endisset
        </div>
    </div>
</div>
