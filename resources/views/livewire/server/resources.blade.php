<div>
    <x-server.navbar :server="$server" :parameters="$parameters" />
    @if ($server->isFunctional())
        <div x-data="{ activeTab: window.location.hash ? window.location.hash.substring(1) : 'managed' }" class="flex h-full">
            <div class="flex flex-col gap-4">
                <a :class="activeTab === 'managed' && 'dark:text-white'"
                    @click.prevent="activeTab = 'managed'; window.location.hash = 'managed'" href="#">Managed</a>
                <a :class="activeTab === 'unmanaged' && 'dark:text-white'"
                    @click.prevent="activeTab = 'unmanaged'; window.location.hash = 'unmanaged'"
                    href="#">Unmanaged</a>
            </div>
            <div class="w-full pl-8">
                <div x-cloak x-show="activeTab === 'managed'" class="h-full">
                    <div class="flex flex-col">
                        <div class="flex gap-2">
                            <h2>Resources</h2>
                            <x-forms.button wire:click="refreshStatus">Refresh</x-forms.button>
                        </div>
                        <div class="subtitle">Here you can find all resources that are managed by Coolify.</div>
                    </div>
                    @if ($server->definedResources()->count() > 0)
                        <div class="flex flex-col">
                            <div class="flex flex-col">
                                <div class="overflow-x-auto">
                                    <div class="inline-block min-w-full">
                                        <div class="overflow-hidden">
                                            <table class="min-w-full divide-y divide-coolgray-400">
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
                                                <tbody class="divide-y divide-coolgray-400">
                                                    @forelse ($server->definedResources()->sortBy('name',SORT_NATURAL) as $resource)
                                                        <tr class="dark:text-white bg-coolblack hover:bg-coolgray-100">
                                                            <td class="px-5 py-4 text-sm whitespace-nowrap">
                                                                {{ data_get($resource->project(), 'name') }}
                                                            </td>
                                                            <td class="px-5 py-4 text-sm whitespace-nowrap">
                                                                {{ data_get($resource, 'environment.name') }}
                                                            </td>
                                                            <td class="px-5 py-4 text-sm whitespace-nowrap"><a
                                                                    class=""
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
                                                                    <x-status.index :resource="$resource"
                                                                        :showRefreshButton="false" />
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
                    @else
                        <div>No resources found.</div>
                    @endif
                </div>
                <div x-cloak x-show="activeTab === 'unmanaged'" class="h-full">
                    <div class="flex flex-col" x-init="$wire.loadUnmanagedContainers()">
                        <div class="flex gap-2">
                            <h2>Resources</h2>
                            <x-forms.button wire:click="refreshStatus">Refresh</x-forms.button>
                        </div>
                        <div class="subtitle">Here you can find all other containers running on the server.</div>
                    </div>
                    @if ($unmanagedContainers->count() > 0)
                        <div class="flex flex-col">
                            <div class="flex flex-col">
                                <div class="overflow-x-auto">
                                    <div class="inline-block min-w-full">
                                        <div class="overflow-hidden">
                                            <table class="min-w-full divide-y divide-coolgray-400">
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
                                                <tbody class="divide-y divide-coolgray-400">
                                                    @forelse ($unmanagedContainers->sortBy('name',SORT_NATURAL) as $resource)
                                                        <tr class="dark:text-white bg-coolblack hover:bg-coolgray-100">
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
                        </div>
                    @else
                        <div>No resources found.</div>
                    @endif
                </div>
            </div>
        </div>
    @else
        <div>Server is not validated. Validate first.</div>
    @endif
</div>
