<div>
    <x-slot:title>
        Notifications | Coolify
    </x-slot>
    <x-notification.navbar />
    <form wire:submit='submit' class="flex flex-col gap-4 pb-4">
        <div class="flex items-center gap-2">
            <h2>Email</h2>
            <x-forms.button type="submit">
                Save
            </x-forms.button>
            @if (auth()->user()->isAdminFromSession())
                @if ($team->isNotificationEnabled('email'))
                    <x-modal-input buttonTitle="Send Test Email" title="Send Test Email">
                        <form wire:submit.prevent="sendTestEmail" class="flex flex-col w-full gap-2">
                            <x-forms.input wire:model="testEmailAddress" placeholder="test@example.com"
                                id="testEmailAddress" label="Recipients" required />
                            <x-forms.button type="submit" @click="modalOpen=false">
                                Send Email
                            </x-forms.button>
                        </form>
                    </x-modal-input>
                @else
                    <x-forms.button disabled class="normal-case dark:text-white btn btn-xs no-animation btn-primary">
                        Send Test Email
                    </x-forms.button>
                @endif
            @endif
        </div>
        @if (!isCloud())
            <div class="w-96">
                <x-forms.checkbox instantSave="instantSave()" id="useInstanceEmailSettings"
                    label="Use system wide (transactional) email settings" />
            </div>
        @endif
        @if (!$useInstanceEmailSettings)
            <div class="flex gap-4">
                <x-forms.input required id="smtpFromName" helper="Name used in emails." label="From Name" />
                <x-forms.input required id="smtpFromAddress" helper="Email address used in emails."
                    label="From Address" />
            </div>
            @if (isInstanceAdmin() && !$useInstanceEmailSettings)
                <x-forms.button wire:click='copyFromInstanceSettings'>
                    Copy from Instance Settings
                </x-forms.button>
            @endif
        @endif
    </form>
    @if (isCloud())
        <div class="w-64 py-4">
            <x-forms.checkbox instantSave="instantSave()" id="useInstanceEmailSettings"
                label="Use Hosted Email Service" />
        </div>
    @endif
    @if (!$useInstanceEmailSettings)
        <div class="flex flex-col gap-4">
            <form wire:submit='submitSmtp' class="p-4 border dark:border-coolgray-300 flex flex-col gap-2">
                <div class="flex items-center gap-2">
                    <h3>SMTP Server</h3>
                    <x-forms.button type="submit">
                        Save
                    </x-forms.button>
                </div>
                <div class="w-32">
                    <x-forms.checkbox wire:model="smtpEnabled" instantSave="instantSave('SMTP')" id="smtpEnabled"
                        label="Enabled" />
                </div>
                <div class="flex flex-col">
                    <div class="flex flex-col gap-4">
                        <div class="flex flex-col w-full gap-2 xl:flex-row">
                            <x-forms.input required id="smtpHost" placeholder="smtp.mailgun.org" label="Host" />
                            <x-forms.input required id="smtpPort" placeholder="587" label="Port" />
                            <x-forms.select required id="smtpEncryption" label="Encryption">
                                <option value="starttls">StartTLS</option>
                                <option value="tls">TLS/SSL</option>
                                <option value="none">None</option>
                            </x-forms.select>
                        </div>
                        <div class="flex flex-col w-full gap-2 xl:flex-row">
                            <x-forms.input id="smtpUsername" label="SMTP Username" />
                            <x-forms.input id="smtpPassword" type="password" label="SMTP Password" />
                            <x-forms.input id="smtpTimeout" helper="Timeout value for sending emails."
                                label="Timeout" />
                        </div>
                    </div>
                </div>
            </form>
            <form wire:submit='submitResend' class="p-4 border dark:border-coolgray-300 flex flex-col gap-2">
                <div class="flex items-center gap-2">
                    <h3>Resend</h3>
                    <x-forms.button type="submit">
                        Save
                    </x-forms.button>
                </div>
                <div class="w-32">
                    <x-forms.checkbox wire:model="resendEnabled" instantSave="instantSave('Resend')" id="resendEnabled"
                        label="Enabled" />
                </div>
                <div class="flex flex-col">
                    <div class="flex flex-col gap-4">
                        <div class="flex flex-col w-full gap-2 xl:flex-row">
                            <x-forms.input required type="password" id="resendApiKey" placeholder="API key"
                                label="API Key" />
                        </div>
                    </div>
                </div>
            </form>
        </div>
    @endif
    <h2 class="mt-4">Notification Settings</h2>
    <p class="mb-4">
        Select events for which you would like to receive email notifications.
    </p>
    <div class="flex flex-col gap-4 max-w-2xl">
        <div class="border dark:border-coolgray-300 p-4 rounded-lg">
            <h3 class="font-medium mb-3">Deployments</h3>
            <div class="flex flex-col gap-1.5 pl-1">
                <x-forms.checkbox instantSave="saveModel" id="deploymentSuccessEmailNotifications"
                    label="Deployment Success" />
                <x-forms.checkbox instantSave="saveModel" id="deploymentFailureEmailNotifications"
                    label="Deployment Failure" />
                <x-forms.checkbox instantSave="saveModel"
                    helper="Send an email when a container status changes. It will send and email for Stopped and Restarted events of a container."
                    id="statusChangeEmailNotifications" label="Container Status Changes" />
            </div>
        </div>
        <div class="border dark:border-coolgray-300 p-4 rounded-lg">
            <h3 class="font-medium mb-3">Backups</h3>
            <div class="flex flex-col gap-1.5 pl-1">
                <x-forms.checkbox instantSave="saveModel" id="backupSuccessEmailNotifications"
                    label="Backup Success" />
                <x-forms.checkbox instantSave="saveModel" id="backupFailureEmailNotifications"
                    label="Backup Failure" />
            </div>
        </div>
        <div class="border dark:border-coolgray-300 p-4 rounded-lg">
            <h3 class="font-medium mb-3">Scheduled Tasks</h3>
            <div class="flex flex-col gap-1.5 pl-1">
                <x-forms.checkbox instantSave="saveModel" id="scheduledTaskSuccessEmailNotifications"
                    label="Scheduled Task Success" />
                <x-forms.checkbox instantSave="saveModel" id="scheduledTaskFailureEmailNotifications"
                    label="Scheduled Task Failure" />
            </div>
        </div>
        <div class="border dark:border-coolgray-300 p-4 rounded-lg">
            <h3 class="font-medium mb-3">Server</h3>
            <div class="flex flex-col gap-1.5 pl-1">
                <x-forms.checkbox instantSave="saveModel" id="dockerCleanupSuccessEmailNotifications"
                    label="Docker Cleanup Success" />
                <x-forms.checkbox instantSave="saveModel" id="dockerCleanupFailureEmailNotifications"
                    label="Docker Cleanup Failure" />
                <x-forms.checkbox instantSave="saveModel" id="serverDiskUsageEmailNotifications"
                    label="Server Disk Usage" />
                <x-forms.checkbox instantSave="saveModel" id="serverReachableEmailNotifications"
                    label="Server Reachable" />
                <x-forms.checkbox instantSave="saveModel" id="serverUnreachableEmailNotifications"
                    label="Server Unreachable" />
            </div>
        </div>
    </div>
</div>
