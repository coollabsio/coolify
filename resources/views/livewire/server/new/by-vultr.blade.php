<div class="w-full">
    @if ($limit_reached)
        <x-limit-reached name="servers" />
    @else
        @if ($current_step === 1)
            <div class="flex flex-col w-full gap-4">
                <div class="text-sm text-neutral-500 dark:text-neutral-400">
                    Don't have a Vultr account? <a href="https://coolify.io/vultr" target="_blank"
                        class="underline dark:text-white">Sign up here</a>.
                    <span class="text-xs">Coolify's affiliate link - it supports both of us.</span>
                </div>
                @if ($available_tokens->count() > 0)
                    <div class="grid gap-3 md:grid-cols-2">
                        @foreach ($available_tokens as $token)
                            <a class="coolbox group text-left" wire:key="vultr-token-{{ $token->id }}"
                                href="{{ route('server.create.token', ['type' => 'vultr', 'token_uuid' => $token->uuid]) }}" {{ wireNavigate() }}>
                                <div class="flex flex-col justify-center mx-6">
                                    <div class="box-title">
                                        {{ $token->name ?? 'Vultr Token' }}
                                    </div>
                                    <div class="box-description">
                                        Use this token to create a Vultr server.
                                    </div>
                                </div>
                            </a>
                        @endforeach
                    </div>
                @else
                    <div class="w-full max-w-2xl">
                            <x-modal-input title="Add Vultr Token">
                                <x-slot:content>
                                    <div class="coolbox group cursor-pointer">
                                        <div class="flex items-center gap-4 mx-6">
                                            <div
                                                class="flex size-10 shrink-0 items-center justify-center rounded-full bg-coollabs/10 text-coollabs dark:bg-warning/20 dark:text-warning">
                                                <svg class="size-6" xmlns="http://www.w3.org/2000/svg" fill="none"
                                                    viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round"
                                                        d="M12 4.5v15m7.5-7.5h-15" />
                                                </svg>
                                            </div>
                                            <div class="flex flex-col justify-center">
                                                <div class="box-title">Add a new token</div>
                                                <div class="box-description">
                                                    Add a Vultr API token to create servers from your account.
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </x-slot:content>
                                <livewire:security.cloud-provider-token-form :modal_mode="true" provider="vultr"
                                    wire:key="new-server-empty-token-vultr" />
                            </x-modal-input>
                    </div>
                @endif
            </div>
        @elseif ($current_step === 2)
            <div wire:init="loadVultrData">
            @if ($loading_data)
                <div class="flex items-center justify-center py-8">
                    <x-loading text="Loading Vultr data..." />
                </div>
            @elseif ($provider_data_error)
                <div class="flex flex-col gap-4 rounded-lg border border-error bg-error/10 p-4">
                    <div>
                        <h3>Unable to load Vultr details</h3>
                        <p class="text-sm text-neutral-700 dark:text-neutral-300">
                            Coolify could not fetch Vultr data with the selected token. The token may have been
                            deleted, revoked, or no longer has access.
                        </p>
                    </div>
                    <pre class="whitespace-pre-wrap break-words text-sm text-error">{{ $provider_data_error }}</pre>
                    <div>
                        <a class="button" href="{{ route('server.create.type', ['type' => 'vultr']) }}" {{ wireNavigate() }}>
                            Select another token
                        </a>
                    </div>
                </div>
            @else
                <form class="flex flex-col w-full gap-2" wire:submit='submit'>
                    <div>
                        <x-forms.input id="server_name" label="Server Name" helper="A friendly name for your server." />
                    </div>

                    <div>
                        <x-forms.select label="Region" id="selected_region" wire:model.live="selected_region" required>
                            <option value="">Select a region...</option>
                            @foreach ($regions as $region)
                                <option value="{{ $region['id'] }}">
                                    {{ $region['city'] ?? $region['id'] }} - {{ $region['country'] ?? $region['id'] }}
                                </option>
                            @endforeach
                        </x-forms.select>
                    </div>

                    <div>
                        <x-forms.select label="Plan" id="selected_plan" wire:model.live="selected_plan"
                            helper="Learn more about <a class='inline-block underline dark:text-white' href='https://www.vultr.com/products/cloud-compute/' target='_blank'>Vultr plans</a>"
                            required :disabled="!$selected_region">
                            <option value="">
                                {{ $selected_region ? 'Select a plan...' : 'Select a region first' }}
                            </option>
                            @foreach ($this->availablePlans as $plan)
                                <option value="{{ $plan['id'] }}">
                                    {{ $plan['id'] }} -
                                    {{ $plan['vcpu_count'] ?? '?' }} vCPU,
                                    {{ isset($plan['ram']) ? number_format($plan['ram'] / 1024, 1) : '?' }}GB RAM,
                                    {{ $plan['disk'] ?? '?' }}GB
                                    @if (isset($plan['monthly_cost']))
                                        - ${{ number_format((float) $plan['monthly_cost'], 2) }}/mo
                                    @endif
                                </option>
                            @endforeach
                        </x-forms.select>
                    </div>

                    <div>
                        <x-forms.select label="Operating System" id="selected_os_id" required>
                            <option value="">Select an operating system...</option>
                            @foreach ($operatingSystems as $operatingSystem)
                                <option value="{{ $operatingSystem['id'] }}">
                                    {{ $operatingSystem['name'] }}
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
                                    <x-modal-input buttonTitle="Create New Private Key" title="New Private Key"
                                        isHighlightedButton>
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
                                This SSH key will be automatically added to your Vultr account and used to access the
                                server.
                            </p>
                        @endif
                    </div>

                    <x-dropdown inline panelClass="max-h-[55vh] overflow-y-auto scrollbar">
                        <x-slot:title>
                            <span class="text-sm font-medium">Advanced Vultr options</span>
                            <span class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">SSH keys, network configuration, and cloud-init.</span>
                            @if (count($this->advancedVultrOptionsSummary) > 0)
                                <span class="mt-1 flex flex-wrap gap-1.5">
                                    @foreach ($this->advancedVultrOptionsSummary as $summaryItem)
                                        <span class="rounded bg-neutral-200 px-2 py-0.5 text-xs text-neutral-700 dark:bg-coolgray-100 dark:text-neutral-300">
                                            {{ $summaryItem }}
                                        </span>
                                    @endforeach
                                </span>
                            @endif
                        </x-slot>

                        <div class="flex w-full flex-col gap-4 p-3">
                            <div>
                                <x-forms.datalist label="Additional SSH Keys (from Vultr)" id="selectedVultrSshKeyIds"
                                    helper="Select existing SSH keys from your Vultr account to add to this server. The Coolify SSH key will be automatically added."
                                    :multiple="true" :disabled="count($vultrSshKeys) === 0" :placeholder="count($vultrSshKeys) > 0 ? 'Search and select SSH keys...' : 'No SSH keys found in Vultr account'">
                                    @foreach ($vultrSshKeys as $sshKey)
                                        <option value="{{ $sshKey['id'] }}">
                                            {{ $sshKey['name'] }} - {{ substr($sshKey['id'], 0, 20) }}
                                        </option>
                                    @endforeach
                                </x-forms.datalist>
                            </div>

                            <div class="flex flex-col gap-2">
                                <label class="text-sm font-medium">Network Configuration</label>
                                <div class="grid grid-cols-1 gap-2 md:grid-cols-2">
                                    <x-forms.checkbox id="enable_ipv6" label="Enable IPv6"
                                        helper="Enable public IPv6 address for this server" fullWidth />
                                    <x-forms.checkbox id="disable_public_ipv4" label="Disable public IPv4"
                                        helper="Disable the default public IPv4 address" fullWidth />
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
                                    helper="Add a cloud-init script to run when the server is created. Coolify sends it to Vultr as user data."
                                    rows="8" />

                                <div class="flex items-center gap-2">
                                    <x-forms.checkbox id="save_cloud_init_script" label="Save this script for later use" />
                                    <div class="flex-1">
                                        <x-forms.input id="cloud_init_script_name" label="" placeholder="Script name..." />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </x-dropdown>

                    <x-forms.button class="w-full" isHighlighted canGate="create" :canResource="App\Models\Server::class"
                        type="submit" :disabled="!$private_key_id">
                        Buy & Create Server{{ $this->selectedServerPrice ? ' (' . $this->selectedServerPrice . '/mo)' : '' }}
                    </x-forms.button>
                </form>
            @endif
            </div>
        @endif
    @endif
</div>
