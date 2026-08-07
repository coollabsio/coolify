<div class="w-full">
    @if ($limit_reached)
        <x-limit-reached name="servers" />
    @elseif ($current_step === 1)
        <div class="flex flex-col w-full gap-4">
            <div class="text-sm text-neutral-500 dark:text-neutral-400">
                Manage your Hostinger API tokens in <a href="https://hpanel.hostinger.com/profile/api" target="_blank"
                    class="underline dark:text-white">hPanel</a>.
            </div>
            @if ($available_tokens->isNotEmpty())
                <div class="grid gap-3 md:grid-cols-2">
                    @foreach ($available_tokens as $token)
                        <a class="coolbox group text-left" wire:key="hostinger-token-{{ $token->id }}"
                            href="{{ route('server.create.token', ['type' => 'hostinger', 'token_uuid' => $token->uuid]) }}"
                            {{ wireNavigate() }}>
                            <div class="flex flex-col justify-center mx-6">
                                <div class="box-title">{{ $token->name ?? 'Hostinger Token' }}</div>
                                <div class="box-description">Use this token to purchase and create a Hostinger VPS.</div>
                            </div>
                        </a>
                    @endforeach
                </div>
            @else
                <div class="w-full max-w-2xl">
                    <x-modal-input title="Add Hostinger Token">
                        <x-slot:content>
                            <div class="coolbox group cursor-pointer">
                                <div class="flex items-center gap-4 mx-6">
                                    <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-coollabs/10 text-coollabs dark:bg-warning/20 dark:text-warning">
                                        <svg class="size-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                            stroke-width="1.5" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                        </svg>
                                    </div>
                                    <div class="flex flex-col justify-center">
                                        <div class="box-title">Add a new token</div>
                                        <div class="box-description">Add a Hostinger API token to create VPS instances.</div>
                                    </div>
                                </div>
                            </div>
                        </x-slot:content>
                        <livewire:security.cloud-provider-token-form :modal_mode="true" provider="hostinger"
                            wire:key="new-server-empty-token-hostinger" />
                    </x-modal-input>
                </div>
            @endif
        </div>
    @else
        <div wire:init="loadHostingerData">
            @if ($loading_data)
                <div class="flex items-center justify-center py-8">
                    <x-loading text="Loading Hostinger data..." />
                </div>
            @elseif ($provider_data_error)
                <div class="flex flex-col gap-4 rounded-lg border border-error bg-error/10 p-4">
                    <div>
                        <h3>Unable to load Hostinger details</h3>
                        <p class="text-sm text-neutral-700 dark:text-neutral-300">
                            Coolify could not fetch Hostinger data with the selected token.
                        </p>
                    </div>
                    <pre class="whitespace-pre-wrap break-words text-sm text-error">{{ $provider_data_error }}</pre>
                    <div>
                        <a class="button" href="{{ route('server.create.type', ['type' => 'hostinger']) }}" {{ wireNavigate() }}>
                            Select another token
                        </a>
                    </div>
                </div>
            @else
                <form class="flex flex-col w-full gap-2" wire:submit="submit">
                    <x-forms.input id="server_name" label="Server Name" helper="A valid hostname for your VPS." />

                    <x-forms.select label="Data Center" id="selected_data_center_id" required>
                        <option value="">Select a data center...</option>
                        @foreach ($data_centers as $dataCenter)
                            <option value="{{ $dataCenter['id'] }}">
                                {{ $dataCenter['city'] ?? $dataCenter['name'] }}@if (!empty($dataCenter['location']))
                                    ({{ strtoupper($dataCenter['location']) }})
                                @endif
                            </option>
                        @endforeach
                    </x-forms.select>

                    <x-forms.select label="Plan & Billing Period" id="selected_price_id" wire:model.live="selected_price_id" required>
                        <option value="">Select a plan...</option>
                        @foreach ($this->priceOptions as $price)
                            <option value="{{ $price['id'] }}">{{ $this->priceLabel($price) }}</option>
                        @endforeach
                    </x-forms.select>

                    <x-forms.select label="Operating System" id="selected_template_id" required>
                        <option value="">Select an operating system...</option>
                        @foreach ($templates as $template)
                            <option value="{{ $template['id'] }}">{{ $template['name'] ?? $template['description'] }}</option>
                        @endforeach
                    </x-forms.select>

                    <div>
                        @if ($private_keys->isEmpty())
                            <div class="flex flex-col gap-2 rounded border border-warning-500 bg-warning-50 p-4 dark:border-warning-600 dark:bg-warning-900/10">
                                <p class="text-sm text-neutral-700 dark:text-neutral-300">
                                    Create a private key before purchasing the VPS.
                                </p>
                                <x-modal-input buttonTitle="Create New Private Key" title="New Private Key" isHighlightedButton>
                                    <livewire:security.private-key.create :modal_mode="true" from="server" />
                                </x-modal-input>
                            </div>
                        @else
                            <x-forms.select label="Private Key" id="private_key_id" required>
                                <option value="">Select a private key...</option>
                                @foreach ($private_keys as $key)
                                    <option value="{{ $key->id }}">{{ $key->name }}</option>
                                @endforeach
                            </x-forms.select>
                            <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                                This public key will be installed during Hostinger VPS setup.
                            </p>
                        @endif
                    </div>

                    <x-forms.checkbox id="enable_backups" label="Enable weekly Hostinger backups" />

                    <div class="my-2 rounded border border-warning/40 bg-warning/10 p-3 text-sm">
                        This action purchases a paid Hostinger VPS using your account's default payment method.
                        Review the plan and billing period before continuing.
                    </div>

                    <x-forms.button type="submit" class="w-full" :disabled="$private_keys->isEmpty()" :showLoadingIndicator="false"
                        wire:loading.attr="disabled" wire:target="submit">
                        Buy & Create Server
                        <x-loading-on-button wire:loading wire:target="submit" />
                    </x-forms.button>
                </form>
            @endif
        </div>
    @endif
</div>
