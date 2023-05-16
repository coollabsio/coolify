<x-layout>
    <x-applications.navbar :application="$application" :gitBranchLocation="$application->gitBranchLocation" />
    <h1 class="py-10">Configuration</h1>
    <div x-data="{ activeTab: window.location.hash ? window.location.hash.substring(1) : 'general' }" class="flex pt-6">
        <div class="flex flex-col min-w-fit">
            <a :class="activeTab === 'general' && 'text-coollabs-100'"
                @click.prevent="activeTab = 'general'; window.location.hash = 'general'" href="#">General</a>
            <a :class="activeTab === 'environment-variables' && 'text-coollabs-100'"
                @click.prevent="activeTab = 'environment-variables'; window.location.hash = 'environment-variables'"
                href="#">Environment
                Variables</a>
            <a :class="activeTab === 'source' && 'text-coollabs-100'"
                @click.prevent="activeTab = 'source'; window.location.hash = 'source'" href="#">Source</a>
            <a :class="activeTab === 'destination' && 'text-coollabs-100'"
                @click.prevent="activeTab = 'destination'; window.location.hash = 'destination'"
                href="#">Destination
            </a>
            <a :class="activeTab === 'storages' && 'text-coollabs-100'"
                @click.prevent="activeTab = 'storages'; window.location.hash = 'storages'" href="#">Storages
            </a>
            <a :class="activeTab === 'revert' && 'text-coollabs-100'"
                @click.prevent="activeTab = 'revert'; window.location.hash = 'revert'" href="#">Revert
            </a>
            {{-- <a :class="activeTab === 'previews' && 'text-coollabs-100'"
                @click.prevent="activeTab = 'previews'; window.location.hash = 'previews'" href="#">Previews
            </a> --}}
        </div>
        <div class="w-full pt-2 pl-8">
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
            <div x-cloak x-show="activeTab === 'revert'">
                <livewire:project.application.revert :application="$application" />
            </div>
            {{-- <div x-cloak x-show="activeTab === 'previews'">
                <livewire:project.application.previews :application="$application" />
            </div> --}}
        </div>
    </div>
</x-layout>
