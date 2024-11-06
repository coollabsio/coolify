<div>
    <x-slot:title>
        Transactional Email | Coolify
    </x-slot>
    <x-settings.navbar />
    <form wire:submit='submit' class="flex flex-col gap-2 pb-4">
        <div class="flex items-center gap-2">
            <h2>Transactional Email</h2>
            <x-forms.button type="submit">
                Save
            </x-forms.button>
        </div>
        <div class="pb-4 ">Email settings for password resets, invitations, etc.</div>
        <div class="flex  gap-4">
            <x-forms.input required id="smtpFromName" helper="Name used in emails." label="From Name" />
            <x-forms.input required id="smtpFromAddress" helper="Email address used in emails." label="From Address" />
        </div>

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
                    <x-forms.checkbox instantSave='instantSave("SMTP")' id="smtpEnabled" label="Enabled" />
                </div>
                <div class="flex flex-col gap-4">
                    <div class="flex flex-col w-full gap-2 xl:flex-row">
                        <x-forms.input required id="smtpHost" placeholder="smtp.mailgun.org" label="Host" />
                        <x-forms.input required id="smtpPort" placeholder="587" label="Port" />
                        <x-forms.input id="smtpEncryption" helper="If SMTP uses SSL, set it to 'tls'." placeholder="tls"
                            label="Encryption" />
                    </div>
                    <div class="flex flex-col w-full gap-2 xl:flex-row">
                        <x-forms.input id="smtpUsername" label="SMTP Username" />
                        <x-forms.input id="smtpPassword" type="password" label="SMTP Password"
                            autocomplete="new-password" />
                        <x-forms.input id="smtpTimeout" helper="Timeout value for sending emails." label="Timeout" />
                    </div>
                </div>
            </form>
        </div>
        <div class="p-4 border dark:border-coolgray-300">
            <form wire:submit='submit' class="flex flex-col">
                <div class="flex gap-2">
                    <h3>Resend</h3>
                    <x-forms.button type="submit">
                        Save
                    </x-forms.button>
                </div>
                <div class="w-32">
                    <x-forms.checkbox instantSave='instantSave("Resend")' id="resendEnabled" label="Enabled" />
                </div>
                <div class="flex flex-col gap-4">
                    <div class="flex flex-col w-full gap-2 xl:flex-row">
                        <x-forms.input type="password" id="resendApiKey" placeholder="API key" required label="API Key"
                            autocomplete="new-password" />
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
