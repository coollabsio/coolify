<div class="mt-8 flex w-full max-w-none flex-col gap-6 lg:mt-3">
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
                        wire:click="setPrivateKey({{ $key->id }})"
                        wire:loading.attr="disabled" wire:target="setPrivateKey({{ $key->id }})"
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
                    <x-forms.listbox id="deploymentTarget" label="Deployment target" required
                        helper="Choose automatic cluster placement or a specific cluster server."
                        placeholder="Select a deployment target" :options="$deploymentTargets" />
                    <x-forms.input id="repository_url" required label="Repository URL"
                        placeholder="git@github.com:owner/repository.git"
                        helper="An SSH URL, such as git@github.com:owner/repository.git or ssh://git@host:2222/owner/repository.git. Add the public key of the deploy key to the repository." />
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-forms.input id="branch" required label="Branch" />
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
                    <div x-cloak x-show="$wire.build_pack === '{{ \App\Enums\NodeWorkloadSourceType::COMPOSE_BUILD_PACK }}' && String($wire.deploymentTarget).startsWith('cluster:')">
                        <x-forms.listbox id="stackPlacement" label="Placement"
                            helper="One server runs every service on one server of the cluster. Spread across the cluster puts each service on the least loaded server. Services that share a volume stay on one server."
                            :options="[['value' => 'one', 'label' => 'One server'], ['value' => 'spread', 'label' => 'Spread across the cluster']]" />
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
</div>
