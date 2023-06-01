<div>
    <form wire:submit.prevent='submit' class="flex flex-col mt-2">
        <div class="flex items-center gap-2">
            <h3>E-mail (SMTP)</h3>
            <x-forms.button class="w-16 mt-4" type="submit">
                Save
            </x-forms.button>
        </div>
        <div class="flex flex-col w-96">
            <x-forms.checkbox instantSave id="model.extra_attributes.smtp_active" label="Notification Enabled" />
        </div>
        <x-forms.input id="model.extra_attributes.test_notification_email" label="Test Notification Email" />
        <div class="flex flex-col gap-2 xl:flex-row">
            <div class="flex flex-col w-96">
                <x-forms.input required id="model.extra_attributes.recipients" helper="Emails separated by comma"
                    label="Recipients" />
            </div>
        </div>
        <div class="flex flex-col gap-2 xl:flex-row">
            <div class="flex flex-col w-96">
                <x-forms.input required id="model.extra_attributes.smtp_host" label="Host" />
                <x-forms.input required id="model.extra_attributes.smtp_port" label="Port" />
                <x-forms.input id="model.extra_attributes.smtp_encryption" label="Encryption" />
            </div>
            <div class="flex flex-col w-96">
                <x-forms.input id="model.extra_attributes.smtp_username" label="Username" />
                <x-forms.input id="model.extra_attributes.smtp_password" label="Password" />
                <x-forms.input id="model.extra_attributes.smtp_timeout" label="Timeout" />
            </div>
            <div class="flex flex-col w-96">
                <x-forms.input required id="model.extra_attributes.from_address" label="From Address" />
                <x-forms.input required id="model.extra_attributes.from_name" label="From Name" />
            </div>
        </div>
    </form>
</div>
