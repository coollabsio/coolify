<div class="mt-8 flex w-full max-w-none flex-col gap-6 lg:mt-3">
    @if ($github_apps->isEmpty())
        <section class="application-settings-section">
            <div class="application-settings-section-header">
                <div>
                    <h2>GitHub App</h2>
                    <p>Connect a GitHub App before selecting a private repository.</p>
                </div>
            </div>
            <x-empty title="No GitHub Apps"
                description="Create an app to grant Coolify access to selected repositories."
                icon-name="sources">
                <x-slot:contents>
                    <x-modal-input buttonTitle="+ Add GitHub App" title="New GitHub App" closeOutside="false">
                        <livewire:source.github.create />
                    </x-modal-input>
                </x-slot:contents>
            </x-empty>
        </section>
    @elseif ($current_step === 'github_apps')
        <section class="application-settings-section">
            <div class="application-settings-section-header">
                <div>
                    <h2>Choose GitHub App</h2>
                    <p>Select the installation that can access the repository you want to deploy.</p>
                </div>
                <x-modal-input buttonTitle="+ Add GitHub App" title="New GitHub App" closeOutside="false">
                    <livewire:source.github.create />
                </x-modal-input>
            </div>
            <div class="application-settings-section-body p-0!">
                @foreach ($github_apps as $ghapp)
                    <button type="button"
                        class="group relative flex w-full items-center gap-3 border-b border-neutral-200 px-4 py-3 text-left transition-colors last:border-b-0 hover:bg-neutral-50 dark:border-white/[0.06] dark:hover:bg-white/[0.025]"
                        wire:click.prevent="loadRepositories({{ $ghapp->id }})"
                        wire:loading.class="coolbox-loading"
                        wire:loading.attr="disabled" wire:target="loadRepositories({{ $ghapp->id }})"
                        wire:key="{{ $ghapp->id }}">
                        <div
                            class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-neutral-500 dark:bg-white/[0.06] dark:text-fg-dim">
                            <x-reicon name="sources" class="size-4" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-sm font-semibold text-black dark:text-fg">
                                {{ data_get($ghapp, 'name') }}
                            </div>
                            <p class="mt-0.5 truncate text-xs text-neutral-500 dark:text-fg-dim">
                                {{ data_get($ghapp, 'html_url') }}
                            </p>
                        </div>
                    </button>
                @endforeach
            </div>
        </section>
    @elseif ($current_step === 'repository')
        <section class="application-settings-section">
            <div class="application-settings-section-header">
                <div>
                    <h2>Choose repository</h2>
                    <p>Search repositories available through {{ $github_app_name }}.</p>
                </div>
                <div class="flex items-center gap-2">
                    <x-forms.button wire:click.prevent="loadRepositories({{ $selected_github_app_id }})">
                        Refresh
                    </x-forms.button>
                    <a target="_blank" class="button" href="{{ $github_app_installation_path }}">
                        GitHub access
                        <x-reicon name="arrow-right" class="size-3.5 -rotate-45" />
                    </a>
                </div>
            </div>
            <div class="application-settings-section-body">
                @if ($repositories->isNotEmpty())
                    <div class="flex items-end gap-2">
                        <x-forms.listbox searchable id="selected_repository_id" label="Repository" required live
                            searchPlaceholder="Search repositories…"
                            :options="$repositories->map(fn ($repository) => [
                                'value' => data_get($repository, 'id'),
                                'label' => data_get($repository, 'full_name', data_get($repository, 'name')),
                            ])->values()->all()" />
                        <x-forms.button :showLoadingIndicator="false" wire:click.prevent="loadBranches"
                            wire:loading.attr="disabled"
                            wire:target="loadBranches,selected_repository_id">
                            <x-loading-on-button wire:loading.delay
                                wire:target="loadBranches,selected_repository_id" />
                            Load repository
                        </x-forms.button>
                    </div>
                @else
                    <x-empty size="sm" title="No repositories available"
                        description="Review this GitHub App installation and grant access to a repository." />
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
                        <x-forms.button type="submit" wire:target="submit" isHighlighted>Continue</x-forms.button>
                    </div>
                    <div class="application-settings-section-body space-y-5">
                        <x-forms.listbox id="deploymentTarget" label="Deployment target" required
                            helper="Choose automatic cluster placement or a specific cluster server."
                            placeholder="Select a deployment target" :options="$deploymentTargets" />
                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-forms.listbox searchable id="selected_branch_name" label="Branch" required
                                searchPlaceholder="Search branches…"
                                :options="$branches->map(fn ($branch) => [
                                    'value' => data_get($branch, 'name'),
                                    'label' => data_get($branch, 'name'),
                                ])->values()->all()" />
                            <x-forms.listbox id="build_pack" label="Build pack" required
                                :options="\App\Enums\NodeWorkloadSourceType::gitBuildPackOptions(withCompose: true)" />
                        </div>
                        <div x-cloak x-show="$wire.build_pack === 'dockerfile'">
                            <x-forms.input id="dockerfileLocation" label="Dockerfile location" placeholder="Dockerfile"
                                helper="Relative to the base directory." />
                        </div>
                        <div x-cloak x-show="$wire.build_pack === '{{ \App\Enums\NodeWorkloadSourceType::COMPOSE_BUILD_PACK }}'">
                            <x-forms.input id="composeLocation" label="Compose file location" placeholder="/docker-compose.yaml"
                                helper="Relative to the base directory. Each service with build: builds its image on a cluster server. Each Compose service becomes an application of one stack." />
                        </div>
                        <x-forms.input id="base_directory" label="Base directory" placeholder="/"
                            helper="Repository directory used as the build root." />
                        <div x-cloak x-show="$wire.build_pack === 'static'">
                            <x-forms.input id="publishDirectory" label="Publish directory" placeholder="/"
                                helper="Directory with the files to serve on port 80, relative to the base directory, for example dist. Static only serves files: it does not install packages or run a build. Empty serves the base directory, so every file in it can be downloaded." />
                        </div>
                    </div>
                </section>
            </form>
        @endif
    @endif
</div>
