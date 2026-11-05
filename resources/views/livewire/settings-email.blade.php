<div>
    <x-slot:title>
        Transactional Email | Coolify
    </x-slot>

    <x-settings.layout>
    <div class="application-settings-form mx-auto flex w-full max-w-none min-w-0 flex-col gap-6">
        {{-- One bar for the whole page. Save validates only the provider that is selected. --}}
        <x-unsaved-bar action="submit"
            targets="smtpFromName,smtpFromAddress,smtpHost,smtpPort,smtpEncryption,smtpUsername,smtpPassword,smtpTimeout,smtpEhloDomain,resendApiKey,resendFromName,resendFromAddress,notificationFromName,notificationFromAddress" />

        <form wire:submit="submit">
            <x-application.settings-section title="Email delivery"
                description="Only one provider sends email. Select a provider to see its settings.">
                <x-slot:badges>
                    <div class="w-40">
                        <x-forms.listbox id="emailProvider" onChange="selectEmailProvider"
                            :options="collect(email_provider_labels())
                                ->map(fn ($label, $value) => ['value' => $value, 'label' => $label])
                                ->values()
                                ->all()" />
                    </div>
                </x-slot:badges>
                <x-slot:actions>
                    @include('livewire.partials.settings-email-send-test')
                </x-slot:actions>
                <div class="grid gap-4 lg:grid-cols-3">
                    @if ($emailProvider === 'none')
                        <p class="text-sm text-neutral-500 lg:col-span-3 dark:text-neutral-400">
                            Coolify does not send email. Select a provider next to the title to set it up.
                        </p>
                    @endif

                    @if ($emailProvider !== $this->activeEmailProvider)
                        <div class="lg:col-span-3">
                            <x-callout type="warning" title="Not active yet">
                                Enter the {{ email_provider_labels()[$emailProvider] }} settings and save.
                                @if ($this->activeEmailProvider === 'none')
                                    Until then, Coolify does not send email.
                                @else
                                    Until then, Coolify sends email with
                                    {{ email_provider_labels()[$this->activeEmailProvider] }}.
                                @endif
                            </x-callout>
                        </div>
                    @endif

                    @if ($emailProvider === 'smtp')
                        @include('livewire.partials.smtp-server-picker')
                    @elseif ($emailProvider === 'resend')
                        <x-forms.input required type="password" id="resendApiKey" placeholder="API key"
                            label="Resend API key" autocomplete="new-password" />
                        <x-forms.input required id="resendFromName" helper="Name shown in email from Resend."
                            label="From name" />
                        <x-forms.input required id="resendFromAddress"
                            helper="Address used in email from Resend. It must be on a domain verified in Resend."
                            label="From address" />
                    @endif

                    @if ($emailProvider !== 'none')
                        <x-forms.input id="notificationFromName" placeholder="Uses the from name when empty"
                            helper="Name shown in notification emails (deployments, backups, server alerts) to teams that use the instance email settings."
                            label="Notification from name" />
                        <x-forms.input id="notificationFromAddress" placeholder="Uses the from address when empty"
                            helper="Address used for notification emails. Password resets and invitations still use the from address. Use a separate subdomain (for example alerts@notifications.example.com) to keep the reputation of the two senders apart. Your SMTP provider or Resend must allow this address."
                            label="Notification from address" />
                    @endif
                </div>
            </x-application.settings-section>
        </form>
    </div>
    </x-settings.layout>
</div>
