<div>
    <x-slot:title>
        {{ __('notification.title') }} | Coolify
    </x-slot>
    <x-notification.navbar />
    <form wire:submit='submit' class="flex flex-col gap-4 pb-4">
        <div class="flex items-center gap-2">
            <h2>{{ __('notification.discord') }}</h2>
            <x-forms.button canGate="update" :canResource="$settings" type="submit">
                {{ __('button.save') }}
            </x-forms.button>
            @if ($discordEnabled)
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
        <div class="w-48">
            <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="instantSaveDiscordEnabled" id="discordEnabled" label="{{ __('common.enabled') }}" />
            <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="instantSaveDiscordPingEnabled" id="discordPingEnabled"
                helper="{{ __('notification.ping_enabled_helper') }}"
                label="{{ __('notification.ping_enabled') }}" />
        </div>
        <x-forms.input canGate="update" :canResource="$settings" type="password"
            helper="{{ __('notification.webhook_helper_discord') }}"
            required id="discordWebhookUrl" label="{{ __('notification.webhook') }}" />
    </form>
    <h2 class="mt-4">{{ __('notification.notification_settings') }}</h2>
    <p class="mb-4">
        {{ __('notification.select_events_for_discord') }}
    </p>
    <div class="flex flex-col gap-4 max-w-2xl">
        <div class="border dark:border-coolgray-300 border-neutral-200 p-4 rounded-lg">
            <h3 class="font-medium mb-3">{{ __('notification.deployments') }}</h3>
            <div class="flex flex-col gap-1.5 pl-1">
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="deploymentSuccessDiscordNotifications"
                    label="{{ __('notification.deployment_success') }}" />
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="deploymentFailureDiscordNotifications"
                    label="{{ __('notification.deployment_failure') }}" />
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel"
                    helper="{{ __('notification.status_change_hint') }}"
                    id="statusChangeDiscordNotifications" label="{{ __('notification.container_status_changes') }}" />
            </div>
        </div>
        <div class="border dark:border-coolgray-300 border-neutral-200 p-4 rounded-lg">
            <h3 class="font-medium mb-3">{{ __('notification.backups') }}</h3>
            <div class="flex flex-col gap-1.5 pl-1">
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="backupSuccessDiscordNotifications"
                    label="{{ __('notification.backup_success') }}" />
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="backupFailureDiscordNotifications"
                    label="{{ __('notification.backup_failure') }}" />
            </div>
        </div>
        <div class="border dark:border-coolgray-300 border-neutral-200 p-4 rounded-lg">
            <h3 class="font-medium mb-3">{{ __('notification.scheduled_tasks') }}</h3>
            <div class="flex flex-col gap-1.5 pl-1">
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="scheduledTaskSuccessDiscordNotifications"
                    label="{{ __('notification.scheduled_task_success') }}" />
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="scheduledTaskFailureDiscordNotifications"
                    label="{{ __('notification.scheduled_task_failure') }}" />
            </div>
        </div>
        <div class="border dark:border-coolgray-300 border-neutral-200 p-4 rounded-lg">
            <h3 class="font-medium mb-3">{{ __('notification.server') }}</h3>
            <div class="flex flex-col gap-1.5 pl-1">
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="dockerCleanupSuccessDiscordNotifications"
                    label="{{ __('notification.docker_cleanup_success') }}" />
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="dockerCleanupFailureDiscordNotifications"
                    label="{{ __('notification.docker_cleanup_failure') }}" />
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="serverDiskUsageDiscordNotifications"
                    label="{{ __('notification.server_disk_usage') }}" />
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="serverReachableDiscordNotifications"
                    label="{{ __('notification.server_reachable') }}" />
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="serverUnreachableDiscordNotifications"
                    label="{{ __('notification.server_unreachable') }}" />
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="serverPatchDiscordNotifications"
                    label="{{ __('notification.server_patching') }}" />
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="traefikOutdatedDiscordNotifications"
                    label="{{ __('notification.traefik_outdated') }}" />
            </div>
        </div>
    </div>
</div>
