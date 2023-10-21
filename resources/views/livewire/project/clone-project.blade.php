<form wire:submit.prevent='clone'>
    <div class="flex flex-col">
        <div class="flex gap-2">
            <h1>Clone</h1>
            <x-forms.button type="submit">Clone to a New Project</x-forms.button>
        </div>
        <div class="subtitle ">Quickly clone a project</div>
    </div>
    <x-forms.input required id="newProjectName" label="New Project Name" />
    <h3 class="pt-4 pb-2">Servers</h3>
    <div class="grid gap-2 lg:grid-cols-3">
        @foreach ($servers as $srv)
            <div wire:click="selectServer('{{ $srv->id }}')"
                class="cursor-pointer box-without-bg bg-coolgray-200 group"
                :class="'{{ $selectedServer === $srv->id }}' && 'bg-coollabs'">
                <div class="flex flex-col mx-6">
                    <div :class="'{{ $selectedServer === $srv->id }}' && 'text-white'"> {{ $srv->name }}</div>
                    @isset($selectedServer)
                        <div :class="'{{ $selectedServer === $srv->id }}' && 'text-white pt-2 text-xs font-bold'">
                            {{ $srv->description }}</div>
                    @else
                        <div class="description">
                            {{ $srv->description }}</div>
                    @endisset

                </div>
            </div>
        @endforeach
    </div>
    <h3 class="pt-4 pb-2">Resources</h3>
    <div class="grid grid-cols-1 gap-2">
        @foreach ($environment->applications->sortBy('name') as $application)
            <div class="p-2 border border-coolgray-200">
                <div class="flex flex-col">
                    <div class="font-bold text-white">{{ $application->name }}</div>
                    <div class="description">{{ $application->description }}</div>
                </div>
            </div>
        @endforeach
        @foreach ($environment->databases()->sortBy('name') as $database)
            <div class="p-2 border border-coolgray-200">
                <div class="flex flex-col">
                    <div class="font-bold text-white">{{ $database->name }}</div>
                    <div class="description">{{ $database->description }}</div>
                </div>
            </div>
        @endforeach
        @foreach ($environment->services->sortBy('name') as $service)
            <div class="p-2 border border-coolgray-200">
                <div class="flex flex-col">
                    <div class="font-bold text-white">{{ $service->name }}</div>
                    <div class="description">{{ $service->description }}</div>
                </div>
            </div>
        @endforeach
    </div>
</form>
