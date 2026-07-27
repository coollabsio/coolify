<div>
    <x-slot:title>
        Proxy Dynamic Configuration | Coolify
    </x-slot>

    <livewire:server.navbar :server="$server" />

    <div
        class="server-settings-workspace application-settings-workspace mt-8 grid w-full max-w-[1180px] min-w-0 gap-8 xl:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-10">
        <x-server.sidebar-proxy :server="$server" :parameters="$parameters" />

        <div class="application-settings-form flex w-full flex-col gap-6">
            @if ($server->isFunctional())
                <div class="flex flex-wrap items-start justify-between gap-3 px-1">
                    <div>
                        <h2 class="text-sm! font-medium text-neutral-950 dark:text-fg">
                            Dynamic configurations
                        </h2>
                        <p class="mt-1 text-xs text-neutral-500 dark:text-fg-dim">
                            Manage additional proxy routes, middleware, and services loaded at runtime.
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <x-forms.button wire:click="loadDynamicConfigurations">
                            <x-reicon name="refresh" class="size-3.5" />
                            Reload
                        </x-forms.button>
                        @can('update', $server)
                            <x-modal-input buttonTitle="+ Add" title="New Dynamic Configuration">
                                <livewire:server.proxy.new-dynamic-configuration :server_id="$server->id" />
                            </x-modal-input>
                        @endcan
                    </div>
                </div>

                <div x-init="$wire.initLoadDynamicConfigurations" class="contents">
                    <div wire:loading wire:target="initLoadDynamicConfigurations"
                        class="rounded-lg border border-neutral-200 p-6 dark:border-white/[0.08]">
                        <x-loading text="Loading dynamic configurations…" />
                    </div>

                    @if ($contents?->isNotEmpty())
                        @foreach ($contents as $fileName => $value)
                            @php
                                $displayName = str_replace('|', '.', $fileName);
                                $isManagedConfiguration = in_array($displayName, [
                                    'coolify.yaml',
                                    'Caddyfile',
                                    'coolify.caddy',
                                    'default_redirect_503.yaml',
                                    'default_redirect_503.caddy',
                                ]);
                            @endphp
                            <x-application.settings-section :title="$displayName"
                                wire:key="proxy-dynamic-configuration-{{ $fileName }}">
                                <x-slot:actions>
                                    @if ($isManagedConfiguration)
                                        <x-status-badge status="Managed" type="neutral" />
                                    @else
                                        <livewire:server.proxy.dynamic-configuration-navbar
                                            :server_id="$server->id" :server="$server" :fileName="$fileName"
                                            :value="$value ?? ''" :newFile="false"
                                            wire:key="{{ $fileName }}-{{ $loop->index }}" />
                                    @endif
                                </x-slot:actions>
                                <x-forms.textarea disabled wire:model="contents.{{ $fileName }}"
                                    rows="8" />
                            </x-application.settings-section>
                        @endforeach
                    @else
                        <x-application.settings-section wire:loading.remove title="Dynamic configurations">
                            <x-empty size="sm" title="No dynamic configurations"
                                description="Add a configuration file to extend the proxy at runtime.">
                                <x-slot:icon>
                                    <x-reicon name="file-content" class="size-8" />
                                </x-slot:icon>
                            </x-empty>
                        </x-application.settings-section>
                    @endif
                </div>
            @else
                <x-application.settings-section title="Dynamic configurations"
                    helper="Manage additional runtime proxy configuration.">
                    <x-empty size="sm" title="Server validation required"
                        description="Validate this server before loading proxy configuration.">
                        <x-slot:icon>
                            <x-reicon name="file-content" class="size-8" />
                        </x-slot:icon>
                    </x-empty>
                </x-application.settings-section>
            @endif
        </div>
    </div>
</div>
