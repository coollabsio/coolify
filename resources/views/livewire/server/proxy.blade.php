@php use App\Enums\ProxyTypes; @endphp

<div class="application-settings-form flex w-full flex-col gap-6">
    @if ($server->proxyType())
        @if ($selectedProxy !== 'NONE')
            <form wire:submit="submit" class="contents">
                <x-unsaved-bar action="submit" />

                <fieldset class="contents" wire:loading.attr="disabled"
                    wire:target="submit,resetProxyConfiguration">

                <x-application.settings-section id="server-proxy-overview-section" title="Proxy configuration"
                    helper="Configure the reverse proxy and request handling for this server.">
                    <x-slot:actions>
                        <div class="flex items-center gap-2">
                            <x-status-badge :status="str($server->proxy->status)->headline()"
                                :type="str($server->proxy->status)->contains('running') ? 'success' : 'neutral'" />
                            @if ($server->proxy->status === 'exited' || $server->proxy->status === 'removing')
                                @can('update', $server)
                                    <x-modal-confirmation title="Confirm Proxy Switching?"
                                        buttonTitle="Switch proxy" submitAction="changeProxy"
                                        :actions="['Custom proxy configurations may be reset to their default settings.']"
                                        warningMessage="Review the proxy switching guide before continuing."
                                        step2ButtonText="Switch Proxy" :confirmWithText="false"
                                        :confirmWithPassword="false" />
                                @endcan
                            @else
                                <x-forms.button canGate="update" :canResource="$server"
                                    wire:click="$dispatch('error', 'The running proxy must be stopped before switching.')">
                                    Switch proxy
                                </x-forms.button>
                            @endif
                        </div>
                    </x-slot:actions>

                    @if (
                        $server->proxy->last_applied_settings &&
                            $server->proxy->last_saved_settings !== $server->proxy->last_applied_settings)
                        <x-callout type="warning" title="Your configuration changed, please restart the proxy." />
                    @elseif ($server->hasPendingProxyConfiguration())
                        <x-callout type="warning" title="Restart required">
                            Restart the proxy to apply TLS certificate changes.
                        </x-callout>
                    @else
                        <div class="flex items-start gap-3">
                            <div
                                class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-neutral-500 dark:bg-white/[0.06] dark:text-fg-dim">
                                <x-reicon name="servers" class="size-4" />
                            </div>
                            <div>
                                <p class="text-sm font-medium text-neutral-950 dark:text-fg">
                                    {{ str($server->proxyType())->title() }}
                                </p>
                                <p class="mt-1 text-xs text-neutral-500 dark:text-fg-dim">
                                    Saved and running configuration are synchronized.
                                </p>
                            </div>
                        </div>
                    @endif
                </x-application.settings-section>

                <x-application.settings-section id="server-proxy-routing-section" title="Routing behavior"
                    helper="Control generated labels and requests that do not match a running resource.">
                    <div class="grid gap-4 lg:grid-cols-2">
                        <x-forms.listbox id="generateExactLabels" label="Generated labels"
                            helper="<ul class='list-disc space-y-1 pl-4'><li><span class='font-semibold'>All supported proxies:</span> Traefik and Caddy labels are both generated, so switching the proxy keeps routing working.</li><li><span class='font-semibold'>Active proxy only:</span> generates fewer labels, but all resources must be redeployed to become accessible again after each proxy switch.</li></ul>"
                            onChange="instantSave" :options="[
                                ['value' => false, 'label' => 'Labels for all supported proxies'],
                                ['value' => true, 'label' => 'Labels for the active proxy only'],
                            ]" />
                        <x-forms.listbox id="redirectEnabled" label="Unknown requests"
                            helper="Control the 503 response for unknown hosts and stopped services. Custom request handling shows an error page or redirects to a URL."
                            onChange="instantSaveRedirect" :options="[
                                ['value' => false, 'label' => 'Return the default 503 response'],
                                ['value' => true, 'label' => 'Use custom request handling'],
                            ]" />
                        @if ($redirectEnabled)
                            <x-forms.input canGate="update" :canResource="$server"
                                placeholder="https://app.coolify.io" id="redirectUrl"
                                label="Redirect URL"
                                helper="Leave empty to return a 503 response instead of redirecting." />
                        @endif
                    </div>
                    @if ($redirectEnabled && ! $server->isSwarm())
                        <div class="mt-4 flex flex-col gap-2">
                            <x-forms.textarea canGate="update" :canResource="$server" id="customErrorPage" rows="10"
                                monospace label="Custom error page (HTML)"
                                placeholder="Leave empty to use the Coolify default error page."
                                helper="One self-contained HTML file (max {{ \App\Models\Server::PROXY_ERROR_PAGE_MAX_BYTES / 1024 }} KB) with inline CSS and images. Visitors see it with a 503 status for unknown domains and for stopped, deploying or unhealthy resources. A Redirect URL takes priority over this page." />
                            @can('update', $server)
                                <div class="flex gap-2">
                                    <x-forms.button type="button" wire:click="resetCustomErrorPage">Reset to
                                        default</x-forms.button>
                                </div>
                            @endcan
                        </div>
                    @endif
                </x-application.settings-section>

                @php
                    $proxyTitle =
                        $server->proxyType() === ProxyTypes::TRAEFIK->value
                            ? 'Traefik configuration'
                            : 'Caddy configuration';
                @endphp

                @if ($server->proxyType() === ProxyTypes::TRAEFIK->value || $server->proxyType() === 'CADDY')
                    <x-application.settings-section id="server-proxy-file-section" :title="$proxyTitle"
                        x-init="$wire.loadProxyConfiguration()"
                        helper="Edit the generated proxy compose configuration used on this server.">
                        <x-slot:actions>
                            @can('update', $server)
                                @if ($proxySettings)
                                    <x-modal-confirmation title="Reset Proxy Configuration?"
                                        buttonTitle="Reset configuration"
                                        submitAction="resetProxyConfiguration" :actions="[
                                            'Reset the proxy configuration to Coolify defaults.',
                                            'Remove custom ports, entrypoints, and other manual changes.',
                                        ]" confirmationText="{{ $server->name }}"
                                        confirmationLabel="Confirm by entering the server name"
                                        shortConfirmationLabel="Server Name"
                                        step2ButtonText="Reset Configuration"
                                        :confirmWithPassword="false" :confirmWithText="true" />
                                @endif
                            @endcan
                        </x-slot:actions>

                        @if ($server->proxyType() === ProxyTypes::TRAEFIK->value)
                            @if ($this->traefikVersionForWarning === 'latest')
                                <x-callout type="warning" title="Unpinned Traefik version">
                                    The proxy uses the <span class="font-mono">latest</span> tag. Pin
                                    <span class="font-mono">traefik:{{ $this->latestTraefikVersion }}</span>
                                    for predictable updates.
                                </x-callout>
                            @endif
                            @if ($this->isTraefikOutdated)
                                <x-callout type="warning" title="Traefik patch update available">
                                    {{ $server->detected_traefik_version ? 'Running version' : 'Configured image' }}
                                    v{{ ltrim($this->traefikVersionForWarning, 'v') }}. The latest patch
                                    for this branch is {{ $this->latestTraefikVersion }}. Test the update before applying it
                                    to production servers.
                                </x-callout>
                            @endif
                            @if ($this->newerTraefikBranchAvailable)
                                <x-callout type="info" title="New Traefik minor version available">
                                    {{ $this->newerTraefikBranchAvailable }} is available (latest patch:
                                    {{ $this->latestNewerTraefikVersion }}). Review the Traefik changelog for breaking
                                    changes before upgrading.
                                </x-callout>
                            @endif
                        @elseif ($this->outdatedCaddyImage)
                            <x-server.caddy-image-outdated-callout :image="$this->outdatedCaddyImage" />
                        @endif

                        <div wire:loading.flex wire:target="loadProxyConfiguration"
                            class="min-h-32 items-center justify-center">
                            <x-loading text="Loading proxy configuration…" />
                        </div>

                        @if ($proxySettings)
                            <div class="relative mt-4" wire:loading.class="pointer-events-none opacity-50"
                                wire:target="submit,resetProxyConfiguration" aria-live="polite">
                                <div wire:loading.flex wire:target="submit,resetProxyConfiguration"
                                    class="absolute inset-0 z-20 hidden items-center justify-center rounded-lg bg-white/75 backdrop-blur-[1px] dark:bg-black/55">
                                    <div
                                        class="flex items-center gap-2 rounded-lg bg-white px-3 py-2 text-xs font-medium text-neutral-700 shadow-sm ring-1 ring-neutral-200 dark:bg-coolgray-100 dark:text-fg dark:ring-white/10">
                                        <x-loading />
                                        Updating proxy configuration…
                                    </div>
                                </div>
                                <x-forms.textarea canGate="update" :canResource="$server" useMonacoEditor
                                    monacoEditorLanguage="yaml"
                                    label="Configuration file · {{ $this->configurationFilePath }}"
                                    name="proxySettings" id="proxySettings" rows="30" />
                            </div>
                        @endif
                    </x-application.settings-section>
                @endif
                </fieldset>
            </form>
        @elseif($selectedProxy === 'NONE')
            <x-application.settings-section title="Custom proxy"
                helper="Coolify will not manage a reverse proxy for this server.">
                <x-slot:actions>
                    @can('update', $server)
                        <x-forms.button wire:click.prevent="changeProxy">Switch proxy</x-forms.button>
                    @endcan
                </x-slot:actions>
                <x-callout type="info" title="Custom proxy selected">
                    Configure and operate the proxy outside Coolify.
                </x-callout>
            </x-application.settings-section>
        @else
            <x-application.settings-section title="Proxy configuration"
                helper="Choose the reverse proxy implementation for this server.">
                @can('update', $server)
                    <div class="grid gap-3 lg:grid-cols-3">
                        @foreach ([
                            ['value' => 'NONE', 'title' => 'Custom', 'description' => 'Manage the proxy outside Coolify.'],
                            ['value' => 'TRAEFIK', 'title' => 'Traefik', 'description' => 'Use the default Coolify proxy.'],
                            ['value' => 'CADDY', 'title' => 'Caddy', 'description' => 'Use the Coolify Caddy integration.'],
                        ] as $proxyOption)
                            <button type="button" wire:click="selectProxy('{{ $proxyOption['value'] }}')"
                                class="rounded-lg p-4 text-left ring-1 ring-neutral-200 transition-colors hover:bg-neutral-50 dark:ring-white/[0.08] dark:hover:bg-white/[0.04]">
                                <p class="text-sm font-medium text-neutral-950 dark:text-fg">
                                    {{ $proxyOption['title'] }}
                                </p>
                                <p class="mt-1 text-xs leading-5 text-neutral-500 dark:text-fg-dim">
                                    {{ $proxyOption['description'] }}
                                </p>
                            </button>
                        @endforeach
                    </div>
                @else
                    <x-callout type="danger" title="Insufficient permissions">
                        You do not have permission to select a proxy for this server.
                    </x-callout>
                @endcan
            </x-application.settings-section>
        @endif
    @else
        <x-application.settings-section title="Proxy configuration"
            helper="Choose the reverse proxy implementation for this server.">
            @can('update', $server)
                <div class="grid gap-3 lg:grid-cols-3">
                    @foreach ([
                        ['value' => 'NONE', 'title' => 'Custom', 'description' => 'Manage the proxy outside Coolify.'],
                        ['value' => 'TRAEFIK', 'title' => 'Traefik', 'description' => 'Use the default Coolify proxy.'],
                        ['value' => 'CADDY', 'title' => 'Caddy', 'description' => 'Use the Coolify Caddy integration.'],
                    ] as $proxyOption)
                        <button type="button" wire:click="selectProxy('{{ $proxyOption['value'] }}')"
                            class="rounded-lg p-4 text-left ring-1 ring-neutral-200 transition-colors hover:bg-neutral-50 dark:ring-white/[0.08] dark:hover:bg-white/[0.04]">
                            <p class="text-sm font-medium text-neutral-950 dark:text-fg">{{ $proxyOption['title'] }}</p>
                            <p class="mt-1 text-xs leading-5 text-neutral-500 dark:text-fg-dim">
                                {{ $proxyOption['description'] }}
                            </p>
                        </button>
                    @endforeach
                </div>
            @endcan
        </x-application.settings-section>
    @endif
</div>
