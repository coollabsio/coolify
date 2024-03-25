<div>
    @if (
        $resource->getMorphClass() == 'App\Models\Application' ||
            $resource->getMorphClass() == 'App\Models\StandalonePostgresql' ||
            $resource->getMorphClass() == 'App\Models\StandaloneRedis' ||
            $resource->getMorphClass() == 'App\Models\StandaloneMariadb' ||
            $resource->getMorphClass() == 'App\Models\StandaloneMongodb')
        <div class="flex items-center gap-2">
            <h2>Storages</h2>
            <x-helper
                helper="For Preview Deployments, storage has a <span class='text-helper'>-pr-#PRNumber</span> in their
                    volume
                    name, example: <span class='text-helper'>-pr-1</span>" />
            <x-modal-input buttonTitle="+ Add" title="New Persistent Storage">
                <livewire:project.shared.storages.add :uuid="$resource->uuid" />
            </x-modal-input>
        </div>
        <div class="pb-4">Persistent storage to preserve data between deployments.</div>
        @if ($resource->persistentStorages()->get()->count() === 0 && $resource->fileStorages()->get()->count() == 0)
            <div>No storage found.</div>
        @else
            @if ($resource->persistentStorages()->get()->count() > 0)
                <livewire:project.shared.storages.all :resource="$resource" />
            @endif
            @if ($resource->fileStorages()->get()->count() > 0)
                <div class="flex flex-col gap-4 pt-4">
                    @foreach ($resource->fileStorages()->get()->sort() as $fileStorage)
                        <livewire:project.service.file-storage :fileStorage="$fileStorage"
                            wire:key="resource-{{ $resource->uuid }}" />
                    @endforeach
                </div>
            @endif
        @endif
    @else
        @if ($resource->persistentStorages()->get()->count() > 0 || $resource->fileStorages()->get()->count() > 0)
            <h3 class="pt-4">{{ Str::headline($resource->name) }} </h3>
        @endif
        @if ($resource->persistentStorages()->get()->count() > 0)
            <livewire:project.shared.storages.all :resource="$resource" />
        @endif
        @if ($resource->fileStorages()->get()->count() > 0)
            <div class="flex flex-col gap-4 pt-4">
                @foreach ($resource->fileStorages()->get()->sort() as $fileStorage)
                    <livewire:project.service.file-storage :fileStorage="$fileStorage" wire:key="resource-{{ $resource->uuid }}" />
                @endforeach
            </div>
        @endif
    @endif
</div>
