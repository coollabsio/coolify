<div class="flex flex-col gap-2">
    <h2>Storages</h2>
    @forelse ($application->persistentStorages as $storage)
        <livewire:project.application.storages.show wire:key="storage-{{ $storage->id }}" :storage="$storage" />
    @empty
        <p>There are no persistent storages attached for this application.</p>
    @endforelse
    <livewire:project.application.storages.add />
</div>
