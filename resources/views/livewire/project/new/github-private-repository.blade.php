<div class="mt-8 flex w-full max-w-[1180px] flex-col gap-6 lg:mt-3">
    @if ($github_apps->isEmpty())
        <section class="application-settings-section">
            <div class="application-settings-section-header">
                <div>
                    <h2>GitHub App</h2>
                    <p>Connect a GitHub App before selecting a private repository.</p>
                </div>
            </div>
            <x-empty title="No GitHub Apps"
                description="Create an app to grant Coolify access to selected repositories.">
                <x-slot:icon>
                    <x-reicon name="sources" class="size-5" />
                </x-slot:icon>
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
                        class="group flex w-full items-center gap-3 border-b border-neutral-200 px-4 py-3 text-left transition-colors last:border-b-0 hover:bg-neutral-50 dark:border-white/[0.06] dark:hover:bg-white/[0.025]"
                        wire:click.prevent="loadRepositories({{ $ghapp->id }})"
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
                        <x-reicon name="arrow-right"
                            class="size-4 text-neutral-300 transition-transform group-hover:translate-x-0.5 dark:text-fg-faint" />
                    </button>
                @endforeach
            </div>
        </section>
    @elseif ($current_step === 'repository')
        <section class="application-settings-section">
            <div class="application-settings-section-header">
                <div>
                    <h2>Choose repository</h2>
                    <p>Search repositories available through {{ $github_app->name }}.</p>
                </div>
                <div class="flex items-center gap-2">
                    <x-forms.button wire:click.prevent="loadRepositories({{ $github_app->id }})">
                        Refresh
                    </x-forms.button>
                    <a target="_blank" class="button" href="{{ getInstallationPath($github_app) }}">
                        GitHub access
                        <x-reicon name="arrow-right" class="size-3.5 -rotate-45" />
                    </a>
                </div>
            </div>
            <div class="application-settings-section-body">
                @if ($repositories->isNotEmpty())
                    <div class="flex items-end gap-2">
                        <x-forms.datalist class="w-full" label="Repository"
                            placeholder="Search repositories…" wire:model.live="selected_repository_id">
                            @foreach ($repositories as $repo)
                                <option value="{{ data_get($repo, 'id') }}">{{ data_get($repo, 'name') }}</option>
                            @endforeach
                        </x-forms.datalist>
                        <x-forms.button :showLoadingIndicator="false" wire:click.prevent="loadBranches"
                            wire:target="loadBranches,selected_repository_id">
                            Load repository
                            <x-loading-on-button wire:loading.delay
                                wire:target="loadBranches,selected_repository_id" />
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
                                ['value' => 'nixpacks', 'label' => 'Nixpacks'],
                                ['value' => 'railpack', 'label' => 'Railpack (Beta)'],
                                ['value' => 'static', 'label' => 'Static'],
                                ['value' => 'dockerfile', 'label' => 'Dockerfile'],
                                ['value' => 'dockercompose', 'label' => 'Docker Compose'],
                            ]" />
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

                        @if ($show_is_static)
                            <div class="grid gap-4 sm:grid-cols-2">
                                <x-forms.input type="number" id="port" label="Port"
                                    :readonly="$is_static || $build_pack === 'static'"
                                    helper="Port the application listens on." />
                                <x-forms.listbox id="is_static" label="Output type" onChange="instantSave"
                                    :options="[
                                        ['value' => false, 'label' => 'Web application'],
                                        ['value' => true, 'label' => 'Static site'],
                                    ]" />
                            </div>
                        @endif
                    </div>
                </section>
            </form>
        @endif
    @endif
</div>
