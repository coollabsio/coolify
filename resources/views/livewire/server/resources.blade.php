<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Server Resources | Coolify
    </x-slot>
    <x-server.navbar :server="$server" :parameters="$parameters" />
    <div x-data="{ activeTab: 'managed' }" class="flex flex-col h-full gap-8 md:flex-row">
        <div class="w-full">
            <div class="flex flex-col">
                <div class="flex gap-2">
                    <h2>Resources</h2>
                    <x-forms.button wire:click="refreshStatus">Refresh</x-forms.button>
                </div>
                <div>Here you can find all resources that are managed by Coolify.</div>
                <div class="flex flex-row gap-4 py-10">
                    <div @class([
                        'box-without-bg cursor-pointer bg-coolgray-100 text-white w-full text-center items-center justify-center',
                        'bg-coollabs' => $activeTab === 'managed',
                    ]) wire:click="loadManagedContainers">
                        Managed
                        <div class="flex flex-col items-center justify-center">
                            <x-loading wire:loading wire:target="loadManagedContainers" />
                        </div>
                    </div>
                    <div @class([
                        'box-without-bg cursor-pointer bg-coolgray-100 text-white w-full text-center items-center justify-center',
                        'bg-coollabs' => $activeTab === 'unmanaged',
                    ]) wire:click="loadUnmanagedContainers">
                        Unmanaged
                        <div class="flex flex-col items-center justify-center">
                            <x-loading wire:loading wire:target="loadUnmanagedContainers" />
                        </div>
                    </div>
                </div>
            </div>
            @if ($containers->count() > 0)
                @if ($activeTab === 'managed')
                    <div class="flex flex-col">
                        <div class="flex flex-col">
                            <div class="overflow-x-auto">
                                <div class="inline-block min-w-full">
                                    <div class="overflow-hidden">
                                        <table class="min-w-full">
                                            <thead>
                                                <tr>
                                                    <th class="px-5 py-3 text-xs font-medium text-left uppercase">
                                                        Project
                                                    </th>
                                                    <th class="px-5 py-3 text-xs font-medium text-left uppercase">
                                                        Environment</th>
                                                    <th class="px-5 py-3 text-xs font-medium text-left uppercase">
                                                        Name
                                                    </th>
                                                    <th class="px-5 py-3 text-xs font-medium text-left uppercase">
                                                        Type
                                                    </th>
                                                    <th class="px-5 py-3 text-xs font-medium text-left uppercase">
                                                        Status
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @forelse ($server->definedResources()->sortBy('name',SORT_NATURAL) as $resource)
                                                    <tr>
                                                        <td class="px-5 py-4 text-sm whitespace-nowrap">
                                                            {{ data_get($resource->project(), 'name') }}
                                                        </td>
                                                        <td class="px-5 py-4 text-sm whitespace-nowrap">
                                                            {{ data_get($resource, 'environment.name') }}
                                                        </td>
                                                        <td class="px-5 py-4 text-sm whitespace-nowrap hover:underline">
                                                            <a class=""
                                                                href="{{ $resource->link() }}">{{ $resource->name }}
                                                                <x-internal-link /></a>
                                                        </td>
                                                        <td class="px-5 py-4 text-sm whitespace-nowrap">
                                                            {{ str($resource->type())->headline() }}</td>
                                                        <td class="px-5 py-4 text-sm font-medium whitespace-nowrap">
                                                            @if ($resource->type() === 'service')
                                                                <x-status.services :service="$resource"
                                                                    :showRefreshButton="false" />
                                                            @else
                                                                <x-status.index :resource="$resource" :showRefreshButton="false" />
                                                            @endif
                                                        </td>
                                                    </tr>
                                                @empty
                                                @endforelse
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                @elseif ($activeTab === 'unmanaged')
                    <div class="flex flex-col">
                        <div class="flex flex-col">
                            <div class="overflow-x-auto">
                                <div class="inline-block min-w-full">
                                    <div class="overflow-hidden">
                                        <table class="min-w-full">
                                            <thead>
                                                <tr>
                                                    <th class="px-5 py-3 text-xs font-medium text-left uppercase">
                                                        Name
                                                    </th>
                                                    <th class="px-5 py-3 text-xs font-medium text-left uppercase">
                                                        Image
                                                    </th>
                                                    <th class="px-5 py-3 text-xs font-medium text-left uppercase">
                                                        Status
                                                    </th>
                                                    <th class="px-5 py-3 text-xs font-medium text-left uppercase">
                                                        Action
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @forelse ($containers->sortBy('name',SORT_NATURAL) as $resource)
                                                    <tr>
                                                        <td class="px-5 py-4 text-sm whitespace-nowrap">
                                                            {{ data_get($resource, 'Names') }}
                                                        </td>
                                                        <td class="px-5 py-4 text-sm whitespace-nowrap">
                                                            {{ data_get($resource, 'Image') }}
                                                        </td>
                                                        <td class="px-5 py-4 text-sm whitespace-nowrap">
                                                            {{ data_get($resource, 'State') }}
                                                        </td>
                                                        <td class="flex gap-2 px-5 py-4 text-sm whitespace-nowrap">
                                                            @if (data_get($resource, 'State') === 'running')
                                                                <x-forms.button
                                                                    wire:click="restartUnmanaged('{{ data_get($resource, 'ID') }}')"
                                                                    wire:key="{{ data_get($resource, 'ID') }}">Restart</x-forms.button>
                                                                <x-forms.button isError
                                                                    wire:click="stopUnmanaged('{{ data_get($resource, 'ID') }}')"
                                                                    wire:key="{{ data_get($resource, 'ID') }}">Stop</x-forms.button>
                                                            @elseif (data_get($resource, 'State') === 'exited')
                                                                <x-forms.button
                                                                    wire:click="startUnmanaged('{{ data_get($resource, 'ID') }}')"
                                                                    wire:key="{{ data_get($resource, 'ID') }}">Start</x-forms.button>
                                                            @elseif (data_get($resource, 'State') === 'restarting')
                                                                <x-forms.button
                                                                    wire:click="stopUnmanaged('{{ data_get($resource, 'ID') }}')"
                                                                    wire:key="{{ data_get($resource, 'ID') }}">Stop</x-forms.button>
                                                            @endif
                                                        </td>
                                                    </tr>
                                                @empty
                                                @endforelse
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                @endif
            @else
                @if ($activeTab === 'managed')
                    <div>No managed resources found.</div>
                @elseif ($activeTab === 'unmanaged')
                    <div>No unmanaged resources found.</div>
                @endif
            @endif
        </div>
    </div>
</div>
