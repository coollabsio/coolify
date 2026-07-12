<div>
    <div class="flex items-center gap-2">
        <h1>Destination</h1>
    </div>
    <div class="subtitle">Resources deployed to this Docker network.</div>

    @include('livewire.destination.navbar', ['destination' => $destination])

    <div class="pt-4" x-data="{ search: '' }">
        @if (count($resources) === 0)
            <div class="py-4 text-sm opacity-70">No resources are using this destination.</div>
        @else
            <x-forms.input placeholder="Search resources..." x-model="search" id="null" />
            <div class="overflow-x-auto pt-4">
                <div class="inline-block min-w-full">
                    <div class="overflow-hidden">
                        <table class="min-w-full">
                            <thead>
                                <tr>
                                    <th class="px-5 py-3 text-xs font-medium text-left uppercase">Project</th>
                                    <th class="px-5 py-3 text-xs font-medium text-left uppercase">Environment</th>
                                    <th class="px-5 py-3 text-xs font-medium text-left uppercase">Name</th>
                                    <th class="px-5 py-3 text-xs font-medium text-left uppercase">Type</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                @foreach ($resources as $row)
                                    <tr class="dark:hover:bg-coolgray-300 hover:bg-neutral-100"
                                        wire:key="destination-resource-{{ $row['type'] }}-{{ $row['uuid'] }}"
                                        x-show="search === '' || '{{ addslashes($row['search']) }}'.includes(search.toLowerCase())">
                                        <td class="px-5 py-4 text-sm whitespace-nowrap">{{ $row['project'] }}</td>
                                        <td class="px-5 py-4 text-sm whitespace-nowrap">{{ $row['environment'] }}</td>
                                        <td class="px-5 py-4 text-sm whitespace-nowrap">
                                            @if ($row['url'])
                                                <a {{ wireNavigate() }} href="{{ $row['url'] }}">
                                                    {{ $row['name'] }}
                                                    <x-internal-link />
                                                </a>
                                            @else
                                                <span>{{ $row['name'] }}</span>
                                            @endif
                                        </td>
                                        <td class="px-5 py-4 text-sm whitespace-nowrap">{{ ucfirst($row['type']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>
