@php
    $hasVolumes = $this->volumeCount > 0;
    $hasFiles = $this->fileCount > 0;
    $hasDirectories = $this->directoryCount > 0;
    $defaultTab = $hasVolumes ? 'volumes' : ($hasFiles ? 'files' : 'directories');
@endphp

<div class="flex flex-col gap-6" x-data="{ activeTab: '{{ $defaultTab }}' }">
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
        <x-application.settings-section id="storage-mounts-section" title="Persistent storage"
            helper="Preview deployment volumes can use a -pr-#PRNumber suffix so each pull request receives isolated storage."
            flush>
            <x-slot:actions>
                @if ($resource?->build_pack !== 'dockercompose')
                    @can('update', $resource)
                        <div x-data="{
                            dropdownOpen: false,
                            volumeModalOpen: false,
                            fileModalOpen: false,
                            hostFileModalOpen: false,
                            directoryModalOpen: false
                        }"
                            @close-storage-modal.window="
                            if ($event.detail === 'volume') volumeModalOpen = false;
                            if ($event.detail === 'file') fileModalOpen = false;
                            if ($event.detail === 'host-file') hostFileModalOpen = false;
                            if ($event.detail === 'directory') directoryModalOpen = false;
                        ">
                            <div class="relative" @click.outside="dropdownOpen = false">
                                <x-forms.button
                                    class="bg-coollabs/10! text-coollabs! ring-1 ring-coollabs/25 hover:bg-coollabs/15! dark:bg-warning/15! dark:text-warning! dark:ring-warning/25 dark:hover:bg-warning/20!"
                                    @click="dropdownOpen = !dropdownOpen" aria-haspopup="menu"
                                    x-bind:aria-expanded="dropdownOpen">
                                    <x-reicon name="plus" class="size-3.5" />
                                    Add mount
                                </x-forms.button>

                                <div x-show="dropdownOpen" x-cloak role="menu"
                                    x-transition:enter="ease-out duration-100"
                                    x-transition:enter-start="opacity-0 -translate-y-1"
                                    x-transition:enter-end="opacity-100 translate-y-0"
                                    class="listbox-panel left-auto! right-0 min-w-0! w-44!">
                                    <button type="button" class="listbox-option justify-start! gap-2.5!" role="menuitem"
                                        @click="volumeModalOpen = true; dropdownOpen = false">
                                        <x-reicon name="storages" class="size-4" />
                                        Volume mount
                                    </button>
                                    <button type="button" class="listbox-option justify-start! gap-2.5!" role="menuitem"
                                        @click="fileModalOpen = true; dropdownOpen = false">
                                        <x-reicon name="file" class="size-4" />
                                        File mount
                                    </button>
                                    <button type="button" class="listbox-option justify-start! gap-2.5!" role="menuitem"
                                        @click="hostFileModalOpen = true; dropdownOpen = false">
                                        <x-reicon name="file-content" class="size-4" />
                                        Host file mount
                                    </button>
                                    <button type="button" class="listbox-option justify-start! gap-2.5!" role="menuitem"
                                        @click="directoryModalOpen = true; dropdownOpen = false">
                                        <x-reicon name="folder" class="size-4" />
                                        Directory mount
                                    </button>
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
                                        class="application-settings-form application-settings-section relative w-full min-w-full lg:min-w-[36rem] lg:max-w-2xl">
                                        <header>
                                            <h3>Add volume mount</h3>
                                            <button @click="volumeModalOpen=false"
                                                class="flex size-7 items-center justify-center rounded-md text-neutral-500 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.07] dark:hover:text-fg">
                                                <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none"
                                                    viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round"
                                                        d="M6 18L18 6M6 6l12 12" />
                                                </svg>
                                            </button>
                                        </header>
                                        <div class="application-settings-section-body relative flex items-center justify-center w-auto"
                                            x-init="$watch('volumeModalOpen', value => {
                                                if (value) {
                                                    $nextTick(() => {
                                                        const input = $el.querySelector('input');
                                                        input?.focus();
                                                    })
                                                }
                                            })">
                                            <form class="flex w-full flex-col gap-4"
                                                wire:submit='submitPersistentVolume'>
                                                <p class="text-[13px] leading-5 text-neutral-500 dark:text-fg-dim">
                                                    Mount a Docker volume inside the container.
                                                </p>
                                                @if ($isSwarm)
                                                    <div class="text-warning">Swarm Mode detected: You need to set a shared
                                                        volume
                                                        (EFS/NFS/etc) on all the worker nodes if you would like to use a
                                                        persistent
                                                        volumes.</div>
                                                @endif
                                                <div class="flex flex-col gap-4">
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
                                                    <div class="flex justify-end pt-2">
                                                        <x-forms.button canGate="update" :canResource="$resource" type="submit">
                                                            Add volume
                                                        </x-forms.button>
                                                    </div>
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
                                        class="application-settings-form application-settings-section relative w-full min-w-full lg:min-w-[36rem] lg:max-w-2xl">
                                        <header>
                                            <h3>Add file mount</h3>
                                            <button @click="fileModalOpen=false"
                                                class="flex size-7 items-center justify-center rounded-md text-neutral-500 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.07] dark:hover:text-fg">
                                                <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none"
                                                    viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round"
                                                        d="M6 18L18 6M6 6l12 12" />
                                                </svg>
                                            </button>
                                        </header>
                                        <div class="application-settings-section-body relative flex items-center justify-center w-auto"
                                            x-init="$watch('fileModalOpen', value => {
                                                if (value) {
                                                    $nextTick(() => {
                                                        const input = $el.querySelector('input');
                                                        input?.focus();
                                                    })
                                                }
                                            })">
                                            <form class="flex w-full flex-col gap-4"
                                                x-data="{
                                                    hostPath: @js($this->fileStorageHostPath()),
                                                    filePath: @entangle('file_storage_path'),
                                                    previewPath() {
                                                        const path = (this.filePath || '').trim();

                                                        return this.hostPath + (path === '' ? '/' : (path.startsWith('/') ? path : `/${path}`));
                                                    },
                                                }"
                                                wire:submit='submitFileStorage'>
                                                <p class="text-[13px] leading-5 text-neutral-500 dark:text-fg-dim">
                                                    Create a managed file on the host and mount it inside the container.
                                                </p>
                                                <div class="flex flex-col gap-4">
                                                    <div class="rounded-lg bg-neutral-100 p-3 text-xs ring-1 ring-neutral-200 dark:bg-white/[0.04] dark:ring-white/[0.07]">
                                                        <div class="mb-1 font-medium">Host file path</div>
                                                        <code class="break-all" x-text="previewPath()">{{ $this->fileStoragePreviewPath() }}</code>
                                                    </div>
                                                    <x-forms.input canGate="update" :canResource="$resource"
                                                        placeholder="/etc/nginx/nginx.conf" id="file_storage_path"
                                                        label="Destination Path" required
                                                        x-on:input="filePath = $event.target.value"
                                                        helper="File location inside the container" />
                                                    <x-forms.textarea canGate="update" :canResource="$resource" label="Content"
                                                        id="file_storage_content"></x-forms.textarea>
                                                    <div class="flex justify-end pt-2">
                                                        <x-forms.button canGate="update" :canResource="$resource" type="submit">
                                                            Add file
                                                        </x-forms.button>
                                                    </div>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </template>

                            {{-- Host File Modal --}}
                            <template x-teleport="body">
                                <div x-show="hostFileModalOpen" @keydown.window.escape="hostFileModalOpen=false"
                                    class="fixed top-0 left-0 lg:px-0 px-4 z-99 flex items-center justify-center w-screen h-screen">
                                    <div x-show="hostFileModalOpen" x-transition:enter="ease-out duration-100"
                                        x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                                        x-transition:leave="ease-in duration-100" x-transition:leave-start="opacity-100"
                                        x-transition:leave-end="opacity-0" @click="hostFileModalOpen=false"
                                        class="absolute inset-0 w-full h-full bg-black/20 backdrop-blur-xs"></div>
                                    <div x-show="hostFileModalOpen" x-trap.inert.noscroll="hostFileModalOpen"
                                        x-transition:enter="ease-out duration-100"
                                        x-transition:enter-start="opacity-0 -translate-y-2 sm:scale-95"
                                        x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                                        x-transition:leave="ease-in duration-100"
                                        x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                                        x-transition:leave-end="opacity-0 -translate-y-2 sm:scale-95"
                                        class="application-settings-form application-settings-section relative w-full min-w-full lg:min-w-[36rem] lg:max-w-2xl">
                                        <header>
                                            <h3>Add host file mount</h3>
                                            <button @click="hostFileModalOpen=false"
                                                class="flex size-7 items-center justify-center rounded-md text-neutral-500 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.07] dark:hover:text-fg">
                                                <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none"
                                                    viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round"
                                                        d="M6 18L18 6M6 6l12 12" />
                                                </svg>
                                            </button>
                                        </header>
                                        <div class="application-settings-section-body relative flex items-center justify-center w-auto"
                                            x-init="$watch('hostFileModalOpen', value => {
                                                if (value) {
                                                    $nextTick(() => {
                                                        const input = $el.querySelector('input');
                                                        input?.focus();
                                                    })
                                                }
                                            })">
                                            <form class="flex w-full flex-col gap-4"
                                                wire:submit='submitHostFileStorage'>
                                                <p class="text-[13px] leading-5 text-neutral-500 dark:text-fg-dim">
                                                    Bind an existing host file into the container. Coolify will not modify
                                                    or delete the source file.
                                                </p>
                                                <div class="flex flex-col gap-4">
                                                    <x-forms.input canGate="update" :canResource="$resource"
                                                        placeholder="/etc/nginx/nginx.conf"
                                                        id="host_file_storage_source" label="Host File Path" required
                                                        helper="Existing file on the host system." />
                                                    <x-forms.input canGate="update" :canResource="$resource"
                                                        placeholder="/etc/nginx/nginx.conf"
                                                        id="host_file_storage_destination" label="Destination Path"
                                                        required helper="File location inside the container." />
                                                    <div class="flex justify-end pt-2">
                                                        <x-forms.button canGate="update" :canResource="$resource" type="submit">
                                                            Add host file
                                                        </x-forms.button>
                                                    </div>
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
                                        class="application-settings-form application-settings-section relative w-full min-w-full lg:min-w-[36rem] lg:max-w-2xl">
                                        <header>
                                            <h3>Add directory mount</h3>
                                            <button @click="directoryModalOpen=false"
                                                class="flex size-7 items-center justify-center rounded-md text-neutral-500 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.07] dark:hover:text-fg">
                                                <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none"
                                                    viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round"
                                                        d="M6 18L18 6M6 6l12 12" />
                                                </svg>
                                            </button>
                                        </header>
                                        <div class="application-settings-section-body relative flex items-center justify-center w-auto"
                                            x-init="$watch('directoryModalOpen', value => {
                                                if (value) {
                                                    $nextTick(() => {
                                                        const input = $el.querySelector('input');
                                                        input?.focus();
                                                    })
                                                }
                                            })">
                                            <form class="flex w-full flex-col gap-4"
                                                wire:submit='submitFileStorageDirectory'>
                                                <p class="text-[13px] leading-5 text-neutral-500 dark:text-fg-dim">
                                                    Bind a directory from the host system into the container.
                                                </p>
                                                <div class="flex flex-col gap-4">
                                                    <x-forms.input canGate="update" :canResource="$resource"
                                                        placeholder="{{ application_configuration_dir() }}/{{ $resource->uuid }}/etc/nginx"
                                                        id="file_storage_directory_source" label="Source Directory"
                                                        required helper="Directory on the host system." />
                                                    <x-forms.input canGate="update" :canResource="$resource"
                                                        placeholder="/etc/nginx" id="file_storage_directory_destination"
                                                        label="Destination Directory" required
                                                        helper="Directory inside the container." />
                                                    <div class="flex justify-end pt-2">
                                                        <x-forms.button canGate="update" :canResource="$resource" type="submit">
                                                            Add directory
                                                        </x-forms.button>
                                                    </div>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    @endcan
                @endif
                @if ($hasVolumes || $hasFiles || $hasDirectories)
                    <div
                        class="inline-flex items-center gap-0.5 rounded-lg bg-neutral-100 p-1 dark:bg-white/[0.04]">
                        <button type="button" @click="activeTab = 'volumes'"
                            :class="activeTab === 'volumes'
                                ? 'bg-white text-black shadow-sm ring-1 ring-neutral-200 dark:bg-white/[0.09] dark:text-fg dark:ring-white/[0.08]'
                                : 'text-neutral-500 hover:text-black dark:text-fg-faint dark:hover:text-fg'"
                            @disabled(!$hasVolumes)
                            class="h-7 rounded-md px-2.5 text-[12px] font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-40">
                            Volumes ({{ $this->volumeCount }})
                        </button>
                        <button type="button" @click="activeTab = 'files'"
                            :class="activeTab === 'files'
                                ? 'bg-white text-black shadow-sm ring-1 ring-neutral-200 dark:bg-white/[0.09] dark:text-fg dark:ring-white/[0.08]'
                                : 'text-neutral-500 hover:text-black dark:text-fg-faint dark:hover:text-fg'"
                            @disabled(!$hasFiles)
                            class="h-7 rounded-md px-2.5 text-[12px] font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-40">
                            Files ({{ $this->fileCount }})
                        </button>
                        <button type="button" @click="activeTab = 'directories'"
                            :class="activeTab === 'directories'
                                ? 'bg-white text-black shadow-sm ring-1 ring-neutral-200 dark:bg-white/[0.09] dark:text-fg dark:ring-white/[0.08]'
                                : 'text-neutral-500 hover:text-black dark:text-fg-faint dark:hover:text-fg'"
                            @disabled(!$hasDirectories)
                            class="h-7 rounded-md px-2.5 text-[12px] font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-40">
                            Directories ({{ $this->directoryCount }})
                        </button>
                    </div>
                @endif
            </x-slot:actions>

        @if (!$hasVolumes && !$hasFiles && !$hasDirectories)
            <x-empty title="No persistent storage"
                description="Add a volume, file, or directory mount to preserve data between deployments.">
                <x-slot:icon>
                    <x-reicon name="storages" class="size-8" />
                </x-slot:icon>
            </x-empty>
        @else
            <div>
                {{-- Tab Content --}}
                <div class="p-4">
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
        </x-application.settings-section>
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
