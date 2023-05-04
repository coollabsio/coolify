<x-layout>
    <h1>Configuration</h1>
    <x-applications.navbar :applicationId="$application->id" />
    <div x-data="{ activeTab: window.location.hash ? window.location.hash.substring(1) : 'general' }">
        <div class="flex gap-4">
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
                @click.prevent="activeTab = 'storages'; window.location.hash = 'storages'" href="#">Storage
            </a>
        </div>
        <div x-cloak x-show="activeTab === 'general'">
            <h3>General Configurations</h3>
            <livewire:project.application.general :applicationId="$application->id" />
        </div>
        <div x-cloak x-show="activeTab === 'environment-variables'" class="flex flex-col gap-2">
            <h3>Environment Variables</h3>
            @forelse ($application->environment_variables->all() as $keyName => $env)
                <livewire:project.application.environment-variable :keyName="$keyName" :env="$env" />
            @empty
                <p>There are no environment variables for this application.</p>
            @endforelse
            <livewire:project.application.environment-variable />
        </div>
        <div x-cloak x-show="activeTab === 'source'">
            <h3>Source</h3>
            <livewire:project.application.source :applicationId="$application->id" />
        </div>
        <div x-cloak x-show="activeTab === 'destination'">
            <h3>Destination</h3>
            <livewire:project.application.destination :destination="$application->destination" />
        </div>
        <div x-cloak x-show="activeTab === 'storages'">
            <h3>Persistent Storages</h3>
            <livewire:project.application.storages :storages="$application->persistentStorages" />
        </div>
    </div>
</x-layout>
