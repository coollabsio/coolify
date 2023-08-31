<div>
    <dialog id="sendTestEmail" class="modal">
        <form method="dialog" class="flex flex-col gap-2 rounded modal-box" wire:submit.prevent='submit'>
            <x-forms.input placeholder="test@example.com" id="emails" label="Recepients" required />
            <x-forms.button onclick="sendTestEmail.close()" wire:click="sendTestNotification">
                Send Email
            </x-forms.button>
        </form>
        <form method="dialog" class="modal-backdrop">
            <button>close</button>
        </form>
    </dialog>
    <form wire:submit.prevent='submit' class="flex flex-col">
        <div class="flex items-center gap-2">
            <h2>Email</h2>
            <x-forms.button type="submit">
                Save
            </x-forms.button>
            @if (isInstanceAdmin() && !$team->use_instance_email_settings)
                <x-forms.button wire:click='copyFromInstanceSettings'>
                    Copy from Instance Settings
                </x-forms.button>
            @endif
            @if (isEmailEnabled($team) || data_get($team, 'use_instance_email_settings'))
                <x-forms.button onclick="sendTestEmail.showModal()"
                    class="text-white normal-case btn btn-xs no-animation btn-primary">
                    Send Test Email
                </x-forms.button>
            @endif

        </div>
    </form>
    @if ($this->sharedEmailEnabled)
        <div class="w-64 pb-4">
            <x-forms.checkbox instantSave="instantSaveInstance" id="team.use_instance_email_settings"
                label="Use hosted email service" />
        </div>
    @endif
    @if (!$team->use_instance_email_settings)
        <form class="flex flex-col items-end gap-2 pb-4 xl:flex-row" wire:submit.prevent='submitFromFields'>
            <x-forms.input required id="team.smtp_from_name" helper="Name used in emails." label="From Name" />
            <x-forms.input required id="team.smtp_from_address" helper="Email address used in emails."
                label="From Address" />
            <x-forms.button type="submit">
                Save
            </x-forms.button>
        </form>
        <div class="flex flex-col gap-4">
            <details class="border rounded collapse border-coolgray-500 collapse-arrow ">
                <summary class="text-xl collapse-title">
                    <div>SMTP Server</div>
                    <div class="w-32">
                        <x-forms.checkbox instantSave id="team.smtp_enabled" label="Enabled" />
                    </div>
                </summary>
                <div class="collapse-content">
                    <form wire:submit.prevent='submit' class="flex flex-col">
                        <div class="flex flex-col gap-4">
                            <div class="flex flex-col w-full gap-2 xl:flex-row">
                                <x-forms.input required id="team.smtp_host" placeholder="smtp.mailgun.org"
                                    label="Host" />
                                <x-forms.input required id="team.smtp_port" placeholder="587" label="Port" />
                                <x-forms.input id="team.smtp_encryption" helper="If SMTP uses SSL, set it to 'tls'."
                                    placeholder="tls" label="Encryption" />
                            </div>
                            <div class="flex flex-col w-full gap-2 xl:flex-row">
                                <x-forms.input id="team.smtp_username" label="SMTP Username" />
                                <x-forms.input id="team.smtp_password" type="password" label="SMTP Password" />
                                <x-forms.input id="team.smtp_timeout" helper="Timeout value for sending emails."
                                    label="Timeout" />
                            </div>
                        </div>
                        <div class="flex justify-end gap-4 pt-6">
                            <x-forms.button type="submit">
                                Save
                            </x-forms.button>
                        </div>
                    </form>
                </div>
            </details>
            <details class="border rounded collapse border-coolgray-500 collapse-arrow">
                <summary class="text-xl collapse-title">
                    <div>Resend</div>
                    <div class="w-32">
                        <x-forms.checkbox instantSave='instantSaveResend' id="team.resend_enabled" label="Enabled" />
                    </div>
                </summary>
                <div class="collapse-content">
                    <form wire:submit.prevent='submitResend' class="flex flex-col">
                        <div class="flex flex-col gap-4">
                            <div class="flex flex-col w-full gap-2 xl:flex-row">
                                <x-forms.input type="password" id="team.resend_api_key" placeholder="API key"
                                    label="Host" />
                            </div>
                        </div>
                        <div class="flex justify-end gap-4 pt-6">
                            <x-forms.button type="submit">
                                Save
                            </x-forms.button>
                        </div>
                    </form>
                </div>
            </details>
        </div>
    @endif
    @if (isEmailEnabled($team) || data_get($team, 'use_instance_email_settings'))
        <h3 class="mt-4">Subscribe to events</h3>
        <div class="w-64">
            @if (isDev())
                <x-forms.checkbox instantSave="saveModel" id="team.smtp_notifications_test" label="Test" />
            @endif
            <h4 class="mt-4">General</h4>
            <x-forms.checkbox instantSave="saveModel" id="team.smtp_notifications_status_changes"
                label="Container Status Changes" />
            <h4 class="mt-4">Applications</h4>
            <x-forms.checkbox instantSave="saveModel" id="team.smtp_notifications_deployments" label="Deployments" />
            <h4 class="mt-4">Databases</h4>
            <x-forms.checkbox instantSave="saveModel" id="team.smtp_notifications_database_backups"
                label="Backup Statuses" />
        </div>
    @endif
</div>
