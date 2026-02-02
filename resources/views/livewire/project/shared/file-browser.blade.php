<div>
    <x-slot:title>
        {{ data_get_str($resource, 'name')->limit(10) }} > Files | Coolify
    </x-slot>
    @if ($type === 'application')
        <livewire:project.shared.configuration-checker :resource="$resource" />
        <h1>File Browser</h1>
        <livewire:project.application.heading :application="$resource" />
    @elseif ($type === 'database')
        <livewire:project.shared.configuration-checker :resource="$resource" />
        <h1>File Browser</h1>
        <livewire:project.database.heading :database="$resource" />
    @elseif ($type === 'service')
        <livewire:project.shared.configuration-checker :resource="$resource" />
        <livewire:project.service.heading :service="$resource" :parameters="$parameters" title="File Browser" />
    @endif

    <div x-data="{
        confirmDelete: null,
        confirmDeleteType: null,
        showNewFolder: @entangle('showNewFolderModal'),
        handleDownload(name, path) {
            // Create a temporary link to download
            window.open('/file-browser/download?path=' + encodeURIComponent(path) + '&name=' + encodeURIComponent(name), '_blank');
        }
    }"
    @trigger-download.window="handleDownload($event.detail.name, $event.detail.path)">

        {{-- Container Selection --}}
        <div class="pb-4">
            <h2 class="pb-4">File Browser</h2>
            @if (count($containers) === 0)
                <div>No containers are running.</div>
            @else
                <div class="flex gap-2 items-end w-96 min-w-fit">
                    <x-forms.select label="Container" wire:model.live="selectedContainer">
                        @if ($containers->count() > 1)
                            <option disabled value="default">Select a container</option>
                        @endif
                        @foreach ($containers as $container)
                            <option value="{{ data_get($container, 'container.Names') }}">
                                {{ data_get($container, 'container.Names') }}
                                ({{ data_get($container, 'server.name') }})
                            </option>
                        @endforeach
                    </x-forms.select>
                </div>
            @endif
        </div>

        @if ($selectedContainer !== 'default')
            {{-- Toolbar --}}
            <div class="flex flex-wrap gap-2 items-center pb-3">
                {{-- Breadcrumb Navigation --}}
                <div class="flex items-center gap-1 text-sm flex-wrap flex-1 min-w-0">
                    @foreach ($breadcrumbs as $index => $crumb)
                        @if ($index > 0)
                            <span class="dark:text-neutral-500 text-neutral-400">/</span>
                        @endif
                        @if ($loop->last)
                            <span class="font-semibold dark:text-white text-neutral-900">{{ $crumb['name'] }}</span>
                        @else
                            <button wire:click="navigateToPath('{{ $crumb['path'] }}')"
                                class="dark:text-neutral-400 text-neutral-500 hover:dark:text-white hover:text-neutral-900 transition-colors">
                                {{ $crumb['name'] }}
                            </button>
                        @endif
                    @endforeach
                </div>

                <div class="flex gap-2 items-center shrink-0">
                    {{-- Navigate Up --}}
                    @if ($currentPath !== '/')
                        <x-forms.button wire:click="navigateUp" isSmall>
                            <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" />
                            </svg>
                            Up
                        </x-forms.button>
                    @endif

                    {{-- Refresh --}}
                    <x-forms.button wire:click="refreshDirectory" isSmall>
                        <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182" />
                        </svg>
                        Refresh
                    </x-forms.button>

                    {{-- New Folder --}}
                    <x-forms.button wire:click="$set('showNewFolderModal', true)" isSmall>
                        <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 10.5v6m3-3H9m4.06-7.19-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z" />
                        </svg>
                        New Folder
                    </x-forms.button>

                    {{-- Upload --}}
                    <label class="cursor-pointer">
                        <x-forms.button isSmall onclick="document.getElementById('file-upload-input').click()">
                            <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" />
                            </svg>
                            Upload
                        </x-forms.button>
                        <input id="file-upload-input" type="file" wire:model="uploadFile" class="hidden" />
                    </label>
                </div>
            </div>

            {{-- Loading indicator --}}
            <div wire:loading wire:target="listDirectory,navigateTo,navigateUp,navigateToPath,refreshDirectory,deleteEntry,createFolder,uploadFileToContainer"
                class="flex items-center gap-2 py-2 text-sm dark:text-neutral-400">
                <svg class="w-4 h-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                Loading...
            </div>

            {{-- Upload progress --}}
            <div wire:loading wire:target="uploadFile" class="flex items-center gap-2 py-2 text-sm dark:text-neutral-400">
                <svg class="w-4 h-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                Uploading file...
            </div>

            {{-- Error message --}}
            @if ($errorMessage)
                <div class="p-3 mb-3 text-sm rounded-lg dark:bg-red-900/20 bg-red-50 dark:text-red-400 text-red-600 border dark:border-red-800 border-red-200">
                    {{ $errorMessage }}
                </div>
            @endif

            {{-- New Folder Modal --}}
            @if ($showNewFolderModal)
                <div class="p-4 mb-3 rounded-lg dark:bg-coolgray-100 bg-white border dark:border-coolgray-200 border-neutral-200">
                    <form wire:submit="createFolder" class="flex gap-2 items-end">
                        <x-forms.input id="newFolderName" label="Folder Name" placeholder="new-folder" required />
                        <x-forms.button type="submit" isSmall>Create</x-forms.button>
                        <x-forms.button wire:click="$set('showNewFolderModal', false)" isSmall>Cancel</x-forms.button>
                    </form>
                </div>
            @endif

            {{-- File Table --}}
            @if (count($entries) > 0)
                <div class="overflow-x-auto rounded-lg border dark:border-coolgray-200 border-neutral-200">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="dark:bg-coolgray-100 bg-neutral-50 border-b dark:border-coolgray-200 border-neutral-200">
                                <th class="px-4 py-2 text-left cursor-pointer select-none" wire:click="sortBy('name')">
                                    <div class="flex items-center gap-1">
                                        Name
                                        @if ($sortBy === 'name')
                                            <span>{{ $sortDirection === 'asc' ? '↑' : '↓' }}</span>
                                        @endif
                                    </div>
                                </th>
                                <th class="px-4 py-2 text-left cursor-pointer select-none" wire:click="sortBy('size')">
                                    <div class="flex items-center gap-1">
                                        Size
                                        @if ($sortBy === 'size')
                                            <span>{{ $sortDirection === 'asc' ? '↑' : '↓' }}</span>
                                        @endif
                                    </div>
                                </th>
                                <th class="px-4 py-2 text-left">Permissions</th>
                                <th class="px-4 py-2 text-left">Owner</th>
                                <th class="px-4 py-2 text-left cursor-pointer select-none" wire:click="sortBy('modified')">
                                    <div class="flex items-center gap-1">
                                        Modified
                                        @if ($sortBy === 'modified')
                                            <span>{{ $sortDirection === 'asc' ? '↑' : '↓' }}</span>
                                        @endif
                                    </div>
                                </th>
                                <th class="px-4 py-2 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($entries as $entry)
                                <tr class="border-b dark:border-coolgray-200 border-neutral-200 last:border-0 dark:hover:bg-coolgray-100/50 hover:bg-neutral-50 transition-colors">
                                    {{-- Name --}}
                                    <td class="px-4 py-2">
                                        <div class="flex items-center gap-2">
                                            @if ($entry['type'] === 'directory')
                                                <svg class="w-5 h-5 dark:text-yellow-400 text-yellow-600 shrink-0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                                                    <path d="M19.5 21a3 3 0 0 0 3-3v-4.5a3 3 0 0 0-3-3h-15a3 3 0 0 0-3 3V18a3 3 0 0 0 3 3h15ZM1.5 10.146V6a3 3 0 0 1 3-3h5.379a2.25 2.25 0 0 1 1.59.659l2.122 2.121c.14.141.331.22.53.22H19.5a3 3 0 0 1 3 3v1.146A4.483 4.483 0 0 0 19.5 9h-15a4.483 4.483 0 0 0-3 1.146Z" />
                                                </svg>
                                            @elseif ($entry['type'] === 'symlink')
                                                <svg class="w-5 h-5 dark:text-blue-400 text-blue-600 shrink-0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                                                    <path fill-rule="evenodd" d="M19.902 4.098a3.75 3.75 0 0 0-5.304 0l-4.5 4.5a3.75 3.75 0 0 0 1.035 6.037.75.75 0 0 1-.646 1.353 5.25 5.25 0 0 1-1.449-8.45l4.5-4.5a5.25 5.25 0 1 1 7.424 7.424l-1.757 1.757a.75.75 0 1 1-1.06-1.06l1.757-1.758a3.75 3.75 0 0 0 0-5.304Zm-7.389 4.267a.75.75 0 0 1 1-.353 5.25 5.25 0 0 1 1.449 8.45l-4.5 4.5a5.25 5.25 0 1 1-7.424-7.424l1.757-1.757a.75.75 0 0 1 1.06 1.06l-1.757 1.758a3.75 3.75 0 1 0 5.304 5.304l4.5-4.5a3.75 3.75 0 0 0-1.035-6.037.75.75 0 0 1-.354-1Z" clip-rule="evenodd" />
                                                </svg>
                                            @else
                                                <svg class="w-5 h-5 dark:text-neutral-400 text-neutral-500 shrink-0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                                                    <path d="M5.625 1.5c-1.036 0-1.875.84-1.875 1.875v17.25c0 1.035.84 1.875 1.875 1.875h12.75c1.035 0 1.875-.84 1.875-1.875V12.75A3.75 3.75 0 0 0 16.5 9h-1.875a1.875 1.875 0 0 1-1.875-1.875V5.25A3.75 3.75 0 0 0 9 1.5H5.625Z" />
                                                    <path d="M12.971 1.816A5.23 5.23 0 0 1 14.25 5.25v1.875c0 .207.168.375.375.375H16.5a5.23 5.23 0 0 1 3.434 1.279 9.768 9.768 0 0 0-6.963-6.963Z" />
                                                </svg>
                                            @endif
                                            @if ($entry['type'] === 'directory' || $entry['type'] === 'symlink')
                                                <button wire:click="navigateTo('{{ $entry['name'] }}', '{{ $entry['type'] }}')"
                                                    class="dark:text-white text-neutral-900 hover:underline font-medium truncate">
                                                    {{ $entry['name'] }}
                                                </button>
                                            @else
                                                <span class="dark:text-neutral-300 text-neutral-700 truncate">{{ $entry['name'] }}</span>
                                            @endif
                                            @if ($entry['linkTarget'])
                                                <span class="dark:text-neutral-500 text-neutral-400 text-xs">→ {{ $entry['linkTarget'] }}</span>
                                            @endif
                                        </div>
                                    </td>

                                    {{-- Size --}}
                                    <td class="px-4 py-2 dark:text-neutral-400 text-neutral-500 whitespace-nowrap">
                                        @if ($entry['type'] !== 'directory')
                                            {{ $entry['sizeFormatted'] }}
                                        @else
                                            —
                                        @endif
                                    </td>

                                    {{-- Permissions --}}
                                    <td class="px-4 py-2 font-mono text-xs dark:text-neutral-400 text-neutral-500">
                                        {{ $entry['permissions'] }}
                                    </td>

                                    {{-- Owner --}}
                                    <td class="px-4 py-2 dark:text-neutral-400 text-neutral-500 whitespace-nowrap">
                                        {{ $entry['owner'] }}:{{ $entry['group'] }}
                                    </td>

                                    {{-- Modified --}}
                                    <td class="px-4 py-2 dark:text-neutral-400 text-neutral-500 whitespace-nowrap">
                                        {{ $entry['modified'] }}
                                    </td>

                                    {{-- Actions --}}
                                    <td class="px-4 py-2 text-right">
                                        <div class="flex gap-1 justify-end items-center">
                                            @if ($entry['type'] === 'file')
                                                <button wire:click="downloadFile('{{ $entry['name'] }}')"
                                                    class="p-1 rounded dark:hover:bg-coolgray-200 hover:bg-neutral-200 transition-colors"
                                                    title="Download">
                                                    <svg class="w-4 h-4 dark:text-neutral-400 text-neutral-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
                                                    </svg>
                                                </button>
                                            @endif
                                            <button
                                                x-on:click="if (confirmDelete === '{{ $entry['name'] }}') {
                                                    $wire.deleteEntry('{{ $entry['name'] }}', '{{ $entry['type'] }}');
                                                    confirmDelete = null;
                                                } else {
                                                    confirmDelete = '{{ $entry['name'] }}';
                                                    setTimeout(() => { if (confirmDelete === '{{ $entry['name'] }}') confirmDelete = null }, 3000);
                                                }"
                                                class="p-1 rounded transition-colors"
                                                :class="confirmDelete === '{{ $entry['name'] }}'
                                                    ? 'dark:bg-red-900/50 bg-red-100 dark:text-red-400 text-red-600'
                                                    : 'dark:hover:bg-coolgray-200 hover:bg-neutral-200'"
                                                :title="confirmDelete === '{{ $entry['name'] }}' ? 'Click again to confirm' : 'Delete'">
                                                <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"
                                                    :class="confirmDelete === '{{ $entry['name'] }}' ? 'dark:text-red-400 text-red-600' : 'dark:text-neutral-400 text-neutral-500'">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                                </svg>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @elseif (!$isLoading && empty($errorMessage) && $selectedContainer !== 'default')
                <div class="py-8 text-center dark:text-neutral-400 text-neutral-500">
                    <svg class="w-12 h-12 mx-auto mb-3 dark:text-neutral-600 text-neutral-300" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z" />
                    </svg>
                    This directory is empty.
                </div>
            @endif
        @endif
    </div>
</div>
