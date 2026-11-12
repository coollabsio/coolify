<div x-data x-init="$nextTick(() => { if ($refs.autofocusInput) $refs.autofocusInput.focus(); })"
    class="mt-8 w-full max-w-none lg:mt-3">
    <form wire:submit="submit" class="flex flex-col gap-6">
        <section class="application-settings-section">
            <div class="application-settings-section-header">
                <div>
                    <h2>Public Git repository</h2>
                    <p>Coolify builds the repository on a cluster server and deploys the image.</p>
                </div>
            </div>
            <div class="application-settings-section-body space-y-4">
                <x-forms.listbox id="deploymentTarget" label="Deployment target" required
                    helper="Choose automatic cluster placement or a specific cluster server."
                    placeholder="Select a deployment target" :options="$deploymentTargets" />
                <div>
                    <x-forms.input id="repository" label="Repository URL"
                        placeholder="https://github.com/owner/repository"
                        helper="The https:// URL of a public repository. Coolify reads it without credentials."
                        required autofocus />
                    <p class="mt-2 text-xs text-neutral-500 dark:text-fg-dim">
                        Need a sample? Browse
                        <a class="font-medium text-coollabs hover:underline dark:text-brand-light"
                            href="https://github.com/coollabsio/coolify-examples/" target="_blank">
                            Coolify Examples
                        </a>.
                    </p>
                </div>
            </div>
        </section>

        <section class="application-settings-section">
            <div class="application-settings-section-header">
                <div>
                    <h2>Build configuration</h2>
                    <p>Choose how Coolify builds and runs this repository.</p>
                </div>
                <x-forms.button type="submit" isHighlighted>Continue</x-forms.button>
            </div>
            <div class="application-settings-section-body space-y-5">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-forms.input id="branch" label="Branch" placeholder="main" required />
                    <x-forms.listbox id="buildPack" label="Build pack" required :options="\App\Enums\NodeWorkloadSourceType::gitBuildPackOptions()" />
                </div>
                <div x-cloak x-show="$wire.buildPack === 'dockerfile'">
                    <x-forms.input id="dockerfileLocation" label="Dockerfile location" placeholder="Dockerfile"
                        helper="Relative to the base directory." />
                </div>
                <x-forms.input id="baseDirectory" label="Base directory" placeholder="/"
                    helper="Repository directory used as the build root. Leave empty for the root. Static serves this directory on port 80, so it needs an index.html." />
                <x-forms.input id="name" label="Name" placeholder="Defaults to the repository name"
                    helper="Leave empty to name the application after the repository." />
            </div>
        </section>
    </form>
</div>
