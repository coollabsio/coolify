<form wire:submit='submit'>
    <div>
        <div class="flex items-center gap-2">
            <h2>Advanced</h2>
            <x-forms.button type="submit">Save</x-forms.button>
            <x-modal-confirmation title="Confirm Docker Cleanup?" buttonTitle="Trigger Manual Cleanup"
                submitAction="manualCleanup" :actions="[
                    'Permanently deletes all stopped containers managed by Coolify (as containers are non-persistent, no data will be lost)',
                    'Permanently deletes all unused images',
                    'Clears build cache',
                    'Removes old versions of the Coolify helper image',
                    'Optionally permanently deletes all unused volumes (if enabled in advanced options).',
                    'Optionally permanently deletes all unused networks (if enabled in advanced options).',
                ]" :confirmWithText="false" :confirmWithPassword="false"
                step2ButtonText="Trigger Docker Cleanup" />
        </div>
        <div>Advanced configuration for your server.</div>
    </div>
    <div class="flex flex-col gap-4 pt-4">
        <div class="flex flex-col gap-2">
            <div class="flex items-center gap-2">
                <h3>Docker Cleanup</h3>

            </div>
            <div class="flex flex-wrap items-center gap-4">
                @if ($server->settings->force_docker_cleanup)
                    <x-forms.input placeholder="*/10 * * * *" id="server.settings.docker_cleanup_frequency"
                        label="Docker cleanup frequency" required
                        helper="Cron expression for Docker Cleanup.<br>You can use every_minute, hourly, daily, weekly, monthly, yearly.<br><br>Default is every night at midnight." />
                @else
                    <x-forms.input id="server.settings.docker_cleanup_threshold" label="Docker cleanup threshold (%)"
                        required
                        helper="The Docker cleanup tasks will run when the disk usage exceeds this threshold." />
                @endif
                <div class="w-96">
                    <x-forms.checkbox
                        helper="Enabling Force Docker Cleanup or manually triggering a cleanup will perform the following actions:
                        <ul class='list-disc pl-4 mt-2'>
                            <li>Removes stopped containers manged by Coolify (as containers are none persistent, no data will be lost).</li>
                            <li>Deletes unused images.</li>
                            <li>Clears build cache.</li>
                            <li>Removes old versions of the Coolify helper image.</li>
                            <li>Optionally delete unused volumes (if enabled in advanced options).</li>
                            <li>Optionally remove unused networks (if enabled in advanced options).</li>
                        </ul>"
                        instantSave id="server.settings.force_docker_cleanup" label="Force Docker Cleanup" />
                </div>

            </div>
            <p class="text-sm text-gray-600 dark:text-gray-400 mb-2">
                <span class="dark:text-warning font-bold">Warning: Enable these
                    options only if you fully understand their implications and
                    consequences!</span><br>Improper use will result in data loss and could cause
                functional issues.
            </p>
            <div class="w-96">
                <x-forms.checkbox instantSave id="server.settings.delete_unused_volumes" label="Delete Unused Volumes"
                    helper="This option will remove all unused Docker volumes during cleanup.<br><br><strong>Warning: Data form stopped containers will be lost!</strong><br><br>Consequences include:<br>
            <ul class='list-disc pl-4 mt-2'>
                <li>Volumes not attached to running containers will be deleted and data will be permanently lost (stopped containers are affected).</li>
                <li>Data from stopped containers volumes will be permanently lost.</li>
                <li>No way to recover deleted volume data.</li>
            </ul>" />
                <x-forms.checkbox instantSave id="server.settings.delete_unused_networks" label="Delete Unused Networks"
                    helper="This option will remove all unused Docker networks during cleanup.<br><br><strong>Warning: Functionality may be lost and containers may not be able to communicate with each other!</strong><br><br>Consequences include:<br>
            <ul class='list-disc pl-4 mt-2'>
                <li>Networks not attached to running containers will be permanently deleted (stopped containers are affected).</li>
                <li>Custom networks for stopped containers will be permanently deleted.</li>
                <li>Functionality may be lost and containers may not be able to communicate with each other.</li>
            </ul>" />
            </div>
        </div>
        <div class="flex flex-col">
            <h3>Builds</h3>
            <div>Customize the build process.</div>
            <div class="flex flex-wrap gap-2 sm:flex-nowrap pt-4">
                <x-forms.input id="server.settings.concurrent_builds" label="Number of concurrent builds" required
                    helper="You can specify the number of simultaneous build processes/deployments that should run concurrently." />
                <x-forms.input id="server.settings.dynamic_timeout" label="Deployment timeout (seconds)" required
                    helper="You can define the maximum duration for a deployment to run before timing it out." />
            </div>
        </div>
    </div>
</form>
