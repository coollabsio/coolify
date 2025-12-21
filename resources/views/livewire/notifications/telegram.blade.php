<div>
    <x-slot:title>
        Notifications | Coolify
    </x-slot>
    <x-notification.navbar />
    <form wire:submit='submit' class="flex flex-col gap-4 pb-4">
        <div class="flex items-center gap-2">
            <h2>Telegram</h2>
            <x-forms.button canGate="update" :canResource="$settings" type="submit">
                Save
            </x-forms.button>
            @if ($telegramEnabled)
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
            <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="instantSaveTelegramEnabled" id="telegramEnabled" label="Enabled" />
        </div>
        <div class="flex gap-2">
            <x-forms.input canGate="update" :canResource="$settings" type="password" autocomplete="new-password"
                helper="Get it from the <a class='inline-block underline dark:text-white' href='https://t.me/botfather' target='_blank'>BotFather Bot</a> on Telegram."
                required id="telegramToken" label="Bot API Token" />
            <x-forms.input canGate="update" :canResource="$settings" type="password" autocomplete="new-password"
                helper="Add your bot to a group chat and add its Chat ID here." required id="telegramChatId"
                label="Chat ID" />
        </div>
    </form>
    <h2 class="mt-4">Notification Settings</h2>
    <p class="mb-4">
        Select events for which you would like to receive Telegram notifications.
    </p>
    <div class="flex flex-col gap-4 ">
        <div class="border dark:border-coolgray-300 border-neutral-200 p-4 rounded-lg">
            <h3 class="text-lg font-medium mb-3">Deployments</h3>
            <div class="flex flex-col gap-1.5 pl-1">
                <div class="pl-1 flex gap-2">
                    <div class="w-96">
                        <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="deploymentSuccessTelegramNotifications"
                            label="Deployment Success" />
                    </div>
                    <x-forms.input canGate="update" :canResource="$settings" type="password" placeholder="{{ __('forms.placeholders.telegram_thread_id') }}"
                        id="telegramNotificationsDeploymentSuccessThreadId" />
                </div>
                <div class="pl-1 flex gap-2">
                    <div class="w-96">
                        <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="deploymentFailureTelegramNotifications"
                            label="Deployment Failure" />
                    </div>
                    <x-forms.input canGate="update" :canResource="$settings" type="password" placeholder="{{ __('forms.placeholders.telegram_thread_id') }}"
                        id="telegramNotificationsDeploymentFailureThreadId" />
                </div>
                <div class="pl-1 flex gap-2">
                    <div class="w-96">
                        <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="statusChangeTelegramNotifications"
                            label="Container Status Changes"
                            helper="Send a notification when a container status changes. It will send a notification for Stopped and Restarted events of a container." />
                    </div>
                    <x-forms.input canGate="update" :canResource="$settings" type="password" id="telegramNotificationsStatusChangeThreadId"
                        placeholder="{{ __('forms.placeholders.telegram_thread_id') }}" />
                </div>
            </div>
        </div>
        <div class="border dark:border-coolgray-300 border-neutral-200 p-4 rounded-lg">
            <h3 class="text-lg font-medium mb-3">Backups</h3>
            <div class="flex flex-col gap-1.5 pl-1">
                <div class="pl-1 flex gap-2">
                    <div class="w-96">
                        <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="backupSuccessTelegramNotifications"
                            label="Backup Success" />
                    </div>
                    <x-forms.input canGate="update" :canResource="$settings" type="password" placeholder="{{ __('forms.placeholders.telegram_thread_id') }}"
                        id="telegramNotificationsBackupSuccessThreadId" />
                </div>

                <div class="pl-1 flex gap-2">
                    <div class="w-96">
                        <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="backupFailureTelegramNotifications"
                            label="Backup Failure" />
                    </div>
                    <x-forms.input canGate="update" :canResource="$settings" type="password" placeholder="{{ __('forms.placeholders.telegram_thread_id') }}"
                        id="telegramNotificationsBackupFailureThreadId" />
                </div>
            </div>
        </div>

        <div class="border dark:border-coolgray-300 border-neutral-200 p-4 rounded-lg">
            <h3 class="text-lg font-medium mb-3">Scheduled Tasks</h3>
            <div class="flex flex-col gap-1.5 pl-1">
                <div class="pl-1 flex gap-2">
                    <div class="w-96">
                        <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="scheduledTaskSuccessTelegramNotifications"
                            label="Scheduled Task Success" />
                    </div>
                    <x-forms.input canGate="update" :canResource="$settings" type="password" placeholder="{{ __('forms.placeholders.telegram_thread_id') }}"
                        id="telegramNotificationsScheduledTaskSuccessThreadId" />
                </div>

                <div class="pl-1 flex gap-2">
                    <div class="w-96">
                        <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="scheduledTaskFailureTelegramNotifications"
                            label="Scheduled Task Failure" />
                    </div>
                    <x-forms.input canGate="update" :canResource="$settings" type="password" placeholder="{{ __('forms.placeholders.telegram_thread_id') }}"
                        id="telegramNotificationsScheduledTaskFailureThreadId" />
                </div>
            </div>
        </div>

        <div class="border dark:border-coolgray-300 border-neutral-200 p-4 rounded-lg">
            <h3 class="text-lg font-medium mb-3">Server</h3>
            <div class="flex flex-col gap-1.5 pl-1">
                <div class="pl-1 flex gap-2">
                    <div class="w-96">
                        <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="dockerCleanupSuccessTelegramNotifications"
                            label="Docker Cleanup Success" />
                    </div>
                    <x-forms.input canGate="update" :canResource="$settings" type="password" placeholder="{{ __('forms.placeholders.telegram_thread_id') }}"
                        id="telegramNotificationsDockerCleanupSuccessThreadId" />
                </div>

                <div class="pl-1 flex gap-2">
                    <div class="w-96">
                        <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="dockerCleanupFailureTelegramNotifications"
                            label="Docker Cleanup Failure" />
                    </div>
                    <x-forms.input canGate="update" :canResource="$settings" type="password" placeholder="{{ __('forms.placeholders.telegram_thread_id') }}"
                        id="telegramNotificationsDockerCleanupFailureThreadId" />
                </div>

                <div class="pl-1 flex gap-2">
                    <div class="w-96">
                        <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="serverDiskUsageTelegramNotifications"
                            label="Server Disk Usage" />
                    </div>
                    <x-forms.input canGate="update" :canResource="$settings" type="password" placeholder="{{ __('forms.placeholders.telegram_thread_id') }}"
                        id="telegramNotificationsServerDiskUsageThreadId" />
                </div>

                <div class="pl-1 flex gap-2">
                    <div class="w-96">
                        <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="serverReachableTelegramNotifications"
                            label="Server Reachable" />
                    </div>
                    <x-forms.input canGate="update" :canResource="$settings" type="password" placeholder="{{ __('forms.placeholders.telegram_thread_id') }}"
                        id="telegramNotificationsServerReachableThreadId" />
                </div>

                <div class="pl-1 flex gap-2">
                    <div class="w-96">
                        <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="serverUnreachableTelegramNotifications"
                            label="Server Unreachable" />
                    </div>
                    <x-forms.input canGate="update" :canResource="$settings" type="password" placeholder="{{ __('forms.placeholders.telegram_thread_id') }}"
                        id="telegramNotificationsServerUnreachableThreadId" />
                </div>

                <div class="pl-1 flex gap-2">
                    <div class="w-96">
                        <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="serverPatchTelegramNotifications"
                            label="Server Patching" />
                    </div>
                    <x-forms.input canGate="update" :canResource="$settings" type="password" placeholder="{{ __('forms.placeholders.telegram_thread_id') }}"
                        id="telegramNotificationsServerPatchThreadId" />
                </div>

                <div class="pl-1 flex gap-2">
                    <div class="w-96">
                        <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="traefikOutdatedTelegramNotifications"
                            label="Traefik Proxy Outdated" />
                    </div>
                    <x-forms.input canGate="update" :canResource="$settings" type="password" placeholder="{{ __('forms.placeholders.telegram_thread_id') }}"
                        id="telegramNotificationsTraefikOutdatedThreadId" />
                </div>
            </div>
        </div>
    </div>
</div>
