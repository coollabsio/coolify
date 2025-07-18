<div x-data="{ activeTab: window.location.hash ? window.location.hash.substring(1) : 'general' }">
    <livewire:project.service.heading :service="$service" :parameters="$parameters" :query="$query" />
    <div class="flex flex-col h-full gap-8 sm:flex-row">
        <div class="flex flex-col items-start gap-2 min-w-fit">
            <a class="menu-item"
                class="{{ request()->routeIs('project.service.configuration') ? 'menu-item-active' : '' }}"
                href="{{ route('project.service.configuration', [...$parameters, 'stack_service_uuid' => null]) }}">
                <button><- Back</button>
            </a>
            <a class="menu-item" :class="activeTab === 'general' && 'menu-item-active'"
                @click.prevent="activeTab = 'general'; window.location.hash = 'general'; if(window.location.search) window.location.search = ''"
                href="#">General</a>
            @if ($serviceDatabase?->isBackupSolutionAvailable() || $serviceDatabase?->is_migrated)
                <a :class="activeTab === 'backups' && 'menu-item-active'" class="menu-item"
                    @click.prevent="activeTab = 'backups'; window.location.hash = 'backups'" href="#backups">Backups</a>
            @endif
        </div>
        <div class="w-full">
            @isset($serviceApplication)
                <x-slot:title>
                    {{ data_get_str($service, 'name')->limit(10) }} >
                    {{ data_get_str($serviceApplication, 'name')->limit(10) }} | Coolify
                </x-slot>
                <div x-cloak x-show="activeTab === 'general'" class="h-full">
                    <livewire:project.service.service-application-view :application="$serviceApplication" />
                </div>
            @endisset
            @isset($serviceDatabase)
                <x-slot:title>
                    {{ data_get_str($service, 'name')->limit(10) }} >
                    {{ data_get_str($serviceDatabase, 'name')->limit(10) }} | Coolify
                </x-slot>
                <div x-cloak x-show="activeTab === 'general'" class="h-full">
                    <livewire:project.service.database :database="$serviceDatabase" />
                </div>
                @if ($serviceDatabase?->isBackupSolutionAvailable() || $serviceDatabase?->is_migrated)
                    <div x-cloak x-show="activeTab === 'backups'">
                        <div class="flex gap-2">
                            <h2 class="pb-4">Scheduled Backups</h2>
                            @if (filled($serviceDatabase->custom_type) || !$serviceDatabase->is_migrated)
                                <x-modal-input buttonTitle="+ Add" title="New Scheduled Backup">
                                    <livewire:project.database.create-scheduled-backup :database="$serviceDatabase" />
                                </x-modal-input>
                            @endif
                        </div>
                        <livewire:project.database.scheduled-backups :database="$serviceDatabase" />
                    </div>
                @endif
            @endisset
        </div>
    </div>
</div>
