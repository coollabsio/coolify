<div class="w-full">
    @if ($limit_reached)
        <x-limit-reached name="servers" />
    @else
        @if ($current_step === 1)
            <div class="flex flex-col w-full gap-4">
                @if ($available_tokens->count() > 0)
                    <div class="flex gap-2">
                        <div class="flex-1">
                            <x-forms.select label="Select Hetzner Token" id="selected_token_id"
                                wire:change="selectToken($event.target.value)" required>
                                <option value="">Select a saved token...</option>
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
                    buttonTitle="{{ $available_tokens->count() > 0 ? '+ Add New Token' : 'Add Hetzner Token' }}"
                    title="Add Hetzner Token">
                    <livewire:security.cloud-provider-token-form :modal_mode="true" provider="hetzner" />
                </x-modal-input>
            </div>
        @elseif ($current_step === 2)
            @if ($loading_data)
                <div class="flex items-center justify-center py-8">
                    <div class="text-center">
                        <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-primary mx-auto"></div>
                        <p class="mt-4 text-sm dark:text-neutral-400">Loading Hetzner data...</p>
                    </div>
                </div>
            @else
                <form class="flex flex-col w-full gap-2" wire:submit='submit'>
                    <div>
                        <x-forms.input id="server_name" label="Server Name" helper="A friendly name for your server." />
                    </div>

                    <div>
                        <x-forms.select label="Location" id="selected_location" wire:model.live="selected_location" required>
                            <option value="">Select a location...</option>
                            @foreach ($locations as $location)
                                <option value="{{ $location['name'] }}">
                                    {{ $location['city'] }} - {{ $location['country'] }}
                                </option>
                            @endforeach
                        </x-forms.select>
                    </div>

                    <div>
                        <x-forms.select label="Server Type" id="selected_server_type" wire:model.live="selected_server_type"
                            helper="Learn more about <a class='inline-block underline dark:text-white' href='https://www.hetzner.com/cloud/' target='_blank'>Hetzner server types</a>"
                            required :disabled="!$selected_location">
                            <option value="">
                                {{ $selected_location ? 'Select a server type...' : 'Select a location first' }}
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
                        <x-forms.select label="Image" id="selected_image" required :disabled="!$selected_server_type">
                            <option value="">
                                {{ $selected_server_type ? 'Select an image...' : 'Select a server type first' }}
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
                                    Private Key
                                    <x-highlighted text="*" />
                                </label>
                                <div
                                    class="p-4 border border-warning-500 dark:border-warning-600 rounded bg-warning-50 dark:bg-warning-900/10">
                                    <p class="text-sm mb-3 text-neutral-700 dark:text-neutral-300">
                                        No private keys found. You need to create a private key to continue.
                                    </p>
                                    <x-modal-input buttonTitle="Create New Private Key" title="New Private Key" isHighlightedButton>
                                        <livewire:security.private-key.create :modal_mode="true" from="server" />
                                    </x-modal-input>
                                </div>
                            </div>
                        @else
                            <x-forms.select label="Private Key" id="private_key_id" required>
                                <option value="">Select a private key...</option>
                                @foreach ($private_keys as $key)
                                    <option value="{{ $key->id }}">
                                        {{ $key->name }}
                                    </option>
                                @endforeach
                            </x-forms.select>
                            <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                                This SSH key will be automatically added to your Hetzner account and used to access the
                                server.
                            </p>
                        @endif
                    </div>
                    <div>
                        <x-forms.datalist label="Additional SSH Keys (from Hetzner)" id="selectedHetznerSshKeyIds"
                            helper="Select existing SSH keys from your Hetzner account to add to this server. The Coolify SSH key will be automatically added."
                            :multiple="true" :disabled="count($hetznerSshKeys) === 0" :placeholder="count($hetznerSshKeys) > 0
                                ? 'Search and select SSH keys...'
                                : 'No SSH keys found in Hetzner account'">
                            @foreach ($hetznerSshKeys as $sshKey)
                                <option value="{{ $sshKey['id'] }}">
                                    {{ $sshKey['name'] }} - {{ substr($sshKey['fingerprint'], 0, 20) }}...
                                </option>
                            @endforeach
                        </x-forms.datalist>
                    </div>

                    <div class="flex flex-col gap-2">
                        <label class="text-sm font-medium">Network Configuration</label>
                        <div class="flex gap-4">
                            <x-forms.checkbox id="enable_ipv4" label="Enable IPv4"
                                helper="Enable public IPv4 address for this server" />
                            <x-forms.checkbox id="enable_ipv6" label="Enable IPv6"
                                helper="Enable public IPv6 address for this server" />
                        </div>
                    </div>

                    <div class="flex flex-col gap-2">
                        <div class="flex justify-between items-center gap-2">
                            <label class="text-sm font-medium w-32">Cloud-Init Script</label>
                            @if ($saved_cloud_init_scripts->count() > 0)
                                <div class="flex items-center gap-2 flex-1">
                                    <x-forms.select wire:model.live="selected_cloud_init_script_id" label="" helper="">
                                        <option value="">Load saved script...</option>
                                        @foreach ($saved_cloud_init_scripts as $script)
                                            <option value="{{ $script->id }}">{{ $script->name }}</option>
                                        @endforeach
                                    </x-forms.select>
                                    <x-forms.button type="button" wire:click="clearCloudInitScript">
                                        Clear
                                    </x-forms.button>
                                </div>
                            @endif
                        </div>
                        <x-forms.textarea id="cloud_init_script" label=""
                            helper="Add a cloud-init script to run when the server is created. See Hetzner's documentation for details."
                            rows="8" />

                        <div class="flex items-center gap-2">
                            <x-forms.checkbox id="save_cloud_init_script" label="Save this script for later use" />
                            <div class="flex-1">
                                <x-forms.input id="cloud_init_script_name" label="" placeholder="Script name..." />
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