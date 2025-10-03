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
                        <div x-data="{
                            dropdownOpen: false,
                            volumeModalOpen: false,
                            fileModalOpen: false,
                            directoryModalOpen: false
                        }"
                            @close-storage-modal.window="
                            if ($event.detail === 'volume') volumeModalOpen = false;
                            if ($event.detail === 'file') fileModalOpen = false;
                            if ($event.detail === 'directory') directoryModalOpen = false;
                        ">
                            <div class="relative" @click.outside="dropdownOpen = false">
                                <x-forms.button @click="dropdownOpen = !dropdownOpen">
                                    + Add
                                    <svg class="w-4 h-4 ml-2" xmlns="http://www.w3.org/2000/svg" fill="none"
                                        viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M8.25 15L12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9" />
                                    </svg>
                                </x-forms.button>

                                <div x-show="dropdownOpen" @click.away="dropdownOpen=false"
                                    x-transition:enter="ease-out duration-200" x-transition:enter-start="-translate-y-2"
                                    x-transition:enter-end="translate-y-0" class="absolute top-0 z-50 mt-10 min-w-max"
                                    x-cloak>
                                    <div
                                        class="p-1 mt-1 bg-white border rounded-sm shadow-sm dark:bg-coolgray-200 dark:border-coolgray-300 border-neutral-300">
                                        <div class="flex flex-col gap-1">
                                            <a class="dropdown-item" @click="volumeModalOpen = true; dropdownOpen = false">
                                                <svg class="size-4" fill="none" stroke="currentColor"
                                                    viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                        d="M5 19a2 2 0 01-2-2V7a2 2 0 012-2h4l2 2h4a2 2 0 012 2v1M5 19h14a2 2 0 002-2v-5a2 2 0 00-2-2H9a2 2 0 00-2 2v5a2 2 0 01-2 2z" />
                                                </svg>
                                                Volume Mount
                                            </a>
                                            <a class="dropdown-item" @click="fileModalOpen = true; dropdownOpen = false">
                                                <svg class="size-4" fill="none" stroke="currentColor"
                                                    viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                        d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                                                </svg>
                                                File Mount
                                            </a>
                                            <a class="dropdown-item"
                                                @click="directoryModalOpen = true; dropdownOpen = false">
                                                <svg class="size-4" fill="none" stroke="currentColor"
                                                    viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                        d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" />
                                                </svg>
                                                Directory Mount
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{-- Volume Modal --}}
                            <template x-teleport="body">
                                <div x-show="volumeModalOpen" @keydown.window.escape="volumeModalOpen=false"
                                    class="fixed top-0 left-0 lg:px-0 px-4 z-99 flex items-center justify-center w-screen h-screen">
                                    <div x-show="volumeModalOpen" x-transition:enter="ease-out duration-100"
                                        x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                                        x-transition:leave="ease-in duration-100" x-transition:leave-start="opacity-100"
                                        x-transition:leave-end="opacity-0" @click="volumeModalOpen=false"
                                        class="absolute inset-0 w-full h-full bg-black/20 backdrop-blur-xs"></div>
                                    <div x-show="volumeModalOpen" x-trap.inert.noscroll="volumeModalOpen"
                                        x-transition:enter="ease-out duration-100"
                                        x-transition:enter-start="opacity-0 -translate-y-2 sm:scale-95"
                                        x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                                        x-transition:leave="ease-in duration-100"
                                        x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                                        x-transition:leave-end="opacity-0 -translate-y-2 sm:scale-95"
                                        class="relative w-full py-6 border rounded-sm drop-shadow-sm min-w-full lg:min-w-[36rem] max-w-fit bg-white border-neutral-200 dark:bg-base px-6 dark:border-coolgray-300">
                                        <div class="flex items-center justify-between pb-3">
                                            <h3 class="text-2xl font-bold">Add Volume Mount</h3>
                                            <button @click="volumeModalOpen=false"
                                                class="absolute top-0 right-0 flex items-center justify-center w-8 h-8 mt-5 mr-5 rounded-full dark:text-white hover:bg-neutral-100 dark:hover:bg-coolgray-300 outline-0 focus-visible:ring-2 focus-visible:ring-coollabs dark:focus-visible:ring-warning">
                                                <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none"
                                                    viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round"
                                                        d="M6 18L18 6M6 6l12 12" />
                                                </svg>
                                            </button>
                                        </div>
                                        <div class="relative flex items-center justify-center w-auto"
                                            x-init="$watch('volumeModalOpen', value => {
                                                if (value) {
                                                    $nextTick(() => {
                                                        const input = $el.querySelector('input');
                                                        input?.focus();
                                                    })
                                                }
                                            })">
                                            <form class="flex flex-col w-full gap-2 rounded-sm"
                                                wire:submit='submitPersistentVolume'>
                                                <div class="flex flex-col">
                                                    <div>Docker Volumes mounted to the container.</div>
                                                </div>
                                                @if ($isSwarm)
                                                    <div class="text-warning">Swarm Mode detected: You need to set a shared
                                                        volume
                                                        (EFS/NFS/etc) on all the worker nodes if you would like to use a
                                                        persistent
                                                        volumes.</div>
                                                @endif
                                                <div class="flex flex-col gap-2">
                                                    <x-forms.input canGate="update" :canResource="$resource" placeholder="pv-name"
                                                        id="name" label="Name" required helper="Volume name." />
                                                    @if ($isSwarm)
                                                        <x-forms.input canGate="update" :canResource="$resource"
                                                            placeholder="/root" id="host_path" label="Source Path" required
                                                            helper="Directory on the host system." />
                                                    @else
                                                        <x-forms.input canGate="update" :canResource="$resource"
                                                            placeholder="/root" id="host_path" label="Source Path"
                                                            helper="Directory on the host system." />
                                                    @endif
                                                    <x-forms.input canGate="update" :canResource="$resource"
                                                        placeholder="/tmp/root" id="mount_path" label="Destination Path"
                                                        required helper="Directory inside the container." />
                                                    <x-forms.button canGate="update" :canResource="$resource" type="submit">
                                                        Add
                                                    </x-forms.button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </template>

                            {{-- File Modal --}}
                            <template x-teleport="body">
                                <div x-show="fileModalOpen" @keydown.window.escape="fileModalOpen=false"
                                    class="fixed top-0 left-0 lg:px-0 px-4 z-99 flex items-center justify-center w-screen h-screen">
                                    <div x-show="fileModalOpen" x-transition:enter="ease-out duration-100"
                                        x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                                        x-transition:leave="ease-in duration-100" x-transition:leave-start="opacity-100"
                                        x-transition:leave-end="opacity-0" @click="fileModalOpen=false"
                                        class="absolute inset-0 w-full h-full bg-black/20 backdrop-blur-xs"></div>
                                    <div x-show="fileModalOpen" x-trap.inert.noscroll="fileModalOpen"
                                        x-transition:enter="ease-out duration-100"
                                        x-transition:enter-start="opacity-0 -translate-y-2 sm:scale-95"
                                        x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                                        x-transition:leave="ease-in duration-100"
                                        x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                                        x-transition:leave-end="opacity-0 -translate-y-2 sm:scale-95"
                                        class="relative w-full py-6 border rounded-sm drop-shadow-sm min-w-full lg:min-w-[36rem] max-w-fit bg-white border-neutral-200 dark:bg-base px-6 dark:border-coolgray-300">
                                        <div class="flex items-center justify-between pb-3">
                                            <h3 class="text-2xl font-bold">Add File Mount</h3>
                                            <button @click="fileModalOpen=false"
                                                class="absolute top-0 right-0 flex items-center justify-center w-8 h-8 mt-5 mr-5 rounded-full dark:text-white hover:bg-neutral-100 dark:hover:bg-coolgray-300 outline-0 focus-visible:ring-2 focus-visible:ring-coollabs dark:focus-visible:ring-warning">
                                                <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none"
                                                    viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round"
                                                        d="M6 18L18 6M6 6l12 12" />
                                                </svg>
                                            </button>
                                        </div>
                                        <div class="relative flex items-center justify-center w-auto"
                                            x-init="$watch('fileModalOpen', value => {
                                                if (value) {
                                                    $nextTick(() => {
                                                        const input = $el.querySelector('input');
                                                        input?.focus();
                                                    })
                                                }
                                            })">
                                            <form class="flex flex-col w-full gap-2 rounded-sm"
                                                wire:submit='submitFileStorage'>
                                                <div class="flex flex-col">
                                                    <div>Actual file mounted from the host system to the container.</div>
                                                </div>
                                                <div class="flex flex-col gap-2">
                                                    <x-forms.input canGate="update" :canResource="$resource"
                                                        placeholder="/etc/nginx/nginx.conf" id="file_storage_path"
                                                        label="Destination Path" required
                                                        helper="File location inside the container" />
                                                    <x-forms.textarea canGate="update" :canResource="$resource" label="Content"
                                                        id="file_storage_content"></x-forms.textarea>
                                                    <x-forms.button canGate="update" :canResource="$resource" type="submit">
                                                        Add
                                                    </x-forms.button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </template>

                            {{-- Directory Modal --}}
                            <template x-teleport="body">
                                <div x-show="directoryModalOpen" @keydown.window.escape="directoryModalOpen=false"
                                    class="fixed top-0 left-0 lg:px-0 px-4 z-99 flex items-center justify-center w-screen h-screen">
                                    <div x-show="directoryModalOpen" x-transition:enter="ease-out duration-100"
                                        x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                                        x-transition:leave="ease-in duration-100" x-transition:leave-start="opacity-100"
                                        x-transition:leave-end="opacity-0" @click="directoryModalOpen=false"
                                        class="absolute inset-0 w-full h-full bg-black/20 backdrop-blur-xs"></div>
                                    <div x-show="directoryModalOpen" x-trap.inert.noscroll="directoryModalOpen"
                                        x-transition:enter="ease-out duration-100"
                                        x-transition:enter-start="opacity-0 -translate-y-2 sm:scale-95"
                                        x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                                        x-transition:leave="ease-in duration-100"
                                        x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                                        x-transition:leave-end="opacity-0 -translate-y-2 sm:scale-95"
                                        class="relative w-full py-6 border rounded-sm drop-shadow-sm min-w-full lg:min-w-[36rem] max-w-fit bg-white border-neutral-200 dark:bg-base px-6 dark:border-coolgray-300">
                                        <div class="flex items-center justify-between pb-3">
                                            <h3 class="text-2xl font-bold">Add Directory Mount</h3>
                                            <button @click="directoryModalOpen=false"
                                                class="absolute top-0 right-0 flex items-center justify-center w-8 h-8 mt-5 mr-5 rounded-full dark:text-white hover:bg-neutral-100 dark:hover:bg-coolgray-300 outline-0 focus-visible:ring-2 focus-visible:ring-coollabs dark:focus-visible:ring-warning">
                                                <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none"
                                                    viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round"
                                                        d="M6 18L18 6M6 6l12 12" />
                                                </svg>
                                            </button>
                                        </div>
                                        <div class="relative flex items-center justify-center w-auto"
                                            x-init="$watch('directoryModalOpen', value => {
                                                if (value) {
                                                    $nextTick(() => {
                                                        const input = $el.querySelector('input');
                                                        input?.focus();
                                                    })
                                                }
                                            })">
                                            <form class="flex flex-col w-full gap-2 rounded-sm"
                                                wire:submit='submitFileStorageDirectory'>
                                                <div class="flex flex-col">
                                                    <div>Directory mounted from the host system to the container.</div>
                                                </div>
                                                <div class="flex flex-col gap-2">
                                                    <x-forms.input canGate="update" :canResource="$resource"
                                                        placeholder="{{ application_configuration_dir() }}/{{ $resource->uuid }}/etc/nginx"
                                                        id="file_storage_directory_source" label="Source Directory"
                                                        required helper="Directory on the host system." />
                                                    <x-forms.input canGate="update" :canResource="$resource"
                                                        placeholder="/etc/nginx" id="file_storage_directory_destination"
                                                        label="Destination Directory" required
                                                        helper="Directory inside the container." />
                                                    <x-forms.button canGate="update" :canResource="$resource" type="submit">
                                                        Add
                                                    </x-forms.button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
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
                        :class="activeTab === 'volumes' ? 'border-b-2 dark:border-white border-black' :
                            'border-b-2 border-transparent'"
                        @if (!$hasVolumes) disabled @endif
                        class="px-4 py-2 -mb-px font-medium transition-colors {{ $hasVolumes ? 'dark:text-neutral-400 dark:hover:text-white text-neutral-600 hover:text-black cursor-pointer' : 'opacity-50 cursor-not-allowed' }} focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-coollabs dark:focus-visible:ring-warning focus-visible:ring-offset-2 dark:focus-visible:ring-offset-coolgray-100">
                        Volumes ({{ $this->volumeCount }})
                    </button>
                    <button @click="activeTab = 'files'"
                        :class="activeTab === 'files' ? 'border-b-2 dark:border-white border-black' :
                            'border-b-2 border-transparent'"
                        @if (!$hasFiles) disabled @endif
                        class="px-4 py-2 -mb-px font-medium transition-colors {{ $hasFiles ? 'dark:text-neutral-400 dark:hover:text-white text-neutral-600 hover:text-black cursor-pointer' : 'opacity-50 cursor-not-allowed' }} focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-coollabs dark:focus-visible:ring-warning focus-visible:ring-offset-2 dark:focus-visible:ring-offset-coolgray-100">
                        Files ({{ $this->fileCount }})
                    </button>
                    <button @click="activeTab = 'directories'"
                        :class="activeTab === 'directories' ? 'border-b-2 dark:border-white border-black' :
                            'border-b-2 border-transparent'"
                        @if (!$hasDirectories) disabled @endif
                        class="px-4 py-2 -mb-px font-medium transition-colors {{ $hasDirectories ? 'dark:text-neutral-400 dark:hover:text-white text-neutral-600 hover:text-black cursor-pointer' : 'opacity-50 cursor-not-allowed' }} focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-coollabs dark:focus-visible:ring-warning focus-visible:ring-offset-2 dark:focus-visible:ring-offset-coolgray-100">
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
        <div class="flex flex-col gap-4 py-2">
            <div>
                <div class="flex items-center gap-2">
                    <h2>{{ Str::headline($resource->name) }}</h2>
                </div>
            </div>

            @if ($resource->persistentStorages()->get()->count() === 0 && $fileStorage->count() == 0)
                <div>No storage found.</div>
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
                            :class="activeTab === 'volumes' ? 'border-b-2 dark:border-white border-black' :
                                'border-b-2 border-transparent'"
                            @if (!$hasVolumes) disabled @endif
                            class="px-4 py-2 -mb-px font-medium transition-colors {{ $hasVolumes ? 'dark:text-neutral-400 dark:hover:text-white text-neutral-600 hover:text-black cursor-pointer' : 'opacity-50 cursor-not-allowed' }} focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-coollabs dark:focus-visible:ring-warning focus-visible:ring-offset-2 dark:focus-visible:ring-offset-coolgray-100">
                            Volumes ({{ $this->volumeCount }})
                        </button>
                        <button @click="activeTab = 'files'"
                            :class="activeTab === 'files' ? 'border-b-2 dark:border-white border-black' :
                                'border-b-2 border-transparent'"
                            @if (!$hasFiles) disabled @endif
                            class="px-4 py-2 -mb-px font-medium transition-colors {{ $hasFiles ? 'dark:text-neutral-400 dark:hover:text-white text-neutral-600 hover:text-black cursor-pointer' : 'opacity-50 cursor-not-allowed' }} focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-coollabs dark:focus-visible:ring-warning focus-visible:ring-offset-2 dark:focus-visible:ring-offset-coolgray-100">
                            Files ({{ $this->fileCount }})
                        </button>
                        <button @click="activeTab = 'directories'"
                            :class="activeTab === 'directories' ? 'border-b-2 dark:border-white border-black' :
                                'border-b-2 border-transparent'"
                            @if (!$hasDirectories) disabled @endif
                            class="px-4 py-2 -mb-px font-medium transition-colors {{ $hasDirectories ? 'dark:text-neutral-400 dark:hover:text-white text-neutral-600 hover:text-black cursor-pointer' : 'opacity-50 cursor-not-allowed' }} focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-coollabs dark:focus-visible:ring-warning focus-visible:ring-offset-2 dark:focus-visible:ring-offset-coolgray-100">
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
        </div>
    @endif
</div>
