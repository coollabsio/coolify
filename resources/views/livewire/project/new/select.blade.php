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
    <div class="flex flex-col gap-4 pt-10 sm:px-20">
        @if ($current_step === 'type')
            <ul class="pb-10 steps">
                <li class="step step-secondary">Select Resource Type</li>
                <li class="step">Select a Server</li>
                <li class="step">Select a Destination</li>
            </ul>
            <h2>Applications</h2>
            <div class="grid justify-start grid-cols-1 gap-4 text-left xl:grid-cols-3">
                <x-resource-view wire="setType('public')">
                    <x-slot:title>Public Repository</x-slot>
                    <x-slot:description>
                        You can deploy any kind of public repositories from the supported git providers.
                    </x-slot>
                    <x-slot:logo>
                        <img class="w-[4.5rem]
                            aspect-square h-[4.5rem] p-2 transition-all duration-200 opacity-30 grayscale group-hover:grayscale-0 group-hover:opacity-100"
                            src="{{ asset('svgs/git.svg') }}">
                    </x-slot:logo>
                </x-resource-view>
                <x-resource-view wire="setType('private-gh-app')">
                    <x-slot:title>Private Repository (with GitHub App)</x-slot>
                    <x-slot:description>
                        You can deploy public & private repositories through your GitHub Apps.
                    </x-slot>
                    <x-slot:logo>
                        <img class="w-[4.5rem]
                            aspect-square h-[4.5rem] p-2 transition-all duration-200 opacity-30 grayscale group-hover:grayscale-0 group-hover:opacity-100"
                            src="{{ asset('svgs/github.svg') }}">
                    </x-slot:logo>
                </x-resource-view>

                <x-resource-view wire="setType('private-deploy-key')">
                    <x-slot:title> Private Repository (with deploy key)</x-slot>
                    <x-slot:description>
                        You can deploy public & private repositories with a simple deploy key (SSH key).
                    </x-slot>
                    <x-slot:logo>
                        <img class="w-[4.5rem]
                            aspect-square h-[4.5rem] p-2 transition-all duration-200 opacity-30 grayscale group-hover:grayscale-0 group-hover:opacity-100"
                            src="{{ asset('svgs/git.svg') }}">
                    </x-slot:logo>
                </x-resource-view>
            </div>
            <div class="grid justify-start grid-cols-1 gap-4 text-left xl:grid-cols-3">
                <x-resource-view wire="setType('dockerfile')">
                    <x-slot:title>Based on a Dockerfile</x-slot>
                    <x-slot:description>
                        You can deploy a simple Dockerfile, without Git.
                    </x-slot>
                    <x-slot:logo>
                        <img class="w-[4.5rem]
                            aspect-square h-[4.5rem] p-2 transition-all duration-200 opacity-30 grayscale group-hover:grayscale-0 group-hover:opacity-100"
                            src="{{ asset('svgs/docker.svg') }}">
                    </x-slot:logo>
                </x-resource-view>
                <x-resource-view wire="setType('docker-compose-empty')">
                    <x-slot:title>Based on a Docker Compose</x-slot>
                    <x-slot:description>
                        You can deploy complex application easily with Docker Compose, without Git.
                    </x-slot>
                    <x-slot:logo>
                        <img class="w-[4.5rem]
                            aspect-square h-[4.5rem] p-2 transition-all duration-200 opacity-30 grayscale group-hover:grayscale-0 group-hover:opacity-100"
                            src="{{ asset('svgs/docker.svg') }}">
                    </x-slot:logo>
                </x-resource-view>
                <x-resource-view wire="setType('docker-image')">
                    <x-slot:title>Based on an existing Docker Image</x-slot>
                    <x-slot:description>
                        You can deploy an existing Docker Image from any Registry, without Git.
                    </x-slot>
                    <x-slot:logo>
                        <img class="w-[4.5rem]
                            aspect-square h-[4.5rem] p-2 transition-all duration-200 opacity-30 grayscale group-hover:grayscale-0 group-hover:opacity-100"
                            src="{{ asset('svgs/docker.svg') }}">
                    </x-slot:logo>
                </x-resource-view>
            </div>
            <h2 class="py-4">Databases</h2>
            <div class="grid justify-start grid-cols-1 gap-4 text-left xl:grid-cols-3">
                <x-resource-view wire="setType('postgresql')">
                    <x-slot:title> New PostgreSQL</x-slot>
                    <x-slot:description>
                        PostgreSQL is an object-relational database known for its
                        robustness, advanced features, and strong standards compliance.
                    </x-slot>
                    <x-slot:logo>
                        <img class="w-[4.5rem]
                            aspect-square h-[4.5rem] p-2 transition-all duration-200 opacity-30 grayscale group-hover:grayscale-0 group-hover:opacity-100"
                            src="{{ asset('svgs/postgres.svg') }}">
                    </x-slot:logo>
                </x-resource-view>
                <x-resource-view wire="setType('redis')">
                    <x-slot:title> New Redis</x-slot>
                    <x-slot:description>
                        Redis is an open-source, in-memory data structure store, used as a database, cache, and message
                        broker.
                    </x-slot>
                    <x-slot:logo>
                        <img class="w-[4.5rem]
                            aspect-square h-[4.5rem] p-2 transition-all duration-200 opacity-30 grayscale group-hover:grayscale-0 group-hover:opacity-100"
                            src="{{ asset('svgs/redis.svg') }}">
                    </x-slot:logo>
                </x-resource-view>
                <x-resource-view wire="setType('mongodb')">
                    <x-slot:title> New MongoDB</x-slot>
                    <x-slot:description>
                        MongoDB is a source-available, NoSQL database that uses JSON-like documents with
                        optional schemas.
                    </x-slot>
                    <x-slot:logo>
                        <img class="w-[4.5rem]
                            aspect-square h-[4.5rem] p-2 transition-all duration-200 opacity-30 grayscale group-hover:grayscale-0 group-hover:opacity-100"
                            src="{{ asset('svgs/mongodb.svg') }}">
                    </x-slot:logo>
                </x-resource-view>
                <x-resource-view wire="setType('mysql')">
                    <x-slot:title>New MySQL</x-slot>
                    <x-slot:description>
                        MySQL is a relational database known for its speed, reliability, and
                        flexibility.
                    </x-slot>
                    <x-slot:logo>
                        <img class="w-[4.5rem]
                            aspect-square h-[4.5rem] p-2 transition-all duration-200 opacity-30 grayscale group-hover:grayscale-0 group-hover:opacity-100"
                            src="{{ asset('svgs/mysql.svg') }}">
                    </x-slot:logo>
                </x-resource-view>
                <x-resource-view wire="setType('mariadb')">
                    <x-slot:title> New Mariadb</x-slot>
                    <x-slot:description>
                        MariaDB is a relational database that serves as a drop-in
                        replacement for MySQL.
                    </x-slot>
                    <x-slot:logo>
                        <img class="w-[4.5rem]
                            aspect-square h-[4.5rem] p-2 transition-all duration-200 opacity-30 grayscale group-hover:grayscale-0 group-hover:opacity-100"
                            src="{{ asset('svgs/mariadb.svg') }}">
                    </x-slot:logo>
                </x-resource-view>

                {{-- <div class="box group" wire="setType('existing-postgresql')">
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
                <x-forms.button wire:click="loadServices('force')">Reload List</x-forms.button>
                <input
                    class="w-full text-white rounded input input-sm bg-coolgray-200 disabled:bg-coolgray-200/50 disabled:border-none placeholder:text-coolgray-500 read-only:text-neutral-500 read-only:bg-coolgray-200/50"
                    wire:model.live.debounce.200ms="search" placeholder="Search...">
            </div>
            <div class="grid justify-start grid-cols-1 gap-4 text-left xl:grid-cols-3">
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
                            <x-resource-view wire="setType('one-click-service-{{ $serviceName }}')">
                                <x-slot:title> {{ Str::headline($serviceName) }}</x-slot>
                                <x-slot:description>
                                    @if (data_get($service, 'slogan'))
                                        {{ data_get($service, 'slogan') }}
                                    @endif
                                </x-slot>
                                <x-slot:logo>
                                    @if (data_get($service, 'logo'))
                                        <img class="w-[4.5rem]
                                        aspect-square h-[4.5rem] p-2 transition-all duration-200 opacity-30 grayscale group-hover:grayscale-0 group-hover:opacity-100"
                                            src="{{ asset(data_get($service, 'logo')) }}">
                                    @endif
                                </x-slot:logo>
                                <x-slot:documentation>
                                    {{ data_get($service, 'documentation') }}
                                </x-slot>
                            </x-resource-view>
                            {{-- <button class="text-left box group" wire:loading.attr="disabled"
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
                            </button> --}}
                        @endif
                    @empty
                        <div class="w-96">No service found. Please try to reload the list!</div>
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
                    <div class="w-64 box group" wire:click="setServer({{ $server }})">
                        <div class="flex flex-col mx-6">
                            <div class="font-bold group-hover:text-white">
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
                                    Network: {{ $standaloneDocker->network }}</div>
                            </div>
                        </div>
                    @endforeach
                @endif
                <a href="{{ route('destination.new', ['server_id' => $server_id]) }}"
                    class="items-center justify-center text-center box-without-bg group bg-coollabs hover:bg-coollabs-100">
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
