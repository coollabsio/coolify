<div class="w-full">
    @if ($limit_reached)
        <x-limit-reached name="servers" />
    @else
        @if ($current_step === 1)
            <div class="flex flex-col w-full gap-4">
                <div class="text-sm text-neutral-500 dark:text-neutral-400">
                    Don't have a DigitalOcean account? <a href="https://coolify.io/digitalocean" target="_blank"
                        class="underline dark:text-white">Sign up here</a>.
                    <span class="text-xs">Coolify's referral link - it supports both of us.</span>
                </div>
                @if ($available_tokens->count() > 0)
                    <div class="grid gap-3 md:grid-cols-2">
                        @foreach ($available_tokens as $token)
                            <a class="coolbox group text-left" wire:key="digital-ocean-token-{{ $token->id }}"
                                href="{{ route('server.create.token', ['type' => 'digital-ocean', 'token_uuid' => $token->uuid]) }}" {{ wireNavigate() }}>
                                <div class="flex flex-col justify-center mx-6">
                                    <div class="box-title">
                                        {{ $token->name ?? 'DigitalOcean Token' }}
                                    </div>
                                    <div class="box-description">
                                        Use this token to create a DigitalOcean Droplet.
                                    </div>
                                </div>
                            </a>
                        @endforeach
                    </div>
                @else
                    <div class="w-full max-w-2xl">
                            <x-modal-input title="Add DigitalOcean Token">
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
                                                    Add a DigitalOcean API token to create Droplets from your account.
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </x-slot:content>
                                <livewire:security.cloud-provider-token-form :modal_mode="true" provider="digitalocean"
                                    wire:key="new-server-empty-token-digitalocean" />
                            </x-modal-input>
                    </div>
                @endif
            </div>
        @elseif ($current_step === 2)
            <div wire:init="loadDigitalOceanData">
            @if ($loading_data)
                <div class="flex items-center justify-center py-8">
                    <x-loading text="Loading DigitalOcean data..." />
                </div>
            @elseif ($provider_data_error)
                <div class="flex flex-col gap-4 rounded-lg border border-error bg-error/10 p-4">
                    <div>
                        <h3>Unable to load DigitalOcean details</h3>
                        <p class="text-sm text-neutral-700 dark:text-neutral-300">
                            Coolify could not fetch DigitalOcean data with the selected token. The token may have been
                            deleted, revoked, or no longer has access.
                        </p>
                    </div>
                    <pre class="whitespace-pre-wrap break-words text-sm text-error">{{ $provider_data_error }}</pre>
                    <div>
                        <a class="button" href="{{ route('server.create.type', ['type' => 'digital-ocean']) }}" {{ wireNavigate() }}>
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
                                <option value="{{ $region['slug'] }}">
                                    {{ $region['name'] ?? $region['slug'] }}
                                </option>
                            @endforeach
                        </x-forms.select>
                    </div>

                    <div>
                        <x-forms.select label="Size" id="selected_size" wire:model.live="selected_size" required
                            :disabled="!$selected_region">
                            <option value="">
                                {{ $selected_region ? 'Select a size...' : 'Select a region first' }}
                            </option>
                            @foreach ($this->availableSizes as $size)
                                <option value="{{ $size['slug'] }}">
                                    {{ $size['slug'] }} - {{ $size['memory'] ?? '?' }}MB RAM,
                                    {{ $size['vcpus'] ?? '?' }} vCPU
                                    @if (isset($size['disk']))
                                        , {{ $size['disk'] }}GB
                                    @endif
                                    @if (isset($size['price_monthly']))
                                        - ${{ number_format((float) $size['price_monthly'], 2) }}/mo
                                    @endif
                                </option>
                            @endforeach
                        </x-forms.select>
                    </div>

                    <div>
                        <x-forms.select label="Image" id="selected_image" required :disabled="!$selected_size">
                            <option value="">
                                {{ $selected_size ? 'Select an image...' : 'Select a size first' }}
                            </option>
                            @foreach ($this->availableImages as $image)
                                <option value="{{ $image['slug'] ?? $image['id'] }}">
                                    {{ trim(($image['distribution'] ?? '') . ' ' . ($image['name'] ?? $image['slug'] ?? $image['id'])) }}
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
                                This SSH key will be automatically added to your DigitalOcean account and used to access
                                the server.
                            </p>
                        @endif
                    </div>

                    <x-dropdown inline panelClass="max-h-[55vh] overflow-y-auto scrollbar">
                        <x-slot:title>
                            <span class="text-sm font-medium">Advanced DigitalOcean options</span>
                            <span class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">SSH keys, networking, monitoring, and cloud-init.</span>
                            @if (count($this->advancedDigitalOceanOptionsSummary) > 0)
                                <span class="mt-1 flex flex-wrap gap-1.5">
                                    @foreach ($this->advancedDigitalOceanOptionsSummary as $summaryItem)
                                        <span class="rounded bg-neutral-200 px-2 py-0.5 text-xs text-neutral-700 dark:bg-coolgray-100 dark:text-neutral-300">
                                            {{ $summaryItem }}
                                        </span>
                                    @endforeach
                                </span>
                            @endif
                        </x-slot>

                        <div class="flex w-full flex-col gap-4 p-3">
                            <div>
                                <x-forms.datalist label="Extra SSH Keys" id="selectedDigitalOceanSshKeyIds"
                                    helper="Select existing SSH keys from your DigitalOcean account to add to this Droplet. The Coolify SSH key will be automatically added."
                                    :multiple="true" :disabled="count($digitalOceanSshKeys) === 0" :placeholder="count($digitalOceanSshKeys) > 0
                                        ? 'Search and select SSH keys...'
                                        : 'No SSH keys found in DigitalOcean account'">
                                    @foreach ($digitalOceanSshKeys as $sshKey)
                                        <option value="{{ $sshKey['id'] }}">
                                            {{ $sshKey['name'] ?? $sshKey['fingerprint'] }}
                                            @if (isset($sshKey['fingerprint']))
                                                - {{ substr($sshKey['fingerprint'], 0, 20) }}...
                                            @endif
                                        </option>
                                    @endforeach
                                </x-forms.datalist>
                            </div>

                            <div class="grid grid-cols-1 gap-2 md:grid-cols-2">
                                <x-forms.checkbox id="enable_ipv6" label="Enable IPv6"
                                    helper="Enable public IPv6 address for this Droplet" fullWidth />
                                <x-forms.checkbox id="monitoring" label="Enable DigitalOcean Monitoring" fullWidth
                                    helper="Enable DigitalOcean metrics collection for this Droplet." />
                            </div>

                            <div class="flex flex-col gap-2">
                                @if (! $show_cloud_init_script && empty($cloud_init_script) && empty($selected_cloud_init_script_id))
                                    <div>
                                        <x-forms.button type="button" wire:click="showCloudInitScript">
                                            Add cloud-init script
                                        </x-forms.button>
                                    </div>
                                @else
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
                                        @else
                                            <x-forms.button type="button" wire:click="clearCloudInitScript">
                                                Remove
                                            </x-forms.button>
                                        @endif
                                    </div>
                                    <x-forms.textarea id="cloud_init_script" label=""
                                        helper="Add a cloud-init script to run when the Droplet is created. See DigitalOcean's cloud-init documentation for details."
                                        rows="8" />

                                    <div class="flex items-center gap-2">
                                        <x-forms.checkbox id="save_cloud_init_script" label="Save this script for later use" />
                                        @if ($save_cloud_init_script)
                                            <div class="flex-1">
                                                <x-forms.input id="cloud_init_script_name" label="" placeholder="Script name..." />
                                            </div>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        </div>
                    </x-dropdown>

                    <x-forms.button class="w-full" isHighlighted canGate="create" :canResource="App\Models\Server::class"
                        type="submit" :disabled="!$private_key_id">
                        Buy & Create Server{{ $this->selectedDropletPrice ? ' (' . $this->selectedDropletPrice . '/mo)' : '' }}
                    </x-forms.button>
                </form>
            @endif
            </div>
        @endif
    @endif
</div>
