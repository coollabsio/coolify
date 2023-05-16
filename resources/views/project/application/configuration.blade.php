<x-layout>
    <h1>Configuration</h1>
    <x-applications.navbar :applicationId="$application->id" :gitBranchLocation="$application->gitBranchLocation" />
    <div x-data="{ activeTab: window.location.hash ? window.location.hash.substring(1) : 'general' }" class="flex pt-6">
        <div class="flex flex-col gap-4 min-w-fit">
            <a :class="activeTab === 'general' && 'text-purple-500'"
                @click.prevent="activeTab = 'general'; window.location.hash = 'general'" href="#">General</a>
            <a :class="activeTab === 'environment-variables' && 'text-purple-500'"
                @click.prevent="activeTab = 'environment-variables'; window.location.hash = 'environment-variables'"
                href="#">Environment
                Variables</a>
            <a :class="activeTab === 'source' && 'text-purple-500'"
                @click.prevent="activeTab = 'source'; window.location.hash = 'source'" href="#">Source</a>
            <a :class="activeTab === 'destination' && 'text-purple-500'"
                @click.prevent="activeTab = 'destination'; window.location.hash = 'destination'"
                href="#">Destination
            </a>
            <a :class="activeTab === 'storages' && 'text-purple-500'"
                @click.prevent="activeTab = 'storages'; window.location.hash = 'storages'" href="#">Storages
            </a>
            {{-- <a :class="activeTab === 'previews' && 'text-purple-500'"
                @click.prevent="activeTab = 'previews'; window.location.hash = 'previews'" href="#">Previews
            </a> --}}
        </div>
        <div class="w-full pl-8">
            <div x-cloak x-show="activeTab === 'general'">
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
            {{-- <div x-cloak x-show="activeTab === 'previews'">
                <livewire:project.application.previews :application="$application" />
            </div> --}}
        </div>
    </div>
</x-layout>
