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
                    <x-modal-confirmation wire:model="showDeleteConfirmation" title="Confirm Image Deletion?"
                        buttonTitle="Delete Selected ({{ count($selectedImages) }})" isErrorButton
                        submitAction="deleteImages" :actions="[
                            count($selectedImages) . ' image(s) will be permanently deleted.',
                            'This action cannot be undone.',
                            'All containers using these images must be stopped first.',
                        ]" confirmationText="delete"
                        confirmationLabel="Please type 'delete' to confirm" shortConfirmationLabel="Confirmation"
                        step3ButtonText="Permanently Delete" wire:model.defer="confirmationText" :disabled="empty($selectedImages)" />
                </div>
            @endif
        </div>

        @if ($selected_uuid !== 'default')
            <div class="flex items-center gap-4 mb-4">
                <div class="flex-1">
                    <x-forms.input type="search" wire:model.live.debounce.300ms="searchQuery"
                        placeholder="Search images..." />
                </div>
                {{-- <label class="flex items-center gap-2">
                    <input type="checkbox" wire:model.live="showOnlyDangling">
                    <span>Show only dangling images</span>
                </label> --}}
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
                                    {{-- <th
                                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Repository</th> --}}
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
                                        Status</th>
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
                                                value="{{ $image['Id'] }}">
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            @if (is_array($image['RepoTags']))
                                                @foreach ($image['RepoTags'] as $tag)
                                                    <span
                                                        class="inline-block px-2 py-1 text-xs bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300 rounded-full mr-2">
                                                        {{ $tag }}
                                                    </span>
                                                @endforeach
                                            @else
                                                <span
                                                    class="inline-block px-2 py-1 text-xs bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300 rounded-full">
                                                    {{ $image['RepoTags'] }}
                                                </span>
                                            @endif
                                        </td>
                                        {{-- <td class="px-6 py-4 whitespace-nowrap">{{ $image['Tag'] }}</td> --}}
                                        <td class="px-6 py-4 whitespace-nowrap font-mono text-sm">
                                            {{ $image['Id'] }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">{{ $image['FormattedSize'] }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center gap-2">
                                                <span @class([
                                                    'px-2 py-1 text-xs rounded-full',
                                                    'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300' =>
                                                        $image['Status'] === 'in use',
                                                    'bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-300' =>
                                                        $image['Status'] === 'unused',
                                                ])>
                                                    {{ $image['Status'] }}
                                                </span>
                                                @if ($image['Dangling'])
                                                    <span
                                                        class="px-2 py-1 text-xs rounded-full bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300">
                                                        Dangling
                                                    </span>
                                                @endif
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex gap-2">
                                                <x-forms.button wire:click="getImageDetails('{{ $image['Id'] }}')">
                                                    Details
                                                </x-forms.button>
                                                <x-modal-confirmation wire:model="showDeleteConfirmation"
                                                    title="Confirm Image Deletion?" buttonTitle="Delete" isErrorButton
                                                    submitAction="deleteImages" :actions="[
                                                        '1 image will be permanently deleted.',
                                                        'This action cannot be undone.',
                                                        'All containers using this image must be stopped first.',
                                                    ]"
                                                    confirmationText="delete"
                                                    confirmationLabel="Please type 'delete' to confirm"
                                                    shortConfirmationLabel="Confirmation"
                                                    step3ButtonText="Permanently Delete"
                                                    wire:model.defer="confirmationText"
                                                    wire:click.prevent="confirmDelete('{{ $image['Id'] }}')" />
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

        {{-- Image Details Modal --}}
        @if ($imageDetails)
            <div class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50">
                <div
                    class="bg-gray-100 dark:bg-gray-800 rounded-lg p-4 sm:p-6 max-w-4xl w-full max-h-[90vh] overflow-y-auto">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-semibold">Image Details</h3>
                        <div class="flex items-center gap-2">
                            <x-modal-confirmation wire:model="showDeleteConfirmation" title="Confirm Image Deletion?"
                                buttonTitle="Delete Image" isErrorButton submitAction="deleteImages" :actions="[
                                    '1 image will be permanently deleted.',
                                    'This action cannot be undone.',
                                    'All containers using this image must be stopped first.',
                                ]"
                                confirmationText="delete" confirmationLabel="Please type 'delete' to confirm"
                                shortConfirmationLabel="Confirmation" step3ButtonText="Permanently Delete"
                                wire:model.defer="confirmationText"
                                wire:click.prevent="confirmDelete('{{ $imageDetails['Id'] }}')" />
                            <button wire:click="$set('imageDetails', null)"
                                class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                                <span class="text-2xl">×</span>
                            </button>
                        </div>
                    </div>

                    <div class="space-y-6">
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                            <div class="space-y-4">
                                {{-- Basic Information --}}
                                <div class="bg-white dark:bg-gray-700 rounded-lg p-3 sm:p-4">
                                    <h4 class="font-semibold mb-2">Basic Information</h4>
                                    <dl class="space-y-2">
                                        <div>
                                            <dt class="text-sm text-gray-500 dark:text-gray-400">ID</dt>
                                            <dd class="font-mono text-sm break-all">{{ $imageDetails['Id'] }}</dd>
                                        </div>
                                        {{-- ... rest of basic information ... --}}
                                    </dl>
                                </div>

                                {{-- Tags and Digests --}}
                                <div class="bg-white dark:bg-gray-700 rounded-lg p-3 sm:p-4">
                                    <h4 class="font-semibold mb-2">Tags and Digests</h4>
                                    <div class="space-y-2">
                                        <div>
                                            <h5 class="text-sm text-gray-500 dark:text-gray-400">Repository Tags</h5>
                                            <div class="flex flex-wrap gap-2">
                                                @if (is_array($imageDetails['RepoTags'] ?? null))
                                                    @foreach ($imageDetails['RepoTags'] as $tag)
                                                        <span
                                                            class="inline-block px-2 py-1 text-sm bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300 rounded-full">
                                                            {{ $tag }}
                                                        </span>
                                                    @endforeach
                                                @elseif($imageDetails['RepoTags'] ?? null)
                                                    <span
                                                        class="inline-block px-2 py-1 text-sm bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300 rounded-full">
                                                        {{ $imageDetails['RepoTags'] }}
                                                    </span>
                                                @else
                                                    <span class="text-sm text-gray-500">No tags</span>
                                                @endif
                                            </div>
                                        </div>
                                        <div>
                                            <h5 class="text-sm text-gray-500 dark:text-gray-400">Repository Digests
                                            </h5>
                                            <div class="space-y-1">
                                                @foreach ($imageDetails['RepoDigests'] ?? [] as $digest)
                                                    <div class="font-mono text-sm break-all">{{ $digest }}</div>
                                                @endforeach
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="space-y-4">
                                {{-- Configuration --}}
                                @if (isset($imageDetails['Config']))
                                    <div class="bg-white dark:bg-gray-700 rounded-lg p-3 sm:p-4">
                                        <h4 class="font-semibold mb-2">Configuration</h4>
                                        <dl class="space-y-2">
                                            {{-- Exposed Ports --}}
                                            @if (isset($imageDetails['Config']['ExposedPorts']))
                                                <div>
                                                    <dt class="text-sm text-gray-500 dark:text-gray-400">Exposed Ports
                                                    </dt>
                                                    <dd class="flex flex-wrap gap-1">
                                                        @foreach (array_keys($imageDetails['Config']['ExposedPorts']) as $port)
                                                            <span
                                                                class="inline-block px-2 py-1 text-sm bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300 rounded-full">
                                                                {{ $port }}
                                                            </span>
                                                        @endforeach
                                                    </dd>
                                                </div>
                                            @endif

                                            {{-- Command --}}
                                            @if (isset($imageDetails['Config']['Cmd']))
                                                <div>
                                                    <dt class="text-sm text-gray-500 dark:text-gray-400">Command</dt>
                                                    <dd class="font-mono text-sm break-all">
                                                        {{ implode(' ', $imageDetails['Config']['Cmd']) }}
                                                    </dd>
                                                </div>
                                            @endif

                                            {{-- Labels --}}
                                            @if (isset($imageDetails['Config']['Labels']))
                                                <div>
                                                    <dt class="text-sm text-gray-500 dark:text-gray-400">Labels</dt>
                                                    <dd class="space-y-1 max-h-48 overflow-y-auto">
                                                        @foreach ($imageDetails['Config']['Labels'] as $key => $value)
                                                            <div class="text-sm">
                                                                <span
                                                                    class="font-semibold break-all">{{ $key }}:</span>
                                                                <span
                                                                    class="font-mono break-all">{{ $value }}</span>
                                                            </div>
                                                        @endforeach
                                                    </dd>
                                                </div>
                                            @endif
                                        </dl>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>
