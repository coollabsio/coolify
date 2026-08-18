<div class="mt-8 flex w-full max-w-none flex-col gap-6 lg:mt-3">
    @if ($gitlab_apps->isEmpty())
        <section class="application-settings-section">
            <div class="application-settings-section-header">
                <div>
                    <h2>GitLab App</h2>
                    <p>Connect a GitLab App before selecting a private repository.</p>
                </div>
            </div>
            <div class="application-settings-section-body">
                <x-empty title="No GitLab Apps"
                    description="Create an app to grant Coolify access to selected repositories."
                    icon-name="sources">
                    <x-slot:contents>
                        <x-modal-input buttonTitle="+ Add GitLab App" title="New GitLab App" closeOutside="false">
                            <livewire:source.gitlab.create />
                        </x-modal-input>
                    </x-slot:contents>
                </x-empty>
            </div>
        </section>
    @elseif ($current_step === 'gitlab_apps')
        <section class="application-settings-section">
            <div class="application-settings-section-header">
                <div>
                    <h2>Choose GitLab App</h2>
                    <p>Select the installation that can access the repository you want to deploy.</p>
                </div>
                <x-modal-input buttonTitle="+ Add GitLab App" title="New GitLab App" closeOutside="false">
                    <livewire:source.gitlab.create />
                </x-modal-input>
            </div>
            <div class="application-settings-section-body p-0!">
                @foreach ($gitlab_apps as $glapp)
                    <button type="button"
                        class="group flex w-full items-center gap-3 border-b border-neutral-200 px-4 py-3 text-left transition-colors last:border-b-0 hover:bg-neutral-50 dark:border-white/[0.06] dark:hover:bg-white/[0.025]"
                        wire:click.prevent="loadRepositories({{ $glapp->id }})"
                        wire:loading.attr="disabled" wire:target="loadRepositories({{ $glapp->id }})"
                        wire:key="{{ $glapp->id }}">
                        <div
                            class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-neutral-500 dark:bg-white/[0.06] dark:text-fg-dim">
                            <x-git-icon class="size-4" git="App\Models\GitlabApp" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-sm font-semibold text-black dark:text-fg">
                                {{ data_get($glapp, 'name') }}
                            </div>
                            <p class="mt-0.5 truncate text-xs text-neutral-500 dark:text-fg-dim">
                                {{ data_get($glapp, 'html_url') }}
                            </p>
                        </div>
                        <x-loading wire:loading wire:target="loadRepositories({{ $glapp->id }})" />
                    </button>
                @endforeach
            </div>
        </section>
    @elseif ($current_step === 'repository')
        <section class="application-settings-section">
            <div class="application-settings-section-header">
                <div>
                    <h2>Choose repository</h2>
                    <p>Search repositories available through the selected GitLab App.</p>
                </div>
                @if ($gitlab_app_id)
                    <x-forms.button wire:click.prevent="loadRepositories({{ $gitlab_app_id }})">
                        Refresh
                    </x-forms.button>
                @endif
            </div>
            <div class="application-settings-section-body">
                @if ($repositories->isNotEmpty())
                    <div class="flex items-end gap-2">
                        <x-forms.datalist class="w-full" label="Repository"
                            placeholder="Search repositories…" wire:model.live="selected_project_id">
                            @foreach ($repositories as $repo)
                                <option value="{{ data_get($repo, 'id') }}">
                                    {{ data_get($repo, 'path_with_namespace') }}
                                </option>
                            @endforeach
                        </x-forms.datalist>
                        <x-forms.button :showLoadingIndicator="false" wire:click.prevent="loadBranches"
                            wire:target="loadBranches,selected_project_id">
                            <x-loading-on-button wire:loading.delay
                                wire:target="loadBranches,selected_project_id" />
                            Load repository
                        </x-forms.button>
                    </div>
                @else
                    <x-empty size="sm" title="No repositories available"
                        description="Review this GitLab App configuration and grant access to a repository." />
                @endif
            </div>
        </section>

        @if ($branches->isNotEmpty())
            <form wire:submit="submit">
                <section class="application-settings-section">
                    <div class="application-settings-section-header">
                        <div>
                            <h2>Build configuration</h2>
                            <p>Choose the branch and build strategy for this application.</p>
                        </div>
                        <x-forms.button type="submit" isHighlighted>Continue</x-forms.button>
                    </div>
                    <div class="application-settings-section-body space-y-5">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-forms.listbox id="selected_branch_name" label="Branch" required
                                :options="$branches->map(fn ($branch) => [
                                    'value' => data_get($branch, 'name'),
                                    'label' => data_get($branch, 'name'),
                                ])->values()->all()" />
                            <x-forms.listbox id="build_pack" label="Build pack" required live :options="[
                                ['value' => 'railpack', 'label' => 'Railpack'],
                                ['value' => 'nixpacks', 'label' => 'Nixpacks'],
                                ['value' => 'static', 'label' => 'Static'],
                                ['value' => 'dockerfile', 'label' => 'Dockerfile'],
                                ['value' => 'dockercompose', 'label' => 'Docker Compose'],
                            ]" />
                            @if ($show_is_static)
                                <x-forms.listbox id="is_static" label="Output type" onChange="instantSave"
                                    :options="[
                                        ['value' => false, 'label' => 'Web application'],
                                        ['value' => true, 'label' => 'Static site'],
                                    ]" />
                                <x-forms.input type="number" id="port" label="Port"
                                    :readonly="$is_static || $build_pack === 'static'"
                                    helper="Port the application listens on." />
                            @endif
                            @if ($is_static)
                                <x-forms.input id="publish_directory" label="Publish directory"
                                    helper="Directory containing the generated static assets." />
                            @endif
                        </div>

                        @if ($build_pack === 'dockercompose')
                            <div x-data="{
                                baseDir: @js($base_directory),
                                composeLocation: @js($docker_compose_location),
                                normalize(path) {
                                    if (!path || path.trim() === '') return '/';
                                    const normalized = path.trim().replace(/\/+$/, '');
                                    return normalized.startsWith('/') ? normalized : '/' + normalized;
                                },
                            }" class="grid gap-4 sm:grid-cols-2">
                                <x-forms.input placeholder="/" wire:model.defer="base_directory"
                                    label="Base directory" helper="Repository directory used as the build root."
                                    x-model="baseDir" @blur="baseDir = normalize(baseDir)" />
                                <x-forms.input placeholder="/docker-compose.yaml"
                                    wire:model.defer="docker_compose_location" label="Compose file"
                                    helper="Path relative to the base directory." x-model="composeLocation"
                                    @blur="composeLocation = normalize(composeLocation)" />
                                <p class="sm:col-span-2 text-xs text-neutral-500 dark:text-fg-dim">
                                    Resolved file:
                                    <code class="font-mono text-coollabs dark:text-warning"
                                        x-text='(baseDir === "/" ? "" : baseDir) + (composeLocation.startsWith("/") ? composeLocation : "/" + composeLocation)'></code>
                                </p>
                            </div>
                        @else
                            <x-forms.input wire:model="base_directory" label="Base directory"
                                helper="Repository directory used as the build root." />
                        @endif
                    </div>
                </section>
            </form>
        @endif
    @endif
</div>
