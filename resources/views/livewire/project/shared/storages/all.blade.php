<div>
    <div class="flex flex-col gap-4">
        @foreach ($resource->persistentStorages as $storage)
            @if ($resource->type() === 'service')
                <livewire:project.shared.storages.show wire:key="storage-{{ $storage->id }}" :storage="$storage"
                    :isFirst="$loop->first" isReadOnly='true' />
            @else
                <livewire:project.shared.storages.show wire:key="storage-{{ $storage->id }}" :storage="$storage" />
            @endif
        @endforeach
    </div>
</div>
