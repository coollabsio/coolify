<div>
    <x-slot:title>
        {{ __('notification.title') }} | Coolify
    </x-slot>
    <x-notification.navbar />
    <form wire:submit='submit' class="flex flex-col gap-4 pb-4">
        <div class="flex items-center gap-2">
            <h2>{{ __('notification.telegram') }}</h2>
            <x-forms.button canGate="update" :canResource="$settings" type="submit">
                {{ __('button.save') }}
            </x-forms.button>
            @if ($telegramEnabled)
                <x-forms.button canGate="sendTest" :canResource="$settings" class="normal-case dark:text-white btn btn-xs no-animation btn-primary"
                    wire:click="sendTestNotification">
                    {{ __('notification.send_test_notification') }}
                </x-forms.button>
            @else
                <x-forms.button canGate="sendTest" :canResource="$settings" disabled class="normal-case dark:text-white btn btn-xs no-animation btn-primary">
                    {{ __('notification.send_test_notification') }}
                </x-forms.button>
            @endif
        </div>
        <div class="w-32">
            <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="instantSaveTelegramEnabled" id="telegramEnabled" label="{{ __('common.enabled') }}" />
        </div>
        <div class="flex gap-2">
            <x-forms.input canGate="update" :canResource="$settings" type="password" autocomplete="new-password"
                helper="{{ __('notification.bot_api_token_helper') }}"
                required id="telegramToken" label="{{ __('notification.bot_api_token') }}" />
            <x-forms.input canGate="update" :canResource="$settings" type="password" autocomplete="new-password"
                helper="{{ __('notification.chat_id_helper') }}" required id="telegramChatId"
                label="{{ __('notification.chat_id') }}" />
        </div>
    </form>
    <h2 class="mt-4">{{ __('notification.notification_settings') }}</h2>
    <p class="mb-4">
        {{ __('notification.select_events_for_telegram') }}
    </p>
    <div class="flex flex-col gap-4 ">
        <div class="border dark:border-coolgray-300 border-neutral-200 p-4 rounded-lg">
            <h3 class="text-lg font-medium mb-3">{{ __('notification.deployments') }}</h3>
            <div class="flex flex-col gap-1.5 pl-1">
                <div class="pl-1 flex gap-2">
                    <div class="w-96">
                        <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="deploymentSuccessTelegramNotifications"
                            label="{{ __('notification.deployment_success') }}" />
                    </div>
                    <x-forms.input canGate="update" :canResource="$settings" type="password" placeholder="{{ __('forms.placeholders.telegram_thread_id') }}"
                        id="telegramNotificationsDeploymentSuccessThreadId" />
                </div>
                <div class="pl-1 flex gap-2">
                    <div class="w-96">
                        <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="deploymentFailureTelegramNotifications"
                            label="{{ __('notification.deployment_failure') }}" />
                    </div>
                    <x-forms.input canGate="update" :canResource="$settings" type="password" placeholder="{{ __('forms.placeholders.telegram_thread_id') }}"
                        id="telegramNotificationsDeploymentFailureThreadId" />
                </div>
                <div class="pl-1 flex gap-2">
                    <div class="w-96">
                        <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="statusChangeTelegramNotifications"
                            label="{{ __('notification.container_status_changes') }}"
                            helper="{{ __('notification.status_change_hint') }}" />
                    </div>
                    <x-forms.input canGate="update" :canResource="$settings" type="password" id="telegramNotificationsStatusChangeThreadId"
                        placeholder="{{ __('forms.placeholders.telegram_thread_id') }}" />
                </div>
            </div>
        </div>
        <div class="border dark:border-coolgray-300 border-neutral-200 p-4 rounded-lg">
            <h3 class="text-lg font-medium mb-3">{{ __('notification.backups') }}</h3>
            <div class="flex flex-col gap-1.5 pl-1">
                <div class="pl-1 flex gap-2">
                    <div class="w-96">
                        <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="backupSuccessTelegramNotifications"
                            label="{{ __('notification.backup_success') }}" />
                    </div>
                    <x-forms.input canGate="update" :canResource="$settings" type="password" placeholder="{{ __('forms.placeholders.telegram_thread_id') }}"
                        id="telegramNotificationsBackupSuccessThreadId" />
                </div>

                <div class="pl-1 flex gap-2">
                    <div class="w-96">
                        <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="backupFailureTelegramNotifications"
                            label="{{ __('notification.backup_failure') }}" />
                    </div>
                    <x-forms.input canGate="update" :canResource="$settings" type="password" placeholder="{{ __('forms.placeholders.telegram_thread_id') }}"
                        id="telegramNotificationsBackupFailureThreadId" />
                </div>
            </div>
        </div>

        <div class="border dark:border-coolgray-300 border-neutral-200 p-4 rounded-lg">
            <h3 class="text-lg font-medium mb-3">{{ __('notification.scheduled_tasks') }}</h3>
            <div class="flex flex-col gap-1.5 pl-1">
                <div class="pl-1 flex gap-2">
                    <div class="w-96">
                        <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="scheduledTaskSuccessTelegramNotifications"
                            label="{{ __('notification.scheduled_task_success') }}" />
                    </div>
                    <x-forms.input canGate="update" :canResource="$settings" type="password" placeholder="{{ __('forms.placeholders.telegram_thread_id') }}"
                        id="telegramNotificationsScheduledTaskSuccessThreadId" />
                </div>

                <div class="pl-1 flex gap-2">
                    <div class="w-96">
                        <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="scheduledTaskFailureTelegramNotifications"
                            label="{{ __('notification.scheduled_task_failure') }}" />
                    </div>
                    <x-forms.input canGate="update" :canResource="$settings" type="password" placeholder="{{ __('forms.placeholders.telegram_thread_id') }}"
                        id="telegramNotificationsScheduledTaskFailureThreadId" />
                </div>
            </div>
        </div>

        <div class="border dark:border-coolgray-300 border-neutral-200 p-4 rounded-lg">
            <h3 class="text-lg font-medium mb-3">{{ __('notification.server') }}</h3>
            <div class="flex flex-col gap-1.5 pl-1">
                <div class="pl-1 flex gap-2">
                    <div class="w-96">
                        <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="dockerCleanupSuccessTelegramNotifications"
                            label="{{ __('notification.docker_cleanup_success') }}" />
                    </div>
                    <x-forms.input canGate="update" :canResource="$settings" type="password" placeholder="{{ __('forms.placeholders.telegram_thread_id') }}"
                        id="telegramNotificationsDockerCleanupSuccessThreadId" />
                </div>

                <div class="pl-1 flex gap-2">
                    <div class="w-96">
                        <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="dockerCleanupFailureTelegramNotifications"
                            label="{{ __('notification.docker_cleanup_failure') }}" />
                    </div>
                    <x-forms.input canGate="update" :canResource="$settings" type="password" placeholder="{{ __('forms.placeholders.telegram_thread_id') }}"
                        id="telegramNotificationsDockerCleanupFailureThreadId" />
                </div>

                <div class="pl-1 flex gap-2">
                    <div class="w-96">
                        <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="serverDiskUsageTelegramNotifications"
                            label="{{ __('notification.server_disk_usage') }}" />
                    </div>
                    <x-forms.input canGate="update" :canResource="$settings" type="password" placeholder="{{ __('forms.placeholders.telegram_thread_id') }}"
                        id="telegramNotificationsServerDiskUsageThreadId" />
                </div>

                <div class="pl-1 flex gap-2">
                    <div class="w-96">
                        <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="serverReachableTelegramNotifications"
                            label="{{ __('notification.server_reachable') }}" />
                    </div>
                    <x-forms.input canGate="update" :canResource="$settings" type="password" placeholder="{{ __('forms.placeholders.telegram_thread_id') }}"
                        id="telegramNotificationsServerReachableThreadId" />
                </div>

                <div class="pl-1 flex gap-2">
                    <div class="w-96">
                        <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="serverUnreachableTelegramNotifications"
                            label="{{ __('notification.server_unreachable') }}" />
                    </div>
                    <x-forms.input canGate="update" :canResource="$settings" type="password" placeholder="{{ __('forms.placeholders.telegram_thread_id') }}"
                        id="telegramNotificationsServerUnreachableThreadId" />
                </div>

                <div class="pl-1 flex gap-2">
                    <div class="w-96">
                        <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="serverPatchTelegramNotifications"
                            label="{{ __('notification.server_patching') }}" />
                    </div>
                    <x-forms.input canGate="update" :canResource="$settings" type="password" placeholder="{{ __('forms.placeholders.telegram_thread_id') }}"
                        id="telegramNotificationsServerPatchThreadId" />
                </div>

                <div class="pl-1 flex gap-2">
                    <div class="w-96">
                        <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="traefikOutdatedTelegramNotifications"
                            label="{{ __('notification.traefik_outdated') }}" />
                    </div>
                    <x-forms.input canGate="update" :canResource="$settings" type="password" placeholder="{{ __('forms.placeholders.telegram_thread_id') }}"
                        id="telegramNotificationsTraefikOutdatedThreadId" />
                </div>
            </div>
        </div>
    </div>
</div>
