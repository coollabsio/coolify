<x-layout>
    <h1>Backups</h1>
    <livewire:project.database.heading :database="$database"/>
    <div class="pt-6">
        <h2 class="pb-4">Scheduled Backups</h2>
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
    </div>
</x-layout>
