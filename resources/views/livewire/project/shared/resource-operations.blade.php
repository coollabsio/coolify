<div x-on:migrate-resource-confirmed.window="$wire.migrateTo(selectedMigrateDestination)"
    x-on:clone-resource-confirmed.window="$wire.cloneTo(selectedCloneDestination, selectedCloneEnvironment)" x-data="{
    selectedCloneServer: null,
    selectedCloneDestination: null,
    selectedCloneProject: null,
    selectedCloneEnvironment: null,
    selectedMigrateServer: null,
    selectedMigrateDestination: null,
    selectedMoveProject: null,
    selectedMoveEnvironment: null,
    currentProjectId: {{ $resource->environment->project->id }},
    currentEnvironmentId: {{ $resource->environment->id }},
    currentServerId: @js($resource->destination->server->id),
    currentDestinationUuid: @js($resource->destination->uuid),
    servers: @js(
        $servers->map(
            fn ($server) => [
                'id' => $server->id,
                'name' => $server->name,
                'ip' => $server->ip,
                'is_functional' => $server->isFunctional(),
                'destinations' => $server->destinations()->map(
                    fn ($destination) => [
                        'id' => $destination->id,
                        'uuid' => $destination->uuid,
                        'name' => $destination->name,
                        'server_id' => $server->id,
                    ],
                ),
            ],
        )->values(),
    ),
    projects: @js(
        $projects->map(
            fn ($project) => [
                'id' => $project->id,
                'name' => $project->name,
                'environments' => $project->environments->map(
                    fn ($environment) => [
                        'id' => $environment->id,
                        'name' => $environment->name,
                        'project_id' => $project->id,
                    ],
                )->values(),
            ],
        )->values(),
    ),
    get availableDestinations() {
        if (this.selectedCloneServer === null || this.selectedCloneServer === '') return [];
        const server = this.servers.find(server => server.id == this.selectedCloneServer);
        return server ? server.destinations : [];
    },
    get availableMigrateDestinations() {
        if (this.selectedMigrateServer === null || this.selectedMigrateServer === '') return [];
        const server = this.servers.find(server => server.id == this.selectedMigrateServer);
        if (!server) return [];
        return server.destinations.filter(destination => destination.uuid !== this.currentDestinationUuid);
    },
    get isCrossServerMigration() {
        return this.selectedMigrateServer && this.selectedMigrateServer != this.currentServerId;
    },
    get availableCloneEnvironments() {
        if (this.selectedCloneProject === null || this.selectedCloneProject === '') return [];
        const project = this.projects.find(project => project.id == this.selectedCloneProject);
        return project ? project.environments : [];
    },
    get availableEnvironments() {
        if (!this.selectedMoveProject) return [];
        const project = this.projects.find(project => project.id == this.selectedMoveProject);
        if (!project) return [];
        return project.environments.filter(environment => {
            if (project.id == this.currentProjectId) {
                return environment.id != this.currentEnvironmentId;
            }
            return true;
        });
    },
    get isCurrentProjectSelected() {
        return this.selectedMoveProject == this.currentProjectId;
    },
    get cloneServerOptions() {
        return [
            ...this.servers.map(server => ({
                value: server.id,
                label: `${server.name} (${server.ip})${server.id == this.currentServerId ? ' (current)' : ''}`,
            })),
            ...@js(
                $buildServers->map(
                    fn ($server) => [
                        'value' => "build-{$server->id}",
                        'label' => "{$server->name} (build server)",
                        'disabled' => true,
                    ],
                )->values(),
            ),
        ];
    },
    get cloneDestinationOptions() {
        return this.availableDestinations.map(destination => ({
            value: destination.uuid,
            label: destination.name + (destination.uuid == this.currentDestinationUuid ? ' (current)' : ''),
        }));
    },
    get migrateServerOptions() {
        return this.servers
            .filter(server => server.is_functional && server.id != this.currentServerId)
            .map(server => ({
                value: server.id,
                label: `${server.name} (${server.ip})`,
            }));
    },
    get migrateDestinationOptions() {
        return this.availableMigrateDestinations.map(destination => ({
            value: destination.uuid,
            label: destination.name,
        }));
    },
    get cloneProjectOptions() {
        return this.projects.map(project => ({
            value: project.id,
            label: project.name + (project.id == this.currentProjectId ? ' (current)' : ''),
        }));
    },
    get cloneEnvironmentOptions() {
        return this.availableCloneEnvironments.map(environment => ({
            value: environment.id,
            label: environment.name + (environment.id == this.currentEnvironmentId ? ' (current)' : ''),
        }));
    },
    get moveProjectOptions() {
        return this.projects.map(project => ({
            value: project.id,
            label: project.name + (project.id == this.currentProjectId ? ' (current)' : ''),
        }));
    },
    get moveEnvironmentOptions() {
        return this.availableEnvironments.map(environment => ({
            value: environment.id,
            label: environment.name,
        }));
    }
}" x-init="
    selectedCloneServer = currentServerId;
    selectedCloneDestination = currentDestinationUuid;
    selectedCloneProject = currentProjectId;
    selectedCloneEnvironment = currentEnvironmentId;
    selectedMigrateServer = null;
    selectedMigrateDestination = null;
    $watch('selectedCloneServer', () => selectedCloneDestination = null);
    $watch('selectedCloneProject', () => selectedCloneEnvironment = null);
    $watch('selectedMigrateServer', () => selectedMigrateDestination = null);
    $watch('selectedMoveProject', () => selectedMoveEnvironment = null);
" class="flex flex-col gap-6">
    @can('update', $resource)
        @if (($resource instanceof \App\Models\Application || $resource instanceof \App\Models\Service) && $resource->usesLegacyComposeParser())
            <x-application.settings-section id="migrate-parser-section" title="Migrate to the latest parser"
                helper="Coolify parses the Compose file of this resource with an older parser. A new resource uses the latest parser.">
                <x-slot:actions>
                    <x-status-badge label="Parser {{ $resource->compose_parsing_version }}" type="warning" />
                </x-slot:actions>
                <ol class="grid gap-4 text-[13px] sm:grid-cols-3">
                    @foreach ([
                        ['copy', 'Migrate', 'Coolify copies this resource and its volume data. This resource stops for the copy, then starts again.'],
                        ['globe', 'Check and deploy the copy', 'The copy uses the same domains. Change them, or stop this resource first.'],
                        ['trash', 'Delete this resource', 'When the copy works, you do not need this resource.'],
                    ] as $index => [$icon, $title, $text])
                        <li class="flex gap-3">
                            <span class="flex size-7 shrink-0 items-center justify-center rounded-md border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.04] dark:text-fg-dim">
                                <x-reicon :name="$icon" class="size-3.5" />
                            </span>
                            <div class="min-w-0">
                                <p class="font-medium text-black dark:text-fg">{{ $index + 1 }}. {{ $title }}</p>
                                <p class="mt-0.5 text-[12px] leading-4 text-neutral-500 dark:text-fg-dim">{{ $text }}</p>
                            </div>
                        </li>
                    @endforeach
                </ol>

                <div class="mt-4 flex justify-end border-t border-neutral-200 pt-4 dark:border-white/[0.07]">
                    @can('deploy', $resource)
                        <x-modal-confirmation title="Migrate to the latest parser?" submitAction="migrateParser"
                            :confirmWithText="false" :confirmWithPassword="false" step2ButtonText="Migrate"
                            warningMessage="This resource is unavailable while Coolify copies its volume data. Coolify does not change or delete it."
                            :actions="[
                                'Coolify makes a copy of this resource with the latest parser, on the same server and in the same environment.',
                                'Data that this resource writes after the copy is not in the copy.',
                                'The copy uses the same domains. Change them, or stop this resource before you deploy the copy.',
                            ]">
                            <x-slot:trigger>
                                <x-forms.button>Migrate</x-forms.button>
                            </x-slot:trigger>
                        </x-modal-confirmation>
                    @endcan
                </div>
            </x-application.settings-section>
        @endif

        <x-application.settings-section id="clone-resource-section" title="Clone resource"
            helper="Create a copy of this resource. Choose where it runs and where it belongs. The current values are selected by default.">
            <x-callout type="info" title="What is copied">
                Cloning copies settings, environment variables, and resource configuration. Stored files,
                database records, and other persistent data are copied only when you select Clone volume data.
            </x-callout>

            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <x-forms.listbox id="clone-resource-server" label="Server" :wire="false"
                    x-model="selectedCloneServer" x-effect="options = cloneServerOptions"
                    placeholder="Choose a server…" />

                <x-forms.listbox id="clone-resource-destination" label="Network destination" :wire="false"
                    x-model="selectedCloneDestination" x-effect="options = cloneDestinationOptions"
                    x-bind:disabled="selectedCloneServer === null || selectedCloneServer === ''"
                    placeholder="Choose a destination…"
                    emptyText="No network destinations are available on this server." />

                <x-forms.listbox id="clone-resource-project" label="Project" :wire="false"
                    x-model="selectedCloneProject" x-effect="options = cloneProjectOptions"
                    placeholder="Choose a project…" />

                <x-forms.listbox id="clone-resource-environment" label="Environment" :wire="false"
                    x-model="selectedCloneEnvironment" x-effect="options = cloneEnvironmentOptions"
                    x-bind:disabled="!selectedCloneProject || availableCloneEnvironments.length === 0"
                    placeholder="Choose an environment…" />
            </div>

            <div class="mt-4 flex">
                <x-forms.checkbox id="cloneVolumeData" live label="Clone volume data"
                    helper="Copies the data of the persistent volumes to the clone. A running source resource is stopped while the data is copied and started again afterwards." />
            </div>

            <div
                class="mt-4 flex flex-col gap-3 border-t border-neutral-200 pt-4 sm:flex-row sm:items-center sm:justify-between dark:border-white/[0.07]">
                @if ($cloneVolumeData)
                    <p class="text-[13px] text-neutral-500 dark:text-fg-dim">
                        A running resource is stopped while its volume data is copied.
                    </p>
                    <x-modal-confirmation title="Clone this resource with its volume data?" submitAction="clone-resource-confirmed"
                        dispatchAction :confirmWithText="false" :confirmWithPassword="false"
                        step2ButtonText="Clone resource"
                        warningMessage="A running resource is unavailable while its volume data is copied."
                        :actions="[
                            'If the resource is running, it is stopped while its volume data is copied.',
                            'The resource is started again after the copy, also when the copy fails.',
                        ]">
                        <x-slot:trigger>
                            <x-forms.button x-bind:disabled="!selectedCloneDestination || !selectedCloneEnvironment">
                                Clone resource
                            </x-forms.button>
                        </x-slot:trigger>
                    </x-modal-confirmation>
                @else
                    <p class="text-[13px] text-neutral-500 dark:text-fg-dim">
                        The running resource will not be changed.
                    </p>
                    <x-forms.button wire:key="clone-resource-without-volume-data"
                        x-bind:disabled="!selectedCloneDestination || !selectedCloneEnvironment"
                        @click="$wire.cloneTo(selectedCloneDestination, selectedCloneEnvironment)">
                        Clone resource
                    </x-forms.button>
                @endif
            </div>
        </x-application.settings-section>

        @if (isDev())
            <x-application.settings-section id="migrate-destination-section" title="Migrate to another server"
                helper="Move this resource to a different validated and reachable server. The resource is stopped and persistent volumes can be transferred.">
                <x-slot:actions>
                    <x-status-badge status="Dev" type="warning" />
                </x-slot:actions>
                <x-callout type="warning" title="Downtime">
                    Migration stops the resource on the source server. After volume transfer finishes, redeploy on
                    the target server. Source volumes are left in place for safety — clean them up manually when you
                    confirm the migration succeeded. Only other servers that are validated and reachable are listed.
                </x-callout>

                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    <x-forms.listbox id="migrate-resource-server" label="Target server" :wire="false"
                        x-model="selectedMigrateServer" x-effect="options = migrateServerOptions"
                        placeholder="Choose a server…"
                        emptyText="No other validated and reachable servers are available." />

                    <x-forms.listbox id="migrate-resource-destination" label="Network destination" :wire="false"
                        x-model="selectedMigrateDestination" x-effect="options = migrateDestinationOptions"
                        x-bind:disabled="selectedMigrateServer === null || selectedMigrateServer === ''"
                        placeholder="Choose a destination…"
                        emptyText="No network destinations are available on this server." />
                </div>

                <div x-show="selectedMigrateDestination" x-cloak class="mt-4 flex">
                    <x-forms.checkbox id="migrateVolumeData" live label="Migrate persistent volume data"
                        helper="Named Docker volumes and bind-mount host paths are transferred when both servers are managed by Coolify." />
                </div>

                <div x-show="selectedMigrateDestination" x-cloak
                    class="mt-4 flex flex-col gap-3 border-t border-neutral-200 pt-4 sm:flex-row sm:items-center sm:justify-between dark:border-white/[0.07]">
                    <p class="text-[13px] text-neutral-500 dark:text-fg-dim">
                        The resource keeps the same UUID and configuration. Only the hosting server and network
                        destination change.
                    </p>
                    <x-modal-confirmation title="Migrate this resource?" submitAction="migrate-resource-confirmed"
                        dispatchAction :confirmWithText="false" :confirmWithPassword="false"
                        step2ButtonText="Migrate resource"
                        :actions="[
                            'The resource will be stopped on the source server.',
                            'Redeploy the resource after the migration completes.',
                        ]">
                        <x-slot:trigger>
                            <x-forms.button>Migrate resource</x-forms.button>
                        </x-slot:trigger>
                    </x-modal-confirmation>
                </div>
            </x-application.settings-section>
        @endif

        <x-application.settings-section id="move-resource-section" title="Move resource"
            helper="Transfer this resource to another project environment without changing the running deployment.">
            @if ($projects->count() > 0)
                <div class="grid gap-4 md:grid-cols-2">
                    <x-forms.listbox id="move-resource-project" label="Project" :wire="false"
                        x-model="selectedMoveProject" x-effect="options = moveProjectOptions"
                        placeholder="Choose a project…" />

                    <x-forms.listbox id="move-resource-environment" label="Environment"
                        helper="The current environment is excluded." :wire="false"
                        x-model="selectedMoveEnvironment" x-effect="options = moveEnvironmentOptions"
                        x-bind:disabled="!selectedMoveProject || availableEnvironments.length === 0"
                        placeholder="Choose an environment…" />
                </div>

                <div x-show="selectedMoveEnvironment" x-cloak
                    class="mt-4 flex flex-col gap-3 border-t border-neutral-200 pt-4 sm:flex-row sm:items-center sm:justify-between dark:border-white/[0.07]">
                    <p class="text-[13px] text-neutral-500 dark:text-fg-dim">
                        All configuration will move with the resource.
                    </p>
                    <x-forms.button @click="$wire.moveTo(selectedMoveEnvironment)">
                        Move resource
                    </x-forms.button>
                </div>
            @else
                <x-empty size="sm" title="No destination environments"
                    description="Create another project or environment before moving this resource."
                    icon-name="projects" />
            @endif
        </x-application.settings-section>
    @else
        <x-callout type="danger" title="Insufficient permissions">
            You do not have permission to clone or move this resource. Contact a team administrator for access.
        </x-callout>
    @endcan
</div>
