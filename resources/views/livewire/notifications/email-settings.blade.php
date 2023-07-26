<div>
    <form wire:submit.prevent='submit' class="flex flex-col">
        <div class="flex items-center gap-2">
            <h2>Email</h2>
            <x-forms.button type="submit">
                Save
            </x-forms.button>
            @if (auth()->user()->isInstanceAdmin())
                <x-forms.button wire:click='copyFromInstanceSettings'>
                    Copy from Instance Settings
                </x-forms.button>
            @endif
            @if ($model->smtp->enabled)
                <x-forms.button class="text-white normal-case btn btn-xs no-animation btn-primary"
                    wire:click="sendTestNotification">
                    Send Test Notifications
                </x-forms.button>
            @endif
        </div>
        <div class="w-48">
            <x-forms.checkbox instantSave id="model.smtp.enabled" label="Notification Enabled" />
        </div>

        <div class="flex flex-col gap-2 xl:flex-row">
            <x-forms.input id="model.smtp.recipients" placeholder="If empty, all users will be notified in the team."
                helper="Email list to send the all notifications to, separated by comma." label="Recipients" />
            <x-forms.input id="model.smtp.test_recipients" label="Test Notification Recipients"
                placeholder="If empty, all users will be notified in the team."
                helper="Email list to send the test notification to, separated by comma." />
        </div>
        <div class="flex flex-col gap-2 xl:flex-row">
            <x-forms.input required id="model.smtp.host" helper="SMTP Hostname" placeholder="smtp.mailgun.org"
                label="Host" />
            <x-forms.input required id="model.smtp.port" helper="SMTP Port" placeholder="587" label="Port" />
            <x-forms.input helper="If SMTP through SSL, set it to 'tls'." placeholder="tls" id="model.smtp.encryption"
                label="Encryption" />
        </div>
        <div class="flex flex-col gap-2 xl:flex-row">
            <x-forms.input id="model.smtp.username" helper="SMTP Username" label="SMTP Username" />
            <x-forms.input type="password" helper="SMTP Password" id="model.smtp.password" label="SMTP Password" />
            <x-forms.input id="model.smtp.timeout" helper="Timeout value for sending emails." label="Timeout" />
        </div>
        <div class="flex flex-col gap-2 xl:flex-row">
            <x-forms.input required id="model.smtp.from_name" helper="Name used in emails." label="From Name" />
            <x-forms.input required id="model.smtp.from_address" helper="Email address used in emails."
                label="From Address" />
        </div>
    </form>
    @if (data_get($model, 'smtp.enabled'))
        <h4 class="mt-4">Subscribe to events</h4>
        <div class="w-64">
            @if (isDev())
                <x-forms.checkbox instantSave="saveModel" id="model.smtp_notifications.test"
                    label="Test Notifications" />
            @endif
            <h5 class="mt-4">Applications</h5>
            <x-forms.checkbox instantSave="saveModel" id="model.smtp_notifications.deployments"
                label="New Deployment" />
            <x-forms.checkbox instantSave="saveModel" id="model.smtp_notifications.status_changes"
                label="Status Changes" />
        </div>
    @endif
</div>
