<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Advanced | Coolify
    </x-slot>
    <livewire:server.navbar :server="$server" />
    <div x-data="{ activeTab: window.location.hash ? window.location.hash.substring(1) : 'general' }" class="flex flex-col h-full gap-8 sm:flex-row">
        <x-server.sidebar :server="$server" activeMenu="advanced" />
        <form wire:submit='submit' class="w-full flex flex-col gap-10">
            <div class="form-card">
                <div class="form-section-title">
                    <h2>Advanced</h2>
                    <div class="flex items-center gap-2">
                        <x-forms.button canGate="update" :canResource="$server" type="submit">Save</x-forms.button>
                    </div>
                </div>
                <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">Advanced configuration for your server.</p>
            </div>
            <div class="form-subsection">
                <h3>Disk Usage</h3>
                <div class="flex flex-col">
                    <div class="flex flex-wrap gap-10 sm:flex-nowrap">
                        <x-forms.input canGate="update" :canResource="$server" placeholder="0 23 * * *"
                            id="serverDiskUsageCheckFrequency" label="Disk usage check frequency" required
                            helper="Cron expression for disk usage check frequency.<br>You can use every_minute, hourly, daily, weekly, monthly, yearly.<br><br>Default is every night at 11:00 PM." />
                        <x-forms.input canGate="update" :canResource="$server" id="serverDiskUsageNotificationThreshold"
                            label="Server disk usage notification threshold (%)" required
                            helper="If the server disk usage exceeds this threshold, Coolify will send a notification to the team members." />
                    </div>
                </div>
            </div>
            <div class="form-subsection">
                <h3>Builds</h3>
                <div class="flex flex-wrap gap-10 sm:flex-nowrap">
                    <x-forms.input canGate="update" :canResource="$server" id="concurrentBuilds"
                        label="Number of concurrent builds" required
                        helper="You can specify the number of simultaneous build processes/deployments that should run concurrently." />
                    <x-forms.input canGate="update" :canResource="$server" id="dynamicTimeout"
                        label="Deployment timeout (seconds)" required
                        helper="You can define the maximum duration for a deployment to run before timing it out." />
                    <x-forms.input canGate="update" :canResource="$server" id="deploymentQueueLimit"
                        label="Deployment queue limit" required
                        helper="Maximum number of queued deployments allowed. New deployments will be rejected with a 429 status when the limit is reached." />
                </div>
            </div>
        </form>
    </div>
</div>
