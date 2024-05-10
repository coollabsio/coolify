<div>
    <h2>Resource Operations</h2>
    <div class="pb-4">You can easily make different kind of operations on this resource.</div>
    <h3>Clone</h3>
    <div class="pb-4">To another project / environment on a different server.</div>
    <div class="pb-4">
        <div class="flex flex-col flex-wrap gap-2">
            @foreach ($servers->sortBy('id') as $server)
                <h5>Server: <span class="font-bold text-dark dark:text-white">{{ $server->name }}</span></h5>
                @foreach ($server->destinations() as $destination)
                    <x-modal-confirmation action="cloneTo({{ data_get($destination, 'id') }})">
                        <x:slot name="content">
                            <div class="box group">
                                <div class="flex flex-col">
                                    <div class="box-title">Network</div>
                                    <div class="box-description">{{ $destination->name }}</div>
                                </div>
                            </div>
                        </x:slot>
                        <div>You are about to clone this resource.</div>
                    </x-modal-confirmation>
                @endforeach
            @endforeach
        </div>
    </div>
    <h3>Move</h3>
    <div class="pb-4">Between projects / environments.</div>
    <div>
        <div class="pb-2">
            This resource is currently in the <span
                class="font-bold dark:text-warning">{{ $resource->environment->project->name }} /
                {{ $resource->environment->name }}</span> environment.
        </div>
        <div class="flex flex-col flex-wrap gap-2">
            @forelse ($projects as $project)
                <h5>Project: <span class="font-bold text-dark dark:text-white">{{ $project->name }}</span></h5>

                @foreach ($project->environments as $environment)
                    <x-modal-confirmation action="moveTo({{ data_get($environment, 'id') }})">
                        <x:slot name="content">
                            <div class="box group">
                                <div class="flex flex-col">
                                    <div class="box-title">Environment</div>
                                    <div class="box-description">{{ $environment->name }}</div>
                                </div>
                            </div>
                        </x:slot>
                        <div>You are about to move this resource.</div>
                    </x-modal-confirmation>
                @endforeach
            @empty
                <div>No projects found to move to</div>
            @endforelse
        </div>
    </div>
</div>
