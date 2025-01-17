<div>
    <x-slot:title>
        Profile | Coolify
    </x-slot>
    <h1>Profile</h1>
    <div class="subtitle ">Your user profile settings.</div>
    <form wire:submit='submit' class="flex flex-col">
        <div class="flex items-center gap-2">
            <h2>General</h2>
            <x-forms.button type="submit" label="Save">Save</x-forms.button>
        </div>
        <div class="flex flex-col gap-2 lg:flex-row">
            <x-forms.input id="name" label="Name" required />
            <x-forms.input id="email" label="Email" readonly />
        </div>
    </form>
    <form wire:submit='resetPassword' class="flex flex-col pt-4">
        <div class="flex items-center gap-2 pb-2">
            <h2>Change Password</h2>
            <x-forms.button type="submit" label="Save">Save</x-forms.button>
        </div>
        <div class="flex flex-col gap-2">
            <x-forms.input id="current_password" label="Current Password" required type="password" />
            <div class="flex gap-2">
                <x-forms.input id="new_password" label="New Password" required type="password" />
                <x-forms.input id="new_password_confirmation" label="New Password Again" required type="password" />
            </div>
        </div>
    </form>
    <h2 class="py-4">Two-factor Authentication</h2>
    @if (session('status') == 'two-factor-authentication-enabled')
        <div class="mb-4 font-medium">
            Please finish configuring two factor authentication below. Read the QR code or enter the secret key
            manually.
        </div>
        <div class="flex flex-col gap-4">
            <form action="/user/confirmed-two-factor-authentication" method="POST" class="flex items-end gap-2">
                @csrf
                <x-forms.input type="text" inputmode="numeric" pattern="[0-9]*" id="code" label="One time (OTP) code" required />
                <x-forms.button type="submit">Validate 2FA</x-forms.button>
            </form>
            <div class="flex flex-col items-start">
                <div class="flex items-center justify-center w-80 h-80 bg-white p-4 border-4 border-gray-300 rounded-lg shadow-lg">
                    {!! request()->user()->twoFactorQrCodeSvg() !!}
                </div>
                <div x-data="{
                    showCode: false,
                    secretKey: '{{ decrypt(request()->user()->two_factor_secret) }}',
                    otpUrl: '{{ request()->user()->twoFactorQrCodeUrl() }}',
                    copiedSecretKey: false,
                    copiedOtpUrl: false
                }" class="py-4 w-full">
                    <div class="flex flex-col gap-2" x-show="showCode">
                        <div class="relative">
                            <x-forms.input
                                x-model="secretKey"
                                label="Secret Key"
                                readonly
                                class="font-mono pr-10"
                            />
                            <button
                                x-show="window.isSecureContext"
                                @click="navigator.clipboard.writeText(secretKey); copiedSecretKey = true; setTimeout(() => copiedSecretKey = false, 2000)"
                                class="absolute right-2 bottom-1 p-1 rounded hover:bg-gray-200 dark:hover:bg-gray-700"
                            >
                                <svg x-show="!copiedSecretKey" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 01-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 011.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 00-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 01-1.125-1.125v-9.25m12 6.625v-1.875a3.375 3.375 0 00-3.375-3.375h-1.5a1.125 1.125 0 01-1.125-1.125v-1.5a3.375 3.375 0 00-3.375-3.375H9.75" />
                                </svg>
                                <svg x-show="copiedSecretKey" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-green-500">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                </svg>
                            </button>
                        </div>
                        <div class="relative" >
                            <x-forms.input
                                x-model="otpUrl"
                                label="OTP URL"
                                readonly
                                class="font-mono pr-10"
                            />
                            <button
                                x-show="window.isSecureContext"
                                @click="navigator.clipboard.writeText(otpUrl); copiedOtpUrl = true; setTimeout(() => copiedOtpUrl = false, 2000)"
                                class="absolute right-2 bottom-1 p-1 rounded hover:bg-gray-200 dark:hover:bg-gray-700"
                            >
                                <svg x-show="!copiedOtpUrl" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 01-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 011.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 00-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 01-1.125-1.125v-9.25m12 6.625v-1.875a3.375 3.375 0 00-3.375-3.375h-1.5a1.125 1.125 0 01-1.125-1.125v-1.5a3.375 3.375 0 00-3.375-3.375H9.75" />
                                </svg>
                                <svg x-show="copiedOtpUrl" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-green-500">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                </svg>
                            </button>
                        </div>
                    </div>
                    <x-forms.button x-on:click="showCode = !showCode" class="mt-2">
                        <span x-text="showCode ? 'Hide Secret Key and OTP URL' : 'Show Secret Key and OTP URL'"></span>
                    </x-forms.button>
                </div>
            </div>
        </div>
    @elseif(session('status') == 'two-factor-authentication-confirmed')
        <div class="mb-4 ">
            Two factor authentication confirmed and enabled successfully.
        </div>
        <div>
            <div class="pb-6 ">Here are the recovery codes for your account. Please store them in a secure
                location.
            </div>
            <div class="dark:text-white">
                @foreach (request()->user()->recoveryCodes() as $code)
                    <div>{{ $code }}</div>
                @endforeach
            </div>
        </div>
    @else
        @if (request()->user()->two_factor_confirmed_at)
            <div class="pb-4 "> Two factor authentication is <span class="text-helper">enabled</span>.</div>
            <div class="flex gap-2">
                <form action="/user/two-factor-authentication" method="POST">
                    @csrf
                    @method ('DELETE')
                    <x-forms.button type="submit">Disable</x-forms.button>
                </form>
                <form action="/user/two-factor-recovery-codes" method="POST">
                    @csrf
                    <x-forms.button type="submit">Regenerate Recovery Codes</x-forms.button>
                </form>
            </div>
            @if (session('status') == 'recovery-codes-generated')
                <div>
                    <div class="py-6 ">Here are the recovery codes for your account. Please store them in a
                        secure
                        location.
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
                <x-forms.button type="submit">Configure</x-forms.button>
            </form>
        @endif
    @endif
    @if (session()->has('errors'))
        <div class="text-error">
            Something went wrong. Please try again.
        </div>
    @endif
</div>
