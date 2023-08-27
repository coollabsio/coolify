<div>
    <dialog id="sendTestEmail" class="modal">
        <form method="dialog" class="flex flex-col gap-2 rounded modal-box" wire:submit.prevent='submit'>
            <x-forms.input placeholder="test@example.com" id="emails" label="Recepients" required />
            <x-forms.button onclick="sendTestEmail.close()" wire:click="sendTestNotification">
                Send Email
            </x-forms.button>
        </form>
        <form method="dialog" class="modal-backdrop">
            <button>close</button>
        </form>
    </dialog>
    <form wire:submit.prevent='submit' class="flex flex-col">
        <div class="flex items-center gap-2">
            <h2>Email</h2>
            <x-forms.button type="submit">
                Save
            </x-forms.button>
            @if (isInstanceAdmin())
                <x-forms.button wire:click='copyFromInstanceSettings'>
                    Copy from Instance Settings
                </x-forms.button>
            @endif
            @if ($model->smtp_enabled)
                <x-forms.button onclick="sendTestEmail.showModal()"
                    class="text-white normal-case btn btn-xs no-animation btn-primary">
                    Send Test Email
                </x-forms.button>
            @endif
        </div>
        <div class="w-48">
            <x-forms.checkbox instantSave id="model.smtp_enabled" label="Notification Enabled" />
        </div>
        <div class="flex flex-col gap-4">
            <div class="flex flex-col gap-2 xl:flex-row">
                <x-forms.input id="model.smtp_recipients"
                    placeholder="If empty, all users will be notified in the team."
                    helper="Email list to send the all notifications to, separated by comma." label="Recipients" />
            </div>
            <div class="flex flex-col gap-2 xl:flex-row">
                <x-forms.input required id="model.smtp_host" helper="SMTP Hostname" placeholder="smtp.mailgun.org"
                    label="Host" />
                <x-forms.input required id="model.smtp_port" helper="SMTP Port" placeholder="587" label="Port" />
                <x-forms.input helper="If SMTP through SSL, set it to 'tls'." placeholder="tls"
                    id="model.smtp_encryption" label="Encryption" />
            </div>
            <div class="flex flex-col gap-2 xl:flex-row">
                <x-forms.input id="model.smtp_username" label="SMTP Username" />
                <x-forms.input type="password" id="model.smtp_password" label="SMTP Password" />
                <x-forms.input id="model.smtp_timeout" helper="Timeout value for sending emails." label="Timeout" />
            </div>
            <div class="flex flex-col gap-2 xl:flex-row">
                <x-forms.input required id="model.smtp_from_name" helper="Name used in emails." label="From Name" />
                <x-forms.input required id="model.smtp_from_address" helper="Email address used in emails."
                    label="From Address" />
            </div>
        </div>
    </form>
    @if (data_get($model, 'smtp_enabled'))
        <h4 class="mt-4">Subscribe to events</h4>
        <div class="w-64">
            @if (isDev())
                <x-forms.checkbox instantSave="saveModel" id="model.smtp_notifications_test" label="Test" />
            @endif
            <h4 class="mt-4">General</h4>
            <x-forms.checkbox instantSave="saveModel" id="model.smtp_notifications_status_changes"
                label="Container Status Changes" />
            <h4 class="mt-4">Applications</h4>
            <x-forms.checkbox instantSave="saveModel" id="model.smtp_notifications_deployments" label="Deployments" />
            <h4 class="mt-4">Databases</h4>
            <x-forms.checkbox instantSave="saveModel" id="model.smtp_notifications_database_backups"
                label="Backup Statuses" />
        </div>
    @endif
</div>
