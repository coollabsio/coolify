<div x-data x-init="$wire.load_servers">
    <h1>New Resource</h1>
    <div class="pb-4 ">Deploy resources, like Applications, Databases, Services...</div>
    <div class="flex flex-col pt-10">
        @if ($current_step === 'type')
            <ul class="pb-10 steps">
                <li class="step step-secondary">Select Source Type</li>
                <li class="step">Select a Server</li>
                <li class="step">Select a Destination</li>
            </ul>
            <h3 class="pb-4">Applications</h3>
            <div class="flex flex-col justify-center gap-2 text-left xl:flex-row">
                <div class="gap-2 py-4 cursor-pointer group hover:bg-coollabs bg-coolgray-200"
                    wire:click="set_type('public')">
                    <div class="flex flex-col mx-6">
                        <div class="group-hover:text-white">
                            Public Repository
                        </div>
                        <div class="text-xs group-hover:text-white">
                            You can deploy any kind of public repositories from the supported git servers.
                        </div>
                    </div>
                </div>
                <div class="gap-2 py-4 cursor-pointer group hover:bg-coollabs bg-coolgray-200"
                    wire:click="set_type('private-gh-app')">
                    <div class="flex flex-col mx-6">
                        <div class="group-hover:text-white">
                            Private Repository
                        </div>
                        <div class="text-xs group-hover:text-white">
                            You can deploy public & private repositories through your GitHub Apps.
                        </div>
                    </div>
                </div>
                <div class="gap-2 py-4 cursor-pointer group hover:bg-coollabs bg-coolgray-200"
                    wire:click="set_type('private-deploy-key')">
                    <div class="flex flex-col mx-6">
                        <div class="group-hover:text-white">
                            Private Repository (with deploy key)
                        </div>
                        <div class="text-xs group-hover:text-white">
                            You can deploy public & private repositories with a simple deploy key.
                        </div>
                    </div>
                </div>
            </div>
            <h3 class="py-4">Databases</h3>
            <div class="flex flex-col justify-center gap-2 text-left xl:flex-row">
                <div class="gap-2 py-4 cursor-pointer group hover:bg-coollabs bg-coolgray-200"
                    wire:click="set_type('postgresql')">
                    <div class="flex flex-col mx-6">
                        <div class="group-hover:text-white">
                            PostgreSQL
                        </div>
                        <div class="text-xs group-hover:text-white">
                            The most loved relational database in the world.
                        </div>
                    </div>
                </div>

            </div>
        @endif
        @if ($current_step === 'servers')
            <ul class="pb-10 steps">
                <li class="step step-secondary">Select Source Type</li>
                <li class="step step-secondary">Select a Server</li>
                <li class="step">Select a Destination</li>
            </ul>
            <div class="flex flex-col justify-center gap-2 text-left xl:flex-row">
                @foreach ($servers as $server)
                    <div class="gap-2 py-4 cursor-pointer group hover:bg-coollabs bg-coolgray-200"
                        wire:click="set_server({{ $server }})">
                        <div class="flex flex-col mx-6">
                            <div class="group-hover:text-white">
                                {{ $server->name }}
                            </div>
                            <div class="text-xs group-hover:text-white">
                                {{ $server->description }}</div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
        @if ($current_step === 'destinations')
            <ul class="pb-10 steps">
                <li class="step step-secondary">Select Source Type</li>
                <li class="step step-secondary">Select a Server</li>
                <li class="step step-secondary">Select a Destination</li>
            </ul>
            <div class="flex flex-col justify-center gap-2 text-left xl:flex-row">
                @foreach ($destinations as $destination)
                    <div class="gap-2 py-4 cursor-pointer group hover:bg-coollabs bg-coolgray-200"
                        wire:click="set_destination('{{ $destination->uuid }}')">
                        <div class="flex flex-col mx-6">
                            <div class="group-hover:text-white">
                                {{ $destination->name }}
                            </div>
                            <div class="text-xs group-hover:text-white">
                                {{ $destination->network }}</div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
