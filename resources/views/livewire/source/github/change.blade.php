<div>
    @if (data_get($github_app, 'app_id'))
        <div class="flex flex-col sm:flex-row sm:items-center gap-2">
            <h1>GitHub App</h1>
        </div>
        <div class="subtitle ">{{ data_get($github_app, 'name') }}</div>
        <div class="navbar-main mb-5">
            <nav class="flex items-center gap-4 overflow-x-scroll sm:overflow-x-hidden scrollbar min-h-10 whitespace-nowrap pt-2">
                <a class="{{ request()->routeIs('source.github.show') ? 'dark:text-white' : '' }}"
                    href="{{ route('source.github.show', ['github_app_uuid' => data_get($github_app, 'uuid')]) }}"
                    {{ wireNavigate() }}>
                    General
                </a>
                <a class="{{ request()->routeIs('source.github.permissions-events') ? 'dark:text-white' : '' }}"
                    href="{{ route('source.github.permissions-events', ['github_app_uuid' => data_get($github_app, 'uuid')]) }}"
                    {{ wireNavigate() }}>
                    Permissions & Events
                </a>
                <a class="{{ request()->routeIs('source.github.resources') ? 'dark:text-white' : '' }}"
                    href="{{ route('source.github.resources', ['github_app_uuid' => data_get($github_app, 'uuid')]) }}"
                    {{ wireNavigate() }}>
                    Resources
                </a>
            </nav>
        </div>
        <livewire:source.github.tabs.general :github-app-uuid="data_get($github_app, 'uuid')"
            :key="'source-github-tab-general-'.data_get($github_app, 'uuid')" />
    @else
        <div class="flex flex-col sm:flex-row sm:items-center gap-2 pb-4">
            <h1>GitHub App</h1>
            <div class="flex gap-2">
                @can('delete', $github_app)
                    <x-modal-confirmation title="Confirm GitHub App Deletion?" isErrorButton buttonTitle="Delete"
                        submitAction="delete" :actions="['The selected GitHub App will be permanently deleted.']" confirmationText="{{ data_get($github_app, 'name') }}"
                        confirmationLabel="Please confirm the execution of the actions by entering the GitHub App Name below"
                        shortConfirmationLabel="GitHub App Name" :confirmWithPassword="false"
                        step2ButtonText="Permanently Delete" />
                @endcan
            </div>
        </div>
        <div class="flex flex-col gap-2">
            @can('create', $github_app)
                <h3>Manual Installation</h3>
                <div class="flex gap-2 items-center">
                    If you want to fill the form manually, you can continue below. Only for advanced users.
                    <x-forms.button wire:click.prevent="createGithubAppManually">
                        Continue
                    </x-forms.button>
                </div>
                <h3>Automated Installation</h3>
                <div class=" pb-5 rounded-sm alert-error">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 stroke-current shrink-0" fill="none"
                        viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                    <span>You must complete this step before you can use this source!</span>
                </div>
            @endcan
            <div class="flex flex-col">
                <div class="pb-10">
                    @can('create', $github_app)
                        @if (!isCloud() || isDev())
                            <div class="flex flex-col sm:flex-row items-start sm:items-end gap-2">
                                <x-forms.select wire:model.live='webhook_endpoint' label="Webhook Endpoint"
                                    helper="All Git webhooks will be sent to this endpoint. <br><br>If you would like to use domain instead of IP address, set your Coolify instance's FQDN in the Settings menu.">
                                    @if ($ipv4)
                                        <option value="{{ $ipv4 }}">Use {{ $ipv4 }}</option>
                                    @endif
                                    @if ($ipv6)
                                        <option value="{{ $ipv6 }}">Use {{ $ipv6 }}</option>
                                    @endif
                                    @if ($fqdn)
                                        <option value="{{ $fqdn }}">Use {{ $fqdn }}</option>
                                    @endif
                                    @if (config('app.url'))
                                        <option value="{{ config('app.url') }}">Use {{ config('app.url') }}</option>
                                    @endif
                                </x-forms.select>
                                <x-forms.button isHighlighted
                                    x-on:click.prevent="createGithubApp('{{ $webhook_endpoint }}','{{ $preview_deployment_permissions }}',{{ $administration }})">
                                    Register Now
                                </x-forms.button>
                            </div>
                        @else
                            <div class="flex flex-col sm:flex-row gap-2">
                                <h2>Register a GitHub App</h2>
                                <x-forms.button isHighlighted
                                    x-on:click.prevent="createGithubApp('{{ $webhook_endpoint }}','{{ $preview_deployment_permissions }}',{{ $administration }})">
                                    Register Now
                                </x-forms.button>
                            </div>
                            <div>You need to register a GitHub App before using this source.</div>
                        @endif

                        <div class="flex flex-col gap-2 pt-4 w-96">
                            <x-forms.checkbox disabled id="default_permissions" label="Mandatory"
                                helper="Contents: read<br>Metadata: read<br>Email: read" />
                            <x-forms.checkbox id="preview_deployment_permissions" label="Preview Deployments "
                                helper="Necessary for updating pull requests with useful comments (deployment status, links, etc.)<br><br>Pull Request: read & write" />
                            {{-- <x-forms.checkbox id="administration" label="Administration (for Github Runners)"
                            helper="Necessary for adding Github Runners to repositories.<br><br>Administration: read & write" /> --}}
                        </div>
                    @else
                        <x-callout type="danger" title="Insufficient Permissions">
                            You don't have permission to create new GitHub Apps. Please contact your team administrator.
                        </x-callout>
                    @endcan
                </div>
            </div>
            <script>
                function createGithubApp(webhook_endpoint, preview_deployment_permissions, administration) {
                    const {
                        organization,
                        uuid,
                        html_url
                    } = @json($github_app);
                    if (!webhook_endpoint) {
                        alert('Please select a webhook endpoint.');
                        return;
                    }
                    let baseUrl = webhook_endpoint;
                    const name = @js($name);
                    const isDev = @js(config('app.env')) ===
                        'local';
                    const devWebhook = @js(config('constants.webhooks.dev_webhook'));
                    if (isDev && devWebhook) {
                        baseUrl = devWebhook;
                    }
                    const webhookBaseUrl = `${baseUrl}/webhooks`;
                    const path = organization ? `organizations/${organization}/settings/apps/new` : 'settings/apps/new';
                    const default_permissions = {
                        contents: 'read',
                        metadata: 'read',
                        emails: 'read',
                        administration: 'read'
                    };
                    const default_events = ['push'];
                    if (preview_deployment_permissions) {
                        default_permissions.pull_requests = 'write';
                        default_events.push('pull_request');
                    }
                    if (administration) {
                        default_permissions.administration = 'write';
                        default_events.push('workflow_job');
                    }

                    const data = {
                        name,
                        url: baseUrl,
                        hook_attributes: {
                            url: `${webhookBaseUrl}/source/github/events`,
                            active: true,
                        },
                        redirect_url: `${webhookBaseUrl}/source/github/redirect`,
                        callback_urls: [`${baseUrl}/login/github/app`],
                        public: false,
                        request_oauth_on_install: false,
                        setup_url: `${webhookBaseUrl}/source/github/install?source=${uuid}`,
                        setup_on_update: true,
                        default_permissions,
                        default_events
                    };
                    const form = document.createElement('form');
                    form.setAttribute('method', 'post');
                    form.setAttribute('action', `${html_url}/${path}?state=${uuid}`);
                    const input = document.createElement('input');
                    input.setAttribute('id', 'manifest');
                    input.setAttribute('name', 'manifest');
                    input.setAttribute('type', 'hidden');
                    input.setAttribute('value', JSON.stringify(data));
                    form.appendChild(input);
                    document.getElementsByTagName('body')[0].appendChild(form);
                    form.submit();
                }
            </script>
    @endif
</div>
