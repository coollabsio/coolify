<div class="w-full">
    <form class="application-settings-form flex w-full flex-col gap-4" wire:submit="save">
        <div class="grid gap-4 lg:grid-cols-2">
            <x-forms.input required id="name" label="Token name" />
            <x-forms.input readonly label="Provider" value="Cloudflare" />
            <div class="lg:col-span-2">
                <x-forms.input type="password" id="newToken" label="New API token"
                    placeholder="Leave blank to keep the current token"
                    helper="Paste a replacement token to rotate this credential." />
            </div>
        </div>

        <fieldset>
            <legend class="text-sm font-medium text-black dark:text-fg">Capabilities</legend>
            <div class="mt-3 rounded-lg border border-neutral-200 p-1 dark:border-white/[0.08]">
                <x-forms.checkbox id="edit-dns-capability" label="DNS" domValue="dns" fullWidth
                    wire:model.live="capabilities" />
                <p class="px-2.5 pb-2 text-[11px] text-neutral-500 dark:text-fg-dim">
                    Manage Cloudflare DNS records.
                </p>
            </div>
            @error('capabilities')
                <span class="text-xs text-red-500">{{ $message }}</span>
            @enderror
        </fieldset>

        @if (in_array('dns', $capabilities, true))
            <div class="rounded-lg border border-neutral-200 bg-neutral-50 p-3 text-[11px] leading-5 text-neutral-600 dark:border-white/[0.08] dark:bg-white/[0.025] dark:text-fg-dim">
                <div class="font-medium text-black dark:text-fg">Required Cloudflare permissions</div>
                <ul class="list-inside list-disc">
                    <li>Zone - DNS - Edit</li>
                    <li>Zone - Zone - Read</li>
                </ul>
                <a href="https://dash.cloudflare.com/profile/api-tokens?permissionGroupKeys=%5B%7B%22key%22%3A%22dns%22%2C%22type%22%3A%22edit%22%7D%5D&amp;accountId=%2A&amp;zoneId=all&amp;name=Coolify%20DNS%20Management"
                    target="_blank" rel="noopener noreferrer"
                    class="font-medium text-coollabs hover:underline dark:text-warning">
                    Create a replacement token in Cloudflare
                </a>
            </div>
        @endif

        <div class="flex items-center justify-between gap-2 border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
            <x-modal-confirmation title="Delete integration token?" isErrorButton buttonTitle="Delete"
                submitAction="delete" :actions="['This integration token will be permanently deleted.']"
                confirmationText="{{ $integrationToken->name }}" :confirmWithPassword="false"
                step2ButtonText="Delete token" />
            <x-forms.button type="submit" wire:target="save" isHighlighted>
                Validate and save
            </x-forms.button>
        </div>
    </form>
</div>
