<div>
    <div class="flex flex-col gap-4">
        @foreach ($resource->persistentStorages as $storage)
            @if ($resource->type() === 'service')
                <livewire:project.shared.storages.show wire:key="storage-{{ $storage->id }}" :storage="$storage"
                    :isFirst="$loop->first" isReadOnly='true' isService='true' />
            @else
                <livewire:project.shared.storages.show wire:key="storage-{{ $storage->id }}" :storage="$storage"
                    isReadOnly="{{ data_get($storage, 'is_readonly') }}"
                    startedAt="{{ data_get($resource, 'started_at') }}" />
            @endif
        @endforeach
    </div>
</div>
