<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Advanced | Coolify
    </x-slot>

    <livewire:server.navbar :server="$server" />

    <div
        class="server-settings-workspace application-settings-workspace mt-8 grid w-full max-w-[1180px] min-w-0 gap-8 xl:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-10">
        <x-server.sidebar :server="$server" activeMenu="advanced" />

        <form wire:submit="submit" class="application-settings-form flex w-full flex-col gap-6">
            <x-unsaved-bar action="submit" />

            <x-application.settings-section id="server-disk-usage-section" title="Disk usage"
                helper="Control when Coolify checks this server and when your team is notified.">
                <div class="grid gap-4 lg:grid-cols-2">
                    <x-forms.input canGate="update" :canResource="$server" placeholder="0 23 * * *"
                        id="serverDiskUsageCheckFrequency" label="Check frequency" required
                        helper="Cron expression or preset such as hourly, daily, weekly, monthly, or yearly." />
                    <x-forms.input canGate="update" :canResource="$server"
                        id="serverDiskUsageNotificationThreshold" type="number" min="1" max="99"
                        label="Notification threshold" required
                        helper="Notify the team when root filesystem usage exceeds this percentage." />
                </div>
            </x-application.settings-section>

            <x-application.settings-section id="server-builds-section" title="Builds"
                helper="Set deployment concurrency, execution timeouts, and queue capacity.">
                <div class="grid gap-4 lg:grid-cols-3">
                    <x-forms.input canGate="update" :canResource="$server" id="concurrentBuilds"
                        type="number" min="1" label="Concurrent builds" required
                        helper="Maximum deployments that can build at the same time." />
                    <x-forms.input canGate="update" :canResource="$server" id="dynamicTimeout"
                        type="number" min="1" label="Deployment timeout" required
                        helper="Maximum deployment duration in seconds." />
                    <x-forms.input canGate="update" :canResource="$server" id="deploymentQueueLimit"
                        type="number" min="1" label="Queue limit" required
                        helper="Maximum queued deployments before new requests are rejected." />
                </div>
            </x-application.settings-section>
        </form>
    </div>
</div>
