@props([
    'optionsRoute' => 'passkey.login-options',
    'submitRoute' => 'passkey.login',
    'label' => 'Sign in with a passkey',
    'autofill' => false,
])

@vite('resources/js/passkeys.js')
<div x-cloak x-show="supported" class="flex flex-col gap-2" x-data="{
        supported: false,
        loading: false,
        error: null,
        routes: { options: @js(route($optionsRoute)), submit: @js(route($submitRoute)) },
        init() {
            const ready = () => {
                this.supported = Boolean(window.Passkeys?.isSupported());
                if (this.supported && @js($autofill)) {
                    this.autofill();
                }
            };
            window.Passkeys ? ready() : window.addEventListener('passkeys:ready', ready, { once: true });
        },
        done(response) {
            if (response) {
                window.location.href = response.redirect || '/';
            }
        },
        async autofill() {
            try {
                this.done(await window.Passkeys.autofill({ routes: this.routes }));
            } catch (e) {
                this.error = e.message;
            }
        },
        async verify() {
            this.loading = true;
            this.error = null;
            try {
                this.done(await window.Passkeys.verify({ routes: this.routes }));
            } catch (e) {
                if (e.name !== 'UserCancelledError') {
                    this.error = e.message;
                }
            } finally {
                this.loading = false;
            }
        },
    }">
    <x-forms.button class="w-full justify-center" type="button" x-on:click="verify()" x-bind:disabled="loading">
        {{ $label }}
    </x-forms.button>
    <template x-if="error">
        <x-auth.alert type="error"><span x-text="error"></span></x-auth.alert>
    </template>
</div>
