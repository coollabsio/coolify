<x-auth.shell title="Two-factor authentication required"
    description="{{ $teamName ?? 'Your team' }} requires two-factor authentication. Configure it to continue.">
    <div class="flex flex-col gap-4">
        @if (request()->user()->two_factor_confirmed_at)
            <p class="text-sm text-neutral-500 dark:text-fg-dim">
                Two-factor authentication is on. Save these recovery codes in a safe place.
            </p>
            <div
                class="grid gap-2 rounded-lg border border-neutral-200 bg-neutral-50 p-4 font-mono text-xs text-neutral-700 sm:grid-cols-2 dark:border-white/[0.07] dark:bg-white/[0.05] dark:text-fg-dim">
                @foreach (request()->user()->recoveryCodes() as $code)
                    <div>{{ $code }}</div>
                @endforeach
            </div>
            <a href="{{ route('dashboard') }}" class="button w-full justify-center">Continue</a>
        @elseif (session('status') === 'two-factor-authentication-enabled' || request()->user()->two_factor_secret)
            <div
                class="flex items-center justify-center rounded-[10px] border border-neutral-200 bg-white p-5 dark:border-white/[0.07]">
                {!! request()->user()->twoFactorQrCodeSvg() !!}
            </div>
            <p class="text-sm text-neutral-500 dark:text-fg-dim">
                Scan the QR code, then enter the current code from your authenticator.
            </p>
            <form action="/user/confirmed-two-factor-authentication" method="POST" class="flex flex-col gap-4"
                x-init="$nextTick(() => $el.querySelector('input[name=code]')?.focus())">
                @csrf
                <x-forms.input name="code" type="text" inputmode="numeric" pattern="[0-9]*" id="code"
                    label="One-time code" required />
                {{-- Livewire views replace $errors with the component bag, so read the Fortify error from the session. --}}
                @if ($codeError = session('errors')?->getBag('confirmTwoFactorAuthentication')->first('code'))
                    <x-auth.alert type="error">{{ $codeError }}</x-auth.alert>
                @endif
                <x-forms.button class="w-full justify-center" type="submit" isHighlighted>Validate 2FA</x-forms.button>
            </form>
            <div x-data="{ showCode: false }">
                <div x-cloak x-show="showCode" class="space-y-2 pb-3">
                    <x-forms.copy-input :text="decrypt(request()->user()->two_factor_secret)" />
                    <x-forms.copy-input :text="request()->user()->twoFactorQrCodeUrl()" />
                </div>
                <x-forms.button class="w-full justify-center" type="button" x-on:click="showCode = !showCode">
                    <span x-text="showCode ? 'Hide manual setup' : 'Show manual setup'"></span>
                </x-forms.button>
            </div>
        @else
            <form action="/user/two-factor-authentication" method="POST">
                @csrf
                <x-forms.button class="w-full justify-center" type="submit" isHighlighted>Configure 2FA</x-forms.button>
            </form>
        @endif
    </div>
    @unless (request()->user()->two_factor_confirmed_at)
        <x-slot:footer>
            <span>Not now?</span>
            <form action="/logout" method="POST">
                @csrf
                <button type="submit" class="auth-text-link">Log out</button>
            </form>
        </x-slot:footer>
    @endunless
</x-auth.shell>
