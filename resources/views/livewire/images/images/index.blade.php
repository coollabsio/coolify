<div>
    <div class="space-y-4">
        <x-images.navbar />

        <div class="flex items-center justify-between gap-2">
            <div class="flex items-center gap-2">
                <h2>Docker Images</h2>
                <form class="flex items-center gap-2" wire:submit="loadServerImages">
                    <x-forms.select id="server" required wire:model.live="selected_uuid">
                        <option value="default" disabled>Select a server</option>
                        @foreach ($servers as $server)
                            <option value="{{ $server->uuid }}">{{ $server->name }}</option>
                        @endforeach
                    </x-forms.select>
                    <x-forms.button type="submit" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="loadServerImages">Refresh</span>
                        <span wire:loading wire:target="loadServerImages">Loading...</span>
                    </x-forms.button>
                </form>
            </div>

            @if ($selected_uuid !== 'default')
                <div class="flex items-center gap-2">
                    <x-forms.button wire:click="pruneUnused"
                        wire:confirm="Are you sure you want to prune unused images?">
                        Prune Unused
                    </x-forms.button>
                    <x-forms.button wire:click="deleteSelectedImages"
                        wire:confirm="Are you sure you want to delete selected images?" :disabled="empty($selectedImages)"
                        class="bg-red-600 hover:bg-red-700">
                        Delete Selected ({{ count($selectedImages) }})
                    </x-forms.button>
                </div>
            @endif
        </div>

        @if ($selected_uuid !== 'default')
            <div class="flex items-center gap-4 mb-4">
                <div class="flex-1">
                    <x-forms.input type="search" wire:model.live.debounce.300ms="searchQuery"
                        placeholder="Search images..." />
                </div>
                <label class="flex items-center gap-2">
                    <input type="checkbox" wire:model.live="showOnlyDangling">
                    <span>Show only dangling images</span>
                </label>
            </div>
        @endif

        <div class="space-y-4">
            <div wire:loading.block wire:target="loadServerImages" class="text-center py-4">
                <x-loading text="Loading images..." />
            </div>

            <div wire:loading.remove wire:target="loadServerImages">
                @if ($selected_uuid !== 'default')
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead>
                                <tr>
                                    <th class="px-6 py-3 text-left">
                                        <input type="checkbox" wire:model.live="selectAll">
                                    </th>
                                    <th
                                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Repository</th>
                                    <th
                                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Tag</th>
                                    <th
                                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Image ID</th>
                                    <th
                                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Size</th>
                                    <th
                                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                @forelse ($filteredImages as $image)
                                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-800">
                                        <td class="px-6 py-4">
                                            <input type="checkbox" wire:model.live="selectedImages"
                                                value="{{ $image['ID'] }}">
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">{{ $image['Repository'] }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap">{{ $image['Tag'] }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap font-mono text-sm">
                                            {{ substr($image['ID'], 7, 12) }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap">{{ $image['Size'] }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex gap-2">
                                                <x-forms.button wire:click="getImageDetails('{{ $image['ID'] }}')">
                                                    Details
                                                </x-forms.button>
                                                <x-forms.button wire:click="deleteImage('{{ $image['ID'] }}')"
                                                    wire:confirm="Are you sure you want to delete this image?"
                                                    class="bg-red-600 hover:bg-red-700">
                                                    Delete
                                                </x-forms.button>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="px-6 py-4 text-center text-gray-500">
                                            No images found
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="text-center py-4 text-gray-500">
                        Please select a server to view images
                    </div>
                @endif
            </div>
        </div>

        @if ($imageDetails)
            <div class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50">
                <div class="bg-white dark:bg-gray-800 rounded-lg p-6 max-w-4xl w-full max-h-[80vh] overflow-y-auto">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-semibold">Image Details</h3>
                        <button wire:click="$set('imageDetails', null)"
                            class="text-gray-500 hover:text-gray-700">×</button>
                    </div>

                    <div class="space-y-6">
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <h4 class="font-semibold">ID:</h4>
                                <p class="font-mono text-sm">{{ $imageDetails[0]['Id'] ?? 'N/A' }}</p>
                            </div>
                            <div>
                                <h4 class="font-semibold">Created:</h4>
                                <p>{{ $imageDetails[0]['FormattedCreated'] ?? 'N/A' }}</p>
                            </div>
                            <div>
                                <h4 class="font-semibold">Size:</h4>
                                <p>{{ $imageDetails[0]['FormattedSize'] ?? 'N/A' }}</p>
                            </div>
                            <div>
                                <h4 class="font-semibold">Container Count:</h4>
                                <p>{{ $imageDetails[0]['ContainerCount'] ?? 'N/A' }}</p>
                            </div>
                        </div>

                        @if (isset($imageDetails[0]['Config']))
                            <div class="border-t pt-4">
                                <h4 class="font-semibold mb-4">Configuration</h4>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    @if (isset($imageDetails[0]['Config']['Env']) && !empty($imageDetails[0]['Config']['Env']))
                                        <div class="bg-gray-50 dark:bg-gray-700 p-4 rounded">
                                            <h5 class="font-semibold mb-2">Environment Variables</h5>
                                            <div class="font-mono text-sm space-y-1 max-h-40 overflow-y-auto">
                                                @foreach ($imageDetails[0]['Config']['Env'] as $env)
                                                    <div class="truncate">{{ $env }}</div>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif

                                    @if (isset($imageDetails[0]['Config']['ExposedPorts']) && !empty($imageDetails[0]['Config']['ExposedPorts']))
                                        <div class="bg-gray-50 dark:bg-gray-700 p-4 rounded">
                                            <h5 class="font-semibold mb-2">Exposed Ports</h5>
                                            <div class="font-mono text-sm space-y-1">
                                                @foreach (array_keys($imageDetails[0]['Config']['ExposedPorts']) as $port)
                                                    <div>{{ $port }}</div>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif

                                    @if (isset($imageDetails[0]['Config']['Volumes']) && !empty($imageDetails[0]['Config']['Volumes']))
                                        <div class="bg-gray-50 dark:bg-gray-700 p-4 rounded">
                                            <h5 class="font-semibold mb-2">Volumes</h5>
                                            <div class="font-mono text-sm space-y-1">
                                                @foreach (array_keys($imageDetails[0]['Config']['Volumes']) as $volume)
                                                    <div>{{ $volume }}</div>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif

                                    @if (isset($imageDetails[0]['Config']['Cmd']) && !empty($imageDetails[0]['Config']['Cmd']))
                                        <div class="bg-gray-50 dark:bg-gray-700 p-4 rounded">
                                            <h5 class="font-semibold mb-2">Command</h5>
                                            <div class="font-mono text-sm">
                                                {{ implode(' ', $imageDetails[0]['Config']['Cmd']) }}
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endif

                        <div class="flex justify-end gap-2 border-t pt-4">
                            <x-forms.button wire:click="pruneUnused"
                                wire:confirm="Are you sure you want to prune unused images?">
                                Prune Unused
                            </x-forms.button>
                            <x-forms.button wire:click="deleteImage('{{ $imageDetails[0]['Id'] }}')"
                                wire:confirm="Are you sure you want to delete this image?"
                                class="bg-red-600 hover:bg-red-700">
                                Delete Image
                            </x-forms.button>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>
