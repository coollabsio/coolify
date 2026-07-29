<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Server Resources | Coolify
    </x-slot>
    <livewire:server.navbar :server="$server" />
    <div x-data="{ activeTab: 'managed' }" class="flex flex-col h-full gap-4 md:gap-8 md:flex-row">
        <div class="w-full">
            <div class="flex flex-col">
                <div class="flex gap-2">
                    <h2>Resources</h2>
                    <x-forms.button wire:click="refreshStatus">Refresh</x-forms.button>
                </div>
                <div class="subtitle">Here you can find all resources that are managed by Coolify.</div>
                <div class="inline-flex gap-1 p-1 mt-6 mb-6 border rounded-xl border-neutral-200 dark:border-coolgray-300 bg-neutral-50 dark:bg-coolgray-200 w-fit">
                    <button type="button" wire:click="loadManagedContainers"
                        @class([
                            'flex items-center gap-2 px-4 py-2 text-sm font-medium rounded-lg cursor-pointer transition-colors',
                            'bg-coollabs text-white' => $activeTab === 'managed',
                            'text-neutral-500 dark:text-coolgray-500 hover:text-black dark:hover:text-white' => $activeTab !== 'managed',
                        ])>
                        Managed
                        <x-loading wire:loading wire:target="loadManagedContainers" />
                    </button>
                    <button type="button" wire:click="loadUnmanagedContainers"
                        @class([
                            'flex items-center gap-2 px-4 py-2 text-sm font-medium rounded-lg cursor-pointer transition-colors',
                            'bg-coollabs text-white' => $activeTab === 'unmanaged',
                            'text-neutral-500 dark:text-coolgray-500 hover:text-black dark:hover:text-white' => $activeTab !== 'unmanaged',
                        ])>
                        Unmanaged
                        <x-loading wire:loading wire:target="loadUnmanagedContainers" />
                    </button>
                </div>
            </div>
            @if ($activeTab === 'managed')
                @php
                    $managedResources = $server->definedResources()->sortBy('name', SORT_NATURAL);
                @endphp
                @if ($managedResources->count() > 0)
                    <div class="overflow-hidden border rounded-xl border-neutral-200 dark:border-coolgray-300">
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="text-xs text-left uppercase bg-neutral-50 dark:bg-coolgray-200 text-neutral-500 dark:text-coolgray-500">
                                        <th class="px-5 py-3 font-medium">Project</th>
                                        <th class="px-5 py-3 font-medium">Environment</th>
                                        <th class="px-5 py-3 font-medium">Name</th>
                                        <th class="px-5 py-3 font-medium">Type</th>
                                        <th class="px-5 py-3 font-medium">Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-neutral-100 dark:divide-coolgray-300">
                                    @foreach ($managedResources as $resource)
                                        <tr class="transition-colors hover:bg-neutral-50 dark:hover:bg-coolgray-200">
                                            <td class="px-5 py-3 whitespace-nowrap text-neutral-600 dark:text-coolgray-500">
                                                {{ data_get($resource->project(), 'name') }}
                                            </td>
                                            <td class="px-5 py-3 whitespace-nowrap text-neutral-600 dark:text-coolgray-500">
                                                {{ data_get($resource, 'environment.name') }}
                                            </td>
                                            <td class="px-5 py-3 font-medium whitespace-nowrap">
                                                <a class="text-black dark:text-white hover:text-coollabs hover:underline" {{ wireNavigate() }}
                                                    href="{{ $resource->link() }}">{{ $resource->name }}
                                                    <x-internal-link /></a>
                                            </td>
                                            <td class="px-5 py-3 whitespace-nowrap text-neutral-600 dark:text-coolgray-500">
                                                {{ str($resource->type())->headline() }}</td>
                                            <td class="px-5 py-3 text-sm font-medium whitespace-nowrap">
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
                @else
                    <div class="p-8 text-center border border-dashed rounded-xl border-neutral-300 dark:border-coolgray-300 text-neutral-500 dark:text-coolgray-500">
                        No managed resources found.
                    </div>
                @endif
            @elseif ($activeTab === 'unmanaged')
                @if (count($unmanagedContainers) > 0)
                    <div class="overflow-hidden border rounded-xl border-neutral-200 dark:border-coolgray-300">
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="text-xs text-left uppercase bg-neutral-50 dark:bg-coolgray-200 text-neutral-500 dark:text-coolgray-500">
                                        <th class="px-5 py-3 font-medium">Name</th>
                                        <th class="px-5 py-3 font-medium">Image</th>
                                        <th class="px-5 py-3 font-medium">Status</th>
                                        <th class="px-5 py-3 font-medium">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-neutral-100 dark:divide-coolgray-300">
                                    @foreach (collect($unmanagedContainers)->sortBy('name', SORT_NATURAL) as $resource)
                                        <tr class="transition-colors hover:bg-neutral-50 dark:hover:bg-coolgray-200">
                                            <td class="px-5 py-3 font-medium whitespace-nowrap text-black dark:text-white">
                                                {{ data_get($resource, 'Names') }}
                                            </td>
                                            <td class="px-5 py-3 font-mono text-xs whitespace-nowrap text-neutral-600 dark:text-coolgray-500">
                                                {{ data_get($resource, 'Image') }}
                                            </td>
                                            <td class="px-5 py-3 whitespace-nowrap text-neutral-600 dark:text-coolgray-500">
                                                {{ data_get($resource, 'State') }}
                                            </td>
                                            <td class="flex gap-2 px-5 py-3 whitespace-nowrap">
                                                    @if (data_get($resource, 'State') === 'running')
                                                        <x-forms.button canGate="update" :canResource="$server"
                                                            wire:click="restartUnmanaged('{{ data_get($resource, 'ID') }}')"
                                                            wire:key="{{ data_get($resource, 'ID') }}">Restart</x-forms.button>
                                                        <x-forms.button canGate="update" :canResource="$server" isError
                                                            wire:click="stopUnmanaged('{{ data_get($resource, 'ID') }}')"
                                                            wire:key="{{ data_get($resource, 'ID') }}">Stop</x-forms.button>
                                                    @elseif (data_get($resource, 'State') === 'exited')
                                                        <x-forms.button canGate="update" :canResource="$server"
                                                            wire:click="startUnmanaged('{{ data_get($resource, 'ID') }}')"
                                                            wire:key="{{ data_get($resource, 'ID') }}">Start</x-forms.button>
                                                    @elseif (data_get($resource, 'State') === 'restarting')
                                                        <x-forms.button canGate="update" :canResource="$server"
                                                            wire:click="stopUnmanaged('{{ data_get($resource, 'ID') }}')"
                                                            wire:key="{{ data_get($resource, 'ID') }}">Stop</x-forms.button>
                                                    @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @else
                    <div class="p-8 text-center border border-dashed rounded-xl border-neutral-300 dark:border-coolgray-300 text-neutral-500 dark:text-coolgray-500">
                        No unmanaged resources found.
                    </div>
                @endif
            @endif
        </div>
    </div>
</div>
