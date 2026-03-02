<div>
    <x-slot:title>
        {{ data_get_str($resource, 'name')->limit(10) }} > File Browser | Coolify
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

    <h2 class="pb-4">File Browser</h2>

    @if (count($containers) === 0)
        <div>No running containers found or terminal access is disabled on this server.</div>
    @else
        {{-- Container selector --}}
        <div class="flex gap-2 items-end pb-4">
            <x-forms.select label="Container" wire:model.live="selected_container" class="w-96">
                @foreach ($containers as $container)
                    @if ($loop->first)
                        <option disabled value="default">Select a container</option>
                    @endif
                    <option value="{{ data_get($container, 'container.Names') }}">
                        {{ data_get($container, 'container.Names') }}
                        ({{ data_get($container, 'server.name') }})
                    </option>
                @endforeach
            </x-forms.select>
        </div>

        @if ($selected_container !== 'default')
            {{-- Breadcrumb navigation --}}
            <div class="flex items-center gap-1 pb-4 text-sm" x-data>
                <button wire:click="browse('/')" class="dark:text-white hover:underline font-bold">/</button>
                @php
                    $pathParts = array_filter(explode('/', $currentPath));
                    $accumulated = '';
                @endphp
                @foreach ($pathParts as $part)
                    @php $accumulated .= '/' . $part; @endphp
                    <span class="dark:text-neutral-400">/</span>
                    <button wire:click="browse('{{ $accumulated }}')" class="hover:underline dark:text-white">{{ $part }}</button>
                @endforeach
            </div>

            {{-- Toolbar --}}
            <div class="flex flex-wrap gap-2 items-center pb-4">
                <x-forms.button wire:click="navigateUp" title="Go up one directory">
                    <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M17 11l-5-5-5 5"/>
                        <path d="M17 18l-5-5-5 5"/>
                    </svg>
                    Up
                </x-forms.button>
                <x-forms.button wire:click="browse('{{ $currentPath }}')" title="Refresh">
                    <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21.5 2v6h-6M2.5 22v-6h6M2 11.5a10 10 0 0 1 18.8-4.3M22 12.5a10 10 0 0 1-18.8 4.2"/>
                    </svg>
                    Refresh
                </x-forms.button>
                <x-forms.button wire:click="$set('showCreateFolder', true)" title="Create folder">
                    <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 5v14M5 12h14"/>
                    </svg>
                    New Folder
                </x-forms.button>
            </div>

            {{-- Create folder form --}}
            @if ($showCreateFolder)
                <div class="flex gap-2 items-end pb-4">
                    <x-forms.input wire:model="newFolderName" label="Folder Name" placeholder="new-folder" required />
                    <x-forms.button wire:click="createFolder">Create</x-forms.button>
                    <x-forms.button wire:click="$set('showCreateFolder', false)">Cancel</x-forms.button>
                </div>
            @endif

            {{-- Upload --}}
            <div class="flex gap-2 items-end pb-4" x-data="{ uploading: false }" x-on:livewire-upload-start="uploading = true" x-on:livewire-upload-finish="uploading = false" x-on:livewire-upload-error="uploading = false">
                <div class="flex gap-2 items-end">
                    <div>
                        <label class="block text-sm font-medium pb-1">Upload File</label>
                        <input type="file" wire:model="uploadFile" class="block text-sm file:mr-4 file:py-2 file:px-4 file:rounded file:border-0 file:text-sm file:bg-coollabs file:text-white hover:file:bg-coollabs-100 dark:text-neutral-300" />
                    </div>
                    <x-forms.button wire:click="uploadToContainer" :disabled="$isUploading">
                        <span x-show="!uploading && !@js($isUploading)">Upload</span>
                        <span x-show="uploading || @js($isUploading)">Uploading...</span>
                    </x-forms.button>
                </div>
            </div>

            {{-- Loading indicator --}}
            <div wire:loading wire:target="browse,navigateTo,navigateUp,createFolder,deleteEntry,uploadToContainer" class="pb-2">
                <x-loading />
            </div>

            {{-- File listing --}}
            <div wire:loading.remove wire:target="browse,navigateTo,navigateUp">
                @if (count($entries) === 0 && $selected_container !== 'default')
                    <div class="dark:text-neutral-400">This directory is empty.</div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="dark:text-neutral-400 border-b dark:border-neutral-700">
                                    <th class="text-left py-2 px-2">Permissions</th>
                                    <th class="text-left py-2 px-2">Owner</th>
                                    <th class="text-left py-2 px-2">Group</th>
                                    <th class="text-right py-2 px-2">Size</th>
                                    <th class="text-left py-2 px-2">Modified</th>
                                    <th class="text-left py-2 px-2">Name</th>
                                    <th class="text-right py-2 px-2">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($entries as $entry)
                                    <tr class="border-b dark:border-neutral-800 hover:dark:bg-neutral-800/50">
                                        <td class="py-1.5 px-2 font-mono text-xs">{{ $entry['permissions'] }}</td>
                                        <td class="py-1.5 px-2">{{ $entry['owner'] }}</td>
                                        <td class="py-1.5 px-2">{{ $entry['group'] }}</td>
                                        <td class="py-1.5 px-2 text-right font-mono">
                                            @if ($entry['isDirectory'])
                                                &mdash;
                                            @else
                                                {{ formatBytes($entry['size']) }}
                                            @endif
                                        </td>
                                        <td class="py-1.5 px-2 text-xs whitespace-nowrap">{{ $entry['modified'] }}</td>
                                        <td class="py-1.5 px-2">
                                            @if ($entry['isDirectory'])
                                                <button wire:click="navigateTo({{ $loop->index }})" class="flex items-center gap-1 hover:underline dark:text-white font-medium">
                                                    <svg class="w-4 h-4 text-yellow-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                                                        <path d="M2 4a2 2 0 0 1 2-2h4.586a2 2 0 0 1 1.414.586l1.414 1.414H20a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V4z"/>
                                                    </svg>
                                                    {{ $entry['name'] }}
                                                </button>
                                            @elseif ($entry['isSymlink'])
                                                <span class="flex items-center gap-1 dark:text-blue-400">
                                                    <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                        <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/>
                                                        <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>
                                                    </svg>
                                                    {{ $entry['name'] }}
                                                    @if ($entry['linkTarget'])
                                                        <span class="dark:text-neutral-500">-&gt; {{ $entry['linkTarget'] }}</span>
                                                    @endif
                                                </span>
                                            @else
                                                <span class="flex items-center gap-1">
                                                    <svg class="w-4 h-4 dark:text-neutral-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                                        <polyline points="14 2 14 8 20 8"/>
                                                    </svg>
                                                    {{ $entry['name'] }}
                                                </span>
                                            @endif
                                        </td>
                                        <td class="py-1.5 px-2 text-right whitespace-nowrap">
                                            @if ($entry['isDirectory'])
                                                <button wire:click="downloadFolder({{ $loop->index }})" class="text-xs hover:underline dark:text-neutral-400 hover:dark:text-white" title="Download as .tar.gz">
                                                    Download
                                                </button>
                                                <span class="dark:text-neutral-600 px-1">|</span>
                                            @else
                                                <button wire:click="downloadFile({{ $loop->index }})" class="text-xs hover:underline dark:text-neutral-400 hover:dark:text-white" title="Download file">
                                                    Download
                                                </button>
                                                <span class="dark:text-neutral-600 px-1">|</span>
                                            @endif
                                            <button
                                                x-data="{ entryName: @js($entry['name']) }"
                                                x-on:click="if (confirm('Delete ' + entryName + '? This cannot be undone.')) { $wire.deleteEntry({{ $loop->index }}) }"
                                                class="text-xs hover:underline text-error"
                                                title="Delete">
                                                Delete
                                            </button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        @endif
    @endif
</div>
