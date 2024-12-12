<div>
    <x-slot:title>
        Notifications | Coolify
    </x-slot>
    <x-notification.navbar />
    <form wire:submit='submit' class="flex flex-col gap-4 pb-4">
        <div class="flex items-center gap-2">
            <h2>Pushover</h2>
            <x-forms.button type="submit">
                Save
            </x-forms.button>
            @if ($pushoverEnabled)
                <x-forms.button class="normal-case dark:text-white btn btn-xs no-animation btn-primary"
                    wire:click="sendTestNotification">
                    Send Test Notification
                </x-forms.button>
            @else
                <x-forms.button disabled class="normal-case dark:text-white btn btn-xs no-animation btn-primary">
                    Send Test Notification
                </x-forms.button>
            @endif
        </div>
        <div class="w-32">
            <x-forms.checkbox instantSave="instantSavePushoverEnabled" id="pushoverEnabled" label="Enabled" />
        </div>
        <div class="flex  gap-2">
            <x-forms.input type="password"
                helper="Get your User Key in Pushover. You need to be logged in to Pushover to see your user key in the top right corner. <br><a class='inline-block underline dark:text-white' href='https://pushover.net/' target='_blank'>Pushover Dashboard</a>"
                required id="pushoverUserKey" label="User Key" />
            <x-forms.input type="password"
                helper="Generate an API Token/Key in Pushover by creating a new application. <br><a class='inline-block underline dark:text-white' href='https://pushover.net/apps/build' target='_blank'>Create Pushover Application</a>"
                required id="pushoverApiToken" label="API Token" />
        </div>
    </form>
    <h2 class="mt-4">Notification Settings</h2>
    <p class="mb-4">
        Select events for which you would like to receive Pushover notifications.
    </p>
    <div class="flex flex-col gap-4 max-w-2xl">
        <div class="border dark:border-coolgray-300 p-4 rounded-lg">
            <h3 class="font-medium mb-3">Deployments</h3>
            <div class="flex flex-col gap-1.5 pl-1">
                <x-forms.checkbox instantSave="saveModel" id="deploymentSuccessPushoverNotifications"
                    label="Deployment Success" />
                <x-forms.checkbox instantSave="saveModel" id="deploymentFailurePushoverNotifications"
                    label="Deployment Failure" />
                <x-forms.checkbox instantSave="saveModel"
                    helper="Send a notification when a container status changes. It will notify for Stopped and Restarted events of a container."
                    id="statusChangePushoverNotifications" label="Container Status Changes" />
            </div>
        </div>
        <div class="border dark:border-coolgray-300 p-4 rounded-lg">
            <h3 class="font-medium mb-3">Backups</h3>
            <div class="flex flex-col gap-1.5 pl-1">
                <x-forms.checkbox instantSave="saveModel" id="backupSuccessPushoverNotifications"
                    label="Backup Success" />
                <x-forms.checkbox instantSave="saveModel" id="backupFailurePushoverNotifications"
                    label="Backup Failure" />
            </div>
        </div>
        <div class="border dark:border-coolgray-300 p-4 rounded-lg">
            <h3 class="font-medium mb-3">Scheduled Tasks</h3>
            <div class="flex flex-col gap-1.5 pl-1">
                <x-forms.checkbox instantSave="saveModel" id="scheduledTaskSuccessPushoverNotifications"
                    label="Scheduled Task Success" />
                <x-forms.checkbox instantSave="saveModel" id="scheduledTaskFailurePushoverNotifications"
                    label="Scheduled Task Failure" />
            </div>
        </div>
        <div class="border dark:border-coolgray-300 p-4 rounded-lg">
            <h3 class="font-medium mb-3">Server</h3>
            <div class="flex flex-col gap-1.5 pl-1">
                <x-forms.checkbox instantSave="saveModel" id="dockerCleanupSuccessPushoverNotifications"
                    label="Docker Cleanup Success" />
                <x-forms.checkbox instantSave="saveModel" id="dockerCleanupFailurePushoverNotifications"
                    label="Docker Cleanup Failure" />
                <x-forms.checkbox instantSave="saveModel" id="serverDiskUsagePushoverNotifications"
                    label="Server Disk Usage" />
                <x-forms.checkbox instantSave="saveModel" id="serverReachablePushoverNotifications"
                    label="Server Reachable" />
                <x-forms.checkbox instantSave="saveModel" id="serverUnreachablePushoverNotifications"
                    label="Server Unreachable" />
            </div>
        </div>
    </div>
</div>
