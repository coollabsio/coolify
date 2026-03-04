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
                        <x-forms.button wire:click="$set('showCreateFolder', true)" class="bg-coollabs">
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
                                                        <x-forms.button wire:click="$set('moveSource', '{{ $file['path'] }}'); $set('showMoveDialog', true)" class="!text-xs !px-2 !py-1" title="Move">
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

                    <!-- File Editor -->
                    @if ($selectedFile)
                        <div class="box-without-bg">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-lg font-semibold">{{ basename($selectedFile) }}</h3>
                                <div class="flex gap-2">
                                    @if (!$isEditing)
                                        <x-forms.button wire:click="$set('isEditing', true)" class="bg-coollabs">
                                            Edit
                                        </x-forms.button>
                                    @else
                                        <x-forms.button wire:click="saveFile" class="bg-green-600">
                                            Save
                                        </x-forms.button>
                                        <x-forms.button wire:click="$set('isEditing', false); loadFileContent('{{ $selectedFile }}')" class="bg-gray-600">
                                            Cancel
                                        </x-forms.button>
                                    @endif
                                    <x-forms.button wire:click="$set('selectedFile', null); $set('fileContent', null); $set('isEditing', false)" class="bg-gray-600">
                                        Close
                                    </x-forms.button>
                                </div>
                            </div>
                            @if ($isEditing)
                                <textarea wire:model="fileContent" class="w-full h-96 p-4 font-mono text-sm border border-coolgray-300 dark:border-coolgray-600 rounded bg-white dark:bg-coolgray-800"></textarea>
                            @else
                                <pre class="w-full h-96 p-4 overflow-auto font-mono text-sm border border-coolgray-300 dark:border-coolgray-600 rounded bg-white dark:bg-coolgray-800 whitespace-pre-wrap">{{ $fileContent }}</pre>
                            @endif
                        </div>
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
                <x-forms.button wire:click="$set('showCreateFolder', false); $set('newFolderName', null)" class="bg-gray-600">Cancel</x-forms.button>
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
                <x-forms.button wire:click="$set('showMoveDialog', false); $set('moveSource', null); $set('moveDestination', null)" class="bg-gray-600">Cancel</x-forms.button>
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
                <x-forms.button wire:click="$set('showCompressDialog', false); $set('compressArchiveName', null); $set('overwriteExisting', false)" class="bg-gray-600">Cancel</x-forms.button>
            </x-slot:footer>
        </x-modal>
    @endif
</div>
