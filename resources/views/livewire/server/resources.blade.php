<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Server Resources | Coolify
    </x-slot>
    <livewire:server.navbar :server="$server" />
    <div x-data="{ activeTab: 'managed' }" class="flex flex-col h-full gap-8 md:flex-row">
        <div class="w-full">
            <div class="flex flex-col">
                <div class="flex gap-2">
                    <h2>{{ __('server.resources') }}</h2>
                    <x-forms.button wire:click="refreshStatus">{{ __('common.refresh') }}</x-forms.button>
                </div>
                <div>{{ __('server.resources_managed_desc') }}</div>
                <div class="flex flex-row gap-4 py-10">
                    <div @class([
                        'box-without-bg cursor-pointer dark:bg-coolgray-100 dark:text-white w-full text-center items-center justify-center',
                        'dark:bg-coollabs bg-coollabs text-white' => $activeTab === 'managed',
                    ]) wire:click="loadManagedContainers">
                        {{ __('server.managed') }}
                        <div class="flex flex-col items-center justify-center">
                            <x-loading wire:loading wire:target="loadManagedContainers" />
                        </div>
                    </div>
                    <div @class([
                        'box-without-bg cursor-pointer dark:bg-coolgray-100 dark:text-white w-full text-center items-center justify-center',
                        'dark:bg-coollabs bg-coollabs text-white' => $activeTab === 'unmanaged',
                    ]) wire:click="loadUnmanagedContainers">
                        {{ __('server.unmanaged') }}
                        <div class="flex flex-col items-center justify-center">
                            <x-loading wire:loading wire:target="loadUnmanagedContainers" />
                        </div>
                    </div>
                </div>
            </div>
            @if ($activeTab === 'managed')
                @php
                    $managedResources = $server->definedResources()->sortBy('name', SORT_NATURAL);
                @endphp
                @if ($managedResources->count() > 0)
                    <div class="flex flex-col">
                        <div class="flex flex-col">
                            <div class="overflow-x-auto">
                                <div class="inline-block min-w-full">
                                    <div class="overflow-hidden">
                                        <table class="min-w-full">
                                            <thead>
                                                <tr>
                                                    <th class="px-5 py-3 text-xs font-medium text-left uppercase">
                                                        {{ __('server.project') }}
                                                    </th>
                                                    <th class="px-5 py-3 text-xs font-medium text-left uppercase">
                                                        {{ __('server.environment') }}</th>
                                                    <th class="px-5 py-3 text-xs font-medium text-left uppercase">
                                                        {{ __('server.name') }}
                                                    </th>
                                                    <th class="px-5 py-3 text-xs font-medium text-left uppercase">
                                                        {{ __('server.type') }}
                                                    </th>
                                                    <th class="px-5 py-3 text-xs font-medium text-left uppercase">
                                                        {{ __('server.status') }}
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach ($managedResources as $resource)
                                                    <tr>
                                                        <td class="px-5 py-4 text-sm whitespace-nowrap">
                                                            {{ data_get($resource->project(), 'name') }}
                                                        </td>
                                                        <td class="px-5 py-4 text-sm whitespace-nowrap">
                                                            {{ data_get($resource, 'environment.name') }}
                                                        </td>
                                                        <td class="px-5 py-4 text-sm whitespace-nowrap hover:underline">
                                                            <a class="" {{ wireNavigate() }}
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
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                @else
                    <div>{{ __('server.no_managed_resources') }}</div>
                @endif
            @elseif ($activeTab === 'unmanaged')
                @if (count($unmanagedContainers) > 0)
                    <div class="flex flex-col">
                        <div class="flex flex-col">
                            <div class="overflow-x-auto">
                                <div class="inline-block min-w-full">
                                    <div class="overflow-hidden">
                                        <table class="min-w-full">
                                            <thead>
                                                <tr>
                                                    <th class="px-5 py-3 text-xs font-medium text-left uppercase">
                                                        {{ __('server.name') }}
                                                    </th>
                                                    <th class="px-5 py-3 text-xs font-medium text-left uppercase">
                                                        {{ __('server.image') }}
                                                    </th>
                                                    <th class="px-5 py-3 text-xs font-medium text-left uppercase">
                                                        {{ __('server.status') }}
                                                    </th>
                                                    <th class="px-5 py-3 text-xs font-medium text-left uppercase">
                                                        {{ __('server.action') }}
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach (collect($unmanagedContainers)->sortBy('name', SORT_NATURAL) as $resource)
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
                                                                    wire:key="{{ data_get($resource, 'ID') }}">{{ __('common.restart') }}</x-forms.button>
                                                                <x-forms.button isError
                                                                    wire:click="stopUnmanaged('{{ data_get($resource, 'ID') }}')"
                                                                    wire:key="{{ data_get($resource, 'ID') }}">{{ __('common.stop') }}</x-forms.button>
                                                            @elseif (data_get($resource, 'State') === 'exited')
                                                                <x-forms.button
                                                                    wire:click="startUnmanaged('{{ data_get($resource, 'ID') }}')"
                                                                    wire:key="{{ data_get($resource, 'ID') }}">{{ __('common.start') }}</x-forms.button>
                                                            @elseif (data_get($resource, 'State') === 'restarting')
                                                                <x-forms.button
                                                                    wire:click="stopUnmanaged('{{ data_get($resource, 'ID') }}')"
                                                                    wire:key="{{ data_get($resource, 'ID') }}">{{ __('common.stop') }}</x-forms.button>
                                                            @endif
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                @else
                    <div>{{ __('server.no_unmanaged_resources') }}</div>
                @endif
            @endif
        </div>
    </div>
</div>
