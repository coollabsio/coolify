<div x-data="{
    emailModalOpen: @js($show_verification),
    openEmailModal() {
        this.emailModalOpen = true;
        this.$nextTick(() => this.$refs.newEmailInput?.focus());
    },
}"
    @close-email-change-modal.window="emailModalOpen = false">
    <x-slot:title>Profile | Coolify</x-slot>
    <div class="mt-8 flex w-full max-w-[1180px] flex-col gap-6 lg:mt-3">
        <form wire:submit="submit">
            <x-unsaved-bar action="submit" />
            <section class="application-settings-section">
                <div class="application-settings-section-header">
                    <div>
                        <h2>Profile details</h2>
                        <p>Your display name and verified sign-in address.</p>
                    </div>
                </div>
                <div class="application-settings-section-body grid gap-4 sm:grid-cols-2">
                    <x-forms.input id="name" label="Name" required />
                    <div class="flex items-end gap-2">
                        <x-forms.input id="email" label="Email" readonly />
                        <x-forms.button @click="openEmailModal()" type="button"
                            x-bind:disabled="emailModalOpen">
                            Change
                        </x-forms.button>
                    </div>
                </div>
            </section>
        </form>

        <template x-teleport="body">
            <div x-show="emailModalOpen" x-cloak
                class="fixed inset-0 z-99 flex h-screen w-screen items-center justify-center p-4">
                <div class="absolute inset-0 h-full w-full bg-black/55 backdrop-blur-[3px]"></div>
                <div x-show="emailModalOpen" x-trap.inert.noscroll="emailModalOpen"
                    class="application-settings-form application-settings-section relative w-full max-w-xl overflow-hidden"
                    style="box-shadow: 0 0 0 1px var(--coollabs-hairline), var(--shadow-modal)">
                    <header>
                        <div>
                            <h3>{{ $show_verification ? 'Verify new email' : 'Change email' }}</h3>
                            <p class="mt-1 text-xs text-neutral-500 dark:text-fg-dim">
                                @if ($show_verification)
                                    Code sent to {{ $new_email ?: auth()->user()->pending_email }}.
                                @else
                                    A six-digit verification code will be sent to the new address.
                                @endif
                            </p>
                        </div>
                        <button type="button"
                            @click="@if ($show_verification) $wire.cancelEmailChange().then(() => emailModalOpen = false) @else emailModalOpen = false @endif"
                            class="icon-button shrink-0" aria-label="Close">
                            <x-reicon name="x" class="size-4" />
                        </button>
                    </header>

                    @if ($show_verification)
                        <form wire:submit="verifyEmailChange" class="application-settings-section-body space-y-4">
                            <x-forms.input id="email_verification_code" label="Verification code" required
                                inputmode="numeric" maxlength="6" />
                            <p class="text-xs text-neutral-500 dark:text-fg-dim">
                                The code expires after
                                {{ config('constants.email_change.verification_code_expiry_minutes', 10) }} minutes.
                            </p>
                            <div class="flex justify-end gap-2">
                                <x-forms.button wire:click="resendVerificationCode" type="button">Resend code</x-forms.button>
                                <x-forms.button type="submit" isHighlighted>Verify email</x-forms.button>
                            </div>
                        </form>
                    @else
                        <form wire:submit="requestEmailChange" class="application-settings-section-body space-y-4">
                            <x-forms.input id="new_email" label="New email address" required type="email"
                                x-ref="newEmailInput" />
                            <div class="flex justify-end">
                                <x-forms.button type="submit" isHighlighted>Send code</x-forms.button>
                            </div>
                        </form>
                    @endif
                </div>
            </div>
        </template>

        <form wire:submit="resetPassword">
            <section class="application-settings-section">
                <div class="application-settings-section-header">
                    <div>
                        <h2>Password</h2>
                        <p>Changing your password signs out every active session.</p>
                    </div>
                    <x-forms.button type="submit">Change password</x-forms.button>
                </div>
                <div class="application-settings-section-body grid gap-4 sm:grid-cols-2">
                    <x-forms.input class="sm:col-span-2" id="current_password" label="Current password"
                        required type="password" />
                    <x-forms.input id="new_password" label="New password" required type="password" />
                    <x-forms.input id="new_password_confirmation" label="Confirm new password" required
                        type="password" />
                </div>
            </section>
        </form>

        <section class="application-settings-section">
            <div class="application-settings-section-header">
                <div>
                    <h2>Two-factor authentication</h2>
                    <p>Add a time-based one-time password to protect your account.</p>
                </div>
                @if (! request()->user()->two_factor_confirmed_at
                        && session('status') !== 'two-factor-authentication-enabled')
                    <form action="/user/two-factor-authentication" method="POST">
                        @csrf
                        <x-forms.button type="submit">Configure 2FA</x-forms.button>
                    </form>
                @endif
            </div>
            <div class="application-settings-section-body">
                @if (session('status') === 'two-factor-authentication-enabled')
                    <div class="grid gap-6 lg:grid-cols-[320px_minmax(0,1fr)]">
                        <div
                            class="flex aspect-square items-center justify-center rounded-[10px] border border-neutral-200 bg-white p-5 dark:border-white/[0.07]">
                            {!! request()->user()->twoFactorQrCodeSvg() !!}
                        </div>
                        <div class="space-y-4">
                            <div>
                                <h3 class="text-sm font-semibold text-black dark:text-fg">Finish setup</h3>
                                <p class="mt-1 text-sm text-neutral-500 dark:text-fg-dim">
                                    Scan the QR code, then enter the current code from your authenticator.
                                </p>
                            </div>
                            <form action="/user/confirmed-two-factor-authentication" method="POST"
                                class="flex items-end gap-2">
                                @csrf
                                <x-forms.input type="text" inputmode="numeric" pattern="[0-9]*" id="code"
                                    label="One-time code" required />
                                <x-forms.button type="submit">Validate 2FA</x-forms.button>
                            </form>
                            <div x-data="{ showCode: false }">
                                <div x-cloak x-show="showCode" class="space-y-2 pb-3">
                                    <x-forms.copy-button
                                        text="{{ decrypt(request()->user()->two_factor_secret) }}" />
                                    <x-forms.copy-button text="{{ request()->user()->twoFactorQrCodeUrl() }}" />
                                </div>
                                <x-forms.button type="button" x-on:click="showCode = !showCode">
                                    <span x-text="showCode ? 'Hide manual setup' : 'Show manual setup'"></span>
                                </x-forms.button>
                            </div>
                        </div>
                    </div>
                @elseif (request()->user()->two_factor_confirmed_at)
                    <div class="flex flex-col gap-4">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <x-status-badge status="Enabled" type="success" />
                            <div class="flex flex-wrap items-center gap-2">
                                <form action="/user/two-factor-recovery-codes" method="POST">
                                    @csrf
                                    <x-forms.button type="submit">Regenerate recovery codes</x-forms.button>
                                </form>
                                <form action="/user/two-factor-authentication" method="POST">
                                    @csrf
                                    @method('DELETE')
                                    <x-forms.button type="submit" isError>Disable 2FA</x-forms.button>
                                </form>
                            </div>
                        </div>
                        @if (session('status') === 'two-factor-authentication-confirmed'
                                || session('status') === 'recovery-codes-generated')
                            <div
                                class="grid gap-2 rounded-lg border border-neutral-200 bg-neutral-50 p-4 font-mono text-xs text-neutral-700 sm:grid-cols-2 dark:border-white/[0.07] dark:bg-white/[0.025] dark:text-fg-dim">
                                @foreach (request()->user()->recoveryCodes() as $code)
                                    <div>{{ $code }}</div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @else
                    <x-empty size="sm" title="Two-factor authentication is off"
                        description="Configure an authenticator app to add another sign-in check."
                        icon-name="keys" />
                @endif
            </div>
        </section>

        @if (session()->has('errors'))
            <x-callout type="danger" title="Profile update failed">
                Something went wrong. Please review the fields and try again.
            </x-callout>
        @endif
    </div>
</div>
