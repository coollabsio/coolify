{{-- Login of an SFTP storage: a password, or a private key of the storage team. Uses sftpAuth, privateKeyId, hasSavedSecret, $privateKeys, and $publicKey. --}}
<div class="grid gap-4 lg:grid-cols-2">
    <x-forms.listbox required live id="sftpAuth" label="Authentication" :disabled="$disabled ?? false" :options="[
        ['value' => 'key', 'label' => 'Private key'],
        ['value' => 'password', 'label' => 'Password'],
    ]" />
    @if ($sftpAuth === 'key')
        <x-forms.listbox required live id="privateKeyId" label="Private key" :disabled="$disabled ?? false"
            :options="$privateKeys" placeholder="Select a private key"
            emptyText="No private keys yet. Add one under Keys & Tokens." />
        @if ($publicKey)
            <div class="lg:col-span-2">
                <x-forms.copy-input label="Public key" :text="$publicKey" />
                <p class="mt-1 text-[12px] text-neutral-500 dark:text-fg-dim">
                    Add this line to ~/.ssh/authorized_keys of the user on the SFTP server.
                </p>
            </div>
        @endif
    @else
        <x-forms.input type="password" label="Password" id="secret" :disabled="$disabled ?? false"
            :required="! ($hasSavedSecret ?? false)"
            :placeholder="($hasSavedSecret ?? false) ? 'Saved. Leave empty to keep it.' : ''" />
    @endif
</div>
