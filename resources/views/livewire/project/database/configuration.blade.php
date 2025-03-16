<div>
    <x-slot:title>
        {{ data_get_str($database, 'name')->limit(10) }} > Configuration | Coolify
    </x-slot>
    <h1>Configuration</h1>
    <livewire:project.shared.configuration-checker :resource="$database" />
    <livewire:project.database.heading :database="$database" />
    <div class="flex flex-col h-full gap-8 pt-6 sm:flex-row">
        <div class="flex flex-col items-start gap-2 min-w-fit">
            <a class='menu-item' wire:current.exact="menu-item-active"
                href="{{ route('project.database.configuration', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'database_uuid' => $database->uuid]) }}"
                wire:navigate>General</a>
            <a class='menu-item' wire:current.exact="menu-item-active"
                href="{{ route('project.database.environment-variables', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'database_uuid' => $database->uuid]) }}"
                wire:navigate>Environment Variables</a>
            <a class='menu-item' wire:current.exact="menu-item-active"
                href="{{ route('project.database.servers', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'database_uuid' => $database->uuid]) }}"
                wire:navigate>Servers</a>
            <a class='menu-item' wire:current.exact="menu-item-active"
                href="{{ route('project.database.persistent-storage', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'database_uuid' => $database->uuid]) }}"
                wire:navigate>Persistent Storage</a>
            <a class='menu-item' wire:current.exact="menu-item-active"
                href="{{ route('project.database.import-backups', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'database_uuid' => $database->uuid]) }}">Import
                Backups</a>
            <a class='menu-item' wire:current.exact="menu-item-active"
                href="{{ route('project.database.webhooks', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'database_uuid' => $database->uuid]) }}"
                wire:navigate>Webhooks</a>
            <a class="menu-item" wire:current.exact="menu-item-active"
                href="{{ route('project.database.resource-limits', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'database_uuid' => $database->uuid]) }}"
                wire:navigate>Resource Limits</a>
            <a class="menu-item" wire:current.exact="menu-item-active"
                href="{{ route('project.database.resource-operations', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'database_uuid' => $database->uuid]) }}"
                wire:navigate>Resource Operations</a>
            <a class='menu-item' wire:current.exact="menu-item-active"
                href="{{ route('project.database.metrics', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'database_uuid' => $database->uuid]) }}">Metrics</a>
            <a class='menu-item' wire:current.exact="menu-item-active"
                href="{{ route('project.database.tags', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'database_uuid' => $database->uuid]) }}"
                wire:navigate>Tags</a>
            <a class='menu-item' wire:current.exact="menu-item-active"
                href="{{ route('project.database.danger', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'database_uuid' => $database->uuid]) }}"
                wire:navigate>Danger Zone</a>
        </div>
        <div class="w-full">
            @if ($currentRoute === 'project.database.configuration')
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
            @elseif ($currentRoute === 'project.database.environment-variables')
                <livewire:project.shared.environment-variable.all :resource="$database" />
            @elseif ($currentRoute === 'project.database.servers')
                <livewire:project.shared.destination :resource="$database" />
            @elseif ($currentRoute === 'project.database.persistent-storage')
                <livewire:project.service.storage :resource="$database" />
            @elseif ($currentRoute === 'project.database.import-backups')
                <livewire:project.database.import :resource="$database" />
            @elseif ($currentRoute === 'project.database.webhooks')
                <livewire:project.shared.webhooks :resource="$database" />
            @elseif ($currentRoute === 'project.database.resource-limits')
                <livewire:project.shared.resource-limits :resource="$database" />
            @elseif ($currentRoute === 'project.database.resource-operations')
                <livewire:project.shared.resource-operations :resource="$database" />
            @elseif ($currentRoute === 'project.database.metrics')
                <livewire:project.shared.metrics :resource="$database" />
            @elseif ($currentRoute === 'project.database.tags')
                <livewire:project.shared.tags :resource="$database" />
            @elseif ($currentRoute === 'project.database.danger')
                <livewire:project.shared.danger :resource="$database" />
            @endif
        </div>
    </div>
</div>
