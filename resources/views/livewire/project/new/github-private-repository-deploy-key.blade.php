<div x-data="{ envModalOpen: false }" class="mt-8 flex w-full max-w-none flex-col gap-6 lg:mt-3">
    @if ($current_step === 'private_keys')
        <section class="application-settings-section">
            <div class="application-settings-section-header">
                <div>
                    <h2>Private repository</h2>
                    <p>Choose the SSH key Coolify will use to clone this repository.</p>
                </div>
            </div>
            <div class="application-settings-section-body p-0!">
                @forelse ($private_keys as $key)
                    <button type="button"
                        class="group flex w-full items-center gap-3 border-b border-neutral-200 px-4 py-3 text-left transition-colors last:border-b-0 hover:bg-neutral-50 dark:border-white/[0.06] dark:hover:bg-white/[0.025]"
                        wire:click="setPrivateKey('{{ $key->id }}')"
                        wire:loading.attr="disabled" wire:target="setPrivateKey('{{ $key->id }}')"
                        wire:key="{{ $key->id }}">
                        <div
                            class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-neutral-500 dark:bg-white/[0.06] dark:text-fg-dim">
                            <x-reicon name="keys" class="size-4" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-sm font-semibold text-black dark:text-fg">{{ $key->name }}</div>
                            <p class="mt-0.5 truncate text-xs text-neutral-500 dark:text-fg-dim">
                                {{ $key->description ?: 'SSH deploy key' }}
                            </p>
                        </div>
                    </button>
                @empty
                    <x-empty title="No private keys"
                        description="Create an SSH key before connecting a private repository."
                        icon-name="keys">
                        <x-slot:contents>
                            <a class="button" href="{{ route('security.private-key.index') }}" {{ wireNavigate() }}>
                                Create private key
                            </a>
                        </x-slot:contents>
                    </x-empty>
                @endforelse
            </div>
        </section>
    @endif

    @if ($current_step === 'repository')
        <form wire:submit="submit">
            <section class="application-settings-section">
                <div class="application-settings-section-header">
                    <div>
                        <h2>Repository configuration</h2>
                        <p>Enter the repository location and choose how Coolify should build it.</p>
                    </div>
                    <x-forms.button type="submit" isHighlighted>Continue</x-forms.button>
                </div>
                <div class="application-settings-section-body space-y-5">
                    <x-forms.input id="repository_url" required label="Repository URL"
                        placeholder="git@github.com:owner/repository.git" />
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-forms.input id="branch" required label="Branch" />
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
                            <x-forms.input type="number" required id="port" label="Port"
                                :readonly="$is_static || $build_pack === 'static'" />
                        @endif
                        @if ($is_static)
                            <x-forms.input id="publish_directory" required label="Publish directory" />
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

                    {{-- Smart Scan --}}
                    <div class="border-t border-neutral-200 pt-5 dark:border-white/[0.06]">
                        <h3 class="text-sm font-semibold text-black dark:text-fg">Smart Scan</h3>
                        <p class="mt-0.5 text-xs text-neutral-500 dark:text-fg-dim">Scan for Dockerfiles, Docker Compose files, and environment configuration.</p>

                        <div class="flex items-center gap-3 pt-3">
                            <x-forms.button type="button" wire:click="detectRepository">
                                <span wire:loading.remove wire:target="detectRepository">Detect Repository</span>
                                <span wire:loading wire:target="detectRepository" class="inline-flex items-center gap-2">
                                    <x-loading /> Scanning...
                                </span>
                            </x-forms.button>
                        </div>

                        @if ($detectionRan)
                            <div wire:loading.remove wire:target="detectRepository" class="pt-3">
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
                </div>
            </section>
        </form>

        {{-- Environment Variables Import Modal --}}
        @include('livewire.project.new.partials.env-import-modal')
    @endif
</div>
