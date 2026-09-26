<div x-data="{ envModalOpen: false }" x-init="$nextTick(() => { if ($refs.autofocusInput) $refs.autofocusInput.focus(); })"
    class="mt-8 flex w-full max-w-none flex-col gap-6 lg:mt-3">
    <form wire:submit="loadBranch">
        <section class="application-settings-section">
            <div class="application-settings-section-header">
                <div>
                    <h2>Public Git repository</h2>
                    <p>Connect a public repository over HTTPS and inspect its default branch.</p>
                </div>
            </div>
            <div class="application-settings-section-body">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
                    <div class="min-w-0 flex-1">
                        <x-forms.input required id="repository_url" label="Repository URL"
                            helper="{!! __('repository.url') !!}" placeholder="https://github.com/owner/repository"
                            autofocus />
                    </div>
                    <x-forms.button type="submit" class="w-full justify-center sm:w-auto"
                        wire:loading.attr="disabled" wire:target="loadBranch" :showLoadingIndicator="false">
                        <svg wire:loading wire:target="loadBranch" class="size-3.5 shrink-0 animate-spin"
                            viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <circle class="opacity-25" cx="12" cy="12" r="9" stroke="currentColor"
                                stroke-width="3" />
                            <path class="opacity-75" d="M21 12a9 9 0 0 0-9-9" stroke="currentColor"
                                stroke-width="3" stroke-linecap="round" />
                        </svg>
                        Check repository
                    </x-forms.button>
                </div>
                <p class="mt-2 text-xs text-neutral-500 dark:text-fg-dim">
                    Need a sample? Browse
                    <a class="font-medium text-coollabs hover:underline dark:text-warning"
                        href="https://github.com/coollabsio/coolify-examples/" target="_blank">
                        Coolify Examples
                    </a>.
                </p>
            </div>
        </section>
    </form>

    @if ($branchFound)
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
                        <p>Choose how Coolify builds and runs this repository.</p>
                    </div>
                    <x-forms.button type="submit" isHighlighted>Continue</x-forms.button>
                </div>
                <div class="application-settings-section-body space-y-5">
                    @if ($rate_limit_remaining && $rate_limit_reset)
                        <x-callout type="info" title="Git provider rate limit">
                            {{ $rate_limit_remaining }} requests remain. The limit resets at
                            {{ $rate_limit_reset }} UTC.
                        </x-callout>
                    @endif

                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-forms.input id="git_branch" label="Branch"
                            :disabled="$git_source !== 'other'"
                            helper="You can choose another branch after the application is created." />
                        <x-forms.listbox id="build_pack" label="Build pack" required live :options="[
                            ['value' => 'railpack', 'label' => 'Railpack'],
                            ['value' => 'nixpacks', 'label' => 'Nixpacks'],
                            ['value' => 'static', 'label' => 'Static'],
                            ['value' => 'dockerfile', 'label' => 'Dockerfile'],
                            ['value' => 'dockercompose', 'label' => 'Docker Compose'],
                        ]" />
                        @if ($show_is_static)
                            <x-forms.listbox id="isStatic" label="Output type" onChange="instantSave"
                                :options="[
                                    ['value' => false, 'label' => 'Web application'],
                                    ['value' => true, 'label' => 'Static site'],
                                ]" />
                            <x-forms.input type="number" id="port" label="Port"
                                :readonly="$isStatic || $build_pack === 'static'"
                                helper="Port the application listens on." />
                        @endif
                        @if ($isStatic)
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
</div>
