<div x-data x-init="$wire.loadThings">
    <h1>New Resource</h1>
    <div class="pb-4 ">Deploy resources, like Applications, Databases, Services...</div>
    <div class="flex flex-col gap-2 pt-10">
        @if ($current_step === 'type')
            <ul class="pb-10 steps">
                <li class="step step-secondary">Select Resource Type</li>
                <li class="step">Select a Server</li>
                <li class="step">Select a Destination</li>
            </ul>
            <h2>Applications</h2>
            <div class="grid justify-start grid-cols-1 gap-2 text-left xl:grid-cols-3">
                <div class="box group" wire:click="setType('public')">
                    <div class="flex flex-col mx-6">
                        <div class="font-bold text-white group-hover:text-white">
                            Public Repository
                        </div>
                        <div class="text-xs group-hover:text-white">
                            You can deploy any kind of public repositories from the supported git servers.
                        </div>
                    </div>
                </div>
                <div class="box group" wire:click="setType('private-gh-app')">
                    <div class="flex flex-col mx-6">
                        <div class="font-bold text-white group-hover:text-white">
                            Private Repository
                        </div>
                        <div class="text-xs group-hover:text-white">
                            You can deploy public & private repositories through your GitHub Apps.
                        </div>
                    </div>
                </div>
                <div class="box group" wire:click="setType('private-deploy-key')">
                    <div class="flex flex-col mx-6">
                        <div class="font-bold text-white group-hover:text-white">
                            Private Repository (with deploy key)
                        </div>
                        <div class="text-xs group-hover:text-white">
                            You can deploy public & private repositories with a simple deploy key.
                        </div>
                    </div>
                </div>
            </div>
            <div class="grid justify-start grid-cols-1 gap-2 text-left xl:grid-cols-3">
                <div class="box group" wire:click="setType('dockerfile')">
                    <div class="flex flex-col mx-6">
                        <div class="font-bold text-white group-hover:text-white">
                            Based on a Dockerfile
                        </div>
                        <div class="text-xs group-hover:text-white">
                            You can deploy a simple Dockerfile, without Git.
                        </div>
                    </div>
                </div>
                <div class="box group" wire:click="setType('docker-compose-empty')">
                    <div class="flex flex-col mx-6">
                        <div class="font-bold text-white group-hover:text-white">
                            Based on a Docker Compose
                        </div>
                        <div class="text-xs group-hover:text-white">
                            You can deploy complex application easily with Docker Compose.
                        </div>
                    </div>
                </div>
            </div>
            <h2 class="py-4">Databases</h2>
            <div class="grid justify-start grid-cols-1 gap-2 text-left xl:grid-cols-3">
                <div class="box group" wire:click="setType('postgresql')">
                    <div class="flex flex-col mx-6">
                        <div class="font-bold text-white group-hover:text-white">
                            New PostgreSQL
                        </div>
                        <div class="text-xs group-hover:text-white">
                            The most loved relational database in the world.
                        </div>
                    </div>
                </div>
                {{-- <div class="box group" wire:click="setType('existing-postgresql')">
                    <div class="flex flex-col mx-6">
                        <div class="group-hover:text-white">
                            Backup Existing PostgreSQL
                        </div>
                        <div class="text-xs group-hover:text-white">
                            Schedule a backup of an existing PostgreSQL database.
                        </div>
                    </div>
                </div> --}}
            </div>
            <div class="flex items-center gap-2">
                <h2 class="py-4">Services</h2>
                <x-forms.button wire:click='loadServices(true)'>Reload Services List</x-forms.button>
            </div>
            <div class="grid justify-start grid-cols-1 gap-2 text-left xl:grid-cols-3">
                @if ($loadingServices)
                    <span class="loading loading-xs loading-spinner"></span>
                @else
                    @foreach ($services as $serviceName => $service)
                        <button class="text-left box group"
                            wire:loading.attr="disabled" wire:click="setType('one-click-service-{{ $serviceName }}')">
                            <div class="flex flex-col mx-6">
                                <div class="font-bold text-white group-hover:text-white">
                                    {{ Str::headline($serviceName) }}
                                </div>
                                @if (data_get($service, 'slogan'))
                                    <div class="text-xs">
                                        {{ data_get($service, 'slogan') }}
                                    </div>
                                @endif
                            </div>
                        </button>
                    @endforeach
                @endif
            </div>
        @endif
        @if ($current_step === 'servers')
            <ul class="pb-10 steps">
                <li class="step step-secondary">Select Resource Type</li>
                <li class="step step-secondary">Select a Server</li>
                <li class="step">Select a Destination</li>
            </ul>
            <div class="flex flex-col justify-center gap-2 text-left xl:flex-row">
                @forelse($servers as $server)
                    <div class="box group" wire:click="setServer({{ $server }})">
                        <div class="flex flex-col mx-6">
                            <div class="group-hover:text-white">
                                {{ $server->name }}
                            </div>
                            <div class="text-xs group-hover:text-white">
                                {{ $server->description }}</div>
                        </div>
                    </div>
                @empty
                    <div>
                        <div>No validated & reachable servers found. <a class="text-white underline" href="/servers">
                                Go to servers page
                            </a></div>

                        <x-use-magic-bar link="/server/new" />
                    </div>
                @endforelse
            </div>
        @endif
        @if ($current_step === 'destinations')
            <ul class="pb-10 steps">
                <li class="step step-secondary">Select Resource Type</li>
                <li class="step step-secondary">Select a Server</li>
                <li class="step step-secondary">Select a Destination</li>
            </ul>
            <div class="flex flex-col justify-center gap-2 text-left xl:flex-row">
                @foreach ($standaloneDockers as $standaloneDocker)
                    <div class="box group" wire:click="setDestination('{{ $standaloneDocker->uuid }}')">
                        <div class="flex flex-col mx-6">
                            <div class="font-bold group-hover:text-white">
                                Standalone Docker <span class="text-xs">({{ $standaloneDocker->name }})</span>
                            </div>
                            <div class="text-xs group-hover:text-white">
                                network: {{ $standaloneDocker->network }}</div>
                        </div>
                    </div>
                @endforeach
                @foreach ($swarmDockers as $swarmDocker)
                    <div class="box group" wire:click="setDestination('{{ $swarmDocker->uuid }}')">
                        <div class="flex flex-col mx-6">
                            <div class="font-bold group-hover:text-white">
                                Swarm Docker <span class="text-xs">({{ $swarmDocker->name }})</span>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
        @if ($current_step === 'existing-postgresql')
            <form wire:submit.prevent='addExistingPostgresql' class="flex items-end gap-2">
                <x-forms.input placeholder="postgres://username:password@database:5432" label="Database URL"
                    id="existingPostgresqlUrl" />
                <x-forms.button type="submit">Add Database</x-forms.button>
            </form>
        @endif
    </div>
</div>
