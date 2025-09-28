<div>
    <x-slot:title>
        Notifications | Coolify
    </x-slot>
    <x-notification.navbar />
    <form wire:submit='submit' class="flex flex-col gap-4 pb-4">
        <div class="flex items-center gap-2">
            <h2>Matrix</h2>
            <x-forms.button canGate="update" :canResource="$settings" type="submit">
                Save
            </x-forms.button>
            @if ($matrixEnabled)
                <x-forms.button canGate="sendTest" :canResource="$settings" class="normal-case dark:text-white btn btn-xs no-animation btn-primary"
                    wire:click="sendTestNotification">
                    Send Test Notification
                </x-forms.button>
            @else
                <x-forms.button canGate="sendTest" :canResource="$settings" disabled class="normal-case dark:text-white btn btn-xs no-animation btn-primary">
                    Send Test Notification
                </x-forms.button>
            @endif
        </div>
        <div class="w-32">
            <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="instantSaveMatrixEnabled" id="matrixEnabled" label="Enabled" />
        </div>
        <x-forms.input canGate="update" :canResource="$settings" type="text"
            helper="Your Matrix homeserver URL (e.g., https://matrix.org or https://your-homeserver.com:8448)"
            required id="matrixHomeserverUrl" label="Homeserver URL" />
        <x-forms.input canGate="update" :canResource="$settings" type="text"
            helper="The internal room ID (e.g., !QMdRCpUIfLwsfjxye6:home.server). You can find this in the advanced section of room settings in your Matrix client."
            required id="matrixRoomId" label="Room ID" />
        <x-forms.input canGate="update" :canResource="$settings" type="password"
            helper="Access token for the Matrix bot user. It is highly recommended to create a new user for notifications instead of using your personal account."
            required id="matrixAccessToken" label="Access Token" />
        <x-forms.input canGate="update" :canResource="$settings" type="text"
            helper="Optional friendly name for this notification configuration (for your reference only)"
            id="matrixFriendlyName" label="Friendly Name" />
    </form>
    <h2 class="mt-4">Notification Settings</h2>
    <p class="mb-4">
        Select events for which you would like to receive Matrix notifications.
    </p>
    <div class="flex flex-col gap-4 max-w-2xl">
        <div class="border dark:border-coolgray-300 border-neutral-200 p-4 rounded-lg">
            <h3 class="font-medium mb-3">Deployments</h3>
            <div class="flex flex-col gap-1.5 pl-1">
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="deploymentSuccessMatrixNotifications"
                    label="Deployment Success" />
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="deploymentFailureMatrixNotifications"
                    label="Deployment Failure" />
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel"
                    helper="Send a notification when a container status changes. It will notify for Stopped and Restarted events of a container."
                    id="statusChangeMatrixNotifications" label="Container Status Changes" />
            </div>
        </div>
        <div class="border dark:border-coolgray-300 border-neutral-200 p-4 rounded-lg">
            <h3 class="font-medium mb-3">Backups</h3>
            <div class="flex flex-col gap-1.5 pl-1">
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="backupSuccessMatrixNotifications" label="Backup Success" />
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="backupFailureMatrixNotifications" label="Backup Failure" />
            </div>
        </div>
        <div class="border dark:border-coolgray-300 border-neutral-200 p-4 rounded-lg">
            <h3 class="font-medium mb-3">Scheduled Tasks</h3>
            <div class="flex flex-col gap-1.5 pl-1">
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="scheduledTaskSuccessMatrixNotifications"
                    label="Scheduled Task Success" />
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="scheduledTaskFailureMatrixNotifications"
                    label="Scheduled Task Failure" />
            </div>
        </div>
        <div class="border dark:border-coolgray-300 border-neutral-200 p-4 rounded-lg">
            <h3 class="font-medium mb-3">Server</h3>
            <div class="flex flex-col gap-1.5 pl-1">
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="dockerCleanupSuccessMatrixNotifications"
                    label="Docker Cleanup Success" />
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="dockerCleanupFailureMatrixNotifications"
                    label="Docker Cleanup Failure" />
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="serverDiskUsageMatrixNotifications"
                    label="Server Disk Usage" />
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="serverReachableMatrixNotifications"
                    label="Server Reachable" />
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="serverUnreachableMatrixNotifications"
                    label="Server Unreachable" />
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="serverPatchMatrixNotifications" label="Server Patching" />
            </div>
        </div>
    </div>
</div>