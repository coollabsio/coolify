<div x-data x-init="$nextTick(() => { if ($refs.autofocusInput) $refs.autofocusInput.focus(); })"
    class="mt-8 flex w-full max-w-[1180px] flex-col gap-6 lg:mt-3">
    <form wire:submit="loadBranch">
        <section class="application-settings-section">
            <div class="application-settings-section-header">
                <div>
                    <h2>Public Git repository</h2>
                    <p>Connect a public repository over HTTPS and inspect its default branch.</p>
                </div>
            </div>
            <div class="application-settings-section-body">
                <div class="flex items-end gap-2">
                    <x-forms.input required id="repository_url" label="Repository URL"
                        helper="{!! __('repository.url') !!}" placeholder="https://github.com/owner/repository"
                        autofocus />
                    <x-forms.button type="submit">Check repository</x-forms.button>
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
                            ['value' => 'nixpacks', 'label' => 'Nixpacks'],
                            ['value' => 'railpack', 'label' => 'Railpack (Beta)'],
                            ['value' => 'static', 'label' => 'Static'],
                            ['value' => 'dockerfile', 'label' => 'Dockerfile'],
                            ['value' => 'dockercompose', 'label' => 'Docker Compose'],
                        ]" />
                        @if ($isStatic)
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
                                :readonly="$isStatic || $build_pack === 'static'"
                                helper="Port the application listens on." />
                            <x-forms.listbox id="isStatic" label="Output type" onChange="instantSave"
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
