<div>
    <form wire:submit='submit' class="flex flex-col gap-2">
        <div class="flex flex-col sm:flex-row sm:items-center gap-2">
            <h2>General</h2>
            <div class="flex gap-2">
                @if (data_get($github_app, 'installation_id'))
                    <x-forms.button canGate="update" :canResource="$github_app" type="submit">Save</x-forms.button>
                @endif
                @can('delete', $github_app)
                    @if ($applications->count() > 0)
                        <x-modal-confirmation title="Confirm GitHub App Deletion?" isErrorButton buttonTitle="Delete"
                            submitAction="delete" :actions="['The selected GitHub App will be permanently deleted.']"
                            confirmationText="{{ data_get($github_app, 'name') }}"
                            confirmationLabel="Please confirm the execution of the actions by entering the GitHub App Name below"
                            shortConfirmationLabel="GitHub App Name" :confirmWithPassword="false"
                            step2ButtonText="Permanently Delete" />
                    @else
                        <x-modal-confirmation title="Confirm GitHub App Deletion?" isErrorButton buttonTitle="Delete"
                            submitAction="delete" :actions="['The selected GitHub App will be permanently deleted.']"
                            confirmationLabel="Please confirm the execution of the actions by entering the GitHub App Name below"
                            shortConfirmationLabel="GitHub App Name" confirmationText="{{ data_get($github_app, 'name') }}"
                            :confirmWithPassword="false" step2ButtonText="Permanently Delete" />
                    @endif
                @endcan
            </div>
        </div>

        @if (!data_get($github_app, 'installation_id'))
            <div class="rounded-sm alert-error">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 stroke-current shrink-0" fill="none"
                    viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
                <span>You must complete this step before you can use this source!</span>
            </div>
            <a class="items-center justify-center coolbox" href="{{ getInstallationPath($github_app) }}">
                Install Repositories on GitHub
            </a>
        @else
            <div class="flex flex-col gap-2">
                <div class="flex flex-col sm:flex-row gap-2">
                    <div class="flex flex-col sm:flex-row items-start sm:items-end gap-2 w-full">
                        <x-forms.input canGate="update" :canResource="$github_app" id="name" label="App Name" />
                        <x-forms.button canGate="update" :canResource="$github_app" wire:click.prevent="updateGithubAppName">
                            Sync Name
                        </x-forms.button>
                        @can('update', $github_app)
                            <a href="{{ $this->getGithubAppNameUpdatePath() }}">
                                <x-forms.button
                                    class="bg-transparent border-transparent hover:bg-transparent hover:border-transparent hover:underline">
                                    Rename
                                    <x-external-link />
                                </x-forms.button>
                            </a>
                            <a href="{{ getInstallationPath($github_app) }}" class="w-fit">
                                <x-forms.button
                                    class="bg-transparent border-transparent hover:bg-transparent hover:border-transparent hover:underline whitespace-nowrap">
                                    Update Repositories
                                    <x-external-link />
                                </x-forms.button>
                            </a>
                        @endcan
                    </div>
                </div>
                <x-forms.input canGate="update" :canResource="$github_app" id="organization" label="Organization"
                    placeholder="If empty, personal user will be used" />
                @if (!isCloud())
                    <div class="w-48">
                        <x-forms.checkbox canGate="update" :canResource="$github_app" label="System Wide?"
                            helper="If checked, this GitHub App will be available for everyone in this Coolify instance."
                            instantSave id="isSystemWide" />
                    </div>
                    @if ($isSystemWide)
                        <x-callout type="warning" title="Not Recommended">
                            System-wide GitHub Apps are shared across all teams on this Coolify instance. This means any team can use this GitHub App to deploy applications from your repositories. For better security and isolation, it's recommended to create team-specific GitHub Apps instead.
                        </x-callout>
                    @endif
                @endif
                <div class="flex flex-col sm:flex-row gap-2">
                    <x-forms.input canGate="update" :canResource="$github_app" id="htmlUrl" label="HTML Url" />
                    <x-forms.input canGate="update" :canResource="$github_app" id="apiUrl" label="API Url" />
                </div>
                <div class="flex flex-col sm:flex-row gap-2">
                    <x-forms.input canGate="update" :canResource="$github_app" id="customUser" label="User"
                        required />
                    <x-forms.input canGate="update" :canResource="$github_app" type="number" id="customPort"
                        label="Port" required />
                </div>
                <div class="flex flex-col sm:flex-row gap-2">
                    <x-forms.input canGate="update" :canResource="$github_app" type="number" id="appId"
                        label="App Id" required />
                    <x-forms.input canGate="update" :canResource="$github_app" type="number" id="installationId"
                        label="Installation Id" required />
                </div>
                <div class="flex flex-col sm:flex-row gap-2">
                    <x-forms.input canGate="update" :canResource="$github_app" id="clientId" label="Client Id"
                        type="password" required />
                    <x-forms.input canGate="update" :canResource="$github_app" id="clientSecret"
                        label="Client Secret" type="password" required />
                    <x-forms.input canGate="update" :canResource="$github_app" id="webhookSecret"
                        label="Webhook Secret" type="password" required />
                </div>
                <div class="flex gap-2">
                    <x-forms.select canGate="update" :canResource="$github_app" id="privateKeyId" label="Private Key"
                        required>
                        @if (blank($github_app->private_key_id))
                            <option value="0" selected>Select a private key</option>
                        @endif
                        @foreach ($privateKeys as $privateKey)
                            <option value="{{ $privateKey->id }}">{{ $privateKey->name }}</option>
                        @endforeach
                    </x-forms.select>
                </div>
            </div>
        @endif
    </form>
</div>
