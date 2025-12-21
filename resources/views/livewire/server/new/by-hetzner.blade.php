<div class="w-full">
    @if ($limit_reached)
        <x-limit-reached name="servers" />
    @else
        @if ($current_step === 1)
            <div class="flex flex-col w-full gap-4">
                @if ($available_tokens->count() > 0)
                    <div class="flex gap-2">
                        <div class="flex-1">
                            <x-forms.select label="{{ __('server.select_hetzner_token') }}" id="selected_token_id"
                                wire:change="selectToken($event.target.value)" required>
                                <option value="">{{ __('server.select_saved_token') }}</option>
                                @foreach ($available_tokens as $token)
                                    <option value="{{ $token->id }}">
                                        {{ $token->name ?? 'Hetzner Token' }}
                                    </option>
                                @endforeach
                            </x-forms.select>
                        </div>
                        <div class="flex items-end">
                            <x-forms.button canGate="create" :canResource="App\Models\Server::class" wire:click="nextStep"
                                :disabled="!$selected_token_id">
                                Continue
                            </x-forms.button>
                        </div>
                    </div>

                    <div class="text-center text-sm dark:text-neutral-500">OR</div>
                @endif

                <x-modal-input isFullWidth
                    buttonTitle="{{ $available_tokens->count() > 0 ? __('button.add_new_token') : __('modal.add_hetzner_token') }}"
                    title="{{ __('modal.add_hetzner_token') }}">
                    <livewire:security.cloud-provider-token-form :modal_mode="true" provider="hetzner" />
                </x-modal-input>
            </div>
        @elseif ($current_step === 2)
            @if ($loading_data)
                <div class="flex items-center justify-center py-8">
                    <div class="text-center">
                        <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-primary mx-auto"></div>
                        <p class="mt-4 text-sm dark:text-neutral-400">{{ __('server.loading_hetzner_data') }}</p>
                    </div>
                </div>
            @else
                <form class="flex flex-col w-full gap-2" wire:submit='submit'>
                    <div>
                        <x-forms.input id="server_name" label="{{ __('server.server_name') }}" helper="{{ __('server.server_name_helper') }}" />
                    </div>

                    <div>
                        <x-forms.select label="{{ __('server.location') }}" id="selected_location" wire:model.live="selected_location" required>
                            <option value="">{{ __('server.select_location') }}</option>
                            @foreach ($locations as $location)
                                <option value="{{ $location['name'] }}">
                                    {{ $location['city'] }} - {{ $location['country'] }}
                                </option>
                            @endforeach
                        </x-forms.select>
                    </div>

                    <div>
                        <x-forms.select label="{{ __('server.server_type') }}" id="selected_server_type" wire:model.live="selected_server_type"
                            helper="{{ __('server.server_type_helper') }}"
                            required :disabled="!$selected_location">
                            <option value="">
                                {{ $selected_location ? __('server.select_server_type') : __('server.select_location_first') }}
                            </option>
                            @foreach ($this->availableServerTypes as $serverType)
                                <option value="{{ $serverType['name'] }}">
                                    {{ $serverType['description'] }} -
                                    {{ $serverType['cores'] }} vCPU
                                    @if (isset($serverType['cpu_vendor_info']) && $serverType['cpu_vendor_info'])
                                        ({{ $serverType['cpu_vendor_info'] }})
                                    @endif
                                    , {{ $serverType['memory'] }}GB RAM, 
                                    {{ $serverType['disk'] }}GB
                                    @if (isset($serverType['architecture']))
                                        [{{ $serverType['architecture'] }}]
                                    @endif
                                    @if (isset($serverType['prices']))
                                        -
                                        €{{ number_format($serverType['prices'][0]['price_monthly']['gross'] ?? 0, 2) }}/mo
                                    @endif
                                </option>
                            @endforeach
                        </x-forms.select>
                    </div>

                    <div>
                        <x-forms.select label="{{ __('server.image') }}" id="selected_image" required :disabled="!$selected_server_type">
                            <option value="">
                                {{ $selected_server_type ? __('server.select_image') : __('server.select_server_type_first') }}
                            </option>
                            @foreach ($this->availableImages as $image)
                                <option value="{{ $image['id'] }}">
                                    {{ $image['description'] ?? $image['name'] }}
                                    @if (isset($image['architecture']))
                                        ({{ $image['architecture'] }})
                                    @endif
                                </option>
                            @endforeach
                        </x-forms.select>
                    </div>

                    <div>
                        @if ($private_keys->count() === 0)
                            <div class="flex flex-col gap-2">
                                <label class="flex gap-1 items-center mb-1 text-sm font-medium">
                                    {{ __('server.private_key') }}
                                    <x-highlighted text="*" />
                                </label>
                                <div
                                    class="p-4 border border-warning-500 dark:border-warning-600 rounded bg-warning-50 dark:bg-warning-900/10">
                                    <p class="text-sm mb-3 text-neutral-700 dark:text-neutral-300">
                                        {{ __('server.no_private_keys_found_create') }}
                                    </p>
                                    <x-modal-input buttonTitle="{{ __('common.create') }} {{ __('modal.new_private_key') }}" title="{{ __('modal.new_private_key') }}" isHighlightedButton>
                                        <livewire:security.private-key.create :modal_mode="true" from="server" />
                                    </x-modal-input>
                                </div>
                            </div>
                        @else
                            <x-forms.select label="{{ __('server.private_key') }}" id="private_key_id" required>
                                <option value="">{{ __('server.select_private_key_option') }}</option>
                                @foreach ($private_keys as $key)
                                    <option value="{{ $key->id }}">
                                        {{ $key->name }}
                                    </option>
                                @endforeach
                            </x-forms.select>
                            <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                                {{ __('server.ssh_key_auto_added') }}
                            </p>
                        @endif
                    </div>
                    <div>
                        <x-forms.datalist label="{{ __('server.additional_ssh_keys') }}" id="selectedHetznerSshKeyIds"
                            helper="{{ __('server.additional_ssh_keys_helper') }}"
                            :multiple="true" :disabled="count($hetznerSshKeys) === 0" :placeholder="count($hetznerSshKeys) > 0
                                ? __('server.search_select_ssh_keys')
                                : __('server.no_ssh_keys_in_hetzner')">
                            @foreach ($hetznerSshKeys as $sshKey)
                                <option value="{{ $sshKey['id'] }}">
                                    {{ $sshKey['name'] }} - {{ substr($sshKey['fingerprint'], 0, 20) }}...
                                </option>
                            @endforeach
                        </x-forms.datalist>
                    </div>

                    <div class="flex flex-col gap-2">
                        <label class="text-sm font-medium">{{ __('server.network_configuration') }}</label>
                        <div class="flex gap-4">
                            <x-forms.checkbox id="enable_ipv4" label="{{ __('server.enable_ipv4') }}"
                                helper="{{ __('server.enable_ipv4_helper') }}" />
                            <x-forms.checkbox id="enable_ipv6" label="{{ __('server.enable_ipv6') }}"
                                helper="{{ __('server.enable_ipv6_helper') }}" />
                        </div>
                    </div>

                    <div class="flex flex-col gap-2">
                        <div class="flex justify-between items-center gap-2">
                            <label class="text-sm font-medium w-32">{{ __('server.cloud_init_script') }}</label>
                            @if ($saved_cloud_init_scripts->count() > 0)
                                <div class="flex items-center gap-2 flex-1">
                                    <x-forms.select wire:model.live="selected_cloud_init_script_id" label="" helper="">
                                        <option value="">{{ __('server.load_saved_script') }}</option>
                                        @foreach ($saved_cloud_init_scripts as $script)
                                            <option value="{{ $script->id }}">{{ $script->name }}</option>
                                        @endforeach
                                    </x-forms.select>
                                    <x-forms.button type="button" wire:click="clearCloudInitScript">
                                        {{ __('server.clear') }}
                                    </x-forms.button>
                                </div>
                            @endif
                        </div>
                        <x-forms.textarea id="cloud_init_script" label=""
                            helper="{{ __('server.cloud_init_script_helper') }}"
                            rows="8" />

                        <div class="flex items-center gap-2">
                            <x-forms.checkbox id="save_cloud_init_script" label="{{ __('server.save_script_later') }}" />
                            <div class="flex-1">
                                <x-forms.input id="cloud_init_script_name" label="" placeholder="{{ __('server.script_name_placeholder') }}" />
                            </div>
                        </div>
                    </div>

                    <div class="flex gap-2 justify-between">
                        <x-forms.button type="button" wire:click="previousStep">
                            Back
                        </x-forms.button>
                        <x-forms.button isHighlighted canGate="create" :canResource="App\Models\Server::class" type="submit"
                            :disabled="!$private_key_id">
                            Buy & Create Server{{ $this->selectedServerPrice ? ' (' . $this->selectedServerPrice . '/mo)' : '' }}
                        </x-forms.button>
                    </div>
                </form>
            @endif
        @endif
    @endif
</div>