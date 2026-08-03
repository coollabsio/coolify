<div class="application-settings-form">
    <form class="flex flex-col gap-4" wire:submit="createPrivateKey">
        <div class="grid gap-4 lg:grid-cols-2">
            <x-forms.input id="name" label="Name" required />
            <x-forms.input id="description" label="Description" />
            <div class="lg:col-span-2">
                <x-forms.textarea realtimeValidation id="value" rows="10" monospace
                    placeholder="-----BEGIN OPENSSH PRIVATE KEY-----" label="Private key" required />
            </div>
            <div class="lg:col-span-2">
                <x-forms.input id="publicKey" readonly label="Public key"
                    helper="Copy this value to ~/.ssh/authorized_keys on the target server." />
            </div>
        </div>
        <div class="flex justify-end border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
            <button type="submit"
                class="button bg-coollabs/10! text-coollabs! ring-1 ring-coollabs/25 hover:bg-coollabs/15! dark:bg-warning/15! dark:text-warning! dark:ring-warning/25 dark:hover:bg-warning/20!">
                Continue
            </button>
        </div>
    </form>
</div>
