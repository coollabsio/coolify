<div>
    <x-slot:title>
        Transactional Email | Coolify
    </x-slot>
    <x-settings.navbar />
    <form wire:submit='submit' class="flex flex-col pb-4">
        <div class="form-card">
            <div class="form-section-title">
                <h2>Transactional Email</h2>
                <div class="flex items-center gap-2">
                    <x-forms.button type="submit">
                        Save
                    </x-forms.button>
                    @if (is_transactional_emails_enabled() && auth()->user()->isAdminFromSession())
                        <x-modal-input buttonTitle="Send Test Email" title="Send Test Email">
                            <form wire:submit.prevent="sendTestEmail" class="flex flex-col w-full gap-8">
                                <x-forms.input wire:model="testEmailAddress" placeholder="test@example.com" id="testEmailAddress"
                                    label="Recipient" required />
                                <x-forms.button type="submit" @click="modalOpen=false">
                                    Send Email
                                </x-forms.button>
                            </form>
                        </x-modal-input>
                    @endif
                </div>
            </div>
            <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">Instance wide email settings for password resets, invitations, etc.</p>
            <div class="flex gap-2 mt-4">
                <x-forms.input required id="smtpFromName" helper="Name used in emails." label="From Name" />
                <x-forms.input required id="smtpFromAddress" helper="Email address used in emails." label="From Address" />
            </div>
        </div>
    </form>
    <div class="flex flex-col gap-6">
        <div class="form-subsection">
            <form wire:submit.prevent="submitSmtp" class="flex flex-col">
                <div class="form-section-title">
                    <h3>SMTP Server</h3>
                    <x-forms.button type="submit">
                        Save
                    </x-forms.button>
                </div>
                <div class="w-32 mt-4">
                    <x-forms.checkbox instantSave='instantSave("SMTP")' id="smtpEnabled" label="Enabled" />
                </div>
                <div class="flex flex-col gap-10 mt-4">
                    <div class="flex flex-col w-full gap-8 xl:flex-row">
                        <x-forms.input required id="smtpHost" placeholder="smtp.mailgun.org" label="Host" />
                        <x-forms.input required id="smtpPort" type="number" placeholder="587" label="Port" />
                        <x-forms.select required id="smtpEncryption" label="Encryption">
                            <option value="starttls">StartTLS</option>
                            <option value="tls">TLS/SSL</option>
                            <option value="none">None</option>
                        </x-forms.select>
                    </div>
                    <div class="flex flex-col w-full gap-8 xl:flex-row">
                        <x-forms.input id="smtpUsername" label="SMTP Username" />
                        <x-forms.input id="smtpPassword" type="password" label="SMTP Password"
                            autocomplete="new-password" />
                        <x-forms.input id="smtpTimeout" helper="Timeout value for sending emails." label="Timeout" />
                    </div>
                </div>
            </form>
        </div>
        <div class="form-subsection">
            <form wire:submit.prevent="submitResend" class="flex flex-col">
                <div class="form-section-title">
                    <h3>Resend</h3>
                    <x-forms.button type="submit">
                        Save
                    </x-forms.button>
                </div>
                <div class="w-32 mt-4">
                    <x-forms.checkbox instantSave='instantSave("Resend")' id="resendEnabled" label="Enabled" />
                </div>
                <div class="flex flex-col gap-10 mt-4">
                    <div class="flex flex-col w-full gap-8 xl:flex-row">
                        <x-forms.input type="password" id="resendApiKey" placeholder="API key" required label="API Key"
                            autocomplete="new-password" />
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
