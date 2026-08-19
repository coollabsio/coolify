<div>
    <x-slot:title>
        Transactional Email | Coolify
    </x-slot>

    <x-settings.layout>
    <div class="application-settings-form mx-auto flex w-full max-w-none min-w-0 flex-col gap-6">
        {{-- One bar for the whole page. Three stacked bars made Save run
             submitResend(), which required an API key even when Resend was off. --}}
        <x-unsaved-bar action="submit"
            targets="smtpFromName,smtpFromAddress,smtpHost,smtpPort,smtpEncryption,smtpUsername,smtpPassword,smtpTimeout,smtpEhloDomain,resendApiKey" />

        <form wire:submit="submit">
            <x-application.settings-section title="Sender">
                <x-slot:actions>
                    @include('livewire.partials.settings-email-send-test')
                </x-slot:actions>
                <div class="grid gap-4 lg:grid-cols-2">
                    <x-forms.input required id="smtpFromName" helper="Name shown in outgoing email."
                        label="From name" />
                    <x-forms.input required id="smtpFromAddress" helper="Address used for outgoing email."
                        label="From address" />
                </div>
            </x-application.settings-section>
        </form>

        <form wire:submit.prevent="submitSmtp">
            <x-application.settings-section title="SMTP server">
                <div class="grid gap-4 lg:grid-cols-3">
                    <div class="lg:col-span-3">
                        <div class="w-full sm:w-72">
                            <x-forms.listbox id="smtpEnabled" label="SMTP delivery"
                                onChange="instantSaveSmtp" :options="[
                                    ['value' => true, 'label' => 'Enabled'],
                                    ['value' => false, 'label' => 'Disabled'],
                                ]" />
                        </div>
                    </div>
                    <x-forms.input required id="smtpHost" placeholder="smtp.mailgun.org" label="Host" />
                    <x-forms.input required id="smtpPort" type="number" placeholder="587" label="Port" />
                    <x-forms.listbox required id="smtpEncryption" label="Encryption" :options="[
                        ['value' => 'starttls', 'label' => 'StartTLS'],
                        ['value' => 'tls', 'label' => 'TLS / SSL'],
                        ['value' => 'none', 'label' => 'None'],
                    ]" />
                    <x-forms.input id="smtpUsername" label="Username" />
                    <x-forms.input id="smtpPassword" type="password" label="Password"
                        autocomplete="new-password" />
                    <x-forms.input id="smtpTimeout" type="number"
                        helper="Maximum delivery time in seconds." label="Timeout" />
                    <x-forms.input id="smtpEhloDomain" placeholder="coolify.example.com"
                        helper="Fully qualified domain sent in the SMTP EHLO command. Uses the system default when empty."
                        label="EHLO domain" />
                </div>
            </x-application.settings-section>
        </form>

        <form wire:submit.prevent="submitResend">
            <x-application.settings-section title="Resend">
                <div class="grid gap-4 lg:grid-cols-2">
                    <x-forms.listbox id="resendEnabled" label="Resend delivery"
                        onChange="instantSaveResend" :options="[
                            ['value' => true, 'label' => 'Enabled'],
                            ['value' => false, 'label' => 'Disabled'],
                        ]" />
                    <x-forms.input type="password" id="resendApiKey" placeholder="API key"
                        :required="$resendEnabled" label="API key" autocomplete="new-password" />
                </div>
            </x-application.settings-section>
        </form>
    </div>
    </x-settings.layout>
</div>
