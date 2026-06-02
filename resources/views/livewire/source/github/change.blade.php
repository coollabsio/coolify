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
        <div class="flex items-center justify-center min-h-[calc(100vh-12rem)]">
            <div class="mx-auto grid w-full max-w-5xl grid-cols-1 gap-4 lg:grid-cols-2">
            @can('create', $github_app)
                <section class="box-without-bg flex-col gap-4 p-6 h-full transition-all duration-200"
                    x-data="{
                        webhookEndpoint: $wire.entangle('webhook_endpoint').live,
                        useCustomWebhookEndpoint: $wire.entangle('use_custom_webhook_endpoint').live,
                        customWebhookEndpoint: $wire.entangle('custom_webhook_endpoint').live,
                    }">
                    <div class="flex flex-col gap-4 text-left h-full">
                        <div class="flex items-center justify-between">
                            <svg class="size-10" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" />
                            </svg>
                            <span
                                class="px-2 py-1 text-xs font-bold uppercase tracking-wide bg-coollabs/10 dark:bg-warning/20 text-coollabs dark:text-warning rounded">
                                Recommended
                            </span>
                        </div>
                        <div>
                            <h3 class="text-xl font-bold mb-2">Automated Installation</h3>
                            <p class="text-sm dark:text-neutral-400">
                                Register a GitHub App via GitHub's manifest flow. Permissions and webhooks are pre-configured.
                            </p>
                        </div>
                        <div class="flex flex-col gap-3 pt-4 border-t border-neutral-200 dark:border-coolgray-400">
                            @if (!isCloud() || isDev())
                                <x-forms.checkbox canGate="create" :canResource="$github_app"
                                    x-model="useCustomWebhookEndpoint" id="use_custom_webhook_endpoint"
                                    label="Use custom webhook endpoint"
                                    helper="Enable this when the public URL GitHub should call differs from Coolify's configured URL, for example behind Cloudflare Tunnel." />
                                <div x-show="!useCustomWebhookEndpoint">
                                    <x-forms.select canGate="create" :canResource="$github_app"
                                        wire:model.live='webhook_endpoint' x-model="webhookEndpoint"
                                        label="Selected endpoint"
                                        helper="GitHub will use this endpoint unless custom mode is enabled.">
                                        @if ($fqdn)
                                            <option value="{{ $fqdn }}">Use {{ $fqdn }}</option>
                                        @endif
                                        @if ($ipv4)
                                            <option value="{{ $ipv4 }}">Use {{ $ipv4 }}</option>
                                        @endif
                                        @if ($ipv6)
                                            <option value="{{ $ipv6 }}">Use {{ $ipv6 }}</option>
                                        @endif
                                        @if (config('app.url'))
                                            <option value="{{ config('app.url') }}">Use {{ config('app.url') }}</option>
                                        @endif
                                    </x-forms.select>
                                </div>
                                <div x-cloak x-show="useCustomWebhookEndpoint">
                                    <x-forms.input canGate="create" :canResource="$github_app"
                                        x-model="customWebhookEndpoint" id="custom_webhook_endpoint" type="url"
                                        label="Custom endpoint" placeholder="https://coolify.example.com"
                                        helper="GitHub will use this custom public URL. Do not include /webhooks." />
                                </div>
                            @else
                                <div class="text-sm dark:text-neutral-400">You need to register a GitHub App before using this source.</div>
                            @endif

                            <div class="flex w-full flex-col gap-2">
                                <x-forms.checkbox canGate="create" :canResource="$github_app" disabled id="default_permissions" label="Mandatory"
                                    helper="Contents: read<br>Metadata: read<br>Email: read" />
                                <x-forms.checkbox canGate="create" :canResource="$github_app" id="preview_deployment_permissions" label="Preview Deployments"
                                    helper="Necessary for updating pull requests with useful comments (deployment status, links, etc.)<br><br>Pull Request: read & write" />
                            </div>
                        </div>
                        <div class="mt-auto pt-2">
                            <x-forms.button canGate="create" :canResource="$github_app" class="w-full justify-center" isHighlighted
                                x-on:click.prevent="createGithubApp(webhookEndpoint, useCustomWebhookEndpoint, customWebhookEndpoint, {{ Illuminate\Support\Js::from($preview_deployment_permissions) }}, {{ Illuminate\Support\Js::from($administration) }})">
                                Register Now
                            </x-forms.button>
                        </div>
                    </div>
                </section>

                <section class="box-without-bg flex-col gap-4 p-6 h-full transition-all duration-200">
                    <div class="flex flex-col gap-4 text-left h-full">
                        <div class="flex items-center justify-between">
                            <svg class="size-10" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M11.42 15.17L17.25 21A2.652 2.652 0 0021 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 11-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 004.486-6.336l-3.276 3.277a3.004 3.004 0 01-2.25-2.25l3.276-3.276a4.5 4.5 0 00-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085m-1.745 1.437L5.909 7.5H4.5L2.25 3.75l1.5-1.5L7.5 4.5v1.409l4.26 4.26m-1.745 1.437l1.745-1.437m6.615 8.206L15.75 15.75M4.867 19.125h.008v.008h-.008v-.008z" />
                            </svg>
                            <span
                                class="px-2 py-1 text-xs font-bold uppercase tracking-wide bg-neutral-100 dark:bg-coolgray-300 dark:text-neutral-400 rounded">
                                Advanced
                            </span>
                        </div>
                        <div>
                            <h3 class="text-xl font-bold mb-2">Manual Installation</h3>
                            <p class="text-sm dark:text-neutral-400">
                                Fill the GitHub App form manually. For self-hosted GitHub Enterprise or custom permission setups.
                            </p>
                        </div>
                        <div class="mt-auto pt-2">
                            <x-forms.button canGate="create" :canResource="$github_app" class="w-full justify-center" wire:click.prevent="createGithubAppManually">
                                Continue
                            </x-forms.button>
                        </div>
                    </div>
                </section>
            @else
                <div class="pb-10">
                    <x-callout type="danger" title="Insufficient Permissions">
                        You don't have permission to create new GitHub Apps. Please contact your team administrator.
                    </x-callout>
                </div>
            @endcan
            </div>
        </div>
            <script>
                function createGithubApp(webhook_endpoint, use_custom_webhook_endpoint, custom_webhook_endpoint, preview_deployment_permissions, administration) {
                    const {
                        organization,
                        html_url,
                        uuid
                    } = @js($github_app->only(['organization', 'html_url', 'uuid']));
                    const selectedEndpoint = webhook_endpoint ? webhook_endpoint.trim() : '';
                    const customEndpoint = custom_webhook_endpoint ? custom_webhook_endpoint.trim() : '';
                    if (use_custom_webhook_endpoint && !customEndpoint) {
                        alert('Please enter a custom webhook endpoint.');
                        return;
                    }
                    if (!use_custom_webhook_endpoint && !selectedEndpoint) {
                        alert('Please enter a webhook endpoint.');
                        return;
                    }
                    let baseUrl = (use_custom_webhook_endpoint ? customEndpoint : selectedEndpoint).replace(/\/+$/, '');
                    const name = @js($name);
                    const manifestState = @js($manifestState);
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
                    form.setAttribute('action', `${html_url}/${path}?state=${manifestState}`);
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
