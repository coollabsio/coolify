<div>
    <h2 class="pb-0">Storages</h2>
    <div class="text-sm">Persistent storage to preserve data between deployments.</div>
    <div class="flex flex-col gap-2 py-4">
        @forelse ($application->persistentStorages as $storage)
            <livewire:project.application.storages.show wire:key="storage-{{ $storage->id }}" :storage="$storage" />
        @empty
            <p>There are no persistent storages attached for this application.</p>
        @endforelse
    </div>
    <livewire:project.application.storages.add />
</div>
