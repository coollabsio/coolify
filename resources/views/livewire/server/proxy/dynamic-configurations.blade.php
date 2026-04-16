<div>
    <x-slot:title>
        Proxy Dynamic Configuration | Coolify
    </x-slot>
    <livewire:server.navbar :server="$server" />
    <div class="flex flex-col h-full gap-8 sm:flex-row">
        <x-server.sidebar-proxy :server="$server" :parameters="$parameters" />
        @if ($server->isFunctional())
            <div class="w-full">
                <div class="flex gap-2">
                    <div>
                        <div class="flex gap-2">
                            <h2>Dynamic Configurations</h2>
                            <x-forms.button wire:click="loadDynamicConfigurations">Reload</x-forms.button>
                            @can('update', $server)
                                <x-modal-input buttonTitle="+ Add" title="New Dynamic Configuration">
                                    <livewire:server.proxy.new-dynamic-configuration :server_id="$server->id" />
                                </x-modal-input>
                            @endcan
                        </div>
                        <div class='pb-4'>You can add dynamic proxy configurations here.</div>
                    </div>
                </div>
                <div wire:loading wire:target="initLoadDynamicConfigurations">
                    <x-loading text="Loading dynamic configurations..." />
                </div>
                <div x-init="$wire.initLoadDynamicConfigurations" class="flex flex-col gap-4">
                    @if ($contents?->isNotEmpty())
                        @foreach ($contents as $fileName => $value)
                            @php
                                $realName = str_replace('|', '.', $fileName);
                                $isManagedGateway =
                                    str_starts_with($realName, 'gateway-') &&
                                    str_ends_with($realName, '.yaml') &&
                                    str_contains((string) $value, \App\Livewire\Server\Proxy\Gateway::MANAGED_FILE_MARKER);
                            @endphp
                            <div class="flex flex-col gap-2 py-2">
                                @if (
                                    $realName === 'coolify.yaml' ||
                                        $realName === 'Caddyfile' ||
                                        $realName === 'coolify.caddy' ||
                                        $realName === 'default_redirect_503.yaml' ||
                                        $realName === 'default_redirect_503.caddy' ||
                                        $isManagedGateway)
                                    <div>
                                        <h3 class="dark:text-white">File: {{ $realName }}</h3>
                                    </div>
                                    <x-forms.textarea disabled name="proxy_settings"
                                        wire:model="contents.{{ $fileName }}" rows="5" />
                                @else
                                    <livewire:server.proxy.dynamic-configuration-navbar :server_id="$server->id"
                                        :server="$server" :fileName="$fileName" :value="$value ?? ''" :newFile="false"
                                        wire:key="{{ $fileName }}-{{ $loop->index }}" />
                                    <x-forms.textarea disabled wire:model="contents.{{ $fileName }}"
                                        rows="10" />
                                @endif
                            </div>
                        @endforeach
                    @else
                        <div wire:loading.remove> No dynamic configurations found.</div>
                    @endif
                </div>
            </div>
        @endif
    </div>
</div>
