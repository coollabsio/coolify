<div>
    <x-slot:title>
        {{ __('profile.title') }}
    </x-slot>
    <h1>{{ __('profile.heading') }}</h1>
    <div class="subtitle -mt-2">{{ __('profile.subtitle') }}</div>
    <form wire:submit='submit' class="flex flex-col">
        <div class="flex items-center gap-2">
            <h2>{{ __('profile.general') }}</h2>
            <x-forms.button type="submit" label="{{ __('button.save') }}">{{ __('button.save') }}</x-forms.button>
        </div>
        <div class="flex flex-col gap-2 lg:flex-row items-end">
            <x-forms.input id="name" label="{{ __('input.name') }}" required />
            <x-forms.input id="email" label="{{ __('input.email') }}" readonly />
            @if (!$show_email_change && !$show_verification)
                <x-forms.button wire:click="showEmailChangeForm" type="button">{{ __('profile.change_email') }}</x-forms.button>
            @else
                <x-forms.button wire:click="showEmailChangeForm" type="button" disabled>{{ __('profile.change_email') }}</x-forms.button>
            @endif
        </div>
    </form>

    <div class="flex flex-col pt-4">
        @if ($show_email_change)
            <form wire:submit='requestEmailChange'>
                <div class="flex gap-2 items-end">
                    <x-forms.input id="new_email" label="{{ __('profile.new_email') }}" required type="email" />
                    <x-forms.button type="submit">{{ __('profile.send_verification_code') }}</x-forms.button>
                    <x-forms.button wire:click="$set('show_email_change', false)" type="button"
                        isError>{{ __('button.cancel') }}</x-forms.button>
                </div>
                <div class="text-xs font-bold dark:text-warning pt-2">{{ __('profile.verification_code_sent_notice') }}</div>
            </form>
        @endif

        @if ($show_verification)
            <form wire:submit='verifyEmailChange'>
                <div class="flex gap-2 items-end">
                    <x-forms.input id="email_verification_code" label="{{ __('profile.verification_code') }}" required
                        maxlength="6" />
                    <x-forms.button type="submit">{{ __('profile.verify_update_email') }}</x-forms.button>
                    <x-forms.button wire:click="resendVerificationCode" type="button" isWarning>{{ __('profile.resend_code') }}</x-forms.button>
                    <x-forms.button wire:click="cancelEmailChange" type="button" isError>{{ __('button.cancel') }}</x-forms.button>
                </div>
                <div class="text-xs font-bold dark:text-warning pt-2">
                    {{ __('profile.verification_code_sent_helper', ['email' => $new_email ?? auth()->user()->pending_email, 'minutes' => config('constants.email_change.verification_code_expiry_minutes', 10)]) }}
                </div>


            </form>
        @endif
    </div>
    <form wire:submit='resetPassword' class="flex flex-col pt-4">
        <div class="flex items-center gap-2 pb-2">
            <h2>{{ __('profile.change_password') }}</h2>
            <x-forms.button type="submit" label="{{ __('button.save') }}">{{ __('button.save') }}</x-forms.button>
        </div>
        <div class="text-xs font-bold dark:text-warning pb-2">{{ __('profile.logout_warning') }}</div>
        <div class="flex flex-col gap-2">
            <x-forms.input id="current_password" label="{{ __('input.current_password') }}" required type="password" />
            <div class="flex gap-2">
                <x-forms.input id="new_password" label="{{ __('input.new_password') }}" required type="password" />
                <x-forms.input id="new_password_confirmation" label="{{ __('input.new_password_again') }}" required type="password" />
            </div>
        </div>
    </form>
    <h2 class="py-4">{{ __('profile.two_factor_auth') }}</h2>
    @if (session('status') == 'two-factor-authentication-enabled')
        <div class="mb-4 font-medium">
            {{ __('profile.two_factor_configure_help') }}
        </div>
        <div class="flex flex-col gap-4">
            <form action="/user/confirmed-two-factor-authentication" method="POST" class="flex items-end gap-2">
                @csrf
                <x-forms.input type="text" inputmode="numeric" pattern="[0-9]*" id="code"
                    label="{{ __('input.otp_code') }}" required />
                <x-forms.button type="submit">{{ __('profile.validate_2fa') }}</x-forms.button>
            </form>
            <div class="flex flex-col items-start">
                <div
                    class="flex items-center justify-center w-80 h-80 bg-white p-4 border-4 border-gray-300 rounded-lg shadow-lg">
                    {!! request()->user()->twoFactorQrCodeSvg() !!}
                </div>
                <div x-data="{
                    showCode: false,
                }" class="py-4 w-full">
                    <div class="flex flex-col gap-2 pb-2" x-show="showCode">
                        <x-forms.copy-button text="{{ decrypt(request()->user()->two_factor_secret) }}" />
                        <x-forms.copy-button text="{{ request()->user()->twoFactorQrCodeUrl() }}" />
                    </div>
                    <x-forms.button x-on:click="showCode = !showCode" class="mt-2">
                        <span x-text="showCode ? '{{ __('profile.hide_secret_key') }}' : '{{ __('profile.show_secret_key') }}'"></span>
                    </x-forms.button>
                </div>
            </div>
        </div>
    @elseif(session('status') == 'two-factor-authentication-confirmed')
        <div class="mb-4 ">
            {{ __('profile.two_factor_enabled_success') }}
        </div>
        <div>
            <div class="pb-6 ">{{ __('profile.recovery_codes_help') }}
            </div>
            <div class="dark:text-white">
                @foreach (request()->user()->recoveryCodes() as $code)
                    <div>{{ $code }}</div>
                @endforeach
            </div>
        </div>
    @else
        @if (request()->user()->two_factor_confirmed_at)
            <div class="pb-4 "> {{ __('profile.two_factor_is') }} <span class="text-helper">{{ __('status.enabled') }}</span>.</div>
            <div class="flex gap-2">
                <form action="/user/two-factor-authentication" method="POST">
                    @csrf
                    @method ('DELETE')
                    <x-forms.button type="submit">{{ __('button.disable') }}</x-forms.button>
                </form>
                <form action="/user/two-factor-recovery-codes" method="POST">
                    @csrf
                    <x-forms.button type="submit">{{ __('profile.regenerate_recovery_codes') }}</x-forms.button>
                </form>
            </div>
            @if (session('status') == 'recovery-codes-generated')
                <div>
                    <div class="py-6 ">{{ __('profile.recovery_codes_help') }}
                    </div>
                    <div class="dark:text-white">
                        @foreach (request()->user()->recoveryCodes() as $code)
                            <div>{{ $code }}</div>
                        @endforeach
                    </div>
                </div>
            @endif
        @else
            <form action="/user/two-factor-authentication" method="POST">
                @csrf
                <x-forms.button type="submit">{{ __('button.configure') }}</x-forms.button>
            </form>
        @endif
    @endif
    @if (session()->has('errors'))
        <div class="text-error">
            {{ __('error.something_went_wrong') }}
        </div>
    @endif
</div>
