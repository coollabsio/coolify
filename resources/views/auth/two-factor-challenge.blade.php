<x-layout-simple>
    <section class="bg-gray-50 dark:bg-base" x-data="{
        showRecovery: false,
        digits: ['', '', '', '', '', ''],
        code: '',
        focusNext(event) {
            const nextInput = event.target.nextElementSibling;
            if (nextInput && nextInput.tagName === 'INPUT') {
                nextInput.focus();
            }
        },
        focusPrevious(event) {
            if (event.key === 'Backspace' && !event.target.value) {
                const prevInput = event.target.previousElementSibling;
                if (prevInput && prevInput.tagName === 'INPUT') {
                    prevInput.focus();
                }
            }
        },
        updateCode() {
            this.code = this.digits.join('');
            if (this.digits.every(d => d !== '') && this.digits.length === 6) {
                this.$nextTick(() => {
                    const form = document.querySelector('form[action=\'/two-factor-challenge\']');
                    if (form) form.submit();
                });
            }
        },
        pasteCode(event) {
            event.preventDefault();
            const paste = (event.clipboardData || window.clipboardData).getData('text');
            const pasteDigits = paste.replace(/\D/g, '').slice(0, 6).split('');
            const container = event.target.closest('.flex');
            const inputs = container.querySelectorAll('input[type=text]');
            pasteDigits.forEach((digit, index) => {
                if (index < 6 && inputs[index]) {
                    this.digits[index] = digit;
                }
            });
            this.updateCode();
            if (pasteDigits.length > 0 && inputs.length > 0) {
                const lastIndex = Math.min(pasteDigits.length - 1, 5);
                inputs[lastIndex].focus();
            }
        }
    }">
        <div class="flex flex-col items-center justify-center px-6 py-8 mx-auto md:h-screen lg:py-0">
            <div class="w-full max-w-md space-y-8">
                <div class="text-center space-y-2">
                    <h1 class="!text-5xl font-extrabold tracking-tight text-gray-900 dark:text-white">
                        Coolify
                    </h1>
                    <p class="text-lg dark:text-neutral-400">
                        Two-Factor Authentication
                    </p>
                </div>

                <div class="space-y-6">
                    @if (session('status'))
                        <div class="p-4 bg-success/10 border border-success rounded-lg">
                            <p class="text-sm text-success">{{ session('status') }}</p>
                        </div>
                    @endif

                    @if ($errors->any())
                        <div class="p-4 bg-error/10 border border-error rounded-lg">
                            @foreach ($errors->all() as $error)
                                <p class="text-sm text-error">{{ $error }}</p>
                            @endforeach
                        </div>
                    @endif

                    <div x-show="!showRecovery"
                        class="p-4 bg-neutral-50 dark:bg-coolgray-200 rounded-lg border border-neutral-200 dark:border-coolgray-400">
                        <div class="flex gap-3">
                            <svg class="size-5 flex-shrink-0 mt-0.5 text-coollabs dark:text-warning"
                                xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"
                                stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <p class="text-sm dark:text-neutral-400">
                                Enter the verification code from your authenticator app to continue.
                            </p>
                        </div>
                    </div>

                    <form action="/two-factor-challenge" method="POST" class="flex flex-col gap-4">
                        @csrf
                        <div x-show="!showRecovery">
                            <input type="hidden" name="code" x-model="code">
                            <div class="flex gap-2 justify-center" @paste="pasteCode($event)">
                                <template x-for="(digit, index) in digits" :key="index">
                                    <input type="text" inputmode="numeric" maxlength="1" x-model="digits[index]"
                                        @input="if ($event.target.value) { focusNext($event); updateCode(); }"
                                        @keydown="focusPrevious($event)"
                                        class="w-12 h-14 text-center text-2xl font-bold bg-white dark:bg-coolgray-100 border-2 border-neutral-200 dark:border-coolgray-300 rounded-lg focus:border-coollabs dark:focus:border-warning focus:outline-none focus:ring-0 transition-colors"
                                        autocomplete="off" />
                                </template>
                            </div>
                            <button type="button" x-on:click="showRecovery = !showRecovery"
                                class="mt-4 text-sm dark:text-neutral-400 hover:text-black dark:hover:text-white hover:underline transition-colors cursor-pointer">
                                Use Recovery Code Instead
                            </button>
                        </div>
                        <div x-show="showRecovery" x-cloak>
                            <x-forms.input name="recovery_code" label="{{ __('input.recovery_code') }}" />
                            <button type="button" x-on:click="showRecovery = !showRecovery"
                                class="mt-2 text-sm dark:text-neutral-400 hover:text-black dark:hover:text-white hover:underline transition-colors cursor-pointer">
                                Use Authenticator Code Instead
                            </button>
                        </div>
                        <x-forms.button class="w-full justify-center py-3 box-boarding" type="submit" isHighlighted>
                            {{ __('auth.login') }}
                        </x-forms.button>
                    </form>

                    <div class="relative">
                        <div class="absolute inset-0 flex items-center">
                            <div class="w-full border-t border-neutral-300 dark:border-coolgray-400"></div>
                        </div>
                        <div class="relative flex justify-center text-sm">
                            <span class="px-2 bg-gray-50 dark:bg-base text-neutral-500 dark:text-neutral-400">
                                Need help?
                            </span>
                        </div>
                    </div>

                    <a href="/login"
                        class="block w-full text-center py-3 px-4 rounded-lg border border-neutral-300 dark:border-coolgray-400 font-medium hover:border-coollabs dark:hover:border-warning transition-colors">
                        Back to Login
                    </a>
                </div>
            </div>
        </div>
    </section>
</x-layout-simple>