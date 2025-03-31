<div>
    <x-slot:title>
        Notifications | Coolify
    </x-slot>
    <x-notification.navbar />
    <form wire:submit='submit' class="flex flex-col gap-4 pb-4">
        <div class="flex items-center gap-2">
            <h2>Discord</h2>
            <x-forms.button type="submit">
                Save
            </x-forms.button>
            @if ($discordEnabled)
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
        <div class="w-48">
            <x-forms.checkbox instantSave="instantSaveDiscordEnabled" id="discordEnabled" label="Enabled" />
            <x-forms.checkbox instantSave="instantSaveDiscordPingEnabled" id="discordPingEnabled"
                helper="If enabled, a ping (@here) will be sent to the notification when a critical event happens."
                label="Ping Enabled" />
        </div>
        <x-forms.input type="password"
            helper="Create a Discord Server and generate a Webhook URL. <br><a class='inline-block underline dark:text-white' href='https://support.discord.com/hc/en-us/articles/228383668-Intro-to-Webhooks' target='_blank'>Webhook Documentation</a>"
            required id="discordWebhookUrl" label="Webhook" />
    </form>
    <h2 class="mt-4">Notification Settings</h2>
    <p class="mb-4">
        Select events for which you would like to receive Discord notifications.
    </p>
    <div class="flex flex-col gap-4 max-w-2xl">
        <div class="border dark:border-coolgray-300 p-4 rounded-lg">
            <h3 class="font-medium mb-3">Deployments</h3>
            <div class="flex flex-col gap-1.5 pl-1">
                <x-forms.checkbox instantSave="saveModel" id="deploymentSuccessDiscordNotifications"
                    label="Deployment Success" />
                <x-forms.checkbox instantSave="saveModel" id="deploymentFailureDiscordNotifications"
                    label="Deployment Failure" />
                <x-forms.checkbox instantSave="saveModel"
                    helper="Send a notification when a container status changes. It will notify for Stopped and Restarted events of a container."
                    id="statusChangeDiscordNotifications" label="Container Status Changes" />
            </div>
        </div>
        <div class="border dark:border-coolgray-300 p-4 rounded-lg">
            <h3 class="font-medium mb-3">Backups</h3>
            <div class="flex flex-col gap-1.5 pl-1">
                <x-forms.checkbox instantSave="saveModel" id="backupSuccessDiscordNotifications"
                    label="Backup Success" />
                <x-forms.checkbox instantSave="saveModel" id="backupFailureDiscordNotifications"
                    label="Backup Failure" />
            </div>
        </div>
        <div class="border dark:border-coolgray-300 p-4 rounded-lg">
            <h3 class="font-medium mb-3">Scheduled Tasks</h3>
            <div class="flex flex-col gap-1.5 pl-1">
                <x-forms.checkbox instantSave="saveModel" id="scheduledTaskSuccessDiscordNotifications"
                    label="Scheduled Task Success" />
                <x-forms.checkbox instantSave="saveModel" id="scheduledTaskFailureDiscordNotifications"
                    label="Scheduled Task Failure" />
            </div>
        </div>
        <div class="border dark:border-coolgray-300 p-4 rounded-lg">
            <h3 class="font-medium mb-3">Server</h3>
            <div class="flex flex-col gap-1.5 pl-1">
                <x-forms.checkbox instantSave="saveModel" id="dockerCleanupSuccessDiscordNotifications"
                    label="Docker Cleanup Success" />
                <x-forms.checkbox instantSave="saveModel" id="dockerCleanupFailureDiscordNotifications"
                    label="Docker Cleanup Failure" />
                <x-forms.checkbox instantSave="saveModel" id="serverDiskUsageDiscordNotifications"
                    label="Server Disk Usage" />
                <x-forms.checkbox instantSave="saveModel" id="serverReachableDiscordNotifications"
                    label="Server Reachable" />
                <x-forms.checkbox instantSave="saveModel" id="serverUnreachableDiscordNotifications"
                    label="Server Unreachable" />
            </div>
        </div>
    </div>
</div>
