<div>
    <h2>Scheduled Databse Backups</h2>
    @foreach ($scheduledDatabaseBackup as $backup)
        <div>
            {{ $backup->id }}
            {{ $backup->database->id }}
            {{ $backup->frequency }}
            {{ $backup->database->type() }}
            {{ $backup->created_at }}
            {{ $backup->updated_at }}
        </div>
    @endforeach
</div>
