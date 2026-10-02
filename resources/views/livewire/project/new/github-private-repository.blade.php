<div x-data="{ envModalOpen: false }" class="mt-8 flex w-full max-w-none flex-col gap-6 lg:mt-3">
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
                        <x-forms.searchable-listbox id="selected_repository_id" label="Repository" required live
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
            {{-- Repository Detection --}}
            <section class="application-settings-section">
                <div class="application-settings-section-header">
                    <div>
                        <h2>Smart Scan</h2>
                        <p>Detected configuration from your repository.</p>
                    </div>
                </div>
                <div class="application-settings-section-body">
                    <div wire:loading.flex wire:target="detectRepository" class="items-center gap-2 py-3 text-sm dark:text-neutral-400">
                        <x-loading /> Scanning repository for Dockerfiles and configuration...
                    </div>

                    @if ($detectionRan)
                        <div wire:loading.remove wire:target="detectRepository">
                            <div class="flex items-center gap-3 flex-wrap text-sm">
                                @if (count($detectedDockerfiles))
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-sm dark:bg-coolgray-100 border border-neutral-200 dark:border-coolgray-300">
                                        <span class="badge badge-success"></span>
                                        Dockerfile{{ count($detectedDockerfiles) > 1 ? 's' : '' }}
                                        <span class="dark:text-neutral-400">({{ count($detectedDockerfiles) }})</span>
                                    </span>
                                @endif
                                @if (count($detectedDockerComposeFiles))
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-sm dark:bg-coolgray-100 border border-neutral-200 dark:border-coolgray-300">
                                        <span class="badge badge-success"></span>
                                        Docker Compose
                                        <span class="dark:text-neutral-400">({{ count($detectedDockerComposeFiles) }})</span>
                                    </span>
                                @endif
                                @include('livewire.project.new.partials.env-detection-badges')
                                @if (!count($detectedDockerfiles) && !count($detectedDockerComposeFiles) && !count($detectedEnvFiles))
                                    <span class="dark:text-neutral-400">No Dockerfile, Docker Compose, or env files detected.</span>
                                @endif
                            </div>
                        </div>
                    @endif
                </div>
            </section>

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
                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-forms.searchable-listbox id="selected_branch_name" label="Branch" required
                                searchPlaceholder="Search branches…"
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

                        {{-- Dockerfile selector when multiple detected --}}
                        @if ($build_pack === 'dockerfile' && count($detectedDockerfiles) > 1)
                            <x-forms.select wire:model.live="selectedDockerfile" label="Dockerfile"
                                helper="Multiple Dockerfiles were detected in your repository. Select which one to use.">
                                @foreach ($detectedDockerfiles as $df)
                                    <option value="{{ $df }}">{{ $df }}</option>
                                @endforeach
                            </x-forms.select>
                        @endif

                        {{-- Docker Compose file selector when multiple detected --}}
                        @if ($build_pack === 'dockercompose' && count($detectedDockerComposeFiles) > 1)
                            <x-forms.select wire:model.live="selectedDockerComposeFile" label="Docker Compose File"
                                helper="Multiple Docker Compose files were detected. Select which one to use.">
                                @foreach ($detectedDockerComposeFiles as $cf)
                                    <option value="{{ $cf }}">{{ $cf }}</option>
                                @endforeach
                            </x-forms.select>
                        @endif

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

            {{-- Environment Variables Import Modal --}}
            @include('livewire.project.new.partials.env-import-modal')
        @endif
    @endif
</div>
