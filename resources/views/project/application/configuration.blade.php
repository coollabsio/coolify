<x-layout>
    <h1>Configuration</h1>
    <nav class="flex pt-2 pb-10 text-sm">
        <ol class="inline-flex items-center">
            <li class="inline-flex items-center">
                <a href="{{ route('project.show', ['project_uuid' => request()->route('project_uuid')]) }}">
                    {{ $application->environment->project->name }}</a>
            </li>
            <li>
                <div class="flex items-center">
                    <svg aria-hidden="true" class="w-4 h-4 mx-1 font-bold text-warning" fill="currentColor"
                        viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                        <path fill-rule="evenodd"
                            d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z"
                            clip-rule="evenodd"></path>
                    </svg>
                    <a
                        href="{{ route('project.resources', ['environment_name' => request()->route('environment_name'), 'project_uuid' => request()->route('project_uuid')]) }}">{{ request()->route('environment_name') }}</a>
                </div>
            </li>
            <li>
                <div class="flex items-center">
                    <svg aria-hidden="true" class="w-4 h-4 mx-1 font-bold text-warning" fill="currentColor"
                        viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                        <path fill-rule="evenodd"
                            d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z"
                            clip-rule="evenodd"></path>
                    </svg>
                    <span>{{ data_get($application, 'name') }}</span>
                </div>
            </li>
            <li>
                <div class="flex items-center">
                    <svg aria-hidden="true" class="w-4 h-4 mx-1 font-bold text-warning" fill="currentColor"
                        viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                        <path fill-rule="evenodd"
                            d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z"
                            clip-rule="evenodd"></path>
                    </svg>
                    <livewire:project.application.status :application="$application" />
                </div>
            </li>
        </ol>
    </nav>

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
            <a :class="activeTab === 'previews' && 'text-white'"
                @click.prevent="activeTab = 'previews'; window.location.hash = 'previews'" href="#">Previews
                Deployments
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
            <div x-cloak x-show="activeTab === 'previews'">
                <livewire:project.application.previews :application="$application" />
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
