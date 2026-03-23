<div>
    <x-slot:title>
        {{ data_get_str($resource, 'name')->limit(10) }} > Files | Coolify
    </x-slot>
    @if ($type === 'application')
        <livewire:project.shared.configuration-checker :resource="$resource" />
        <h1>Files</h1>
        <livewire:project.application.heading :application="$resource" />
    @elseif ($type === 'database')
        <livewire:project.shared.configuration-checker :resource="$resource" />
        <h1>Files</h1>
        <livewire:project.database.heading :database="$resource" />
    @elseif ($type === 'service')
        <livewire:project.shared.configuration-checker :resource="$resource" />
        <livewire:project.service.heading :service="$resource" :parameters="$parameters" title="Files" />
    @endif

    @if ($type === 'application' || $type === 'database' || $type === 'service')
        <h2 class="pb-4">File Explorer</h2>
        @if (!isset($containers) || $containers->isEmpty())
            <div>No containers are running or terminal access is disabled on this server.</div>
        @else
            <div class="flex flex-col gap-4">
                <!-- Container Selection -->
                <div class="flex gap-2 items-end">
                    <div class="w-96 min-w-fit">
                        <x-forms.select id="selected_container" wire:model.live="selected_container">
                            <option value="default">Select a container</option>
                            @foreach ($containers as $container)
                                <option value="{{ data_get($container, 'container.Names') }}">
                                    {{ data_get($container, 'server.name') }} -> {{ data_get($container, 'container.Names') }}
                                </option>
                            @endforeach
                        </x-forms.select>
                    </div>
                    <x-forms.button wire:click="loadFiles" wire:loading.attr="disabled">
                        <x-loading wire:loading wire:target="loadFiles" />
                        Refresh
                    </x-forms.button>
                    <x-forms.button wire:click="checkAndInstallUnzip" wire:loading.attr="disabled" class="bg-green-600">
                        <x-loading wire:loading wire:target="checkAndInstallUnzip" />
                        Verificar/Instalar unzip
                    </x-forms.button>
                </div>

                @if ($selected_container !== 'default')
                    <!-- Toolbar -->
                    <div class="flex flex-wrap gap-2 items-center">
                        <x-forms.button wire:click="showCreateFolderDialog" class="bg-coollabs">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                            </svg>
                            New Folder
                        </x-forms.button>
                        <div class="relative inline-block"
                             x-data="{
                                 uploading: false,
                                 progress: 0,
                                 fileName: '',
                                 errorMsg: '',
                                 successMsg: '',
                                 async uploadChunked(file) {
                                     const CHUNK_SIZE = 10 * 1024 * 1024; // 10MB
                                     const totalChunks = Math.ceil(file.size / CHUNK_SIZE);
                                     const uploadId = Date.now() + '_' + Math.random().toString(36).substr(2, 9);
                                     const csrfToken = document.querySelector('meta[name=csrf-token]')?.content;

                                     this.uploading = true;
                                     this.progress = 0;
                                     this.fileName = file.name;
                                     this.errorMsg = '';
                                     this.successMsg = '';

                                     try {
                                         // Send each chunk
                                         for (let i = 0; i < totalChunks; i++) {
                                             const start = i * CHUNK_SIZE;
                                             const end = Math.min(start + CHUNK_SIZE, file.size);
                                             const chunk = file.slice(start, end);

                                             const formData = new FormData();
                                             formData.append('chunk', chunk, file.name);
                                             formData.append('uploadId', uploadId);
                                             formData.append('chunkIndex', i);
                                             formData.append('totalChunks', totalChunks);
                                             formData.append('fileName', file.name);

                                             const resp = await fetch('/file-explorer/upload-chunk', {
                                                 method: 'POST',
                                                 headers: { 'X-CSRF-TOKEN': csrfToken },
                                                 body: formData
                                             });

                                             if (!resp.ok) {
                                                 const err = await resp.json().catch(() => ({}));
                                                 throw new Error(err.message || 'Chunk ' + i + ' failed');
                                             }

                                             this.progress = Math.round(((i + 1) / totalChunks) * 90); // 0-90% for chunks
                                         }

                                         // Finalize: assemble + docker cp
                                         this.progress = 92;
                                         const serverId = await $wire.getSelectedServerId();
                                         const containerName = $wire.selected_container;
                                         const currentPath = $wire.currentPath;

                                         const finalResp = await fetch('/file-explorer/finalize-upload', {
                                             method: 'POST',
                                             headers: {
                                                 'X-CSRF-TOKEN': csrfToken,
                                                 'Content-Type': 'application/json',
                                                 'Accept': 'application/json'
                                             },
                                             body: JSON.stringify({
                                                 uploadId: uploadId,
                                                 totalChunks: totalChunks,
                                                 fileName: file.name,
                                                 containerName: containerName,
                                                 serverId: serverId,
                                                 destinationPath: currentPath
                                             })
                                         });

                                         const result = await finalResp.json();

                                         if (!finalResp.ok || !result.success) {
                                             throw new Error(result.message || 'Finalize failed');
                                         }

                                         this.progress = 100;
                                         this.successMsg = 'File uploaded!';
                                         alert('Upload completed! Saved in: ' + currentPath);

                                         // Refresh file list via Livewire
                                         $wire.onChunkedUploadComplete();

                                         setTimeout(() => {
                                             this.uploading = false;
                                             this.progress = 0;
                                             this.fileName = '';
                                             this.successMsg = '';
                                             document.getElementById('uploadFileInput').value = '';
                                         }, 2000);

                                     } catch (err) {
                                         this.errorMsg = err.message;
                                         this.uploading = false;
                                         this.progress = 0;
                                         this.fileName = '';
                                         $wire.dispatch('error', 'Upload failed: ' + err.message);
                                         document.getElementById('uploadFileInput').value = '';
                                     }
                                 }
                             }">
                            <input type="file"
                                   id="uploadFileInput"
                                   class="hidden"
                                   accept="*"
                                   x-on:change="
                                       if ($event.target.files.length > 0) {
                                           uploadChunked($event.target.files[0]);
                                       }
                                   ">
                            <x-forms.button type="button"
                                            class="bg-coollabs"
                                            onclick="document.getElementById('uploadFileInput').click()"
                                            :disabled="$selected_container === 'default'">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
                                </svg>
                                Upload File
                            </x-forms.button>

                            <!-- Progress Bar -->
                            <div x-show="uploading"
                                 x-cloak
                                 class="absolute top-full left-0 right-0 mt-2 w-full min-w-[250px] bg-white dark:bg-coolgray-800 rounded-lg shadow-lg border border-coolgray-300 dark:border-coolgray-600 p-3 z-50">
                                <div class="flex items-center gap-2 mb-2">
                                    <svg class="w-4 h-4 text-coollabs animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                                    </svg>
                                    <span class="text-sm font-medium dark:text-white truncate max-w-[150px]" x-text="fileName || 'Uploading...'"></span>
                                    <span class="text-xs text-gray-500 dark:text-gray-400 ml-auto" x-text="Math.round(progress) + '%'"></span>
                                </div>
                                <div class="w-full bg-coolgray-200 dark:bg-coolgray-700 rounded-full h-2 overflow-hidden">
                                    <div class="bg-coollabs h-2 rounded-full transition-all duration-300 ease-out"
                                         :style="'width: ' + progress + '%'"></div>
                                </div>
                                <div x-show="successMsg" class="text-xs text-green-500 mt-1" x-text="successMsg"></div>
                                <div x-show="errorMsg" class="text-xs text-red-500 mt-1" x-text="errorMsg"></div>
                            </div>

                            @if ($selected_container === 'default')
                                <div class="absolute top-full left-0 mt-1 text-xs text-red-500 whitespace-nowrap">
                                    Select a container first
                                </div>
                            @endif
                        </div>
                        @if (isset($selectedFiles) && is_array($selectedFiles) && count($selectedFiles) > 0)
                            <x-modal-confirmation title="Delete selected items?" buttonTitle="Delete Selected" submitAction="deleteSelectedFiles()" :confirmWithText="false" :confirmWithPassword="false" step1ButtonText="Delete" step2ButtonText="Confirm">
                                <x-slot:content>
                                    <x-forms.button @click.prevent="modalOpen=true" class="bg-red-600">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                        </svg>
                                        Delete ({{ count($selectedFiles) }})
                                    </x-forms.button>
                                </x-slot:content>
                            </x-modal-confirmation>
                            <x-forms.button wire:click="openCompressDialog" class="bg-green-600">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                                </svg>
                                Compress ({{ count($selectedFiles) }})
                            </x-forms.button>
                            @if (count($selectedFiles) === 1 && preg_match('/\.(zip|tar|tar\.gz|tar\.bz2|tar\.xz|tgz|tbz2|tbz|txz|gz)$/i', $selectedFiles[0]))
                                <x-forms.button wire:click="extractSelectedFiles" class="bg-blue-600">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                                    </svg>
                                    Extract
                                </x-forms.button>
                            @endif
                            <x-forms.button wire:click="deselectAll" class="bg-gray-600">
                                Clear Selection
                            </x-forms.button>
                        @else
                            <x-forms.button wire:click="selectAll" class="bg-gray-600">
                                Select All
                            </x-forms.button>
                        @endif
                            <x-forms.button wire:click="openDatabasePanel" class="bg-green-600">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"></path>
                                </svg>
                            phpMyAdmin
                            </x-forms.button>
                        @if ($selected_container !== 'default')
                            <x-forms.button wire:click="openImportDatabaseDialog" class="bg-purple-600">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path>
                                </svg>
                                Import Database
                            </x-forms.button>
                        <x-forms.button wire:click="openQuickEdit('wp-config.php')" class="bg-blue-600" title="Quick Edit wp-config.php">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                            </svg>
                            wp-config.php
                        </x-forms.button>
                        <x-forms.button wire:click="openQuickEdit('.env')" class="bg-blue-600" title="Quick Edit .env">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                            </svg>
                            .env
                        </x-forms.button>
                        @endif
                    </div>
                @endif

                    <!-- Breadcrumb Navigation -->
                    <div class="flex items-center gap-2 flex-wrap">
                        <button wire:click="navigateTo('/')" class="text-coollabs hover:underline">
                            /
                        </button>
                        @php
                            $currentPathSafe = $currentPath ?? '/';
                            $exploded = explode('/', $currentPathSafe);
                            $pathParts = [];
                            foreach ($exploded as $part) {
                                if (!empty($part) && $part !== '/') {
                                    $pathParts[] = $part;
                                }
                            }
                            $currentPathParts = [];
                            $breadcrumbPaths = [];
                            foreach ($pathParts as $part) {
                                $currentPathParts[] = $part;
                                $breadcrumbPaths[] = [
                                    'part' => $part,
                                    'path' => '/' . implode('/', $currentPathParts)
                                ];
                            }
                        @endphp
                        @foreach ($breadcrumbPaths as $breadcrumb)
                            <span>/</span>
                            <button wire:click="navigateTo('{{ $breadcrumb['path'] }}')" class="text-coollabs hover:underline">
                                {{ $breadcrumb['part'] }}
                            </button>
                        @endforeach
                    </div>

                    <!-- File List -->
                    <div class="box-without-bg">
                        @if ($isLoading)
                            <div class="flex items-center justify-center p-8">
                                <x-loading text="Loading files..." />
                            </div>
                        @elseif (!isset($files) || !is_array($files) || count($files) === 0)
                            <div class="p-8 text-center text-gray-500">No files found in this directory.</div>
                        @else
                            <div class="overflow-x-auto">
                                <table class="w-full">
                                    <thead>
                                        <tr class="border-b border-coolgray-300 dark:border-coolgray-600">
                                            <th class="text-left p-2 w-12">
                                                @php
                                                    $allSelected = false;
                                                    if (isset($selectedFiles) && is_array($selectedFiles) && isset($files) && is_array($files)) {
                                                        $allSelected = count($selectedFiles) === count($files);
                                                    }
                                                @endphp
                                                <input type="checkbox" wire:change="selectAll" class="cursor-pointer" title="Select All" {{ $allSelected ? 'checked' : '' }}>
                                            </th>
                                            <th class="text-left p-2">Name</th>
                                            <th class="text-left p-2">Size</th>
                                            <th class="text-left p-2">Permissions</th>
                                            <th class="text-left p-2">Date</th>
                                            <th class="text-right p-2">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($files ?? [] as $file)
                                            @php
                                                // Ensure file path exists and is valid
                                                $filePath = null;
                                                $filePathEscaped = '';
                                                $isSelected = false;

                                                // Ensure selectedFiles is always an array
                                                $selectedFilesArray = isset($selectedFiles) && is_array($selectedFiles) ? $selectedFiles : [];

                                                if (isset($file['path']) && is_string($file['path'])) {
                                                    $trimmedPath = trim($file['path']);
                                                    if (!empty($trimmedPath)) {
                                                        $filePath = $trimmedPath;
                                                        $isSelected = in_array($filePath, $selectedFilesArray, true);
                                                        // Escape filePath for use in HTML attributes
                                                        $filePathEscaped = htmlspecialchars($filePath, ENT_QUOTES, 'UTF-8');
                                                        $filePathEncoded = rtrim(strtr(base64_encode($filePath), '+/', '-_'), '=');
                                                    }
                                                }
                                            @endphp
                                            @if (empty($filePath) || empty($filePathEscaped))
                                                @continue
                                            @endif
                                            <tr wire:key="file-row-{{ md5($filePath) }}" class="border-b border-coolgray-200 dark:border-coolgray-700 hover:bg-coolgray-50 dark:hover:bg-coolgray-800 {{ $isSelected ? 'bg-blue-50 dark:bg-blue-900/20' : '' }}">
                                                <td class="p-2">
                                                    <input type="checkbox" wire:change='toggleFileSelection(@js($filePath))' {{ $isSelected ? 'checked' : '' }} class="cursor-pointer">
                                                </td>
                                                <td class="p-2">
                                                    <div class="flex items-center gap-2">
                                                        @if ($file['is_directory'])
                                                            <svg class="w-5 h-5 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"></path>
                                                            </svg>
                                                            <button wire:click='navigateTo(@js($filePath))' class="text-coollabs hover:underline font-medium">
                                                                {{ $file['name'] }}
                                                            </button>
                                                        @else
                                                            <svg class="w-5 h-5 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                                            </svg>
                                                            <button wire:click='openFile(@js($filePath))' class="text-coollabs hover:underline">
                                                                {{ $file['name'] }}
                                                            </button>
                                                        @endif
                                                    </div>
                                                </td>
                                                <td class="p-2 text-sm text-gray-600 dark:text-gray-400">{{ $file['size'] }}</td>
                                                <td class="p-2 text-sm font-mono text-gray-600 dark:text-gray-400">{{ $file['permissions'] }}</td>
                                                <td class="p-2 text-sm text-gray-600 dark:text-gray-400">{{ $file['date'] }}</td>
                                                <td class="p-2">
                                                    <div class="flex items-center justify-end gap-1">
                                                        @if (!$file['is_directory'])
                                                            <a href="{{ $file['download_url'] ?? '#' }}" target="_blank" class="inline-flex items-center justify-center px-2 py-1 text-xs font-medium text-white bg-coollabs rounded hover:bg-coollabs-600" title="Download">
                                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                                                                </svg>
                                                            </a>
                                                            <x-forms.button wire:click='compressFile(@js($filePath))' class="!text-xs !px-2 !py-1" title="Compress">
                                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                                                                </svg>
                                                            </x-forms.button>
                                                        @endif
                                                        @php
                                                            $fileName = strtolower($file['name']);
                                                            $isArchive = str_ends_with($fileName, '.zip') ||
                                                                        str_ends_with($fileName, '.tar') ||
                                                                        str_ends_with($fileName, '.tar.gz') ||
                                                                        str_ends_with($fileName, '.tgz') ||
                                                                        str_ends_with($fileName, '.tar.bz2') ||
                                                                        str_ends_with($fileName, '.tbz2') ||
                                                                        str_ends_with($fileName, '.tbz') ||
                                                                        str_ends_with($fileName, '.tar.xz') ||
                                                                        str_ends_with($fileName, '.txz') ||
                                                                        str_ends_with($fileName, '.gz') ||
                                                                        str_ends_with($fileName, '.bz2') ||
                                                                        str_ends_with($fileName, '.xz');
                                                        @endphp
                                                        @if ($isArchive)
                                                            <x-forms.button wire:click='decompressFile(@js($filePath))' class="!text-xs !px-2 !py-1" title="Decompress">
                                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                                                                </svg>
                                                            </x-forms.button>
                                                        @endif
                                                        <x-forms.button wire:click='openRenameDialog(@js($filePath))' class="!text-xs !px-2 !py-1" title="Rename">
                                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                                            </svg>
                                                        </x-forms.button>
                                                        <x-forms.button wire:click='openMoveDialog(@js($filePath))' class="!text-xs !px-2 !py-1" title="Move">
                                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path>
                                                            </svg>
                                                        </x-forms.button>
                                                        <x-modal-confirmation title="Delete File?" buttonTitle="Delete" submitAction="deleteFileByEncodedPath('{{ $filePathEncoded }}')" :confirmWithText="false" :confirmWithPassword="false" step1ButtonText="Delete" step2ButtonText="Confirm" :ignoreWire="false">
                                            <x-slot:content>
                                                <div class="cursor-pointer" title="Delete">
                                                    <x-forms.button @click.prevent="modalOpen=true" class="!text-xs !px-2 !py-1" type="button">
                                                        <svg class="w-4 h-4 text-error" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                                        </svg>
                                                    </x-forms.button>
                                                </div>
                                            </x-slot:content>
                                        </x-modal-confirmation>
                                                    </div>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>

                    <!-- File Editor Modal -->
                    @if ($selectedFile)
                        @php
                            try {
                                $fileLanguage = !empty($selectedFile) ? $this->getFileLanguage($selectedFile) : 'plaintext';
                            } catch (\Throwable $e) {
                                $fileLanguage = 'plaintext';
                            }
                        @endphp
                        <template x-teleport="body">
                            <div x-data="{ editorOpen: true }"
                                x-show="editorOpen"
                                x-cloak
                                class="fixed top-0 left-0 z-[99999] flex flex-col w-screen h-screen bg-coolgray-50 dark:bg-coolgray-900"
                                style="z-index: 99999;">
                                <!-- Backdrop -->
                                <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" wire:click="closeFile"></div>

                                <!-- Modal Content -->
                                <div class="relative z-10 flex flex-col w-full h-full bg-white dark:bg-coolgray-800 shadow-2xl">
                                    <!-- Header -->
                                    <div class="flex items-center justify-between px-6 py-4 border-b border-coolgray-300 dark:border-coolgray-600 bg-white dark:bg-coolgray-800 shrink-0">
                                        <div class="flex items-center gap-3">
                                            <svg class="w-5 h-5 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                            </svg>
                                            <h3 class="text-lg font-semibold">{{ !empty($selectedFile) ? basename($selectedFile) : '' }}</h3>
                                            <span class="px-2 py-1 text-xs font-mono text-gray-500 dark:text-gray-400 bg-coolgray-100 dark:bg-coolgray-700 rounded">
                                                {{ $fileLanguage }}
                                            </span>
                                            <span class="text-sm text-gray-500 dark:text-gray-400">{{ $selectedFile }}</span>
                                        </div>
                                        <div class="flex gap-2">
                                            <x-forms.button wire:click="saveFile" class="bg-green-600 hover:bg-green-700">
                                                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                                </svg>
                                                Save
                                            </x-forms.button>
                                            <x-forms.button wire:click="closeFile" class="bg-gray-600 hover:bg-gray-700">
                                                Close
                                            </x-forms.button>
                                        </div>
                                    </div>

                                    <!-- Editor Container -->
                                    <div class="flex-1 overflow-hidden" style="height: calc(100vh - 80px); min-height: 0;">
                                        <div wire:key="file-editor-{{ md5($selectedFile) }}" class="w-full h-full">
                                            <x-forms.textarea
                                                id="fileContent"
                                                useMonacoEditor
                                                monacoEditorLanguage="{{ $fileLanguage }}"
                                                wire:model="fileContent"
                                                :readonly="false" />
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </template>
                @endif
            </div>
        @endif
    @endif

    <!-- Create Folder Modal -->
    <div x-data="{ modalOpen: @entangle('showCreateFolder') }"
        x-show="modalOpen"
        x-cloak
        @keydown.escape.window="modalOpen = false; $wire.hideCreateFolderDialog()"
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm"
        style="display: none;">
        <div @click.away="modalOpen = false; $wire.hideCreateFolderDialog()"
            x-transition:enter="ease-out duration-300"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="ease-in duration-200"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
            class="relative w-full max-w-md bg-white dark:bg-base rounded-lg shadow-2xl flex flex-col overflow-hidden border border-coolgray-300 dark:border-coolgray-600">
            <div class="flex items-center justify-between p-4 border-b border-coolgray-300 dark:border-coolgray-600 bg-coolgray-50 dark:bg-coolgray-900">
                <h2 class="text-xl font-bold dark:text-white">Create New Folder</h2>
                <button @click="modalOpen = false; $wire.hideCreateFolderDialog()" class="text-gray-500 hover:text-gray-700 dark:hover:text-gray-300">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            <div class="p-6">
                <x-forms.input id="newFolderName" label="Folder Name" wire:model="newFolderName" placeholder="Enter folder name" />
            </div>
            <div class="flex items-center justify-end gap-2 p-4 border-t border-coolgray-300 dark:border-coolgray-600 bg-coolgray-50 dark:bg-coolgray-900">
                <x-forms.button wire:click="createFolder" class="bg-coollabs">Create</x-forms.button>
                <x-forms.button wire:click="hideCreateFolderDialog" class="bg-gray-600">Cancel</x-forms.button>
            </div>
        </div>
    </div>

    <!-- Rename File Dialog -->
    <div x-data="{ modalOpen: @entangle('showRenameDialog') }"
        x-show="modalOpen"
        x-cloak
        @keydown.escape.window="modalOpen = false; $wire.closeRenameDialog()"
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm"
        style="display: none;">
        <div @click.away="modalOpen = false; $wire.closeRenameDialog()"
            x-transition:enter="ease-out duration-300"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="ease-in duration-200"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
            class="relative w-full max-w-md bg-white dark:bg-base rounded-lg shadow-2xl flex flex-col overflow-hidden border border-coolgray-300 dark:border-coolgray-600">
            <div class="flex items-center justify-between p-4 border-b border-coolgray-300 dark:border-coolgray-600 bg-coolgray-50 dark:bg-coolgray-900">
                <h2 class="text-xl font-bold dark:text-white">Rename File/Folder</h2>
                <button @click="modalOpen = false; $wire.closeRenameDialog()" class="text-gray-500 hover:text-gray-700 dark:hover:text-gray-300">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            <div class="p-6">
                <div class="flex flex-col gap-4">
                    <div>
                        <label class="block text-sm font-medium mb-1 dark:text-white">Current Path</label>
                        <input type="text" value="{{ $renameSource }}" class="w-full p-2 border rounded bg-coolgray-50 dark:bg-coolgray-800" readonly>
                    </div>
                    <x-forms.input id="renameNewName" label="New Name" wire:model.live="renameNewName" placeholder="Enter new name" />
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        Enter only the new name (not the full path). Invalid characters: /, \, null bytes
                    </p>
                </div>
            </div>
            <div class="flex items-center justify-end gap-2 p-4 border-t border-coolgray-300 dark:border-coolgray-600 bg-coolgray-50 dark:bg-coolgray-900">
                <x-forms.button wire:click="renameFile"
                                 class="bg-coollabs"
                                 wire:loading.attr="disabled"
                                 :disabled="empty($renameSource) || empty($renameNewName)">Rename</x-forms.button>
                <x-forms.button wire:click="closeRenameDialog" class="bg-gray-600">Cancel</x-forms.button>
            </div>
        </div>
    </div>

    <!-- Move File Dialog -->
    <div x-data="{ modalOpen: @entangle('showMoveDialog') }"
        x-show="modalOpen"
        x-cloak
        @keydown.escape.window="modalOpen = false; $wire.closeMoveDialog()"
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm"
        style="display: none;">
        <div @click.away="modalOpen = false; $wire.closeMoveDialog()"
            x-transition:enter="ease-out duration-300"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="ease-in duration-200"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
            class="relative w-full max-w-md bg-white dark:bg-base rounded-lg shadow-2xl flex flex-col overflow-hidden border border-coolgray-300 dark:border-coolgray-600">
            <div class="flex items-center justify-between p-4 border-b border-coolgray-300 dark:border-coolgray-600 bg-coolgray-50 dark:bg-coolgray-900">
                <h2 class="text-xl font-bold dark:text-white">Move File</h2>
                <button @click="modalOpen = false; $wire.closeMoveDialog()" class="text-gray-500 hover:text-gray-700 dark:hover:text-gray-300">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            <div class="p-6">
                <div class="flex flex-col gap-4">
                    <div>
                        <label class="block text-sm font-medium mb-1 dark:text-white">Source</label>
                        <input type="text" value="{{ $moveSource }}" class="w-full p-2 border rounded bg-coolgray-50 dark:bg-coolgray-800" readonly>
                    </div>
                    <x-forms.input id="moveDestination" label="Destination Path" wire:model.live="moveDestination" placeholder="/path/to/destination" />
                </div>
            </div>
            <div class="flex items-center justify-end gap-2 p-4 border-t border-coolgray-300 dark:border-coolgray-600 bg-coolgray-50 dark:bg-coolgray-900">
                <x-forms.button wire:click="moveFile"
                                 class="bg-coollabs"
                                 wire:loading.attr="disabled"
                                 :disabled="empty($moveSource) || empty($moveDestination)">Move</x-forms.button>
                <x-forms.button wire:click="closeMoveDialog" class="bg-gray-600">Cancel</x-forms.button>
            </div>
        </div>
    </div>

    <!-- Import Database Dialog -->
    <div x-data="{ modalOpen: @entangle('showImportDatabaseDialog') }"
        x-show="modalOpen"
        x-cloak
        @keydown.escape.window="modalOpen = false; $wire.hideImportDatabaseDialog()"
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm"
        style="display: none;">
        <div @click.away="modalOpen = false; $wire.hideImportDatabaseDialog()"
            x-transition:enter="ease-out duration-300"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="ease-in duration-200"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
            class="relative w-full max-w-lg bg-white dark:bg-base rounded-lg shadow-2xl flex flex-col overflow-hidden border border-coolgray-300 dark:border-coolgray-600">
            <div class="flex items-center justify-between p-4 border-b border-coolgray-300 dark:border-coolgray-600 bg-coolgray-50 dark:bg-coolgray-900">
                <h2 class="text-xl font-bold dark:text-white">Import Database</h2>
                <button @click="modalOpen = false; $wire.hideImportDatabaseDialog()" class="text-gray-500 hover:text-gray-700 dark:hover:text-gray-300">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            <div class="p-6">
                <div class="flex flex-col gap-4">
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        Select a SQL file (.sql) from the current directory to import into the database.
                    </p>
                    @php
                        try {
                            $databaseContainers = $this->getDatabaseContainers();
                        } catch (\Throwable $e) {
                            $databaseContainers = [];
                        }
                    @endphp
                    @if (count($databaseContainers) > 1)
                        <div>
                            <label class="block text-sm font-medium mb-2">Database Container</label>
                            <select wire:model="importDatabaseContainer" class="w-full p-2 border rounded bg-white dark:bg-coolgray-800">
                                <option value="">Use current container ({{ $selected_container }})</option>
                                @foreach ($databaseContainers as $dbContainer)
                                    <option value="{{ $dbContainer['name'] }}">{{ $dbContainer['server'] }} -> {{ $dbContainer['name'] }}</option>
                                @endforeach
                            </select>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                Select the MySQL/MariaDB container to import into. If not selected, will use the current container.
                            </p>
                        </div>
                    @endif
                    <div>
                        <label class="block text-sm font-medium mb-2">Database File</label>
                        <select wire:model="importDatabaseFile" class="w-full p-2 border rounded bg-white dark:bg-coolgray-800">
                            <option value="">Select a file...</option>
                            @foreach ($files as $file)
                                @if (!$file['is_directory'])
                                    @php
                                        $fileName = strtolower($file['name']);
                                        $isSQLFile = str_ends_with($fileName, '.sql') &&
                                                    !str_ends_with($fileName, '.sql.gz') &&
                                                    !str_ends_with($fileName, '.sql.zip');
                                    @endphp
                                    @if ($isSQLFile)
                                        <option value="{{ $file['path'] }}">{{ $file['name'] }}</option>
                                    @endif
                                @endif
                            @endforeach
                        </select>
                    </div>
                    <div class="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded p-3">
                        <p class="text-sm text-yellow-800 dark:text-yellow-200">
                            <strong>Warning:</strong> This will import the selected SQL file into the database. Make sure you have a backup before proceeding.
                        </p>
                    </div>
                </div>
            </div>
            <div class="flex items-center justify-end gap-2 p-4 border-t border-coolgray-300 dark:border-coolgray-600 bg-coolgray-50 dark:bg-coolgray-900">
                <x-forms.button wire:click="importDatabase" class="bg-purple-600">Import</x-forms.button>
                <x-forms.button wire:click="hideImportDatabaseDialog" class="bg-gray-600">Cancel</x-forms.button>
            </div>
        </div>
    </div>

    <!-- Compress Files Dialog -->
    <div x-data="{ modalOpen: @entangle('showCompressDialog') }"
        x-show="modalOpen"
        x-cloak
        @keydown.escape.window="modalOpen = false; $wire.hideCompressDialog()"
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm"
        style="display: none;">
        <div @click.away="modalOpen = false; $wire.hideCompressDialog()"
            x-transition:enter="ease-out duration-300"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="ease-in duration-200"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
            class="relative w-full max-w-lg bg-white dark:bg-base rounded-lg shadow-2xl flex flex-col overflow-hidden border border-coolgray-300 dark:border-coolgray-600">
            <div class="flex items-center justify-between p-4 border-b border-coolgray-300 dark:border-coolgray-600 bg-coolgray-50 dark:bg-coolgray-900">
                <h2 class="text-xl font-bold dark:text-white">Compress Files</h2>
                <button @click="modalOpen = false; $wire.hideCompressDialog()" class="text-gray-500 hover:text-gray-700 dark:hover:text-gray-300">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            <div class="p-6 overflow-y-auto">
                <div class="flex flex-col gap-4">
                    <div>
                        <p class="text-sm text-gray-600 dark:text-gray-400 mb-2">
                            Compressing <strong>{{ count($selectedFiles) }}</strong> item(s):
                        </p>
                        <ul class="list-disc list-inside text-sm text-gray-600 dark:text-gray-400 max-h-32 overflow-y-auto mb-4">
                            @php
                                $filesToShow = [];
                                $selectedFilesSlice = array_slice($selectedFiles ?? [], 0, 10);
                                foreach ($selectedFilesSlice as $selectedPath) {
                                    if (empty($selectedPath)) {
                                        continue;
                                    }
                                    $fileName = basename($selectedPath);
                                    if (isset($files) && is_array($files)) {
                                        foreach ($files as $f) {
                                            if (isset($f['path']) && $f['path'] === $selectedPath) {
                                                $fileName = $f['name'] ?? basename($selectedPath);
                                                break;
                                            }
                                        }
                                    }
                                    if (!empty($fileName)) {
                                        $filesToShow[] = $fileName;
                                    }
                                }
                            @endphp
                            @foreach ($filesToShow as $fileName)
                                <li>{{ $fileName }}</li>
                            @endforeach
                            @if (isset($selectedFiles) && is_array($selectedFiles) && count($selectedFiles) > 10)
                                <li class="text-gray-500">... and {{ count($selectedFiles) - 10 }} more</li>
                            @endif
                        </ul>
                    </div>
                    <x-forms.input id="compressArchiveName" label="Archive Name" wire:model="compressArchiveName" placeholder="archive.zip" />
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        Supported formats: .zip, .tar, .tar.gz, .tar.bz2, .tar.xz, .tgz, .tbz2, .tbz, .txz
                    </p>
                    <div class="flex items-center gap-2">
                        <input type="checkbox" id="overwriteExisting" wire:model="overwriteExisting" class="cursor-pointer">
                        <label for="overwriteExisting" class="text-sm cursor-pointer dark:text-gray-300">Overwrite existing archive if it exists</label>
                    </div>
                </div>
            </div>
            <div class="flex items-center justify-end gap-2 p-4 border-t border-coolgray-300 dark:border-coolgray-600 bg-coolgray-50 dark:bg-coolgray-900">
                <x-forms.button wire:click="compressSelectedFiles" class="bg-green-600">Compress</x-forms.button>
                <x-forms.button wire:click="hideCompressDialog" class="bg-gray-600">Cancel</x-forms.button>
            </div>
        </div>
    </div>

    <!-- Extract Files Dialog -->
    <div x-data="{ modalOpen: @entangle('showExtractDialog') }"
        x-show="modalOpen"
        x-cloak
        @keydown.escape.window="modalOpen = false; $wire.set('showExtractDialog', false)"
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm"
        style="display: none;">
        <div @click.away="modalOpen = false; $wire.set('showExtractDialog', false)"
            x-transition:enter="ease-out duration-300"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="ease-in duration-200"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
            class="relative w-full max-w-lg bg-white dark:bg-base rounded-lg shadow-2xl flex flex-col overflow-hidden border border-coolgray-300 dark:border-coolgray-600">
            <div class="flex items-center justify-between p-4 border-b border-coolgray-300 dark:border-coolgray-600 bg-coolgray-50 dark:bg-coolgray-900">
                <h2 class="text-xl font-bold flex items-center gap-2 dark:text-white">
                    <svg class="w-6 h-6 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                    </svg>
                    Extract Archive
                </h2>
                <button @click="modalOpen = false; $wire.set('showExtractDialog', false)" class="text-gray-500 hover:text-gray-700 dark:hover:text-gray-300">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            <div class="p-6">
                <div class="mb-4 text-sm text-gray-700 dark:text-gray-300">
                    <p>You are about to extract:</p>
                    <p class="font-bold mt-1 text-base max-w-[350px] truncate text-coollabs">{{ $extractArchiveName }}</p>

                    <div class="mt-6 p-4 bg-yellow-50 dark:bg-yellow-900/40 border border-yellow-200 dark:border-yellow-800 rounded flex gap-3 text-yellow-800 dark:text-yellow-300">
                        <svg class="w-6 h-6 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                        <div>
                            <p class="font-bold uppercase text-xs mb-1 tracking-wider opacity-80">Overwrite Warning</p>
                            <p>Any existing files or folders with the same names in the current directory will be overwritten automatically.</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="flex items-center justify-end gap-2 p-4 border-t border-coolgray-300 dark:border-coolgray-600 bg-coolgray-50 dark:bg-coolgray-900">
                <x-forms.button wire:click="$set('showExtractDialog', false)" class="bg-gray-600">Cancel</x-forms.button>
                <x-forms.button wire:click="executeExtraction" class="bg-blue-600">Extract & Overwrite</x-forms.button>
            </div>
        </div>
    </div>

                </div>
            </div>

<script>
    // Evitar múltiples registros del listener
    if (window.phpMyAdminListenerRegistered) {
        // Ya está registrado, no hacer nada
    } else {
        window.phpMyAdminListenerRegistered = true;

        // Definir la URL del endpoint de autologin
        window.phpMyAdminAutologinUrl = @js(route('phpmyadmin.autologin'));
        console.log('[phpMyAdmin] Script loaded, autologin URL:', window.phpMyAdminAutologinUrl);

        // Función para manejar el evento openPhpMyAdmin
        function handleOpenPhpMyAdmin(data) {
            console.log('[phpMyAdmin] Event received:', data);

            if (!data || !data.url) {
                console.error('[phpMyAdmin] Invalid data received:', data);
                return;
            }

            // Si hay datos encriptados, usar el endpoint de autologin
            // Los datos ya están guardados en sesión, así que solo redirigimos
            if (data.encryptedData) {
                console.log('[phpMyAdmin] Using autologin endpoint (data in session)');
                // Los datos ya están en sesión, solo abrir la URL
                window.open(window.phpMyAdminAutologinUrl, '_blank');
                return;
            }

            console.log('[phpMyAdmin] No encrypted data, opening direct URL:', data.url);
            window.open(data.url, '_blank');
        }

        // Registrar listeners de Livewire cuando esté disponible (solo una vez)
        function registerListener() {
            if (window.phpMyAdminListenerRegistered === 'registered') {
                return; // Ya registrado
            }
            window.phpMyAdminListenerRegistered = 'registered';

            Livewire.on('console-log', (data) => {
                console.log('[FileExplorer Debug]', data);
            });

            Livewire.on('openPhpMyAdmin', handleOpenPhpMyAdmin);
            console.log('[phpMyAdmin] Listener registered');
        }

        // Intentar registrar cuando Livewire esté disponible
        if (window.Livewire) {
            registerListener();
        }

        document.addEventListener('livewire:init', registerListener);
        document.addEventListener('livewire:initialized', registerListener);
    }
</script>
