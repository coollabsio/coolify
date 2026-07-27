<div>
    <x-slot:title>
        Transactional Email | Coolify
    </x-slot>

    <x-settings.navbar>
        <x-slot:actions>
            @if (is_transactional_emails_enabled() && auth()->user()->isAdminFromSession())
                <x-modal-input title="Send Test Email">
                    <x-slot:content>
                        <button type="button" class="button">
                            <x-reicon name="notifications" class="size-3.5" />
                            Send test
                        </button>
                    </x-slot:content>
                    <form wire:submit.prevent="sendTestEmail" class="application-settings-form flex flex-col gap-4">
                        <x-forms.input wire:model="testEmailAddress" placeholder="test@example.com"
                            id="testEmailAddress" label="Recipient" required />
                        <div class="flex justify-end border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
                            <button type="submit" class="button" @click="modalOpen=false">Send email</button>
                        </div>
                    </form>
                </x-modal-input>
            @endif
        </x-slot:actions>
    </x-settings.navbar>

    <div class="application-settings-form flex w-full min-w-0 flex-col gap-6">
        <form wire:submit="submit">
            <x-unsaved-bar action="submit" />
            <x-application.settings-section title="Sender">
                <div class="grid gap-4 lg:grid-cols-2">
                    <x-forms.input required id="smtpFromName" helper="Name shown in outgoing email."
                        label="From name" />
                    <x-forms.input required id="smtpFromAddress" helper="Address used for outgoing email."
                        label="From address" />
                </div>
            </x-application.settings-section>
        </form>

        <form wire:submit.prevent="submitSmtp">
            <x-unsaved-bar action="submitSmtp" />
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
                </div>
            </x-application.settings-section>
        </form>

        <form wire:submit.prevent="submitResend">
            <x-unsaved-bar action="submitResend" />
            <x-application.settings-section title="Resend">
                <div class="grid gap-4 lg:grid-cols-2">
                    <x-forms.listbox id="resendEnabled" label="Resend delivery"
                        onChange="instantSaveResend" :options="[
                            ['value' => true, 'label' => 'Enabled'],
                            ['value' => false, 'label' => 'Disabled'],
                        ]" />
                    <x-forms.input type="password" id="resendApiKey" placeholder="API key" required
                        label="API key" autocomplete="new-password" />
                </div>
            </x-application.settings-section>
        </form>
    </div>
</div>
