<x-layout>
    <h1>GitHub App</h1>
    <livewire:source.github.change :github_app="$github_app" />
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
            const host = @js($host);
            const name = @js($name);
            let url = 'settings/apps/new';
            if (organization) {
                organization = `'organizations/${organization}/settings/apps/new`;
            }
            const data = {
                name,
                url: host,
                hook_attributes: {
                    url: `${host}/webhooks/github/events`
                },
                redirect_url: `${host}/webhooks/github`,
                callback_urls: [`${host}/login/github/app`],
                public: false,
                request_oauth_on_install: false,
                setup_url: `${host}/webhooks/github/install?source=${uuid}`,
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
            form.setAttribute('action', `${html_url}/${url}?state=${uuid}`);
            const input = document.createElement('input');
            input.setAttribute('id', 'manifest');
            input.setAttribute('name', 'manifest');
            input.setAttribute('type', 'hidden');
            input.setAttribute('value', data);
            form.appendChild(input);
            document.getElementsByTagName('body')[0].appendChild(form);
            form.submit();
        }
    </script>
</x-layout>
