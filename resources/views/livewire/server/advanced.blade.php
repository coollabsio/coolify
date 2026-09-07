<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Advanced | Coolify
    </x-slot>
    <livewire:server.navbar :server="$server" />
    <div x-data="{ activeTab: window.location.hash ? window.location.hash.substring(1) : 'general' }" class="flex flex-col h-full gap-8 sm:flex-row">
        <x-server.sidebar :server="$server" activeMenu="advanced" />
        <form wire:submit='submit' class="w-full">
            <div>
                <div class="flex items-center gap-2">
                    <h2>Advanced</h2>
                    <x-forms.button canGate="update" :canResource="$server" type="submit">Save</x-forms.button>
                </div>
                <div class="mb-4">Advanced configuration for your server.</div>
            </div>

            <h3>Disk Usage</h3>
            <div class="flex flex-col gap-6">
                <div class="flex flex-col">
                    <div class="flex flex-wrap gap-2 sm:flex-nowrap pt-4">
                        <x-forms.input canGate="update" :canResource="$server" placeholder="0 23 * * *"
                            id="serverDiskUsageCheckFrequency" label="Disk usage check frequency" required
                            helper="Cron expression for disk usage check frequency.<br>You can use every_minute, hourly, daily, weekly, monthly, yearly.<br><br>Default is every night at 11:00 PM." />
                        <x-forms.input canGate="update" :canResource="$server" id="serverDiskUsageNotificationThreshold"
                            type="number" min="1" max="99"
                            label="Server disk usage notification threshold (%)" required
                            helper="If the server disk usage exceeds this threshold, Coolify will send a notification to the team members." />
                    </div>
                </div>

                <div class="flex flex-col">
                    <h3>Builds</h3>
                    <div class="flex flex-wrap gap-2 sm:flex-nowrap pt-4">
                        <x-forms.input canGate="update" :canResource="$server" id="concurrentBuilds"
                            type="number" min="1"
                            label="Number of concurrent builds" required
                            helper="You can specify the number of simultaneous build processes/deployments that should run concurrently." />
                        <x-forms.input canGate="update" :canResource="$server" id="dynamicTimeout"
                            type="number" min="1"
                            label="Deployment timeout (seconds)" required
                            helper="You can define the maximum duration for a deployment to run before timing it out." />
                        <x-forms.input canGate="update" :canResource="$server" id="deploymentQueueLimit"
                            type="number" min="1"
                            label="Deployment queue limit" required
                            helper="Maximum number of queued deployments allowed. New deployments will be rejected with a 429 status when the limit is reached." />
                    </div>
                </div>

                <div class="flex flex-col">
                    <div class="flex items-center gap-2">
                        <h3>Cloudflare DNS</h3>
                        <x-forms.button canGate="update" :canResource="$server" type="button"
                            wire:click.prevent="checkCloudflareDns">
                            Check DNS now
                        </x-forms.button>
                    </div>
                    <div class="pb-4 text-sm text-neutral-500 dark:text-neutral-400">
                        Let Coolify create or update A/AAAA records for application domains on this server.
                    </div>
                    <div class="flex flex-col gap-2">
                        <x-forms.checkbox canGate="update" :canResource="$server" id="cloudflareDnsEnabled"
                            label="Enable Cloudflare DNS management" />
                        <div class="flex flex-wrap gap-2 sm:flex-nowrap">
                            <x-forms.select canGate="update" :canResource="$server" id="cloudflareDnsTokenId"
                                label="Cloudflare Integration Token"
                                helper="Create a Cloudflare token with Zone Read and DNS Write permissions.">
                                <option value="">Select a token</option>
                                @foreach ($cloudflareTokens as $token)
                                    <option value="{{ $token->id }}">{{ $token->name }}</option>
                                @endforeach
                            </x-forms.select>
                        </div>
                        <x-forms.checkbox canGate="update" :canResource="$server" id="cloudflareDnsProxied"
                            label="Enable Cloudflare proxy (orange cloud)"
                            helper="Off by default. When enabled, Cloudflare will proxy supported DNS records." />
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>
