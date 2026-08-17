<x-layout-simple>
    <x-auth.shell title="Coolify" description="Verify your identity to finish signing in.">
        <div class="flex flex-col gap-4" x-data="{
            showRecovery: false,
            digits: ['', '', '', '', '', ''],
            code: '',
            focusNext(event) {
                const nextInput = event.target.nextElementSibling;
                if (nextInput?.tagName === 'INPUT') nextInput.focus();
            },
            focusPrevious(event) {
                if (event.key !== 'Backspace' || event.target.value) return;

                const previousInput = event.target.previousElementSibling;
                if (previousInput?.tagName === 'INPUT') previousInput.focus();
            },
            updateCode() {
                this.code = this.digits.join('');

                if (this.code.length === 6) {
                    this.$nextTick(() => this.$refs.challengeForm.requestSubmit());
                }
            },
            pasteCode(event) {
                event.preventDefault();

                const pastedDigits = event.clipboardData.getData('text').replace(/\D/g, '').slice(0, 6).split('');
                const inputs = event.currentTarget.querySelectorAll('input[type=text]');

                pastedDigits.forEach((digit, index) => this.digits[index] = digit);
                this.updateCode();
                inputs[Math.min(pastedDigits.length, 6) - 1]?.focus();
            },
        }">
            @if (session('status'))
                <x-auth.alert type="success">{{ session('status') }}</x-auth.alert>
            @endif

            @if ($errors->any())
                <x-auth.alert type="error">
                    <div class="flex flex-col gap-1">
                        @foreach ($errors->all() as $error)
                            <p>{{ $error }}</p>
                        @endforeach
                    </div>
                </x-auth.alert>
            @endif

            <div class="auth-guidance">
                <x-reicon name="info-circle" class="mt-0.5 size-4 shrink-0" />
                <p x-show="!showRecovery">Enter the 6-digit code from your authenticator app.</p>
                <p x-show="showRecovery" x-cloak>Enter one of the recovery codes you saved when setting up two-factor authentication.</p>
            </div>

            <form x-ref="challengeForm" action="/two-factor-challenge" method="POST" class="flex flex-col gap-4">
                @csrf

                <div x-show="!showRecovery" class="flex flex-col gap-3">
                    <input type="hidden" name="code" x-model="code" :disabled="showRecovery">
                    <div class="flex justify-center gap-2" aria-label="Two-factor authentication code"
                        @paste="pasteCode($event)">
                        <template x-for="(digit, index) in digits" :key="index">
                            <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1"
                                x-model="digits[index]" :aria-label="`Digit ${index + 1}`"
                                @input="focusNext($event); updateCode()" @keydown="focusPrevious($event)"
                                class="h-12 w-11 rounded-md border border-neutral-300 bg-white text-center text-lg font-semibold text-neutral-900 transition-colors focus:border-warning focus:outline-none focus:ring-1 focus:ring-warning dark:border-white/10 dark:bg-coolgray-100 dark:text-white sm:h-14 sm:w-12 sm:text-xl"
                                autocomplete="one-time-code" />
                        </template>
                    </div>
                    <button type="button" class="auth-text-link self-center"
                        x-on:click="showRecovery = true; $nextTick(() => $refs.recoveryCode.focus())">
                        Use a recovery code
                    </button>
                </div>

                <div x-show="showRecovery" x-cloak class="flex flex-col gap-3">
                    <x-forms.input x-ref="recoveryCode" name="recovery_code" autocomplete="one-time-code"
                        x-bind:disabled="!showRecovery" label="{{ __('input.recovery_code') }}" />
                    <button type="button" class="auth-text-link self-center"
                        x-on:click="showRecovery = false; $nextTick(() => $el.closest('form').querySelector('input[type=text]').focus())">
                        Use an authenticator code
                    </button>
                </div>

                <x-forms.button class="w-full justify-center" type="submit" isHighlighted>
                    Verify and continue
                </x-forms.button>
            </form>
        </div>

        <x-slot:footer>
            <span>Not your account?</span>
            <a href="/login" class="auth-text-link">Back to login</a>
        </x-slot:footer>
    </x-auth.shell>
</x-layout-simple>
