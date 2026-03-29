@props(['resource'])

@php
    $serverId = $resource->destination->server->id;
    $environment = $resource->environment;
    $eagerLoad = ['destination.server'];
    $databases = collect()
        ->merge($environment->postgresqls()->with($eagerLoad)->get())
        ->merge($environment->mysqls()->with($eagerLoad)->get())
        ->merge($environment->mariadbs()->with($eagerLoad)->get())
        ->merge($environment->mongodbs()->with($eagerLoad)->get())
        ->merge($environment->redis()->with($eagerLoad)->get())
        ->merge($environment->clickhouses()->with($eagerLoad)->get())
        ->merge($environment->keydbs()->with($eagerLoad)->get())
        ->merge($environment->dragonflies()->with($eagerLoad)->get())
        ->filter(fn ($db) => $db->destination->server->id === $serverId)
        ->sortBy('name')
        ->values();

    $dbData = $databases->map(function ($db) {
        $dbClass = get_class($db);
        $dbType = match ($dbClass) {
            'App\Models\StandalonePostgresql' => 'PostgreSQL',
            'App\Models\StandaloneMysql' => 'MySQL',
            'App\Models\StandaloneMariadb' => 'MariaDB',
            'App\Models\StandaloneMongodb' => 'MongoDB',
            'App\Models\StandaloneRedis' => 'Redis',
            'App\Models\StandaloneClickhouse' => 'ClickHouse',
            'App\Models\StandaloneKeydb' => 'KeyDB',
            'App\Models\StandaloneDragonfly' => 'Dragonfly',
            default => 'Database',
        };

        $fields = match ($dbClass) {
            'App\Models\StandalonePostgresql' => [
                ['key' => 'DB_CONNECTION', 'value' => 'pgsql'],
                ['key' => 'DB_HOST', 'value' => $db->uuid],
                ['key' => 'DB_PORT', 'value' => '5432'],
                ['key' => 'DB_USERNAME', 'value' => $db->postgres_user],
                ['key' => 'DB_PASSWORD', 'value' => $db->postgres_password],
                ['key' => 'DB_DATABASE', 'value' => $db->postgres_db],
                ['key' => 'DATABASE_URL', 'value' => $db->internal_db_url],
            ],
            'App\Models\StandaloneMysql' => [
                ['key' => 'DB_CONNECTION', 'value' => 'mysql'],
                ['key' => 'DB_HOST', 'value' => $db->uuid],
                ['key' => 'DB_PORT', 'value' => '3306'],
                ['key' => 'DB_USERNAME', 'value' => $db->mysql_user],
                ['key' => 'DB_PASSWORD', 'value' => $db->mysql_password],
                ['key' => 'DB_DATABASE', 'value' => $db->mysql_database],
                ['key' => 'DATABASE_URL', 'value' => $db->internal_db_url],
            ],
            'App\Models\StandaloneMariadb' => [
                ['key' => 'DB_CONNECTION', 'value' => 'mariadb'],
                ['key' => 'DB_HOST', 'value' => $db->uuid],
                ['key' => 'DB_PORT', 'value' => '3306'],
                ['key' => 'DB_USERNAME', 'value' => $db->mariadb_user],
                ['key' => 'DB_PASSWORD', 'value' => $db->mariadb_password],
                ['key' => 'DB_DATABASE', 'value' => $db->mariadb_database],
                ['key' => 'DATABASE_URL', 'value' => $db->internal_db_url],
            ],
            'App\Models\StandaloneMongodb' => [
                ['key' => 'DB_CONNECTION', 'value' => 'mongodb'],
                ['key' => 'MONGO_HOST', 'value' => $db->uuid],
                ['key' => 'MONGO_PORT', 'value' => '27017'],
                ['key' => 'MONGO_USERNAME', 'value' => $db->mongo_initdb_root_username],
                ['key' => 'MONGO_PASSWORD', 'value' => $db->mongo_initdb_root_password],
                ['key' => 'MONGO_DATABASE', 'value' => $db->mongo_initdb_database],
                ['key' => 'DATABASE_URL', 'value' => $db->internal_db_url],
            ],
            'App\Models\StandaloneRedis' => [
                ['key' => 'REDIS_HOST', 'value' => $db->uuid],
                ['key' => 'REDIS_PORT', 'value' => '6379'],
                ['key' => 'REDIS_PASSWORD', 'value' => $db->redis_password],
                ['key' => 'REDIS_URL', 'value' => $db->internal_db_url],
            ],
            'App\Models\StandaloneClickhouse' => [
                ['key' => 'CLICKHOUSE_HOST', 'value' => $db->uuid],
                ['key' => 'CLICKHOUSE_PORT', 'value' => '9000'],
                ['key' => 'CLICKHOUSE_USERNAME', 'value' => $db->clickhouse_admin_user],
                ['key' => 'CLICKHOUSE_PASSWORD', 'value' => $db->clickhouse_admin_password],
                ['key' => 'CLICKHOUSE_DATABASE', 'value' => $db->clickhouse_db ?? 'default'],
                ['key' => 'DATABASE_URL', 'value' => $db->internal_db_url],
            ],
            'App\Models\StandaloneKeydb' => [
                ['key' => 'REDIS_HOST', 'value' => $db->uuid],
                ['key' => 'REDIS_PORT', 'value' => '6379'],
                ['key' => 'REDIS_PASSWORD', 'value' => $db->keydb_password],
                ['key' => 'REDIS_URL', 'value' => $db->internal_db_url],
            ],
            'App\Models\StandaloneDragonfly' => [
                ['key' => 'REDIS_HOST', 'value' => $db->uuid],
                ['key' => 'REDIS_PORT', 'value' => '6379'],
                ['key' => 'REDIS_PASSWORD', 'value' => $db->dragonfly_password],
                ['key' => 'REDIS_URL', 'value' => $db->internal_db_url],
            ],
            default => [],
        };

        return [
            'name' => $db->name,
            'type' => $dbType,
            'fields' => $fields,
        ];
    })->values();
@endphp

@if ($databases->isNotEmpty())
    <div x-data="{ selected: '' }" class="pb-2 mb-2 border-b dark:border-coolgray-300 border-neutral-200">
        <div class="flex items-center gap-2">
            <select x-model="selected"
                class="w-full text-sm dark:bg-coolgray-100 dark:border-coolgray-300 border-neutral-200 rounded-sm">
                <option value="">Fill from existing database ({{ $databases->count() }})</option>
                @foreach ($dbData as $index => $db)
                    <option value="{{ $index }}">{{ $db['name'] }} ({{ $db['type'] }})</option>
                @endforeach
            </select>
        </div>
        @foreach ($dbData as $index => $db)
            <div x-show="selected === '{{ $index }}'" x-cloak class="flex flex-wrap gap-1 mt-2">
                @foreach ($db['fields'] as $field)
                    <button type="button"
                        @click="Livewire.dispatch('prefillFromDatabase', { key: {{ Js::from($field['key']) }}, value: {{ Js::from($field['value']) }} })"
                        class="px-2 py-1 text-xs rounded-sm dark:bg-coolgray-200 dark:hover:bg-coolgray-300 bg-neutral-100 hover:bg-neutral-200 dark:text-neutral-300 hover:dark:text-white transition-colors">
                        {{ $field['key'] }}
                    </button>
                @endforeach
            </div>
        @endforeach
    </div>
@endif
