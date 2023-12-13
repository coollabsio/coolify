<form wire:submit='clone'>
    <div class="flex flex-col">
        <h1>Clone</h1>
        <div class="subtitle ">Quickly clone all resources to a new project</div>
    </div>
    <div class="flex items-end gap-2">
        <x-forms.input required id="newProjectName" label="New Project Name" />
        <x-forms.button type="submit">Clone</x-forms.button>
    </div>
    <h3 class="pt-4 pb-2">Servers</h3>
    <div class="flex flex-col gap-4">
        @foreach ($servers->sortBy('id') as $server)
            <div class="p-4 border border-coolgray-500">
                <h3>{{ $server->name }}</h3>
                <h5>{{ $server->description }}</h5>
                <div class="pt-4 pb-2">Docker Networks</div>
                <div class="grid grid-cols-1 gap-2 pb-4 lg:grid-cols-4">
                    @foreach ($server->destinations() as $destination)
                        <div class="cursor-pointer box-without-bg bg-coolgray-200 group"
                            :class="'{{ $selectedDestination === $destination->id }}' && 'bg-coollabs text-white'"
                            wire:click="selectServer('{{ $server->id }}', '{{ $destination->id }}')">
                            {{ $destination->name }}
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>

    <h3 class="pt-4 pb-2">Resources</h3>
    <div class="grid grid-cols-1 gap-2 p-4 border border-coolgray-500">
        @foreach ($environment->applications->sortBy('name') as $application)
            <div>
                <div class="flex flex-col">
                    <div class="font-bold text-white">{{ $application->name }}</div>
                    <div class="description">{{ $application->description }}</div>
                </div>
            </div>
        @endforeach
        @foreach ($environment->databases()->sortBy('name') as $database)
            <div>
                <div class="flex flex-col">
                    <div class="font-bold text-white">{{ $database->name }}</div>
                    <div class="description">{{ $database->description }}</div>
                </div>
            </div>
        @endforeach
        @foreach ($environment->services->sortBy('name') as $service)
            <div>
                <div class="flex flex-col">
                    <div class="font-bold text-white">{{ $service->name }}</div>
                    <div class="description">{{ $service->description }}</div>
                </div>
            </div>
        @endforeach
    </div>
</form>
