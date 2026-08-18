<div>
    <x-slot:title>
        {{ $github_app->name ?: 'GitHub App' }} | Sources | Coolify
    </x-slot>

    @if (data_get($github_app, 'app_id'))
        @php
            $githubAppRouteParameters = ['github_app_uuid' => $github_app->uuid];
            $showSettingsSidebar = in_array($activeTab, ['general', 'permissions', 'resources', 'danger'], true);
            $settingsMenuItems = [
                [
                    'label' => 'General',
                    'route' => 'source.github.show',
                    'active' => $activeTab === 'general',
                    'icon' => 'settings',
                ],
                [
                    'label' => 'Permissions',
                    'route' => 'source.github.permissions',
                    'active' => $activeTab === 'permissions',
                    'icon' => 'keys',
                ],
                [
                    'label' => 'Resources',
                    'route' => 'source.github.resources',
                    'active' => $activeTab === 'resources',
                    'icon' => 'grid',
                ],
                [
                    'label' => 'Danger Zone',
                    'route' => 'source.github.danger',
                    'active' => $activeTab === 'danger',
                    'icon' => 'shield-alert',
                ],
            ];
        @endphp

        <x-dashboard.navbar section="source" :parameters="$githubAppRouteParameters"
            :title="$name ?: 'GitHub App'"
            :subtitle="filled($organization) ? 'GitHub App for '.$organization : 'Private GitHub source'"
            :mobileTitleOnly="true" />

        @if ($showSettingsSidebar)
            <section class="application-settings-workspace mt-4 w-full max-w-none lg:mt-0">
                <div class="grid min-w-0 gap-8 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
                    <aside class="application-settings-navigation min-w-0 xl:self-start">
                        <nav aria-label="GitHub App settings"
                            class="grid grid-cols-2 gap-0.5 border-y border-neutral-200 py-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-1 xl:border-y-0 xl:py-0 dark:border-white/[0.06]">
                            <div class="nav-section hidden xl:block">Settings</div>
                            @foreach ($settingsMenuItems as $menuItem)
                                <a wire:key="github-app-settings-{{ str($menuItem['label'])->slug() }}"
                                    @class([
                                        'menu-item',
                                        'menu-item-active' => $menuItem['active'],
                                    ])
                                    {{ wireNavigate() }}
                                    href="{{ route($menuItem['route'], $githubAppRouteParameters) }}">
                                    <x-reicon :name="$menuItem['icon']" class="menu-item-icon" />
                                    <span class="menu-item-label">{{ $menuItem['label'] }}</span>
                                </a>
                            @endforeach
                        </nav>
                    </aside>

                    <div class="min-w-0">
                        @if (!data_get($github_app, 'installation_id') && $activeTab === 'general')
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
                                        <a class="button shrink-0 button-highlighted"
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
                                <x-application.settings-section title="General"
                                    description="Connection and authentication settings for this private GitHub source.">
                                    <x-slot:actions>
                                        <x-forms.button type="button" wire:click.prevent="updateGithubAppName">
                                            <x-reicon name="refresh" class="size-3.5" />
                                            Sync name
                                        </x-forms.button>
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
                                                <x-forms.listbox canGate="update" :canResource="$github_app" id="isSystemWide" label="Availability" :options="[
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
                                        <x-forms.listbox canGate="update" :canResource="$github_app" id="privateKeyId" label="Private key" required
                                            :options="$privateKeyOptions" :disabled="!auth()->user()->can('update', $github_app)" />
                                    </div>
                                </x-application.settings-section>
                            </form>
                        @elseif ($activeTab === 'danger')
                            <div class="application-settings-form">
                                <x-application.settings-section id="github-app-danger-section" title="Danger zone"
                                    helper="Destructive actions for this GitHub App source cannot be undone.">
                                    <div
                                        class="rounded-lg border border-red-300 bg-red-50 p-4 ring-1 ring-inset ring-red-200/60 dark:border-error/30 dark:bg-error/[0.08] dark:ring-error/10">
                                        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                            <div class="min-w-0">
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <h4 class="text-sm font-semibold text-red-700 dark:text-error">Delete GitHub App</h4>
                                                    <x-status-badge status="Permanent" type="error" />
                                                </div>
                                                <p class="mt-2 max-w-2xl text-[13px] leading-5 text-neutral-600 dark:text-fg-dim">
                                                    Permanently delete
                                                    <strong class="font-semibold text-black dark:text-fg">{{ $name ?: 'this GitHub App' }}</strong>
                                                    from Coolify. Applications using this source will need another Git provider configured.
                                                </p>
                                                <ul class="mt-3 space-y-1 text-xs text-neutral-500 dark:text-fg-dim">
                                                    <li>• The App registration on GitHub is not removed automatically.</li>
                                                    <li>• Linked applications keep their Git settings until you change them.</li>
                                                    <li>• This source cannot be restored from Coolify after deletion.</li>
                                                </ul>
                                            </div>

                                            <div class="shrink-0">
                                                @can('delete', $github_app)
                                                    <x-modal-confirmation title="Confirm GitHub App Deletion?" isErrorButton
                                                        buttonTitle="Delete" submitAction="delete"
                                                        :actions="['The selected GitHub App will be permanently deleted.']"
                                                        confirmationText="{{ data_get($github_app, 'name') }}"
                                                        confirmationLabel="Please confirm the execution of the actions by entering the GitHub App Name below"
                                                        shortConfirmationLabel="GitHub App Name" :confirmWithPassword="false"
                                                        step2ButtonText="Permanently Delete" />
                                                @else
                                                    <x-forms.button disabled tooltip="You do not have permission to delete this GitHub App.">
                                                        Delete
                                                    </x-forms.button>
                                                @endcan
                                            </div>
                                        </div>
                                    </div>

                                    @cannot('delete', $github_app)
                                        <div class="mt-4">
                                            <x-callout type="danger" title="Insufficient permissions">
                                                Contact a team administrator if this GitHub App must be deleted.
                                            </x-callout>
                                        </div>
                                    @endcannot
                                </x-application.settings-section>
                            </div>
                        @elseif ($activeTab === 'permissions')
                            @include('livewire.source.github.permissions')
                        @elseif ($activeTab === 'resources')
                            @include('livewire.source.github.resources')
                        @endif
                    </div>
                </div>
            </section>
        @endif
    @else
        <header class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <h1 class="truncate text-[24px]! leading-7! font-semibold! tracking-tight!">
                    {{ $name ?: 'GitHub App' }}
                </h1>
                <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-dim">
                    Finish registering this GitHub App before using it as a source
                </p>
            </div>
            @can('delete', $github_app)
                <div class="shrink-0">
                    <x-modal-confirmation title="Confirm GitHub App Deletion?" isErrorButton
                        buttonTitle="Delete" submitAction="delete"
                        :actions="['The selected GitHub App will be permanently deleted.']"
                        confirmationText="{{ data_get($github_app, 'name') }}"
                        confirmationLabel="Please confirm the execution of the actions by entering the GitHub App Name below"
                        shortConfirmationLabel="GitHub App Name" :confirmWithPassword="false"
                        step2ButtonText="Permanently Delete" />
                </div>
            @endcan
        </header>

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
                                class="button mt-auto w-full justify-center button-highlighted"
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
