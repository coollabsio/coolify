<div>
    <div class="flex flex-col gap-4">
        @if ($resource->type() === 'service' || data_get($resource, 'build_pack') === 'dockercompose')
            <div class="w-full p-2 text-sm rounded bg-warning/10 text-warning">
                Volume mounts are read-only. If you would like to add or modify a volume, you must edit your Docker
                Compose file and reload the compose file.
            </div>
        @endif
        @foreach ($resource->persistentStorages as $storage)
            @if ($resource->type() === 'service')
                <livewire:project.shared.storages.show wire:key="storage-{{ $storage->id }}" :storage="$storage"
                    :resource="$resource" :isFirst="$storage->id === $this->firstStorageId" isService='true' />
            @else
                <livewire:project.shared.storages.show wire:key="storage-{{ $storage->id }}" :storage="$storage"
                    :resource="$resource" :isFirst="$storage->id === $this->firstStorageId" startedAt="{{ data_get($resource, 'started_at') }}" />
            @endif
        @endforeach
    </div>
</div>
