<div class="mt-8 flex w-full max-w-[1180px] flex-col gap-6 lg:mt-3">
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
                        <x-reicon name="arrow-right"
                            class="size-4 text-neutral-300 transition-transform group-hover:translate-x-0.5 dark:text-fg-faint" />
                    </button>
                @empty
                    <x-empty title="No private keys"
                        description="Create an SSH key before connecting a private repository.">
                        <x-slot:icon>
                            <x-reicon name="keys" class="size-5" />
                        </x-slot:icon>
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
                            ['value' => 'nixpacks', 'label' => 'Nixpacks'],
                            ['value' => 'railpack', 'label' => 'Railpack (Beta)'],
                            ['value' => 'static', 'label' => 'Static'],
                            ['value' => 'dockerfile', 'label' => 'Dockerfile'],
                            ['value' => 'dockercompose', 'label' => 'Docker Compose'],
                        ]" />
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

                    @if ($show_is_static)
                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-forms.input type="number" required id="port" label="Port"
                                :readonly="$is_static || $build_pack === 'static'" />
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
</div>
