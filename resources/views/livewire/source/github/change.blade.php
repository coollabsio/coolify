<div x-data="{ deleteSource: false }">
    <x-naked-modal show="deleteSource" message='Are you sure you would like to delete this source?' />
    <form wire:submit.prevent='submit'>
        <div class="flex items-center gap-2">
            <h1>GitHub App</h1>
            <div class="flex gap-2 ">
                @if ($github_app->app_id)
                    <x-inputs.button type="submit">Save</x-inputs.button>
                    <x-inputs.button x-on:click.prevent="deleteSource = true">
                        Delete
                    </x-inputs.button>
                    <a href="{{ $installation_url }}">
                        <x-inputs.button>
                            @if ($github_app->installation_id)
                                Update Repositories
                                <x-external-link />
                            @else
                                Install Repositories
                                <x-external-link />
                            @endif
                        </x-inputs.button>
                    </a>
                @else
                    <x-inputs.button disabled type="submit">Save</x-inputs.button>
                    <x-inputs.button x-on:click.prevent="deleteSource = true">
                        Delete
                    </x-inputs.button>
                    <form x-data>
                        <x-inputs.button isHighlighted x-on:click.prevent="createGithubApp">Create GitHub Application
                        </x-inputs.button>
                    </form>
                @endif
            </div>
        </div>

        <x-inputs.input id="github_app.name" label="App Name" required />

        @if ($github_app->app_id)
            <x-inputs.input id="github_app.organization" label="Organization" disabled
                placeholder="Personal user if empty" />
        @else
            <x-inputs.input id="github_app.organization" label="Organization" placeholder="Personal user if empty" />
        @endif
        <x-inputs.input id="github_app.api_url" label="API Url" disabled />
        <x-inputs.input id="github_app.html_url" label="HTML Url" disabled />
        <x-inputs.input id="github_app.custom_user" label="User" required />
        <x-inputs.input type="number" id="github_app.custom_port" label="Port" required />

        @if ($github_app->app_id)
            <x-inputs.input type="number" id="github_app.app_id" label="App Id" disabled />
            <x-inputs.input type="number" id="github_app.installation_id" label="Installation Id" disabled />
            <x-inputs.input id="github_app.client_id" label="Client Id" type="password" disabled />
            <x-inputs.input id="github_app.client_secret" label="Client Secret" type="password" disabled />
            <x-inputs.input id="github_app.webhook_secret" label="Webhook Secret" type="password" disabled />
            <x-inputs.checkbox noDirty label="System Wide?" instantSave id="is_system_wide" />
        @else
            <x-inputs.checkbox noDirty label="System Wide?" instantSave id="is_system_wide" />
            <div class="py-2">

            </div>
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
