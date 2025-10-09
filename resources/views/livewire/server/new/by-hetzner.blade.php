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
                            <x-forms.button canGate="create" :canResource="App\Models\Server::class" wire:click="nextStep" :disabled="!$selected_token_id">
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
                        <x-forms.select label="Location" id="selected_location" wire:model.live="selected_location"
                            required>
                            <option value="">Select a location...</option>
                            @foreach ($locations as $location)
                                <option value="{{ $location['name'] }}">
                                    {{ $location['city'] }} - {{ $location['country'] }}
                                </option>
                            @endforeach
                        </x-forms.select>
                    </div>

                    <div>
                        <x-forms.select label="Server Type" id="selected_server_type"
                            wire:model.live="selected_server_type" required :disabled="!$selected_location">
                            <option value="">
                                {{ $selected_location ? 'Select a server type...' : 'Select a location first' }}
                            </option>
                            @foreach ($this->availableServerTypes as $serverType)
                                <option value="{{ $serverType['name'] }}">
                                    {{ $serverType['description'] }} -
                                    {{ $serverType['cores'] }} vCPU,
                                    {{ $serverType['memory'] }}GB RAM,
                                    {{ $serverType['disk'] }}GB
                                    @if (isset($serverType['architecture']))
                                        ({{ $serverType['architecture'] }})
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
                        <x-forms.select label="Private Key" id="private_key_id" required>
                            <option value="">Select a private key...</option>
                            @foreach ($private_keys as $key)
                                <option value="{{ $key->id }}">
                                    {{ $key->name }}
                                </option>
                            @endforeach
                        </x-forms.select>
                    </div>

                    <div class="flex gap-2 justify-between">
                        <x-forms.button type="button" wire:click="previousStep">
                            Back
                        </x-forms.button>
                        <x-forms.button isHighlighted canGate="create" :canResource="App\Models\Server::class" type="submit">
                            Buy & Create Server
                        </x-forms.button>
                    </div>
                </form>
            @endif
        @endif
    @endif
</div>
