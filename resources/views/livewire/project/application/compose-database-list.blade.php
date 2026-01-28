<div>
    <h2 class="pb-4">Compose Database Backups</h2>
    <p class="pb-4 text-sm text-gray-500">
        Database services detected in your Docker Compose file. Click on a database to manage its scheduled backups.
    </p>

    @if ($composeDatabases->isEmpty())
        <div class="text-sm text-gray-500">
            No database services with backup support were detected in your Docker Compose file.
            <br>
            Supported databases: PostgreSQL, MySQL, MariaDB, MongoDB.
        </div>
    @else
        <div class="grid gap-2">
            @foreach ($composeDatabases as $database)
                <a class="box group"
                    href="{{ route('project.application.compose-database.backups', [
                        'project_uuid' => $application->environment->project->uuid,
                        'environment_uuid' => $application->environment->uuid,
                        'application_uuid' => $application->uuid,
                        'compose_database_uuid' => $database->uuid,
                    ]) }}">
                    <div class="flex items-center gap-2">
                        <div class="font-bold text-white">{{ $database->name }}</div>
                        <div class="text-xs text-gray-400">{{ $database->image }}</div>
                        <div class="text-xs px-2 py-0.5 rounded-full
                            {{ str($database->databaseType())->contains('postgres') ? 'bg-blue-500/20 text-blue-400' : '' }}
                            {{ str($database->databaseType())->contains('mysql') ? 'bg-orange-500/20 text-orange-400' : '' }}
                            {{ str($database->databaseType())->contains('mariadb') ? 'bg-teal-500/20 text-teal-400' : '' }}
                            {{ str($database->databaseType())->contains('mongo') ? 'bg-green-500/20 text-green-400' : '' }}
                        ">
                            {{ str($database->databaseType())->after('standalone-')->value() }}
                        </div>
                    </div>
                    <div class="text-xs text-gray-500">
                        {{ $database->scheduledBackups()->count() }} scheduled backup(s)
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</div>
