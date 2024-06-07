<div>
    <x-slot:title>
        {{ data_get_str($database, 'name')->limit(10) }} > Configuration | Coolify
    </x-slot>
    <h1>Configuration</h1>
    <livewire:project.shared.configuration-checker :resource="$database" />
    <livewire:project.database.heading :database="$database" />
    <div x-data="{ activeTab: window.location.hash ? window.location.hash.substring(1) : 'general' }" class="flex flex-col h-full gap-8 pt-6 sm:flex-row">
        <div class="flex flex-col items-start gap-2 min-w-fit">
            <a class="menu-item" :class="activeTab === 'general' && 'menu-item-active'"
                @click.prevent="activeTab = 'general';
                window.location.hash = 'general'"
                href="#">General</a>
            <a class="menu-item" :class="activeTab === 'environment-variables' && 'menu-item-active'"
                @click.prevent="activeTab = 'environment-variables'; window.location.hash = 'environment-variables'"
                href="#">Environment
                Variables</a>
            <a class="menu-item" :class="activeTab === 'servers' && 'menu-item-active'"
                @click.prevent="activeTab = 'servers';
                window.location.hash = 'servers'"
                href="#">Servers
            </a>
            <a class="menu-item" :class="activeTab === 'storages' && 'menu-item-active'"
                @click.prevent="activeTab = 'storages';
                window.location.hash = 'storages'"
                href="#">Storages
            </a>
            <a class="menu-item" :class="activeTab === 'import' && 'menu-item-active'"
                @click.prevent="activeTab = 'import';
            window.location.hash = 'import'" href="#">Import
                Backup
            </a>
            <a class="menu-item" :class="activeTab === 'webhooks' && 'menu-item-active'"
                @click.prevent="activeTab = 'webhooks'; window.location.hash = 'webhooks'" href="#">Webhooks
            </a>
            <a class="menu-item" :class="activeTab === 'resource-limits' && 'menu-item-active'"
                @click.prevent="activeTab = 'resource-limits';
                window.location.hash = 'resource-limits'"
                href="#">Resource Limits
            </a>
            <a class="menu-item" :class="activeTab === 'resource-operations' && 'menu-item-active'"
                @click.prevent="activeTab = 'resource-operations'; window.location.hash = 'resource-operations'"
                href="#">Resource Operations
            </a>
            <a class="menu-item" :class="activeTab === 'tags' && 'menu-item-active'"
                @click.prevent="activeTab = 'tags'; window.location.hash = 'tags'" href="#">Tags
            </a>
            <a class="menu-item" :class="activeTab === 'danger' && 'menu-item-active'"
                @click.prevent="activeTab = 'danger';
                window.location.hash = 'danger'"
                href="#">Danger Zone
            </a>
        </div>
        <div class="w-full">
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
                @elseif ($database->type() === 'standalone-keydb')
                    <livewire:project.database.keydb.general :database="$database" />
                @elseif ($database->type() === 'standalone-dragonfly')
                    <livewire:project.database.dragonfly.general :database="$database" />
                @elseif ($database->type() === 'standalone-clickhouse')
                    <livewire:project.database.clickhouse.general :database="$database" />
                @endif
            </div>
            <div x-cloak x-show="activeTab === 'environment-variables'">
                <livewire:project.shared.environment-variable.all :resource="$database" />
            </div>
            <div x-cloak x-show="activeTab === 'servers'">
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
            <div x-cloak x-show="activeTab === 'import'">
                <livewire:project.database.import :resource="$database" />
            </div>
            <div x-cloak x-show="activeTab === 'resource-operations'">
                <livewire:project.shared.resource-operations :resource="$database" />
            </div>
            <div x-cloak x-show="activeTab === 'tags'">
                <livewire:project.shared.tags :resource="$database" />
            </div>
            <div x-cloak x-show="activeTab === 'danger'">
                <livewire:project.shared.danger :resource="$database" />
            </div>
        </div>
    </div>
</div>
