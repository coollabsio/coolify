<div x-data="{ deleteSource: false }">
    <x-naked-modal show="deleteSource" title="Delete Source"
        message='This source will be deleted. It is not reversible. <br>Please think again.' />
    <form wire:submit.prevent='submit'>
        <div class="flex items-center gap-2">
            <h1>GitHub App</h1>
            <div class="flex gap-2">
                @if ($github_app->app_id)
                    <x-forms.button type="submit">Save</x-forms.button>
                    <x-forms.button x-on:click.prevent="deleteSource = true">
                        Delete
                    </x-forms.button>
                    <a href="{{ get_installation_path($github_app) }}">
                        <x-forms.button>
                            @if ($github_app->installation_id)
                                Update Repositories
                                <x-external-link />
                            @else
                                Install Repositories
                                <x-external-link />
                            @endif
                        </x-forms.button>
                    </a>
                @else
                    <x-forms.button disabled type="submit">Save</x-forms.button>
                    <x-forms.button x-on:click.prevent="deleteSource = true">
                        Delete
                    </x-forms.button>

                @endif
            </div>
        </div>
        <div class="pt-2 pb-10 ">Your Private GitHub App for private repositories.</div>
        @if ($github_app->app_id)
            <div class="w-48">
                <x-forms.checkbox noDirty label="System Wide?"
                    helper="If checked, this GitHub App will be available for everyone in this Coolify instance."
                    instantSave id="is_system_wide" />
            </div>
            <div class="flex gap-2">
                <x-forms.input id="github_app.name" label="App Name" disabled />
                <x-forms.input id="github_app.organization" label="Organization" disabled
                    placeholder="If empty, personal user will be used" />
            </div>
            <div class="flex gap-2">
                <x-forms.input id="github_app.html_url" label="HTML Url" disabled />
                <x-forms.input id="github_app.api_url" label="API Url" disabled />
            </div>
            <div class="flex gap-2">
                @if ($github_app->html_url === 'https://github.com')
                    <x-forms.input id="github_app.custom_user" label="User" disabled />
                    <x-forms.input type="number" id="github_app.custom_port" label="Port" disabled />
                @else
                    <x-forms.input id="github_app.custom_user" label="User" required />
                    <x-forms.input type="number" id="github_app.custom_port" label="Port" required />
                @endif
            </div>
            <div class="flex gap-2">
                <x-forms.input type="number" id="github_app.app_id" label="App Id" disabled />
                <x-forms.input type="number" id="github_app.installation_id" label="Installation Id" disabled />
            </div>
            <div class="flex gap-2">
                <x-forms.input id="github_app.client_id" label="Client Id" type="password" disabled />
                <x-forms.input id="github_app.client_secret" label="Client Secret" type="password" />
                <x-forms.input id="github_app.webhook_secret" label="Webhook Secret" type="password" />
            </div>
        @else
            <form class="flex gap-4">
                <div class="flex items-end gap-2">
                    <h3>Register a GitHub App</h3>
                    <x-forms.button
                        x-on:click.prevent="createGithubApp('{{ $webhook_endpoint }}','{{ $preview_deployment_permissions }}')">
                        Register a
                        GitHub
                        Application
                    </x-forms.button>
                </div>
                <div class="pt-1 pb-2 ">You need to register a GitHub App before using this source.</div>
                <div class="pt-2 pb-10">
                    <div class="flex items-end gap-2">
                        <x-forms.select wire:model='webhook_endpoint' label="Webhook Endpoint"
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
                        </x-forms.select>
                    </div>
                    <div class="flex flex-col gap-2 pt-4">
                        <x-forms.checkbox disabled instantSave id="default_permissions" label="Default Permissions"
                            helper="Contents: read<br>Metadata: read<br>Email: read" />
                        <x-forms.checkbox instantSave id="preview_deployment_permissions"
                            label="Preview Deployments Permission"
                            helper="Necessary for updating pull requests with useful comments (deployment status, links, etc.)<br><br>Pull Request: read & write" />
                    </div>
                </div>
            </form>
            <div class="flex gap-2">
                <x-forms.input id="github_app.name" label="App Name" disabled />
                <x-forms.input id="github_app.organization" label="Organization"
                    placeholder="If empty, personal user will be used" disabled />
            </div>
            <div class="flex gap-2">
                <x-forms.input id="github_app.html_url" label="HTML Url" disabled />
                <x-forms.input id="github_app.api_url" label="API Url" disabled />
            </div>
            <div class="flex gap-2">
                @if ($github_app->html_url === 'https://github.com')
                    <x-forms.input id="github_app.custom_user" label="User" disabled />
                    <x-forms.input type="number" id="github_app.custom_port" label="Port" disabled />
                @else
                    <x-forms.input id="github_app.custom_user" label="User" required />
                    <x-forms.input type="number" id="github_app.custom_port" label="Port" required />
                @endif
            </div>
            <x-forms.checkbox
                helper="If checked, this GitHub App will be available for everyone in this Coolify instance." noDirty
                label="System Wide?" disabled id="is_system_wide" />
            <script>
                function createGithubApp(webhook_endpoint, preview_deployment_permissions) {
                    const {
                        organization,
                        uuid,
                        html_url
                    } = @json($github_app);
                    let baseUrl = webhook_endpoint;
                    const name = @js($name);
                    const isDev = @js(config('app.env')) === 'local';
                    const devWebhook = @js(config('coolify.dev_webhook'));
                    if (isDev && devWebhook) {
                        baseUrl = devWebhook;
                    }
                    const webhookBaseUrl = `${baseUrl}/webhooks`;
                    const path = organization ? `organizations/${organization}/settings/apps/new` : 'settings/apps/new';
                    const default_permissions = {
                        contents: 'read',
                        metadata: 'read',
                        emails: 'read'
                    };
                    if (preview_deployment_permissions) {
                        default_permissions.pull_requests = 'write';
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
                        default_events: ['pull_request', 'push']
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
    </form>
</div>
