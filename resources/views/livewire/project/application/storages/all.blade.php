<div>
    <div>
        <div class="flex items-center gap-2">
            <h2>Storages </h2>
            <x-helper
                helper="For Preview Deployments, storage has a <span class='text-helper'>-pr-#PRNumber</span> in their
            volume
            name, example: <span class='text-helper'>-pr-1</span>" />
        </div>
        <div class="text-sm">Persistent storage to preserve data between deployments.</div>
    </div>
    <div class="flex flex-col gap-2 py-4">
        @foreach ($application->persistentStorages as $storage)
            <livewire:project.application.storages.show wire:key="storage-{{ $storage->id }}" :storage="$storage" />
        @endforeach
    </div>
    <livewire:project.application.storages.add />
</div>
