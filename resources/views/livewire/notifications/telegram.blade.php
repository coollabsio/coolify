<div>
    <x-slot:title>
        Notifications | Coolify
        </x-slot>
        <x-notification.navbar />
        <form wire:submit='submit' class="flex flex-col gap-4">
            <div class="flex items-center gap-2">
                <h2>Telegram</h2>
                <x-forms.button type="submit">
                    Save
                </x-forms.button>
                @if ($telegramEnabled)
                <x-forms.button class="normal-case dark:text-white btn btn-xs no-animation btn-primary" wire:click="sendTestNotification">
                    Send Test Notification
                </x-forms.button>
                @endif
            </div>
            <div class="w-32">
                <x-forms.checkbox instantSave="instantSaveTelegramEnabled" id="telegramEnabled" label="Enabled" />
            </div>
            <div class="flex gap-2">
                <x-forms.input type="password" autocomplete="new-password" helper="Get it from the <a class='inline-block underline dark:text-white' href='https://t.me/botfather' target='_blank'>BotFather Bot</a> on Telegram." required id="telegramToken" label="Token" />
                <x-forms.input helper="Recommended to add your bot to a group chat and add its Chat ID here." required id="telegramChatId" label="Chat ID" />
            </div>
            @if ($telegramEnabled)
            <h2 class="mt-8 mb-4">Notification Settings</h2>
            <p class="mb-4">
                Select events for which you would like to receive Telegram notifications.
            </p>
            <div class="flex flex-col gap-4 max-w-2xl">
                <div class="border dark:border-coolgray-300 p-4 rounded-lg">
                    <h3 class="text-lg font-medium mb-3">Deployment</h3>
                    <div class="flex flex-col gap-1.5 pl-1">
                        <h4 class="font-medium mt-2">Deployment Success</h4>
                        <div class="pl-1">
                            <x-forms.checkbox instantSave="saveModel" id="deploymentSuccessTelegramNotifications" label="Enabled" />
                            <x-forms.input helper="Topic ID for deployment success notifications" id="telegramNotificationsDeploymentSuccessTopicId" label="Success Topic ID" />
                        </div>
                        <h4 class="font-medium mt-3">Deployment Failure</h4>
                        <div class="pl-1">
                            <x-forms.checkbox instantSave="saveModel" id="deploymentFailureTelegramNotifications" label="Enabled" />
                            <x-forms.input helper="Topic ID for deployment failure notifications" id="telegramNotificationsDeploymentFailureTopicId" label="Failure Topic ID" />
                        </div>
                        {{-- <h4 class="font-medium mt-3">Container Status Changes</h4>
                        <div class="pl-1">
                            <x-forms.checkbox instantSave="saveModel" id="statusChangeTelegramNotifications" label="Enabled" helper="Send a notification when a container status changes. It will send a notification for Stopped and Restarted events of a container." />
                            <x-forms.input helper="Topic ID for container status notifications" id="telegramNotificationsStatusChangeTopicId" label="Container Status Topic ID" />
                        </div> --}}
                    </div>
                </div>
                <div class="border dark:border-coolgray-300 p-4 rounded-lg">
                    <h3 class="text-lg font-medium mb-3">Backups</h3>
                    <div class="flex flex-col gap-1.5 pl-1">
                        <h4 class="font-medium mt-2">Backup Success</h4>
                        <div class="pl-1">
                            <x-forms.checkbox instantSave="saveModel" id="backupSuccessTelegramNotifications" label="Enabled" />
                            <x-forms.input helper="Topic ID for backup success notifications" id="telegramNotificationsBackupSuccessTopicId" label="Success Topic ID" />
                        </div>

                        <h4 class="font-medium mt-3">Backup Failure</h4>
                        <div class="pl-1">
                            <x-forms.checkbox instantSave="saveModel" id="backupFailureTelegramNotifications" label="Enabled" />
                            <x-forms.input helper="Topic ID for backup failure notifications" id="telegramNotificationsBackupFailureTopicId" label="Failure Topic ID" />
                        </div>
                    </div>
                </div>

                <div class="border dark:border-coolgray-300 p-4 rounded-lg">
                    <h3 class="text-lg font-medium mb-3">Scheduled Tasks</h3>
                    <div class="flex flex-col gap-1.5 pl-1">
                        <h4 class="font-medium mt-2">Scheduled Task Success</h4>
                        <div class="pl-1">
                            <x-forms.checkbox instantSave="saveModel" id="scheduledTaskSuccessTelegramNotifications" label="Enabled" />
                            <x-forms.input helper="Topic ID for scheduled task success notifications" id="telegramNotificationsScheduledTaskSuccessTopicId" label="Success Topic ID" />
                        </div>

                        <h4 class="font-medium mt-3">Scheduled Task Failure</h4>
                        <div class="pl-1">
                            <x-forms.checkbox instantSave="saveModel" id="scheduledTaskFailureTelegramNotifications" label="Enabled" />
                            <x-forms.input helper="Topic ID for scheduled task failure notifications" id="telegramNotificationsScheduledTaskFailureTopicId" label="Failure Topic ID" />
                        </div>
                    </div>
                </div>

                <div class="border dark:border-coolgray-300 p-4 rounded-lg">
                    <h3 class="text-lg font-medium mb-3">Server</h3>
                    <div class="flex flex-col gap-1.5 pl-1">
                        <h4 class="font-medium mt-2">Docker Cleanup Success</h4>
                        <div class="pl-1">
                            <x-forms.checkbox instantSave="saveModel" helper="Send a notification when Docker Cleanup is run on a server." id="dockerCleanupSuccessTelegramNotifications" label="Enabled" />
                            <x-forms.input helper="Topic ID for Docker cleanup success notifications" id="telegramNotificationsDockerCleanupSuccessTopicId" label="Docker Cleanup Success Topic ID" />
                        </div>

                        <h4 class="font-medium mt-3">Docker Cleanup Failure</h4>
                        <div class="pl-1">
                            <x-forms.checkbox instantSave="saveModel" helper="Send a notification when Docker Cleanup fails on a server." id="dockerCleanupFailureTelegramNotifications" label="Enabled" />
                            <x-forms.input helper="Topic ID for Docker cleanup failure notifications" id="telegramNotificationsDockerCleanupFailureTopicId" label="Docker Cleanup Failure Topic ID" />
                        </div>

                        <h4 class="font-medium mt-3">Server Disk Usage</h4>
                        <div class="pl-1">
                            <x-forms.checkbox instantSave="saveModel" id="serverDiskUsageTelegramNotifications" label="Enabled" />
                            <x-forms.input helper="Topic ID for disk usage notifications" id="telegramNotificationsServerDiskUsageTopicId" label="Disk Usage Topic ID" />
                        </div>

                        <h4 class="font-medium mt-3">Server Reachable</h4>
                        <div class="pl-1">
                            <x-forms.checkbox instantSave="saveModel" id="serverReachableTelegramNotifications" label="Enabled" />
                            <x-forms.input helper="Topic ID for server reachable notifications" id="telegramNotificationsServerReachableTopicId" label="Server Reachable Topic ID" />
                        </div>

                        <h4 class="font-medium mt-3">Server Unreachable</h4>
                        <div class="pl-1">
                            <x-forms.checkbox instantSave="saveModel" id="serverUnreachableTelegramNotifications" label="Enabled" />
                            <x-forms.input helper="Topic ID for server unreachable notifications" id="telegramNotificationsServerUnreachableTopicId" label="Server Unreachable Topic ID" />
                        </div>
                    </div>
                </div>
            </div>
            @endif
        </form>
</div>
