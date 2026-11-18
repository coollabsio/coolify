<div>
    <x-slot:title>
        {{ $name ?: 'Origin App' }} | Sources | Coolify
    </x-slot>

    @if ($origin_app)
        @php
            $originEvents = implode(', ', ORIGIN_APP_EVENTS);
            $originScopes = implode(' ', ORIGIN_APP_SCOPES);
        @endphp

        @if ($isConnected)
            <header class="mb-5 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="truncate text-[24px]! leading-7! font-semibold! tracking-tight!">
                            {{ $name ?: 'Origin App' }}
                        </h1>
                        <x-status-badge label="Connected" type="success" />
                    </div>
                    <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-dim">
                        {{ filled($namespaceSlug) ? 'Origin App for '.$namespaceSlug : 'Private Origin source' }}
                    </p>
                </div>
                <div class="flex shrink-0 flex-wrap items-center gap-2 sm:ml-auto">
                    @can('view', $origin_app)
                        <x-forms.button type="button" wire:click.prevent="testConnection">
                            Test connection
                        </x-forms.button>
                    @endcan
                    @can('delete', $origin_app)
                        <x-modal-confirmation title="Confirm Origin App Deletion?" isErrorButton buttonTitle="Delete"
                            submitAction="delete" :actions="['The selected Origin App will be permanently deleted.']"
                            confirmationText="{{ data_get($origin_app, 'name') }}"
                            confirmationLabel="Please confirm by entering the Origin App Name below"
                            shortConfirmationLabel="Origin App Name" :confirmWithPassword="false"
                            step2ButtonText="Permanently Delete" />
                    @endcan
                </div>
            </header>

            <form wire:submit="submit" class="application-settings-form">
                <x-unsaved-bar action="submit" />

                <x-application.settings-section title="General"
                    description="Connection settings for this Cursor Origin source.">
                    <div class="grid gap-4 lg:grid-cols-2">
                        <x-forms.input canGate="update" :canResource="$origin_app" id="name" label="Name" />
                        <x-forms.input readonly id="appId" label="App ID" copyable />
                        <x-forms.input readonly id="installationId" label="Installation ID" copyable />
                        <x-forms.input readonly id="namespaceSlug" label="Namespace" />

                        @if (! isCloud())
                            <div class="lg:col-span-2 max-w-xs">
                                <x-forms.checkbox canGate="update" :canResource="$origin_app" label="System wide"
                                    helper="If checked, this Origin App will be available for everyone in this Coolify instance."
                                    instantSave id="isSystemWide" />
                            </div>
                            @if ($isSystemWide)
                                <div class="lg:col-span-2">
                                    <x-callout type="warning" title="Shared with every team">
                                        System-wide Origin Apps are shared across all teams on this Coolify instance. Prefer team-specific apps when you need repository isolation.
                                    </x-callout>
                                </div>
                            @endif
                        @endif
                    </div>
                </x-application.settings-section>

                <x-application.settings-section title="Setup values"
                    description="Values configured on your Origin App at cursor.com.">
                    <div class="grid gap-4 lg:grid-cols-2">
                        <div class="lg:col-span-2">
                            <div class="relative">
                                    <x-forms.textarea id="publicKey" label="Public key (signing key)" readonly monospace rows="4"
                                        helper="Add this Ed25519 public key as a signing key on your Origin App. Coolify keeps the private key encrypted." />
                                    <x-copy-button :value="$publicKey" label="Copy public key" class="absolute top-7 right-2 z-10" />
                                </div>
                        </div>
                        <x-forms.input readonly copyable label="Webhook URL" value="{{ $this->webhookUrl() }}" />
                        <x-forms.input readonly copyable label="Install redirect URI"
                            value="{{ $this->installRedirectUri() }}" />
                    </div>
                </x-application.settings-section>
            </form>

            <div x-data="{ search: '' }" class="application-settings-form mt-6">
                <x-application.settings-section title="Resources"
                    description="Applications currently using this Origin App." flush>
                    @if ($applications->isEmpty())
                        <x-empty title="No resources use this source"
                            description="Applications will appear here after this Origin App is selected as their source."
                            icon-name="sources" size="sm" />
                    @else
                        <div class="border-b border-neutral-200 p-3 dark:border-white/[0.08]">
                            <div class="relative w-full max-w-sm">
                                <x-reicon name="search"
                                    class="pointer-events-none absolute top-1/2 left-2.5 z-10 size-3.5 -translate-y-1/2 text-neutral-400 dark:text-fg-faint" />
                                <input x-model.debounce.150ms="search" type="search" placeholder="Search resources"
                                    class="h-8! w-full rounded-lg! border-neutral-200! bg-white! py-0! pr-3! pl-8! text-[12px]! shadow-none! placeholder:text-neutral-400 focus:border-accent! focus:ring-0! dark:border-white/[0.08]! dark:bg-white/[0.035]! dark:text-fg! dark:placeholder:text-fg-faint">
                            </div>
                        </div>
                        <div class="overflow-x-auto">
                            <div
                                class="grid min-w-[680px] grid-cols-[minmax(10rem,.8fr)_minmax(10rem,.8fr)_minmax(12rem,1fr)_8rem] border-b border-neutral-200 bg-neutral-50 px-4 py-2.5 text-[11px] font-medium text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.05] dark:text-fg-faint">
                                <div>Project</div>
                                <div>Environment</div>
                                <div>Resource</div>
                                <div>Repository</div>
                            </div>
                            @foreach ($applications->sortBy('name', SORT_NATURAL) as $application)
                                @php
                                    $projectName = (string) data_get($application, 'environment.project.name');
                                    $environmentName = (string) data_get($application, 'environment.name');
                                    $resourceName = (string) $application->name;
                                    $repoLabel = $application->git_repository.':'.$application->git_branch;
                                    $searchValue = strtolower(
                                        $projectName.' '.$environmentName.' '.$resourceName.' '.$repoLabel,
                                    );
                                @endphp
                                <a {{ wireNavigate() }}
                                    href="{{ route('project.application.configuration', [
                                        'project_uuid' => data_get($application, 'environment.project.uuid'),
                                        'environment_uuid' => data_get($application, 'environment.uuid'),
                                        'application_uuid' => data_get($application, 'uuid'),
                                    ]) }}"
                                    x-show="search === '' || '{{ addslashes($searchValue) }}'.includes(search.toLowerCase())"
                                    class="grid min-h-13 min-w-[680px] grid-cols-[minmax(10rem,.8fr)_minmax(10rem,.8fr)_minmax(12rem,1fr)_8rem] items-center border-b border-neutral-200 px-4 py-2.5 text-[12px] transition-colors last:border-b-0 hover:bg-neutral-50 hover:no-underline dark:border-white/[0.07] dark:hover:bg-white/[0.025]">
                                    <span class="truncate text-neutral-500 dark:text-fg-dim">{{ $projectName }}</span>
                                    <span class="truncate text-neutral-500 dark:text-fg-dim">{{ $environmentName }}</span>
                                    <span class="truncate font-medium text-black dark:text-fg">{{ $resourceName }}</span>
                                    <span class="truncate text-neutral-500 dark:text-fg-dim">{{ $repoLabel }}</span>
                                </a>
                            @endforeach
                        </div>
                    @endif
                </x-application.settings-section>
            </div>
        @else
            <header class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0">
                    <h1 class="truncate text-[24px]! leading-7! font-semibold! tracking-tight!">
                        {{ $name ?: 'Origin App' }}
                    </h1>
                    <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-dim">
                        Finish connecting this Origin App before using it as a source
                    </p>
                </div>
                @can('delete', $origin_app)
                    <div class="shrink-0 sm:ml-auto">
                        <x-modal-confirmation title="Confirm Origin App Deletion?" isErrorButton buttonTitle="Delete"
                            submitAction="delete" :actions="['The selected Origin App will be permanently deleted.']"
                            confirmationText="{{ data_get($origin_app, 'name') }}"
                            confirmationLabel="Please confirm by entering the Origin App Name below"
                            shortConfirmationLabel="Origin App Name" :confirmWithPassword="false"
                            step2ButtonText="Permanently Delete" />
                    </div>
                @endcan
            </header>

            <div class="application-settings-form flex flex-col gap-6">
                <x-application.settings-section title="Step 1 · Choose the Coolify URL"
                    description="Origin sends webhooks and install redirects to this Coolify URL.">
                    @if (! isCloud() || isDev())
                        <div class="grid gap-4 lg:grid-cols-2">
                            <div class="lg:col-span-2 max-w-md">
                                <x-forms.listbox id="use_custom_webhook_endpoint" label="Webhook endpoint"
                                    :live="true" :options="[
                                        ['value' => false, 'label' => 'Use an instance endpoint'],
                                        ['value' => true, 'label' => 'Use a custom endpoint'],
                                    ]"
                                    helper="Use a custom public URL when Coolify is behind a tunnel or reverse proxy." />
                            </div>
                            @if (! $use_custom_webhook_endpoint)
                                <div class="lg:col-span-2">
                                    <x-forms.listbox id="webhook_endpoint" :live="true" label="Selected endpoint"
                                        helper="Origin will use this endpoint unless custom mode is enabled."
                                        :options="collect([$fqdn, $ipv4, $ipv6, config('app.url')])
                                            ->filter()->unique()->map(fn ($endpoint) => [
                                                'value' => $endpoint,
                                                'label' => 'Use '.$endpoint,
                                            ])->values()->all()" />
                                </div>
                            @else
                                <div class="lg:col-span-2">
                                    <x-forms.input id="custom_webhook_endpoint" wire:model.blur="custom_webhook_endpoint"
                                        type="url" label="Custom endpoint" placeholder="https://coolify.example.com"
                                        helper="Origin will use this custom public URL. Do not include /webhooks." />
                                </div>
                            @endif
                        </div>
                    @else
                        <p class="text-[12px] text-neutral-500 dark:text-fg-dim">{{ $this->resolvePublicBaseUrl() }}</p>
                    @endif

                    @unless ($this->isPublicBaseUrlHttps())
                        <div class="mt-4">
                            <x-callout type="warning" title="HTTPS required">
                                Origin only accepts HTTPS webhook and redirect URLs. Select or enter an https:// endpoint for Coolify.
                            </x-callout>
                        </div>
                    @endunless
                </x-application.settings-section>

                <x-application.settings-section title="Step 2 · Create an Origin App"
                    description="Create a new app on Cursor and configure it with the values below.">
                    <div class="flex flex-col gap-4">
                        <a href="{{ \App\Livewire\Source\Origin\Change::APPS_SETTINGS_URL }}" target="_blank"
                            class="inline-flex w-fit items-center gap-1 text-[12px] font-medium text-black underline-offset-2 hover:underline dark:text-fg">
                            {{ \App\Livewire\Source\Origin\Change::APPS_SETTINGS_URL }}
                            <x-external-link />
                        </a>
                        <div class="grid gap-4 lg:grid-cols-2">
                            <div class="lg:col-span-2">
                                <div class="relative">
                                    <x-forms.textarea id="publicKey" label="Public key (signing key)" readonly monospace rows="4"
                                        helper="Add this Ed25519 public key as a signing key on your Origin App. Coolify keeps the private key encrypted." />
                                    <x-copy-button :value="$publicKey" label="Copy public key" class="absolute top-7 right-2 z-10" />
                                </div>
                            </div>
                            <x-forms.input readonly copyable label="Webhook URL" value="{{ $this->webhookUrl() }}"
                                wire:key="origin-webhook-{{ md5($this->webhookUrl()) }}" />
                            <x-forms.input readonly copyable label="Install redirect URI"
                                value="{{ $this->installRedirectUri() }}"
                                wire:key="origin-redirect-{{ md5($this->installRedirectUri()) }}" />
                            <x-forms.input readonly copyable label="Events" value="{{ $originEvents }}"
                                helper="Subscribe to these repository events. Installation events are always delivered." />
                            <x-forms.input readonly copyable label="Default scopes" value="{{ $originScopes }}" />
                        </div>
                        @can('update', $origin_app)
                            <div>
                                <x-modal-confirmation title="Regenerate key pair?" buttonTitle="Regenerate key pair"
                                    submitAction="regenerateKeyPair"
                                    :actions="['A new Ed25519 key pair replaces the current one.', 'You must update the signing key on your Origin App.']"
                                    :confirmWithText="false" :confirmWithPassword="false"
                                    step2ButtonText="Regenerate" />
                            </div>
                        @endcan
                    </div>
                </x-application.settings-section>

                <form wire:submit="submit" class="contents">
                    <x-application.settings-section title="Step 3 · Enter the App ID"
                        description="Paste the App ID of your Origin App, then save.">
                        <x-slot:actions>
                            <x-forms.button canGate="update" :canResource="$origin_app" type="submit">Save</x-forms.button>
                        </x-slot:actions>

                        <div class="grid gap-4 lg:grid-cols-2">
                            <x-forms.input canGate="update" :canResource="$origin_app" id="name" label="Name" />
                            <x-forms.input canGate="update" :canResource="$origin_app" id="appId" label="App ID"
                                placeholder="app_…" helper="The App ID shown on your Origin App settings page." />
                            @if (! isCloud())
                                <div class="max-w-xs lg:col-span-2">
                                    <x-forms.checkbox canGate="update" :canResource="$origin_app" label="System wide"
                                        id="isSystemWide"
                                        helper="If checked, this Origin App will be available for everyone in this Coolify instance." />
                                </div>
                            @endif
                        </div>
                    </x-application.settings-section>
                </form>

                @if (filled($appId) && $appId === $origin_app->app_id)
                    <x-application.settings-section title="Step 4 · Install on Origin"
                        description="Install the app on your Origin namespace. Origin redirects back to Coolify to finish the connection.">
                        <div class="flex flex-col gap-4">
                            <a href="{{ $this->getInstallUrl() }}"
                                wire:key="origin-install-url-{{ md5($this->installRedirectUri()) }}"
                                class="button button-highlighted w-fit">
                                Install on Origin
                                <x-external-link />
                            </a>

                            @can('update', $origin_app)
                                <form wire:submit="saveInstallationId" class="flex flex-col gap-2">
                                    <p class="text-[12px] text-neutral-500 dark:text-fg-dim">
                                        Already installed? Paste the installation ID and Coolify verifies it with Origin.
                                    </p>
                                    <div class="flex max-w-xl items-end gap-2">
                                        <x-forms.input id="installationIdInput" label="Installation ID"
                                            placeholder="i_…" />
                                        <x-forms.button type="submit">Verify</x-forms.button>
                                    </div>
                                </form>
                            @endcan
                        </div>
                    </x-application.settings-section>
                @endif
            </div>
        @endif
    @endif
</div>
