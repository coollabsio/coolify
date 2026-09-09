<div class="w-full">
    @if ($limit_reached)
        <x-limit-reached name="servers" />
    @elseif ($current_step === 1)
        <div class="flex flex-col gap-6">
            <x-server.provider-token-picker provider="hostinger" providerLabel="Hostinger"
                :tokens="$available_tokens" />
            <p class="text-[11px] text-neutral-500 dark:text-fg-faint">
                New to Hostinger?
                <a href="https://www.hostinger.com/vps/coolify-hosting?ref=coolify.io" target="_blank"
                    rel="noopener noreferrer"
                    class="font-medium text-coollabs hover:underline dark:text-warning">Create an account</a>
                through Coolify's affiliate link.
            </p>
        </div>
    @elseif ($current_step === 2)
        <div wire:init="loadHostingerData">
            @if ($loading_data)
                <x-application.settings-section title="Loading Hostinger"
                    description="Fetching data centers, plans, billing periods, and operating systems.">
                    <div class="flex min-h-40 items-center justify-center">
                        <x-loading text="Loading Hostinger data..." />
                    </div>
                </x-application.settings-section>
            @elseif ($provider_data_error)
                <x-application.settings-section title="Unable to load Hostinger"
                    description="The selected token could not access the provider API.">
                    <x-callout type="error" title="Provider request failed">
                        <pre class="mt-2 whitespace-pre-wrap break-words text-[11px]">{{ $provider_data_error }}</pre>
                    </x-callout>
                    <div class="mt-4">
                        <a class="button" href="{{ route('server.create.type', ['type' => 'hostinger']) }}"
                            {{ wireNavigate() }}>Select another token</a>
                    </div>
                </x-application.settings-section>
            @else
                @php
                    $dataCenterOptions = collect($data_centers)->map(fn ($dataCenter) => [
                        'value' => $dataCenter['id'],
                        'label' => ($dataCenter['city'] ?? $dataCenter['name'])
                            . (!empty($dataCenter['location']) ? ' · ' . strtoupper($dataCenter['location']) : ''),
                    ])->values()->all();
                    $priceOptions = collect($this->priceOptions)->map(fn ($price) => [
                        'value' => $price['id'],
                        'label' => $this->priceLabel($price),
                    ])->values()->all();
                    $templateOptions = collect($templates)->map(fn ($template) => [
                        'value' => $template['id'],
                        'label' => $template['name'] ?? $template['description'],
                    ])->values()->all();
                    $privateKeyOptions = $private_keys->map(fn ($key) => [
                        'value' => $key->id,
                        'label' => $key->name,
                    ])->values()->all();
                @endphp

                <form wire:submit="submit" class="flex flex-col gap-6">
                    <x-application.settings-section title="Hostinger server"
                        description="Choose the data center, plan, operating system, and Coolify SSH key.">
                        <x-slot:actions>
                            <button type="submit" class="button button-highlighted"
                                wire:loading.attr="disabled" wire:target="submit"
                                @disabled(!$private_key_id)>
                                Buy and create
                                <x-loading-on-button wire:loading wire:target="submit" />
                            </button>
                        </x-slot:actions>

                        <div class="grid gap-4 lg:grid-cols-2">
                            <div class="lg:col-span-2">
                                <x-forms.input id="server_name" label="Server name"
                                    helper="A friendly name shown in Coolify and used as the VPS hostname." />
                            </div>
                            <x-forms.listbox id="selected_data_center_id" label="Data center" required live
                                placeholder="Select a data center" :options="$dataCenterOptions" />
                            <x-forms.listbox id="selected_price_id" label="Plan and billing period" required live
                                :disabled="!$selected_data_center_id" placeholder="Select a plan"
                                :options="$priceOptions" />
                            <x-forms.listbox id="selected_template_id" label="Operating system" required
                                :disabled="!$selected_price_id" placeholder="Select an operating system"
                                :options="$templateOptions" />
                            @if ($private_keys->isEmpty())
                                <div>
                                    <label class="mb-1.5 flex w-fit items-center gap-1.5">Private key
                                        <x-highlighted text="*" />
                                    </label>
                                    <div
                                        class="flex min-h-8 items-center justify-between gap-3 rounded-lg border border-warning/30 bg-warning/5 px-3 py-2">
                                        <span class="text-[11px] text-neutral-600 dark:text-fg-dim">A private key is required.</span>
                                        <x-modal-input title="New Private Key">
                                            <x-slot:content>
                                                <button type="button" class="button">Create key</button>
                                            </x-slot:content>
                                            <livewire:security.private-key.create :modal_mode="true" from="server" />
                                        </x-modal-input>
                                    </div>
                                </div>
                            @else
                                <x-forms.listbox id="private_key_id" label="Private key" required
                                    placeholder="Select a private key" :options="$privateKeyOptions"
                                    helper="This key is added to the Hostinger VPS automatically." />
                            @endif
                        </div>
                    </x-application.settings-section>

                    <x-application.settings-section title="Advanced options"
                        description="Provider backups and purchase details.">
                        <div class="flex flex-col gap-4">
                            <x-forms.checkbox id="enable_backups" label="Enable weekly Hostinger backups" fullWidth />
                            <x-callout type="warning" title="This purchase is billed by Hostinger">
                                The VPS is purchased with your Hostinger account's default payment method. Review the
                                selected plan and billing period before continuing.
                            </x-callout>
                        </div>
                    </x-application.settings-section>
                </form>
            @endif
        </div>
    @endif
</div>
