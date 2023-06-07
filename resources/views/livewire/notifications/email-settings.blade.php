<div>
    <form wire:submit.prevent='submit' class="flex flex-col">
        <div class="flex items-center gap-2">
            <h2>Email</h2>
            <x-forms.button type="submit">
                Save
            </x-forms.button>
            @if (auth()->user()->isAdmin())
                <x-forms.button wire:click='copySMTP'>
                    Copy from Instance Settings
                </x-forms.button>
            @endif
            <x-forms.button class="text-white normal-case btn btn-xs no-animation btn-primary"
                wire:click="sendTestNotification">
                Send Test Notifications
            </x-forms.button>
        </div>
        <div class="flex flex-col w-96">
            <x-forms.checkbox instantSave id="model.extra_attributes.smtp_active" label="Notification Enabled" />
        </div>

        <div class="flex gap-2">
            <x-forms.input required id="model.extra_attributes.smtp_recipients"
                helper="Email list to send the all notifications to, separated by comma." label="Recipient(s)" />
            <x-forms.input id="model.extra_attributes.smtp_test_recipients" label="Test Notification Recipient(s)"
                helper="Email list to send the test notification to, separated by comma." />
        </div>
        <div class="flex flex-col gap-2 xl:flex-row">
            <div class="flex flex-col w-96">
                <x-forms.input required id="model.extra_attributes.smtp_host" helper="SMTP Hostname"
                    placeholder="smtp.mailgun.org" label="Host" />
                <x-forms.input required id="model.extra_attributes.smtp_port" helper="SMTP Port" placeholder="587"
                    label="Port" />
                <x-forms.input helper="If SMTP through SSL, set it to 'tls'." placeholder="tls"
                    id="model.extra_attributes.smtp_encryption" label="Encryption" />
            </div>
            <div class="flex flex-col w-96">
                <x-forms.input id="model.extra_attributes.smtp_username" helper="SMTP Username" label="Username" />
                <x-forms.input type="password" helper="SMTP Password" id="model.extra_attributes.smtp_password"
                    label="Password" />
                <x-forms.input id="model.extra_attributes.smtp_timeout" helper="Timeout value for sending emails."
                    label="Timeout" />
            </div>
            <div class="flex flex-col w-96">
                <x-forms.input required id="model.extra_attributes.smtp_from_name" helper="Name used in emails."
                    label="From Name" />
                <x-forms.input required id="model.extra_attributes.smtp_from_address"
                    helper="Email address used in emails." label="From Address" />
            </div>
        </div>
    </form>
</div>
