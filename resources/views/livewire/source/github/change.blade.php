<div>
    @if (data_get($github_app, 'app_id'))
        <form wire:submit='submit'>
            <div class="flex items-center gap-2">
                <h1>GitHub App</h1>
                <div class="flex gap-2">
                    @if (data_get($github_app, 'installation_id'))
                        <x-forms.button type="submit">Save</x-forms.button>
                        <a href="{{ get_installation_path($github_app) }}">
                            <x-forms.button>
                                Update Repositories
                                <x-external-link />
                            </x-forms.button>
                        </a>
                    @endif
                    @if ($applications->count() > 0)
                        <x-modal-confirmation disabled isErrorButton buttonTitle="Delete">
                            This source will be deleted. It is not reversible. <br>Please think again.
                        </x-modal-confirmation>
                    @else
                        <x-modal-confirmation isErrorButton buttonTitle="Delete">
                            This source will be deleted. It is not reversible. <br>Please think again.
                        </x-modal-confirmation>
                    @endif
                </div>
            </div>
            <div class="subtitle">Your Private GitHub App for private repositories.</div>
            @if (!data_get($github_app, 'installation_id'))
                <div class="mb-10 rounded alert-error">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 stroke-current shrink-0" fill="none"
                        viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                    <span>You must complete this step before you can use this source!</span>
                </div>
                <a class="items-center justify-center box" href="{{ get_installation_path($github_app) }}">
                    Install Repositories on GitHub
                </a>
            @else
                <div class="flex flex-col gap-2">
                    <div class="flex gap-2">
                        <x-forms.input id="github_app.name" label="App Name" disabled />
                        <x-forms.input id="github_app.organization" label="Organization" disabled
                            placeholder="If empty, personal user will be used" />
                    </div>
                    @if (!isCloud())
                        <div class="w-48">
                            <x-forms.checkbox label="System Wide?"
                                helper="If checked, this GitHub App will be available for everyone in this Coolify instance."
                                instantSave id="github_app.is_system_wide" />
                        </div>
                    @endif
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
                        <x-forms.input type="number" id="github_app.installation_id" label="Installation Id"
                            disabled />
                    </div>
                    <div class="flex gap-2">
                        <x-forms.input id="github_app.client_id" label="Client Id" type="password" disabled />
                        <x-forms.input id="github_app.client_secret" label="Client Secret" type="password" />
                        <x-forms.input id="github_app.webhook_secret" label="Webhook Secret" type="password" />
                    </div>
                    <div class="flex items-end gap-2 ">
                        <h2 class="pt-4">Permissions</h2>
                        <x-forms.button wire:click.prevent="checkPermissions">Refetch</x-forms.button>
                        <a href="{{ get_permissions_path($github_app) }}">
                            <x-forms.button>
                                Update
                                <x-external-link />
                            </x-forms.button>
                        </a>
                    </div>
                    <div class="flex gap-2">
                        <x-forms.input id="github_app.contents" helper="read - mandatory." label="Content" readonly
                            placeholder="N/A" />
                        <x-forms.input id="github_app.metadata" helper="read - mandatory." label="Metadata" readonly
                            placeholder="N/A" />
                        {{-- <x-forms.input id="github_app.administration"
                            helper="read:write access needed to setup servers as GitHub Runner." label="Administration"
                            readonly placeholder="N/A" /> --}}
                        <x-forms.input id="github_app.pull_requests"
                            helper="write access needed to use deployment status update in previews."
                            label="Pull Request" readonly placeholder="N/A" />
                    </div>
                </div>
            @endif
        </form>
        @if (data_get($github_app, 'installation_id'))
            <div class="w-full pt-10">
                <div class="h-full">
                    <div class="flex flex-col">
                        <div class="flex gap-2">
                            <h2>Resources</h2>
                        </div>
                        <div class="pb-4 title">Here you can find all resources that are used by this source.</div>
                    </div>
                    <div class="flex flex-col">
                        <div class="flex flex-col">
                            <div class="overflow-x-auto">
                                <div class="inline-block min-w-full">
                                    <div class="overflow-hidden">
                                        <table class="min-w-full">
                                            <thead>
                                                <tr>
                                                    <th class="px-5 py-3 text-xs font-medium text-left uppercase">
                                                        Project
                                                    </th>
                                                    <th class="px-5 py-3 text-xs font-medium text-left uppercase">
                                                        Environment</th>
                                                    <th class="px-5 py-3 text-xs font-medium text-left uppercase">Name
                                                    </th>
                                                    <th class="px-5 py-3 text-xs font-medium text-left uppercase">Type
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y">
                                                @forelse ($applications->sortBy('name',SORT_NATURAL) as $resource)
                                                    <tr>
                                                        <td class="px-5 py-4 text-sm whitespace-nowrap">
                                                            {{ data_get($resource->project(), 'name') }}
                                                        </td>
                                                        <td class="px-5 py-4 text-sm whitespace-nowrap">
                                                            {{ data_get($resource, 'environment.name') }}
                                                        </td>
                                                        <td class="px-5 py-4 text-sm whitespace-nowrap"><a
                                                                class=""
                                                                href="{{ $resource->link() }}">{{ $resource->name }}
                                                                <x-internal-link /></a>
                                                        </td>
                                                        <td class="px-5 py-4 text-sm whitespace-nowrap">
                                                            {{ str($resource->type())->headline() }}</td>
                                                    </tr>
                                                @empty
                                                @endforelse
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    @else
        <div class="flex items-center gap-2 pb-4">
            <h1>GitHub App</h1>
            <div class="flex gap-2">
                <x-modal-confirmation isErrorButton buttonTitle="Delete">
                    This source will be deleted. It is not reversible. <br>Please think again.
                </x-modal-confirmation>
            </div>
        </div>
        <div class="mb-10 rounded alert-error">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 stroke-current shrink-0" fill="none"
                viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
            <span>You must complete this step before you can use this source!</span>
        </div>
        <div class="flex flex-col">
            <div class="flex gap-2">
                <h2>Register a GitHub App</h2>
                <x-forms.button class="bg-coollabs hover:bg-coollabs-100"
                    x-on:click.prevent="createGithubApp('{{ $webhook_endpoint }}','{{ $preview_deployment_permissions }}',{{ $administration }})">
                    Register Now
                </x-forms.button>
            </div>
            <div>You need to register a GitHub App before using this source.</div>
            <div class="py-10">
                @if (!isCloud() || isDev())
                    <div class="flex items-end gap-2">
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
                    </div>
                @endif
                <div class="flex flex-col gap-2 pt-4 w-96">
                    <x-forms.checkbox disabled instantSave id="default_permissions" label="Mandatory"
                        helper="Contents: read<br>Metadata: read<br>Email: read" />
                    <x-forms.checkbox instantSave id="preview_deployment_permissions" label="Preview Deployments "
                        helper="Necessary for updating pull requests with useful comments (deployment status, links, etc.)<br><br>Pull Request: read & write" />
                    {{-- <x-forms.checkbox instantSave id="administration" label="Administration (for Github Runners)"
                        helper="Necessary for adding Github Runners to repositories.<br><br>Administration: read & write" /> --}}
                </div>
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
                const devWebhook = @js(config('coolify.dev_webhook'));
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
                if (preview_deployment_permissions) {
                    default_permissions.pull_requests = 'write';
                }
                if (administration) {
                    default_permissions.administration = 'write';
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
</div>
