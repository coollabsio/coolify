<x-applications.layout :applicationId="$application->id" title="Configurations">
    <div x-data="{ tab: window.location.hash ? window.location.hash.substring(1) : 'general' }">
        <div class="flex gap-4">
            <button @click.prevent="tab = 'general'; window.location.hash = 'general'" href="#">General</button>
            <button @click.prevent="tab = 'secrets'; window.location.hash = 'secrets'" href="#">Secrets</button>
            <button @click.prevent="tab = 'source'; window.location.hash = 'source'" href="#">Source</button>
            <button @click.prevent="tab = 'destination'; window.location.hash = 'destination'" href="#">Destination
            </button>
        </div>
        <div x-cloak x-show="tab === 'general'">
            <livewire:application.general :applicationId="$application->id" />
        </div>
        <div x-cloak x-show="tab === 'secrets'">
            <livewire:application.secrets :secrets="$application->id" />
        </div>
        <div x-cloak x-show="tab === 'source'">
            <livewire:application.source :applicationId="$application->id" />
        </div>
        <div x-cloak x-show="tab === 'destination'">
            <livewire:application.destination :destination="$application->destination->server" />
        </div>
    </div>
</x-applications.layout>
