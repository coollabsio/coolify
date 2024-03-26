<div>
    <h2>Resource Operations</h2>
    <div class="pb-4">You can easily make different kind of operations on this resource.</div>
    <h4>Clone</h4>
    <div class="pb-8">
        <div class="pb-2">
            Clone this resource to another project / environment.
        </div>
        <div class="flex flex-col">
            @foreach ($servers->sortBy('id') as $server)
                <div>
                    <div class="grid grid-cols-1 gap-2 pb-4 lg:grid-cols-4">
                        @foreach ($server->destinations() as $destination)
                            <x-modal-confirmation action="cloneTo({{ data_get($destination, 'id') }})">
                                <x:slot name="content">
                                    <div class="box">
                                        <div class="flex flex-col">
                                            <div class="box-title">{{ $server->name }}</div>
                                            <div class="box-description">{{ $destination->name }}</div>
                                        </div>
                                    </div>
                                </x:slot>
                                <div>You are about to clone this resource.</div>
                            </x-modal-confirmation>
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
                class="font-bold dark:text-warning">{{ $resource->environment->project->name }} /
                {{ $resource->environment->name }}</span> environment.
        </div>
        <div class="flex flex-wrap gap-2">
            @forelse ($projects as $project)
                <div class="flex flex-wrap gap-2">
                    @foreach ($project->environments as $environment)
                        <x-modal-confirmation action="moveTo({{ data_get($environment, 'id') }})">
                            <x:slot name="content">
                                <div class="box">
                                    <div class="flex flex-col">
                                        <div class="box-title">{{ $project->name }}</div>
                                        <div class="box-description">environment: {{ $environment->name }}
                                        </div>
                                    </div>
                                </div>
                            </x:slot>
                            <div>You are about to move this resource.</div>
                        </x-modal-confirmation>
                    @endforeach
                </div>
            @empty
                <div>No projects found to move to</div>
            @endforelse
        </div>
    </div>
</div>
