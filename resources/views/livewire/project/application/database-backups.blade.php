<div>
    <h2 class="pb-4">Database Backups</h2>
    <p class="pb-4">Databases detected in your Docker Compose file. Click on a database to manage its backups.</p>

    @if ($databases->isEmpty())
        <p>No databases detected in your Docker Compose configuration.</p>
    @else
        <div class="grid gap-4">
            @foreach ($databases as $database)
                <a href="{{ route('project.application.database-backups.show', [
                    'project_uuid' => $application->environment->project->uuid,
                    'environment_uuid' => $application->environment->uuid,
                    'application_uuid' => $application->uuid,
                    'database_uuid' => $database->uuid,
                ]) }}"
                    {{ wireNavigate() }}
                    class="box group">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="font-bold text-lg">{{ $database->name }}</h3>
                            <p class="text-sm text-neutral-400">{{ $database->image }}</p>
                            <p class="text-sm">
                                Type: {{ str($database->databaseType())->replace('standalone-', '') }}
                                @if ($database->isBackupSolutionAvailable())
                                    <span class="text-success"> — Backup supported</span>
                                @else
                                    <span class="text-warning"> — Backup not supported for this database type</span>
                                @endif
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="badge {{ $database->isRunning() ? 'bg-success' : 'bg-error' }}">
                                {{ $database->status }}
                            </span>
                            <span class="text-sm text-neutral-400">
                                {{ $database->scheduledBackups()->count() }} backup(s) configured
                            </span>
                        </div>
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</div>
