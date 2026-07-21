<div>
    @if ($isConnected)
        <form wire:submit='submit'>
            <div class="flex flex-col sm:flex-row sm:items-center gap-2">
                <h1>GitLab App</h1>
                <div class="flex gap-2">
                    <x-forms.button canGate="update" :canResource="$gitlab_app" type="submit">Save</x-forms.button>
                    <x-forms.button wire:click.prevent="testConnection">Test Connection</x-forms.button>
                    @can('delete', $gitlab_app)
                        <x-modal-confirmation title="Confirm GitLab App Deletion?" isErrorButton buttonTitle="Delete"
                            submitAction="delete" :actions="['The selected GitLab App will be permanently deleted.']"
                            confirmationText="{{ data_get($gitlab_app, 'name') }}"
                            confirmationLabel="Please confirm by entering the GitLab App Name below"
                            shortConfirmationLabel="GitLab App Name" :confirmWithPassword="false"
                            step2ButtonText="Permanently Delete" />
                    @endcan
                </div>
            </div>
            <div class="subtitle">Your GitLab App for private repositories.</div>
            <div class="flex items-center gap-2 mb-4">
                <span class="inline-flex items-center gap-1 px-2 py-1 text-xs font-medium rounded bg-success/10 text-success">
                    <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 8 8"><circle cx="4" cy="4" r="3" /></svg>
                    Connected
                </span>
                <x-forms.button wire:click.prevent="disconnect"
                    class="bg-transparent border-transparent hover:bg-transparent hover:border-transparent hover:underline text-xs">
                    Disconnect
                </x-forms.button>
            </div>
            <div class="flex flex-col gap-2">
                <x-forms.input canGate="update" :canResource="$gitlab_app" id="name" label="Name" />
                @if (!isCloud())
                    <div class="w-48">
                        <x-forms.checkbox canGate="update" :canResource="$gitlab_app" label="System Wide"
                            helper="If checked, this GitLab App will be available for everyone in this Coolify instance."
                            instantSave id="isSystemWide" />
                    </div>
                    @if ($isSystemWide)
                        <x-callout type="warning" title="Not Recommended">
                            System-wide GitLab Apps are shared across all teams on this Coolify instance. This means any team
                            can use this GitLab App to deploy applications from your repositories. For better security and
                            isolation, it's recommended to create team-specific GitLab Apps instead.
                        </x-callout>
                    @endif
                @endif

                <h3 class="pt-4">OAuth Credentials</h3>
                <x-forms.input canGate="update" :canResource="$gitlab_app" id="clientId" label="Application ID" />
                <x-forms.input canGate="update" :canResource="$gitlab_app" id="clientSecretInput" label="Application Secret" type="password" />
                <x-forms.input canGate="update" :canResource="$gitlab_app" id="groupName" label="Group Name"
                    helper="Comma-separated group names to filter visible repositories." />

                <div x-data="{
                                        activeAccordion: '',
                                        setActiveAccordion(id) {
                                            this.activeAccordion = (this.activeAccordion == id) ? '' : id
                                        }
                                    }" class="relative w-full py-2 mx-auto overflow-hidden text-sm font-normal rounded-md">
                    <div x-data="{ id: $id('accordion') }" class="cursor-pointer">
                        <button @click="setActiveAccordion(id)"
                            class="flex items-center justify-between w-full px-1 py-2 text-left select-none dark:hover:text-white hover:bg-white/5"
                            type="button">
                            <h4>Advanced / Self-hosted</h4>
                            <svg class="w-4 h-4 duration-200 ease-out" :class="{ 'rotate-180': activeAccordion == id }"
                                viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor"
                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="6 9 12 15 18 9"></polyline>
                            </svg>
                        </button>
                        <div x-show="activeAccordion==id" x-collapse x-cloak class="px-2">
                            <div class="flex flex-col gap-2 pt-0 opacity-70">
                                <div class="flex gap-2">
                                    <x-forms.input canGate="update" :canResource="$gitlab_app" id="htmlUrl" label="GitLab URL" />
                                    <x-forms.input canGate="update" :canResource="$gitlab_app" id="apiUrl" label="API URL" />
                                </div>
                                <div class="flex gap-2">
                                    <x-forms.input canGate="update" :canResource="$gitlab_app" id="customUser" label="SSH User" />
                                    <x-forms.input canGate="update" :canResource="$gitlab_app" type="number" id="customPort" label="SSH Port" />
                                </div>
                                <div class="flex gap-2">
                                    <x-forms.select canGate="update" :canResource="$gitlab_app" id="privateKeyId" label="SSH Private Key (optional)">
                                        <option value="">None</option>
                                        @foreach ($privateKeys as $key)
                                            <option value="{{ $key->id }}">{{ $key->name }}</option>
                                        @endforeach
                                    </x-forms.select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <h3 class="pt-4">Webhook</h3>
                <div class="flex flex-col gap-1">
                    <div class="text-sm">
                        Configure this webhook URL in your GitLab project settings
                        (<code>Settings &gt; Webhooks</code>):
                    </div>
                    <x-forms.input readonly label="Webhook URL"
                        value="{{ $webhook_endpoint }}/webhooks/source/gitlab/events" />
                    <x-forms.input canGate="update" :canResource="$gitlab_app" id="webhookToken" label="Webhook Secret Token"
                        helper="Set this same token in your GitLab webhook's 'Secret token' field." />
                </div>

                @if ($applications->count() > 0)
                    <h3 class="pt-4">Applications Using This Source</h3>
                    <div class="flex flex-col gap-2">
                        @foreach ($applications as $application)
                            <a class="coolbox group"
                                href="{{ route('project.application.configuration', [
                                    'project_uuid' => data_get($application, 'environment.project.uuid'),
                                    'environment_uuid' => data_get($application, 'environment.uuid'),
                                    'application_uuid' => data_get($application, 'uuid'),
                                ]) }}">
                                <div class="text-left dark:group-hover:text-white flex flex-col justify-center mx-6">
                                    <div class="box-title">{{ $application->name }}</div>
                                    <div class="box-description">{{ $application->git_repository }}:{{ $application->git_branch }}</div>
                                </div>
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>
        </form>
    @else
        <div class="flex flex-col sm:flex-row sm:items-center gap-2">
            <h1>GitLab App</h1>
            <div class="flex gap-2">
                @can('delete', $gitlab_app)
                    <x-modal-confirmation title="Confirm GitLab App Deletion?" isErrorButton buttonTitle="Delete"
                        submitAction="delete" :actions="['The selected GitLab App will be permanently deleted.']"
                        confirmationText="{{ data_get($gitlab_app, 'name') }}"
                        confirmationLabel="Please confirm by entering the GitLab App Name below"
                        shortConfirmationLabel="GitLab App Name" :confirmWithPassword="false"
                        step2ButtonText="Permanently Delete" />
                @endcan
            </div>
        </div>
        <div class="subtitle">Connect your GitLab instance to deploy private repositories.</div>

        <div class="mb-6 rounded-sm alert-error">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 stroke-current shrink-0" fill="none"
                viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
            <span>You must complete this step before you can use this source!</span>
        </div>

        <div class="flex flex-col gap-4">
            <h3>Step 1: Create an OAuth Application on GitLab</h3>
            <div class="text-sm flex flex-col gap-1">
                <p>Go to your GitLab instance and create a new OAuth Application:</p>
                <a href="{{ rtrim($htmlUrl, '/') }}/-/profile/applications" target="_blank"
                    class="inline-flex items-center gap-1 text-sm underline">
                    {{ rtrim($htmlUrl, '/') }}/-/profile/applications
                    <x-external-link />
                </a>
                <ul class="list-disc list-inside mt-2 space-y-1">
                    <li>Set <strong>Redirect URI</strong> to: <code>{{ $redirectUri }}</code></li>
                    <li>Enable scopes: <code>api</code>, <code>read_user</code>, <code>read_repository</code></li>
                    <li>Uncheck <strong>Confidential</strong> if you run into issues</li>
                </ul>
            </div>

            <form wire:submit='submit' class="flex flex-col gap-2">
                <div class="flex flex-col sm:flex-row sm:items-center gap-2 pt-2">
                    <h3>Step 2: Enter the credentials</h3>
                    <x-forms.button type="submit">Save Credentials</x-forms.button>
                </div>
                <x-forms.input id="name" label="Name" />
                <x-forms.input id="clientId" label="Application ID" required
                    helper="The Application ID from your GitLab OAuth Application." />
                <x-forms.input id="clientSecretInput" label="Application Secret" type="password" required
                    helper="The Secret from your GitLab OAuth Application." />
                <x-forms.input id="groupName" label="Group Name"
                    helper="Optional. Comma-separated group names to filter repositories." />

                <div x-data="{
                                        activeAccordion: '',
                                        setActiveAccordion(id) {
                                            this.activeAccordion = (this.activeAccordion == id) ? '' : id
                                        }
                                    }" class="relative w-full py-2 mx-auto overflow-hidden text-sm font-normal rounded-md">
                    <div x-data="{ id: $id('accordion') }" class="cursor-pointer">
                        <button @click="setActiveAccordion(id)"
                            class="flex items-center justify-between w-full px-1 py-2 text-left select-none dark:hover:text-white hover:bg-white/5"
                            type="button">
                            <h4>Advanced / Self-hosted</h4>
                            <svg class="w-4 h-4 duration-200 ease-out" :class="{ 'rotate-180': activeAccordion == id }"
                                viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor"
                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="6 9 12 15 18 9"></polyline>
                            </svg>
                        </button>
                        <div x-show="activeAccordion==id" x-collapse x-cloak class="px-2">
                            <div class="flex flex-col gap-2 pt-0 opacity-70">
                                <div class="flex gap-2">
                                    <x-forms.input id="htmlUrl" label="GitLab URL"
                                        helper="Only change this for self-hosted GitLab (e.g. https://gitlab.example.com)." />
                                    <x-forms.input id="apiUrl" label="API URL"
                                        helper="Usually your GitLab URL with /api/v4 appended." />
                                </div>
                                <div class="flex gap-2">
                                    <x-forms.input id="customUser" label="SSH User" />
                                    <x-forms.input type="number" id="customPort" label="SSH Port" />
                                </div>
                                @if (!isCloud())
                                    <div class="w-48">
                                        <x-forms.checkbox label="System Wide" id="isSystemWide"
                                            helper="If checked, this GitLab App will be available for everyone in this Coolify instance." />
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </form>

            @if ($clientId)
                <h3 class="pt-2">Step 3: Authorize with GitLab</h3>
                <div class="text-sm">Click the button below to authorize Coolify with your GitLab instance.</div>
                <a href="{{ $this->getOAuthUrl() }}" class="w-fit">
                    <x-forms.button class="mt-2">
                        Connect to GitLab
                    </x-forms.button>
                </a>
            @endif
        </div>
    @endif
</div>
