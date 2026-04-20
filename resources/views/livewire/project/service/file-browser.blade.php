<div class="flex flex-col gap-4">
    {{-- Header --}}
    <div class="flex items-center justify-between">
        <h3 class="text-lg font-medium">File Browser</h3>
        <div class="flex gap-2">
            <x-forms.button wire:click="refresh" wire:loading.attr="disabled">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                </svg>
                Refresh
            </x-forms.button>
        </div>
    </div>

    {{-- Messages --}}
    @if($errorMessage)
        <div class="p-4 bg-red-500/10 border border-red-500 rounded text-red-500">
            {{ $errorMessage }}
        </div>
    @endif

    @if($successMessage)
        <div class="p-4 bg-green-500/10 border border-green-500 rounded text-green-500">
            {{ $successMessage }}
        </div>
    @endif

    {{-- Navigation --}}
    <div class="flex items-center gap-2 p-3 bg-white dark:bg-base-200 rounded border dark:border-coolgray-300 border-neutral-200">
        {{-- Up button --}}
        <x-forms.button wire:click="navigateUp" wire:loading.attr="disabled" :disabled="$currentPath === '/'">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
            </svg>
        </x-forms.button>

        {{-- Path display --}}
        <div class="flex items-center gap-1 text-sm font-mono flex-1 overflow-x-auto">
            <span class="text-coolgray-400">Container:</span>
            <span class="px-2 py-1 bg-coolgray-100 dark:bg-coolgray-300 rounded">{{ $containerName }}</span>
            <span class="text-coolgray-400">/</span>
            @foreach(explode('/', trim($currentPath, '/')) as $i => $segment)
                @if($i > 0)
                    <span class="text-coolgray-400">/</span>
                @endif
                @if(!empty($segment))
                    <button wire:click="navigateTo('{{ '/' . implode('/', array_slice(explode('/', trim($currentPath, '/')), 0, $i + 1)) }}')" 
                            class="hover:text-white transition-colors">
                        {{ $segment }}
                    </button>
                @endif
            @endforeach
            @if($currentPath === '/')
                <span>/</span>
            @endif
        </div>
    </div>

    {{-- Upload Section --}}
    <div class="p-4 bg-white dark:bg-base-200 rounded border dark:border-coolgray-300 border-neutral-200">
        <form wire:submit.prevent="uploadFile" class="flex items-end gap-4">
            <div class="flex-1">
                <x-forms.input label="Upload to current path" :value="$currentPath" readonly />
            </div>
            <div class="flex-1">
                <x-forms.file 
                    id="uploadFile" 
                    label="Select file to upload (max 10MB)" 
                    wire:model="uploadFile" 
                />
            </div>
            <div>
                <x-forms.button type="submit" wire:loading.attr="disabled">
                    Upload
                </x-forms.button>
            </div>
        </form>
        @error('uploadFile') 
            <span class="text-red-500 text-sm mt-1">{{ $message }}</span> 
        @enderror
    </div>

    {{-- Loading State --}}
    @if($isLoading)
        <div class="flex items-center justify-center py-12">
            <svg class="animate-spin h-8 w-8 text-coollabs" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
        </div>
    @else
        {{-- File List --}}
        <div class="bg-white dark:bg-base-200 rounded border dark:border-coolgray-300 border-neutral-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-coolgray-50 dark:bg-coolgray-300 border-b dark:border-coolgray-400 border-neutral-200">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium">Name</th>
                            <th class="px-4 py-3 text-left font-medium">Type</th>
                            <th class="px-4 py-3 text-left font-medium">Size</th>
                            <th class="px-4 py-3 text-left font-medium">Permissions</th>
                            <th class="px-4 py-3 text-right font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($files as $file)
                            <tr class="border-b dark:border-coolgray-400 border-neutral-200 hover:bg-coolgray-50 dark:hover:bg-coolgray-300/50 transition-colors">
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        @if($file['is_directory'])
                                            <svg class="w-4 h-4 text-yellow-500" fill="currentColor" viewBox="0 0 20 20">
                                                <path d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"></path>
                                            </svg>
                                            <button wire:click="navigateTo('{{ $file['path'] }}')" 
                                                    class="hover:text-white transition-colors text-left">
                                                {{ $file['name'] }}
                                            </button>
                                        @else
                                            <svg class="w-4 h-4 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z" clip-rule="evenodd"></path>
                                            </svg>
                                            <span class="text-left">{{ $file['name'] }}</span>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    @if($file['is_directory'])
                                        <span class="px-2 py-1 text-xs rounded bg-yellow-500/10 text-yellow-500">Directory</span>
                                    @elseif($file['is_symlink'])
                                        <span class="px-2 py-1 text-xs rounded bg-blue-500/10 text-blue-500">Symlink</span>
                                    @else
                                        <span class="px-2 py-1 text-xs rounded bg-gray-500/10 text-gray-400">File</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 font-mono text-xs">{{ $file['size'] }}</td>
                                <td class="px-4 py-3 font-mono text-xs text-coolgray-400">{{ $file['permissions'] }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-end gap-2">
                                        @if(!$file['is_directory'])
                                            <a href="{{ route('services.filebrowser.download', ['service' => $resource->uuid, 'path' => ltrim($file['path'], '/')]) }}" 
                                               class="p-1 hover:bg-coollabs/10 rounded transition-colors"
                                               title="Download">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                                                </svg>
                                            </a>
                                        @endif
                                        <button wire:click="deleteFile('{{ $file['path'] }}', {{ $file['is_directory'] ? 'true' : 'false' }})" 
                                                wire:confirm="Are you sure you want to delete {{ $file['name'] }}?"
                                                class="p-1 hover:bg-red-500/10 rounded transition-colors text-red-500"
                                                title="Delete">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                            </svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-12 text-center text-coolgray-400">
                                    No files found in this directory
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
