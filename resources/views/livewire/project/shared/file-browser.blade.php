<div class="p-4 border rounded-lg dark:border-coolgray-200 bg-white dark:bg-base">
    <div class="flex items-center justify-between mb-4">
        <div class="flex items-center gap-2">
            <button wire:click="goUp" class="p-2 rounded hover:bg-neutral-100 dark:hover:bg-coolgray-100 disabled:opacity-50" @if($currentPath === '/') disabled @endif>
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 15l-3-3m0 0l3-3m-3 3h8M3 12a9 9 0 1118 0 9 9 0 01-18 0z"/></svg>
            </button>
            <span class="font-mono text-sm dark:text-neutral-300">{{ $currentPath }}</span>
        </div>
        <button wire:click="loadFiles" class="btn-primary btn-sm">Refresh</button>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="border rounded h-96 overflow-y-auto dark:border-coolgray-200">
            <table class="w-full text-sm text-left">
                <tbody class="divide-y dark:divide-coolgray-200">
                    @foreach ($files as $file)
                        <tr class="hover:bg-neutral-50 dark:hover:bg-coolgray-200 cursor-pointer" 
                            wire:click="{{ $file['is_directory'] ? 'changeDirectory(\''.$file['path'].'\')' : 'readFile(\''.$file['name'].'\')' }}">
                            <td class="p-2 flex items-center gap-2">
                                @if ($file['is_directory'])
                                    <svg class="w-4 h-4 text-yellow-500" fill="currentColor" viewBox="0 0 20 20"><path d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/></svg>
                                @else
                                    <svg class="w-4 h-4 text-neutral-400" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z" clip-rule="evenodd"/></svg>
                                @endif
                                <span class="truncate">{{ $file['name'] }}</span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="border rounded h-96 flex flex-col dark:border-coolgray-200">
            @if ($selectedFileContent !== null)
                <div class="p-2 bg-neutral-100 dark:bg-coolgray-100 border-bottom text-xs font-bold truncate">
                    {{ $selectedFileName }}
                </div>
                <pre class="p-4 flex-1 overflow-auto font-mono text-xs whitespace-pre-wrap dark:text-neutral-300">{{ $selectedFileContent }}</pre>
            @else
                <div class="flex items-center justify-center h-full text-neutral-400 italic text-sm">
                    Select a file to view content
                </div>
            @endif
        </div>
    </div>
</div>
