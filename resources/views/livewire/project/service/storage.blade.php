<div class="flex flex-col gap-4">
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
        <div>
            <div class="flex items-center gap-2">
                <h2>Storages</h2>
                <x-helper
                    helper="For Preview Deployments, storage has a <span class='text-helper'>-pr-#PRNumber</span> in their
                        volume
                        name, example: <span class='text-helper'>-pr-1</span>" />
                @if ($resource?->build_pack !== 'dockercompose')
                    @can('update', $resource)
                        <x-modal-input :closeOutside="false" buttonTitle="+ Add" title="New Persistent Storage" minWidth="64rem">
                            <livewire:project.shared.storages.add :resource="$resource" />
                        </x-modal-input>
                    @endcan
                @endif
            </div>
            <div>Persistent storage to preserve data between deployments.</div>
        </div>
        @if ($resource?->build_pack === 'dockercompose')
            <div class="dark:text-warning text-coollabs">Please modify storage layout in your Docker Compose
                file or reload the compose file to reread the storage layout.</div>
        @else
            @if ($resource->persistentStorages()->get()->count() === 0 && $fileStorage->count() == 0)
                <div>No storage found.</div>
            @endif
        @endif

        @php
            $hasVolumes = $this->volumeCount > 0;
            $hasFiles = $this->fileCount > 0;
            $hasDirectories = $this->directoryCount > 0;
            $defaultTab = $hasVolumes ? 'volumes' : ($hasFiles ? 'files' : 'directories');
        @endphp

        @if ($hasVolumes || $hasFiles || $hasDirectories)
            <div x-data="{
                activeTab: '{{ $defaultTab }}'
            }">
                {{-- Tabs Navigation --}}
                <div class="flex gap-2 border-b dark:border-coolgray-300 border-neutral-200">
                    <button @click="activeTab = 'volumes'"
                        :class="activeTab === 'volumes' ? 'border-b-2 dark:border-white border-black' : 'border-b-2 border-transparent'"
                        @if (!$hasVolumes) disabled @endif
                        class="px-4 py-2 -mb-px font-medium transition-colors {{ $hasVolumes ? 'dark:text-neutral-400 dark:hover:text-white text-neutral-600 hover:text-black cursor-pointer' : 'opacity-50 cursor-not-allowed' }}">
                        Volumes ({{ $this->volumeCount }})
                    </button>
                    <button @click="activeTab = 'files'"
                        :class="activeTab === 'files' ? 'border-b-2 dark:border-white border-black' : 'border-b-2 border-transparent'"
                        @if (!$hasFiles) disabled @endif
                        class="px-4 py-2 -mb-px font-medium transition-colors {{ $hasFiles ? 'dark:text-neutral-400 dark:hover:text-white text-neutral-600 hover:text-black cursor-pointer' : 'opacity-50 cursor-not-allowed' }}">
                        Files ({{ $this->fileCount }})
                    </button>
                    <button @click="activeTab = 'directories'"
                        :class="activeTab === 'directories' ? 'border-b-2 dark:border-white border-black' : 'border-b-2 border-transparent'"
                        @if (!$hasDirectories) disabled @endif
                        class="px-4 py-2 -mb-px font-medium transition-colors {{ $hasDirectories ? 'dark:text-neutral-400 dark:hover:text-white text-neutral-600 hover:text-black cursor-pointer' : 'opacity-50 cursor-not-allowed' }}">
                        Directories ({{ $this->directoryCount }})
                    </button>
                </div>

                {{-- Tab Content --}}
                <div class="pt-4">
                    {{-- Volumes Tab --}}
                    <div x-show="activeTab === 'volumes'" class="flex flex-col gap-4">
                        @if ($hasVolumes)
                            <livewire:project.shared.storages.all :resource="$resource" />
                        @else
                            <div class="text-center py-8 dark:text-neutral-500 text-neutral-400">
                                No volumes configured.
                            </div>
                        @endif
                    </div>

                    {{-- Files Tab --}}
                    <div x-show="activeTab === 'files'" class="flex flex-col gap-4">
                        @if ($hasFiles)
                            @foreach ($this->files as $fs)
                                <livewire:project.service.file-storage :fileStorage="$fs"
                                    wire:key="file-{{ $fs->id }}" />
                            @endforeach
                        @else
                            <div class="text-center py-8 dark:text-neutral-500 text-neutral-400">
                                No file mounts configured.
                            </div>
                        @endif
                    </div>

                    {{-- Directories Tab --}}
                    <div x-show="activeTab === 'directories'" class="flex flex-col gap-4">
                        @if ($hasDirectories)
                            @foreach ($this->directories as $fs)
                                <livewire:project.service.file-storage :fileStorage="$fs"
                                    wire:key="directory-{{ $fs->id }}" />
                            @endforeach
                        @else
                            <div class="text-center py-8 dark:text-neutral-500 text-neutral-400">
                                No directory mounts configured.
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @endif
    @else
        @if ($resource->persistentStorages()->get()->count() > 0)
            <h3>{{ Str::headline($resource->name) }} </h3>
        @endif
        @if ($resource->persistentStorages()->get()->count() > 0)
            <livewire:project.shared.storages.all :resource="$resource" />
        @endif
        @if ($fileStorage->count() > 0)
            <div class="flex flex-col gap-4">
                @foreach ($fileStorage->sort() as $fileStorage)
                    <livewire:project.service.file-storage :fileStorage="$fileStorage"
                        wire:key="resource-{{ $fileStorage->uuid }}" />
                @endforeach
            </div>
        @endif
    @endif
</div>
