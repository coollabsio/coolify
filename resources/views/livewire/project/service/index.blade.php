<div x-data="{ activeTab: window.location.hash ? window.location.hash.substring(1) : 'general' }">
    <livewire:project.service.navbar :service="$service" :parameters="$parameters" :query="$query" />
    <div class="flex h-full pt-6">
        <div class="flex flex-col gap-4 min-w-fit">
            <a  class="{{ request()->routeIs('project.service.configuration') ? 'text-white' : '' }}"
                href="{{ route('project.service.configuration', [...$parameters, 'service_name' => null]) }}">
                <button><- Back</button>
            </a>
            <a :class="activeTab === 'general' && 'text-white'"
                @click.prevent="activeTab = 'general'; window.location.hash = 'general'; if(window.location.search) window.location.search = ''"
                href="#">General</a>
            <a :class="activeTab === 'storages' && 'text-white'"
                @click.prevent="activeTab = 'storages'; window.location.hash = 'storages'; if(window.location.search) window.location.search = ''"
                href="#">Storages
            </a>
            <a :class="activeTab === 'environment-variables' && 'text-white'"
                @click.prevent="activeTab = 'environment-variables'; window.location.hash = 'environment-variables'"
                href="#">Environment
                Variables</a>
            <a :class="activeTab === 'scheduled-tasks' && 'text-white'"
                @click.prevent="activeTab = 'scheduled-tasks'; window.location.hash = 'scheduled-tasks'"
                href="#">Scheduled Tasks
            </a>
            <a :class="activeTab === 'danger' && 'text-white'"
                @click.prevent="activeTab = 'danger';
                window.location.hash = 'danger'"
                href="#">Danger Zone
         
            @if (
                $serviceDatabase?->databaseType() === 'standalone-mysql' ||
                    $serviceDatabase?->databaseType() === 'standalone-postgresql' ||
                    $serviceDatabase?->databaseType() === 'standalone-mariadb')
                <a :class="activeTab === 'backups' && 'text-white'"
                    @click.prevent="activeTab = 'backups'; window.location.hash = 'backups'" href="#">Backups</a>
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
                    <livewire:project.service.storage wire:key="application-{{ $serviceDatabase->id }}" :resource="$serviceDatabase" />
                </div>
                <div x-cloak x-show="activeTab === 'backups'">
                    <div class="flex gap-2 ">
                        <h2 class="pb-4">Scheduled Backups</h2>
                        <x-forms.button onclick="createScheduledBackup.showModal()">+ Add</x-forms.button>
                    </div>
                    <livewire:project.database.create-scheduled-backup :database="$serviceDatabase" :s3s="$s3s" />
                    <livewire:project.database.scheduled-backups :database="$serviceDatabase" />
                </div>
            </div>
            <div x-cloak x-show="activeTab === 'scheduled-tasks'">
                <livewire:project.shared.scheduled-task.all :resource="$service" />
            </div>
            <div x-cloak x-show="activeTab === 'danger'">
                <livewire:project.shared.danger :resource="$service" />
            </div>
            @endisset
        </div>
    </div>
</div>
