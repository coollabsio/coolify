<div>
    <div>
        <h2>Gateway</h2>
        <div class="pb-4">Route traffic through Traefik to external services.</div>
    </div>

    @if ($dnsChallengeMissing && $routes?->contains(fn ($r) => str_starts_with($r['domain'] ?? '', '*.')))
        <x-callout type="warning" title="Wildcard TLS not ready" class="mb-4">
            Wildcard TLS certificates configuration is missing on your Traefik configuration. Set it up by following
            <a href="https://coolify.io/docs/knowledge-base/proxy/traefik/wildcard-certs" target="_blank"
                rel="noopener" class="underline">this guide</a>.
        </x-callout>
    @endif

    <div class="flex gap-2 items-center pt-4">
        <h3>Routes</h3>
        <x-forms.button wire:click="loadRoutes">
            Reload
            <x-loading-on-button wire:target="initLoadRoutes" wire:loading.delay />
        </x-forms.button>
        @can('update', $server)
            <x-modal-input buttonTitle="+ Add" title="New Gateway Route">
                <livewire:server.proxy.gateway-route-form :server_id="$server->id" />
            </x-modal-input>
        @endcan
    </div>

    <div x-init="$wire.initLoadRoutes" class="flex flex-col gap-2 mt-2">
        @if ($brokenFiles?->isNotEmpty())
            @foreach ($brokenFiles as $bf)
                <div
                    class="rounded border border-red-300 dark:border-red-800/60 bg-red-50 dark:bg-red-950/30 p-4">
                    <div class="flex gap-3 justify-between items-start">
                        <div class="min-w-0 flex-1">
                            <div class="flex gap-2 items-center">
                                <span
                                    class="px-1.5 py-0.5 text-xs rounded border border-red-400 bg-red-100 text-red-800 dark:border-red-500 dark:bg-red-900/50 dark:text-red-200 shrink-0">
                                    Broken file
                                </span>
                                <h4 class="dark:text-white truncate">{{ basename($bf['file']) }}</h4>
                            </div>
                            <pre
                                class="mt-2 text-xs whitespace-pre-wrap break-words text-red-900 dark:text-red-200">{{ $bf['error'] }}</pre>
                        </div>
                        @can('update', $server)
                            <x-modal-confirmation title="Delete Broken Gateway File?" isErrorButton
                                buttonTitle="Delete file"
                                submitAction="deleteBrokenFile({{ $bf['file'] }})"
                                :actions="['The file ' . basename($bf['file']) . ' will be deleted on the server.']"
                                confirmationText="{{ basename($bf['file']) }}"
                                confirmationLabel="Type the filename to confirm"
                                shortConfirmationLabel="Filename" :confirmWithPassword="false"
                                step2ButtonText="Permanently Delete" />
                        @endcan
                    </div>
                </div>
            @endforeach
        @endif
        @if ($routes?->isNotEmpty())
            @foreach ($routes as $route)
                <div x-data="{ expanded: false }"
                    class="rounded border border-neutral-200 dark:border-coolgray-200 dark:bg-coolgray-100">
                    <div class="flex gap-2 justify-between items-center p-4 cursor-pointer select-none hover:bg-gray-50 dark:hover:bg-coolgray-200"
                        @click="expanded = !expanded">
                        <div class="flex gap-3 items-center min-w-0">
                            <svg class="w-4 h-4 transition-transform shrink-0"
                                :class="expanded ? 'rotate-90' : ''" viewBox="0 0 24 24"
                                xmlns="http://www.w3.org/2000/svg">
                                <path fill="currentColor"
                                    d="M8.59 16.59L13.17 12 8.59 7.41 10 6l6 6-6 6-1.41-1.41z" />
                            </svg>
                            <h4 class="dark:text-white shrink-0">{{ $route['name'] }}</h4>
                            @if (! empty($route['missing_fields']))
                                <span title="The UI couldn't read: {{ implode(', ', $route['missing_fields']) }}"
                                    class="px-1.5 py-0.5 text-xs rounded border border-red-300 bg-red-50 text-red-800 dark:border-red-600 dark:bg-red-900/40 dark:text-red-200 shrink-0">
                                    Missing fields
                                </span>
                            @endif
                            @if ($route['has_extra_config'])
                                <span title="This file has Traefik fields the UI doesn't manage."
                                    class="px-1.5 py-0.5 text-xs rounded border border-amber-300 bg-amber-50 text-amber-800 dark:border-amber-600 dark:bg-amber-900/40 dark:text-amber-200 shrink-0">
                                    Extra config
                                </span>
                            @endif
                            <span
                                class="text-xs truncate dark:text-gray-400">{{ $route['domain'] }}{{ $route['path_prefix'] !== '/' ? $route['path_prefix'] : '' }}</span>
                            <span class="text-xs dark:text-gray-500">→</span>
                            <span class="text-xs truncate dark:text-gray-400">{{ $route['target_url'] }}</span>
                        </div>
                    </div>
                    @php
                        $readonlyClass = 'read-only:!bg-neutral-50 read-only:!text-black read-only:!border read-only:!border-neutral-300 dark:read-only:!bg-coolgray-200 dark:read-only:!text-white dark:read-only:!border dark:read-only:!border-coolgray-300';
                    @endphp
                    <div x-show="expanded" x-collapse x-cloak>
                        <div class="px-4 pb-4">
                            @if (! empty($route['missing_fields']))
                                <x-callout type="warning" title="Missing required fields" class="mb-3">
                                    The UI couldn't read these from {{ basename($route['source_file']) }}:
                                    <strong>{{ implode(', ', $route['missing_fields']) }}</strong>.
                                    The route may not work until you edit it and save valid values.
                                </x-callout>
                            @endif
                            @if ($route['has_extra_config'])
                                <x-callout type="warning" title="Extra Traefik config in this file" class="mb-3">
                                    {{ basename($route['source_file']) }} contains fields the UI doesn't manage
                                    (e.g. custom middlewares, headers, multiple servers). Saving from this form will
                                    overwrite this route's router/service/middleware entries; other routers in the
                                    file are preserved.
                                </x-callout>
                            @endif
                            <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                                <x-forms.input label="Domain" :value="$route['domain']" readonly :class="$readonlyClass" />
                                <x-forms.input label="Path Prefix" :value="$route['path_prefix']" readonly :class="$readonlyClass" />
                            </div>
                            <div class="grid grid-cols-1 gap-3 mt-3 md:grid-cols-3">
                                <x-forms.input label="Target URL" :value="$route['target_url']" readonly :class="$readonlyClass" />
                                <x-forms.input label="Entrypoints" :value="implode(', ', $route['entrypoints'])" readonly :class="$readonlyClass" />
                                <x-forms.input label="TLS Cert Resolver"
                                    :value="$route['tls_enabled'] ? $route['tls_cert_resolver'] : '—'" readonly :class="$readonlyClass" />
                            </div>
                            <div class="grid grid-cols-2 gap-3 mt-3 md:grid-cols-4">
                                <x-forms.input label="TLS Enabled" :value="$route['tls_enabled'] ? 'Yes' : 'No'" readonly :class="$readonlyClass" />
                                <x-forms.input label="HTTPS Redirect" :value="$route['https_redirect'] ? 'Yes' : 'No'" readonly :class="$readonlyClass" />
                                <x-forms.input label="Pass Host Header" :value="$route['pass_host_header'] ? 'Yes' : 'No'" readonly :class="$readonlyClass" />
                                <x-forms.input label="Strip Prefix" :value="$route['strip_prefix'] ? 'Yes' : 'No'" readonly :class="$readonlyClass" />
                            </div>
                            @can('update', $server)
                                <div class="flex gap-2 mt-4">
                                    <x-modal-input buttonTitle="Edit" title="Edit Gateway Route">
                                        <livewire:server.proxy.gateway-route-form :server_id="$server->id"
                                            :routerName="$route['router_name']"
                                            wire:key="edit-{{ $route['router_name'] }}" />
                                    </x-modal-input>
                                    <x-modal-confirmation title="Confirm Gateway Route Deletion?" isErrorButton
                                        buttonTitle="Delete"
                                        submitAction="deleteRoute({{ $route['router_name'] }})"
                                        :actions="[
                                            $route['is_only_in_file']
                                                ? 'The file ' . basename($route['source_file']) . ' will be deleted on the server.'
                                                : 'This route will be removed from ' . basename($route['source_file']) . '. Other routes in the file will be preserved.',
                                        ]"
                                        confirmationText="{{ $route['name'] }}"
                                        confirmationLabel="Please confirm by entering the route name below"
                                        shortConfirmationLabel="Route Name" :confirmWithPassword="false"
                                        step2ButtonText="Permanently Delete" />
                                </div>
                            @endcan
                        </div>
                    </div>
                </div>
            @endforeach
        @else
            <div wire:loading.remove wire:target="initLoadRoutes,loadRoutes">
                <x-callout type="info" title="No routes">
                    No gateway routes configured yet. Click <strong>+ Add</strong> to create one.
                </x-callout>
            </div>
        @endif
    </div>
</div>
