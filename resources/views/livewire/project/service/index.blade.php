<div x-data="{ activeTab: window.location.hash ? window.location.hash.substring(1) : 'general' }">
    <livewire:project.service.navbar :service="$service" :parameters="$parameters" :query="$query" />
    <div class="flex h-full pt-6">
        <div class="flex flex-col gap-4 min-w-fit">
            <a class="{{ request()->routeIs('project.service.configuration') ? 'dark:text-white' : '' }}"
                href="{{ route('project.service.configuration', [...$parameters, 'stack_service_uuid' => null]) }}">
                <button><- Back</button>
            </a>
            <a :class="activeTab === 'general' && 'dark:text-white'"
                @click.prevent="activeTab = 'general'; window.location.hash = 'general'; if(window.location.search) window.location.search = ''"
                href="#">General</a>
            <a :class="activeTab === 'storages' && 'dark:text-white'"
                @click.prevent="activeTab = 'storages'; window.location.hash = 'storages'; if(window.location.search) window.location.search = ''"
                href="#">Storages
            </a>
            <a :class="activeTab === 'scheduled-tasks' && 'dark:text-white'"
                @click.prevent="activeTab = 'scheduled-tasks'; window.location.hash = 'scheduled-tasks'"
                href="#">Scheduled Tasks
            </a>
            @if (str($serviceDatabase?->databaseType())->contains('mysql') ||
                    str($serviceDatabase?->databaseType())->contains('postgres') ||
                    str($serviceDatabase?->databaseType())->contains('mariadb'))
                <a :class="activeTab === 'backups' && 'dark:text-white'"
                    @click.prevent="activeTab = 'backups'; window.location.hash = 'backups'" href="#">Backups</a>
            @endif
        </div>
        <div class="w-full pl-8">
            @isset($serviceApplication)
                <div x-cloak x-show="activeTab === 'general'" class="h-full">
                    <livewire:project.service.service-application-view :application="$serviceApplication" />
                </div>
                <div x-cloak x-show="activeTab === 'storages'">
                    <div class="flex items-center gap-2">
                        <h2>Storages</h2>
                    </div>
                    <div class="pb-4">Persistent storage to preserve data between deployments.</div>
                    <span class="dark:text-warning">Please modify storage layout in your Docker Compose file.</span>
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
                    <span class="dark:text-warning">Please modify storage layout in your Docker Compose file.</span>
                    <livewire:project.service.storage wire:key="application-{{ $serviceDatabase->id }}" :resource="$serviceDatabase" />
                </div>
                <div x-cloak x-show="activeTab === 'backups'">
                    <div class="flex gap-2 ">
                        <h2 class="pb-4">Scheduled Backups</h2>
                        <x-modal-input buttonTitle="+ Add" title="New Scheduled Backup">
                            <livewire:project.database.create-scheduled-backup :database="$serviceDatabase" :s3s="$s3s" />
                        </x-modal-input>
                    </div>
                    <livewire:project.database.scheduled-backups :database="$serviceDatabase" />
                </div>
            @endisset
            <div x-cloak x-show="activeTab === 'scheduled-tasks'">
                <livewire:project.shared.scheduled-task.all :resource="$service" />
            </div>
        </div>
    </div>
</div>
