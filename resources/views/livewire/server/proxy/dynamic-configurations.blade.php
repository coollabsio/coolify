<div>
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
                            <x-slide-over>
                                <x-slot:title>New Dynamic Configuration</x-slot:title>
                                <x-slot:content>
                                    <livewire:server.proxy.new-dynamic-configuration />
                                </x-slot:content>
                                <button @click="slideOverOpen=true"
                                    class="font-normal text-white normal-case border-none rounded btn btn-primary btn-sm no-animation">+
                                    Add</button>
                            </x-slide-over>
                        </div>
                        <div class='pb-4'>You can add dynamic Traefik configurations here.</div>
                    </div>
                </div>
                <div wire:loading wire:target="loadDynamicConfigurations">
                    <x-loading text="Loading dynamic configurations..." />
                </div>
                <div x-init="$wire.loadDynamicConfigurations" class="flex flex-col gap-4">
                    @if ($contents?->isNotEmpty())
                        @foreach ($contents as $fileName => $value)
                            <div class="flex flex-col gap-2 py-2">
                                @if (str_replace('|', '.', $fileName) === 'coolify.yaml')
                                    <div>
                                        <h3 class="text-white">File: {{ str_replace('|', '.', $fileName) }}</h3>
                                    </div>
                                    <x-forms.textarea disabled name="proxy_settings"
                                        wire:model="contents.{{ $fileName }}" rows="10" />
                                @else
                                    <livewire:server.proxy.dynamic-configuration-navbar :server_id="$server->id"
                                        :fileName="$fileName" :value="$value" :newFile="false"
                                        wire:key="{{ $fileName }}-{{ $loop->index }}" />
                                    <x-forms.textarea disabled wire:model="contents.{{ $fileName }}"
                                        rows="10" />
                                @endif
                            </div>
                        @endforeach
                    @endif
                </div>

            @endif
        </div>
    </div>
</div>
