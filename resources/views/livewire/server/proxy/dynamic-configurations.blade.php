<div>
    <x-slot:title>
        Proxy Dynamic Configuration | Coolify
    </x-slot>
    <x-server.navbar :server="$server" :parameters="$parameters" />
    <div class="flex gap-2">
        <x-server.sidebar :server="$server" :parameters="$parameters" />
        <div class="w-full">
            @if ($server->isFunctional())
                <div class="flex gap-2">
                    <div>
                        <div class="flex gap-2">
                            <h2>Dynamic Configurations</h2>
                            <x-forms.button wire:click='loadDynamicConfigurations'>Reload</x-forms.button>
                            <x-modal-input buttonTitle="+ Add" title="New Dynamic Configuration">
                                <livewire:server.proxy.new-dynamic-configuration />
                            </x-modal-input>
                        </div>
                        <div class='pb-4'>You can add dynamic proxy configurations here.</div>
                    </div>
                </div>
                <div wire:loading wire:target="loadDynamicConfigurations">
                    <x-loading text="Loading dynamic configurations..." />
                </div>
                <div x-init="$wire.loadDynamicConfigurations" class="flex flex-col gap-4">
                    @if ($contents?->isNotEmpty())
                        @foreach ($contents as $fileName => $value)
                            <div class="flex flex-col gap-2 py-2">
                                @if (str_replace('|', '.', $fileName) === 'coolify.yaml' ||
                                        str_replace('|', '.', $fileName) === 'Caddyfile' ||
                                        str_replace('|', '.', $fileName) === 'coolify.caddy' ||
                                        str_replace('|', '.', $fileName) === 'default_redirect_404.caddy')
                                    <div>
                                        <h3 class="dark:text-white">File: {{ str_replace('|', '.', $fileName) }}</h3>
                                    </div>
                                    <x-forms.textarea disabled name="proxy_settings"
                                        wire:model="contents.{{ $fileName }}" rows="5" />
                                @else
                                    <livewire:server.proxy.dynamic-configuration-navbar :server_id="$server->id"
                                        :fileName="$fileName" :value="$value" :newFile="false"
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
            @endif
        </div>
    </div>
</div>
