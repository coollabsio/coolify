<div>
    @if ($enabled)
        @assets
            @vite('resources/js/passkeys.js')
        @endassets
        <section class="application-settings-section">
            <div class="application-settings-section-header">
                <div>
                    <h2>Passkeys</h2>
                    <p>Sign in with your fingerprint, face, or a security key. A passkey sign-in skips two-factor authentication.</p>
                </div>
                @if ($available && ! $show_form)
                    <a class="button" href="{{ route('profile.passkeys.confirm') }}">Add passkey</a>
                @endif
            </div>
            <div class="application-settings-section-body flex flex-col gap-4">
                @unless ($available)
                    <x-callout type="info" title="Passkeys are not available on this address">
                        Passkeys need HTTPS on the instance domain, or <code>localhost</code>. An IP address does not work.
                    </x-callout>
                @endunless

                @if ($available && $show_form)
                    <form class="flex flex-col gap-2" x-data="{
                            name: '',
                            loading: false,
                            error: null,
                            init() {
                                const ready = () => { this.name = this.name || window.passkeyDeviceName(); };
                                window.Passkeys ? ready() : window.addEventListener('passkeys:ready', ready, { once: true });
                            },
                            async register() {
                                this.loading = true;
                                this.error = null;
                                try {
                                    if (!window.Passkeys?.isSupported()) {
                                        throw new Error('This browser does not support passkeys.');
                                    }
                                    await window.Passkeys.register({ name: this.name });
                                    await $wire.registered();
                                } catch (e) {
                                    if (e.name !== 'UserCancelledError') {
                                        this.error = e.message;
                                    }
                                } finally {
                                    this.loading = false;
                                }
                            },
                        }" x-on:submit.prevent="register()">
                        <div class="flex items-end gap-2">
                            <x-forms.input x-model="name" label="Passkey name" required maxlength="255" />
                            <x-forms.button type="submit" isHighlighted x-bind:disabled="loading">Create passkey</x-forms.button>
                            <x-forms.button type="button" wire:click="$set('show_form', false)">Cancel</x-forms.button>
                        </div>
                        <p x-cloak x-show="error" x-text="error" class="text-sm text-error"></p>
                    </form>
                @endif

                @forelse ($passkeys as $passkey)
                    <div wire:key="passkey-{{ $passkey->id }}"
                        class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-neutral-200 p-3 dark:border-white/[0.07]"
                        x-data="{ editing: false, name: @js($passkey->name), saved: @js($passkey->name) }">
                        <div class="min-w-0">
                            <div x-show="!editing" class="text-sm font-semibold text-black dark:text-fg">{{ $passkey->name }}</div>
                            <form x-cloak x-show="editing" class="flex items-end gap-2"
                                x-on:submit.prevent="$wire.rename({{ $passkey->id }}, name).then(ok => { if (ok) { saved = name; editing = false; } })">
                                <x-forms.input x-model="name" required maxlength="255" aria-label="Passkey name" />
                                <x-forms.button type="submit">Save</x-forms.button>
                            </form>
                            <p class="mt-1 text-xs text-neutral-500 dark:text-fg-dim">
                                {{ $passkey->authenticator ?? 'Passkey' }}
                                · Added {{ $passkey->created_at->diffForHumans() }}
                                · {{ $passkey->last_used_at ? 'Last used '.$passkey->last_used_at->diffForHumans() : 'Not used yet' }}
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <x-forms.button type="button" x-on:click="editing = !editing; name = saved">
                                <span x-text="editing ? 'Cancel' : 'Rename'"></span>
                            </x-forms.button>
                            <div wire:key="passkey-delete-{{ $passkey->id }}-{{ md5($passkey->name) }}">
                                <x-modal-confirmation title="Delete passkey?" buttonTitle="Delete" isErrorButton
                                    submitAction="delete({{ $passkey->id }})"
                                    :actions="['You cannot sign in with this passkey after it is deleted.']"
                                    confirmationText="{{ $passkey->name }}"
                                    confirmationLabel="Enter the passkey name to confirm"
                                    shortConfirmationLabel="Passkey name" step3ButtonText="Delete passkey" />
                            </div>
                        </div>
                    </div>
                @empty
                    @if ($available && ! $show_form)
                        <x-empty size="sm" title="No passkeys"
                            description="Add a passkey to sign in without your password."
                            icon-name="keys" />
                    @endif
                @endforelse

                @error('name')
                    <p class="text-sm text-error">{{ $message }}</p>
                @enderror
            </div>
        </section>
    @endif
</div>
