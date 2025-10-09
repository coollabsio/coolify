<div class="w-full">
    <form class="flex flex-col gap-2 {{ $modal_mode ? 'w-full' : '' }}" wire:submit='addToken'>
        @if ($modal_mode)
            {{-- Modal layout: vertical, compact --}}
            @if (!isset($provider) || empty($provider) || $provider === '')
                <x-forms.select required id="provider" label="Provider">
                    <option value="hetzner">Hetzner</option>
                    <option value="digitalocean">DigitalOcean</option>
                </x-forms.select>
            @else
                <input type="hidden" wire:model="provider" />
            @endif

            <x-forms.input required id="name" label="Token Name"
                placeholder="e.g., Production Hetzner. tip: add Hetzner project name in it" />

            <x-forms.input required type="password" id="token" label="API Token" placeholder="Enter your API token"
                helper="Your {{ ucfirst($provider) }} Cloud API token. You can create one in your <a href='{{ $provider === 'hetzner' ? 'https://console.hetzner.cloud/' : '#' }}' target='_blank' class='underline dark:text-white'>{{ ucfirst($provider) }} Console</a>." />

            <x-forms.button type="submit">Add Token</x-forms.button>
        @else
            {{-- Full page layout: horizontal, spacious --}}
            <div class="flex gap-2 items-end flex-wrap">
                <div class="w-64">
                    <x-forms.select required id="provider" label="Provider">
                        <option value="hetzner">Hetzner</option>
                        <option value="digitalocean">DigitalOcean</option>
                    </x-forms.select>
                </div>
                <div class="flex-1 min-w-64">
                    <x-forms.input required id="name" label="Token Name" placeholder="e.g., Production Hetzner" />
                </div>
            </div>
            <div class="flex gap-2 items-end flex-wrap">
                <div class="flex-1 min-w-64">
                    <x-forms.input required type="password" id="token" label="API Token"
                        placeholder="Enter your API token" />
                </div>
                <x-forms.button type="submit">Add Token</x-forms.button>
            </div>
        @endif
    </form>
</div>
