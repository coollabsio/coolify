<div class="mt-8 w-full max-w-none lg:mt-3">
    <form wire:submit="submit" class="flex flex-col gap-6">
        <section class="application-settings-section">
            <div class="application-settings-section-header">
                <div>
                    <h2>Dockerfile</h2>
                    <p>Create an application directly from a Dockerfile without connecting a Git repository. Coolify builds it on a cluster server with an empty build context.</p>
                </div>
                <x-forms.button type="submit" wire:target="submit" isHighlighted>Create application</x-forms.button>
            </div>
            <div class="application-settings-section-body space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-forms.listbox id="deploymentTarget" label="Deployment target" required
                        helper="Choose automatic cluster placement or a specific cluster server."
                        placeholder="Select a deployment target" :options="$deploymentTargets" />
                    <x-forms.input id="name" label="Name" placeholder="Defaults to dockerfile"
                        helper="Leave empty to name the application dockerfile." />
                </div>
            </div>
            <div class="application-settings-section-body p-0!">
                <x-forms.textarea useMonacoEditor monacoEditorLanguage="dockerfile" rows="20"
                    id="dockerfile" autofocus placeholder='FROM nginx
EXPOSE 80
CMD ["nginx", "-g", "daemon off;"]
'></x-forms.textarea>
            </div>
        </section>
    </form>
</div>
