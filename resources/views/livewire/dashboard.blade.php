<div>
    <x-slot:title>
        Dashboard | Coolify
    </x-slot>
    @if (session('error'))
        <span x-data x-init="$wire.emit('error', '{{ session('error') }}')" />
    @endif
    <h1>Dashboard</h1>
    <div class="subtitle">Your self-hosted infrastructure.</div>
    @if (request()->query->get('success'))
        <div class="items-center justify-center mb-10 font-bold rounded alert alert-success">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 stroke-current shrink-0" fill="none"
                viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            Your subscription has been activated! Welcome onboard! <br>It could take a few seconds before your
            subscription is activated.<br> Please be patient.
        </div>
    @endif
    <h3 class="pb-4">Projects</h3>
    @if ($projects->count() > 0)
        <div class="grid grid-cols-1 gap-2 xl:grid-cols-2">
            @foreach ($projects as $project)
                <div class="gap-2 border border-transparent cursor-pointer box group"
                    onclick="gotoProject('{{ $project->uuid }}','{{ $project->default_environment() }}')">
                    <div class="flex flex-1 mx-6">
                        <div class="flex flex-col justify-center flex-1">
                            <div class="box-title">{{ $project->name }}</div>
                            <div class="box-description">
                                {{ $project->description }}
                            </div>
                        </div>
                        <div class="flex items-center justify-center gap-2 text-xs font-bold">
                            <a class="hover:underline"
                                href="{{ route('project.resource.create', ['project_uuid' => $project->uuid, 'environment_name' => data_get($project, 'default_environment()', 'production')]) }}">
                                <span class="p-2 font-bold">+ Add Resource</span>
                            </a>
                            <a class="hover:underline"
                                href="{{ route('project.edit', ['project_uuid' => $project->uuid]) }}">
                                Settings
                            </a>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @else
        <div class="flex flex-col gap-1">
            <div class='font-bold dark:text-warning'>No projects found.</div>
            <div class="flex items-center gap-1">
                <x-modal-input buttonTitle="Add" title="New Project">
                    <livewire:project.add-empty />
                </x-modal-input> your first project or
                go to the <a class="underline dark:text-white" href="{{ route('onboarding') }}">onboarding</a> page.
            </div>
        </div>
    @endif

    <h3 class="py-4">Servers</h3>
    @if ($servers->count() > 0)
        <div class="grid grid-cols-1 gap-2 xl:grid-cols-2">
            @foreach ($servers as $server)
                <a href="{{ route('server.show', ['server_uuid' => data_get($server, 'uuid')]) }}"
                    @class([
                        'gap-2 border cursor-pointer box group',
                        'border-transparent' => $server->settings->is_reachable,
                        'border-red-500' => !$server->settings->is_reachable,
                    ])>
                    <div class="flex flex-col justify-center mx-6">
                        <div class="box-title">
                            {{ $server->name }}
                        </div>
                        <div class="box-description">
                            {{ $server->description }}</div>
                        <div class="flex gap-1 text-xs text-error">
                            @if (!$server->settings->is_reachable)
                                Not reachable
                            @endif
                            @if (!$server->settings->is_reachable && !$server->settings->is_usable)
                                &
                            @endif
                            @if (!$server->settings->is_usable)
                                Not usable by Coolify
                            @endif
                        </div>
                    </div>
                    <div class="flex-1"></div>
                </a>
            @endforeach
        </div>
    @else
        @if ($private_keys->count() === 0)
            <div class="flex flex-col gap-1">
                <div class='font-bold dark:text-warning'>No private keys found.</div>
                <div class="flex items-center gap-1">Before you can add your server, first <x-modal-input
                        buttonTitle="add" title="New Private Key">
                        <livewire:security.private-key.create from="server" />
                    </x-modal-input> a private key
                    or
                    go to the <a class="underline dark:text-white" href="{{ route('onboarding') }}">onboarding</a>
                    page.
                </div>
            </div>
        @else
            <div class="flex flex-col gap-1">
                <div class='font-bold dark:text-warning'>No servers found.</div>
                <div class="flex items-center gap-1">
                    <x-modal-input buttonTitle="Add" title="New Server" :closeOutside="false">
                        <livewire:server.create />
                    </x-modal-input> your first server
                    or
                    go to the <a class="underline dark:text-white" href="{{ route('onboarding') }}">onboarding</a>
                    page.
                </div>
            </div>
        @endif
    @endif
    @if ($servers->count() > 0 && $projects->count() > 0)
        <div class="flex items-center gap-2">
            <h3 class="py-4">Deployments</h3>
            @if (count($deployments_per_server) > 0)
                <x-loading />
            @endif
            <x-modal-confirmation isErrorButton action="cleanup_queue" buttonTitle="Cleanup Queues">
                This will clean up the deployment queue. <br>Please think again.
            </x-modal-confirmation>
        </div>
        <div wire:poll.3000ms="get_deployments" class="grid grid-cols-1">
            @forelse ($deployments_per_server as $server_name => $deployments)
                <h4 class="py-4">{{ $server_name }}</h4>
                <div class="grid grid-cols-1 gap-2 lg:grid-cols-3">
                    @foreach ($deployments as $deployment)
                        <a href="{{ data_get($deployment, 'deployment_url') }}" @class([
                            'gap-2 cursor-pointer box group border-l-2 border-dotted',
                            'dark:border-coolgray-300' => data_get($deployment, 'status') === 'queued',
                            'border-yellow-500' => data_get($deployment, 'status') === 'in_progress',
                        ])>
                            <div class="flex flex-col justify-center mx-6">
                                <div class="box-title">
                                    {{ data_get($deployment, 'application_name') }}
                                </div>
                                @if (data_get($deployment, 'pull_request_id') !== 0)
                                    <div class="box-description">
                                        PR #{{ data_get($deployment, 'pull_request_id') }}
                                    </div>
                                @endif
                                <div class="box-description">
                                    {{ str(data_get($deployment, 'status'))->headline() }}
                                </div>
                            </div>
                            <div class="flex-1"></div>
                        </a>
                    @endforeach
                </div>
            @empty
                <div>No deployments running.</div>
            @endforelse
        </div>
    @endif


    <script>
        function gotoProject(uuid, environment) {
            if (environment) {
                window.location.href = '/project/' + uuid + '/' + environment;
            } else {
                window.location.href = '/project/' + uuid;
            }
        }
    </script>
    {{-- <x-forms.button wire:click='getIptables'>Get IPTABLES</x-forms.button> --}}
</div>
