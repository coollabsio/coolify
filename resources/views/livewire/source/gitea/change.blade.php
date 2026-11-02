<div>
    <x-slot:title>
        {{ $name ?: 'Gitea App' }} | Sources | Coolify
    </x-slot>

    @if ($isConnected)
        <header class="mb-5 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="truncate text-[24px]! leading-7! font-semibold! tracking-tight!">
                        {{ $name ?: 'Gitea App' }}
                    </h1>
                    <x-status-badge label="Connected" type="success" />
                </div>
                <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-dim">
                    {{ filled($organization) ? 'Gitea App for '.$organization : 'Private Gitea source' }}
                </p>
            </div>
            <div class="flex shrink-0 flex-wrap items-center gap-2 sm:ml-auto">
                @can('view', $gitea_app)
                    <x-forms.button type="button" wire:click.prevent="testConnection">
                        Test connection
                    </x-forms.button>
                @endcan
                @can('delete', $gitea_app)
                    <x-modal-confirmation title="Confirm Gitea App Deletion?" isErrorButton buttonTitle="Delete"
                        submitAction="delete" :actions="['The selected Gitea App will be permanently deleted.']"
                        confirmationText="{{ data_get($gitea_app, 'name') }}"
                        confirmationLabel="Please confirm by entering the Gitea App Name below"
                        shortConfirmationLabel="Gitea App Name" :confirmWithPassword="false"
                        step2ButtonText="Permanently Delete" />
                @endcan
            </div>
        </header>

        <form wire:submit="submit" class="application-settings-form flex flex-col gap-6">
            <x-unsaved-bar action="submit" />

            <x-application.settings-section title="General"
                description="Name, visibility, and webhook of this source.">
                <div class="grid gap-4 lg:grid-cols-2">
                    <x-forms.input canGate="update" :canResource="$gitea_app" id="name" label="Name" />

                    @if (! isCloud())
                        <div class="lg:col-span-2 max-w-xs">
                            <x-forms.checkbox canGate="update" :canResource="$gitea_app" label="System wide"
                                helper="If checked, this Gitea App will be available for everyone in this Coolify instance."
                                instantSave id="isSystemWide" />
                        </div>
                        @if ($isSystemWide)
                            <div class="lg:col-span-2">
                                <x-callout type="warning" title="Shared with every team">
                                    System-wide Gitea Apps are shared across all teams on this Coolify instance. Prefer team-specific apps when you need repository isolation.
                                </x-callout>
                            </div>
                        @endif
                    @endif
                </div>
                <x-application.settings-subsection title="Webhook"
                    helper="Configure this webhook URL in Gitea (user, organization, or repository settings → Webhooks → Add webhook → Gitea).">
                    <div class="grid gap-4 lg:grid-cols-2">
                        <div class="lg:col-span-2">
                            <x-forms.input readonly label="Webhook URL"
                                value="{{ rtrim($this->resolvePublicBaseUrl(), '/') }}/webhooks/source/gitea/events" />
                        </div>
                        <x-forms.input canGate="update" :canResource="$gitea_app" id="webhookSecret"
                            label="Webhook secret" type="password"
                            helper="Set this as the Secret in a Gitea webhook (user, organization, or repository settings → Webhooks → Add webhook → Gitea), content type application/json, trigger on Push and Pull Request events. Stored encrypted." />
                    </div>
                </x-application.settings-subsection>
            </x-application.settings-section>

            <x-application.settings-section id="connection-section" title="Connection"
                helper="OAuth credentials and the endpoints Coolify uses to reach this source.">
                <x-application.settings-subsection first title="OAuth credentials"
                    helper="Client credentials from your Gitea OAuth2 application.">
                    <div class="grid gap-4 lg:grid-cols-2">
                        <x-forms.input canGate="update" :canResource="$gitea_app" id="clientId"
                            label="Client ID" />
                        <x-forms.input canGate="update" :canResource="$gitea_app" id="clientSecretInput"
                            label="Client secret" type="password"
                            helper="Stored encrypted. Leave empty to keep the existing secret." />
                        <x-forms.input canGate="update" :canResource="$gitea_app" id="organization" label="Organization"
                            helper="Comma-separated organization or user names to filter visible repositories." />
                    </div>
                </x-application.settings-subsection>
                <x-application.settings-subsection title="Self-hosted / advanced"
                    helper="Override endpoints and SSH settings for self-hosted Gitea.">
                    <div class="grid gap-4 lg:grid-cols-2">
                        <x-forms.input canGate="update" :canResource="$gitea_app" id="htmlUrl"
                            label="Gitea URL" />
                        <x-forms.input canGate="update" :canResource="$gitea_app" id="apiUrl" label="API URL" />
                        <x-forms.input canGate="update" :canResource="$gitea_app" id="customUser"
                            label="SSH user" />
                        <x-forms.input canGate="update" :canResource="$gitea_app" type="number" id="customPort"
                            label="SSH port" />
                        <div class="lg:col-span-2">
                            <x-forms.listbox canGate="update" :canResource="$gitea_app" id="privateKeyId" label="SSH private key (optional)"
                                :options="collect($privateKeys)->map(fn ($key) => [
                                    'value' => $key->id,
                                    'label' => $key->name,
                                ])->prepend(['value' => null, 'label' => 'None'])->values()->all()"
                                :disabled="! auth()->user()->can('update', $gitea_app)" />
                        </div>
                    </div>
                </x-application.settings-subsection>
            </x-application.settings-section>

        </form>

        <div x-data="{ search: '' }" class="application-settings-form mt-6">
            <x-application.settings-section title="Resources"
                description="Applications currently using this Gitea App." flush>
                @if ($applications->isEmpty())
                    <x-empty title="No resources use this source"
                        description="Applications will appear here after this Gitea App is selected as their source."
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
                    {{ $name ?: 'Gitea App' }}
                </h1>
                <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-dim">
                    Finish connecting this Gitea App before using it as a source
                </p>
            </div>
            @can('delete', $gitea_app)
                <div class="shrink-0 sm:ml-auto">
                    <x-modal-confirmation title="Confirm Gitea App Deletion?" isErrorButton buttonTitle="Delete"
                        submitAction="delete" :actions="['The selected Gitea App will be permanently deleted.']"
                        confirmationText="{{ data_get($gitea_app, 'name') }}"
                        confirmationLabel="Please confirm by entering the Gitea App Name below"
                        shortConfirmationLabel="Gitea App Name" :confirmWithPassword="false"
                        step2ButtonText="Permanently Delete" />
                </div>
            @endcan
        </header>

        <div class="application-settings-form flex flex-col gap-6"
            x-data="{
                webhookEndpoint: $wire.entangle('webhook_endpoint').live,
                useCustomWebhookEndpoint: $wire.entangle('use_custom_webhook_endpoint').live,
                customWebhookEndpoint: $wire.entangle('custom_webhook_endpoint').live,
                redirectPath: '/webhooks/source/gitea/redirect',
                get redirectUri() {
                    const base = (this.useCustomWebhookEndpoint ? this.customWebhookEndpoint : this.webhookEndpoint) || '';
                    return base ? base.replace(/\/+$/, '') + this.redirectPath : '';
                }
            }">
            <x-application.settings-section title="Step 1 · Create an OAuth application"
                description="In Gitea, open Manage OAuth2 Applications and create a new application with the redirect URI below.">
                <div class="flex flex-col gap-3 text-[12px] leading-5 text-neutral-600 dark:text-fg-dim">
                    <a href="{{ rtrim($htmlUrl, '/') }}/user/settings/applications" target="_blank"
                        class="inline-flex w-fit items-center gap-1 font-medium text-black underline-offset-2 hover:underline dark:text-fg">
                        {{ rtrim($htmlUrl, '/') }}/user/settings/applications
                        <x-external-link />
                    </a>
                    <ul class="list-inside list-disc space-y-1.5">
                        <li>
                            Set <strong class="text-black dark:text-fg">Redirect URI</strong> to:
                            <code class="rounded bg-neutral-100 px-1.5 py-0.5 text-[11px] dark:bg-white/[0.06]"
                                x-text="redirectUri || @js($redirectUri)">{{ $redirectUri }}</code>
                        </li>
                        <li>
                            Keep <strong class="text-black dark:text-fg">Confidential client</strong> checked
                        </li>
                    </ul>
                </div>
            </x-application.settings-section>

            <form wire:submit="submit" class="contents">
                <x-application.settings-section title="Step 2 · Enter credentials"
                    description="Paste the Client ID and Client secret from Gitea, then save.">
                    <x-slot:actions>
                        <x-forms.button type="submit">Save</x-forms.button>
                    </x-slot:actions>

                    <div class="grid gap-4 lg:grid-cols-2">
                        <x-forms.input id="name" label="Name" />
                        <x-forms.input id="clientId" label="Client ID" required
                            helper="The Client ID from your Gitea OAuth2 application." />
                        <x-forms.input id="clientSecretInput" label="Client secret" type="password"
                            :required="blank($clientSecretInput) && blank(data_get($gitea_app, 'client_secret'))"
                            helper="The Client secret from your Gitea OAuth2 application. Saved encrypted." />
                        <x-forms.input id="organization" label="Organization"
                            helper="Optional. Comma-separated organization or user names to filter repositories." />
                    </div>

                    @if (! isCloud() || isDev())
                        <div class="mt-4 grid gap-4 lg:grid-cols-2">
                            <div class="lg:col-span-2 text-[12px] text-neutral-500 dark:text-fg-dim">
                                Gitea will redirect back to this Coolify URL. It must match the Redirect URI on your Gitea OAuth2 application exactly.
                            </div>
                            <div class="lg:col-span-2 max-w-md">
                                <x-forms.listbox id="use_custom_webhook_endpoint" label="Webhook endpoint"
                                    :live="true" :options="[
                                        ['value' => false, 'label' => 'Use an instance endpoint'],
                                        ['value' => true, 'label' => 'Use a custom endpoint'],
                                    ]"
                                    x-model="useCustomWebhookEndpoint"
                                    helper="Use a custom public URL when Coolify is behind a tunnel or reverse proxy." />
                            </div>
                            <div class="lg:col-span-2" x-show="!useCustomWebhookEndpoint">
                                <x-forms.listbox id="webhook_endpoint" x-model="webhookEndpoint"
                                    label="Selected endpoint"
                                    helper="Gitea will use this endpoint unless custom mode is enabled."
                                    :options="collect([$fqdn, $ipv4, $ipv6, config('app.url')])
                                        ->filter()->unique()->map(fn ($endpoint) => [
                                            'value' => $endpoint,
                                            'label' => 'Use '.$endpoint,
                                        ])->values()->all()" />
                            </div>
                            <div class="lg:col-span-2" x-cloak x-show="useCustomWebhookEndpoint">
                                <x-forms.input x-model="customWebhookEndpoint" id="custom_webhook_endpoint"
                                    type="url" label="Custom endpoint"
                                    placeholder="https://coolify.example.com"
                                    helper="Gitea will use this custom public URL. Do not include /webhooks." />
                            </div>
                        </div>
                    @endif

                    <div class="mt-4" x-data="{ open: false }">
                        <button type="button" @click="open = !open"
                            class="flex w-full items-center justify-between rounded-lg border border-neutral-200 px-3 py-2.5 text-left text-[12px] font-medium text-neutral-700 transition-colors hover:bg-neutral-50 dark:border-white/[0.08] dark:text-fg-dim dark:hover:bg-white/[0.03]">
                            Advanced / self-hosted
                            <svg class="size-3.5 transition-transform" :class="{ 'rotate-180': open }"
                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="6 9 12 15 18 9"></polyline>
                            </svg>
                        </button>
                        <div x-cloak x-show="open" x-collapse.duration.200ms
                            class="mt-3 grid gap-4 rounded-lg border border-neutral-200 p-3 lg:grid-cols-2 dark:border-white/[0.08]">
                            <x-forms.input id="htmlUrl" label="Gitea URL"
                                helper="Only change this for self-hosted Gitea (e.g. https://gitea.example.com)." />
                            <x-forms.input id="apiUrl" label="API URL"
                                helper="Usually your Gitea URL with /api/v1 appended." />
                            <x-forms.input id="customUser" label="SSH user" />
                            <x-forms.input type="number" id="customPort" label="SSH port" />
                            @if (! isCloud())
                                <div class="max-w-xs lg:col-span-2">
                                    <x-forms.checkbox label="System wide" id="isSystemWide"
                                        helper="If checked, this Gitea App will be available for everyone in this Coolify instance." />
                                </div>
                            @endif
                        </div>
                    </div>
                </x-application.settings-section>
            </form>

            @if ($clientId)
                <x-application.settings-section title="Step 3 · Authorize with Gitea"
                    description="Authorize Coolify with your Gitea instance. The redirect URI must match the one configured in Gitea.">
                    <a href="{{ $this->getOAuthUrl() }}" wire:key="oauth-url-{{ md5((string) $redirectUri) }}"
                        class="button button-highlighted">
                        Connect to Gitea
                        <x-external-link />
                    </a>
                </x-application.settings-section>
            @endif
        </div>
    @endif
</div>
