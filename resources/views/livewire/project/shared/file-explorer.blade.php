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
                        <label class="cursor-pointer">
                            <x-forms.button type="button" class="bg-coollabs">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
                                </svg>
                                Upload File
                            </x-forms.button>
                            <input type="file" wire:model="uploadFile" class="hidden">
                        </label>
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
                                            <tr class="border-b border-coolgray-200 dark:border-coolgray-700 hover:bg-coolgray-50 dark:hover:bg-coolgray-800 {{ in_array($file['path'], $selectedFiles) ? 'bg-blue-50 dark:bg-blue-900/20' : '' }}">
                                                <td class="p-2">
                                                    <input type="checkbox" wire:change="toggleFileSelection('{{ $file['path'] }}')" {{ in_array($file['path'], $selectedFiles) ? 'checked' : '' }} class="cursor-pointer">
                                                </td>
                                                <td class="p-2">
                                                    <div class="flex items-center gap-2">
                                                        @if ($file['is_directory'])
                                                            <svg class="w-5 h-5 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"></path>
                                                            </svg>
                                                            <button wire:click="navigateTo('{{ $file['path'] }}')" class="text-coollabs hover:underline font-medium">
                                                                {{ $file['name'] }}
                                                            </button>
                                                        @else
                                                            <svg class="w-5 h-5 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                                            </svg>
                                                            <button wire:click="openFile('{{ $file['path'] }}')" class="text-coollabs hover:underline">
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
                                                            <a href="{{ $this->getDownloadUrl($file['path']) }}" target="_blank" class="inline-flex items-center justify-center px-2 py-1 text-xs font-medium text-white bg-coollabs rounded hover:bg-coollabs-600" title="Download">
                                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                                                                </svg>
                                                            </a>
                                                            <x-forms.button wire:click="compressFile('{{ $file['path'] }}')" class="!text-xs !px-2 !py-1" title="Compress">
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
                                                            <x-forms.button wire:click="decompressFile('{{ $file['path'] }}')" class="!text-xs !px-2 !py-1" title="Decompress">
                                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                                                                </svg>
                                                            </x-forms.button>
                                                        @endif
                                                        <x-forms.button wire:click="openMoveDialog('{{ $file['path'] }}')" class="!text-xs !px-2 !py-1" title="Move">
                                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path>
                                                            </svg>
                                                        </x-forms.button>
                                                        <x-modal-confirmation title="Delete File?" buttonTitle="Delete" submitAction="deleteFile('{{ $file['path'] }}')" :confirmWithText="false" :confirmWithPassword="false" step1ButtonText="Delete" step2ButtonText="Confirm">
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
                                                {{ $this->getFileLanguage($selectedFile) }}
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
                                                monacoEditorLanguage="{{ $this->getFileLanguage($selectedFile) }}"
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
    @if ($showCreateFolder)
        <x-modal wire:model="showCreateFolder">
            <x-slot:title>Create New Folder</x-slot:title>
            <x-slot:content>
                <x-forms.input id="newFolderName" label="Folder Name" wire:model="newFolderName" placeholder="Enter folder name" />
            </x-slot:content>
            <x-slot:footer>
                <x-forms.button wire:click="createFolder" class="bg-coollabs">Create</x-forms.button>
                <x-forms.button wire:click="hideCreateFolderDialog" class="bg-gray-600">Cancel</x-forms.button>
            </x-slot:footer>
        </x-modal>
    @endif

    <!-- Move File Dialog -->
    @if ($showMoveDialog)
        <x-modal wire:model="showMoveDialog">
            <x-slot:title>Move File</x-slot:title>
            <x-slot:content>
                <div class="flex flex-col gap-4">
                    <div>
                        <label class="block text-sm font-medium mb-1">Source</label>
                        <input type="text" value="{{ $moveSource }}" class="w-full p-2 border rounded bg-coolgray-50 dark:bg-coolgray-800" readonly>
                    </div>
                    <x-forms.input id="moveDestination" label="Destination Path" wire:model="moveDestination" placeholder="/path/to/destination" />
                </div>
            </x-slot:content>
            <x-slot:footer>
                <x-forms.button wire:click="moveFile('{{ $moveSource }}', $moveDestination)" class="bg-coollabs">Move</x-forms.button>
                <x-forms.button wire:click="closeMoveDialog" class="bg-gray-600">Cancel</x-forms.button>
            </x-slot:footer>
        </x-modal>
    @endif

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
    @if ($showCompressDialog)
        <x-modal wire:model="showCompressDialog">
            <x-slot:title>Compress Files</x-slot:title>
            <x-slot:content>
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
                        <label for="overwriteExisting" class="text-sm cursor-pointer">Overwrite existing archive if it exists</label>
                    </div>
                </div>
            </x-slot:content>
            <x-slot:footer>
                <x-forms.button wire:click="compressSelectedFiles" class="bg-green-600">Compress</x-forms.button>
                <x-forms.button wire:click="hideCompressDialog" class="bg-gray-600">Cancel</x-forms.button>
            </x-slot:footer>
        </x-modal>
    @endif

    <!-- Database Panel Modal -->
    <div x-data="{ modalOpen: @entangle('showDatabasePanel') }"
        x-show="modalOpen"
        x-cloak
        @keydown.escape.window="modalOpen = false; $wire.closeDatabasePanel()"
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm"
        style="display: none;">
        <div @click.away="modalOpen = false; $wire.closeDatabasePanel()"
            class="relative w-full max-w-7xl max-h-[90vh] bg-white dark:bg-coolgray-800 rounded-lg shadow-xl flex flex-col overflow-hidden">
            <!-- Header -->
            <div class="flex items-center justify-between p-6 border-b border-coolgray-300 dark:border-coolgray-600">
                <h2 class="text-xl font-semibold">Database Panel</h2>
                <x-forms.button wire:click="closeDatabasePanel" class="bg-gray-600">Close</x-forms.button>
            </div>
            <!-- Content -->
            <div class="flex-1 overflow-y-auto p-6">
                <div class="flex flex-col gap-4 h-[70vh]">
                    <!-- Databases List -->
                    <div class="border rounded-lg p-4 bg-white dark:bg-coolgray-800">
                        <h3 class="text-lg font-semibold mb-3">Databases</h3>
                        @if (empty($databases))
                            <div class="text-center py-8 text-gray-500">
                                <p wire:loading.remove>No databases found or loading...</p>
                                <p wire:loading>Loading databases...</p>
                            </div>
                        @else
                            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-2">
                                @foreach ($databases as $database)
                                    <button
                                        wire:click="selectDatabase('{{ $database }}')"
                                        class="p-3 border rounded hover:bg-coollabs-100 dark:hover:bg-coolgray-700 text-left transition-colors {{ $selectedDatabase === $database ? 'bg-coollabs-200 dark:bg-coolgray-600 border-coollabs' : '' }}"
                                    >
                                        <div class="font-medium">{{ $database }}</div>
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <!-- Tables List -->
                    @if (!empty($selectedDatabase))
                        <div class="border rounded-lg p-4 bg-white dark:bg-coolgray-800">
                            <h3 class="text-lg font-semibold mb-3">
                                Tables in <span class="text-coollabs">{{ $selectedDatabase }}</span>
                            </h3>
                            @if (empty($tables))
                                <div class="text-center py-4 text-gray-500">
                                    <p wire:loading.remove>No tables found or loading...</p>
                                    <p wire:loading>Loading tables...</p>
                                </div>
                            @else
                                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-2">
                                    @foreach ($tables as $table)
                                        <button
                                            wire:click="selectTable('{{ $table }}')"
                                            class="p-2 border rounded hover:bg-coollabs-100 dark:hover:bg-coolgray-700 text-left transition-colors {{ $selectedTable === $table ? 'bg-coollabs-200 dark:bg-coolgray-600 border-coollabs' : '' }}"
                                        >
                                            <div class="text-sm font-medium">{{ $table }}</div>
                                        </button>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endif

                    <!-- Table Structure and Data -->
                    @if (!empty($selectedTable))
                        <div class="flex-1 overflow-auto border rounded-lg p-4 bg-white dark:bg-coolgray-800">
                            <h3 class="text-lg font-semibold mb-3">
                                Table: <span class="text-coollabs">{{ $selectedTable }}</span>
                            </h3>

                            <!-- Structure Tab -->
                            <div class="mb-4">
                                <h4 class="font-semibold mb-2">Structure</h4>
                                @if (empty($tableStructure))
                                    <p class="text-gray-500">Loading structure...</p>
                                @else
                                    <div class="overflow-x-auto">
                                        <table class="w-full text-sm border-collapse">
                                            <thead>
                                                <tr class="bg-gray-100 dark:bg-coolgray-700">
                                                    <th class="border p-2 text-left">Field</th>
                                                    <th class="border p-2 text-left">Type</th>
                                                    <th class="border p-2 text-left">Null</th>
                                                    <th class="border p-2 text-left">Key</th>
                                                    <th class="border p-2 text-left">Default</th>
                                                    <th class="border p-2 text-left">Extra</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach ($tableStructure as $column)
                                                    <tr class="hover:bg-gray-50 dark:hover:bg-coolgray-700">
                                                        <td class="border p-2 font-medium">{{ $column['field'] }}</td>
                                                        <td class="border p-2">{{ $column['type'] }}</td>
                                                        <td class="border p-2">{{ $column['null'] }}</td>
                                                        <td class="border p-2">{{ $column['key'] }}</td>
                                                        <td class="border p-2">{{ $column['default'] ?? 'NULL' }}</td>
                                                        <td class="border p-2">{{ $column['extra'] }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @endif
                            </div>

                            <!-- Data Tab -->
                            <div>
                                <div class="flex items-center justify-between mb-2">
                                    <h4 class="font-semibold">Data</h4>
                                    <div class="flex items-center gap-2">
                                        <x-forms.button wire:click="previousPage" :disabled="$currentPage <= 1" class="bg-gray-600 text-xs py-1 px-2">
                                            Previous
                                        </x-forms.button>
                                        <span class="text-sm">Page {{ $currentPage }}</span>
                                        <x-forms.button wire:click="nextPage" class="bg-gray-600 text-xs py-1 px-2">
                                            Next
                                        </x-forms.button>
                                    </div>
                                </div>
                                @if (empty($tableData))
                                    <p class="text-gray-500">Loading data...</p>
                                @elseif (count($tableData) === 0)
                                    <p class="text-gray-500">No data found in this table.</p>
                                @else
                                    <div class="overflow-x-auto">
                                        <table class="w-full text-sm border-collapse">
                                            <thead>
                                                <tr class="bg-gray-100 dark:bg-coolgray-700">
                                                    @if (!empty($tableData))
                                                        @foreach (array_keys($tableData[0]) as $header)
                                                            <th class="border p-2 text-left">{{ $header }}</th>
                                                        @endforeach
                                                    @endif
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach ($tableData as $row)
                                                    <tr class="hover:bg-gray-50 dark:hover:bg-coolgray-700">
                                                        @foreach ($row as $value)
                                                            <td class="border p-2">
                                                                <div class="max-w-xs truncate" title="{{ $value }}">
                                                                    {{ $value ?? 'NULL' }}
                                                                </div>
                                                            </td>
                                                        @endforeach
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                    <p class="text-xs text-gray-500 mt-2">
                                        Showing {{ count($tableData) }} rows (Page {{ $currentPage }})
                                    </p>
                                @endif
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
