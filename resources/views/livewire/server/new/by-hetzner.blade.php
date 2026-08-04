<div class="w-full">
    @if ($limit_reached)
        <x-limit-reached name="servers" />
    @elseif ($current_step === 1)
        <div class="flex flex-col gap-6">
            <x-server.provider-token-picker provider="hetzner" providerLabel="Hetzner"
                :tokens="$available_tokens" />
            <p class="text-[11px] text-neutral-500 dark:text-fg-faint">
                New to Hetzner?
                <a href="https://coolify.io/hetzner" target="_blank"
                    class="font-medium text-coollabs hover:underline dark:text-warning">Create an account</a>
                through Coolify's affiliate link.
            </p>
        </div>
    @elseif ($current_step === 2)
        <div wire:init="loadHetznerData">
            @if ($loading_data)
                <x-application.settings-section title="Loading Hetzner"
                    description="Fetching locations, server types, images, and account resources.">
                    <div class="flex min-h-40 items-center justify-center">
                        <x-loading text="Loading Hetzner data..." />
                    </div>
                </x-application.settings-section>
            @elseif ($provider_data_error)
                <x-application.settings-section title="Unable to load Hetzner"
                    description="The selected token could not access the provider API.">
                    <x-callout type="error" title="Provider request failed">
                        <pre class="mt-2 whitespace-pre-wrap break-words text-[11px]">{{ $provider_data_error }}</pre>
                    </x-callout>
                    <div class="mt-4">
                        <a class="button" href="{{ route('server.create.type', ['type' => 'hetzner']) }}"
                            {{ wireNavigate() }}>Select another token</a>
                    </div>
                </x-application.settings-section>
            @else
                @php
                    $locationOptions = collect($locations)->map(fn ($location) => [
                        'value' => $location['name'],
                        'label' => $location['city'] . ' · ' . $location['country'],
                    ])->values()->all();
                    $serverTypeOptions = collect($this->availableServerTypes)->map(function ($type) {
                        $price = data_get($type, 'prices.0.price_monthly.gross');
                        $label = ($type['description'] ?? $type['name'])
                            . ' · ' . ($type['cores'] ?? '?') . ' vCPU'
                            . ' · ' . ($type['memory'] ?? '?') . ' GB RAM'
                            . ' · ' . ($type['disk'] ?? '?') . ' GB';
                        return [
                            'value' => $type['name'],
                            'label' => $price ? $label . ' · €' . number_format($price, 2) . '/mo' : $label,
                        ];
                    })->values()->all();
                    $imageOptions = collect($this->availableImages)->map(fn ($image) => [
                        'value' => $image['id'],
                        'label' => ($image['description'] ?? $image['name'])
                            . (isset($image['architecture']) ? ' · ' . $image['architecture'] : ''),
                    ])->values()->all();
                    $privateKeyOptions = $private_keys->map(fn ($key) => [
                        'value' => $key->id,
                        'label' => $key->name,
                    ])->values()->all();
                    $scriptOptions = collect([
                        ['value' => '', 'label' => 'Start with an empty script'],
                        ...$saved_cloud_init_scripts->map(fn ($script) => [
                            'value' => $script->id,
                            'label' => $script->name,
                        ])->all(),
                    ])->all();
                @endphp

                <form wire:submit="submit" class="flex flex-col gap-6">
                    <x-application.settings-section title="Hetzner server"
                        description="Choose the location, hardware, operating system, and Coolify SSH key.">
                        <x-slot:actions>
                            <button type="submit"
                                class="button bg-coollabs/10! text-coollabs! ring-1 ring-coollabs/25 hover:bg-coollabs/15! dark:bg-warning/15! dark:text-warning! dark:ring-warning/25 dark:hover:bg-warning/20!"
                                @disabled(!$private_key_id)>
                                Buy and create
                                @if ($this->selectedServerPrice)
                                    <span class="opacity-70">· {{ $this->selectedServerPrice }}/mo</span>
                                @endif
                            </button>
                        </x-slot:actions>

                        <div class="grid gap-4 lg:grid-cols-2">
                            <div class="lg:col-span-2">
                                <x-forms.input id="server_name" label="Server name"
                                    helper="A friendly name shown in Coolify." />
                            </div>
                            <x-forms.listbox id="selected_location" label="Location" required live
                                placeholder="Select a location" :options="$locationOptions" />
                            <x-forms.listbox id="selected_server_type" label="Server type" required live
                                :disabled="!$selected_location" placeholder="Select a server type"
                                :options="$serverTypeOptions" />
                            <x-forms.listbox id="selected_image" label="Image" required
                                :disabled="!$selected_server_type" placeholder="Select an image"
                                :options="$imageOptions" />
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
                                    helper="This key is added to the Hetzner server automatically." />
                            @endif
                        </div>
                    </x-application.settings-section>

                    <x-application.settings-section title="Advanced options"
                        description="Provider SSH keys, networking, backups, and cloud-init.">
                        @if (count($this->advancedHetznerOptionsSummary) > 0)
                            <div class="mb-4 flex flex-wrap gap-1.5">
                                @foreach ($this->advancedHetznerOptionsSummary as $summaryItem)
                                    <span
                                        class="rounded-full bg-neutral-100 px-2 py-0.5 text-[10px] font-medium text-neutral-600 dark:bg-white/[0.06] dark:text-fg-dim">
                                        {{ $summaryItem }}
                                    </span>
                                @endforeach
                            </div>
                        @endif

                        <div class="flex flex-col gap-4">
                            <x-forms.datalist label="Extra SSH keys" id="selectedHetznerSshKeyIds"
                                helper="Existing keys from the Hetzner account." :multiple="true"
                                :disabled="count($hetznerSshKeys) === 0"
                                :placeholder="count($hetznerSshKeys) ? 'Search SSH keys' : 'No account keys found'">
                                @foreach ($hetznerSshKeys as $sshKey)
                                    <option value="{{ $sshKey['id'] }}">{{ $sshKey['name'] }}</option>
                                @endforeach
                            </x-forms.datalist>

                            <div class="grid gap-4 lg:grid-cols-2">
                                <x-forms.datalist label="Firewalls" id="selectedHetznerFirewallIds"
                                    :multiple="true" :disabled="count($hetznerFirewalls) === 0"
                                    :placeholder="count($hetznerFirewalls) ? 'Search firewalls' : 'No firewalls found'">
                                    @foreach ($hetznerFirewalls as $firewall)
                                        <option value="{{ $firewall['id'] }}">{{ $firewall['name'] }}</option>
                                    @endforeach
                                </x-forms.datalist>
                                <x-forms.datalist label="Private networks" id="selectedHetznerNetworkIds"
                                    :multiple="true" :disabled="count($this->availableNetworks) === 0"
                                    :placeholder="count($this->availableNetworks) ? 'Search networks' : 'No compatible networks'">
                                    @foreach ($this->availableNetworks as $network)
                                        <option value="{{ $network['id'] }}">
                                            {{ $network['name'] }} · {{ $network['ip_range'] }}
                                        </option>
                                    @endforeach
                                </x-forms.datalist>
                            </div>

                            <div class="grid gap-3 lg:grid-cols-3">
                                <x-forms.checkbox id="enable_ipv4" label="Enable IPv4" fullWidth />
                                <x-forms.checkbox id="enable_ipv6" label="Enable IPv6" fullWidth />
                                <x-forms.checkbox id="enable_backups" label="Enable Hetzner backups" fullWidth
                                    helper="Adds 20% to the provider server price." />
                            </div>

                            <div class="border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
                                @if (!$show_cloud_init_script && blank($cloud_init_script) && blank($selected_cloud_init_script_id))
                                    <button type="button" class="button" wire:click="showCloudInitScript">
                                        <x-reicon name="plus" class="size-3.5" />
                                        Add cloud-init script
                                    </button>
                                @else
                                    <div class="flex flex-col gap-4">
                                        <div class="grid items-end gap-3 lg:grid-cols-[minmax(0,1fr)_auto]">
                                            <x-forms.listbox id="selected_cloud_init_script_id"
                                                label="Saved cloud-init script" live :options="$scriptOptions" />
                                            <button type="button" class="button"
                                                wire:click="clearCloudInitScript">Clear</button>
                                        </div>
                                        <x-forms.textarea id="cloud_init_script" label="Cloud-init script"
                                            rows="8" monospace />
                                        <div class="grid items-end gap-4 lg:grid-cols-2">
                                            <x-forms.checkbox id="save_cloud_init_script"
                                                label="Save this script for later" />
                                            @if ($save_cloud_init_script)
                                                <x-forms.input id="cloud_init_script_name" label="Saved script name" />
                                            @endif
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </x-application.settings-section>
                </form>
            @endif
        </div>
    @endif
</div>
