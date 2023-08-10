<div class="flex flex-wrap gap-2">
    @forelse($database->scheduledBackups as $backup)
        <a class="box flex flex-col"
           href="{{ route('project.database.backups.logs', [...$parameters,'backup_uuid'=> $backup->uuid]) }}">
            <div>Frequency: {{$backup->frequency}}</div>
            <div>Last backup: {{data_get($backup->latest_log, 'status','No backup yet')}}</div>
            <div>Number of backups to keep (locally): {{$backup->number_of_backups_locally}}</div>
        </a>
    @empty
        <div>No scheduled backups configured.</div>
    @endforelse
</div>
