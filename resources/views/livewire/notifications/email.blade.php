<div>
    <x-slot:title>
        {{ __('notification.title') }} | Coolify
    </x-slot>
    <x-notification.navbar />
    <form wire:submit='submit' class="flex flex-col gap-4 pb-4">
        <div class="flex items-center gap-2">
            <h2>{{ __('notification.email') }}</h2>
            <x-forms.button canGate="update" :canResource="$settings" type="submit">
                {{ __('common.save') }}
            </x-forms.button>
            @if (auth()->user()->isAdminFromSession())
                @can('sendTest', $settings)
                    @if ($team->isNotificationEnabled('email'))
                        <x-modal-input buttonTitle="{{ __('modal.send_test_email') }}" title="{{ __('modal.send_test_email') }}">
                            <form wire:submit.prevent="sendTestEmail" class="flex flex-col w-full gap-2">
                                <x-forms.input wire:model="testEmailAddress" placeholder="{{ __('forms.placeholders.test_email') }}"
                                    id="testEmailAddress" label="{{ __('notification.recipient') }}" required />
                                <x-forms.button type="submit" @click="modalOpen=false">
                                    {{ __('notification.send_email') }}
                                </x-forms.button>
                            </form>
                        </x-modal-input>
                    @else
                        <x-forms.button disabled class="normal-case dark:text-white btn btn-xs no-animation btn-primary">
                            {{ __('modal.send_test_email') }}
                        </x-forms.button>
                    @endif
                @endcan
            @endif
        </div>
        @if (!isCloud())
            <div class="w-96">
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="instantSave()" id="useInstanceEmailSettings"
                    label="{{ __('notification.use_system_email') }}" />
            </div>
        @endif
        @if (!$useInstanceEmailSettings)
            <div class="flex gap-2">
                <x-forms.input canGate="update" :canResource="$settings" required id="smtpFromName" helper="{{ __('notification.from_name_helper') }}" label="{{ __('notification.from_name') }}" />
                <x-forms.input canGate="update" :canResource="$settings" required id="smtpFromAddress" helper="{{ __('notification.from_address_helper') }}"
                    label="{{ __('notification.from_address') }}" />
            </div>
            @if (isInstanceAdmin() && !$useInstanceEmailSettings)
                <x-forms.button canGate="update" :canResource="$settings" wire:click='copyFromInstanceSettings'>
                    {{ __('notification.copy_from_instance') }}
                </x-forms.button>
            @endif
        @endif
    </form>
    @if (isCloud())
        <div class="w-64 py-4">
            <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="instantSave()" id="useInstanceEmailSettings"
                label="{{ __('notification.use_hosted_email') }}" />
        </div>
    @endif
    @if (!$useInstanceEmailSettings)
        <div class="flex flex-col gap-4">
            <form wire:submit='submitSmtp'
                class="p-4 border dark:border-coolgray-300 border-neutral-200 rounded-lg flex flex-col gap-2">
                <div class="flex items-center gap-2">
                    <h3>{{ __('notification.smtp_server') }}</h3>
                    <x-forms.button canGate="update" :canResource="$settings" type="submit">
                        {{ __('common.save') }}
                    </x-forms.button>
                </div>
                <div class="w-32">
                    <x-forms.checkbox canGate="update" :canResource="$settings" wire:model="smtpEnabled" instantSave="instantSave('SMTP')" id="smtpEnabled"
                        label="{{ __('common.enabled') }}" />
                </div>
                <div class="flex flex-col">
                    <div class="flex flex-col gap-4">
                        <div class="flex flex-col w-full gap-2 xl:flex-row">
                            <x-forms.input canGate="update" :canResource="$settings" required id="smtpHost" placeholder="{{ __('forms.placeholders.smtp_host') }}" label="{{ __('forms.host') }}" />
                            <x-forms.input canGate="update" :canResource="$settings" required id="smtpPort" placeholder="{{ __('forms.placeholders.smtp_port') }}" label="{{ __('forms.port') }}" />
                            <x-forms.select canGate="update" :canResource="$settings" required id="smtpEncryption" label="{{ __('forms.encryption') }}">
                                <option value="starttls">{{ __('forms.starttls') }}</option>
                                <option value="tls">{{ __('forms.tls_ssl') }}</option>
                                <option value="none">{{ __('forms.none') }}</option>
                            </x-forms.select>
                        </div>
                        <div class="flex flex-col w-full gap-2 xl:flex-row">
                            <x-forms.input canGate="update" :canResource="$settings" id="smtpUsername" label="{{ __('forms.smtp_username') }}" />
                            <x-forms.input canGate="update" :canResource="$settings" id="smtpPassword" type="password" label="{{ __('forms.smtp_password') }}" />
                            <x-forms.input canGate="update" :canResource="$settings" id="smtpTimeout" helper="{{ __('notification.timeout_helper') }}"
                                label="{{ __('forms.timeout') }}" />
                        </div>
                    </div>
                </div>
            </form>
            <form wire:submit='submitResend'
                class="p-4 border dark:border-coolgray-300 border-neutral-200 rounded-lg flex flex-col gap-2">
                <div class="flex items-center gap-2">
                    <h3>{{ __('notification.resend') }}</h3>
                    <x-forms.button canGate="update" :canResource="$settings" type="submit">
                        {{ __('common.save') }}
                    </x-forms.button>
                </div>
                <div class="w-32">
                    <x-forms.checkbox canGate="update" :canResource="$settings" wire:model="resendEnabled" instantSave="instantSave('Resend')" id="resendEnabled"
                        label="{{ __('common.enabled') }}" />
                </div>
                <div class="flex flex-col">
                    <div class="flex flex-col gap-4">
                        <div class="flex flex-col w-full gap-2 xl:flex-row">
                            <x-forms.input canGate="update" :canResource="$settings" required type="password" id="resendApiKey" placeholder="{{ __('forms.placeholders.api_key') }}"
                                label="{{ __('forms.api_key') }}" />
                        </div>
                    </div>
                </div>
            </form>
        </div>
    @endif
    <h2 class="mt-4">{{ __('notification.settings') }}</h2>
    <p class="mb-4">
        {{ __('notification.select_events_hint') }}
    </p>
    <div class="flex flex-col gap-4 max-w-2xl">
        <div class="border dark:border-coolgray-300 border-neutral-200 p-4 rounded-lg">
            <h3 class="font-medium mb-3">{{ __('notification.deployments') }}</h3>
            <div class="flex flex-col gap-1.5 pl-1">
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="deploymentSuccessEmailNotifications"
                    label="{{ __('notification.deployment_success') }}" />
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="deploymentFailureEmailNotifications"
                    label="{{ __('notification.deployment_failure') }}" />
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel"
                    helper="{{ __('notification.status_change_hint') }}"
                    id="statusChangeEmailNotifications" label="{{ __('notification.container_status_changes') }}" />
            </div>
        </div>
        <div class="border dark:border-coolgray-300 border-neutral-200 p-4 rounded-lg">
            <h3 class="font-medium mb-3">{{ __('notification.backups') }}</h3>
            <div class="flex flex-col gap-1.5 pl-1">
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="backupSuccessEmailNotifications"
                    label="{{ __('notification.backup_success') }}" />
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="backupFailureEmailNotifications"
                    label="{{ __('notification.backup_failure') }}" />
            </div>
        </div>
        <div class="border dark:border-coolgray-300 border-neutral-200 p-4 rounded-lg">
            <h3 class="font-medium mb-3">{{ __('notification.scheduled_tasks') }}</h3>
            <div class="flex flex-col gap-1.5 pl-1">
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="scheduledTaskSuccessEmailNotifications"
                    label="{{ __('notification.scheduled_task_success') }}" />
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="scheduledTaskFailureEmailNotifications"
                    label="{{ __('notification.scheduled_task_failure') }}" />
            </div>
        </div>
        <div class="border dark:border-coolgray-300 border-neutral-200 p-4 rounded-lg">
            <h3 class="font-medium mb-3">{{ __('notification.server') }}</h3>
            <div class="flex flex-col gap-1.5 pl-1">
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="dockerCleanupSuccessEmailNotifications"
                    label="{{ __('notification.docker_cleanup_success') }}" />
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="dockerCleanupFailureEmailNotifications"
                    label="{{ __('notification.docker_cleanup_failure') }}" />
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="serverDiskUsageEmailNotifications"
                    label="{{ __('notification.server_disk_usage') }}" />
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="serverReachableEmailNotifications"
                    label="{{ __('notification.server_reachable') }}" />
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="serverUnreachableEmailNotifications"
                    label="{{ __('notification.server_unreachable') }}" />
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="serverPatchEmailNotifications"
                    label="{{ __('notification.server_patching') }}" />
                <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="saveModel" id="traefikOutdatedEmailNotifications"
                    label="{{ __('notification.traefik_outdated') }}" />
            </div>
        </div>
    </div>
</div>
