<div class="lg:col-span-2">
    <x-forms.input readonly id="fingerprint" label="Fingerprint" />
</div>
<div class="lg:col-span-2" x-data="{
    replacing: false,
    askPassword: false,
    password: '',
    revealed: '',
    revealing: false,
    skipPassword: @js(shouldSkipPasswordConfirmation()),
    reveal() {
        if (this.replacing) return;
        if (!this.skipPassword && !this.password) {
            this.askPassword = true;
            return;
        }
        const sentPassword = this.password;
        this.revealing = true;
        $wire.revealPrivateKey(this.password).then((key) => {
            this.revealing = false;
            this.password = '';
            if (key && !this.replacing) {
                this.revealed = key;
                this.askPassword = false;
            } else if (!key && !sentPassword) {
                this.skipPassword = false;
                this.askPassword = true;
            }
        }).catch(() => {
            this.revealing = false;
        });
    },
    hide() {
        this.revealed = '';
        this.askPassword = false;
        this.password = '';
        if (this.$refs.revealedKey) this.$refs.revealedKey.textContent = '';
    },
}" x-init="$wire.$on('privateKeyUpdated', () => { replacing = false })"
    x-on:livewire:navigating.document="hide()">
    <div class="mb-1.5 flex items-center justify-between gap-3">
        <label class="text-[13px] font-medium">Private key</label>
        @can('update', $private_key)
            <div class="flex items-center gap-3">
                <button type="button"
                    class="text-[11px] font-medium text-coollabs hover:underline disabled:cursor-not-allowed disabled:no-underline disabled:opacity-50 dark:text-brand-light"
                    x-on:click="revealed || askPassword ? hide() : reveal()"
                    x-bind:disabled="replacing"
                    x-text="revealed || askPassword ? 'Hide key' : 'Reveal key'"></button>
                <button type="button"
                    class="text-[11px] font-medium text-coollabs hover:underline dark:text-brand-light"
                    x-on:click="replacing = !replacing; if (replacing) { hide() } else { $wire.set('newPrivateKey', '', false) }"
                    x-text="replacing ? 'Cancel replace' : 'Replace key'"></button>
            </div>
        @endcan
    </div>
    <p class="text-xs text-neutral-500 dark:text-fg-dim" x-show="!replacing && !revealed && !askPassword">
        The private key is stored encrypted and is not shown. Use the public key and the fingerprint to identify it.
    </p>
    @can('update', $private_key)
        <div x-cloak x-show="askPassword && !revealed" class="flex items-end gap-2">
            <div class="flex-1">
                <label class="text-[13px] font-medium">Enter your password to reveal the private key</label>
                <input type="password" class="w-full input" x-model="password" autocomplete="current-password"
                    x-on:keydown.enter.prevent="reveal()" placeholder="Enter your password">
            </div>
            <x-forms.button type="button" x-on:click="reveal()" x-bind:disabled="!password || revealing">
                Reveal
            </x-forms.button>
        </div>
        <div x-cloak x-show="revealed">
            <pre x-ref="revealedKey" class="input h-auto! overflow-x-auto whitespace-pre font-mono text-xs" x-text="revealed"></pre>
        </div>
        <div x-cloak x-show="replacing" class="mt-2">
            <x-forms.textarea rows="10" id="newPrivateKey" monospace
                placeholder="Paste the new private key. Leave empty to keep the stored key." />
        </div>
    @endcan
</div>
