<div>
    <x-server.navbar :server="$server" :parameters="$parameters" />
    <div class="flex flex-col">
        <div class="flex gap-2">
            <h2>Resources</h2>
            <x-forms.button wire:click="refreshStatus">Refresh</x-forms.button>
        </div>
        <div class="pb-4 title">Here you can find all resources for this server.</div>
    </div>
    <div class="flex flex-col">
        <div class="flex flex-col">
            <div class="overflow-x-auto">
                <div class="inline-block min-w-full">
                    <div class="overflow-hidden">
                        <table class="min-w-full divide-y divide-coolgray-400">
                            <thead>
                                <tr class="text-neutral-500">
                                    <th class="px-5 py-3 text-xs font-medium text-left uppercase">Project</th>
                                    <th class="px-5 py-3 text-xs font-medium text-left uppercase">Environment</th>
                                    <th class="px-5 py-3 text-xs font-medium text-left uppercase">Name</th>
                                    <th class="px-5 py-3 text-xs font-medium text-left uppercase">Type</th>
                                    <th class="px-5 py-3 text-xs font-medium text-left uppercase">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-coolgray-400">
                                @forelse ($server->definedResources()->sortBy('name',SORT_NATURAL) as $resource)
                                    <tr class="text-white bg-coolblack hover:bg-coolgray-100">
                                        <td class="px-5 py-4 text-sm whitespace-nowrap">
                                            {{ data_get($resource->project(), 'name') }}
                                        </td>
                                        <td class="px-5 py-4 text-sm whitespace-nowrap">
                                            {{ data_get($resource, 'environment.name') }}
                                        </td>
                                        <td class="px-5 py-4 text-sm whitespace-nowrap"><a class=""
                                                href="{{ $resource->link() }}">{{ $resource->name }} </a></td>
                                        <td class="px-5 py-4 text-sm whitespace-nowrap">
                                            {{ str($resource->type())->headline() }}</td>
                                        <td class="px-5 py-4 text-sm font-medium whitespace-nowrap">
                                            @if ($resource->type() === 'service')
                                                <x-status.services :service="$resource" :showRefreshButton="false" />
                                            @else
                                                <x-status.index :status="$resource->status" :showRefreshButton="false" />
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
</div>
