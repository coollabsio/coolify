<form>
    <x-slot:title>
        {{ data_get_str($project, 'name')->limit(10) }} > Clone | Coolify
    </x-slot>
    <div class="flex flex-col">
        <h1>Clone</h1>
        <div class="subtitle ">Quickly clone all resources to a new project or environment.</div>
    </div>
    <x-forms.input required id="newName" label="New Name" />
    <x-forms.button isHighlighted wire:click="clone('project')" class="mt-4">Clone to a new Project</x-forms.button>
    <x-forms.button isHighlighted wire:click="clone('environment')" class="mt-4">Clone to a new Environment</x-forms.button>
    <h3 class="pt-4 pb-2">Servers</h3>
    <div>Choose the server and network to clone the resources to.</div>
    <div class="flex flex-col gap-4">
        @foreach ($servers->sortBy('id') as $server)
            <div class="p-4">
                <h4>{{ $server->name }}</h4>
                <div class="pt-4 pb-2">Docker Networks</div>
                <div class="grid grid-cols-1 gap-2 pb-4 lg:grid-cols-4">
                    @foreach ($server->destinations() as $destination)
                        <div class="cursor-pointer box-without-bg group"
                            :class="'{{ $selectedDestination === $destination->id }}' ? 'bg-coollabs text-white' : 'dark:bg-coolgray-100 bg-white'"
                            wire:click="selectServer('{{ $server->id }}', '{{ $destination->id }}')">
                            {{ $destination->name }}
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>

    <h3 class="pt-4 pb-2">Resources</h3>
    <div>These will be cloned to the new project</div>
    <div class="grid grid-cols-1 gap-2 pt-4 opacity-95 lg:grid-cols-2 xl:grid-cols-3">
        @foreach ($environment->applications->sortBy('name') as $application)
            <div class="bg-white cursor-default box-without-bg dark:bg-coolgray-100 group">
                <div class="flex flex-col">
                    <div class="font-bold dark:text-white">{{ $application->name }}</div>
                    <div class="description">{{ $application->description }}</div>
                </div>
            </div>
        @endforeach
        @foreach ($environment->databases()->sortBy('name') as $database)
            <div class="bg-white cursor-default box-without-bg dark:bg-coolgray-100 group">
                <div class="flex flex-col">
                    <div class="font-bold dark:text-white">{{ $database->name }}</div>
                    <div class="description">{{ $database->description }}</div>
                </div>
            </div>
        @endforeach
        @foreach ($environment->services->sortBy('name') as $service)
            <div class="bg-white cursor-default box-without-bg dark:bg-coolgray-100 group">
                <div class="flex flex-col">
                    <div class="font-bold dark:text-white">{{ $service->name }}</div>
                    <div class="description">{{ $service->description }}</div>
                </div>
            </div>
        @endforeach
    </div>
</form>
