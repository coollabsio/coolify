<div>
    <div class="flex items-center gap-2">
        <h2>Transactional Email</h2>
    </div>
    <div class="pb-4 ">Email settings for password resets, invitations, etc.</div>
    <form wire:submit='submitFromFields' class="flex flex-col gap-2 pb-4">
        <x-forms.input required id="settings.smtp_from_name" helper="Name used in emails." label="From Name" />
        <x-forms.input required id="settings.smtp_from_address" helper="Email address used in emails."
            label="From Address" />
        <x-forms.button type="submit">
            Save
        </x-forms.button>
    </form>
    <div class="flex flex-col gap-4">
        <div class="p-4 border dark:border-coolgray-300">
            <form wire:submit='submit' class="flex flex-col">
                <div class="flex gap-2">
                    <h3>SMTP Server</h3>
                    <x-forms.button type="submit">
                        Save
                    </x-forms.button>
                </div>
                <div class="w-32">
                    <x-forms.checkbox instantSave id="settings.smtp_enabled" label="Enabled" />
                </div>
                <div class="flex flex-col gap-4">
                    <div class="flex flex-col w-full gap-2 xl:flex-row">
                        <x-forms.input required id="settings.smtp_host" placeholder="smtp.mailgun.org" label="Host" />
                        <x-forms.input required id="settings.smtp_port" placeholder="587" label="Port" />
                        <x-forms.input id="settings.smtp_encryption" helper="If SMTP uses SSL, set it to 'tls'."
                            placeholder="tls" label="Encryption" />
                    </div>
                    <div class="flex flex-col w-full gap-2 xl:flex-row">
                        <x-forms.input id="settings.smtp_username" label="SMTP Username" />
                        <x-forms.input id="settings.smtp_password" type="password" label="SMTP Password" />
                        <x-forms.input id="settings.smtp_timeout" helper="Timeout value for sending emails."
                            label="Timeout" />
                    </div>
                </div>
            </form>
        </div>
        <div class="p-4 border dark:border-coolgray-300">
            <form wire:submit='submitResend' class="flex flex-col">
                <div class="flex gap-2">
                    <h3>Resend</h3>
                    <x-forms.button type="submit">
                        Save
                    </x-forms.button>
                </div>
                <div class="w-32">
                    <x-forms.checkbox instantSave='instantSaveResend' id="settings.resend_enabled" label="Enabled" />
                </div>
                <div class="flex flex-col gap-4">
                    <div class="flex flex-col w-full gap-2 xl:flex-row">
                        <x-forms.input type="password" id="settings.resend_api_key" placeholder="API key" required
                            label="Host" />
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
