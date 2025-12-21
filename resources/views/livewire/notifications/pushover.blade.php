<div>
    <x-slot:title>
        {{ __('notification.title') }} | Coolify
    </x-slot>
    <x-notification.navbar />
    <form wire:submit='submit' class="flex flex-col gap-4 pb-4">
        <div class="flex items-center gap-2">
            <h2>{{ __('notification.pushover') }}</h2>
            <x-forms.button canGate="update" :canResource="$settings" type="submit">
                {{ __('button.save') }}
            </x-forms.button>
            @if ($pushoverEnabled)
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
            <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="instantSavePushoverEnabled" id="pushoverEnabled" label="{{ __('common.enabled') }}" />
        </div>
        <div class="flex  gap-2">
            <x-forms.input canGate="update" :canResource="$settings" type="password"
                helper="{{ __('notification.user_key_helper') }}"
                required id="pushoverUserKey" label="{{ __('notification.user_key') }}" />
            <x-forms.input canGate="update" :canResource="$settings" type="password"
                helper="{{ __('notification.api_token_helper') }}"
                required id="pushoverApiToken" label="{{ __('notification.api_token') }}" />
        </div>
    </form>
    <h2 class="mt-4">{{ __('notification.notification_settings') }}</h2>
    <p class="mb-4">
        {{ __('notification.select_events_for_pushover') }}
    </p>
    <div class="flex flex-col gap-4 max-w-2xl">
        <div class="border dark:border-coolgray-300 border-neutral-200 p-4 rounded-lg">
            <h3 class="font-medium mb-3">{{ __('notification.deployments') }}</h3>
            <div class="flex flex-col gap-1.5 pl-1">
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="deploymentSuccessPushoverNotifications"
                    label="{{ __('notification.deployment_success') }}" />
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="deploymentFailurePushoverNotifications"
                    label="{{ __('notification.deployment_failure') }}" />
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel"
                    helper="{{ __('notification.status_change_hint') }}"
                    id="statusChangePushoverNotifications" label="{{ __('notification.container_status_changes') }}" />
            </div>
        </div>
        <div class="border dark:border-coolgray-300 border-neutral-200 p-4 rounded-lg">
            <h3 class="font-medium mb-3">{{ __('notification.backups') }}</h3>
            <div class="flex flex-col gap-1.5 pl-1">
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="backupSuccessPushoverNotifications"
                    label="{{ __('notification.backup_success') }}" />
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="backupFailurePushoverNotifications"
                    label="{{ __('notification.backup_failure') }}" />
            </div>
        </div>
        <div class="border dark:border-coolgray-300 border-neutral-200 p-4 rounded-lg">
            <h3 class="font-medium mb-3">{{ __('notification.scheduled_tasks') }}</h3>
            <div class="flex flex-col gap-1.5 pl-1">
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="scheduledTaskSuccessPushoverNotifications"
                    label="{{ __('notification.scheduled_task_success') }}" />
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="scheduledTaskFailurePushoverNotifications"
                    label="{{ __('notification.scheduled_task_failure') }}" />
            </div>
        </div>
        <div class="border dark:border-coolgray-300 border-neutral-200 p-4 rounded-lg">
            <h3 class="font-medium mb-3">{{ __('notification.server') }}</h3>
            <div class="flex flex-col gap-1.5 pl-1">
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="dockerCleanupSuccessPushoverNotifications"
                    label="{{ __('notification.docker_cleanup_success') }}" />
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="dockerCleanupFailurePushoverNotifications"
                    label="{{ __('notification.docker_cleanup_failure') }}" />
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="serverDiskUsagePushoverNotifications"
                    label="{{ __('notification.server_disk_usage') }}" />
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="serverReachablePushoverNotifications"
                    label="{{ __('notification.server_reachable') }}" />
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="serverUnreachablePushoverNotifications"
                    label="{{ __('notification.server_unreachable') }}" />
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="serverPatchPushoverNotifications"
                    label="{{ __('notification.server_patching') }}" />
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="traefikOutdatedPushoverNotifications"
                    label="{{ __('notification.traefik_outdated') }}" />
            </div>
        </div>
    </div>
</div>
