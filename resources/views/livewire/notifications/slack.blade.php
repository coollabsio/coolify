<div>
    <x-slot:title>
        Notifications | Coolify
    </x-slot>
    <x-notification.navbar />
    <form wire:submit='submit' class="flex flex-col gap-4 pb-4">
        <div class="flex items-center gap-2">
            <h2>Slack</h2>
            <x-forms.button type="submit">
                Save
            </x-forms.button>
            @if ($slackEnabled)
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
            <x-forms.checkbox instantSave="instantSaveSlackEnabled" id="slackEnabled" label="Enabled" />
        </div>
        <x-forms.input type="password"
            helper="Create a Slack APP and generate a Incoming Webhook URL. <br><a class='inline-block underline dark:text-white' href='https://api.slack.com/apps' target='_blank'>Create Slack APP</a>" required
            id="slackWebhookUrl" label="Webhook" />
    </form>
    <h2 class="mt-4">Notification Settings</h2>
    <p class="mb-4">
        Select events for which you would like to receive Slack notifications.
    </p>
    <div class="flex flex-col gap-4 max-w-2xl">
        <div class="border dark:border-coolgray-300 p-4 rounded-lg">
            <h3 class="font-medium mb-3">Deployments</h3>
            <div class="flex flex-col gap-1.5 pl-1">
                <x-forms.checkbox instantSave="saveModel" id="deploymentSuccessSlackNotifications"
                    label="Deployment Success" />
                <x-forms.checkbox instantSave="saveModel" id="deploymentFailureSlackNotifications"
                    label="Deployment Failure" />
                <x-forms.checkbox instantSave="saveModel"
                    helper="Send a notification when a container status changes. It will notify for Stopped and Restarted events of a container."
                    id="statusChangeSlackNotifications" label="Container Status Changes" />
            </div>
        </div>
        <div class="border dark:border-coolgray-300 p-4 rounded-lg">
            <h3 class="font-medium mb-3">Backups</h3>
            <div class="flex flex-col gap-1.5 pl-1">
                <x-forms.checkbox instantSave="saveModel" id="backupSuccessSlackNotifications" label="Backup Success" />
                <x-forms.checkbox instantSave="saveModel" id="backupFailureSlackNotifications" label="Backup Failure" />
            </div>
        </div>
        <div class="border dark:border-coolgray-300 p-4 rounded-lg">
            <h3 class="font-medium mb-3">Scheduled Tasks</h3>
            <div class="flex flex-col gap-1.5 pl-1">
                <x-forms.checkbox instantSave="saveModel" id="scheduledTaskSuccessSlackNotifications"
                    label="Scheduled Task Success" />
                <x-forms.checkbox instantSave="saveModel" id="scheduledTaskFailureSlackNotifications"
                    label="Scheduled Task Failure" />
            </div>
        </div>
        <div class="border dark:border-coolgray-300 p-4 rounded-lg">
            <h3 class="font-medium mb-3">Server</h3>
            <div class="flex flex-col gap-1.5 pl-1">
                <x-forms.checkbox instantSave="saveModel" id="dockerCleanupSuccessSlackNotifications"
                    label="Docker Cleanup Success" />
                <x-forms.checkbox instantSave="saveModel" id="dockerCleanupFailureSlackNotifications"
                    label="Docker Cleanup Failure" />
                <x-forms.checkbox instantSave="saveModel" id="serverDiskUsageSlackNotifications"
                    label="Server Disk Usage" />
                <x-forms.checkbox instantSave="saveModel" id="serverReachableSlackNotifications"
                    label="Server Reachable" />
                <x-forms.checkbox instantSave="saveModel" id="serverUnreachableSlackNotifications"
                    label="Server Unreachable" />
            </div>
        </div>
    </div>
</div>
