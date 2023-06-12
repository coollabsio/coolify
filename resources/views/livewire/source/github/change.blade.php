<div x-data="{ deleteSource: false }">
    <x-naked-modal show="deleteSource" message='Are you sure you would like to delete this source?' />
    <form wire:submit.prevent='submit'>
        <div class="flex items-center gap-2">
            <h1>GitHub App</h1>
            <div class="flex gap-2">
                @if ($github_app->app_id)
                    <x-forms.button type="submit">Save</x-forms.button>
                    <x-forms.button x-on:click.prevent="deleteSource = true">
                        Delete
                    </x-forms.button>
                    <a href="{{ $installation_url }}">
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
        <div class="pt-2 pb-10 text-sm">Your Private GitHub App for private repositories.</div>
        @if (!$github_app->app_id)
            <div class="flex items-center gap-4 pt-2 pb-10">
                <div class="text-sm">You need to register a GitHub App before using this source:</div>
                <form>
                    <x-forms.button x-on:click.prevent="createGithubApp">Register a GitHub
                        Application
                    </x-forms.button>
                </form>
            </div>
        @endif
        <div class="flex gap-2">
            <x-forms.input id="github_app.name" label="App Name" required />

            @if ($github_app->app_id)
                <x-forms.input id="github_app.organization" label="Organization" disabled
                    placeholder="Personal user if empty" />
            @else
                <x-forms.input id="github_app.organization" label="Organization" placeholder="Personal user if empty" />
            @endif
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
        @if ($github_app->app_id)
            <div class="flex gap-2">
                <x-forms.input type="number" id="github_app.app_id" label="App Id" disabled />
                <x-forms.input type="number" id="github_app.installation_id" label="Installation Id" disabled />
            </div>
            <div class="flex gap-2">
                <x-forms.input id="github_app.client_id" label="Client Id" type="password" disabled />
                <x-forms.input id="github_app.client_secret" label="Client Secret" type="password" disabled />
                <x-forms.input id="github_app.webhook_secret" label="Webhook Secret" type="password" />
            </div>
            <x-forms.checkbox noDirty label="System Wide?"
                helper="If checked, this GitHub App will be available for everyone in this Coolify instance."
                instantSave id="is_system_wide" />
        @else
            <x-forms.checkbox
                helper="If checked, this GitHub App will be available for everyone in this Coolify instance." noDirty
                label="System Wide?" instantSave id="is_system_wide" />
        @endif
    </form>
    @if (!$github_app->app_id)
        <script>
            function createGithubApp() {
                const {
                    organization,
                    uuid,
                    html_url
                } = @json($github_app);
                let baseUrl = @js($host);
                const name = @js($name);
                const isDev = @js(config('app.env')) === 'local';
                const devWebhook = @js(config('coolify.dev_webhook'));
                if (isDev && devWebhook) {
                    baseUrl = devWebhook;
                }
                const webhookBaseUrl = `${baseUrl}/webhooks`;
                const path = organization ? `organizations/${organization}/settings/apps/new` : 'settings/apps/new';
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
                    default_permissions: {
                        contents: 'read',
                        metadata: 'read',
                        pull_requests: 'read',
                        emails: 'read'
                    },
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
</div>
