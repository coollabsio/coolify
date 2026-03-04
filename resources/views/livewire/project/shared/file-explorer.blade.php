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
        @if (count($containers) === 0)
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
                                 fileName: ''
                             }"
                             @livewire:upload-progress.window="
                                 console.log('Upload progress:', $event.detail);
                                 if ($event.detail.targetName === 'uploadFile') {
                                     progress = $event.detail.progress;
                                     uploading = true;
                                 }
                             "
                             @livewire:upload-finish.window="
                                 console.log('Upload finish:', $event.detail);
                                 if ($event.detail.targetName === 'uploadFile') {
                                     progress = 100;
                                     setTimeout(() => {
                                         uploading = false;
                                         progress = 0;
                                         fileName = '';
                                         document.getElementById('uploadFileInput').value = '';
                                     }, 1500);
                                 }
                             "
                             @livewire:upload-error.window="
                                 console.log('Upload error:', $event.detail);
                                 if ($event.detail.targetName === 'uploadFile') {
                                     uploading = false;
                                     progress = 0;
                                     fileName = '';
                                 }
                             ">
                            <input type="file" 
                                   id="uploadFileInput" 
                                   wire:model="uploadFile" 
                                   class="hidden" 
                                   wire:loading.attr="disabled"
                                   accept="*"
                                   x-on:change="
                                       if ($event.target.files.length > 0) {
                                           fileName = $event.target.files[0].name;
                                           uploading = true;
                                           progress = 0;
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
                                 class="absolute top-full left-0 right-0 mt-2 w-full min-w-[200px] bg-white dark:bg-coolgray-800 rounded-lg shadow-lg border border-coolgray-300 dark:border-coolgray-600 p-3 z-50">
                                <div class="flex items-center gap-2 mb-2">
                                    <svg class="w-4 h-4 text-coollabs animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                                    </svg>
                                    <span class="text-sm font-medium dark:text-white" x-text="fileName || 'Uploading...'"></span>
                                    <span class="text-xs text-gray-500 dark:text-gray-400 ml-auto" x-text="Math.round(progress) + '%'"></span>
                                </div>
                                <div class="w-full bg-coolgray-200 dark:bg-coolgray-700 rounded-full h-2 overflow-hidden">
                                    <div class="bg-coollabs h-2 rounded-full transition-all duration-300 ease-out" 
                                         :style="'width: ' + progress + '%'"></div>
                                </div>
                            </div>
                            
                            @if ($selected_container === 'default')
                                <div class="absolute top-full left-0 mt-1 text-xs text-red-500 whitespace-nowrap">
                                    Select a container first
                                </div>
                            @endif
                        </div>
                        @if (count($selectedFiles) > 0)
                            <x-forms.button wire:click="openCompressDialog" class="bg-green-600">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                                </svg>
                                Compress ({{ count($selectedFiles) }})
                            </x-forms.button>
                            <x-forms.button wire:click="deselectAll" class="bg-gray-600">
                                Clear Selection
                            </x-forms.button>
                        @else
                            <x-forms.button wire:click="selectAll" class="bg-gray-600">
                                Select All
                            </x-forms.button>
                        @endif
                        @if ($selected_container !== 'default')
                            <x-forms.button wire:click="openDatabasePanel" class="bg-green-600">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"></path>
                                </svg>
                                Database Panel
                            </x-forms.button>
                            <x-forms.button wire:click="openImportDatabaseDialog" class="bg-purple-600">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path>
                                </svg>
                                Import Database
                            </x-forms.button>
                        @endif
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
                    </div>

                    <!-- Breadcrumb Navigation -->
                    <div class="flex items-center gap-2 flex-wrap">
                        <button wire:click="navigateTo('/')" class="text-coollabs hover:underline">
                            /
                        </button>
                        @php
                            $pathParts = array_filter(explode('/', $currentPath));
                            $currentPathParts = [];
                            foreach ($pathParts as $part) {
                                $currentPathParts[] = $part;
                                $path = '/' . implode('/', $currentPathParts);
                        @endphp
                            <span>/</span>
                            <button wire:click="navigateTo('{{ $path }}')" class="text-coollabs hover:underline">
                                {{ $part }}
                            </button>
                        @php
                            }
                        @endphp
                    </div>

                    <!-- File List -->
                    <div class="box-without-bg">
                        @if ($isLoading)
                            <div class="flex items-center justify-center p-8">
                                <x-loading text="Loading files..." />
                            </div>
                        @elseif (count($files) === 0)
                            <div class="p-8 text-center text-gray-500">No files found in this directory.</div>
                        @else
                            <div class="overflow-x-auto">
                                <table class="w-full">
                                    <thead>
                                        <tr class="border-b border-coolgray-300 dark:border-coolgray-600">
                                            <th class="text-left p-2 w-12">
                                                <input type="checkbox" wire:change="selectAll" class="cursor-pointer" title="Select All" {{ count($selectedFiles) === count($files) ? 'checked' : '' }}>
                                            </th>
                                            <th class="text-left p-2">Name</th>
                                            <th class="text-left p-2">Size</th>
                                            <th class="text-left p-2">Permissions</th>
                                            <th class="text-left p-2">Date</th>
                                            <th class="text-right p-2">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($files as $file)
                                            @php
                                                $filePath = data_get($file, 'path', '');
                                                $downloadUrl = !empty($filePath) && !$file['is_directory'] ? $this->getDownloadUrl($filePath) : '#';
                                            @endphp
                                            <tr class="border-b border-coolgray-200 dark:border-coolgray-700 hover:bg-coolgray-50 dark:hover:bg-coolgray-800 {{ in_array($filePath, $selectedFiles) ? 'bg-blue-50 dark:bg-blue-900/20' : '' }}">
                                                <td class="p-2">
                                                    <input type="checkbox" wire:change="toggleFileSelection('{{ $filePath }}')" {{ in_array($filePath, $selectedFiles) ? 'checked' : '' }} class="cursor-pointer">
                                                </td>
                                                <td class="p-2">
                                                    <div class="flex items-center gap-2">
                                                        @if ($file['is_directory'])
                                                            <svg class="w-5 h-5 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"></path>
                                                            </svg>
                                                            <button wire:click="navigateTo('{{ $filePath }}')" class="text-coollabs hover:underline font-medium">
                                                                {{ $file['name'] }}
                                                            </button>
                                                        @else
                                                            <svg class="w-5 h-5 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                                            </svg>
                                                            <button wire:click="openFile('{{ $filePath }}')" class="text-coollabs hover:underline">
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
                                                        @if (!$file['is_directory'] && !empty($filePath))
                                                            <a href="{{ $downloadUrl }}" target="_blank" class="inline-flex items-center justify-center px-2 py-1 text-xs font-medium text-white bg-coollabs rounded hover:bg-coollabs-600" title="Download">
                                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                                                                </svg>
                                                            </a>
                                                            <x-forms.button wire:click="compressFile('{{ $filePath }}')" class="!text-xs !px-2 !py-1" title="Compress">
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
                                                            <x-forms.button wire:click="decompressFile('{{ $filePath }}')" class="!text-xs !px-2 !py-1" title="Decompress">
                                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                                                                </svg>
                                                            </x-forms.button>
                                                        @endif
                                                        <x-forms.button wire:click="openMoveDialog('{{ $filePath }}')" class="!text-xs !px-2 !py-1" title="Move">
                                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path>
                                                            </svg>
                                                        </x-forms.button>
                                                        <x-modal-confirmation title="Delete File?" buttonTitle="Delete" submitAction="deleteFile('{{ $filePath }}')" :confirmWithText="false" :confirmWithPassword="false" step1ButtonText="Delete" step2ButtonText="Confirm">
                                                            <x-slot:button-title>
                                                                <svg class="w-4 h-4 text-error" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                                                </svg>
                                                            </x-slot:button-title>
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
                            $fileLanguage = $this->getFileLanguage($selectedFile);
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
                                            <h3 class="text-lg font-semibold">{{ basename($selectedFile) }}</h3>
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
    @if ($showImportDatabaseDialog)
        <x-modal wire:model="showImportDatabaseDialog">
            <x-slot:title>Import Database</x-slot:title>
            <x-slot:content>
                <div class="flex flex-col gap-4">
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        Select a SQL file (.sql, .sql.gz, .sql.zip) from the current directory to import into the database.
                    </p>
                    @php
                        $databaseContainers = $this->getDatabaseContainers();
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
                                        $isSQLFile = str_ends_with($fileName, '.sql') || 
                                                    str_ends_with($fileName, '.sql.gz') || 
                                                    str_ends_with($fileName, '.sql.zip');
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
            </x-slot:content>
            <x-slot:footer>
                <x-forms.button wire:click="importDatabase" class="bg-purple-600">Import</x-forms.button>
                <x-forms.button wire:click="hideImportDatabaseDialog" class="bg-gray-600">Cancel</x-forms.button>
            </x-slot:footer>
        </x-modal>
    @endif

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
                            @foreach (array_slice($selectedFiles, 0, 10) as $selectedPath)
                                @php
                                    $file = collect($files)->firstWhere('path', $selectedPath);
                                @endphp
                                <li>{{ $file['name'] ?? basename($selectedPath) }}</li>
                            @endforeach
                            @if (count($selectedFiles) > 10)
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

    <!-- Database Panel Modal -->
    <div x-data="{ modalOpen: @entangle('showDatabasePanel') }"
        x-show="modalOpen"
        x-cloak
        x-transition
        @keydown.escape.window="modalOpen = false; $wire.closeDatabasePanel()"
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm"
        style="display: none;">
        <div @click.away="modalOpen = false; $wire.closeDatabasePanel()"
            x-transition:enter="ease-out duration-300"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="ease-in duration-200"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
            class="relative w-full max-w-7xl max-h-[90vh] bg-white dark:bg-base rounded-lg shadow-2xl flex flex-col overflow-hidden border border-coolgray-300 dark:border-coolgray-600">
            <!-- Header -->
            <div class="flex items-center justify-between p-4 border-b border-coolgray-300 dark:border-coolgray-600 bg-coolgray-50 dark:bg-coolgray-900">
                <div class="flex items-center gap-3">
                    <svg class="w-6 h-6 text-coollabs" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"></path>
                    </svg>
                    <h2 class="text-xl font-bold dark:text-white">Database Panel</h2>
                    @if ($selectedDatabase)
                        <span class="text-sm text-gray-500 dark:text-gray-400">› {{ $selectedDatabase }}</span>
                    @endif
                    @if ($selectedTable)
                        <span class="text-sm text-gray-500 dark:text-gray-400">› {{ $selectedTable }}</span>
                    @endif
                </div>
                <x-forms.button wire:click="closeDatabasePanel" class="bg-gray-600">Close</x-forms.button>
            </div>
            <!-- Content -->
            <div class="flex-1 overflow-hidden flex flex-col">
                @if ($adminerUrl)
                    <iframe src="{{ $adminerUrl }}" class="w-full h-full border-0" style="min-height: 600px;" allow="clipboard-read; clipboard-write"></iframe>
                @else
                    <div class="flex-1 overflow-y-auto p-6">
                        <div class="flex flex-col gap-6">
                    <!-- Databases List -->
                    <div class="box-without-bg">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-semibold dark:text-white flex items-center gap-2">
                                <svg class="w-5 h-5 text-coollabs" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"></path>
                                </svg>
                                Databases
                            </h3>
                            @if (!empty($databases))
                                <span class="text-sm text-gray-500 dark:text-gray-400">{{ count($databases) }} database(s)</span>
                            @endif
                        </div>
                        @if (empty($databases))
                            <div class="text-center py-12">
                                <x-loading wire:loading text="Loading databases..." />
                                <p wire:loading.remove class="text-gray-500 dark:text-gray-400">No databases found.</p>
                            </div>
                        @else
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                                @foreach ($databases as $database)
                                    <button
                                        wire:click="selectDatabase('{{ $database }}')"
                                        class="coolbox p-4 text-left transition-all hover:scale-[1.02] {{ $selectedDatabase === $database ? 'ring-2 ring-coollabs bg-coollabs-50 dark:bg-coollabs-900/20' : '' }}"
                                    >
                                        <div class="flex items-center gap-3">
                                            <div class="flex-shrink-0 w-10 h-10 rounded-lg bg-coollabs-100 dark:bg-coollabs-900 flex items-center justify-center">
                                                <svg class="w-6 h-6 text-coollabs" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"></path>
                                                </svg>
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <div class="font-semibold dark:text-white truncate">{{ $database }}</div>
                                            </div>
                                            @if ($selectedDatabase === $database)
                                                <svg class="w-5 h-5 text-coollabs flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                                </svg>
                                            @endif
                                        </div>
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <!-- Tables List -->
                    @if (!empty($selectedDatabase))
                        <div class="box-without-bg">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-lg font-semibold dark:text-white flex items-center gap-2">
                                    <svg class="w-5 h-5 text-coollabs" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                                    </svg>
                                    Tables in <span class="text-coollabs">{{ $selectedDatabase }}</span>
                                </h3>
                                @if (!empty($tables))
                                    <span class="text-sm text-gray-500 dark:text-gray-400">{{ count($tables) }} table(s)</span>
                                @endif
                            </div>
                            @if (empty($tables))
                                <div class="text-center py-8">
                                    <x-loading wire:loading text="Loading tables..." />
                                    <p wire:loading.remove class="text-gray-500 dark:text-gray-400">No tables found.</p>
                                </div>
                            @else
                                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-2">
                                    @foreach ($tables as $table)
                                        <button
                                            wire:click="selectTable('{{ $table }}')"
                                            class="p-3 border border-coolgray-300 dark:border-coolgray-600 rounded-lg hover:bg-coolgray-50 dark:hover:bg-coolgray-800 text-left transition-all {{ $selectedTable === $table ? 'bg-coollabs-50 dark:bg-coollabs-900/20 border-coollabs ring-1 ring-coollabs' : '' }}"
                                        >
                                            <div class="text-sm font-medium dark:text-white truncate">{{ $table }}</div>
                                        </button>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endif

                    <!-- Table Structure and Data -->
                    @if (!empty($selectedTable))
                        <div class="box-without-bg">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-lg font-semibold dark:text-white flex items-center gap-2">
                                    <svg class="w-5 h-5 text-coollabs" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                    </svg>
                                    Table: <span class="text-coollabs">{{ $selectedTable }}</span>
                                </h3>
                            </div>

                            <!-- Tabs -->
                            <div class="flex gap-2 mb-4 border-b border-coolgray-300 dark:border-coolgray-600">
                                <button class="px-4 py-2 text-sm font-medium border-b-2 border-coollabs text-coollabs">Structure</button>
                                <button class="px-4 py-2 text-sm font-medium text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300">Data</button>
                            </div>

                            <!-- Structure Tab -->
                            <div class="mb-6">
                                @if (empty($tableStructure))
                                    <div class="text-center py-8">
                                        <x-loading wire:loading text="Loading structure..." />
                                        <p wire:loading.remove class="text-gray-500 dark:text-gray-400">No structure data available.</p>
                                    </div>
                                @else
                                    <div class="overflow-x-auto">
                                        <table class="w-full">
                                            <thead>
                                                <tr class="border-b border-coolgray-300 dark:border-coolgray-600">
                                                    <th class="text-left p-3 text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Field</th>
                                                    <th class="text-left p-3 text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Type</th>
                                                    <th class="text-left p-3 text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Null</th>
                                                    <th class="text-left p-3 text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Key</th>
                                                    <th class="text-left p-3 text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Default</th>
                                                    <th class="text-left p-3 text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Extra</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach ($tableStructure as $column)
                                                    <tr class="border-b border-coolgray-200 dark:border-coolgray-700 hover:bg-coolgray-50 dark:hover:bg-coolgray-800">
                                                        <td class="p-3 font-medium dark:text-white">{{ $column['field'] }}</td>
                                                        <td class="p-3 text-sm dark:text-gray-300">{{ $column['type'] }}</td>
                                                        <td class="p-3 text-sm dark:text-gray-300">{{ $column['null'] }}</td>
                                                        <td class="p-3 text-sm dark:text-gray-300">
                                                            @if (!empty($column['key']))
                                                                <span class="px-2 py-1 text-xs rounded bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-300">{{ $column['key'] }}</span>
                                                            @else
                                                                <span class="text-gray-400">—</span>
                                                            @endif
                                                        </td>
                                                        <td class="p-3 text-sm dark:text-gray-300">{{ $column['default'] ?? '<span class="text-gray-400">NULL</span>' }}</td>
                                                        <td class="p-3 text-sm dark:text-gray-300">{{ $column['extra'] ?: '—' }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @endif
                            </div>

                            <!-- Data Tab -->
                            <div>
                                <div class="flex items-center justify-between mb-4">
                                    <h4 class="font-semibold dark:text-white">Data</h4>
                                    <div class="flex items-center gap-2">
                                        <x-forms.button wire:click="previousPage" :disabled="$currentPage <= 1" class="bg-gray-600 text-xs py-1.5 px-3">
                                            <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                                            </svg>
                                            Previous
                                        </x-forms.button>
                                        <span class="text-sm text-gray-500 dark:text-gray-400 px-3">Page {{ $currentPage }}</span>
                                        <x-forms.button wire:click="nextPage" class="bg-gray-600 text-xs py-1.5 px-3">
                                            Next
                                            <svg class="w-4 h-4 inline ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                            </svg>
                                        </x-forms.button>
                                    </div>
                                </div>
                                @if (empty($tableData))
                                    <div class="text-center py-8">
                                        <x-loading wire:loading text="Loading data..." />
                                        <p wire:loading.remove class="text-gray-500 dark:text-gray-400">No data available.</p>
                                    </div>
                                @elseif (count($tableData) === 0)
                                    <div class="text-center py-8">
                                        <p class="text-gray-500 dark:text-gray-400">No data found in this table.</p>
                                    </div>
                                @else
                                    <div class="overflow-x-auto border border-coolgray-300 dark:border-coolgray-600 rounded-lg">
                                        <div id="tableDataContainer" wire:ignore></div>
                                    </div>
                                    <div class="flex items-center justify-between mt-3 text-xs text-gray-500 dark:text-gray-400">
                                        <span>Showing {{ count($tableData) }} row(s) on page {{ $currentPage }}</span>
                                        <span>{{ $this->perPage }} rows per page</span>
                                    </div>
                                    <script>
                                        document.addEventListener('livewire:init', () => {
                                            Livewire.hook('morph.updated', ({ el, component }) => {
                                                if (component.name === 'project.shared.file-explorer' && @js($selectedTable)) {
                                                    initTableData();
                                                }
                                            });
                                        });
                                        
                                        function initTableData() {
                                            const container = document.getElementById('tableDataContainer');
                                            if (!container) return;
                                            
                                            const tableData = @js($tableData);
                                            if (!tableData || tableData.length === 0) return;
                                            
                                            // Create table with better styling
                                            let html = '<table class="w-full">';
                                            html += '<thead><tr class="bg-coolgray-50 dark:bg-coolgray-900 border-b border-coolgray-300 dark:border-coolgray-600">';
                                            Object.keys(tableData[0]).forEach(header => {
                                                html += `<th class="text-left p-3 text-xs font-medium uppercase text-gray-500 dark:text-gray-400 sticky top-0 bg-coolgray-50 dark:bg-coolgray-900 z-10">${header}</th>`;
                                            });
                                            html += '</tr></thead><tbody>';
                                            
                                            tableData.forEach(row => {
                                                html += '<tr class="border-b border-coolgray-200 dark:border-coolgray-700 hover:bg-coolgray-50 dark:hover:bg-coolgray-800">';
                                                Object.values(row).forEach(value => {
                                                    const displayValue = value ?? '<span class="text-gray-400">NULL</span>';
                                                    html += `<td class="p-3 text-sm dark:text-gray-300"><div class="max-w-xs truncate" title="${value}">${displayValue}</div></td>`;
                                                });
                                                html += '</tr>';
                                            });
                                            html += '</tbody></table>';
                                            
                                            container.innerHTML = html;
                                        }
                                        
                                        // Initialize on page load
                                        if (document.readyState === 'loading') {
                                            document.addEventListener('DOMContentLoaded', initTableData);
                                        } else {
                                            initTableData();
                                        }
                                    </script>
                                @endif
                            </div>
                        </div>
                    @endif
                </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
