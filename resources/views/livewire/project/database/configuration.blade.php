<div>
    <x-slot:title>
        {{ data_get_str($database, 'name')->limit(10) }} > Configuration | Coolify
    </x-slot>

    <livewire:project.database.heading :database="$database" />


    <section class="application-settings-workspace mt-4 w-full max-w-none lg:mt-0">
        <div class="grid min-w-0 gap-8 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
            <x-database.configuration-sidebar :database="$database" :current-route="$currentRoute" />

            <div class="min-w-0">
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
                @elseif ($currentRoute === 'project.database.healthcheck')
                    <livewire:project.database.health :database="$database" />
                @elseif ($currentRoute === 'project.database.import-backup')
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
    </section>
</div>
