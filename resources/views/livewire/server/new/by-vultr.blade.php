<div class="w-full">
    @if ($limit_reached)
        <x-limit-reached name="servers" />
    @elseif ($current_step === 1)
        <x-server.provider-token-picker provider="vultr" providerLabel="Vultr"
            :tokens="$available_tokens" />
    @elseif ($current_step === 2)
        <div wire:init="loadVultrData">
            @if ($loading_data)
                <x-application.settings-section title="Loading Vultr"
                    description="Fetching regions, plans, operating systems, and account resources.">
                    <div class="flex min-h-40 items-center justify-center">
                        <x-loading text="Loading Vultr data..." />
                    </div>
                </x-application.settings-section>
            @elseif ($provider_data_error)
                <x-application.settings-section title="Unable to load Vultr"
                    description="The selected token could not access the provider API.">
                    <x-callout type="error" title="Provider request failed">
                        <pre class="mt-2 whitespace-pre-wrap break-words text-[11px]">{{ $provider_data_error }}</pre>
                    </x-callout>
                    <div class="mt-4">
                        <a class="button" href="{{ route('server.create.type', ['type' => 'vultr']) }}"
                            {{ wireNavigate() }}>Select another token</a>
                    </div>
                </x-application.settings-section>
            @else
                @php
                    $regionOptions = collect($regions)->map(fn ($region) => [
                        'value' => $region['id'],
                        'label' => ($region['city'] ?? $region['id']) . ' · ' . ($region['country'] ?? $region['id']),
                    ])->values()->all();
                    $planOptions = collect($this->availablePlans)->map(function ($plan) {
                        $label = $plan['id']
                            . ' · ' . ($plan['vcpu_count'] ?? '?') . ' vCPU'
                            . ' · ' . (isset($plan['ram']) ? number_format($plan['ram'] / 1024, 1) : '?') . ' GB RAM'
                            . ' · ' . ($plan['disk'] ?? '?') . ' GB';
                        return [
                            'value' => $plan['id'],
                            'label' => isset($plan['monthly_cost'])
                                ? $label . ' · $' . number_format((float) $plan['monthly_cost'], 2) . '/mo'
                                : $label,
                        ];
                    })->values()->all();
                    $osOptions = collect($operatingSystems)->map(fn ($os) => [
                        'value' => $os['id'],
                        'label' => $os['name'],
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
                    <x-application.settings-section title="Vultr server"
                        description="Choose the region, plan, operating system, and Coolify SSH key.">
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
                            <x-forms.listbox id="selected_region" label="Region" required live
                                placeholder="Select a region" :options="$regionOptions" />
                            <x-forms.listbox id="selected_plan" label="Plan" required live
                                :disabled="!$selected_region" placeholder="Select a plan"
                                :options="$planOptions" />
                            <x-forms.listbox id="selected_os_id" label="Operating system" required
                                placeholder="Select an operating system" :options="$osOptions" />
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
                                    helper="This key is added to the Vultr server automatically." />
                            @endif
                        </div>
                    </x-application.settings-section>

                    <x-application.settings-section title="Advanced options"
                        description="Provider SSH keys, networking, and cloud-init.">
                        @if (count($this->advancedVultrOptionsSummary) > 0)
                            <div class="mb-4 flex flex-wrap gap-1.5">
                                @foreach ($this->advancedVultrOptionsSummary as $summaryItem)
                                    <span
                                        class="rounded-full bg-neutral-100 px-2 py-0.5 text-[10px] font-medium text-neutral-600 dark:bg-white/[0.06] dark:text-fg-dim">
                                        {{ $summaryItem }}
                                    </span>
                                @endforeach
                            </div>
                        @endif

                        <div class="flex flex-col gap-4">
                            <x-forms.datalist label="Extra SSH keys" id="selectedVultrSshKeyIds"
                                helper="Existing keys from the Vultr account." :multiple="true"
                                :disabled="count($vultrSshKeys) === 0"
                                :placeholder="count($vultrSshKeys) ? 'Search SSH keys' : 'No account keys found'">
                                @foreach ($vultrSshKeys as $sshKey)
                                    <option value="{{ $sshKey['id'] }}">{{ $sshKey['name'] }}</option>
                                @endforeach
                            </x-forms.datalist>

                            <div class="grid gap-3 lg:grid-cols-2">
                                <x-forms.checkbox id="enable_ipv6" label="Enable IPv6" fullWidth />
                                <x-forms.checkbox id="disable_public_ipv4" label="Disable public IPv4" fullWidth />
                            </div>

                            <div class="border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
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
                            </div>
                        </div>
                    </x-application.settings-section>
                </form>
            @endif
        </div>
    @endif
</div>
