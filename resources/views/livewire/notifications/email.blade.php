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
            @if (isInstanceAdmin() && !$useInstanceEmailSettings)
                <x-forms.button wire:click='copyFromInstanceSettings'>
                    Copy from Instance Settings
                </x-forms.button>
            @endif
            @if (isEmailEnabled($team) && auth()->user()->isAdminFromSession() && isTestEmailEnabled($team))
                <x-modal-input buttonTitle="Send Test Email" title="Send Test Email">
                    <form wire:submit.prevent="sendTestEmail" class="flex flex-col w-full gap-2">
                        <x-forms.input wire:model="testEmailAddress" placeholder="test@example.com" id="testEmailAddress"
                            label="Recipients" required />
                        <x-forms.button type="submit" @click="modalOpen=false">
                            Send Email
                        </x-forms.button>
                    </form>
                </x-modal-input>
            @endif
        </div>
        @if (!isCloud())
            <div class="w-96">
                <x-forms.checkbox instantSave="instantSaveInstance" id="useInstanceEmailSettings"
                    label="Use system wide (transactional) email settings" />
            </div>
        @endif
        @if (!$useInstanceEmailSettings)
            <div class="flex gap-4">
                <x-forms.input required id="smtpFromName" helper="Name used in emails." label="From Name" />
                <x-forms.input required id="smtpFromAddress" helper="Email address used in emails."
                    label="From Address" />
            </div>
        @endif
    </form>
    @if (isCloud())
        <div class="w-64 py-4">
            <x-forms.checkbox instantSave="instantSaveInstance" id="useInstanceEmailSettings"
                label="Use Hosted Email Service" />
        </div>
    @endif
    @if (!$useInstanceEmailSettings)
        <div class="flex flex-col gap-4">
            <form wire:submit='submit' class="p-4 border dark:border-coolgray-300 flex flex-col gap-2">
                <div class="flex items-center gap-2">
                    <h3>SMTP Server</h3>
                    <x-forms.button type="submit">
                        Save
                    </x-forms.button>
                </div>
                <div class="w-32">
                    <x-forms.checkbox instantSave="instantSaveSmtpEnabled" id="smtpEnabled" label="Enabled" />
                </div>
                <div class="flex flex-col">
                    <div class="flex flex-col gap-4">
                        <div class="flex flex-col w-full gap-2 xl:flex-row">
                            <x-forms.input required id="smtpHost" placeholder="smtp.mailgun.org" label="Host" />
                            <x-forms.input required id="smtpPort" placeholder="587" label="Port" />
                            <x-forms.select id="smtpEncryption" label="Encryption">
                                <option value="tls">TLS</option>
                                <option value="ssl">SSL</option>
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
            <form wire:submit='submit' class="p-4 border dark:border-coolgray-300 flex flex-col gap-2">
                <div class="flex items-center gap-2">
                    <h3>Resend</h3>
                    <x-forms.button type="submit">
                        Save
                    </x-forms.button>
                </div>
                <div class="w-32">
                    <x-forms.checkbox instantSave='instantSaveResend' id="resendEnabled" label="Enabled" />
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
    @if (isEmailEnabled($team) || $useInstanceEmailSettings)
        <h2 class="mt-4">Subscribe to events</h2>
        <div class="w-64">
            @if (isDev())
                <x-forms.checkbox instantSave="saveModel" id="smtpNotificationsTest" label="Test" />
            @endif
            <x-forms.checkbox instantSave="saveModel" id="smtpNotificationsStatusChanges"
                label="Container Status Changes" />
            <x-forms.checkbox instantSave="saveModel" id="smtpNotificationsDeployments"
                label="Application Deployments" />
            <x-forms.checkbox instantSave="saveModel" id="smtpNotificationsDatabaseBackups" label="Backup Status" />
            <x-forms.checkbox instantSave="saveModel" id="smtpNotificationsScheduledTasks"
                label="Scheduled Tasks Status" />
            <x-forms.checkbox instantSave="saveModel" id="smtpNotificationsServerDiskUsage" label="Server Disk Usage" />
        </div>
    @endif
</div>
