<div>
    <div class="space-y-4">
        <x-images.navbar />

        <div class="flex items-center justify-between gap-2">
            <div class="flex items-center gap-2">
                <h2>Docker Images</h2>
                <form class="flex items-center gap-2" wire:submit="loadServerImages">
                    <x-forms.select id="server" required wire:model.live="selected_uuid">
                        @foreach ($servers as $server)
                            @if ($loop->first)
                                <option disabled value="default">Select a server</option>
                            @endif
                            <option value="{{ $server->uuid }}">{{ $server->name }}</option>
                        @endforeach
                    </x-forms.select>
                    <x-forms.button type="submit" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="loadServerImages">Refresh</span>
                        <span wire:loading wire:target="loadServerImages">Loading...</span>
                    </x-forms.button>
                </form>
            </div>
        </div>

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
                                        Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                {{-- TODO: Implement image listing logic --}}
                                {{-- Example row structure:
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-800">
                                    <td class="px-6 py-4 whitespace-nowrap">repository_name</td>
                                    <td class="px-6 py-4 whitespace-nowrap">tag</td>
                                    <td class="px-6 py-4 whitespace-nowrap font-mono text-sm">image_id</td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex gap-2">
                                            <x-forms.button>Details</x-forms.button>
                                        </div>
                                    </td>
                                </tr>
                                --}}
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
    </div>
</div>
