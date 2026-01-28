<div>
    <div x-data="{
        showNewFolderInput: false,
    }">

        {{-- Container Selector --}}
        @if ($containers->count() === 0)
            <div class="pt-4">
                <p>No running containers found. Deploy the resource first.</p>
            </div>
        @elseif ($containers->count() > 1 && empty($selectedContainer))
            <div class="pt-4">
                <h3 class="pb-2">Select a Container</h3>
                <div class="flex flex-wrap gap-2">
                    @foreach ($containers as $container)
                        <x-forms.button
                            wire:click="selectContainer('{{ data_get($container, 'container.Names') }}')"
                        >
                            {{ data_get($container, 'container.Names') }}
                            <span class="text-xs opacity-60">({{ data_get($container, 'server.name') }})</span>
                        </x-forms.button>
                    @endforeach
                </div>
            </div>
        @endif

        @if (!empty($selectedContainer))
            {{-- Toolbar --}}
            <div class="flex flex-wrap items-center gap-2 pb-4">
                @if ($containers->count() > 1)
                    <select wire:change="selectContainer($event.target.value)"
                        class="px-3 py-1.5 text-sm border rounded bg-coolgray-100 border-coolgray-300 dark:bg-coolgray-100 dark:border-coolgray-300">
                        @foreach ($containers as $container)
                            <option value="{{ data_get($container, 'container.Names') }}"
                                @selected(data_get($container, 'container.Names') === $selectedContainer)>
                                {{ data_get($container, 'container.Names') }}
                            </option>
                        @endforeach
                    </select>
                @endif

                <x-forms.button wire:click="loadDirectory" title="Refresh">
                    <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182M2.985 19.644l3.181-3.182" />
                    </svg>
                    Refresh
                </x-forms.button>

                <x-forms.button @click="showNewFolderInput = !showNewFolderInput" title="New Folder">
                    <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 10.5v6m3-3H9m4.06-7.19l-2.12-2.12a1.5 1.5 0 00-1.06-.44H4.5A2.25 2.25 0 002.25 6v12a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9a2.25 2.25 0 00-2.25-2.25h-5.379a1.5 1.5 0 01-1.06-.44z" />
                    </svg>
                    New Folder
                </x-forms.button>

                <label class="button cursor-pointer" title="Upload File">
                    <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
                    </svg>
                    Upload
                    <input type="file" wire:model="uploadFile" class="hidden" />
                </label>
            </div>

            {{-- Upload progress --}}
            <div wire:loading wire:target="uploadFile" class="pb-2 text-sm text-warning">
                Uploading file...
            </div>

            {{-- New Folder Input --}}
            <div x-show="showNewFolderInput" x-cloak class="flex items-center gap-2 pb-4">
                <input type="text" wire:model="newFolderName" wire:keydown.enter="createFolder"
                    placeholder="Folder name"
                    class="px-3 py-1.5 text-sm border rounded bg-coolgray-100 border-coolgray-300 dark:bg-coolgray-100 dark:border-coolgray-300 w-64">
                <x-forms.button wire:click="createFolder" @click="showNewFolderInput = false">Create</x-forms.button>
                <x-forms.button @click="showNewFolderInput = false; $wire.set('newFolderName', '')">Cancel</x-forms.button>
            </div>

            {{-- Breadcrumb Navigation --}}
            <div class="flex items-center gap-1 pb-3 text-sm flex-wrap">
                <button wire:click="navigateTo('/')" class="hover:text-white transition-colors"
                    title="Go to root">
                    <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955a1.126 1.126 0 011.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
                    </svg>
                </button>
                <span class="opacity-40">/</span>
                @foreach ($breadcrumbs as $crumb)
                    <button wire:click="navigateTo('{{ $crumb['path'] }}')"
                        class="hover:text-white transition-colors hover:underline">
                        {{ $crumb['name'] }}
                    </button>
                    @if (!$loop->last)
                        <span class="opacity-40">/</span>
                    @endif
                @endforeach
                @if ($currentPath !== '/')
                    <button wire:click="navigateUp" class="ml-2 opacity-60 hover:opacity-100 transition-opacity" title="Go up">
                        <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3" />
                        </svg>
                    </button>
                @endif
            </div>

            {{-- Loading State --}}
            <div wire:loading wire:target="navigateTo,loadDirectory,deleteEntry,createFolder" class="pb-2 text-sm opacity-60">
                Loading...
            </div>

            {{-- Delete Confirmation --}}
            @if ($deleteTarget)
                <div class="flex items-center gap-3 p-3 mb-3 border rounded border-error/50 bg-error/10">
                    <svg class="w-5 h-5 text-error shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                    </svg>
                    <span class="text-sm">Delete <strong>{{ basename($deleteTarget) }}</strong>?</span>
                    <x-forms.button isError wire:click="deleteEntry">Delete</x-forms.button>
                    <x-forms.button wire:click="cancelDelete">Cancel</x-forms.button>
                </div>
            @endif

            {{-- File Table --}}
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left border-b border-coolgray-300">
                            <th class="pb-2 pr-4 font-medium w-8"></th>
                            <th class="pb-2 pr-4 font-medium">Name</th>
                            <th class="pb-2 pr-4 font-medium w-24 hidden sm:table-cell">Size</th>
                            <th class="pb-2 pr-4 font-medium w-40 hidden md:table-cell">Modified</th>
                            <th class="pb-2 pr-4 font-medium w-28 hidden lg:table-cell">Permissions</th>
                            <th class="pb-2 font-medium w-24 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($entries as $entry)
                            <tr class="border-b border-coolgray-300/30 hover:bg-coolgray-200/30 transition-colors group">
                                {{-- Icon --}}
                                <td class="py-2 pr-2">
                                    @if ($entry['is_directory'])
                                        <svg class="w-5 h-5 text-warning" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 014.5 9.75h15A2.25 2.25 0 0121.75 12v.75m-8.69-6.44l-2.12-2.12a1.5 1.5 0 00-1.06-.44H4.5A2.25 2.25 0 002.25 6v12a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9a2.25 2.25 0 00-2.25-2.25h-5.379a1.5 1.5 0 01-1.06-.44z" />
                                        </svg>
                                    @elseif ($entry['is_symlink'])
                                        <svg class="w-5 h-5 text-info" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m9.86-2.439a4.5 4.5 0 00-1.242-7.244l-4.5-4.5a4.5 4.5 0 10-6.364 6.364l1.757 1.757" />
                                        </svg>
                                    @else
                                        <svg class="w-5 h-5 opacity-60" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                                        </svg>
                                    @endif
                                </td>

                                {{-- Name --}}
                                <td class="py-2 pr-4">
                                    @if ($entry['is_directory'])
                                        <button wire:click="navigateTo('{{ $entry['path'] }}')"
                                            class="hover:text-white hover:underline transition-colors font-medium">
                                            {{ $entry['name'] }}
                                        </button>
                                    @else
                                        <span>{{ $entry['name'] }}</span>
                                    @endif
                                    @if ($entry['is_symlink'] && $entry['symlink_target'])
                                        <span class="text-xs opacity-40 ml-1">→ {{ $entry['symlink_target'] }}</span>
                                    @endif
                                </td>

                                {{-- Size --}}
                                <td class="py-2 pr-4 opacity-60 hidden sm:table-cell">
                                    @if (!$entry['is_directory'])
                                        {{ \App\Livewire\Project\Shared\ContainerFileBrowser::formatSize($entry['size']) }}
                                    @else
                                        —
                                    @endif
                                </td>

                                {{-- Modified --}}
                                <td class="py-2 pr-4 opacity-60 hidden md:table-cell">
                                    {{ $entry['date'] }} {{ $entry['time'] }}
                                </td>

                                {{-- Permissions --}}
                                <td class="py-2 pr-4 font-mono text-xs opacity-60 hidden lg:table-cell">
                                    {{ $entry['permissions'] }}
                                </td>

                                {{-- Actions --}}
                                <td class="py-2 text-right">
                                    <div class="flex items-center justify-end gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                        @if (!$entry['is_directory'])
                                            <button wire:click="prepareDownload('{{ $entry['path'] }}')"
                                                class="p-1 rounded hover:bg-coolgray-300 transition-colors" title="Download">
                                                <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                                                </svg>
                                            </button>
                                        @endif
                                        <button wire:click="confirmDelete('{{ $entry['path'] }}')"
                                            class="p-1 rounded hover:bg-error/20 text-error transition-colors" title="Delete">
                                            <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                            </svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="py-8 text-center opacity-60">
                                    @if ($isLoading)
                                        Loading directory contents...
                                    @else
                                        This directory is empty.
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Current path display --}}
            <div class="pt-3 text-xs opacity-40">
                Container: {{ $selectedContainer }} · Path: {{ $currentPath }}
            </div>
        @endif
    </div>
</div>
