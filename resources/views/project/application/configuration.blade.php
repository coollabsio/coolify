<x-layout>
    <h1>Configuration</h1>
    <x-applications.navbar :applicationId="$application->id" />
    <div x-data="{ activeTab: 'general' }">
        <div class="flex gap-4">
            <a :class="activeTab === 'general' && 'text-green-500'" @click.prevent="activeTab = 'general'"
                href="#">General</a>
            <a :class="activeTab === 'envs' && 'text-green-500'" @click.prevent="activeTab = 'envs'"
                href="#">Environment Variables</a>
            <a :class="activeTab === 'source' && 'text-green-500'" @click.prevent="activeTab = 'source'"
                href="#">Source</a>
            <a :class="activeTab === 'destination' && 'text-green-500'" @click.prevent="activeTab = 'destination'"
                href="#">Destination
            </a>
            <a :class="activeTab === 'storages' && 'text-green-500'" @click.prevent="activeTab = 'storages'"
                href="#">Storage
            </a>
        </div>
        <div x-cloak x-show="activeTab === 'general'">
            <livewire:project.application.general :applicationId="$application->id" />
        </div>
        <div x-cloak x-show="activeTab === 'envs'">
            <livewire:project.application.environment-variables />
        </div>
        <div x-cloak x-show="activeTab === 'source'">
            <livewire:project.application.source :applicationId="$application->id" />
        </div>
        <div x-cloak x-show="activeTab === 'destination'">
            <livewire:project.application.destination :destination="$application->destination" />
        </div>
        <div x-cloak x-show="activeTab === 'storages'">
            <livewire:project.application.storages :storages="$application->persistentStorages" />
        </div>
    </div>
</x-layout>
