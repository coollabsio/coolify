<div class="flex flex-wrap gap-2">
    @forelse($database->scheduledBackups as $backup)
        <div class="box flex flex-col">
            <div>Frequency: {{$backup->frequency}}</div>
            <div>Keep locally: {{$backup->keep_locally}}</div>
            <div>Sync to S3: {{$backup->save_s3}}</div>
        </div>
    @empty
        <div>No scheduled backups configured.</div>
    @endforelse
</div>
