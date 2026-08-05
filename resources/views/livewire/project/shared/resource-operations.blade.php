<div x-data="{
    selectedCloneServer: null,
    selectedCloneDestination: null,
    selectedCloneProject: null,
    selectedCloneEnvironment: null,
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
    selectedCloneServer = null;
    selectedCloneDestination = null;
    selectedCloneProject = null;
    selectedCloneEnvironment = null;
    $watch('selectedCloneServer', () => selectedCloneDestination = null);
    $watch('selectedCloneProject', () => selectedCloneEnvironment = null);
    $watch('selectedMoveProject', () => selectedMoveEnvironment = null);
" class="flex flex-col gap-6">
    @can('update', $resource)
        <x-application.settings-section id="clone-destination-section" title="Clone to another destination"
            helper="Create the clone in the current environment on another server or network.">
            <x-callout type="info" title="Configuration only">
                Cloning copies settings, environment variables, and resource configuration. Stored files,
                database records, and other persistent data are not copied.
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
            </div>

            <div x-show="selectedCloneDestination" x-cloak
                class="mt-4 flex flex-col gap-3 border-t border-neutral-200 pt-4 sm:flex-row sm:items-center sm:justify-between dark:border-white/[0.07]">
                <p class="text-[13px] text-neutral-500 dark:text-fg-dim">
                    The running resource will not be changed.
                </p>
                <x-forms.button @click="$wire.cloneTo(selectedCloneDestination)">
                    Clone resource
                </x-forms.button>
            </div>
        </x-application.settings-section>

        <x-application.settings-section id="clone-environment-section" title="Clone to another environment"
            helper="Create the clone in another project environment while keeping the current server and network.">
            <div class="grid gap-4 md:grid-cols-2">
                <x-forms.listbox id="clone-resource-project" label="Project" :wire="false"
                    x-model="selectedCloneProject" x-effect="options = cloneProjectOptions"
                    placeholder="Choose a project…" />

                <x-forms.listbox id="clone-resource-environment" label="Environment" :wire="false"
                    x-model="selectedCloneEnvironment" x-effect="options = cloneEnvironmentOptions"
                    x-bind:disabled="!selectedCloneProject || availableCloneEnvironments.length === 0"
                    placeholder="Choose an environment…" />
            </div>

            <div x-show="selectedCloneEnvironment" x-cloak
                class="mt-4 flex flex-col gap-3 border-t border-neutral-200 pt-4 sm:flex-row sm:items-center sm:justify-between dark:border-white/[0.07]">
                <p class="text-[13px] text-neutral-500 dark:text-fg-dim">
                    Uses {{ data_get($resource, 'destination.server.name') }} ·
                    {{ data_get($resource, 'destination.network') }}.
                </p>
                <x-forms.button
                    @click="$wire.cloneTo(@js($resource->destination->uuid), selectedCloneEnvironment)">
                    Clone resource
                </x-forms.button>
            </div>
        </x-application.settings-section>

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
