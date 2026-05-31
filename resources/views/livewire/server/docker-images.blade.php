<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Docker Images | Coolify
    </x-slot>
    <livewire:server.navbar :server="$server" />
    <div class="flex flex-col h-full gap-8 sm:flex-row">
        <x-server.sidebar :server="$server" activeMenu="docker-images" />
        <div class="w-full">
            <div class="flex flex-col gap-2">
                <div class="flex items-center gap-2">
                    <h2>Docker Images</h2>
                    <x-forms.button wire:click="loadImages">Refresh</x-forms.button>
                </div>
                <div>Review local Docker images on this server and remove unused images manually.</div>
            </div>

            <div class="grid gap-2 py-6 sm:grid-cols-2 xl:grid-cols-4">
                <div class="box-without-bg">
                    <div class="text-xs uppercase text-neutral-500">Total</div>
                    <div class="text-2xl font-semibold">{{ $this->imageSummary['total'] }}</div>
                </div>
                <div class="box-without-bg">
                    <div class="text-xs uppercase text-neutral-500">In use</div>
                    <div class="text-2xl font-semibold">{{ $this->imageSummary['used'] }}</div>
                </div>
                <div class="box-without-bg">
                    <div class="text-xs uppercase text-neutral-500">Unused</div>
                    <div class="text-2xl font-semibold">{{ $this->imageSummary['unused'] }}</div>
                </div>
                <div class="box-without-bg">
                    <div class="text-xs uppercase text-neutral-500">Dangling</div>
                    <div class="text-2xl font-semibold">{{ $this->imageSummary['dangling'] }}</div>
                </div>
            </div>

            <div class="flex flex-col gap-2 pb-6 xl:flex-row">
                <x-forms.input id="search" label="Search" placeholder="Repository, tag, digest, or image ID" />
                <x-forms.select id="filter" label="Filter">
                    <option value="all">All images</option>
                    <option value="used">In use</option>
                    <option value="unused">Unused</option>
                    <option value="dangling">Dangling</option>
                </x-forms.select>
            </div>

            <div wire:loading wire:target="loadImages,removeImage" class="pb-4">
                Loading Docker images...
            </div>

            @if ($this->filteredImages->count() > 0)
                <div class="overflow-x-auto">
                    <table class="min-w-full">
                        <thead>
                            <tr>
                                <th class="px-5 py-3 text-xs font-medium text-left uppercase">Repository</th>
                                <th class="px-5 py-3 text-xs font-medium text-left uppercase">Tag</th>
                                <th class="px-5 py-3 text-xs font-medium text-left uppercase">Image ID</th>
                                <th class="px-5 py-3 text-xs font-medium text-left uppercase">Created</th>
                                <th class="px-5 py-3 text-xs font-medium text-left uppercase">Size</th>
                                <th class="px-5 py-3 text-xs font-medium text-left uppercase">Status</th>
                                <th class="px-5 py-3 text-xs font-medium text-left uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->filteredImages as $image)
                                <tr wire:key="docker-image-{{ data_get($image, 'id') }}">
                                    <td class="px-5 py-4 text-sm whitespace-nowrap">
                                        {{ data_get($image, 'repository') ?? '<none>' }}
                                        @if (data_get($image, 'digest'))
                                            <div class="text-xs text-neutral-500">{{ data_get($image, 'digest') }}</div>
                                        @endif
                                    </td>
                                    <td class="px-5 py-4 text-sm whitespace-nowrap">
                                        {{ data_get($image, 'tag') ?? '<none>' }}
                                    </td>
                                    <td class="px-5 py-4 font-mono text-xs whitespace-nowrap">
                                        {{ str(data_get($image, 'id'))->limit(24) }}
                                    </td>
                                    <td class="px-5 py-4 text-sm whitespace-nowrap">
                                        {{ data_get($image, 'created_since') ?? data_get($image, 'created_at') ?? '-' }}
                                    </td>
                                    <td class="px-5 py-4 text-sm whitespace-nowrap">
                                        {{ data_get($image, 'size') ?? '-' }}
                                    </td>
                                    <td class="px-5 py-4 text-sm whitespace-nowrap">
                                        @if (data_get($image, 'in_use'))
                                            <span class="text-green-500">In use</span>
                                            @if (count(data_get($image, 'containers', [])) > 0)
                                                <div class="text-xs text-neutral-500">
                                                    @foreach (collect(data_get($image, 'containers', []))->take(2) as $container)
                                                        <div>{{ data_get($container, 'name') }} ({{ data_get($container, 'state') }})</div>
                                                    @endforeach
                                                    @if (count(data_get($image, 'containers', [])) > 2)
                                                        <div>+{{ count(data_get($image, 'containers', [])) - 2 }} more</div>
                                                    @endif
                                                </div>
                                            @endif
                                        @elseif (data_get($image, 'dangling'))
                                            <span class="text-warning">Dangling</span>
                                        @else
                                            <span>Unused</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-4 text-sm whitespace-nowrap">
                                        @if (data_get($image, 'in_use'))
                                            <x-forms.button disabled isError>Delete</x-forms.button>
                                        @else
                                            <x-forms.button isError
                                                wire:click="removeImage('{{ data_get($image, 'id') }}')"
                                                wire:confirm="Delete this unused Docker image from the server?">
                                                Delete
                                            </x-forms.button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div>No Docker images found.</div>
            @endif
        </div>
    </div>
</div>
