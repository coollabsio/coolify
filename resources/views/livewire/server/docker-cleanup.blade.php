<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Docker Cleanup | Coolify
    </x-slot>
    <livewire:server.navbar :server="$server" />
    <div x-data="{ activeTab: window.location.hash ? window.location.hash.substring(1) : 'general' }" class="flex flex-col h-full gap-8 sm:flex-row">
        <x-server.sidebar :server="$server" activeMenu="docker-cleanup" />
        <div class="w-full">
            <form wire:submit='submit'>
                <div>
                    <div class="flex items-center gap-2">
                        <h2>Docker Cleanup</h2>
                        <x-forms.button type="submit" canGate="update" :canResource="$server">Save</x-forms.button>
                        @can('update', $server)
                            <x-modal-confirmation title="Confirm Docker Cleanup?" buttonTitle="Trigger Manual Cleanup"
                                isHighlightedButton submitAction="manualCleanup" :actions="[
                                    'Permanently deletes all stopped containers managed by Coolify (as containers are non-persistent, no data will be lost)',
                                    'Permanently deletes all unused images',
                                    'Clears build cache',
                                    'Removes old versions of the Coolify helper image',
                                    'Optionally permanently deletes all unused volumes (if enabled in advanced options).',
                                    'Optionally permanently deletes all unused networks (if enabled in advanced options).',
                                ]" :confirmWithText="false"
                                :confirmWithPassword="false" step2ButtonText="Trigger Docker Cleanup" />
                        @endcan
                    </div>
                    <div class="mt-1 mb-6">Configure Docker cleanup settings for your server.</div>
                </div>

                <div class="flex flex-col gap-2">
                    <div class="flex gap-4">
                        <h3>Cleanup Configuration</h3>
                    </div>
                    <div class="flex items-center gap-4">
                        <x-forms.input canGate="update" :canResource="$server" placeholder="*/10 * * * *"
                            id="dockerCleanupFrequency" label="Docker cleanup frequency" required
                            helper="Cron expression for Docker Cleanup.<br>You can use every_minute, hourly, daily, weekly, monthly, yearly.<br><br>Default is every night at midnight." />
                        @if (!$forceDockerCleanup)
                            <x-forms.input canGate="update" :canResource="$server" id="dockerCleanupThreshold"
                                label="Docker cleanup threshold (%)" required
                                helper="The Docker cleanup tasks will run when the disk usage exceeds this threshold." />
                        @endif
                    </div>
                    <div class="w-full sm:w-96">
                        <x-forms.checkbox canGate="update" :canResource="$server"
                            helper="Enabling Force Docker Cleanup or manually triggering a cleanup will perform the following actions:
                            <ul class='list-disc pl-4 mt-2'>
                                <li>Removes stopped containers managed by Coolify (as containers are non-persistent, no data will be lost).</li>
                                <li>Deletes unused images.</li>
                                <li>Clears build cache.</li>
                                <li>Removes old versions of the Coolify helper image.</li>
                                <li>Optionally delete unused volumes (if enabled in advanced options).</li>
                                <li>Optionally remove unused networks (if enabled in advanced options).</li>
                            </ul>"
                            instantSave id="forceDockerCleanup" label="Force Docker Cleanup" />
                    </div>

                </div>

                <div class="flex flex-col gap-2 mt-6">
                    <h3>Advanced</h3>
                    <x-callout type="warning" title="Caution">
                        <p>These options can cause permanent data loss and functional issues. Only enable if you fully
                            understand the consequences.</p>
                    </x-callout>
                    <div class="w-full sm:w-96">
                        <x-forms.checkbox canGate="update" :canResource="$server" instantSave id="deleteUnusedVolumes"
                            label="Delete Unused Volumes"
                            helper="This option will remove all unused Docker volumes during cleanup.<br><br><strong>Warning: Data from stopped containers will be lost!</strong><br><br>Consequences include:<br>
                            <ul class='list-disc pl-4 mt-2'>
                                <li>Volumes not attached to running containers will be permanently deleted (volumes from stopped containers are affected).</li>
                                <li>Data stored in deleted volumes cannot be recovered.</li>
                            </ul>" />
                        <x-forms.checkbox canGate="update" :canResource="$server" instantSave id="deleteUnusedNetworks"
                            label="Delete Unused Networks"
                            helper="This option will remove all unused Docker networks during cleanup.<br><br><strong>Warning: Functionality may be lost and containers may not be able to communicate with each other!</strong><br><br>Consequences include:<br>
                            <ul class='list-disc pl-4 mt-2'>
                                <li>Networks not attached to running containers will be permanently deleted (networks used by stopped containers are affected).</li>
                                <li>Containers may lose connectivity if required networks are removed.</li>
                            </ul>" />
                    </div>
                </div>
            </form>

            <div class="mt-8">
                <h3 class="mb-4">Recent executions <span class="text-xs text-neutral-500">(click to check
                        output)</span></h3>
                <livewire:server.docker-cleanup-executions :server="$server" />
            </div>
        </div>
    </div>
</div>
