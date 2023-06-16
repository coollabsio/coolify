<div>
    <form wire:submit.prevent='submit' class="flex flex-col pb-10">
        <div class="flex items-center gap-2">
            <h3>Transactional Emails</h3>
            <x-forms.button type="submit">
                Save
            </x-forms.button>
        </div>
        <div class="pt-2 pb-4 ">SMTP settings for password resets, invitations, etc.</div>
        <div class="flex flex-col">
            <x-forms.checkbox instantSave id="settings.extra_attributes.smtp_active" label="Enabled" />
        </div>
        <div class="flex items-end gap-2">
            <x-forms.input id="settings.extra_attributes.smtp_test_recipients" label="Test Recipients"
                helper="Email list to send a test email to, separated by comma." />
            @if ($settings->extra_attributes->smtp_active)
                <x-forms.button wire:click='test_email'>
                    Send Test Email
                </x-forms.button>
            @endif
        </div>
        <div class="flex flex-col gap-2 xl:flex-row">
            <div class="flex flex-col w-full">
                <x-forms.input required id="settings.extra_attributes.smtp_host" helper="SMTP Hostname"
                    placeholder="smtp.mailgun.org" label="Host" />
                <x-forms.input required id="settings.extra_attributes.smtp_port" helper="SMTP Port" placeholder="587"
                    label="Port" />
                <x-forms.input id="settings.extra_attributes.smtp_encryption"
                    helper="If SMTP through SSL, set it to 'tls'." placeholder="tls" label="Encryption" />
            </div>
            <div class="flex flex-col w-full">
                <x-forms.input id="settings.extra_attributes.smtp_username" helper="SMTP Username"
                    label="SMTP Username" />
                <x-forms.input id="settings.extra_attributes.smtp_password" type="password" helper="SMTP Password"
                    label="SMTP Password" />
                <x-forms.input id="settings.extra_attributes.smtp_timeout" helper="Timeout value for sending emails."
                    label="Timeout" />
            </div>
            <div class="flex flex-col w-full">
                <x-forms.input required id="settings.extra_attributes.smtp_from_name" helper="Name used in emails."
                    label="From Name" />
                <x-forms.input required id="settings.extra_attributes.smtp_from_address"
                    helper="Email address used in emails." label="From Address" />
            </div>
        </div>
    </form>
</div>
