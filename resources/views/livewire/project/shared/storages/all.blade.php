<div>
    <div class="flex flex-col gap-4">
        @if ($resource->type() === 'service' || data_get($resource, 'build_pack') === 'dockercompose')
            <div class="w-full p-2 text-sm rounded bg-warning/10 text-warning">
                For docker compose based applications Volume mounts are read-only in the Coolify dashboard. To add, modify, or manage volumes, you must edit your Docker Compose file and reload the compose file.
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
