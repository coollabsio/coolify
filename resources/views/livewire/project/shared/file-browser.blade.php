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

    <div class="pt-4">
        @if ($containers->count() === 0)
            <div class="dark:text-neutral-400">No running containers found.</div>
        @else
            @if ($containers->count() > 1)
                <div class="flex gap-2 items-end pb-4">
                    <x-forms.select label="Container" wire:model.live="selectedContainer">
                        <option value="">Select a container</option>
                        @foreach ($containers as $container)
                            <option value="{{ data_get($container, 'container.Names') }}">
                                {{ data_get($container, 'container.Names') }}
                                ({{ data_get($container, 'server.name') }})
                            </option>
                        @endforeach
                    </x-forms.select>
                </div>
            @endif

            @if ($connected)
                <div class="flex items-center gap-1 pb-3 text-sm flex-wrap">
                    <svg class="w-4 h-4 dark:text-neutral-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" />
                    </svg>
                    @foreach ($this->getBreadcrumbs() as $crumb)
                        @if (!$loop->first)
                            <span class="dark:text-neutral-500">/</span>
                        @endif
                        <button
                            class="dark:text-neutral-300 hover:dark:text-white hover:underline"
                            wire:click="navigateToPath('{{ $crumb['path'] }}')"
                        >
                            {{ $crumb['name'] }}
                        </button>
                    @endforeach
                </div>

                <div class="flex flex-wrap gap-3 items-end pb-4">
                    <div class="flex gap-2 items-end">
                        <div>
                            <label class="block text-sm font-medium dark:text-neutral-300 pb-1">Upload File</label>
                            <input type="file" wire:model="uploadFile"
                                class="text-sm dark:text-neutral-300 file:mr-2 file:py-1 file:px-3 file:rounded file:border-0 file:text-sm file:bg-coolgray-200 file:dark:text-white hover:file:bg-coolgray-300 cursor-pointer" />
                        </div>
                        <x-forms.button wire:click="uploadToContainer" wire:loading.attr="disabled">
                            <div wire:loading wire:target="uploadFile" class="pr-1">
                                <svg class="animate-spin h-4 w-4" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                </svg>
                            </div>
                            Upload
                        </x-forms.button>
                    </div>

                    <div class="flex gap-2 items-end">
                        <x-forms.input wire:model="newFolderName" placeholder="New folder name" label="Create Folder" />
                        <x-forms.button wire:click="createFolder">
                            Create
                        </x-forms.button>
                    </div>

                    <x-forms.button wire:click="listFiles">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                        </svg>
                        Refresh
                    </x-forms.button>
                </div>

                <div class="rounded-sm border dark:border-coolgray-300 overflow-hidden">
                    <table class="w-full text-sm">
                        <thead class="dark:bg-coolgray-200">
                            <tr class="border-b dark:border-coolgray-300">
                                <th class="text-left p-2 cursor-pointer hover:dark:text-white dark:text-neutral-300" wire:click="sort('name')">
                                    <div class="flex items-center gap-1">
                                        Name
                                        @if ($sortBy === 'name')
                                            <svg class="w-3 h-3" viewBox="0 0 24 24" fill="currentColor">
                                                @if ($sortDirection === 'asc')
                                                    <path d="M7 14l5-5 5 5z"/>
                                                @else
                                                    <path d="M7 10l5 5 5-5z"/>
                                                @endif
                                            </svg>
                                        @endif
                                    </div>
                                </th>
                                <th class="text-left p-2 cursor-pointer hover:dark:text-white dark:text-neutral-300 w-28" wire:click="sort('size')">
                                    <div class="flex items-center gap-1">
                                        Size
                                        @if ($sortBy === 'size')
                                            <svg class="w-3 h-3" viewBox="0 0 24 24" fill="currentColor">
                                                @if ($sortDirection === 'asc')
                                                    <path d="M7 14l5-5 5 5z"/>
                                                @else
                                                    <path d="M7 10l5 5 5-5z"/>
                                                @endif
                                            </svg>
                                        @endif
                                    </div>
                                </th>
                                <th class="text-left p-2 cursor-pointer hover:dark:text-white dark:text-neutral-300 hidden md:table-cell" wire:click="sort('permissions')">
                                    <div class="flex items-center gap-1">
                                        Permissions
                                        @if ($sortBy === 'permissions')
                                            <svg class="w-3 h-3" viewBox="0 0 24 24" fill="currentColor">
                                                @if ($sortDirection === 'asc')
                                                    <path d="M7 14l5-5 5 5z"/>
                                                @else
                                                    <path d="M7 10l5 5 5-5z"/>
                                                @endif
                                            </svg>
                                        @endif
                                    </div>
                                </th>
                                <th class="text-left p-2 dark:text-neutral-300 hidden lg:table-cell">Owner</th>
                                <th class="text-right p-2 dark:text-neutral-300 w-24">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($files as $file)
                                <tr class="border-b dark:border-coolgray-300 hover:dark:bg-coolgray-100 transition-colors" wire:key="file-{{ $loop->index }}">
                                    <td class="p-2">
                                        <div class="flex items-center gap-2">
                                            @if ($file['name'] === '..')
                                                <svg class="w-4 h-4 dark:text-neutral-400 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <path d="M15 19l-7-7 7-7" />
                                                </svg>
                                                <button class="hover:underline dark:text-neutral-300 hover:dark:text-white" wire:click="navigateTo('..')">
                                                    ..
                                                </button>
                                            @elseif ($file['isDirectory'])
                                                <svg class="w-4 h-4 text-yellow-500 shrink-0" viewBox="0 0 24 24" fill="currentColor">
                                                    <path d="M10 4H4a2 2 0 00-2 2v12a2 2 0 002 2h16a2 2 0 002-2V8a2 2 0 00-2-2h-8l-2-2z"/>
                                                </svg>
                                                <button class="hover:underline dark:text-neutral-200 hover:dark:text-white truncate" wire:click="navigateTo('{{ $file['name'] }}')">
                                                    {{ $file['name'] }}
                                                </button>
                                            @elseif ($file['isLink'])
                                                <svg class="w-4 h-4 dark:text-blue-400 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <path d="M10 13a5 5 0 007.54.54l3-3a5 5 0 00-7.07-7.07l-1.72 1.71"/>
                                                    <path d="M14 11a5 5 0 00-7.54-.54l-3 3a5 5 0 007.07 7.07l1.71-1.71"/>
                                                </svg>
                                                <span class="dark:text-neutral-200 truncate">
                                                    {{ $file['name'] }}
                                                    @if ($file['linkTarget'])
                                                        <span class="dark:text-neutral-500">-> {{ $file['linkTarget'] }}</span>
                                                    @endif
                                                </span>
                                            @else
                                                <svg class="w-4 h-4 dark:text-neutral-400 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/>
                                                    <polyline points="14 2 14 8 20 8"/>
                                                </svg>
                                                <span class="dark:text-neutral-200 truncate">{{ $file['name'] }}</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="p-2 dark:text-neutral-400 text-xs whitespace-nowrap">
                                        {{ $file['name'] === '..' ? '' : $file['size'] }}
                                    </td>
                                    <td class="p-2 dark:text-neutral-400 font-mono text-xs hidden md:table-cell">
                                        {{ $file['name'] === '..' ? '' : $file['permissions'] }}
                                    </td>
                                    <td class="p-2 dark:text-neutral-400 text-xs hidden lg:table-cell">
                                        {{ $file['name'] === '..' ? '' : $file['owner'] . ':' . $file['group'] }}
                                    </td>
                                    <td class="p-2 text-right">
                                        @if ($file['name'] !== '..')
                                            <div class="flex gap-1 justify-end">
                                                @if (!$file['isDirectory'])
                                                    <button
                                                        class="p-1 rounded hover:dark:bg-coolgray-300 dark:text-neutral-400 hover:dark:text-white"
                                                        title="Download"
                                                        wire:click="downloadFile('{{ $file['name'] }}')"
                                                    >
                                                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                            <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/>
                                                            <polyline points="7 10 12 15 17 10"/>
                                                            <line x1="12" y1="15" x2="12" y2="3"/>
                                                        </svg>
                                                    </button>
                                                @endif
                                                <button
                                                    class="p-1 rounded hover:dark:bg-coolgray-300 dark:text-neutral-400 hover:text-red-500"
                                                    title="Delete"
                                                    wire:click="deleteItem('{{ $file['name'] }}', {{ $file['isDirectory'] ? 'true' : 'false' }})"
                                                    wire:confirm="Are you sure you want to delete '{{ $file['name'] }}'{{ $file['isDirectory'] ? ' and all its contents' : '' }}?"
                                                >
                                                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <polyline points="3 6 5 6 21 6"/>
                                                        <path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/>
                                                    </svg>
                                                </button>
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="p-4 text-center dark:text-neutral-400">
                                        This directory is empty.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @endif
        @endif
    </div>
</div>
