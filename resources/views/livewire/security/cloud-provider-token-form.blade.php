<div class="w-full">
    <form class="flex flex-col gap-2 {{ $modal_mode ? 'w-full' : '' }}" wire:submit='addToken'>
        @if ($modal_mode)
            {{-- Modal layout: vertical, compact --}}
            @if (!isset($provider) || empty($provider) || $provider === '')
                <x-forms.select required id="provider" label="Provider" wire:model.live="provider">
                    <option value="hetzner">Hetzner</option>
                    <option value="digitalocean">DigitalOcean</option>
                    <option value="vultr">Vultr</option>
                </x-forms.select>
            @else
                <input type="hidden" wire:model="provider" />
            @endif

            <x-forms.input required id="name" label="Token Name"
                placeholder="e.g., Production {{ $provider === 'digitalocean' ? 'DigitalOcean' : ucfirst($provider) }} token. tip: add project name to identify easier" />

            <x-forms.textarea id="description" label="Description" rows="3"
                placeholder="Optional notes about where this token is used" />

            <x-forms.input required type="password" id="token" label="API Token"
                placeholder="Enter your API token" />

            <div class="text-sm text-neutral-500 dark:text-neutral-400">
                Create an API token in the <a
                    href='{{ $provider === 'hetzner' ? 'https://console.hetzner.com/projects' : ($provider === 'vultr' ? 'https://console.vultr.com/user/apiaccess/' : 'https://cloud.digitalocean.com/account/api/tokens') }}'
                    target='_blank' class='underline dark:text-white'>{{ $provider === 'digitalocean' ? 'DigitalOcean' : ucfirst($provider) }} Console</a>.
                @if ($provider === 'hetzner')
                    Choose Project → Security → API Tokens.
                @endif
                @if ($provider === 'digitalocean')
                    Generate New Token.
                @endif
                @if ($provider === 'vultr')
                    Open Account → API Access.
                @endif
            </div>

            <x-forms.button type="submit" :showLoadingIndicator="false" wire:loading.attr="disabled" wire:target="addToken">
                Validate & Add Token
                <x-loading-on-button wire:loading wire:target="addToken" />
            </x-forms.button>
        @else
            {{-- Full page layout: horizontal, spacious --}}
            <div class="flex gap-2 items-end flex-wrap">
                <div class="w-64">
                    <x-forms.select required id="provider" label="Provider" wire:model.live="provider">
                        <option value="hetzner">Hetzner</option>
                        <option value="digitalocean">DigitalOcean</option>
                        <option value="vultr">Vultr</option>
                    </x-forms.select>
                </div>
                <div class="flex-1 min-w-64">
                    <x-forms.input required id="name" label="Token Name" placeholder="e.g., Production cloud token" />
                </div>
                <div class="flex-1 min-w-64">
                    <x-forms.textarea id="description" label="Description" rows="3"
                        placeholder="Optional notes about where this token is used" />
                </div>
            </div>
            <div class="flex-1 min-w-64">
                <x-forms.input required type="password" id="token" label="API Token"
                    placeholder="Enter your API token" />
                <div class="text-sm text-neutral-500 dark:text-neutral-400 mt-2">
                    Create an API token in the <a
                        href='{{ $provider === 'hetzner' ? 'https://console.hetzner.com/projects' : ($provider === 'vultr' ? 'https://console.vultr.com/user/apiaccess/' : 'https://cloud.digitalocean.com/account/api/tokens') }}'
                        target='_blank' class='underline dark:text-white'>{{ $provider === 'digitalocean' ? 'DigitalOcean' : ucfirst($provider) }} Console</a>.
                    @if ($provider === 'hetzner')
                        Choose Project → Security → API Tokens.
                    @endif
                    @if ($provider === 'digitalocean')
                        Generate New Token.
                    @endif
                    @if ($provider === 'vultr')
                        Open Account → API Access.
                    @endif
                </div>
            </div>
            <x-forms.button type="submit" :showLoadingIndicator="false" wire:loading.attr="disabled" wire:target="addToken">
                Validate & Add Token
                <x-loading-on-button wire:loading wire:target="addToken" />
            </x-forms.button>
        @endif
    </form>
</div>
