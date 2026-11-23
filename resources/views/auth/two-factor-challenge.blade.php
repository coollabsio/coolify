<x-layout-simple>
    <x-auth.shell title="Coolify" description="Verify your identity to finish signing in.">
        <div class="flex flex-col gap-4" x-data="{
            showRecovery: false,
            submitting: false,
            code: '',
            codeFocused: false,
            handleSubmit(event) {
                if (this.submitting) {
                    event.preventDefault();
                }

                this.submitting = true;
            },
            submitAuthenticatorCode(event) {
                event.target.value = event.target.value.replace(/\D/g, '').slice(0, 6);
                this.code = event.target.value;

                if (event.target.value.length === 6) {
                    this.$nextTick(() => this.$refs.challengeForm.requestSubmit());
                }
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

            <form x-ref="challengeForm" action="/two-factor-challenge" method="POST" class="flex flex-col gap-4"
                @submit="handleSubmit($event)">
                @csrf

                <div x-show="!showRecovery" class="flex flex-col gap-3">
                    <div class="relative mx-auto flex gap-2">
                        @for ($i = 0; $i < 6; $i++)
                            <div aria-hidden="true" data-code-digit
                                class="flex h-14 w-11 items-center justify-center rounded-md border bg-white text-xl font-semibold text-neutral-900 transition-colors dark:bg-coolgray-100 dark:text-white"
                                :class="codeFocused && (code.length === {{ $i }} || (code.length === 6 && {{ $i }} === 5))
                                    ? 'border-coollabs ring-1 ring-coollabs'
                                    : 'border-neutral-300 dark:border-white/10'"
                                x-text="code[{{ $i }}] ?? ''"></div>
                        @endfor
                        <input x-ref="authenticatorCode" type="text" name="code" inputmode="numeric"
                            pattern="[0-9]*" maxlength="6" autocomplete="one-time-code" autofocus
                            aria-label="Two-factor authentication code" :disabled="showRecovery"
                            x-init="codeFocused = document.activeElement === $el"
                            @input="submitAuthenticatorCode($event)" @focus="codeFocused = true"
                            @blur="codeFocused = false"
                            class="absolute inset-0 h-full w-full cursor-text rounded-md border-0 bg-transparent text-transparent caret-transparent opacity-0 focus:outline-none focus:ring-0" />
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
                        x-on:click="showRecovery = false; $nextTick(() => $refs.authenticatorCode.focus())">
                        Use an authenticator code
                    </button>
                </div>

                <x-forms.button class="w-full justify-center" type="submit" x-bind:disabled="submitting" isHighlighted>
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
