<div>
    <x-slot:title>
        Profile | Coolify
    </x-slot>
    <x-profile.navbar />
    <form wire:submit='submit' class="flex flex-col">
        <div class="flex items-center gap-2">
            <h2>General</h2>
            <x-forms.button type="submit" label="Save">Save</x-forms.button>
        </div>
        <div class="flex flex-col gap-2 lg:flex-row items-end">
            <x-forms.input id="name" label="Name" required />
            <x-forms.input id="email" label="Email" readonly />
            @if (!$show_email_change && !$show_verification)
                <x-forms.button wire:click="showEmailChangeForm" type="button">Change Email</x-forms.button>
            @else
                <x-forms.button wire:click="showEmailChangeForm" type="button" disabled>Change Email</x-forms.button>
            @endif
        </div>
    </form>

    <div class="flex flex-col pt-4">
        @if ($show_email_change)
            <form wire:submit='requestEmailChange'>
                <div class="flex gap-2 items-end">
                    <x-forms.input id="new_email" label="New Email Address" required type="email" />
                    <x-forms.button type="submit">Send Verification Code</x-forms.button>
                    <x-forms.button wire:click="$set('show_email_change', false)" type="button"
                        isError>Cancel</x-forms.button>
                </div>
                <div class="text-xs font-bold dark:text-warning pt-2">A verification code will be sent to your
                    new email
                    address.</div>
            </form>
        @endif

        @if ($show_verification)
            <form wire:submit='verifyEmailChange'>
                <div class="flex gap-2 items-end">
                    <x-forms.input id="email_verification_code" label="Verification Code (6 digits)" required
                        maxlength="6" />
                    <x-forms.button type="submit">Verify & Update Email</x-forms.button>
                    <x-forms.button wire:click="resendVerificationCode" type="button" isWarning>Resend
                        Code</x-forms.button>
                    <x-forms.button wire:click="cancelEmailChange" type="button" isError>Cancel</x-forms.button>
                </div>
                <div class="text-xs font-bold dark:text-warning pt-2">
                    Verification code sent to {{ $new_email ?? auth()->user()->pending_email }}.
                    The code is valid for {{ config('constants.email_change.verification_code_expiry_minutes', 10) }}
                    minutes.
                </div>


            </form>
        @endif
    </div>
    <form wire:submit='resetPassword' class="flex flex-col pt-4">
        <div class="flex items-center gap-2 pb-2">
            <h2>Change Password</h2>
            <x-forms.button type="submit" label="Save">Save</x-forms.button>
        </div>
        <div class="text-xs font-bold dark:text-warning pb-2">Resetting the password will logout all sessions.</div>
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
                <x-forms.input type="text" inputmode="numeric" pattern="[0-9]*" id="code"
                    label="One time (OTP) code" required />
                <x-forms.button type="submit">Validate 2FA</x-forms.button>
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
    <h2 class="py-4">Passkeys</h2>
    @if (session('status') == 'passkey-registered')
        <div class="mb-4 font-medium">
            Passkey registered successfully.
        </div>
    @elseif (session('status') == 'passkey-deleted')
        <div class="mb-4 font-medium">
            Passkey deleted successfully.
        </div>
    @endif

    @php
        $userPasskeys = request()->user()->passkeys()->orderByDesc('created_at')->get();
        $requiresIdentityConfirmation = ! shouldSkipPasswordConfirmation();
        $addPasskeyConfirmUrl = route('profile.add-passkey');
    @endphp

    <div x-data="{
        name: '',
        loading: false,
        error: null,
        supported: null,
        scriptMissing: false,
        addModalOpen: false,
        requiresIdentityConfirmation: @json($requiresIdentityConfirmation),
        addPasskeyConfirmUrl: '{{ $addPasskeyConfirmUrl }}',
        init() {
            if (!window.coolifyPasskeys?.getSupportError) {
                this.supported = false;
                this.scriptMissing = true;
                return;
            }
            this.supported = window.coolifyPasskeys.getSupportError() === null;

            if (new URLSearchParams(window.location.search).has('addPasskey')) {
                this.openAddForm();
                window.history.replaceState({}, '', window.location.pathname);
            }
        },
        async startAddPasskey() {
            if (this.requiresIdentityConfirmation) {
                try {
                    const response = await fetch('/user/confirmed-password-status', {
                        headers: { Accept: 'application/json' },
                        credentials: 'same-origin',
                    });

                    if (response.ok) {
                        const { confirmed } = await response.json();

                        if (! confirmed) {
                            window.location.href = this.addPasskeyConfirmUrl;

                            return;
                        }
                    }
                } catch (error) {
                    this.error = 'Could not verify your identity status. Please try again.';
                    return;
                }
            }

            this.openAddForm();
        },
        openAddForm() {
            this.addModalOpen = true;
            this.error = null;
            this.$nextTick(() => this.$refs.passkeyName?.focus());
        },
        cancelAddForm() {
            this.addModalOpen = false;
            this.name = '';
            this.error = null;
            this.loading = false;
        },
        checkSupported() {
            if (!window.coolifyPasskeys?.getSupportError) {
                this.supported = false;
                this.scriptMissing = true;
                this.error = 'Passkey scripts failed to load. When using a custom HTTPS domain, run npm run build in the Vite container and remove public/hot.';
                return false;
            }
            const supportError = window.coolifyPasskeys.getSupportError();
            this.supported = supportError === null;
            if (supportError) {
                this.error = supportError;
            }
            return this.supported;
        },
        async addPasskey() {
            if (!this.checkSupported()) {
                return;
            }
            if (!this.name.trim()) {
                this.error = 'Please enter a name for this passkey.';
                return;
            }
            this.loading = true;
            this.error = null;
            try {
                await window.coolifyPasskeys.register(this.name.trim());
            } catch (error) {
                this.error = error?.message ?? 'Failed to register passkey. Please try again.';
            } finally {
                this.loading = false;
            }
        },
    }" class="relative flex flex-col gap-4 max-w-xl" :class="{ 'z-40': addModalOpen }"
        @keydown.window.escape="if (addModalOpen && !loading) { cancelAddForm(); }">
        @if ($userPasskeys->isNotEmpty())
            <div class="text-sm">
                Passkeys are <span class="text-helper">enabled</span> for this account.
            </div>
            <div class="flex flex-col gap-4">
                @foreach ($userPasskeys as $passkey)
                    <div
                        class="box-without-bg dark:bg-coolgray-100 bg-white flex items-center justify-between gap-4 p-4 min-h-0">
                        <div class="min-w-0 flex-1">
                            <div class="box-title truncate">{{ $passkey->name }}</div>
                            <div class="box-description">
                                Added {{ $passkey->created_at->diffForHumans() }}
                                @if ($passkey->last_used_at)
                                    · Last used {{ $passkey->last_used_at->diffForHumans() }}
                                @endif
                            </div>
                        </div>
                        <div class="shrink-0">
                            <x-modal-confirmation title="Confirm Passkey Deletion?" buttonTitle="Delete"
                                isErrorButton submitAction="deletePasskey({{ $passkey->id }})" :actions="[
                                    'The passkey \'' . $passkey->name . '\' will be permanently removed from your account.',
                                    'You will no longer be able to sign in with \'' . $passkey->name . '\'.',
                                ]" confirmationText="{{ $passkey->name }}"
                                confirmationLabel="Please confirm the execution of the actions by entering the Passkey Name below"
                                shortConfirmationLabel="Passkey Name" :confirmWithPassword="false"
                                step2ButtonText="Permanently Delete" />
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <div class="text-sm dark:text-neutral-400">
                Register a passkey to sign in without a password using Face ID, Touch ID, Windows Hello, or a security
                key.
            </div>
        @endif

        <x-forms.button class="self-start" type="button" x-on:click="startAddPasskey()">
            Add passkey
        </x-forms.button>

        <template x-teleport="body">
            <div x-show="addModalOpen" x-cloak class="fixed inset-0 z-99 overflow-y-auto">
                <div x-show="addModalOpen" x-transition:enter="ease-out duration-100"
                    x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                    x-transition:leave="ease-in duration-100" x-transition:leave-start="opacity-100"
                    x-transition:leave-end="opacity-0" class="absolute inset-0 w-full h-full bg-black/20 backdrop-blur-xs">
                </div>
                <div x-on:click.self="if (!loading) { cancelAddForm(); }"
                    class="relative flex min-h-full items-start justify-center p-4 sm:items-center">
                    <div x-show="addModalOpen" x-trap.inert.noscroll="addModalOpen"
                        x-transition:enter="ease-out duration-100"
                        x-transition:enter-start="opacity-0 -translate-y-2 sm:scale-95"
                        x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                        x-transition:leave="ease-in duration-100"
                        x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                        x-transition:leave-end="opacity-0 -translate-y-2 sm:scale-95"
                        class="relative flex max-h-[calc(100dvh-2rem)] w-full flex-col overflow-hidden rounded-sm border border-neutral-200 bg-white drop-shadow-sm dark:border-coolgray-300 dark:bg-base lg:w-auto lg:min-w-2xl lg:max-w-4xl">
                        <div class="flex items-center justify-between py-6 px-6 shrink-0">
                            <h3 class="text-2xl font-bold">Add Passkey</h3>
                            <button type="button" x-on:click="cancelAddForm()" x-bind:disabled="loading"
                                class="absolute cursor-pointer top-0 right-0 flex items-center justify-center w-8 h-8 mt-5 mr-5 rounded-full dark:text-white hover:bg-neutral-100 dark:hover:bg-coolgray-300 outline-0 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-coollabs dark:focus-visible:ring-warning focus-visible:ring-offset-2 dark:focus-visible:ring-offset-base">
                                <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none"
                                    viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                        <div class="relative min-h-0 flex-1 overflow-y-auto px-6 pb-6 pt-1"
                            style="-webkit-overflow-scrolling: touch;">
                            <p class="mb-4 text-sm dark:text-neutral-400">
                                Register a passkey to sign in without a password using Face ID, Touch ID, Windows
                                Hello, or a security key.
                            </p>
                            <div class="flex flex-col gap-2">
                                <div class="w-full">
                                    <label class="flex gap-1 items-center mb-1 text-sm font-medium"
                                        for="passkey-name">Passkey name</label>
                                    <input id="passkey-name" x-ref="passkeyName" x-model="name" type="text"
                                        placeholder="Work laptop" class="input" autocomplete="off" maxlength="255" />
                                </div>
                                <p x-show="error" x-text="error" class="text-sm text-error"></p>
                                <x-forms.button class="mt-4 w-full" type="button" x-on:click="addPasskey()"
                                    x-bind:disabled="loading">
                                    <span x-text="loading ? 'Registering...' : 'Add passkey'"></span>
                                </x-forms.button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <p x-show="scriptMissing" class="text-xs text-neutral-500 dark:text-neutral-400">
            Passkey scripts could not be loaded. If you access Coolify via a custom HTTPS domain while the Vite dev
            server is running, run <code class="text-xs">npm run build</code> and delete <code
                class="text-xs">public/hot</code>, then refresh.
        </p>
        <p x-show="supported === false && !scriptMissing" class="text-xs text-neutral-500 dark:text-neutral-400">
            Passkeys require a supported browser and a secure connection (HTTPS).
        </p>
    </div>

    @if (session()->has('errors'))
        <div class="text-error">
            Something went wrong. Please try again.
        </div>
    @endif
</div>
