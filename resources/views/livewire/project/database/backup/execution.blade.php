<div>
    <h1>Backups</h1>
    <livewire:project.shared.configuration-checker :resource="$database" />
    <livewire:project.database.heading :database="$database" />
    <div class="pt-6">
        <livewire:project.database.backup-edit :backup="$backup" :s3s="$s3s" :status="data_get($database, 'status')" />
        <livewire:project.database.backup-executions :backup="$backup" />
    </div>
</div>
