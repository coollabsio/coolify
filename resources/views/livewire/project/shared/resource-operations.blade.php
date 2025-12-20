<div>
    <h2>{{ __('resource_ops.title') }}</h2>
    <div>{{ __('resource_ops.description') }}</div>

    <div x-data="{
        selectedCloneServer: null,
        selectedCloneDestination: null,
        selectedMoveProject: null,
        selectedMoveEnvironment: null,
        currentProjectId: {{ $resource->environment->project->id }},
        currentEnvironmentId: {{ $resource->environment->id }},
        servers: @js(
    $servers->map(
        fn($s) => [
            'id' => $s->id,
            'name' => $s->name,
            'ip' => $s->ip,
            'destinations' => $s->destinations()->map(
                fn($d) => [
                    'id' => $d->id,
                    'name' => $d->name,
                    'server_id' => $s->id,
                ],
            ),
        ],
    ),
),
        projects: @js(
    $projects->map(
        fn($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'environments' => $p->environments->map(
                fn($e) => [
                    'id' => $e->id,
                    'name' => $e->name,
                    'project_id' => $p->id,
                ],
            ),
        ],
    ),
),
        get availableDestinations() {
            if (!this.selectedCloneServer) return [];
            const server = this.servers.find(s => s.id == this.selectedCloneServer);
            return server ? server.destinations : [];
        },
        get availableEnvironments() {
            if (!this.selectedMoveProject) return [];
            const project = this.projects.find(p => p.id == this.selectedMoveProject);
            if (!project) return [];
            // Filter out the current environment if we're in the same project
            return project.environments.filter(e => {
                if (project.id === this.currentProjectId) {
                    return e.id !== this.currentEnvironmentId;
                }
                return true;
            });
        },
        get isCurrentProjectSelected() {
            return this.selectedMoveProject == this.currentProjectId;
        }
    }">
        <h3 class="pt-4">{{ __('resource_ops.clone_title') }}</h3>
        <div class="pb-2">{{ __('resource_ops.clone_desc') }}</div>

        @can('update', $resource)
            <div class="space-y-4 pb-8">
                <div class="flex flex-col lg:flex-row gap-4">
                    <div class="flex-1">
                        <label class="block text-sm font-medium mb-2">{{ __('resource_ops.select_server') }}</label>
                        <select x-model="selectedCloneServer" @change="selectedCloneDestination = null" class="select">
                            <option value="">{{ __('resource_ops.choose_server') }}</option>
                            <template x-for="server in servers" :key="server.id">
                                <option :value="server.id" x-text="`${server.name} (${server.ip})`"></option>
                            </template>
                        </select>
                    </div>

                    <div class="flex-1">
                        <label class="block text-sm font-medium mb-2">{{ __('resource_ops.select_destination') }}</label>
                        <select x-model="selectedCloneDestination" :disabled="!selectedCloneServer" class="select">
                            <option value="">{{ __('resource_ops.choose_destination') }}</option>
                            <template x-for="destination in availableDestinations" :key="destination.id">
                                <option :value="destination.id" x-text="destination.name"></option>
                            </template>
                        </select>
                    </div>
                </div>

                <div x-show="selectedCloneDestination" x-cloak>
                    <x-forms.button isHighlighted @click="$wire.cloneTo(selectedCloneDestination)" class="mt-2">
                        {{ __('resource_ops.clone_button') }}
                    </x-forms.button>
                    <div class="mt-2 text-sm text-neutral-600 dark:text-neutral-400">
                        {{ __('resource_ops.clone_notice') }}
                    </div>
                </div>
            </div>
        @else
            <x-callout type="warning" title="{{ __('resource_ops.clone_restricted') }}">
                {{ __('resource_ops.clone_no_permission') }}
            </x-callout>
        @endcan

        <h3 class="pt-4">{{ __('resource_ops.move_title') }}</h3>
        <div class="pb-4">{{ __('resource_ops.move_desc') }}</div>

        @can('update', $resource)
            @if ($projects->count() > 0)
                <div class="space-y-4">
                    <div class="flex flex-col lg:flex-row gap-4">
                        <div class="flex-1">
                            <label class="block text-sm font-medium mb-2">{{ __('resource_ops.select_project') }}</label>
                            <select x-model="selectedMoveProject" @change="selectedMoveEnvironment = null" class="select">
                                <option value="">{{ __('resource_ops.choose_project') }}</option>
                                <template x-for="project in projects" :key="project.id">
                                    <option :value="project.id"
                                        x-text="project.name + (project.id === currentProjectId ? ' {{ __('resource_ops.current_marker') }}' : '')">
                                    </option>
                                </template>
                            </select>
                        </div>

                        <div class="flex-1">
                            <label class="block text-sm font-medium mb-2 flex gap-2 items-center">{{ __('resource_ops.select_environment') }}
                                <x-helper helper="{{ __('resource_ops.environment_helper') }}" />
                            </label>
                            <select x-model="selectedMoveEnvironment"
                                :disabled="!selectedMoveProject || availableEnvironments.length === 0" class="select">
                                <option value=""
                                    x-text="availableEnvironments.length === 0 && isCurrentProjectSelected ? '{{ __('resource_ops.no_environments') }}' : '{{ __('resource_ops.choose_environment') }}'">
                                </option>
                                <template x-for="environment in availableEnvironments" :key="environment.id">
                                    <option :value="environment.id"
                                        x-text="environment.name + (environment.id === currentEnvironmentId ? ' {{ __('resource_ops.current_marker') }}' : '')">
                                    </option>
                                </template>
                            </select>
                        </div>
                    </div>

                    <div x-show="selectedMoveEnvironment" x-cloak>
                        <x-forms.button isHighlighted @click="$wire.moveTo(selectedMoveEnvironment)" class="mt-2">
                            {{ __('resource_ops.move_button') }}
                        </x-forms.button>
                        <div class="mt-2 text-sm text-neutral-600 dark:text-neutral-400">
                            {{ __('resource_ops.move_notice') }}
                        </div>
                    </div>
                </div>
            @else
                <div class="text-neutral-600 dark:text-neutral-400">{{ __('resource_ops.no_projects') }}
                </div>
            @endif
        @else
            <x-callout type="warning" title="{{ __('resource_ops.clone_restricted') }}">
                {{ __('resource_ops.move_no_permission') }}
            </x-callout>
        @endcan
    </div>
</div>
