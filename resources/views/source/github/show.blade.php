<x-layout>
    <h1>GitHub App</h1>
    <livewire:source.github.change :github_app="$github_app" />
    @if (!$github_app->app_id)
        <form x-data>
            <x-inputs.button x-on:click.prevent="createGithubApp">Create GitHub Application</x-inputs.button>
        </form>
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
    @elseif($github_app->app_id && !$github_app->installation_id)
        <a href="{{ $installation_url }}">
            <x-inputs.button>Install Repositories</x-inputs.button>
        </a>
    @elseif($github_app->app_id && $github_app->installation_id)
        <a href="{{ $installation_url }}">
            <x-inputs.button>Update Repositories</x-inputs.button>
        </a>
    @endif

</x-layout>
