<x-layout>
    <h1>Configuration</h1>
    <livewire:project.database.heading :database="$database" />
    <x-modal modalId="logs">
        <x-slot:modalBody>
            <livewire:activity-monitor :header="true" />
        </x-slot:modalBody>
        <x-slot:modalSubmit>
            <x-forms.button onclick="logs.close()" type="submit">
                Close
            </x-forms.button>
        </x-slot:modalSubmit>
    </x-modal>
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
                @if ($database->getMorphClass() === 'App\Models\Postgresql')
                    <livewire:project.database.postgresql.general :database="$database" />
                @endif
            </div>
            <div x-cloak x-show="activeTab === 'environment-variables'">
                {{-- <livewire:project.application.environment-variable.all :application="$application" /> --}}
            </div>
            <div x-cloak x-show="activeTab === 'source'">
                {{-- <livewire:project.application.source :application="$application" /> --}}
            </div>
            <div x-cloak x-show="activeTab === 'destination'">
                {{-- <livewire:project.application.destination :destination="$application->destination" /> --}}
            </div>
            <div x-cloak x-show="activeTab === 'storages'">
                {{-- <livewire:project.application.storages.all :application="$application" /> --}}
            </div>
            <div x-cloak x-show="activeTab === 'resource-limits'">
                {{-- <livewire:project.application.resource-limits :application="$application" /> --}}
            </div>
            <div x-cloak x-show="activeTab === 'danger'">
                {{-- <livewire:project.application.danger :application="$application" /> --}}
            </div>
        </div>
    </div>
</x-layout>
