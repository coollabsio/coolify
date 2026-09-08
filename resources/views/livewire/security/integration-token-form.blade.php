<div class="w-full">
    <form class="application-settings-form flex w-full flex-col gap-4" wire:submit="addToken">
        <x-forms.listbox required id="provider" label="Provider" :live="true" :options="[
            ['value' => 'cloudflare', 'label' => 'Cloudflare'],
            ['value' => 'doppler', 'label' => 'Doppler'],
            ['value' => 'infisical', 'label' => 'Infisical'],
            ['value' => 'vault', 'label' => 'HashiCorp Vault'],
        ]" />

        @if ($provider === 'infisical')
            <div class="grid gap-4 lg:grid-cols-2">
                <x-forms.input required id="name" label="Token name" placeholder="Production secrets" />
                <x-forms.input required id="metadata.client_id" label="Client ID"
                    placeholder="Machine identity client ID" />
                <x-forms.input required type="password" id="token" label="Client secret"
                    placeholder="Paste the machine identity client secret" />
                <x-forms.input required id="metadata.base_url" label="Base URL"
                    placeholder="https://app.infisical.com"
                    helper="Use the URL of your self-hosted Infisical instance, or the Infisical cloud URL." />
            </div>
        @else
            <div class="grid gap-4 lg:grid-cols-2">
                <x-forms.input required id="name" label="Token name"
                    placeholder="{{ $provider === 'cloudflare' ? 'Production DNS' : 'Production secrets' }}" />
                <x-forms.input required type="password" id="token"
                    label="{{ $provider === 'vault' ? 'Vault token' : 'API token' }}"
                    placeholder="Paste the provider token" />
            </div>
        @endif

        @if ($provider === 'vault')
            <div class="grid gap-4 lg:grid-cols-2">
                <x-forms.input required id="metadata.base_url" label="Base URL"
                    placeholder="https://vault.example.com:8200" />
                <x-forms.input id="metadata.namespace" label="Namespace (optional)"
                    placeholder="admin/team-a" helper="Only for Vault Enterprise / HCP Vault." />
            </div>
        @endif

        @if ($provider === 'cloudflare')
            <fieldset>
                <legend class="text-sm font-medium text-black dark:text-fg">Capabilities</legend>
                <div class="mt-3 rounded-lg border border-neutral-200 p-1 dark:border-white/[0.08]">
                    <x-forms.checkbox id="dns-capability" label="DNS" domValue="dns" fullWidth
                        wire:model.live="capabilities" />
                    <p class="px-2.5 pb-2 text-[11px] text-neutral-500 dark:text-fg-dim">
                        Manage Cloudflare DNS records.
                    </p>
                </div>
                @error('capabilities')
                    <span class="text-xs text-red-500">{{ $message }}</span>
                @enderror
            </fieldset>
        @else
            <div class="rounded-lg border border-neutral-200 bg-neutral-50 p-3 text-[11px] leading-5 text-neutral-600 dark:border-white/[0.08] dark:bg-white/[0.025] dark:text-fg-dim">
                <div class="font-medium text-black dark:text-fg">Capability: Secrets (read-only)</div>
                <p>Coolify reads secrets from this provider at deployment time and writes them into the generated
                    <code>.env</code> file. Secret values are never stored in the Coolify database.</p>
            </div>
        @endif

        @if ($provider === 'cloudflare' && in_array('dns', $capabilities, true))
            <div class="rounded-lg border border-neutral-200 bg-neutral-50 p-3 text-[11px] leading-5 text-neutral-600 dark:border-white/[0.08] dark:bg-white/[0.025] dark:text-fg-dim">
                <div class="font-medium text-black dark:text-fg">Required Cloudflare permissions</div>
                <ul class="list-inside list-disc">
                    <li>Zone - DNS - Edit</li>
                    <li>Zone - Zone - Read</li>
                </ul>
                <p>Limit zone resources to the zones Coolify should manage.</p>
                <a href="https://dash.cloudflare.com/profile/api-tokens?permissionGroupKeys=%5B%7B%22key%22%3A%22dns%22%2C%22type%22%3A%22edit%22%7D%5D&amp;accountId=%2A&amp;zoneId=all&amp;name=Coolify%20DNS%20Management"
                    target="_blank" rel="noopener noreferrer"
                    class="font-medium text-coollabs hover:underline dark:text-warning">
                    Create this token in Cloudflare
                </a>
            </div>
        @elseif ($provider === 'doppler')
            <div class="rounded-lg border border-neutral-200 bg-neutral-50 p-3 text-[11px] leading-5 text-neutral-600 dark:border-white/[0.08] dark:bg-white/[0.025] dark:text-fg-dim">
                <div class="font-medium text-black dark:text-fg">Recommended Doppler token</div>
                <p>Use a read-only <span class="font-medium">Service Token</span> (dp.st.*). It is pinned to one
                    project and config. Create it in Doppler under Project &gt; Config &gt; Access.</p>
            </div>
        @elseif ($provider === 'infisical')
            <div class="rounded-lg border border-neutral-200 bg-neutral-50 p-3 text-[11px] leading-5 text-neutral-600 dark:border-white/[0.08] dark:bg-white/[0.025] dark:text-fg-dim">
                <div class="font-medium text-black dark:text-fg">Infisical machine identity</div>
                <p>Create a machine identity with Universal Auth and read access to your project. Paste the client
                    ID above and the client secret in the secret field.</p>
            </div>
        @elseif ($provider === 'vault')
            <div class="rounded-lg border border-neutral-200 bg-neutral-50 p-3 text-[11px] leading-5 text-neutral-600 dark:border-white/[0.08] dark:bg-white/[0.025] dark:text-fg-dim">
                <div class="font-medium text-black dark:text-fg">Vault token</div>
                <p>Use a token with read access to your KV v2 secrets. Prefer a periodic or long-lived token —
                    deployments fail when the token expires.</p>
            </div>
        @endif

        <div class="flex justify-end border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
            <x-forms.button type="submit" wire:target="addToken" isHighlighted>
                Validate and add
            </x-forms.button>
        </div>
    </form>
</div>
