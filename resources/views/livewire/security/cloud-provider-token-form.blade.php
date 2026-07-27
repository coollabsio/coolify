<div class="w-full">
    <form class="application-settings-form flex w-full flex-col gap-4" wire:submit="addToken">
        @if (!isset($provider) || blank($provider))
            <x-forms.listbox required id="provider" label="Provider" wire:model.live="provider" :options="[
                ['value' => 'hetzner', 'label' => 'Hetzner'],
                ['value' => 'digitalocean', 'label' => 'DigitalOcean'],
                ['value' => 'vultr', 'label' => 'Vultr'],
            ]" />
        @else
            <input type="hidden" wire:model="provider" />
        @endif

        <div class="grid gap-4 lg:grid-cols-2">
            <x-forms.input required id="name" label="Token name"
                placeholder="Production {{ $provider === 'digitalocean' ? 'DigitalOcean' : ucfirst($provider) }} token" />
            <x-forms.input required type="password" id="token" label="API token"
                placeholder="Paste the provider token" />
            <div class="lg:col-span-2">
                <x-forms.textarea id="description" label="Description" rows="3"
                    placeholder="Optional notes about where this token is used" />
            </div>
        </div>

        <div
            class="rounded-lg border border-neutral-200 bg-neutral-50 p-3 text-[11px] leading-5 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.025] dark:text-fg-dim">
            Create the token in the
            <a href="{{ $provider === 'hetzner'
                ? 'https://console.hetzner.com/projects'
                : ($provider === 'vultr'
                    ? 'https://console.vultr.com/user/apiaccess/'
                    : 'https://cloud.digitalocean.com/account/api/tokens') }}"
                target="_blank" class="font-medium text-coollabs hover:underline dark:text-warning">
                {{ $provider === 'digitalocean' ? 'DigitalOcean' : ucfirst($provider) }} console
            </a>.
        </div>

        <div class="flex justify-end border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
            <button type="submit"
                class="button bg-coollabs/10! text-coollabs! ring-1 ring-coollabs/25 hover:bg-coollabs/15! dark:bg-warning/15! dark:text-warning! dark:ring-warning/25 dark:hover:bg-warning/20!"
                wire:loading.attr="disabled" wire:target="addToken">
                Validate and add
                <x-loading-on-button wire:loading wire:target="addToken" />
            </button>
        </div>
    </form>
</div>
