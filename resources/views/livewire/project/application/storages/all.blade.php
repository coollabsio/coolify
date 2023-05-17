<div class="flex flex-col gap-2">
    <h3>Storages</h3>
    @forelse ($application->persistentStorages as $storage)
        <livewire:project.application.storages.show wire:key="storage-{{ $storage->id }}" :storage="$storage" />
    @empty
        <p>There are no persistent storages attached for this application.</p>
    @endforelse
    <livewire:project.application.storages.add />
</div>
