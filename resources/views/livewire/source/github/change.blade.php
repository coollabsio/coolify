<div>
    <x-slot:title>
        {{ $github_app->name ?: 'GitHub App' }} | Sources | Coolify
    </x-slot>

    @if (data_get($github_app, 'app_id'))
        <x-dashboard.navbar section="source" :parameters="['github_app_uuid' => $github_app->uuid]">
            <x-slot:actions>
                @if (data_get($github_app, 'installation_id'))
                    <x-status-badge label="Connected" type="success" />
                @else
                    <x-status-badge label="Setup incomplete" type="warning" />
                @endif
                @can('delete', $github_app)
                    <x-modal-confirmation title="Confirm GitHub App Deletion?" isErrorButton
                        buttonTitle="Delete" submitAction="delete"
                        :actions="['The selected GitHub App will be permanently deleted.']"
                        confirmationText="{{ data_get($github_app, 'name') }}"
                        confirmationLabel="Please confirm the execution of the actions by entering the GitHub App Name below"
                        shortConfirmationLabel="GitHub App Name" :confirmWithPassword="false"
                        step2ButtonText="Permanently Delete" />
                @endcan
            </x-slot:actions>
        </x-dashboard.navbar>

        @if (!data_get($github_app, 'installation_id'))
            <div class="application-settings-form">
                <x-application.settings-section title="Complete GitHub installation"
                    description="Choose which repositories this GitHub App can access before using it as a source.">
                    <div class="flex flex-col items-start gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex items-start gap-3">
                            <div
                                class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-warning/10 text-warning">
                                <x-reicon name="alert-triangle" class="size-4" />
                            </div>
                            <p class="max-w-xl text-[12px] leading-5 text-neutral-500 dark:text-fg-dim">
                                Repository access has not been installed yet. Complete this step before attaching the
                                source to an application.
                            </p>
                        </div>
                        <a class="button shrink-0 bg-coollabs/10! text-coollabs! ring-1 ring-coollabs/25 hover:bg-coollabs/15! dark:bg-warning/15! dark:text-warning! dark:ring-warning/25 dark:hover:bg-warning/20!"
                            href="{{ getInstallationPath($github_app) }}">
                            Install repositories
                            <x-external-link />
                        </a>
                    </div>
                </x-application.settings-section>
            </div>
        @elseif ($activeTab === 'general')
            @php
                $privateKeyOptions = collect([
                    blank($github_app->private_key_id)
                        ? ['value' => 0, 'label' => 'Select a private key']
                        : null,
                    ...$privateKeys->map(fn ($privateKey) => [
                        'value' => $privateKey->id,
                        'label' => $privateKey->name,
                    ])->all(),
                ])->filter()->values()->all();
            @endphp

            <form wire:submit="submit" class="application-settings-form">
                <x-unsaved-bar action="submit" />
                <x-application.settings-section title="{{ $github_app->name }}"
                    description="Connection and authentication settings for this private GitHub source.">
                    <x-slot:actions>
                        <button type="button" class="button" wire:click.prevent="updateGithubAppName">
                            <x-reicon name="refresh" class="size-3.5" />
                            Sync name
                        </button>
                        @can('update', $github_app)
                            <a href="{{ $this->getGithubAppNameUpdatePath() }}" class="button">
                                Rename
                                <x-external-link />
                            </a>
                            <a href="{{ getInstallationPath($github_app) }}" class="button">
                                Repositories
                                <x-external-link />
                            </a>
                        @endcan
                    </x-slot:actions>

                    <div class="grid gap-4 lg:grid-cols-2">
                        <x-forms.input canGate="update" :canResource="$github_app" id="name" label="App name" />
                        <x-forms.input canGate="update" :canResource="$github_app" id="organization"
                            label="Organization" placeholder="Personal account when empty" />

                        @if (!isCloud())
                            <div class="lg:col-span-2">
                                <x-forms.listbox id="isSystemWide" label="Availability" :options="[
                                    ['value' => false, 'label' => 'Only this team'],
                                    ['value' => true, 'label' => 'Every team on this instance'],
                                ]"
                                    helper="System-wide GitHub Apps can be used by every team on this Coolify instance."
                                    :disabled="!auth()->user()->can('update', $github_app)" />
                            </div>
                            @if ($isSystemWide)
                                <div class="lg:col-span-2">
                                    <x-callout type="warning" title="Shared with every team">
                                        Use team-specific GitHub Apps when you need repository isolation between teams.
                                    </x-callout>
                                </div>
                            @endif
                        @endif

                        <x-forms.input canGate="update" :canResource="$github_app" id="htmlUrl"
                            label="HTML URL" />
                        <x-forms.input canGate="update" :canResource="$github_app" id="apiUrl"
                            label="API URL" />
                        <x-forms.input canGate="update" :canResource="$github_app" id="customUser"
                            label="User" required />
                        <x-forms.input canGate="update" :canResource="$github_app" type="number"
                            id="customPort" label="Port" required />
                        <x-forms.input canGate="update" :canResource="$github_app" type="number" id="appId"
                            label="App ID" required />
                        <x-forms.input canGate="update" :canResource="$github_app" type="number"
                            id="installationId" label="Installation ID" required />
                        <x-forms.input canGate="update" :canResource="$github_app" id="clientId"
                            label="Client ID" type="password" required />
                        <x-forms.input canGate="update" :canResource="$github_app" id="clientSecret"
                            label="Client secret" type="password" required />
                        <x-forms.input canGate="update" :canResource="$github_app" id="webhookSecret"
                            label="Webhook secret" type="password" required />
                        <x-forms.listbox id="privateKeyId" label="Private key" required
                            :options="$privateKeyOptions" :disabled="!auth()->user()->can('update', $github_app)" />
                    </div>
                </x-application.settings-section>
            </form>
        @elseif ($activeTab === 'permissions')
            <div class="application-settings-form">
                <x-application.settings-section title="Permissions"
                    description="GitHub permissions currently granted to this App.">
                    <x-slot:actions>
                        @can('view', $github_app)
                            <button type="button" class="button" wire:click.prevent="checkPermissions">
                                <x-reicon name="refresh" class="size-3.5" />
                                Refetch
                            </button>
                            <a href="{{ getPermissionsPath($github_app) }}" class="button">
                                Update on GitHub
                                <x-external-link />
                            </a>
                        @endcan
                    </x-slot:actions>

                    <div class="grid gap-4 lg:grid-cols-3">
                        <x-forms.input canGate="view" :canResource="$github_app" id="contents"
                            helper="Read access is mandatory." label="Contents" readonly placeholder="N/A" />
                        <x-forms.input canGate="view" :canResource="$github_app" id="metadata"
                            helper="Read access is mandatory." label="Metadata" readonly placeholder="N/A" />
                        <x-forms.input canGate="view" :canResource="$github_app" id="pullRequests"
                            helper="Write access is needed for preview deployment status updates."
                            label="Pull requests" readonly placeholder="N/A" />
                    </div>
                </x-application.settings-section>
            </div>
        @else
            <div x-data="{ search: '' }" class="application-settings-form">
                <x-application.settings-section title="Resources"
                    description="Applications currently using this GitHub App." flush>
                    @if ($applications->isEmpty())
                        <x-empty title="No resources use this source"
                            description="Applications will appear here after this GitHub App is selected as their source."
                            size="sm">
                            <x-slot:icon>
                                <x-reicon name="sources" class="size-6" />
                            </x-slot:icon>
                        </x-empty>
                    @else
                        <div class="border-b border-neutral-200 p-3 dark:border-white/[0.08]">
                            <div class="relative w-full max-w-sm">
                                <x-reicon name="search"
                                    class="pointer-events-none absolute top-1/2 left-2.5 z-10 size-3.5 -translate-y-1/2 text-neutral-400 dark:text-fg-faint" />
                                <input x-model.debounce.150ms="search" type="search"
                                    placeholder="Search resources"
                                    class="h-8! w-full rounded-lg! border-neutral-200! bg-white! py-0! pr-3! pl-8! text-[12px]! shadow-none! placeholder:text-neutral-400 focus:border-neutral-300! focus:ring-0! dark:border-white/[0.08]! dark:bg-white/[0.035]! dark:text-fg! dark:placeholder:text-fg-faint">
                            </div>
                        </div>
                        <div class="overflow-x-auto">
                            <div
                                class="grid min-w-[680px] grid-cols-[minmax(10rem,.8fr)_minmax(10rem,.8fr)_minmax(12rem,1fr)_8rem_2rem] border-b border-neutral-200 bg-neutral-50 px-4 py-2.5 text-[11px] font-medium text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.025] dark:text-fg-faint">
                                <div>Project</div>
                                <div>Environment</div>
                                <div>Resource</div>
                                <div>Type</div>
                                <div></div>
                            </div>
                            @foreach ($applications->sortBy('name', SORT_NATURAL) as $resource)
                                @php
                                    $projectName = (string) data_get($resource->project(), 'name');
                                    $environmentName = (string) data_get($resource, 'environment.name');
                                    $resourceName = (string) $resource->name;
                                    $resourceType = (string) str($resource->type())->headline();
                                    $searchValue = strtolower(
                                        $projectName.' '.$environmentName.' '.$resourceName.' '.$resourceType,
                                    );
                                @endphp
                                <a {{ wireNavigate() }} href="{{ $resource->link() }}"
                                    x-show="search === '' || '{{ addslashes($searchValue) }}'.includes(search.toLowerCase())"
                                    class="grid min-h-13 min-w-[680px] grid-cols-[minmax(10rem,.8fr)_minmax(10rem,.8fr)_minmax(12rem,1fr)_8rem_2rem] items-center border-b border-neutral-200 px-4 py-2.5 text-[12px] transition-colors last:border-b-0 hover:bg-neutral-50 hover:no-underline dark:border-white/[0.07] dark:hover:bg-white/[0.025]">
                                    <span class="truncate text-neutral-500 dark:text-fg-dim">{{ $projectName }}</span>
                                    <span class="truncate text-neutral-500 dark:text-fg-dim">{{ $environmentName }}</span>
                                    <span class="truncate font-medium text-black dark:text-fg">{{ $resourceName }}</span>
                                    <span class="text-neutral-500 dark:text-fg-dim">{{ $resourceType }}</span>
                                    <x-reicon name="arrow-right"
                                        class="size-3.5 justify-self-end text-neutral-400 dark:text-fg-faint" />
                                </a>
                            @endforeach
                        </div>
                    @endif
                </x-application.settings-section>
            </div>
        @endif
    @else
        <div class="mb-4 flex justify-end">
            @can('delete', $github_app)
                <x-modal-confirmation title="Confirm GitHub App Deletion?" isErrorButton
                    buttonTitle="Delete" submitAction="delete"
                    :actions="['The selected GitHub App will be permanently deleted.']"
                    confirmationText="{{ data_get($github_app, 'name') }}"
                    confirmationLabel="Please confirm the execution of the actions by entering the GitHub App Name below"
                    shortConfirmationLabel="GitHub App Name" :confirmWithPassword="false"
                    step2ButtonText="Permanently Delete" />
            @endcan
        </div>

        @can('create', $github_app)
            @php
                $endpointOptions = collect([
                    $fqdn ? ['value' => $fqdn, 'label' => 'Use '.$fqdn] : null,
                    $ipv4 ? ['value' => $ipv4, 'label' => 'Use '.$ipv4] : null,
                    $ipv6 ? ['value' => $ipv6, 'label' => 'Use '.$ipv6] : null,
                    config('app.url')
                        ? ['value' => config('app.url'), 'label' => 'Use '.config('app.url')]
                        : null,
                ])->filter()->values()->all();
            @endphp

            <div class="grid gap-4 lg:grid-cols-2">
                <div class="application-settings-form"
                    x-data="{
                        webhookEndpoint: $wire.entangle('webhook_endpoint').live,
                        useCustomWebhookEndpoint: $wire.entangle('use_custom_webhook_endpoint').live,
                        customWebhookEndpoint: $wire.entangle('custom_webhook_endpoint').live,
                    }">
                    <x-application.settings-section title="Automated installation"
                        description="Register through GitHub's manifest flow with permissions and webhooks preconfigured.">
                        <x-slot:actions>
                            <x-status-badge label="Recommended" type="success" />
                        </x-slot:actions>

                        <div class="flex min-h-[24rem] flex-col gap-4">
                            @if (!isCloud() || isDev())
                                <x-forms.listbox id="use_custom_webhook_endpoint" label="Webhook endpoint"
                                    :live="true" :options="[
                                        ['value' => false, 'label' => 'Use an instance endpoint'],
                                        ['value' => true, 'label' => 'Use a custom endpoint'],
                                    ]"
                                    x-model="useCustomWebhookEndpoint"
                                    helper="Use a custom public URL when Coolify is behind a tunnel or reverse proxy." />
                                <div x-show="!useCustomWebhookEndpoint">
                                    <x-forms.listbox id="webhook_endpoint" label="Instance endpoint"
                                        :options="$endpointOptions" x-model="webhookEndpoint" />
                                </div>
                                <div x-cloak x-show="useCustomWebhookEndpoint">
                                    <x-forms.input canGate="create" :canResource="$github_app"
                                        x-model="customWebhookEndpoint" id="custom_webhook_endpoint" type="url"
                                        label="Custom endpoint" placeholder="https://coolify.example.com"
                                        helper="Do not include /webhooks." />
                                </div>
                            @else
                                <p class="text-[12px] leading-5 text-neutral-500 dark:text-fg-dim">
                                    Register the GitHub App before using this source.
                                </p>
                            @endif

                            <div
                                class="rounded-lg border border-neutral-200 bg-neutral-50 p-3 text-[12px] leading-5 text-neutral-600 dark:border-white/[0.08] dark:bg-white/[0.025] dark:text-fg-dim">
                                <p class="font-medium text-black dark:text-fg">Mandatory permissions</p>
                                <p class="mt-1">Contents: read · Metadata: read · Email: read</p>
                            </div>

                            <x-forms.listbox id="preview_deployment_permissions"
                                label="Preview deployment access" :options="[
                                    ['value' => false, 'label' => 'Do not update pull requests'],
                                    ['value' => true, 'label' => 'Read and update pull requests'],
                                ]"
                                helper="Write access lets Coolify post deployment status and links on pull requests." />

                            <button type="button"
                                class="button mt-auto w-full justify-center bg-coollabs/10! text-coollabs! ring-1 ring-coollabs/25 hover:bg-coollabs/15! dark:bg-warning/15! dark:text-warning! dark:ring-warning/25 dark:hover:bg-warning/20!"
                                x-on:click.prevent="createGithubApp(webhookEndpoint, useCustomWebhookEndpoint, customWebhookEndpoint, {{ Illuminate\Support\Js::from($preview_deployment_permissions) }}, {{ Illuminate\Support\Js::from($administration) }})">
                                Register with GitHub
                            </button>
                        </div>
                    </x-application.settings-section>
                </div>

                <div class="application-settings-form">
                    <x-application.settings-section title="Manual installation"
                        description="Enter GitHub App credentials manually for GitHub Enterprise or custom permission sets.">
                        <x-slot:actions>
                            <x-status-badge label="Advanced" type="neutral" />
                        </x-slot:actions>

                        <div class="flex min-h-[24rem] flex-col">
                            <div
                                class="flex size-10 items-center justify-center rounded-xl border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.035] dark:text-fg-dim">
                                <x-reicon name="settings" class="size-5" />
                            </div>
                            <p class="mt-4 max-w-md text-[12px] leading-5 text-neutral-500 dark:text-fg-dim">
                                Use this path when the automated GitHub manifest flow is unavailable or you need
                                complete control over the App configuration.
                            </p>
                            <button type="button" class="button mt-auto w-fit"
                                wire:click.prevent="createGithubAppManually">
                                Continue manually
                                <x-reicon name="arrow-right" class="size-3.5" />
                            </button>
                        </div>
                    </x-application.settings-section>
                </div>
            </div>
        @else
            <x-callout type="danger" title="Insufficient permissions">
                You do not have permission to create GitHub Apps. Contact your team administrator.
            </x-callout>
        @endcan

        <script>
            function createGithubApp(webhook_endpoint, use_custom_webhook_endpoint, custom_webhook_endpoint,
                preview_deployment_permissions, administration) {
                const {
                    organization,
                    html_url
                } = @js($github_app->only(['organization', 'html_url']));
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
                const isDev = @js(config('app.env')) === 'local';
                const devWebhook = @js(config('constants.webhooks.dev_webhook'));
                if (isDev && devWebhook) {
                    baseUrl = devWebhook;
                }
                const webhookBaseUrl = `${baseUrl}/webhooks`;
                const organizationPath = organization ? encodeURIComponent(organization.replace(/^\/+|\/+$/g, '')) : '';
                const path = organizationPath ? `organizations/${organizationPath}/settings/apps/new` : 'settings/apps/new';
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
                    setup_url: `${webhookBaseUrl}/source/github/install`,
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
