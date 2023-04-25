<x-applications.layout :applicationId="$application->id" title="Configurations">
    <div x-data="{ tab: window.location.hash ? window.location.hash.substring(1) : 'general' }">
        <div class="flex gap-4">
            <a @click.prevent="tab = 'general'; window.location.hash = 'general'" href="#">General</a>
            <a @click.prevent="tab = 'envs'; window.location.hash = 'envs'" href="#">Environment Variables</a>
            <a @click.prevent="tab = 'source'; window.location.hash = 'source'" href="#">Source</a>
            <a @click.prevent="tab = 'destination'; window.location.hash = 'destination'" href="#">Destination
            </a>
            <a @click.prevent="tab = 'storages'; window.location.hash = 'storages'" href="#">Storage
            </a>
        </div>
        <div x-cloak x-show="tab === 'general'">
            <livewire:project.application.general :applicationId="$application->id" />
        </div>
        <div x-cloak x-show="tab === 'envs'">
            <livewire:project.application.environment-variables />
        </div>
        <div x-cloak x-show="tab === 'source'">
            <livewire:project.application.source :applicationId="$application->id" />
        </div>
        <div x-cloak x-show="tab === 'destination'">
            <livewire:project.application.destination :destination="$application->destination" />
        </div>
        <div x-cloak x-show="tab === 'storages'">
            <livewire:project.application.storages :storages="$application->persistentStorages" />
        </div>
    </div>
</x-applications.layout>
