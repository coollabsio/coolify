<div>
    <h1>Configuration</h1>
    <livewire:project.database.heading :database="$database" />
    <x-modal modalId="startDatabase">
        <x-slot:modalBody>
            <livewire:activity-monitor header="Database Startup Logs" />
        </x-slot:modalBody>
        <x-slot:modalSubmit>
            <x-forms.button onclick="startDatabase.close()" type="submit">
                Close
            </x-forms.button>
        </x-slot:modalSubmit>
    </x-modal>
    <div x-data="{ activeTab: window.location.hash ? window.location.hash.substring(1) : 'general' }" class="flex h-full pt-6">
        <div class="flex flex-col gap-4 min-w-fit">
            <a :class="activeTab === 'general' && 'text-white'"
                @click.prevent="activeTab = 'general';
                window.location.hash = 'general'"
                href="#">General</a>
            <a :class="activeTab === 'environment-variables' && 'text-white'"
                @click.prevent="activeTab = 'environment-variables'; window.location.hash = 'environment-variables'"
                href="#">Environment
                Variables</a>
            <a :class="activeTab === 'server' && 'text-white'"
                @click.prevent="activeTab = 'server';
                window.location.hash = 'server'"
                href="#">Server
            </a>
            <a :class="activeTab === 'storages' && 'text-white'"
                @click.prevent="activeTab = 'storages';
                window.location.hash = 'storages'"
                href="#">Storages
            </a>
            <a :class="activeTab === 'webhooks' && 'text-white'"
                @click.prevent="activeTab = 'webhooks'; window.location.hash = 'webhooks'" href="#">Webhooks
            </a>
            <a :class="activeTab === 'resource-limits' && 'text-white'"
                @click.prevent="activeTab = 'resource-limits';
                window.location.hash = 'resource-limits'"
                href="#">Resource Limits
            </a>
            <a :class="activeTab === 'danger' && 'text-white'"
                @click.prevent="activeTab = 'danger';
                window.location.hash = 'danger'"
                href="#">Danger Zone
            </a>
        </div>
        <div class="w-full pl-8">
            <div x-cloak x-show="activeTab === 'general'" class="h-full">
                @if ($database->type() === 'standalone-postgresql')
                    <livewire:project.database.postgresql.general :database="$database" />
                @elseif ($database->type() === 'standalone-redis')
                    <livewire:project.database.redis.general :database="$database" />
                @elseif ($database->type() === 'standalone-mongodb')
                    <livewire:project.database.mongodb.general :database="$database" />
                @elseif ($database->type() === 'standalone-mysql')
                    <livewire:project.database.mysql.general :database="$database" />
                @elseif ($database->type() === 'standalone-mariadb')
                    <livewire:project.database.mariadb.general :database="$database" />
                @endif
            </div>
            <div x-cloak x-show="activeTab === 'environment-variables'">
                <livewire:project.shared.environment-variable.all :resource="$database" />
            </div>
            <div x-cloak x-show="activeTab === 'server'">
                <livewire:project.shared.destination :resource="$database" />
            </div>
            <div x-cloak x-show="activeTab === 'storages'">
                <livewire:project.service.storage :resource="$database" />
            </div>
            <div x-cloak x-show="activeTab === 'webhooks'">
                <livewire:project.shared.webhooks :resource="$database" />
            </div>
            <div x-cloak x-show="activeTab === 'resource-limits'">
                <livewire:project.shared.resource-limits :resource="$database" />
            </div>
            <div x-cloak x-show="activeTab === 'danger'">
                <livewire:project.shared.danger :resource="$database" />
            </div>
        </div>
    </div>
</div>
