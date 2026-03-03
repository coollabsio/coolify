<div>
    <div class="h-full pt-6">
        <div class="flex flex-col">
            <div class="flex gap-2">
                <h2>Resources</h2>
            </div>
            <div class="pb-4 title">Here you can find all resources that are using this source.</div>
        </div>

        @if (!data_get($github_app, 'installation_id'))
            <div class="text-sm opacity-70">
                Install the GitHub App first to link resources.
            </div>
        @elseif ($applications->isEmpty())
            <div class="py-4 text-sm opacity-70">
                No resources are currently using this GitHub App.
            </div>
        @else
            <div class="flex flex-col">
                <div class="flex flex-col">
                    <div class="overflow-x-auto">
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
                                        @foreach ($applications->sortBy('name', SORT_NATURAL) as $resource)
                                            <tr>
                                                <td class="px-5 py-4 text-sm whitespace-nowrap">
                                                    {{ data_get($resource->project(), 'name') }}
                                                </td>
                                                <td class="px-5 py-4 text-sm whitespace-nowrap">
                                                    {{ data_get($resource, 'environment.name') }}
                                                </td>
                                                <td class="px-5 py-4 text-sm whitespace-nowrap">
                                                    <a class="" {{ wireNavigate() }} href="{{ $resource->link() }}">
                                                        {{ $resource->name }}
                                                        <x-internal-link />
                                                    </a>
                                                </td>
                                                <td class="px-5 py-4 text-sm whitespace-nowrap">
                                                    {{ str($resource->type())->headline() }}
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
        @endif
    </div>
</div>
