<div>
    <x-slot:title>
        Cliente {{ $client->name }} | Coolify
    </x-slot>

    <div class="flex items-center gap-2">
        <h1>Cliente: {{ $client->name }}</h1>
        <a class="underline dark:text-white" href="{{ route('clients.index') }}" {{ wireNavigate() }}>Volver</a>
    </div>
    <div class="subtitle">
        Asigna servidores/proyectos de tu equipo actual ({{ $sourceTeamId }}) a este cliente. El cliente solo verá lo
        que tenga asignado.
    </div>

    <div class="grid gap-8 lg:grid-cols-2">
        <div>
            <h2>Servidores asignados</h2>
            <div class="subtitle">Los servidores que este cliente ve en su sesión.</div>
            <div class="flex flex-col gap-2">
                @forelse ($assignedServers as $server)
                    <div class="flex items-center gap-3 p-4 border rounded-xl border-neutral-300 dark:border-coolgray-200">
                        <div class="flex flex-col">
                            <div class="font-semibold dark:text-white">{{ $server->name }}</div>
                            <div class="description">{{ $server->ip }}</div>
                        </div>
                        <div class="flex-1"></div>
                        <x-forms.button isErrorButton type="button" wire:click="removeServer({{ $server->id }})">
                            Quitar
                        </x-forms.button>
                    </div>
                @empty
                    <div>No hay servidores asignados.</div>
                @endforelse
            </div>
        </div>

        <div>
            <h2>Servidores disponibles</h2>
            <div class="subtitle">Servidores de tu equipo actual que puedes asignar.</div>
            <div class="flex flex-col gap-2">
                @forelse ($availableServers as $server)
                    <div class="flex items-center gap-3 p-4 border rounded-xl border-neutral-300 dark:border-coolgray-200">
                        <div class="flex flex-col">
                            <div class="font-semibold dark:text-white">{{ $server->name }}</div>
                            <div class="description">{{ $server->ip }}</div>
                        </div>
                        <div class="flex-1"></div>
                        <x-forms.button type="button" wire:click="assignServer({{ $server->id }})">
                            Asignar
                        </x-forms.button>
                    </div>
                @empty
                    <div>No hay servidores disponibles en tu equipo actual.</div>
                @endforelse
            </div>
        </div>

        <div>
            <h2>Proyectos asignados</h2>
            <div class="subtitle">Los proyectos que este cliente ve en su sesión.</div>
            <div class="flex flex-col gap-2">
                @forelse ($assignedProjects as $project)
                    <div class="flex items-center gap-3 p-4 border rounded-xl border-neutral-300 dark:border-coolgray-200">
                        <div class="flex flex-col">
                            <div class="font-semibold dark:text-white">{{ $project->name }}</div>
                            <div class="description">{{ $project->description }}</div>
                        </div>
                        <div class="flex-1"></div>
                        <x-forms.button isErrorButton type="button" wire:click="removeProject({{ $project->id }})">
                            Quitar
                        </x-forms.button>
                    </div>
                @empty
                    <div>No hay proyectos asignados.</div>
                @endforelse
            </div>
        </div>

        <div>
            <h2>Proyectos disponibles</h2>
            <div class="subtitle">Proyectos de tu equipo actual que puedes asignar.</div>
            <div class="flex flex-col gap-2">
                @forelse ($availableProjects as $project)
                    <div class="flex items-center gap-3 p-4 border rounded-xl border-neutral-300 dark:border-coolgray-200">
                        <div class="flex flex-col">
                            <div class="font-semibold dark:text-white">{{ $project->name }}</div>
                            <div class="description">{{ $project->description }}</div>
                        </div>
                        <div class="flex-1"></div>
                        <x-forms.button type="button" wire:click="assignProject({{ $project->id }})">
                            Asignar
                        </x-forms.button>
                    </div>
                @empty
                    <div>No hay proyectos disponibles en tu equipo actual.</div>
                @endforelse
            </div>
        </div>
    </div>
</div>

