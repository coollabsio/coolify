<div>
    <x-slot:title>
        Transactional Email | Coolify
    </x-slot>
    <x-settings.navbar />
    <div x-data="{ activeTab: window.location.hash ? window.location.hash.substring(1) : 'general' }"
        class="flex flex-col h-full gap-8 sm:flex-row">
        <x-settings.sidebar activeMenu="email" />
        <div class="flex flex-col w-full">
            <form wire:submit='submit' class="flex flex-col pb-4">
        <div class="flex items-center gap-2">
            <h2>Transactional Email</h2>
            <x-forms.button type="submit">
                Save
            </x-forms.button>
            @if (is_transactional_emails_enabled() && auth()->user()->isAdminFromSession())
                <x-modal-input buttonTitle="Send Test Email" title="Send Test Email">
                    <form wire:submit.prevent="sendTestEmail" class="flex flex-col w-full gap-2">
                        <x-forms.input wire:model="testEmailAddress" placeholder="test@example.com" id="testEmailAddress"
                            label="Recipient" required />
                        <x-forms.button type="submit" @click="modalOpen=false">
                            Send Email
                        </x-forms.button>
                    </form>
                </x-modal-input>
            @endif
        </div>
        <div class="pb-4">Instance wide email settings for password resets, invitations, etc.</div>
        <div class="flex gap-2">
            <x-forms.input required id="smtpFromName" helper="Name used in emails." label="From Name" />
            <x-forms.input required id="smtpFromAddress" helper="Email address used in emails." label="From Address" />
        </div>
    </form>
            <div class="flex flex-col gap-4">
            <form wire:submit.prevent="submitSmtp" class="flex flex-col">
                <div class="flex items-center gap-2">
                    <h3>SMTP Server</h3>
                    @if ($smtpEnabled)
                        <x-forms.button type="submit">
                            Save
                        </x-forms.button>
                        <x-forms.button wire:click="toggleSmtp">
                            Disable SMTP Server
                        </x-forms.button>
                    @else
                        <x-forms.button isHighlighted wire:click="toggleSmtp">
                            Enable SMTP Server
                        </x-forms.button>
                    @endif
                </div>
                <div class="flex flex-col gap-4">
                    <div class="flex flex-col w-full gap-2 xl:flex-row">
                        <x-forms.input required id="smtpHost" placeholder="smtp.mailgun.org" label="Host" />
                        <x-forms.input required id="smtpPort" type="number" placeholder="587" label="Port" />
                        <x-forms.select required id="smtpEncryption" label="Encryption">
                            <option value="starttls">StartTLS</option>
                            <option value="tls">TLS/SSL</option>
                            <option value="none">None</option>
                        </x-forms.select>
                    </div>
                    <div class="flex flex-col w-full gap-2 xl:flex-row">
                        <x-forms.input id="smtpUsername" label="SMTP Username" />
                        <x-forms.input id="smtpPassword" type="password" label="SMTP Password"
                            autocomplete="new-password" />
                        <x-forms.input id="smtpTimeout" type="number" helper="Timeout value for sending emails." label="Timeout" />
                    </div>
                </div>
            </form>
            <form wire:submit.prevent="submitResend" class="flex flex-col">
                <div class="flex items-center gap-2">
                    <h3>Resend</h3>
                    @if ($resendEnabled)
                        <x-forms.button type="submit">
                            Save
                        </x-forms.button>
                        <x-forms.button wire:click="toggleResend">
                            Disable Resend
                        </x-forms.button>
                    @else
                        <x-forms.button isHighlighted wire:click="toggleResend">
                            Enable Resend
                        </x-forms.button>
                    @endif
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
</div>
