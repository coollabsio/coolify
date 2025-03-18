<div>
    <x-slot:title>
        Notifications | Coolify
    </x-slot>
    <x-notification.navbar />
    <form wire:submit='submit' class="flex flex-col gap-4 pb-4">
        <div class="flex items-center gap-2">
            <h2>Microsoft Teams</h2>
            <x-forms.button type="submit">
                Save
            </x-forms.button>
            @if ($teamsEnabled)
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
            <x-forms.checkbox instantSave="instantSaveTeamsEnabled" id="teamsEnabled" label="Enabled" />
        </div>
        <div class="flex gap-2">
            <x-forms.input type="password" autocomplete="new-password"
                helper="Microsoft Teams webhook URL. <a class='inline-block underline dark:text-white' href='https://learn.microsoft.com/en-us/microsoftteams/platform/webhooks-and-connectors/how-to/add-incoming-webhook' target='_blank'>Learn more</a>."
                required id="teamsWebhookUrl" label="Webhook URL" />
        </div>
    </form>
    <h2 class="mt-4">Notification Settings</h2>
    <p class="mb-4">
        Select events for which you would like to receive Microsoft Teams notifications.
    </p>
    <div class="flex flex-col gap-4 ">
        <div class="border dark:border-coolgray-300 p-4 rounded-lg">
            <h3 class="text-lg font-medium mb-3">Deployments</h3>
            <div class="flex flex-col gap-1.5 pl-1">
                <div class="pl-1 flex gap-2">
                    <div class="w-96">
                        <x-forms.checkbox instantSave="saveModel" id="deploymentSuccessTeamsNotifications"
                            label="Deployment Success" />
                    </div>
                </div>
                <div class="pl-1 flex gap-2">
                    <div class="w-96">
                        <x-forms.checkbox instantSave="saveModel" id="deploymentFailureTeamsNotifications"
                            label="Deployment Failure" />
                    </div>
                </div>
                <div class="pl-1 flex gap-2">
                    <div class="w-96">
                        <x-forms.checkbox instantSave="saveModel" id="statusChangeTeamsNotifications"
                            label="Container Status Changes"
                            helper="Send a notification when a container status changes. It will send a notification for Stopped and Restarted events of a container." />
                    </div>
                </div>
            </div>
        </div>
        <div class="border dark:border-coolgray-300 p-4 rounded-lg">
            <h3 class="text-lg font-medium mb-3">Backups</h3>
            <div class="flex flex-col gap-1.5 pl-1">
                <div class="pl-1 flex gap-2">
                    <div class="w-96">
                        <x-forms.checkbox instantSave="saveModel" id="backupSuccessTeamsNotifications"
                            label="Backup Success" />
                    </div>
                </div>

                <div class="pl-1 flex gap-2">
                    <div class="w-96">
                        <x-forms.checkbox instantSave="saveModel" id="backupFailureTeamsNotifications"
                            label="Backup Failure" />
                    </div>
                </div>
            </div>
        </div>

        <div class="border dark:border-coolgray-300 p-4 rounded-lg">
            <h3 class="text-lg font-medium mb-3">Scheduled Tasks</h3>
            <div class="flex flex-col gap-1.5 pl-1">
                <div class="pl-1 flex gap-2">
                    <div class="w-96">
                        <x-forms.checkbox instantSave="saveModel" id="scheduledTaskSuccessTeamsNotifications"
                            label="Scheduled Task Success" />
                    </div>
                </div>

                <div class="pl-1 flex gap-2">
                    <div class="w-96">
                        <x-forms.checkbox instantSave="saveModel" id="scheduledTaskFailureTeamsNotifications"
                            label="Scheduled Task Failure" />
                    </div>
                </div>
            </div>
        </div>

        <div class="border dark:border-coolgray-300 p-4 rounded-lg">
            <h3 class="text-lg font-medium mb-3">Server</h3>
            <div class="flex flex-col gap-1.5 pl-1">
                <div class="pl-1 flex gap-2">
                    <div class="w-96">
                        <x-forms.checkbox instantSave="saveModel" id="dockerCleanupSuccessTeamsNotifications"
                            label="Docker Cleanup Success" />
                    </div>
                </div>

                <div class="pl-1 flex gap-2">
                    <div class="w-96">
                        <x-forms.checkbox instantSave="saveModel" id="dockerCleanupFailureTeamsNotifications"
                            label="Docker Cleanup Failure" />
                    </div>
                </div>

                <div class="pl-1 flex gap-2">
                    <div class="w-96">
                        <x-forms.checkbox instantSave="saveModel" id="serverDiskUsageTeamsNotifications"
                            label="Server Disk Usage" />
                    </div>
                </div>

                <div class="pl-1 flex gap-2">
                    <div class="w-96">
                        <x-forms.checkbox instantSave="saveModel" id="serverReachableTeamsNotifications"
                            label="Server Reachable" />
                    </div>
                </div>

                <div class="pl-1 flex gap-2">
                    <div class="w-96">
                        <x-forms.checkbox instantSave="saveModel" id="serverUnreachableTeamsNotifications"
                            label="Server Unreachable" />
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
