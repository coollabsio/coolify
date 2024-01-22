<div>
    <h2>Move Resource</h2>
    <div class="pb-4">You can easily move this resource to another project.</div>
    <div>
        <div class="pb-8">
            This resource is currently in the <span
                class="font-bold text-warning">{{ $resource->environment->project->name }} /
                {{ $resource->environment->name }}</span> environment.
        </div>
        <div class="grid grid-flow-col grid-rows-2 gap-4">
            @forelse ($projects as  $project)
                <div class="grid grid-flow-col grid-rows-2 gap-4">
                    @foreach ($project->environments as $environment)
                        <div class="flex flex-col gap-2 box" wire:click="moveTo('{{ data_get($environment, 'id') }}')">
                            <div class="font-bold text-white">{{ $project->name }}</div>
                            <div><span class="text-warning">{{ $environment->name }}</span> environment</div>
                        </div>
                    @endforeach
                </div>
            @empty
                <div>No projects found to move to</div>
            @endforelse
        </div>
    </div>
</div>
