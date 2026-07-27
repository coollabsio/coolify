<div x-data="{
    selectedCloneServer: null,
    selectedCloneDestination: null,
    selectedMoveProject: null,
    selectedMoveEnvironment: null,
    currentProjectId: {{ $resource->environment->project->id }},
    currentEnvironmentId: {{ $resource->environment->id }},
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
        if (!this.selectedCloneServer) return [];
        const server = this.servers.find(server => server.id == this.selectedCloneServer);
        return server ? server.destinations : [];
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
                label: `${server.name} (${server.ip})`,
            })),
            ...@js(
                $buildServers->map(
                    fn ($server) => [
                        'value' => "build-{$server->id}",
                        'label' => "{$server->name} — build server",
                        'disabled' => true,
                    ],
                )->values(),
            ),
        ];
    },
    get cloneDestinationOptions() {
        return this.availableDestinations.map(destination => ({
            value: destination.uuid,
            label: destination.name,
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
    $watch('selectedCloneServer', () => selectedCloneDestination = null);
    $watch('selectedMoveProject', () => selectedMoveEnvironment = null);
" class="flex flex-col gap-6">
    @can('update', $resource)
        <x-application.settings-section id="clone-resource-section" title="Clone resource"
            helper="Duplicate this resource configuration onto another server and network destination.">
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
                    x-bind:disabled="!selectedCloneServer" placeholder="Choose a destination…" />
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
                    description="Create another project or environment before moving this resource.">
                    <x-slot:icon>
                        <x-reicon name="projects" class="size-8" />
                    </x-slot:icon>
                </x-empty>
            @endif
        </x-application.settings-section>
    @else
        <x-callout type="danger" title="Insufficient permissions">
            You do not have permission to clone or move this resource. Contact a team administrator for access.
        </x-callout>
    @endcan
</div>
