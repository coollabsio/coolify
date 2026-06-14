<div>
    <x-slot:title>
        Notifications | Coolify
    </x-slot>
    <x-notification.navbar />
    <form wire:submit='submit' class="flex flex-col gap-4 pb-4">
        <div class="flex items-center gap-2">
            <h2>Ntfy</h2>
            <x-forms.button canGate="update" :canResource="$settings" type="submit">
                Save
            </x-forms.button>
            @if ($ntfyEnabled)
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
            <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="instantSaveNtfyEnabled" id="ntfyEnabled" label="Enabled" />
        </div>
        <div class="flex flex-col gap-2">
            <div class="flex gap-2">
                <x-forms.input canGate="update" :canResource="$settings"
                    helper="The URL of your ntfy server. Use <code>https://ntfy.sh</code> for the public server, or your own self-hosted instance URL. <br><a class='inline-block underline dark:text-white' href='https://docs.ntfy.sh/' target='_blank'>Ntfy Documentation</a>"
                    required id="ntfyUrl" label="Server URL" placeholder="https://ntfy.sh" />
                <x-forms.input canGate="update" :canResource="$settings"
                    helper="The topic name to publish notifications to. Choose a unique, hard-to-guess topic name for security if using the public server."
                    required id="ntfyTopic" label="Topic" placeholder="coolify-alerts" />
                <x-forms.select canGate="update" :canResource="$settings" wire:model.live="ntfyAuthMethod" id="ntfyAuthMethod" label="Authentication"
                    helper="Choose how to authenticate with your ntfy server. Leave credentials empty for public topics.">
                    <option value="basic">Username / Password</option>
                    <option value="token">Access Token</option>
                    <option value="none">None (public topic)</option>
                </x-forms.select>
            </div>
            @if ($ntfyAuthMethod === 'token')
                <div class="flex gap-2">
                    <x-forms.input canGate="update" :canResource="$settings" type="password"
                        helper="Access token for authentication."
                        id="ntfyToken" label="Access Token" />
                </div>
            @elseif ($ntfyAuthMethod === 'basic')
                <div class="flex gap-2">
                    <x-forms.input canGate="update" :canResource="$settings" type="password"
                        helper="Username for basic authentication."
                        id="ntfyUsername" label="Username" />
                    <x-forms.input canGate="update" :canResource="$settings" type="password"
                        helper="Password for basic authentication."
                        id="ntfyPassword" label="Password" />
                </div>
            @endif
        </div>
    </form>
    <h2 class="mt-4">Message Priority</h2>
    <p class="mb-4">
        Configure the notification priority for each event severity level. Priority controls vibration, sound, and notification behavior on your devices.
    </p>
    <div class="flex flex-col gap-2">
        <div class="flex gap-2">
            <x-forms.select canGate="update" :canResource="$settings" id="ntfyPrioritySuccessEvents" label="Success Events"
                helper="Priority for success notifications (deployments, backups, cleanup, server reachable).">
                <option value="1">1 - Min</option>
                <option value="2">2 - Low</option>
                <option value="3">3 - Default</option>
                <option value="4">4 - High</option>
                <option value="5">5 - Urgent</option>
            </x-forms.select>
            <x-forms.select canGate="update" :canResource="$settings" id="ntfyPriorityInfoEvents" label="Info Events"
                helper="Priority for informational notifications (general, server patches, test notifications).">
                <option value="1">1 - Min</option>
                <option value="2">2 - Low</option>
                <option value="3">3 - Default</option>
                <option value="4">4 - High</option>
                <option value="5">5 - Urgent</option>
            </x-forms.select>
            <x-forms.select canGate="update" :canResource="$settings" id="ntfyPriorityWarningEvents" label="Warning Events"
                helper="Priority for warning notifications (disk usage, Traefik outdated, S3 warnings).">
                <option value="1">1 - Min</option>
                <option value="2">2 - Low</option>
                <option value="3">3 - Default</option>
                <option value="4">4 - High</option>
                <option value="5">5 - Urgent</option>
            </x-forms.select>
            <x-forms.select canGate="update" :canResource="$settings" id="ntfyPriorityErrorEvents" label="Error/Failure Events"
                helper="Priority for error notifications (deployments, server unreachable, server disabled).">
                <option value="1">1 - Min</option>
                <option value="2">2 - Low</option>
                <option value="3">3 - Default</option>
                <option value="4">4 - High</option>
                <option value="5">5 - Urgent</option>
            </x-forms.select>
        </div>
    </div>
    <h2 class="mt-4">Notification Settings</h2>
    <p class="mb-4">
        Select events for which you would like to receive Ntfy notifications.
    </p>
    <div class="flex flex-col gap-4 max-w-2xl">
        <div class="border dark:border-coolgray-300 border-neutral-200 p-4 rounded-lg">
            <h3 class="font-medium mb-3">Deployments</h3>
            <div class="flex flex-col gap-1.5 pl-1">
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="deploymentSuccessNtfyNotifications"
                    label="Deployment Success" />
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="deploymentFailureNtfyNotifications"
                    label="Deployment Failure" />
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel"
                    helper="Send a notification when a container status changes. It will notify for Stopped and Restarted events of a container."
                    id="statusChangeNtfyNotifications" label="Container Status Changes" />
            </div>
        </div>
        <div class="border dark:border-coolgray-300 border-neutral-200 p-4 rounded-lg">
            <h3 class="font-medium mb-3">Backups</h3>
            <div class="flex flex-col gap-1.5 pl-1">
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="backupSuccessNtfyNotifications"
                    label="Backup Success" />
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="backupFailureNtfyNotifications"
                    label="Backup Failure" />
            </div>
        </div>
        <div class="border dark:border-coolgray-300 border-neutral-200 p-4 rounded-lg">
            <h3 class="font-medium mb-3">Scheduled Tasks</h3>
            <div class="flex flex-col gap-1.5 pl-1">
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="scheduledTaskSuccessNtfyNotifications"
                    label="Scheduled Task Success" />
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="scheduledTaskFailureNtfyNotifications"
                    label="Scheduled Task Failure" />
            </div>
        </div>
        <div class="border dark:border-coolgray-300 border-neutral-200 p-4 rounded-lg">
            <h3 class="font-medium mb-3">Server</h3>
            <div class="flex flex-col gap-1.5 pl-1">
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="dockerCleanupSuccessNtfyNotifications"
                    label="Docker Cleanup Success" />
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="dockerCleanupFailureNtfyNotifications"
                    label="Docker Cleanup Failure" />
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="serverDiskUsageNtfyNotifications"
                    label="Server Disk Usage" />
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="serverReachableNtfyNotifications"
                    label="Server Reachable" />
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="serverUnreachableNtfyNotifications"
                    label="Server Unreachable" />
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="serverPatchNtfyNotifications"
                    label="Server Patching" />
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="traefikOutdatedNtfyNotifications"
                    label="Traefik Proxy Outdated" />
            </div>
        </div>
    </div>
</div>
