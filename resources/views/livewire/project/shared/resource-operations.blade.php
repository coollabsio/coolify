<div>
    <h2>Resource Operations</h2>
    <div class="pb-4">You can easily make different kind of operations on this resource.</div>
    <h4>Clone</h4>
    <div class="pb-8">
        <div class="pb-8">
            Clone this resource to another project / environment.
        </div>
        <div class="flex flex-col gap-4">
            @foreach ($servers->sortBy('id') as $server)
                <div>
                    <div class="grid grid-cols-1 gap-2 pb-4 lg:grid-cols-4">
                        @foreach ($server->destinations() as $destination)
                            <x-new-modal action="cloneTo({{ data_get($destination, 'id') }})">
                                <x:slot name="content">
                                    <div class="flex flex-col gap-2 box">
                                        <div class="font-bold text-white">{{ $server->name }}</div>
                                        <div>{{ $destination->name }}</div>
                                    </div>
                                </x:slot>
                                <div>You are about to clone this resource.</div>
                            </x-new-modal>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </div>
    <h4>Move</h4>
    <div>
        <div class="pb-8">
            This resource is currently in the <span
                class="font-bold text-warning">{{ $resource->environment->project->name }} /
                {{ $resource->environment->name }}</span> environment.
        </div>
        <div class="grid gap-4">
            @forelse ($projects as $project)
                <div class="flex flex-row flex-wrap gap-2">
                    @foreach ($project->environments as $environment)
                    <x-new-modal action="moveTo({{ data_get($environment, 'id') }})">
                        <x:slot name="content">
                            <div class="flex flex-col gap-2 box">
                                <div class="font-bold text-white">{{ $project->name }}</div>
                                <div><span class="text-warning">{{ $environment->name }}</span> environment</div>
                            </div>
                        </x:slot>
                        <div>You are about to move this resource.</div>
                    </x-new-modal>
                    @endforeach
                </div>
            @empty
                <div>No projects found to move to</div>
            @endforelse
        </div>
    </div>
</div>
