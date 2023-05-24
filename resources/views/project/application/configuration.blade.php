<x-layout>
    <h1 class="pb-0">Configuration</h1>
    <div class="pb-10 text-sm breadcrumbs">
        <ul>
            <li><a href="{{ route('project.show', ['project_uuid' => request()->route('project_uuid')]) }}">
                    {{ $application->environment->project->name }}</a>
            </li>
            <li><a
                    href="{{ route('project.resources', ['environment_name' => request()->route('environment_name'), 'project_uuid' => request()->route('project_uuid')]) }}">{{ request()->route('environment_name') }}</a>
            </li>
            <li>{{ data_get($application, 'name') }}</li>
        </ul>
    </div>
    <x-applications.navbar :application="$application" />
    <div x-data="{ activeTab: window.location.hash ? window.location.hash.substring(1) : 'general' }" class="flex h-full pt-6">
        <div class="flex flex-col gap-4 min-w-fit">
            <a :class="activeTab === 'general' && 'text-white'"
                @click.prevent="activeTab = 'general'; window.location.hash = 'general'" href="#">General</a>
            <a :class="activeTab === 'environment-variables' && 'text-white'"
                @click.prevent="activeTab = 'environment-variables'; window.location.hash = 'environment-variables'"
                href="#">Environment
                Variables</a>
            <a :class="activeTab === 'source' && 'text-white'"
                @click.prevent="activeTab = 'source'; window.location.hash = 'source'" href="#">Source</a>
            <a :class="activeTab === 'destination' && 'text-white'"
                @click.prevent="activeTab = 'destination'; window.location.hash = 'destination'"
                href="#">Destination
            </a>
            <a :class="activeTab === 'storages' && 'text-white'"
                @click.prevent="activeTab = 'storages'; window.location.hash = 'storages'" href="#">Storages
            </a>
            <a :class="activeTab === 'rollback' && 'text-white'"
                @click.prevent="activeTab = 'rollback'; window.location.hash = 'rollback'" href="#">Rollback
            </a>
            <a :class="activeTab === 'resource-limits' && 'text-white'"
                @click.prevent="activeTab = 'resource-limits'; window.location.hash = 'resource-limits'"
                href="#">Resource Limits
            </a>
            <a :class="activeTab === 'danger' && 'text-white'"
                @click.prevent="activeTab = 'danger'; window.location.hash = 'danger'" href="#">Danger Zone
            </a>
            {{-- <a :class="activeTab === 'previews' && 'text-white'"
                @click.prevent="activeTab = 'previews'; window.location.hash = 'previews'" href="#">Previews
            </a> --}}
        </div>
        <div class="w-full pl-8">
            <div x-cloak x-show="activeTab === 'general'" class="h-full">
                <livewire:project.application.general :application="$application" />
            </div>
            <div x-cloak x-show="activeTab === 'environment-variables'">
                <livewire:project.application.environment-variable.all :application="$application" />
            </div>
            <div x-cloak x-show="activeTab === 'source'">
                <livewire:project.application.source :application="$application" />
            </div>
            <div x-cloak x-show="activeTab === 'destination'">
                <livewire:project.application.destination :destination="$application->destination" />
            </div>
            <div x-cloak x-show="activeTab === 'storages'">
                <livewire:project.application.storages.all :application="$application" />
            </div>
            <div x-cloak x-show="activeTab === 'rollback'">
                <livewire:project.application.rollback :application="$application" />
            </div>
            <div x-cloak x-show="activeTab === 'resource-limits'">
                <livewire:project.application.resource-limits :application="$application" />
            </div>
            <div x-cloak x-show="activeTab === 'danger'">
                <livewire:project.application.danger :application="$application" />
            </div>
        </div>
    </div>
</x-layout>
