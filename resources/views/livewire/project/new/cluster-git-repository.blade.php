<div x-data x-init="$nextTick(() => { if ($refs.autofocusInput) $refs.autofocusInput.focus(); })"
    class="mx-auto mt-8 w-full max-w-[920px] lg:mt-3">
    <form wire:submit="submit">
        <section class="application-settings-section">
            <div class="application-settings-section-header">
                <div>
                    <h2>Public Git repository</h2>
                    <p>Coolify builds the repository on a cluster server and deploys the image.</p>
                </div>
                <x-forms.button type="submit" isHighlighted>Create application</x-forms.button>
            </div>
            <div class="application-settings-section-body space-y-4">
                <x-forms.listbox id="deploymentTarget" label="Deployment target" required
                    helper="Choose automatic cluster placement or a specific cluster server."
                    placeholder="Select a deployment target" :options="$deploymentTargets" />
                <x-forms.input id="repository" label="Repository URL"
                    placeholder="https://github.com/coollabsio/coolify-examples"
                    helper="The https:// URL of a public repository. Coolify reads it without credentials."
                    required autofocus />
                <div class="grid gap-3 sm:grid-cols-2">
                    <x-forms.input id="branch" label="Branch" placeholder="main" required />
                    <x-forms.input id="baseDirectory" label="Base directory" placeholder="/"
                        helper="The directory to build, relative to the repository root. Leave empty for the root." />
                </div>
                <x-forms.listbox id="buildPack" label="Build pack" required :options="$this->buildPackOptions()" />
                <div x-cloak x-show="$wire.buildPack === 'dockerfile'">
                    <x-forms.input id="dockerfileLocation" label="Dockerfile location" placeholder="Dockerfile"
                        helper="Relative to the base directory." />
                </div>
                <x-forms.input id="name" label="Name" placeholder="Defaults to the repository name"
                    helper="Leave empty to name the application after the repository." />
            </div>
        </section>
    </form>
</div>
