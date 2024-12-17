<div>
    @if (
        $resource->getMorphClass() == 'App\Models\Application' ||
            $resource->getMorphClass() == 'App\Models\StandalonePostgresql' ||
            $resource->getMorphClass() == 'App\Models\StandaloneRedis' ||
            $resource->getMorphClass() == 'App\Models\StandaloneMariadb' ||
            $resource->getMorphClass() == 'App\Models\StandaloneKeydb' ||
            $resource->getMorphClass() == 'App\Models\StandaloneDragonfly' ||
            $resource->getMorphClass() == 'App\Models\StandaloneClickhouse' ||
            $resource->getMorphClass() == 'App\Models\StandaloneMongodb' ||
            $resource->getMorphClass() == 'App\Models\StandaloneMysql')
        <div class="flex items-center gap-2">
            <h2>Storages</h2>
            <x-helper
                helper="For Preview Deployments, storage has a <span class='text-helper'>-pr-#PRNumber</span> in their
                    volume
                    name, example: <span class='text-helper'>-pr-1</span>" />
            @if ($resource?->build_pack !== 'dockercompose')
                <x-modal-input :closeOutside="false" buttonTitle="+ Add" title="New Persistent Storage" minWidth="64rem">
                    <livewire:project.shared.storages.add :resource="$resource" />
                </x-modal-input>
            @endif
        </div>
        <div class="pb-4">Persistent storage to preserve data between deployments.</div>
        @if ($resource?->build_pack === 'dockercompose')
            <span class="dark:text-warning text-coollabs">Please modify storage layout in your Docker Compose
                file or reload the compose file to reread the storage layout.</span>
        @else
            @if ($resource->persistentStorages()->get()->count() === 0 && $fileStorage->count() == 0)
                <div class="pt-4">No storage found.</div>
            @endif
        @endif

        @if ($resource->persistentStorages()->get()->count() > 0)
            <h3 class="pt-4">Volumes</h3>
            <livewire:project.shared.storages.all :resource="$resource" />
        @endif
        @if ($fileStorage->count() > 0)
            <div class="flex flex-col gap-2">
                @foreach ($fileStorage->sort() as $fileStorage)
                    <livewire:project.service.file-storage :fileStorage="$fileStorage"
                        wire:key="resource-{{ $fileStorage->uuid }}" />
                @endforeach
            </div>
        @endif
    @else
        @if ($resource->persistentStorages()->get()->count() > 0)
            <h3 class="pt-4">{{ Str::headline($resource->name) }} </h3>
        @endif
        @if ($resource->persistentStorages()->get()->count() > 0)
            <livewire:project.shared.storages.all :resource="$resource" />
        @endif
        @if ($fileStorage->count() > 0)
            <div class="flex flex-col gap-4 pt-4">
                @foreach ($fileStorage->sort() as $fileStorage)
                    <livewire:project.service.file-storage :fileStorage="$fileStorage"
                        wire:key="resource-{{ $fileStorage->uuid }}" />
                @endforeach
            </div>
        @endif
    @endif
</div>
