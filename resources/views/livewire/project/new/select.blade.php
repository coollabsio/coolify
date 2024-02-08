<div x-data x-init="$wire.loadServers">
    <div class="flex gap-4 ">
        <h1>New Resource</h1>
        <div class="w-96">
            <x-forms.select wire:model="selectedEnvironment">
                @foreach ($environments as $environment)
                    <option value="{{ $environment->name }}">Environment: {{ $environment->name }}</option>
                @endforeach
            </x-forms.select>
        </div>
    </div>
    <div class="pb-4 ">Deploy resources, like Applications, Databases, Services...</div>
    <div class="flex flex-col gap-4 pt-10">
        @if ($current_step === 'type')
            <ul class="pb-10 steps">
                <li class="step step-secondary">Select Resource Type</li>
                <li class="step">Select a Server</li>
                <li class="step">Select a Destination</li>
            </ul>
            <h2>Applications</h2>
            <div class="grid justify-start grid-cols-1 gap-4 text-left xl:grid-cols-3">
                <div class="box group" wire:click="setType('public')">
                    <div class="flex flex-col mx-6">
                        <div class="font-bold text-white group-hover:text-white">
                            Public Repository
                        </div>
                        <div class="description">
                            You can deploy any kind of public repositories from the supported git providers.
                        </div>
                    </div>
                </div>
                <div class="box group" wire:click="setType('private-gh-app')">
                    <div class="flex flex-col mx-6">
                        <div class="font-bold text-white group-hover:text-white">
                            Private Repository (with GitHub App)
                        </div>
                        <div class="description">
                            You can deploy public & private repositories through your GitHub Apps.
                        </div>
                    </div>
                </div>
                <div class="box group" wire:click="setType('private-deploy-key')">
                    <div class="flex flex-col mx-6">
                        <div class="font-bold text-white group-hover:text-white">
                            Private Repository (with deploy key)
                        </div>
                        <div class="description">
                            You can deploy public & private repositories with a simple deploy key (SSH key).
                        </div>
                    </div>
                </div>
            </div>
            <div class="grid justify-start grid-cols-1 gap-4 text-left xl:grid-cols-3">
                <div class="box group" wire:click="setType('dockerfile')">
                    <div class="flex flex-col mx-6">
                        <div class="font-bold text-white group-hover:text-white">
                            Based on a Dockerfile
                        </div>
                        <div class="description">
                            You can deploy a simple Dockerfile, without Git.
                        </div>
                    </div>
                </div>
                <div class="box group" wire:click="setType('docker-compose-empty')">
                    <div class="flex flex-col mx-6">
                        <div class="font-bold text-white group-hover:text-white">
                            Based on a Docker Compose
                        </div>
                        <div class="description">
                            You can deploy complex application easily with Docker Compose, without Git.
                        </div>
                    </div>
                </div>
                <div class="box group" wire:click="setType('docker-image')">
                    <div class="flex flex-col mx-6">
                        <div class="font-bold text-white group-hover:text-white">
                            Based on an existing Docker Image
                        </div>
                        <div class="description">
                            You can deploy an existing Docker Image from any Registry, without Git.
                        </div>
                    </div>
                </div>
            </div>
            <h2 class="py-4">Databases</h2>
            <div class="grid justify-start grid-cols-1 gap-4 text-left xl:grid-cols-5">
                <div class="box group" wire:click="setType('postgresql')">
                    <div class="flex flex-col mx-6">
                        <div class="font-bold text-white group-hover:text-white">
                            New PostgreSQL
                        </div>
                        <div class="description">
                            PostgreSQL is an open-source, object-relational database management system known for its
                            robustness, advanced features, and strong standards compliance.
                        </div>
                    </div>
                </div>
                <div class="box group" wire:click="setType('redis')">
                    <div class="flex flex-col mx-6">
                        <div class="font-bold text-white group-hover:text-white">
                            New Redis
                        </div>
                        <div class="description">
                            Redis is an open-source, in-memory data structure store used as a database, cache, and
                            message broker, known for its high performance, flexibility, and rich data structures.
                        </div>
                    </div>
                </div>
                <div class="box group" wire:click="setType('mongodb')">
                    <div class="flex flex-col mx-6">
                        <div class="font-bold text-white group-hover:text-white">
                            New MongoDB
                        </div>
                        <div class="description">
                            MongoDB is a source-available, NoSQL database program that uses JSON-like documents with
                            optional schemas, known for its flexibility, scalability, and wide range of application use
                            cases.
                        </div>
                    </div>
                </div>
                <div class="box group" wire:click="setType('mysql')">
                    <div class="flex flex-col mx-6">
                        <div class="font-bold text-white group-hover:text-white">
                            New MySQL
                        </div>
                        <div class="description">
                            MySQL is an open-source relational database management system known for its speed,
                            reliability, and flexibility in managing and accessing data.
                        </div>
                    </div>
                </div>
                <div class="box group" wire:click="setType('mariadb')">
                    <div class="flex flex-col mx-6">
                        <div class="font-bold text-white group-hover:text-white">
                            New Mariadb
                        </div>
                        <div class="description">
                            MariaDB is an open-source relational database management system that serves as a drop-in
                            replacement for MySQL, offering more robust, scalable, and reliable SQL server capabilities.
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
            <div class="flex items-center gap-4" wire:init='loadServices'>
                <h2 class="py-4">Services</h2>
                <x-forms.button wire:click='loadServices'>Reload Services List</x-forms.button>
                <input
                    class="w-full text-white rounded input input-sm bg-coolgray-200 disabled:bg-coolgray-200/50 disabled:border-none placeholder:text-coolgray-500 read-only:text-neutral-500 read-only:bg-coolgray-200/50"
                    wire:model.live.debounce.200ms="search" placeholder="Search...">
            </div>
            <div class="grid justify-start grid-cols-1 gap-4 text-left xl:grid-cols-5">
                @if ($loadingServices)
                    <span class="loading loading-xs loading-spinner"></span>
                @else
                    @forelse ($services as $serviceName => $service)
                        @if (data_get($service, 'disabled'))
                            <button class="text-left cursor-not-allowed bg-coolgray-200/20 box-without-bg" disabled>
                                <div class="flex flex-col mx-6">
                                    <div class="font-bold">
                                        {{ Str::headline($serviceName) }}
                                    </div>
                                    You need to upgrade to {{ data_get($service, 'minVersion') }} to use this service.
                                </div>
                            </button>
                        @else
                            <button class="text-left box group" wire:loading.attr="disabled"
                                wire:click="setType('one-click-service-{{ $serviceName }}')">
                                <div class="flex flex-col mx-2">
                                    <div class="font-bold text-white group-hover:text-white">
                                        {{ Str::headline($serviceName) }}
                                    </div>
                                    @if (data_get($service, 'slogan'))
                                        <div class="description">
                                            {{ data_get($service, 'slogan') }}
                                        </div>
                                    @endif
                                </div>
                            </button>
                        @endif
                    @empty
                        <div>No service found. Please try to reload the list!</div>
                    @endforelse
                @endif
            </div>
            <div class="py-4 pb-10">Trademarks Policy: The respective trademarks mentioned here are owned by the
                respective
                companies, and use of them does not imply any affiliation or endorsement.</div>
        @endif
        @if ($current_step === 'servers')
            <ul class="pb-10 steps">
                <li class="step step-secondary">Select Resource Type</li>
                <li class="step step-secondary">Select a Server</li>
                <li class="step">Select a Destination</li>
            </ul>

            {{-- @if ($isDatabase)
                <div class="flex items-center justify-center pt-4">
                    <x-forms.checkbox instantSave wire:model="includeSwarm"
                        helper="Swarm clusters are excluded from this list by default. For database, services or complex compose deployments with databases to work with Swarm,
                you need to set a few things on the server. Read more <a class='text-white underline' href='https://coolify.io/docs/docker/swarm#database-requirements' target='_blank'>here</a>."
                        label="Include Swarm Clusters" />
                </div>
            @endif --}}
            <div class="flex flex-col justify-center gap-4 text-left xl:flex-row xl:flex-wrap">
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
            @if ($isDatabase)
                <div class="text-center">Swarm clusters are excluded from this type of resource at the moment. It will
                    be activated soon. Stay tuned.</div>
            @endif
        @endif
        @if ($current_step === 'destinations')
            <ul class="pb-10 steps">
                <li class="step step-secondary">Select Resource Type</li>
                <li class="step step-secondary">Select a Server</li>
                <li class="step step-secondary">Select a Destination</li>
            </ul>

            <div class="flex flex-col justify-center gap-4 text-left xl:flex-row xl:flex-wrap">
                @if ($server->isSwarm())
                    @foreach ($swarmDockers as $swarmDocker)
                        <div class="box group" wire:click="setDestination('{{ $swarmDocker->uuid }}')">
                            <div class="flex flex-col mx-6">
                                <div class="font-bold group-hover:text-white">
                                    Swarm Docker <span class="text-xs">({{ $swarmDocker->name }})</span>
                                </div>
                            </div>
                        </div>
                    @endforeach
                @else
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
                @endif
                <a href="{{ route('destination.new', ['server_id' => $server_id]) }}"
                    class="items-center justify-center pb-10 text-center box-without-bg group bg-coollabs hover:bg-coollabs-100">
                    <div class="flex flex-col mx-6 ">
                        <div class="font-bold text-white">
                            + Add New
                        </div>
                    </div>
                </a>
            </div>
        @endif
        @if ($current_step === 'existing-postgresql')
            <form wire:submit='addExistingPostgresql' class="flex items-end gap-4">
                <x-forms.input placeholder="postgres://username:password@database:5432" label="Database URL"
                    id="existingPostgresqlUrl" />
                <x-forms.button type="submit">Add Database</x-forms.button>
            </form>
        @endif
    </div>
</div>
