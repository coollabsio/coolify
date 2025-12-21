<div>
    <x-slot:title>
        {{ __('settings.transactional_email') }} | Coolify
    </x-slot>
    <x-settings.navbar />
    <form wire:submit='submit' class="flex flex-col gap-2 pb-4">
        <div class="flex items-center gap-2">
            <h2>{{ __('settings.transactional_email') }}</h2>
            <x-forms.button type="submit">
                {{ __('button.save') }}
            </x-forms.button>
            @if (is_transactional_emails_enabled() && auth()->user()->isAdminFromSession())
                <x-modal-input buttonTitle="{{ __('modal.send_test_email') }}" title="{{ __('modal.send_test_email') }}">
                    <form wire:submit.prevent="sendTestEmail" class="flex flex-col w-full gap-2">
                        <x-forms.input wire:model="testEmailAddress" placeholder="{{ __('forms.placeholders.test_email') }}" id="testEmailAddress"
                            label="{{ __('settings.recipient') }}" required />
                        <x-forms.button type="submit" @click="modalOpen=false">
                            {{ __('common.send') }}
                        </x-forms.button>
                    </form>
                </x-modal-input>
            @endif
        </div>
        <div class="pb-4">{{ __('settings.transactional_email_desc') }}</div>
        <div class="flex gap-2">
            <x-forms.input required id="smtpFromName" helper="{{ __('settings.from_name_helper') }}" label="{{ __('settings.from_name') }}" />
            <x-forms.input required id="smtpFromAddress" helper="{{ __('settings.from_address_helper') }}" label="{{ __('settings.from_address') }}" />
        </div>
    </form>
    <div class="flex flex-col gap-4">
        <div class="p-4 border dark:border-coolgray-300 border-neutral-200">
            <form wire:submit.prevent="submitSmtp" class="flex flex-col">
                <div class="flex gap-2">
                    <h3>{{ __('settings.smtp_server') }}</h3>
                    <x-forms.button type="submit">
                        {{ __('button.save') }}
                    </x-forms.button>
                </div>
                <div class="w-32">
                    <x-forms.checkbox instantSave='instantSave("SMTP")' id="smtpEnabled" label="{{ __('settings.enabled') }}" />
                </div>
                <div class="flex flex-col gap-4">
                    <div class="flex flex-col w-full gap-2 xl:flex-row">
                        <x-forms.input required id="smtpHost" placeholder="{{ __('forms.placeholders.smtp_host') }}" label="{{ __('settings.host') }}" />
                        <x-forms.input required id="smtpPort" type="number" placeholder="{{ __('forms.placeholders.smtp_port') }}" label="{{ __('settings.port') }}" />
                        <x-forms.select required id="smtpEncryption" label="{{ __('settings.encryption') }}">
                            <option value="starttls">{{ __('settings.starttls') }}</option>
                            <option value="tls">{{ __('settings.tls_ssl') }}</option>
                            <option value="none">{{ __('settings.none') }}</option>
                        </x-forms.select>
                    </div>
                    <div class="flex flex-col w-full gap-2 xl:flex-row">
                        <x-forms.input id="smtpUsername" label="{{ __('settings.smtp_username') }}" />
                        <x-forms.input id="smtpPassword" type="password" label="{{ __('settings.smtp_password') }}"
                            autocomplete="new-password" />
                        <x-forms.input id="smtpTimeout" helper="{{ __('settings.timeout_helper') }}" label="{{ __('settings.timeout') }}" />
                    </div>
                </div>
            </form>
        </div>
        <div class="p-4 border dark:border-coolgray-300 border-neutral-200">
            <form wire:submit.prevent="submitResend" class="flex flex-col">
                <div class="flex gap-2">
                    <h3>{{ __('settings.resend') }}</h3>
                    <x-forms.button type="submit">
                        {{ __('button.save') }}
                    </x-forms.button>
                </div>
                <div class="w-32">
                    <x-forms.checkbox instantSave='instantSave("Resend")' id="resendEnabled" label="{{ __('settings.enabled') }}" />
                </div>
                <div class="flex flex-col gap-4">
                    <div class="flex flex-col w-full gap-2 xl:flex-row">
                        <x-forms.input type="password" id="resendApiKey" placeholder="{{ __('forms.placeholders.api_key') }}" required label="{{ __('settings.api_key') }}"
                            autocomplete="new-password" />
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
