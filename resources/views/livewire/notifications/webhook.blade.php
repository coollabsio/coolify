<div>
    <x-slot:title>
        Notifications | Coolify
    </x-slot>
    <x-notification.navbar />
    <form wire:submit='submit' class="flex flex-col gap-4 pb-4">
        <div class="flex items-center gap-2">
            <h2>Webhook</h2>
            <x-forms.button canGate="update" :canResource="$settings" type="submit">
                Save
            </x-forms.button>
            @if ($webhookEnabled)
                <x-forms.button canGate="sendTest" :canResource="$settings"
                    class="normal-case dark:text-white btn btn-xs no-animation btn-primary"
                    wire:click="sendTestNotification">
                    Send Test Notification
                </x-forms.button>
            @else
                <x-forms.button canGate="sendTest" :canResource="$settings" disabled
                    class="normal-case dark:text-white btn btn-xs no-animation btn-primary">
                    Send Test Notification
                </x-forms.button>
            @endif
        </div>
        <div class="w-48">
            <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="instantSaveWebhookEnabled"
                id="webhookEnabled" label="Enabled" />
        </div>
        <div class="flex items-end gap-2">

            <x-forms.input canGate="update" :canResource="$settings" type="password"
                helper="Enter a valid HTTP or HTTPS URL. Coolify will send POST requests to this endpoint when events occur."
                required id="webhookUrl" label="Webhook URL (POST)" />
        </div>
    </form>
    <h2 class="mt-4">Notification Settings</h2>
    <p class="mb-4">
        Select events for which you would like to receive webhook notifications.
    </p>
    <div class="flex flex-col gap-4 max-w-2xl">
        <div class="border dark:border-coolgray-300 border-neutral-200 p-4 rounded-lg">
            <h3 class="font-medium mb-3">Deployments</h3>
            <div class="flex flex-col gap-1.5 pl-1">
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel"
                    id="deploymentSuccessWebhookNotifications" label="Deployment Success" />
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel"
                    id="deploymentFailureWebhookNotifications" label="Deployment Failure" />
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel"
                    helper="Send a notification when a container status changes. It will notify for Stopped and Restarted events of a container."
                    id="statusChangeWebhookNotifications" label="Container Status Changes" />
            </div>
        </div>
        <div class="border dark:border-coolgray-300 border-neutral-200 p-4 rounded-lg">
            <h3 class="font-medium mb-3">Backups</h3>
            <div class="flex flex-col gap-1.5 pl-1">
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel"
                    id="backupSuccessWebhookNotifications" label="Backup Success" />
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel"
                    id="backupFailureWebhookNotifications" label="Backup Failure" />
            </div>
        </div>
        <div class="border dark:border-coolgray-300 border-neutral-200 p-4 rounded-lg">
            <h3 class="font-medium mb-3">Scheduled Tasks</h3>
            <div class="flex flex-col gap-1.5 pl-1">
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel"
                    id="scheduledTaskSuccessWebhookNotifications" label="Scheduled Task Success" />
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel"
                    id="scheduledTaskFailureWebhookNotifications" label="Scheduled Task Failure" />
            </div>
        </div>
        <div class="border dark:border-coolgray-300 border-neutral-200 p-4 rounded-lg">
            <h3 class="font-medium mb-3">Server</h3>
            <div class="flex flex-col gap-1.5 pl-1">
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel"
                    id="dockerCleanupSuccessWebhookNotifications" label="Docker Cleanup Success" />
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel"
                    id="dockerCleanupFailureWebhookNotifications" label="Docker Cleanup Failure" />
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel"
                    id="serverDiskUsageWebhookNotifications" label="Server Disk Usage" />
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel"
                    id="serverReachableWebhookNotifications" label="Server Reachable" />
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel"
                    id="serverUnreachableWebhookNotifications" label="Server Unreachable" />
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel"
                    id="serverPatchWebhookNotifications" label="Server Patching" />
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel"
                    id="traefikOutdatedWebhookNotifications" label="Traefik Proxy Outdated" />
            </div>
        </div>
    </div>
</div>
