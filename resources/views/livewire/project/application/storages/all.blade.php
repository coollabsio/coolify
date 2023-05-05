<div class="flex flex-col gap-2">
    <h3>Persistent Storages</h3>
    @forelse ($application->persistentStorages as $storage)
        <livewire:project.application.storages.show :storage="$storage" />
    @empty
        <p>There are no persistent storage attached for this application.</p>
    @endforelse
    <h4>Add new environment variable</h4>
    <livewire:project.application.storages.add />
</div>
